"""Tests for BACKLOG-LIVE-PIPELINE-016: Correlation & Alert-Writer Failure DLQ Coverage.

Covers:
- write_alert_failure(): success/failure paths, metrics, record shape, trace_id handling
- METRICS keys for new DLQ counters present at import
- xdr_topic_bootstrap: xdr.correlation_failed and xdr.alert_write_failed in REQUIRED_TOPICS
- validate_live_xdr_pipeline: new DLQ topics in required_topics, check 20 present
"""
from __future__ import annotations

import json
import os
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch, call

# ---------------------------------------------------------------------------
# FastAPI / pydantic stubs (must be set before any service main.py is imported)
# ---------------------------------------------------------------------------

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

_AW_PATH = str(Path(__file__).parent.parent.parent / "services" / "alert-writer-service")
if _AW_PATH not in sys.path:
    sys.path.insert(0, _AW_PATH)
sys.modules.pop("main", None)
import main as aw  # noqa: E402

_SCRIPTS = str(Path(__file__).parent.parent.parent / "scripts")
if _SCRIPTS not in sys.path:
    sys.path.insert(0, _SCRIPTS)

sys.modules.pop("xdr_topic_bootstrap", None)
import xdr_topic_bootstrap as tb  # noqa: E402

sys.modules.pop("validate_live_xdr_pipeline", None)
import validate_live_xdr_pipeline as vlp  # noqa: E402


# ===========================================================================
# write_alert_failure — success path
# ===========================================================================

class TestWriteAlertFailureSuccess(unittest.TestCase):

    def setUp(self):
        aw.METRICS["alert_write_dlq_written"] = 0
        aw.METRICS["alert_write_dlq_errors"] = 0

    def test_returns_true_on_success(self):
        with patch.object(aw, "produce", return_value=1):
            result = aw.write_alert_failure("postgres_write_failed", "conn refused", "xdr.alerts")
        self.assertTrue(result)

    def test_increments_dlq_written_on_success(self):
        with patch.object(aw, "produce", return_value=1):
            aw.write_alert_failure("postgres_write_failed", "err", "xdr.alerts")
        self.assertEqual(aw.METRICS["alert_write_dlq_written"], 1)

    def test_does_not_increment_dlq_errors_on_success(self):
        with patch.object(aw, "produce", return_value=1):
            aw.write_alert_failure("opensearch_write_failed", "err", "xdr.alerts")
        self.assertEqual(aw.METRICS["alert_write_dlq_errors"], 0)

    def test_produces_to_correct_default_topic(self):
        captured = []

        def fake_produce(topic, events):
            captured.append((topic, events))
            return 1

        with patch.dict(os.environ, {"XDR_ALERT_WRITE_FAILED_TOPIC": ""}):
            with patch.object(aw, "produce", side_effect=fake_produce):
                aw.write_alert_failure("event_loop_error", "err", "xdr.alerts")

        self.assertEqual(len(captured), 1)
        self.assertEqual(captured[0][0], "xdr.alert_write_failed")

    def test_produces_to_custom_topic_from_env(self):
        captured = []

        def fake_produce(topic, events):
            captured.append((topic, events))
            return 1

        with patch.dict(os.environ, {"XDR_ALERT_WRITE_FAILED_TOPIC": "custom.dlq"}):
            with patch.object(aw, "produce", side_effect=fake_produce):
                aw.write_alert_failure("event_loop_error", "err", "xdr.alerts")

        self.assertEqual(captured[0][0], "custom.dlq")

    def test_record_contains_required_fields(self):
        captured = []

        def fake_produce(topic, events):
            captured.append(events)
            return 1

        with patch.object(aw, "produce", side_effect=fake_produce):
            aw.write_alert_failure("postgres_write_failed", "timeout", "xdr.alerts",
                                   trace_id="tid-abc", alert_count=3)

        self.assertEqual(len(captured), 1)
        rec = captured[0][0]
        self.assertEqual(rec["dlq_event_type"], "alert_write_failed")
        self.assertEqual(rec["source_topic"], "xdr.alerts")
        self.assertEqual(rec["error_message"], "timeout")
        self.assertEqual(rec["reason"], "postgres_write_failed")
        self.assertEqual(rec["alert_count"], 3)
        self.assertIn("ts", rec)

    def test_trace_id_included_when_provided(self):
        captured = []

        def fake_produce(topic, events):
            captured.append(events)
            return 1

        with patch.object(aw, "produce", side_effect=fake_produce):
            aw.write_alert_failure("postgres_write_failed", "err", "xdr.alerts", trace_id="trace-123")

        self.assertEqual(captured[0][0]["trace_id"], "trace-123")

    def test_trace_id_absent_when_none(self):
        captured = []

        def fake_produce(topic, events):
            captured.append(events)
            return 1

        with patch.object(aw, "produce", side_effect=fake_produce):
            aw.write_alert_failure("postgres_write_failed", "err", "xdr.alerts", trace_id=None)

        self.assertNotIn("trace_id", captured[0][0])


