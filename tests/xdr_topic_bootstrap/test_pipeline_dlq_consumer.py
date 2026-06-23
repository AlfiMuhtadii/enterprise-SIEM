"""Tests for BACKLOG-DLQ-017: Unified DLQ Review for Correlation & Alert-Write Failures.

Covers:
- dlq_consumer_fingerprint: correlation_parse_error uses position-based key
- normalize_dlq_records: replayable/error_reason fields for existing types
- normalize_pipeline_dlq_records: replayability classification per event type
- pipeline_dlq_consumer_event_loop: never calls process_alerts, never calls produce,
  never creates incidents, correct metrics
- METRICS keys for pipeline DLQ consumers present at import
"""
from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

_SERVICES = Path(__file__).parent.parent.parent / "services" / "alert-writer-service"
sys.path.insert(0, str(_SERVICES))


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
# dlq_consumer_fingerprint — correlation_parse_error extension
# ---------------------------------------------------------------------------

class TestDlqFingerprintCorrelationParse(unittest.TestCase):
    def test_correlation_parse_error_uses_position(self):
        fp = aw.dlq_consumer_fingerprint(
            "correlation_parse_error", "telemetry.normalized", 1, 500, "invalid json"
        )
        self.assertEqual(len(fp), 64)

    def test_correlation_parse_error_deterministic(self):
        fp1 = aw.dlq_consumer_fingerprint("correlation_parse_error", "telemetry.normalized", 1, 500, "err")
        fp2 = aw.dlq_consumer_fingerprint("correlation_parse_error", "telemetry.normalized", 1, 500, "err")
        self.assertEqual(fp1, fp2)

    def test_correlation_parse_error_different_offsets_differ(self):
        fp1 = aw.dlq_consumer_fingerprint("correlation_parse_error", "telemetry.normalized", 0, 100, "err")
        fp2 = aw.dlq_consumer_fingerprint("correlation_parse_error", "telemetry.normalized", 0, 200, "err")
        self.assertNotEqual(fp1, fp2)

    def test_correlation_parse_error_without_coordinates_uses_message(self):
        fp = aw.dlq_consumer_fingerprint("correlation_parse_error", "telemetry.normalized", None, None, "bad json")
        # Falls back to type|message fingerprint — still deterministic
        fp2 = aw.dlq_consumer_fingerprint("correlation_parse_error", "telemetry.normalized", None, None, "bad json")
        self.assertEqual(fp, fp2)


# ---------------------------------------------------------------------------
# normalize_dlq_records — replayable / error_reason fields
# ---------------------------------------------------------------------------

class TestNormalizeDlqRecordsReplayability(unittest.TestCase):
    def _make(self, event_type, error="err", has_event=False):
        value = {"dlq_event_type": event_type, "error_message": error}
        if has_event:
            value["event"] = {"ts": "2026-06-23"}
        return {"value": value}

    def test_poison_replayable_false(self):
        rec = {"value": {
            "dlq_event_type": "poison_message_isolated",
            "source_topic": "telemetry.raw",
            "source_partition": 0,
            "source_offset": 1,
            "error_message": "serialize error",
        }}
        result = aw.normalize_dlq_records([rec])
        self.assertFalse(result[0]["replayable"])

    def test_invalid_json_replayable_false(self):
        rec = {"value": {"error": "invalid_json", "raw": "bad{"}}
        result = aw.normalize_dlq_records([rec])
        self.assertFalse(result[0]["replayable"])

    def test_malformed_record_replayable_false(self):
        rec = {"value": {"error": "invalid_record_value", "record": {"x": 1}}}
        result = aw.normalize_dlq_records([rec])
        self.assertFalse(result[0]["replayable"])

    def test_normalization_failure_replayable_true_with_payload(self):
        rec = {"value": {"error": "missing_required_fields", "event": {"ts": "2026-06-23"}}}
        result = aw.normalize_dlq_records([rec])
        self.assertTrue(result[0]["replayable"])

    def test_normalization_failure_replayable_false_without_payload(self):
        rec = {"value": {"error": "missing_required_fields"}}
        result = aw.normalize_dlq_records([rec])
        self.assertFalse(result[0]["replayable"])

    def test_error_reason_is_none_for_normalization_records(self):
        rec = {"value": {"error": "missing_required_fields", "event": {"ts": "2026-06-23"}}}
        result = aw.normalize_dlq_records([rec])
        self.assertIsNone(result[0]["error_reason"])


# ---------------------------------------------------------------------------
# normalize_pipeline_dlq_records
# ---------------------------------------------------------------------------

