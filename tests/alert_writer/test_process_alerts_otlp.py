"""OBS-OTEL-TRACING phase 5: process_alerts() actually emits OTLP spans."""
from __future__ import annotations

import sys
import time
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "alert-writer-service"
sys.path.insert(0, str(SERVICES_DIR))

for _mod in ("fastapi", "pydantic", "xdr_event_contracts"):
    if _mod not in sys.modules:
        _stub = types.ModuleType(_mod)
        _stub.FastAPI = MagicMock(return_value=MagicMock())  # type: ignore[attr-defined]
        _stub.BaseModel = object  # type: ignore[attr-defined]
        _stub.Field = lambda *a, **kw: None  # type: ignore[attr-defined]
        _stub.Depends = lambda f=None: f  # type: ignore[attr-defined]
        _stub.Header = MagicMock(return_value=None)  # type: ignore[attr-defined]
        _stub.HTTPException = type("HTTPException", (Exception,), {  # type: ignore[attr-defined]
            "__init__": lambda self, status_code=400, detail="": Exception.__init__(self, detail)
        })
        _stub.envelope = lambda **kw: dict(kw)  # type: ignore[attr-defined]
        _stub.is_envelope = lambda v: False  # type: ignore[attr-defined]
        _stub.unwrap_payload = lambda v, t: v  # type: ignore[attr-defined]
        _stub.validate_envelope = lambda ev, t: []  # type: ignore[attr-defined]
        sys.modules[_mod] = _stub

import main as aw  # noqa: E402


class FakeAlert:
    def __init__(self, alert_type="TEST_ALERT", traceparent=None, trace_id=None):
        self.alert_type = alert_type
        self.severity = "high"
        self.actor_key = "actor-1"
        self.ip = None
        self.alert_id = None
        self.trace_id = trace_id
        self.traceparent = traceparent
        self.evidence = {}

    def model_dump(self):
        return {"alert_type": self.alert_type, "severity": self.severity}


class TestProcessAlertsEmitsOtlpSpans(unittest.TestCase):
    def _wait_for(self, cond, timeout=2.0):
        deadline = time.time() + timeout
        while time.time() < deadline:
            if cond():
                return True
            time.sleep(0.01)
        return cond()

    def test_emits_one_span_per_alert_when_endpoint_configured(self):
        alerts = [FakeAlert("ALERT_A"), FakeAlert("ALERT_B")]

        mock_resp = MagicMock()
        mock_resp.status_code = 200

        with patch.object(aw, "_write_alerts_core", return_value={"ok": True}), \
             patch.object(aw, "WriteRequest", lambda **kw: types.SimpleNamespace(**kw)), \
             patch.object(aw, "store_operational_event", return_value=None), \
             patch.object(aw, "produce", return_value=0), \
             patch.object(aw, "OTEL_EXPORTER_ENDPOINT", "http://collector/v1/traces"), \
             patch.object(aw.SESSION, "post", return_value=mock_resp) as mock_post:
            aw.process_alerts(alerts, trace_id="t1", source_topic="xdr.alerts")

            self.assertTrue(self._wait_for(lambda: mock_post.called))

        import json as _json
        body = mock_post.call_args.kwargs["data"]
        decoded = _json.loads(body)
        spans = decoded["resourceSpans"][0]["scopeSpans"][0]["spans"]
        self.assertEqual(len(spans), 2, f"expected 1 span per alert (2 alerts), got {len(spans)}")

    def test_does_not_export_when_endpoint_unset(self):
        alerts = [FakeAlert("ALERT_A")]

        with patch.object(aw, "_write_alerts_core", return_value={"ok": True}), \
             patch.object(aw, "WriteRequest", lambda **kw: types.SimpleNamespace(**kw)), \
             patch.object(aw, "store_operational_event", return_value=None), \
             patch.object(aw, "produce", return_value=0), \
             patch.object(aw, "OTEL_EXPORTER_ENDPOINT", ""), \
             patch.object(aw.SESSION, "post") as mock_post:
            aw.process_alerts(alerts, trace_id="t1", source_topic="xdr.alerts")
            time.sleep(0.05)
            mock_post.assert_not_called()

    def test_span_trace_id_matches_inbound_traceparent(self):
        inbound = "00-0123456789abcdef0123456789abcdef-fedcba9876543210-01"
        alerts = [FakeAlert("ALERT_A", traceparent=inbound)]

        mock_resp = MagicMock()
        mock_resp.status_code = 200

        with patch.object(aw, "_write_alerts_core", return_value={"ok": True}), \
             patch.object(aw, "WriteRequest", lambda **kw: types.SimpleNamespace(**kw)), \
             patch.object(aw, "store_operational_event", return_value=None), \
             patch.object(aw, "produce", return_value=0), \
             patch.object(aw, "OTEL_EXPORTER_ENDPOINT", "http://collector/v1/traces"), \
             patch.object(aw.SESSION, "post", return_value=mock_resp) as mock_post:
            aw.process_alerts(alerts, trace_id="t1", source_topic="xdr.alerts")
            self.assertTrue(self._wait_for(lambda: mock_post.called))

        import json as _json
        body = mock_post.call_args.kwargs["data"]
        span = _json.loads(body)["resourceSpans"][0]["scopeSpans"][0]["spans"][0]
        self.assertEqual(span["traceId"], "0123456789abcdef0123456789abcdef")
        self.assertEqual(span["parentSpanId"], "fedcba9876543210")


if __name__ == "__main__":
    unittest.main()
