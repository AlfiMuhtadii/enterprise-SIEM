"""Unit tests for incident-builder-service's incident_aggregation module.

alert_entities()/group_key()/incident_id_for()/aggregate() are the core
alert-to-incident grouping logic but had zero isolated unit test coverage
before this module was extracted from main.py (CODE-STRUCT-DECOMPOSE) --
this file covers the previously-untested branches directly, independent of
any FastAPI/Pydantic/Kafka stubbing (same technique test_alert_identity.py
already established for alert-writer-service's extracted module).
"""
from __future__ import annotations

import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "incident-builder-service"
sys.path.insert(0, str(SERVICES_DIR))

from incident_aggregation import (  # noqa: E402
    aggregate,
    alert_entities,
    group_key,
    incident_id_for,
)


def make_alert(**overrides):
    defaults = dict(
        alert_id="alert-1",
        alert_type="IDENTITY_MFA_FAILURE_BURST",
        severity="high",
        detected_at="2026-07-10T00:00:00Z",
        actor_key="alice",
        ip="10.0.0.1",
        score=0.8,
        evidence={},
        trace_id=None,
        traceparent=None,
        tenant_id=None,
    )
    defaults.update(overrides)
    return SimpleNamespace(**defaults)


class AlertEntitiesTest(unittest.TestCase):
    def test_collects_involved_entity_lists_from_evidence(self):
        # actor_key/ip are unconditionally appended too, so isolate the
        # evidence-derived entities by nulling them out for this case.
        a = make_alert(
            evidence={
                "involved_users": ["bob"],
                "involved_hosts": ["host-1"],
                "involved_cloud_accounts": ["acct-1"],
                "involved_external_ips": ["203.0.113.5"],
                "involved_email_artifacts": ["a@example.com"],
            },
            actor_key=None,
            ip=None,
        )
        entities = alert_entities(a)
        self.assertEqual(entities, sorted(["bob", "host-1", "acct-1", "203.0.113.5", "a@example.com"]))

    def test_actor_key_and_ip_are_always_included_alongside_evidence_entities(self):
        a = make_alert(evidence={"involved_users": ["bob"]}, actor_key="alice", ip="10.0.0.1")
        entities = alert_entities(a)
        self.assertEqual(entities, sorted(["bob", "alice", "10.0.0.1"]))

    def test_falls_back_to_actor_key_and_ip_when_no_evidence_entities(self):
        a = make_alert(evidence={}, actor_key="alice", ip="10.0.0.1")
        self.assertEqual(alert_entities(a), sorted(["alice", "10.0.0.1"]))

    def test_deduplicates_entities(self):
        a = make_alert(evidence={"involved_users": ["alice"]}, actor_key="alice", ip=None)
        self.assertEqual(alert_entities(a), ["alice"])

    def test_ignores_non_list_evidence_values(self):
        a = make_alert(evidence={"involved_users": "not-a-list"}, actor_key=None, ip=None)
        self.assertEqual(alert_entities(a), [])

    def test_skips_falsy_items_in_entity_lists(self):
        a = make_alert(evidence={"involved_users": ["bob", "", None]}, actor_key=None, ip=None)
        self.assertEqual(alert_entities(a), ["bob"])


class GroupKeyTest(unittest.TestCase):
    def test_uses_alert_type_family_and_first_entity_alphabetically(self):
        # alert_entities() sorts entities, so the "first" entity is
        # alphabetical order, not evidence insertion order.
        a = make_alert(
            alert_type="IDENTITY_MFA_FAILURE_BURST",
            evidence={"involved_users": ["alice"]},
            actor_key=None,
            ip=None,
        )
        self.assertEqual(group_key(a), "IDENTITY|alice")

    def test_falls_back_to_actor_key_when_no_entities(self):
        a = make_alert(alert_type="CLOUD_MASS_DOWNLOAD", evidence={}, actor_key="alice", ip=None)
        self.assertEqual(group_key(a), "CLOUD|alice")

    def test_falls_back_to_unknown_when_nothing_available(self):
        a = make_alert(alert_type="CLOUD_MASS_DOWNLOAD", evidence={}, actor_key=None, ip=None)
        self.assertEqual(group_key(a), "CLOUD|unknown")

    def test_same_inputs_produce_same_key(self):
        a = make_alert()
        b = make_alert(alert_id="alert-2")
        self.assertEqual(group_key(a), group_key(b))