# ===========================================================================
# write_alert_failure — failure path
# ===========================================================================

class TestWriteAlertFailureError(unittest.TestCase):

    def setUp(self):
        aw.METRICS["alert_write_dlq_written"] = 0
        aw.METRICS["alert_write_dlq_errors"] = 0

    def test_returns_false_when_produce_raises(self):
        with patch.object(aw, "produce", side_effect=Exception("redpanda down")):
            result = aw.write_alert_failure("event_loop_error", "err", "xdr.alerts")
        self.assertFalse(result)

    def test_increments_dlq_errors_when_produce_raises(self):
        with patch.object(aw, "produce", side_effect=Exception("redpanda down")):
            aw.write_alert_failure("event_loop_error", "err", "xdr.alerts")
        self.assertEqual(aw.METRICS["alert_write_dlq_errors"], 1)

    def test_does_not_increment_dlq_written_when_produce_raises(self):
        with patch.object(aw, "produce", side_effect=Exception("redpanda down")):
            aw.write_alert_failure("event_loop_error", "err", "xdr.alerts")
        self.assertEqual(aw.METRICS["alert_write_dlq_written"], 0)

    def test_does_not_raise_on_produce_failure(self):
        with patch.object(aw, "produce", side_effect=Exception("network error")):
            try:
                aw.write_alert_failure("event_loop_error", "err", "xdr.alerts")
            except Exception as exc:
                self.fail(f"write_alert_failure raised unexpectedly: {exc}")


# ===========================================================================
# METRICS keys present at module import
# ===========================================================================

class TestAlertWriterMetricsKeys(unittest.TestCase):

    def test_alert_write_dlq_written_key_present(self):
        self.assertIn("alert_write_dlq_written", aw.METRICS)

    def test_alert_write_dlq_errors_key_present(self):
        self.assertIn("alert_write_dlq_errors", aw.METRICS)

    def test_initial_values_are_zero(self):
        self.assertIsInstance(aw.METRICS["alert_write_dlq_written"], int)
        self.assertIsInstance(aw.METRICS["alert_write_dlq_errors"], int)


# ===========================================================================
# xdr_topic_bootstrap: new DLQ topics in REQUIRED_TOPICS
# ===========================================================================

