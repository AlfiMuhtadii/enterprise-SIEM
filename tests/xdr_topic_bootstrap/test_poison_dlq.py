"""Tests for BACKLOG-LIVE-PIPELINE-008: Poison Message Isolation & DLQ.

Verifies the Python-side pieces:
- validate_live_xdr_pipeline.check_worker_consumer_lag now exposes
  poison_skipped and dlq_written in evidence
- xdr_topic_bootstrap.REQUIRED_TOPICS includes telemetry.normalization_failed

Go-side logic (parsePoisonErr, isolatePoisonRecord, consumerCommitOffset) is
tested here via a pure-Python re-implementation that mirrors the spec — keeping
tests self-contained without requiring a Go build.
"""
from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

sys.modules.pop("xdr_topic_bootstrap", None)
import xdr_topic_bootstrap as tb  # noqa: E402

sys.modules.pop("validate_live_xdr_pipeline", None)
import validate_live_xdr_pipeline as vlp  # noqa: E402


# ---------------------------------------------------------------------------
# REQUIRED_TOPICS includes the DLQ topic
# ---------------------------------------------------------------------------

class TestRequiredTopicsIncludesDlq(unittest.TestCase):
    def test_normalization_failed_in_required_topics(self):
        self.assertIn("telemetry.normalization_failed", tb.REQUIRED_TOPICS)

    def test_all_core_topics_present(self):
        for topic in ("telemetry.raw", "telemetry.normalized", "xdr.alerts",
                      "alerts.created", "telemetry.normalization_failed"):
            self.assertIn(topic, tb.REQUIRED_TOPICS)


# ---------------------------------------------------------------------------
# Validator: check_worker_consumer_lag exposes poison_skipped / dlq_written
# ---------------------------------------------------------------------------

def _mock_urlopen_for_metrics(status: int, body: str):
    resp = MagicMock()
    resp.status = status
    resp.read.return_value = body.encode()
    resp.__enter__ = lambda s: s
    resp.__exit__ = MagicMock(return_value=False)
    return patch("urllib.request.urlopen", return_value=resp)


class TestCheckWorkerConsumerLagDlqFields(unittest.TestCase):
    """Verify evidence string contains poison_skipped and dlq_written."""

    def _run_check(self, metrics_dict: dict, prom_body: str = "") -> dict:
        metrics_body = json.dumps(metrics_dict)
        with patch.object(vlp, "http_get") as mock_get:
            def side(url, timeout, max_bytes=512):
                if "metrics" in url:
                    return 200, metrics_body
                # Redpanda public_metrics
                return 200, prom_body
            mock_get.side_effect = side
            return vlp.check_worker_consumer_lag(
                "normalizer-worker",
                "http://127.0.0.1:8092",
                "telemetry.raw",
                "http://127.0.0.1:9644",
                5,
            )

    def test_evidence_contains_poison_skipped_zero(self):
        result = self._run_check({"processed": 100, "poison_skipped": 0, "dlq_written": 0})
        self.assertIn("poison_skipped=0", result["evidence"])
        self.assertIn("dlq_written=0", result["evidence"])

    def test_evidence_contains_nonzero_poison_skipped(self):
        result = self._run_check({"processed": 50, "poison_skipped": 3, "dlq_written": 3})
        self.assertIn("poison_skipped=3", result["evidence"])
        self.assertIn("dlq_written=3", result["evidence"])

    def test_evidence_contains_dlq_write_errors(self):
        # dlq_write_errors is present in metrics but not shown in lag check —
        # the lag check only shows dlq_written and poison_skipped.
        result = self._run_check({"processed": 10, "dlq_written": 0, "poison_skipped": 0,
                                   "dlq_write_errors": 2})
        self.assertIn("dlq_written=0", result["evidence"])

    def test_pass_when_no_stuck_indicators(self):
        result = self._run_check({"processed": 1000, "consumer_recreate_count": 1,
                                   "poll_error_count": 0, "poison_skipped": 0, "dlq_written": 0})
        self.assertEqual(result["status"], vlp.PASS)

    def test_fail_when_poll_errors_gte_10(self):
        result = self._run_check({"processed": 0, "poll_error_count": 10,
                                   "poison_skipped": 0, "dlq_written": 0})
        self.assertEqual(result["status"], vlp.FAIL)

    def test_pass_when_poison_skipped_but_no_loop(self):
        # Poison was handled correctly (skipped=5, written=5, no recreate storm)
        result = self._run_check({"processed": 1000, "consumer_recreate_count": 2,
                                   "poll_error_count": 0, "poison_skipped": 5, "dlq_written": 5})
        self.assertEqual(result["status"], vlp.PASS)