class IncidentIdForTest(unittest.TestCase):
    def test_deterministic_for_same_key(self):
        self.assertEqual(incident_id_for("IDENTITY|alice"), incident_id_for("IDENTITY|alice"))

    def test_differs_for_different_keys(self):
        self.assertNotEqual(incident_id_for("IDENTITY|alice"), incident_id_for("IDENTITY|bob"))

    def test_has_expected_prefix_and_length(self):
        inc_id = incident_id_for("IDENTITY|alice")
        self.assertTrue(inc_id.startswith("xdr-inc-"))
        self.assertEqual(len(inc_id), len("xdr-inc-") + 24)


class AggregateTest(unittest.TestCase):
    def test_picks_highest_severity_across_group(self):
        group = [make_alert(severity="low"), make_alert(alert_id="a2", severity="critical")]
        incident = aggregate(group, "k")
        self.assertEqual(incident["severity"], "critical")

    def test_confidence_is_max_score_in_group(self):
        group = [make_alert(score=0.3), make_alert(alert_id="a2", score=0.9)]
        incident = aggregate(group, "k")
        self.assertEqual(incident["confidence"], 0.9)

    def test_confidence_is_zero_when_single_alert_has_no_score(self):
        # confidence's default=0.5 only applies to an empty generator, which
        # can't happen here (aggregate() always receives >=1 alert) --
        # float(None or 0) makes a None score contribute 0.0, not the default.
        group = [make_alert(score=None)]
        incident = aggregate(group, "k")
        self.assertEqual(incident["confidence"], 0.0)

    def test_timeline_ordered_by_detected_at(self):
        group = [
            make_alert(alert_id="later", detected_at="2026-07-10T02:00:00Z"),
            make_alert(alert_id="earlier", detected_at="2026-07-10T01:00:00Z"),
        ]
        incident = aggregate(group, "k")
        self.assertEqual([t["alert_id"] for t in incident["timeline"]], ["earlier", "later"])

    def test_mitre_mapping_deduplicated_and_sorted(self):
        group = [
            make_alert(evidence={"mitre_attack": ["T1110"]}),
            make_alert(alert_id="a2", evidence={"mitre_attack": ["T1078", "T1110"]}),
        ]
        incident = aggregate(group, "k")
        self.assertEqual(incident["mitre_mapping"], ["T1078", "T1110"])

    def test_xdr_domains_deduplicated_and_sorted(self):
        group = [
            make_alert(evidence={"xdr_domains": ["cloud"]}),
            make_alert(alert_id="a2", evidence={"xdr_domains": ["identity", "cloud"]}),
        ]
        incident = aggregate(group, "k")
        self.assertEqual(incident["xdr_domains"], ["cloud", "identity"])

    def test_alert_ids_preserve_group_membership(self):
        group = [make_alert(alert_id="a1"), make_alert(alert_id="a2")]
        incident = aggregate(group, "k")
        self.assertEqual(incident["alert_ids"], ["a1", "a2"])

    def test_incident_id_derived_from_key(self):
        incident = aggregate([make_alert()], "IDENTITY|alice")
        self.assertEqual(incident["incident_id"], incident_id_for("IDENTITY|alice"))

    def test_trace_id_is_first_non_empty_in_group(self):
        group = [make_alert(trace_id=None), make_alert(alert_id="a2", trace_id="trace-123")]
        incident = aggregate(group, "k")
        self.assertEqual(incident["trace_id"], "trace-123")

    def test_tenant_id_is_first_non_empty_in_group(self):
        group = [make_alert(tenant_id=None), make_alert(alert_id="a2", tenant_id="tenant-a")]
        incident = aggregate(group, "k")
        self.assertEqual(incident["tenant_id"], "tenant-a")

    def test_timeline_evidence_chain_capped_at_20(self):
        chain = [f"step-{i}" for i in range(30)]
        group = [make_alert(evidence={"evidence_chain": chain})]
        incident = aggregate(group, "k")
        self.assertEqual(len(incident["timeline"][0]["evidence_chain"]), 20)

    def test_status_always_open_and_title_includes_key(self):
        incident = aggregate([make_alert()], "IDENTITY|alice")
        self.assertEqual(incident["status"], "open")
        self.assertIn("IDENTITY|alice", incident["title"])


if __name__ == "__main__":
    unittest.main()