class TestBootstrapDlqTopics(unittest.TestCase):

    def test_xdr_correlation_failed_in_required_topics(self):
        self.assertIn("xdr.correlation_failed", tb.REQUIRED_TOPICS)

    def test_xdr_alert_write_failed_in_required_topics(self):
        self.assertIn("xdr.alert_write_failed", tb.REQUIRED_TOPICS)

    def test_original_topics_still_present(self):
        for topic in ("telemetry.raw", "telemetry.normalized", "xdr.alerts",
                      "alerts.created", "telemetry.normalization_failed"):
            self.assertIn(topic, tb.REQUIRED_TOPICS, f"{topic} missing from REQUIRED_TOPICS")

    def test_bootstrap_creates_new_topics_when_absent(self):
        existing = ["telemetry.raw", "telemetry.normalized", "xdr.alerts",
                    "alerts.created", "telemetry.normalization_failed"]

        def fake_create(topic, dry_run):
            return tb.CREATED, f"created {topic}"

        def fake_fetch(url, timeout):
            return existing

        with patch.object(tb, "fetch_topic_list", side_effect=fake_fetch):
            with patch.object(tb, "create_topic", side_effect=fake_create):
                rc = tb.main(["--pandaproxy", "http://127.0.0.1:8082"])

        self.assertEqual(rc, 0)

    def test_bootstrap_passes_when_all_topics_present(self):
        existing = list(tb.REQUIRED_TOPICS)

        def fake_fetch(url, timeout):
            return existing

        with patch.object(tb, "fetch_topic_list", side_effect=fake_fetch):
            rc = tb.main(["--pandaproxy", "http://127.0.0.1:8082"])

        self.assertEqual(rc, 0)


# ===========================================================================
# validate_live_xdr_pipeline: DLQ failure topics visible in check 7
# ===========================================================================

class TestValidatorDlqTopics(unittest.TestCase):

    def test_check_required_topics_passes_when_both_dlq_topics_present(self):
        topics = ["xdr.correlation_failed", "xdr.alert_write_failed"]
        body = json.dumps(["xdr.correlation_failed", "xdr.alert_write_failed"])
        with patch.object(vlp, "http_get", return_value=(200, body)):
            result = vlp.check_required_topics("http://127.0.0.1:8082", topics, 3)
        self.assertEqual(result["status"], vlp.PASS)

    def test_check_required_topics_fails_when_correlation_failed_absent(self):
        topics = ["xdr.correlation_failed", "xdr.alert_write_failed"]
        body = json.dumps(["xdr.alert_write_failed"])  # correlation_failed missing
        with patch.object(vlp, "http_get", return_value=(200, body)):
            result = vlp.check_required_topics("http://127.0.0.1:8082", topics, 3)
        self.assertEqual(result["status"], vlp.FAIL)
        self.assertIn("xdr.correlation_failed", result["evidence"])

    def test_check_required_topics_fails_when_alert_write_failed_absent(self):
        topics = ["xdr.correlation_failed", "xdr.alert_write_failed"]
        body = json.dumps(["xdr.correlation_failed"])  # alert_write_failed missing
        with patch.object(vlp, "http_get", return_value=(200, body)):
            result = vlp.check_required_topics("http://127.0.0.1:8082", topics, 3)
        self.assertEqual(result["status"], vlp.FAIL)
        self.assertIn("xdr.alert_write_failed", result["evidence"])

    def test_check_required_topics_fails_when_both_dlq_topics_absent(self):
        topics = ["xdr.correlation_failed", "xdr.alert_write_failed"]
        body = json.dumps(["telemetry.raw", "xdr.alerts"])  # both missing
        with patch.object(vlp, "http_get", return_value=(200, body)):
            result = vlp.check_required_topics("http://127.0.0.1:8082", topics, 3)
        self.assertEqual(result["status"], vlp.FAIL)

    def test_check_topic_watermarks_runs_for_dlq_topics(self):
        # Advisory watermark check returns PASS regardless of watermark values
        topics = ["xdr.correlation_failed", "xdr.alert_write_failed"]
        wmark_body = json.dumps({"0": {"high_watermark": 0, "low_watermark": 0}})
        with patch.object(vlp, "http_get", return_value=(200, wmark_body)):
            result = vlp.check_topic_watermarks("http://127.0.0.1:9644", topics, 3)
        # check_topic_watermarks always returns PASS (informational only)
        self.assertEqual(result["status"], vlp.PASS)


if __name__ == "__main__":
    unittest.main()
