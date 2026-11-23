"""Tests for BACKLOG-SOC-013: Shadow Alert Consumer & Advisory Findings.

Covers the Python-side pieces:
- normalize_shadow_records: parses EndpointAlert batch payloads into advisory finding dicts
- shadow_fingerprint: deterministic, tenant- and topic-scoped fingerprint
- shadow_event_loop lifecycle: consumer created, polls, writes, recovers on error
- STRICT isolation: shadow loop never calls process_alerts or publishes to alerts.created
"""
from __future__ import annotations

import hashlib
import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, call, patch

_SERVICES = Path(__file__).parent.parent.parent / "services" / "alert-writer-service"
sys.path.insert(0, str(_SERVICES))

# Stub heavy optional dependencies not installed in the test environment.
class _FakeHTTPException(Exception):
    def __init__(self, status_code: int = 200, detail: object = None) -> None:
        super().__init__(detail)
        self.status_code = status_code
        self.detail = detail


for _mod in ("fastapi", "pydantic", "xdr_event_contracts"):
    if _mod not in sys.modules:
        _stub = types.ModuleType(_mod)
        sys.modules[_mod] = _stub
    else:
        _stub = sys.modules[_mod]
    _stub.FastAPI = MagicMock(return_value=MagicMock())  # type: ignore[attr-defined]
    _stub.Depends = lambda f: None  # type: ignore[attr-defined]
    _stub.Header = lambda **kw: None  # type: ignore[attr-defined]
    _stub.HTTPException = _FakeHTTPException  # type: ignore[attr-defined]
    _stub.BaseModel = object  # type: ignore[attr-defined]
    _stub.Field = lambda *a, **kw: None  # type: ignore[attr-defined]
    _stub.envelope = lambda **kw: {}  # type: ignore[attr-defined]
    _stub.is_envelope = lambda v: False  # type: ignore[attr-defined]
    _stub.unwrap_payload = lambda v, t: v  # type: ignore[attr-defined]
    _stub.validate_envelope = lambda ev, t: []  # type: ignore[attr-defined]

sys.modules.pop("main", None)
import main as aw  # noqa: E402


# ---------------------------------------------------------------------------
# shadow_fingerprint
# ---------------------------------------------------------------------------

class TestShadowFingerprint(unittest.TestCase):
    def _fp(self, rule, actor, evidence, topic="xdr.alerts.shadow.endpoint", tenant=None):
        return aw.shadow_fingerprint(rule, actor, evidence, source_topic=topic, tenant_id=tenant)

    def test_deterministic_for_same_inputs(self):
        fp1 = self._fp("RULE_A", "host-1", ["e1", "e2"])
        fp2 = self._fp("RULE_A", "host-1", ["e1", "e2"])
        self.assertEqual(fp1, fp2)

    def test_differs_for_different_rule(self):
        fp1 = self._fp("RULE_A", "host-1", ["e1"])
        fp2 = self._fp("RULE_B", "host-1", ["e1"])
        self.assertNotEqual(fp1, fp2)

    def test_differs_for_different_actor(self):
        fp1 = self._fp("RULE_A", "host-1", ["e1"])
        fp2 = self._fp("RULE_A", "host-2", ["e1"])
        self.assertNotEqual(fp1, fp2)

    def test_evidence_ids_order_independent(self):
        fp1 = self._fp("RULE_A", "host-1", ["e1", "e2"])
        fp2 = self._fp("RULE_A", "host-1", ["e2", "e1"])
        self.assertEqual(fp1, fp2)

    def test_empty_evidence_ids_returns_stable_hash(self):
        fp = self._fp("RULE_A", "host-1", [])
        self.assertEqual(len(fp), 64)  # sha256 hex

    def test_missing_actor_is_distinct_from_literal_unknown(self):
        fp_empty = self._fp("RULE_A", "", [])
        fp_none = self._fp("RULE_A", None, [])
        self.assertEqual(fp_empty, fp_none)
        self.assertNotEqual(fp_empty, self._fp("RULE_A", "unknown", []))

    def test_scopes_and_evidence_boundaries_are_distinct(self):
        fingerprints = {
            self._fp("R1", "same-host", ["one,two"], tenant="tenant-a"),
            self._fp("R1", "same-host", ["one", "two"], tenant="tenant-a"),
            self._fp("R1", "same-host", ["one,two"], tenant="tenant-b"),
            self._fp("R1", "same-host", ["one,two"], topic="xdr.alerts.shadow.network", tenant="tenant-a"),
            self._fp("R1", "same-host", ["one,two"]),
        }
        self.assertEqual(len(fingerprints), 5)


# ---------------------------------------------------------------------------
# normalize_shadow_records
# ---------------------------------------------------------------------------

