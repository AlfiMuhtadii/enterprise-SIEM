"""Tests for BACKLOG-SOC-013: Shadow Alert Consumer & Advisory Findings.

Covers the Python-side pieces:
- normalize_shadow_records: parses EndpointAlert batch payloads into advisory finding dicts
- shadow_fingerprint: deterministic, domain-stable fingerprint
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
    def test_deterministic_for_same_inputs(self):
        fp1 = aw.shadow_fingerprint("RULE_A", "host-1", ["e1", "e2"])
        fp2 = aw.shadow_fingerprint("RULE_A", "host-1", ["e1", "e2"])
        self.assertEqual(fp1, fp2)

    def test_differs_for_different_rule(self):
        fp1 = aw.shadow_fingerprint("RULE_A", "host-1", ["e1"])
        fp2 = aw.shadow_fingerprint("RULE_B", "host-1", ["e1"])
        self.assertNotEqual(fp1, fp2)

    def test_differs_for_different_actor(self):
        fp1 = aw.shadow_fingerprint("RULE_A", "host-1", ["e1"])
        fp2 = aw.shadow_fingerprint("RULE_A", "host-2", ["e1"])
        self.assertNotEqual(fp1, fp2)

    def test_evidence_ids_order_independent(self):
        fp1 = aw.shadow_fingerprint("RULE_A", "host-1", ["e1", "e2"])
        fp2 = aw.shadow_fingerprint("RULE_A", "host-1", ["e2", "e1"])
        self.assertEqual(fp1, fp2)

    def test_empty_evidence_ids_returns_stable_hash(self):
        fp = aw.shadow_fingerprint("RULE_A", "host-1", [])
        self.assertEqual(len(fp), 64)  # sha256 hex

    def test_empty_actor_uses_unknown(self):
        fp_empty  = aw.shadow_fingerprint("RULE_A", "", [])
        fp_none   = aw.shadow_fingerprint("RULE_A", None, [])
        self.assertEqual(fp_empty, fp_none)


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
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
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
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
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
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "write_advisory_finding", return_value="inserted"):
            aw.shadow_event_loop("xdr.alerts.shadow.endpoint")

        self.assertGreaterEqual(aw.METRICS["shadow_polls"], 1)
        self.assertEqual(aw.METRICS["shadow_findings_seen"], 1)

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
             patch.object(aw, "consumer_poll", side_effect=fake_poll):
            aw.shadow_event_loop("xdr.alerts.endpoint.test")

        # Should not raise; loop exits cleanly
        self.assertGreaterEqual(aw.METRICS["shadow_consumer_errors"], 3)


if __name__ == "__main__":
    unittest.main()
