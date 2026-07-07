"""Unit tests for alert-writer-service's alert_identity module.

fingerprint()/alert_id() are the core alert dedupe/identity hashing logic
(what determines the durable alert_id written to security_alerts and
OpenSearch) but had zero isolated unit test coverage before this module
was extracted from main.py — this file covers the previously-untested
branches directly, independent of any FastAPI/Pydantic/Kafka stubbing.
"""
from __future__ import annotations

import hashlib
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "alert-writer-service"
sys.path.insert(0, str(SERVICES_DIR))

from alert_identity import alert_id, fingerprint  # noqa: E402


def make_alert(**overrides):
    defaults = dict(
        alert_id=None,
        alert_type="IDENTITY_MFA_FAILURE_BURST",
        severity="high",
        actor_key="alice",
        ip="10.0.0.1",
        evidence={},
    )
    defaults.update(overrides)
    return SimpleNamespace(**defaults)


class FingerprintTest(unittest.TestCase):
    def test_deterministic_for_identical_input(self):
        a = make_alert()
        self.assertEqual(fingerprint(a), fingerprint(a))

    def test_differs_when_alert_type_differs(self):
        a = make_alert(alert_type="IDENTITY_MFA_FAILURE_BURST")
        b = make_alert(alert_type="CLOUD_MASS_DOWNLOAD")
        self.assertNotEqual(fingerprint(a), fingerprint(b))

    def test_differs_when_severity_differs(self):
        a = make_alert(severity="high")
        b = make_alert(severity="low")
        self.assertNotEqual(fingerprint(a), fingerprint(b))

    def test_prefers_evidence_ids_over_event_ids(self):
        a = make_alert(evidence={"evidence_ids": ["e1"], "event_ids": ["e2"]})
        b = make_alert(evidence={"evidence_ids": ["e1"]})
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_falls_back_to_event_ids_when_evidence_ids_absent(self):
        a = make_alert(evidence={"event_ids": ["e9"]})
        b = make_alert(evidence={"evidence_ids": ["e9"]})
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_falls_back_to_empty_list_when_neither_present(self):
        a = make_alert(evidence={})
        b = make_alert(evidence={"evidence_ids": []})
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_scalar_evidence_id_is_coerced_to_single_item_list(self):
        a = make_alert(evidence={"evidence_ids": "solo-id"})
        b = make_alert(evidence={"evidence_ids": ["solo-id"]})
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_evidence_id_order_does_not_affect_fingerprint(self):
        a = make_alert(evidence={"evidence_ids": ["z", "a", "m"]})
        b = make_alert(evidence={"evidence_ids": ["a", "m", "z"]})
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_different_evidence_ids_produce_different_fingerprints(self):
        a = make_alert(evidence={"evidence_ids": ["e1"]})
        b = make_alert(evidence={"evidence_ids": ["e1", "e2"]})
        self.assertNotEqual(fingerprint(a), fingerprint(b))

    def test_actor_key_preferred_over_ip(self):
        a = make_alert(actor_key="alice", ip="10.0.0.1")
        b = make_alert(actor_key="alice", ip="10.0.0.2")
        self.assertEqual(fingerprint(a), fingerprint(b), "actor_key should win over ip when both are set")

    def test_falls_back_to_ip_when_actor_key_is_none(self):
        a = make_alert(actor_key=None, ip="10.0.0.1")
        b = make_alert(actor_key="10.0.0.1", ip=None)
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_falls_back_to_unknown_when_actor_key_and_ip_both_none(self):
        a = make_alert(actor_key=None, ip=None)
        b = make_alert(actor_key="unknown", ip=None)
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_falls_back_to_unknown_when_actor_key_and_ip_both_empty_string(self):
        # empty string is falsy in Python, same fallback path as None
        a = make_alert(actor_key="", ip="")
        b = make_alert(actor_key="unknown", ip=None)
        self.assertEqual(fingerprint(a), fingerprint(b))

    def test_matches_independently_computed_sha256(self):
        a = make_alert(
            alert_type="IDENTITY_MFA_FAILURE_BURST",
            severity="high",
            actor_key="alice",
            ip=None,
            evidence={"evidence_ids": ["e2", "e1"]},
        )
        expected_material = "|".join(["IDENTITY_MFA_FAILURE_BURST", "high", "alice", "e1,e2"])
        expected = hashlib.sha256(expected_material.encode("utf-8")).hexdigest()
        self.assertEqual(fingerprint(a), expected)

    def test_returns_a_64_char_hex_digest(self):
        fp = fingerprint(make_alert())
        self.assertEqual(len(fp), 64)
        int(fp, 16)  # raises ValueError if not valid hex


class AlertIdTest(unittest.TestCase):
    def test_uses_explicit_alert_id_when_present(self):
        a = make_alert(alert_id="explicit-id-123")
        self.assertEqual(alert_id(a, "deadbeef"), "explicit-id-123")

    def test_derives_from_fingerprint_when_alert_id_is_none(self):
        a = make_alert(alert_id=None)
        fp = "a" * 64
        self.assertEqual(alert_id(a, fp), "xdr-" + "a" * 40)

    def test_derived_id_truncates_fingerprint_to_40_chars(self):
        a = make_alert(alert_id=None)
        fp = fingerprint(a)
        derived = alert_id(a, fp)
        self.assertTrue(derived.startswith("xdr-"))
        self.assertEqual(len(derived), len("xdr-") + 40)

    def test_empty_string_alert_id_falls_back_to_derived(self):
        # empty string is falsy -> same fallback as None
        a = make_alert(alert_id="")
        fp = "b" * 64
        self.assertEqual(alert_id(a, fp), "xdr-" + "b" * 40)


if __name__ == "__main__":
    unittest.main()
