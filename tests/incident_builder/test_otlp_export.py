"""OBS-OTEL-TRACING phase 5: otlp_export.py's OTLP/HTTP+JSON wire format and
fire-and-forget export behavior."""
from __future__ import annotations

import json
import sys
import time
import unittest
from pathlib import Path
from unittest.mock import MagicMock

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "incident-builder-service"
sys.path.insert(0, str(SERVICES_DIR))

import otlp_export as oe  # noqa: E402


def sample_span() -> oe.Span:
    return oe.Span(
        trace_id="0123456789abcdef0123456789abcdef",
        span_id="0123456789abcdef",
        parent_span_id="fedcba9876543210",
        name="alert-writer-service.process_alerts",
        kind=oe.SPAN_KIND_INTERNAL,
        start_unix_nano=1700000000000000000,
        end_unix_nano=1700000000050000000,
        attributes={"tenant_id": "tenant-a"},
    )


class TestBuildRequestBody(unittest.TestCase):
    def test_produces_valid_json(self):
        body = oe.build_request_body("alert-writer-service", [sample_span()])
        decoded = json.loads(body)
        self.assertIn("resourceSpans", decoded)

    def test_shape_matches_otlp_http_json(self):
        body = oe.build_request_body("alert-writer-service", [sample_span()])
        decoded = json.loads(body)

        rs = decoded["resourceSpans"][0]
        attrs = rs["resource"]["attributes"]
        self.assertEqual(len(attrs), 1)
        self.assertEqual(attrs[0]["key"], "service.name")
        self.assertEqual(attrs[0]["value"]["stringValue"], "alert-writer-service")

        scope_spans = rs["scopeSpans"][0]
        self.assertEqual(scope_spans["scope"]["name"], "detector-xdr")

        spans = scope_spans["spans"]
        self.assertEqual(len(spans), 1)
        got = spans[0]
        want = sample_span()
        self.assertEqual(got["traceId"], want.trace_id)
        self.assertEqual(got["spanId"], want.span_id)
        self.assertEqual(got["parentSpanId"], want.parent_span_id)
        self.assertEqual(got["name"], want.name)
        self.assertEqual(got["kind"], oe.SPAN_KIND_INTERNAL)
        self.assertEqual(got["startTimeUnixNano"], "1700000000000000000")
        self.assertEqual(got["endTimeUnixNano"], "1700000000050000000")

    def test_omits_parent_span_id_for_root_span(self):
        root = sample_span()
        root.parent_span_id = ""
        body = oe.build_request_body("alert-writer-service", [root])
        span = json.loads(body)["resourceSpans"][0]["scopeSpans"][0]["spans"][0]
        self.assertNotIn("parentSpanId", span)

    def test_batches_multiple_spans_into_one_request(self):
        spans = [sample_span(), sample_span(), sample_span()]
        body = oe.build_request_body("alert-writer-service", spans)
        got_spans = json.loads(body)["resourceSpans"][0]["scopeSpans"][0]["spans"]
        self.assertEqual(len(got_spans), 3)


class TestExport(unittest.TestCase):
    def test_noop_when_endpoint_empty(self):
        session = MagicMock()
        oe.export(session, "", "svc", [sample_span()])
        session.post.assert_not_called()

    def test_noop_for_empty_spans(self):
        session = MagicMock()
        oe.export(session, "http://collector/v1/traces", "svc", [])
        session.post.assert_not_called()

    def test_sends_real_post_via_session(self):
        session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status_code = 200
        session.post.return_value = mock_resp

        oe.export(session, "http://collector/v1/traces", "alert-writer-service", [sample_span()])

        session.post.assert_called_once()
        args, kwargs = session.post.call_args
        self.assertEqual(args[0], "http://collector/v1/traces")
        self.assertEqual(kwargs["headers"]["Content-Type"], "application/json")

    def test_raises_on_non_2xx_response(self):
        session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status_code = 503
        session.post.return_value = mock_resp

        with self.assertRaises(RuntimeError):
            oe.export(session, "http://collector/v1/traces", "svc", [sample_span()])


class TestExportAsync(unittest.TestCase):
    def _wait_for(self, cond, timeout=2.0):
        deadline = time.time() + timeout
        while time.time() < deadline:
            if cond():
                return True
            time.sleep(0.01)
        return cond()

    def test_sends_post_in_background_thread(self):
        session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status_code = 200
        session.post.return_value = mock_resp

        oe.export_async(session, "http://collector/v1/traces", "svc", [sample_span()])

        self.assertTrue(self._wait_for(lambda: session.post.called))

    def test_does_not_raise_when_export_fails(self):
        session = MagicMock()
        session.post.side_effect = RuntimeError("connection refused")
        logger = MagicMock()

        # Must not raise even though the underlying export() will fail.
        oe.export_async(session, "http://collector/v1/traces", "svc", [sample_span()], logger=logger)

        self.assertTrue(self._wait_for(lambda: logger.warning.called))

    def test_noop_when_endpoint_empty(self):
        session = MagicMock()
        oe.export_async(session, "", "svc", [sample_span()])
        time.sleep(0.05)
        session.post.assert_not_called()


if __name__ == "__main__":
    unittest.main()