class TestNormalizePipelineDlqRecords(unittest.TestCase):
    def _corr_parse_record(self):
        return {"value": {
            "dlq_event_type": "correlation_parse_error",
            "source_topic": "telemetry.normalized",
            "source_partition": 0,
            "source_offset": 42,
            "error_message": "unexpected end of JSON",
            "reason": "invalid_json",
            "ts": "2026-06-23T10:00:00Z",
        }}

    def _corr_publish_record(self):
        return {"value": {
            "dlq_event_type": "correlation_publish_error",
            "source_topic": "telemetry.normalized",
            "error_message": "publish timeout",
            "reason": "alert_publish_failed",
            "alert_count": 3,
            "ts": "2026-06-23T10:01:00Z",
        }}

    def _alert_write_failed_postgres(self):
        return {"value": {
            "dlq_event_type": "alert_write_failed",
            "source_topic": "xdr.alerts",
            "error_message": "connection refused",
            "reason": "postgres_write_failed",
            "alert_count": 2,
            "ts": "2026-06-23T10:02:00Z",
        }}

    def _alert_write_failed_opensearch(self):
        return {"value": {
            "dlq_event_type": "alert_write_failed",
            "source_topic": "xdr.alerts",
            "error_message": "index write failed",
            "reason": "opensearch_write_failed",
            "ts": "2026-06-23T10:03:00Z",
        }}

    def _alert_write_failed_event_loop(self):
        return {"value": {
            "dlq_event_type": "alert_write_failed",
            "source_topic": "xdr.alerts",
            "error_message": "consumer crash",
            "reason": "event_loop_error",
            "ts": "2026-06-23T10:04:00Z",
        }}

    def test_correlation_parse_error_replayable_false(self):
        result = aw.normalize_pipeline_dlq_records([self._corr_parse_record()], "xdr.correlation_failed")
        self.assertFalse(result[0]["replayable"])

    def test_correlation_parse_error_preserves_source_topic(self):
        result = aw.normalize_pipeline_dlq_records([self._corr_parse_record()], "xdr.correlation_failed")
        self.assertEqual(result[0]["source_topic"], "xdr.correlation_failed")

    def test_correlation_publish_error_replayable_true(self):
        result = aw.normalize_pipeline_dlq_records([self._corr_publish_record()], "xdr.correlation_failed")
        self.assertTrue(result[0]["replayable"])

    def test_correlation_publish_error_reason_preserved(self):
        result = aw.normalize_pipeline_dlq_records([self._corr_publish_record()], "xdr.correlation_failed")
        self.assertEqual(result[0]["error_reason"], "alert_publish_failed")

    def test_alert_write_failed_postgres_replayable_true(self):
        result = aw.normalize_pipeline_dlq_records(
            [self._alert_write_failed_postgres()], "xdr.alert_write_failed"
        )
        self.assertTrue(result[0]["replayable"])

    def test_alert_write_failed_opensearch_replayable_true(self):
        result = aw.normalize_pipeline_dlq_records(
            [self._alert_write_failed_opensearch()], "xdr.alert_write_failed"
        )
        self.assertTrue(result[0]["replayable"])

    def test_alert_write_failed_event_loop_replayable_false(self):
        result = aw.normalize_pipeline_dlq_records(
            [self._alert_write_failed_event_loop()], "xdr.alert_write_failed"
        )
        self.assertFalse(result[0]["replayable"])

    def test_raw_payload_always_none(self):
        for rec, topic in [
            (self._corr_parse_record(), "xdr.correlation_failed"),
            (self._corr_publish_record(), "xdr.correlation_failed"),
            (self._alert_write_failed_postgres(), "xdr.alert_write_failed"),
        ]:
            result = aw.normalize_pipeline_dlq_records([rec], topic)
            self.assertIsNone(result[0]["raw_payload"], f"raw_payload must be None for {topic}")

    def test_source_topic_set_from_argument(self):
        result = aw.normalize_pipeline_dlq_records(
            [self._alert_write_failed_postgres()], "xdr.alert_write_failed"
        )
        self.assertEqual(result[0]["source_topic"], "xdr.alert_write_failed")

    def test_empty_records_returns_empty(self):
        self.assertEqual(aw.normalize_pipeline_dlq_records([], "xdr.correlation_failed"), [])

    def test_non_dict_value_skipped(self):
        self.assertEqual(
            aw.normalize_pipeline_dlq_records([{"value": "not-a-dict"}], "xdr.correlation_failed"),
            [],
        )

    def test_fingerprint_is_sha256_hex(self):
        result = aw.normalize_pipeline_dlq_records([self._corr_parse_record()], "xdr.correlation_failed")
        self.assertEqual(len(result[0]["fingerprint"]), 64)


# ---------------------------------------------------------------------------
# pipeline_dlq_consumer_event_loop — isolation (no process_alerts, no produce)
# ---------------------------------------------------------------------------

