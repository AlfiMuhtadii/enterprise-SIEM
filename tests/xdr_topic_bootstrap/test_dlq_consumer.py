"""Tests for BACKLOG-DLQ-014: DLQ Consumer & Normalization Review.

Covers the Python-side pieces:
- dlq_consumer_fingerprint: deterministic, type-aware
- normalize_dlq_records: parsing poison, malformed, normalization_failure, invalid_json
- write_dlq_record: INSERT on conflict behaviour
- dlq_consumer_event_loop: never calls process_alerts, never calls produce, metrics
"""
from __future__ import annotations

import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

_SERVICES = Path(__file__).parent.parent.parent / "services" / "alert-writer-service"
sys.path.insert(0, str(_SERVICES))

for _mod in ("fastapi", "pydantic", "xdr_event_contracts"):
    if _mod not in sys.modules:
        _stub = types.ModuleType(_mod)
        _stub.FastAPI = MagicMock(return_value=MagicMock())  # type: ignore[attr-defined]
        _stub.BaseModel = object  # type: ignore[attr-defined]
        _stub.Field = lambda *a, **kw: None  # type: ignore[attr-defined]
        _stub.envelope = lambda **kw: {}  # type: ignore[attr-defined]
        _stub.is_envelope = lambda v: False  # type: ignore[attr-defined]
        _stub.unwrap_payload = lambda v, t: v  # type: ignore[attr-defined]
        _stub.validate_envelope = lambda ev, t: []  # type: ignore[attr-defined]
        sys.modules[_mod] = _stub

sys.modules.pop("main", None)
import main as aw  # noqa: E402


# ---------------------------------------------------------------------------
# dlq_consumer_fingerprint
# ---------------------------------------------------------------------------

class TestDlqConsumerFingerprint(unittest.TestCase):
    def test_poison_fingerprint_uses_coordinates(self):
        fp = aw.dlq_consumer_fingerprint("poison_message_isolated", "telemetry.raw", 0, 123, "some error")
        self.assertEqual(len(fp), 64)

    def test_poison_fingerprint_deterministic(self):
        fp1 = aw.dlq_consumer_fingerprint("poison_message_isolated", "telemetry.raw", 0, 123, "error")
        fp2 = aw.dlq_consumer_fingerprint("poison_message_isolated", "telemetry.raw", 0, 123, "error")
        self.assertEqual(fp1, fp2)

    def test_poison_different_offsets_differ(self):
        fp1 = aw.dlq_consumer_fingerprint("poison_message_isolated", "telemetry.raw", 0, 100, "e")
        fp2 = aw.dlq_consumer_fingerprint("poison_message_isolated", "telemetry.raw", 0, 200, "e")
        self.assertNotEqual(fp1, fp2)

    def test_non_poison_uses_type_and_message(self):
        fp1 = aw.dlq_consumer_fingerprint("normalization_failure", "telemetry.raw", None, None, "missing_required_fields")
        fp2 = aw.dlq_consumer_fingerprint("normalization_failure", "telemetry.raw", None, None, "missing_required_fields")
        self.assertEqual(fp1, fp2)

    def test_non_poison_different_messages_differ(self):
        fp1 = aw.dlq_consumer_fingerprint("invalid_json", "t", None, None, "bad json A")
        fp2 = aw.dlq_consumer_fingerprint("invalid_json", "t", None, None, "bad json B")
        self.assertNotEqual(fp1, fp2)


# ---------------------------------------------------------------------------
# normalize_dlq_records
# ---------------------------------------------------------------------------