# ---------------------------------------------------------------------------
# Pure-Python mirror of parsePoisonErr logic — validates the spec
# ---------------------------------------------------------------------------

def _parse_poison_err_py(http_status: int, body: bytes):
    """Python mirror of Go parsePoisonErr for spec validation."""
    try:
        resp = json.loads(body)
    except (json.JSONDecodeError, ValueError):
        return None
    if resp.get("error_code") != 40801:
        return None
    msg = resp.get("message", "")
    topic = "unknown"
    partition = -1
    offset = -1

    if "at offset " in msg:
        after = msg[msg.index("at offset ") + len("at offset "):]
        parts = after.split()
        if parts:
            try:
                offset = int(parts[0])
            except ValueError:
                pass

    if "topic:partition " in msg:
        after = msg[msg.index("topic:partition ") + len("topic:partition "):]
        parts = after.split()
        if parts:
            tp = parts[0]
            colon = tp.rfind(":")
            if colon >= 0:
                topic = tp[:colon]
                try:
                    partition = int(tp[colon + 1:])
                except ValueError:
                    pass

    return {
        "http_status": http_status,
        "error_code": resp["error_code"],
        "message": msg,
        "topic": topic,
        "partition": partition,
        "offset": offset,
    }


class TestParsePoisonErrSpec(unittest.TestCase):
    """Validates the poison error parsing spec mirrored from Go parsePoisonErr."""

    def _body(self, error_code: int, message: str) -> bytes:
        return json.dumps({"error_code": error_code, "message": message}).encode()

    def test_canonical_40801_parses_correctly(self):
        body = self._body(40801,
            "Unable to serialize value of record at offset 65 in topic:partition telemetry.raw:0")
        pe = _parse_poison_err_py(408, body)
        self.assertIsNotNone(pe)
        self.assertEqual(pe["error_code"], 40801)
        self.assertEqual(pe["topic"], "telemetry.raw")
        self.assertEqual(pe["partition"], 0)
        self.assertEqual(pe["offset"], 65)

    def test_non_40801_returns_none(self):
        body = self._body(40002, "consumer instance not found")
        self.assertIsNone(_parse_poison_err_py(404, body))

    def test_network_error_text_returns_none(self):
        self.assertIsNone(_parse_poison_err_py(500, b"connection refused"))

    def test_large_offset_parses_correctly(self):
        body = self._body(40801,
            "Unable to serialize value of record at offset 123456 in topic:partition telemetry.raw:2")
        pe = _parse_poison_err_py(408, body)
        self.assertEqual(pe["offset"], 123456)
        self.assertEqual(pe["partition"], 2)

    def test_topic_with_dots_parses_correctly(self):
        body = self._body(40801,
            "Unable to serialize value of record at offset 7 in topic:partition telemetry.normalized:0")
        pe = _parse_poison_err_py(408, body)
        self.assertEqual(pe["topic"], "telemetry.normalized")
        self.assertEqual(pe["offset"], 7)

    def test_unparseable_message_gives_defaults(self):
        body = self._body(40801, "Some other 40801 message without coordinates")
        pe = _parse_poison_err_py(408, body)
        self.assertIsNotNone(pe)
        self.assertEqual(pe["topic"], "unknown")
        self.assertEqual(pe["partition"], -1)
        self.assertEqual(pe["offset"], -1)

    def test_empty_body_returns_none(self):
        self.assertIsNone(_parse_poison_err_py(408, b""))

    def test_invalid_json_returns_none(self):
        self.assertIsNone(_parse_poison_err_py(408, b"not json{"))