class TestNormalizeShadowRecords(unittest.TestCase):
    def _make_record(self, alerts, trace_id="t1", scope="endpoint-shadow"):
        return {
            "value": {
                "trace_id": trace_id,
                "source": "correlation-worker",
                "scope": scope,
                "alerts": alerts,
                "shadow_mode": True,
            }
        }

    def _endpoint_alert(self, rule_id="ENDPOINT_PROC_INJ", confidence=0.82):
        return {
            "alert_id":    "aid-001",
            "rule_id":     rule_id,
            "severity":    "high",
            "confidence":  confidence,
            "host":        "host-abc",
            "user":        "user-xyz",
            "evidence_ids": ["e1", "e2"],
            "event_type":  rule_id,
            "evidence":    {"parent": "winword.exe"},
            "shadow_mode": True,
        }

    def test_parses_endpoint_alert_into_finding_dict(self):
        records  = [self._make_record([self._endpoint_alert()])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertEqual(len(findings), 1)
        f = findings[0]
        self.assertEqual(f["rule_id"], "ENDPOINT_PROC_INJ")
        self.assertEqual(f["domain"], "endpoint")
        self.assertEqual(f["severity"], "high")
        self.assertAlmostEqual(f["confidence"], 0.82)

    def test_domain_mapped_from_topic(self):
        records  = [self._make_record([self._endpoint_alert()])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.network")
        self.assertEqual(findings[0]["domain"], "network")

    def test_unknown_topic_uses_unknown_domain(self):
        records  = [self._make_record([self._endpoint_alert()])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.other")
        self.assertEqual(findings[0]["domain"], "unknown")

    def test_actor_prefers_host_over_user(self):
        records  = [self._make_record([self._endpoint_alert()])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertEqual(findings[0]["actor_key"], "host-abc")

    def test_explicit_envelope_scope_must_match_each_finding(self):
        record = self._make_record([dict(self._endpoint_alert(), tenant_id="tenant-a")])
        topic = "xdr.alerts.shadow.endpoint"
        self.assertEqual(aw.normalize_shadow_records([record], topic)[0]["tenant_id"], "tenant-a")

        for tenant in ("tenant-b", "", {"invalid": True}, " tenant-a"):
            record["value"]["tenant_id"] = tenant
            with self.subTest(tenant=tenant), self.assertRaises(ValueError):
                aw.normalize_shadow_records([record], topic)

        record["value"]["tenant_id"] = "tenant-a"
        self.assertEqual(aw.normalize_shadow_records([record], topic)[0]["tenant_id"], "tenant-a")

    def test_multiple_alerts_in_one_batch(self):
        records  = [self._make_record([self._endpoint_alert("R1"), self._endpoint_alert("R2")])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertEqual(len(findings), 2)

    def test_empty_records_returns_empty(self):
        findings = aw.normalize_shadow_records([], "xdr.alerts.shadow.endpoint")
        self.assertEqual(findings, [])

    def test_empty_alerts_list_returns_empty(self):
        records  = [self._make_record([])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertEqual(findings, [])

    def test_promotion_candidate_true_for_high_confidence(self):
        records  = [self._make_record([self._endpoint_alert(confidence=0.90)])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertTrue(findings[0]["promotion_candidate"])

    def test_rehearsal_is_stored_separately_and_cannot_be_a_promotion_candidate(self):
        topic = "xdr.alerts.shadow.endpoint"
        alert = self._endpoint_alert(confidence=0.90)
        alert["evidence"]["shadow_evidence_origin"] = "representative"
        record = self._make_record([alert])
        record["value"]["shadow_evidence_origin"] = "representative"
        unverified = aw.normalize_shadow_records([record], topic)[0]
        self.assertEqual(json.loads(unverified["evidence"])["shadow_evidence_origin"], "unverified")

        record["value"]["shadow_evidence_origin"] = "rehearsal"
        rehearsal = aw.normalize_shadow_records([record], topic)[0]
        self.assertEqual(json.loads(rehearsal["evidence"])["shadow_evidence_origin"], "rehearsal")
        self.assertNotEqual(rehearsal["fingerprint"], unverified["fingerprint"])
        self.assertIsNone(rehearsal["legacy_fingerprint"])
        self.assertFalse(rehearsal["promotion_candidate"])
        self.assertIn("rehearsal_only", rehearsal["promotion_blocker"])

    def test_promotion_candidate_false_for_low_confidence(self):
        records  = [self._make_record([self._endpoint_alert(confidence=0.50)])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertFalse(findings[0]["promotion_candidate"])

    def test_promotion_blocker_always_contains_domain_soak_required(self):
        records  = [self._make_record([self._endpoint_alert()])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertIn("domain_soak_required", findings[0]["promotion_blocker"])

    def test_fingerprint_is_sha256_hex(self):
        records  = [self._make_record([self._endpoint_alert()])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertEqual(len(findings[0]["fingerprint"]), 64)

    def test_evidence_json_serializable(self):
        records  = [self._make_record([self._endpoint_alert()])]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        evidence = findings[0]["evidence"]
        json.loads(evidence)  # must not raise

    def test_non_dict_value_skipped(self):
        records = [{"value": "not-a-dict"}]
        findings = aw.normalize_shadow_records(records, "xdr.alerts.shadow.endpoint")
        self.assertEqual(findings, [])


# ---------------------------------------------------------------------------
# Shadow loop — never calls process_alerts or publishes to alerts.created
# ---------------------------------------------------------------------------

class TestShadowLoopIsolation(unittest.TestCase):
    """Verify the shadow loop stays strictly separate from the active path."""

    def _make_record(self):
        return {
            "topic": "xdr.alerts.shadow.endpoint",
            "partition": 0,
            "offset": 7,
            "value": {
                "trace_id": "t1",
                "source": "correlation-worker",
                "scope": "endpoint-shadow",
                "alerts": [{
                    "alert_id": "a1",
                    "rule_id": "ENDPOINT_TEST",
                    "severity": "medium",
                    "confidence": 0.80,
                    "host": "h1",
                    "evidence_ids": ["e1"],
                    "event_type": "ENDPOINT_TEST",
                    "evidence": {},
                }],
                "shadow_mode": True,
            }
        }

    def test_shadow_loop_does_not_call_process_alerts(self):
        stop_after = {"calls": 0}

        def fake_poll(base_uri):
            stop_after["calls"] += 1
            if stop_after["calls"] == 1:
                return [self._make_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_delete"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "read_shadow_offsets", return_value={0: 0}), \
             patch.object(aw, "commit_shadow_offsets"), \
             patch.object(aw, "write_advisory_finding", return_value="inserted") as mock_write, \
             patch.object(aw, "process_alerts") as mock_process:
            aw.shadow_event_loop("xdr.alerts.shadow.endpoint")

        mock_process.assert_not_called()
        mock_write.assert_called_once()

    def test_shadow_loop_does_not_call_produce(self):
        stop_after = {"calls": 0}

        def fake_poll(base_uri):
            stop_after["calls"] += 1
            if stop_after["calls"] == 1:
                return [self._make_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_delete"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "read_shadow_offsets", return_value={0: 0}), \
             patch.object(aw, "commit_shadow_offsets"), \
             patch.object(aw, "write_advisory_finding", return_value="inserted"), \
             patch.object(aw, "produce") as mock_produce:
            aw.shadow_event_loop("xdr.alerts.shadow.endpoint")

        mock_produce.assert_not_called()

    def test_shadow_metrics_incremented(self):
        stop_after = {"calls": 0}

        def fake_poll(base_uri):
            stop_after["calls"] += 1
            if stop_after["calls"] == 1:
                return [self._make_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        aw.METRICS["shadow_polls"] = 0
        aw.METRICS["shadow_findings_seen"] = 0
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_delete"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "read_shadow_offsets", return_value={0: 0}), \
             patch.object(aw, "commit_shadow_offsets"), \
             patch.object(aw, "write_advisory_finding", return_value="inserted"):
            aw.shadow_event_loop("xdr.alerts.shadow.endpoint")

        self.assertGreaterEqual(aw.METRICS["shadow_polls"], 1)
        self.assertEqual(aw.METRICS["shadow_findings_seen"], 1)

    def test_postgres_failure_is_not_counted_or_committed_before_reconnect(self):
        self._assert_stable_redelivery(RuntimeError("offline DB"), "inserted", fail_commit=False)

    def test_lost_commit_response_replays_without_counting_another_write(self):
        self._assert_stable_redelivery("inserted", "replayed", fail_commit=True)

    def _assert_stable_redelivery(self, initial, retry, *, fail_commit):
        topic = "xdr.alerts.shadow.endpoint"
        calls = []

        def poll(uri):
            calls.append(("poll", uri))
            if uri == "rest-2" and ("commit", uri) in calls:
                aw.STOP.set()
                return []
            return [self._make_record()]

        def commit(uri, offsets):
            calls.append(("commit", uri))
            self.assertEqual(offsets, [{"topic": topic, "partition": 0, "offset": 8}])
            if uri == "rest-1":
                self.assertTrue(fail_commit)
                raise TimeoutError("ack lost")

        aw.STOP.clear()
        old_writes = aw.METRICS["shadow_findings_written"]
        old_replays = aw.METRICS["shadow_findings_replayed"]
        with patch.object(aw, "consumer_create", side_effect=["rest-1", "rest-2"]) as create, \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_delete"), \
             patch.object(aw.STOP, "wait"), \
             patch.object(aw, "consumer_poll", side_effect=poll), \
             patch.object(aw, "read_shadow_offsets", return_value={0: 0}), \
             patch.object(aw, "commit_shadow_offsets", side_effect=commit), \
             patch.object(aw, "write_advisory_finding", side_effect=[initial, retry]) as write:
            aw.shadow_event_loop(topic)

        self.assertEqual([c.args[0] for c in create.call_args_list],
                         ["shadow-consumer-xdr-alerts-shadow-endpoint"] * 2)
        self.assertTrue(all(c.kwargs["manual_commit"] for c in create.call_args_list))
        self.assertEqual([c.args[1:] for c in write.call_args_list], [(0, 7), (0, 7)])
        self.assertEqual(aw.METRICS["shadow_findings_written"] - old_writes, 1)
        self.assertEqual(aw.METRICS["shadow_findings_replayed"] - old_replays, int(fail_commit))
        if not fail_commit:
            self.assertNotIn(("commit", "rest-1"), calls)

    def test_invalid_record_cannot_supply_a_commit_offset(self):
        record = self._make_record()
        with patch.object(aw, "write_advisory_finding") as write:
            for change in ({"topic": "xdr.alerts"}, {"offset": True}, {"partition": -1},
                           {"value": {"shadow_mode": False, "alerts": []}}):
                with self.subTest(change=change), self.assertRaises(ValueError):
                    aw.process_shadow_records([dict(record, **change)], record["topic"])
        write.assert_not_called()

    def test_reconnect_does_not_process_or_recommit_older_records(self):
        record = self._make_record()
        topic = record["topic"]
        aw.STOP.clear()

        def empty_poll_after_replay(uri):
            aw.STOP.set()
            return [record]

        with patch.object(aw, "consumer_create", return_value="rest-1"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_delete"), \
             patch.object(aw, "consumer_poll", side_effect=empty_poll_after_replay), \
             patch.object(aw, "read_shadow_offsets", return_value={0: 8}) as read, \
             patch.object(aw, "write_advisory_finding") as write, \
             patch.object(aw, "commit_shadow_offsets") as commit:
            aw.shadow_event_loop(topic)
        read.assert_called_once_with("rest-1", topic, {0})
        write.assert_not_called()
        commit.assert_not_called()

    def test_reads_committed_positions_and_rejects_incomplete_response(self):
        topic = "xdr.alerts.shadow.endpoint"
        with patch.object(aw.SESSION, "get") as get:
            get.return_value.json.return_value = {"offsets": [
                {"topic": topic, "partition": 0, "offset": -1},
                {"topic": topic, "partition": 1, "offset": 42},
            ]}
            self.assertEqual(aw.read_shadow_offsets("rest", topic, {0, 1}), {0: 0, 1: 42})
            get.return_value.json.return_value["offsets"].pop()
            with self.assertRaises(ValueError):
                aw.read_shadow_offsets("rest", topic, {0, 1})

    def test_commit_uses_explicit_partitions_and_requires_ack(self):
        offsets = [{"topic": "xdr.alerts.shadow.endpoint", "partition": 0, "offset": 8}]
        with patch.object(aw.SESSION, "post") as post:
            post.return_value.status_code = 204
            aw.commit_shadow_offsets("http://broker/instance", offsets)
            self.assertEqual(post.call_args.args[0], "http://broker/instance/offsets")
            self.assertEqual(post.call_args.kwargs["json"], {"partitions": offsets})
            post.return_value.status_code = 200
            with self.assertRaises(RuntimeError):
                aw.commit_shadow_offsets("http://broker/instance", offsets)

    def test_shadow_loop_recovers_on_poll_error(self):
        calls = {"n": 0}

        def fake_poll(base_uri):
            calls["n"] += 1
            if calls["n"] == 1:
                raise Exception("pandaproxy timeout")
            if calls["n"] == 2:
                raise Exception("pandaproxy timeout")
            if calls["n"] == 3:
                raise Exception("pandaproxy timeout")  # triggers recreate at threshold
            aw.STOP.set()
            return []

        aw.STOP.clear()
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_delete"), \
             patch.object(aw.STOP, "wait"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll):
            aw.shadow_event_loop("xdr.alerts.endpoint.test")

        # Should not raise; loop exits cleanly
        self.assertGreaterEqual(aw.METRICS["shadow_consumer_errors"], 3)


if __name__ == "__main__":
    unittest.main()