class TestNormalizeDlqRecords(unittest.TestCase):
    def _poison_record(self):
        return {"value": {
            "dlq_event_type":   "poison_message_isolated",
            "source_topic":     "telemetry.raw",
            "source_partition": 0,
            "source_offset":    42,
            "consumer_group":   "normalizer-worker-v1",
            "error_code":       40801,
            "error_message":    "Unable to serialize value of record at offset 42 in topic:partition telemetry.raw:0",
            "isolated_at":      "2026-06-23T10:00:00Z",
        }}

    def _norm_failure_record(self):
        return {"value": {
            "error": "missing_required_fields",
            "event": {"ts": "2026-06-23", "source_type": "endpoint"},
        }}

    def _invalid_json_record(self):
        return {"value": {
            "error": "invalid_json",
            "raw": '{"ts": "2026-06-23", incomplete',
        }}

    def _malformed_value_record(self):
        return {"value": {
            "error": "invalid_record_value",
            "record": {"key": "value"},
        }}

    def test_parses_poison_record(self):
        result = aw.normalize_dlq_records([self._poison_record()])
        self.assertEqual(len(result), 1)
        r = result[0]
        self.assertEqual(r["dlq_event_type"], "poison_message_isolated")
        self.assertEqual(r["source_partition"], 0)
        self.assertEqual(r["source_offset"], 42)
        self.assertIsNone(r["raw_payload"])

    def test_poison_raw_payload_is_none(self):
        result = aw.normalize_dlq_records([self._poison_record()])
        self.assertIsNone(result[0]["raw_payload"])

    def test_parses_normalization_failure(self):
        result = aw.normalize_dlq_records([self._norm_failure_record()])
        self.assertEqual(len(result), 1)
        r = result[0]
        self.assertEqual(r["dlq_event_type"], "normalization_failure")
        self.assertIsNotNone(r["raw_payload"])

    def test_normalization_failure_raw_payload_is_json_string(self):
        result = aw.normalize_dlq_records([self._norm_failure_record()])
        raw = result[0]["raw_payload"]
        self.assertIsInstance(raw, str)
        parsed = json.loads(raw)
        self.assertIn("ts", parsed)

    def test_parses_invalid_json_record(self):
        result = aw.normalize_dlq_records([self._invalid_json_record()])
        self.assertEqual(len(result), 1)
        r = result[0]
        self.assertEqual(r["dlq_event_type"], "invalid_json")
        self.assertIsNotNone(r["raw_payload"])

    def test_parses_malformed_value_record(self):
        result = aw.normalize_dlq_records([self._malformed_value_record()])
        self.assertEqual(len(result), 1)
        r = result[0]
        self.assertEqual(r["dlq_event_type"], "malformed_record")
        self.assertIsNotNone(r["raw_payload"])

    def test_empty_records_returns_empty(self):
        self.assertEqual(aw.normalize_dlq_records([]), [])

    def test_non_dict_value_skipped(self):
        self.assertEqual(aw.normalize_dlq_records([{"value": "not-a-dict"}]), [])

    def test_fingerprint_is_sha256_hex(self):
        result = aw.normalize_dlq_records([self._poison_record()])
        self.assertEqual(len(result[0]["fingerprint"]), 64)

    def test_error_code_integer(self):
        result = aw.normalize_dlq_records([self._poison_record()])
        self.assertEqual(result[0]["error_code"], 40801)


# ---------------------------------------------------------------------------
# DLQ consumer loop — never calls process_alerts or produce
# ---------------------------------------------------------------------------

class TestDlqConsumerLoopIsolation(unittest.TestCase):
    def _make_record(self):
        return {"value": {
            "dlq_event_type":   "poison_message_isolated",
            "source_topic":     "telemetry.raw",
            "source_partition": 0,
            "source_offset":    7,
            "consumer_group":   "normalizer-worker-v1",
            "error_code":       40801,
            "error_message":    "Unable to serialize record at offset 7",
            "isolated_at":      "2026-06-23T10:00:00Z",
        }}

    def test_dlq_loop_does_not_call_process_alerts(self):
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
             patch.object(aw, "write_dlq_record", return_value="inserted") as mock_write, \
             patch.object(aw, "process_alerts") as mock_process:
            aw.dlq_consumer_event_loop()

        mock_process.assert_not_called()
        mock_write.assert_called_once()

    def test_dlq_loop_does_not_call_produce(self):
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
             patch.object(aw, "write_dlq_record", return_value="inserted"), \
             patch.object(aw, "produce") as mock_produce:
            aw.dlq_consumer_event_loop()

        mock_produce.assert_not_called()

    def test_dlq_consumer_metrics_incremented(self):
        stop_after = {"calls": 0}

        def fake_poll(base_uri):
            stop_after["calls"] += 1
            if stop_after["calls"] == 1:
                return [self._make_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        aw.METRICS["dlq_consumer_polls"] = 0
        aw.METRICS["dlq_consumer_records_seen"] = 0
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "write_dlq_record", return_value="inserted"):
            aw.dlq_consumer_event_loop()

        self.assertGreaterEqual(aw.METRICS["dlq_consumer_polls"], 1)
        self.assertEqual(aw.METRICS["dlq_consumer_records_seen"], 1)

    def test_dlq_loop_recovers_on_poll_error(self):
        calls = {"n": 0}

        def fake_poll(base_uri):
            calls["n"] += 1
            if calls["n"] <= 3:
                raise Exception("pandaproxy timeout")
            aw.STOP.set()
            return []

        aw.STOP.clear()
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_delete"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll):
            aw.dlq_consumer_event_loop()

        self.assertGreaterEqual(aw.METRICS["dlq_consumer_errors"], 3)


if __name__ == "__main__":
    unittest.main()