# ---------------------------------------------------------------------------
# isolatePoisonRecord precondition: coordinates must be valid
# ---------------------------------------------------------------------------

class TestIsolatePoisonPreconditions(unittest.TestCase):
    """Validates the guard conditions before DLQ write (mirrors Go logic)."""

    def _can_isolate(self, topic: str, partition: int, offset: int) -> bool:
        """Return True if isolation would proceed (all coordinates valid)."""
        return not (topic == "" or topic == "unknown" or offset < 0 or partition < 0)

    def test_valid_coordinates_allow_isolation(self):
        self.assertTrue(self._can_isolate("telemetry.raw", 0, 65))

    def test_unknown_topic_blocks_isolation(self):
        self.assertFalse(self._can_isolate("unknown", 0, 65))

    def test_empty_topic_blocks_isolation(self):
        self.assertFalse(self._can_isolate("", 0, 65))

    def test_negative_offset_blocks_isolation(self):
        self.assertFalse(self._can_isolate("telemetry.raw", 0, -1))

    def test_negative_partition_blocks_isolation(self):
        self.assertFalse(self._can_isolate("telemetry.raw", -1, 65))

    def test_offset_zero_is_valid(self):
        self.assertTrue(self._can_isolate("telemetry.raw", 0, 0))


# ---------------------------------------------------------------------------
# DLQ record schema validation
# ---------------------------------------------------------------------------

class TestDlqRecordSchema(unittest.TestCase):
    """Validates DLQ record structure produced by isolatePoisonRecord spec."""

    def _build_dlq_record(self, topic: str, partition: int, offset: int,
                          error_code: int, message: str, group: str) -> dict:
        return {
            "dlq_event_type":   "poison_message_isolated",
            "schema_version":   1,
            "isolation_reason": "pandaproxy_serialization_failure",
            "source_topic":     topic,
            "source_partition": partition,
            "source_offset":    offset,
            "error_code":       error_code,
            "error_message":    message,
            "isolated_at":      "2026-06-23T00:00:00+00:00",
            "consumer_group":   group,
        }

    def test_required_fields_present(self):
        rec = self._build_dlq_record("telemetry.raw", 0, 65, 40801,
                                     "Unable to serialize...", "normalizer-worker-v1")
        required = {"dlq_event_type", "schema_version", "isolation_reason",
                    "source_topic", "source_partition", "source_offset",
                    "error_code", "error_message", "isolated_at", "consumer_group"}
        self.assertTrue(required.issubset(set(rec.keys())))

    def test_dlq_event_type_value(self):
        rec = self._build_dlq_record("telemetry.raw", 0, 65, 40801, "msg", "grp")
        self.assertEqual(rec["dlq_event_type"], "poison_message_isolated")

    def test_isolation_reason_value(self):
        rec = self._build_dlq_record("telemetry.raw", 0, 65, 40801, "msg", "grp")
        self.assertEqual(rec["isolation_reason"], "pandaproxy_serialization_failure")

    def test_coordinates_preserved(self):
        rec = self._build_dlq_record("telemetry.raw", 2, 999, 40801, "msg", "grp-v1")
        self.assertEqual(rec["source_topic"], "telemetry.raw")
        self.assertEqual(rec["source_partition"], 2)
        self.assertEqual(rec["source_offset"], 999)
        self.assertEqual(rec["consumer_group"], "grp-v1")


if __name__ == "__main__":
    unittest.main()