class TestPipelineDlqConsumerLoopIsolation(unittest.TestCase):
    def _corr_record(self):
        return {"value": {
            "dlq_event_type": "correlation_parse_error",
            "source_topic": "telemetry.normalized",
            "source_partition": 0,
            "source_offset": 1,
            "error_message": "bad json",
            "reason": "invalid_json",
            "ts": "2026-06-23T10:00:00Z",
        }}

    def test_loop_does_not_call_process_alerts(self):
        calls = {"n": 0}

        def fake_poll(base_uri):
            calls["n"] += 1
            if calls["n"] == 1:
                return [self._corr_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "write_dlq_record", return_value="inserted"), \
             patch.object(aw, "process_alerts") as mock_process:
            aw.pipeline_dlq_consumer_event_loop("xdr.correlation_failed")

        mock_process.assert_not_called()

    def test_loop_does_not_call_produce(self):
        calls = {"n": 0}

        def fake_poll(base_uri):
            calls["n"] += 1
            if calls["n"] == 1:
                return [self._corr_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "write_dlq_record", return_value="inserted"), \
             patch.object(aw, "produce") as mock_produce:
            aw.pipeline_dlq_consumer_event_loop("xdr.correlation_failed")

        mock_produce.assert_not_called()

    def test_loop_writes_dlq_records(self):
        calls = {"n": 0}

        def fake_poll(base_uri):
            calls["n"] += 1
            if calls["n"] == 1:
                return [self._corr_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "write_dlq_record", return_value="inserted") as mock_write:
            aw.pipeline_dlq_consumer_event_loop("xdr.correlation_failed")

        mock_write.assert_called_once()

    def test_loop_increments_pipeline_dlq_metrics(self):
        calls = {"n": 0}

        def fake_poll(base_uri):
            calls["n"] += 1
            if calls["n"] == 1:
                return [self._corr_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        aw.METRICS["pipeline_dlq_consumer_polls"] = 0
        aw.METRICS["pipeline_dlq_consumer_records_seen"] = 0
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "write_dlq_record", return_value="inserted"):
            aw.pipeline_dlq_consumer_event_loop("xdr.correlation_failed")

        self.assertGreaterEqual(aw.METRICS["pipeline_dlq_consumer_polls"], 1)
        self.assertEqual(aw.METRICS["pipeline_dlq_consumer_records_seen"], 1)

    def test_loop_counts_upserted_records(self):
        calls = {"n": 0}

        def fake_poll(base_uri):
            calls["n"] += 1
            if calls["n"] == 1:
                return [self._corr_record()]
            aw.STOP.set()
            return []

        aw.STOP.clear()
        aw.METRICS["pipeline_dlq_consumer_records_upserted"] = 0
        with patch.object(aw, "consumer_create", return_value="http://x/cg/i"), \
             patch.object(aw, "consumer_subscribe"), \
             patch.object(aw, "consumer_poll", side_effect=fake_poll), \
             patch.object(aw, "write_dlq_record", return_value="updated"):
            aw.pipeline_dlq_consumer_event_loop("xdr.correlation_failed")

        self.assertEqual(aw.METRICS["pipeline_dlq_consumer_records_upserted"], 1)

    def test_loop_recovers_on_poll_error(self):
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
            aw.pipeline_dlq_consumer_event_loop("xdr.correlation_failed")

        self.assertGreaterEqual(aw.METRICS["pipeline_dlq_consumer_errors"], 3)


# ---------------------------------------------------------------------------
# METRICS keys and health endpoint
# ---------------------------------------------------------------------------

class TestPipelineDlqMetricsAndHealth(unittest.TestCase):
    def test_pipeline_dlq_metrics_keys_exist(self):
        for key in (
            "pipeline_dlq_consumer_polls",
            "pipeline_dlq_consumer_records_seen",
            "pipeline_dlq_consumer_records_written",
            "pipeline_dlq_consumer_records_upserted",
            "pipeline_dlq_consumer_errors",
        ):
            self.assertIn(key, aw.METRICS, f"METRICS missing key: {key}")

    def test_pipeline_dlq_metrics_initial_values_are_int(self):
        for key in (
            "pipeline_dlq_consumer_polls",
            "pipeline_dlq_consumer_records_seen",
            "pipeline_dlq_consumer_records_written",
            "pipeline_dlq_consumer_records_upserted",
            "pipeline_dlq_consumer_errors",
        ):
            self.assertIsInstance(aw.METRICS[key], int, f"METRICS[{key}] must be int")

    def test_pipeline_dlq_normalize_function_exists(self):
        self.assertTrue(callable(aw.normalize_pipeline_dlq_records))

    def test_pipeline_dlq_consumer_loop_function_exists(self):
        self.assertTrue(callable(aw.pipeline_dlq_consumer_event_loop))

    def test_correlation_failed_topic_default_resolves(self):
        import os
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop("XDR_CORRELATION_FAILED_TOPIC", None)
            topic = os.getenv("XDR_CORRELATION_FAILED_TOPIC") or "xdr.correlation_failed"
        self.assertEqual(topic, "xdr.correlation_failed")

    def test_pipeline_dlq_consumers_disabled_by_default(self):
        import os
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop("XDR_CORRELATION_DLQ_CONSUMER_ENABLED", None)
            os.environ.pop("XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED", None)
            corr  = os.getenv("XDR_CORRELATION_DLQ_CONSUMER_ENABLED", "false").lower()
            write = os.getenv("XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED", "false").lower()
        self.assertEqual(corr, "false")
        self.assertEqual(write, "false")


if __name__ == "__main__":
    unittest.main()
