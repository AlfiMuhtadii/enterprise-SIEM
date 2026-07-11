"""OBS-OTEL-TRACING phase 5: process_alerts() actually emits OTLP spans."""
from __future__ import annotations

import sys
import time
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "incident-builder-service"
sys.path.insert(0, str(SERVICES_DIR))

sys.modules.pop("main", None)

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

import main as ib  # noqa: E402


class FakeAlert:
    def __init__(self, alert_id="alert-1", traceparent=None, trace_id=None):
        self.alert_id = alert_id
        self.alert_type = "IDENTITY_MFA_FAILURE_BURST"
        self.severity = "high"
        self.detected_at = "2026-07-10T00:00:00Z"
        self.actor_key = "alice"
        self.ip = "10.0.0.1"
        self.score = 0.8
        self.evidence = {}
        self.trace_id = trace_id
        self.traceparent = traceparent
        self.tenant_id = None


class TestProcessAlertsEmitsOtlpSpans(unittest.TestCase):
    def _wait_for(self, cond, timeout=2.0):
        deadline = time.time() + timeout
        while time.time() < deadline:
            if cond():
                return True
            time.sleep(0.01)
        return cond()

    def test_emits_one_span_per_incident_group_when_endpoint_configured(self):
        # Two alerts with different actor_key -> two distinct group_key()s -> 2 incidents.
        alerts = [FakeAlert("alert-1"), FakeAlert("alert-2", trace_id=None)]
        alerts[1].actor_key = "bob"
        alerts[1].ip = "10.0.0.2"

        mock_resp = MagicMock()
        mock_resp.status_code = 200

        with patch.object(ib, "write_incidents", return_value=0), \
             patch.object(ib, "BuildRequest", lambda **kw: types.SimpleNamespace(**kw)), \
             patch.object(ib, "store_operational_event", return_value=None), \
             patch.object(ib, "produce", return_value=0), \
             patch.object(ib, "OTEL_EXPORTER_ENDPOINT", "http://collector/v1/traces"), \
             patch.object(ib.SESSION, "post", return_value=mock_resp) as mock_post:
            ib.process_alerts(alerts, trace_id="t1", source_topic="alerts.created")

            self.assertTrue(self._wait_for(lambda: mock_post.called))

        import json as _json
        body = mock_post.call_args.kwargs["data"]
        spans = _json.loads(body)["resourceSpans"][0]["scopeSpans"][0]["spans"]
        self.assertEqual(len(spans), 2, f"expected 1 span per incident group (2 groups), got {len(spans)}")

    def test_does_not_export_when_endpoint_unset(self):
        alerts = [FakeAlert("alert-1")]

        with patch.object(ib, "write_incidents", return_value=0), \
             patch.object(ib, "BuildRequest", lambda **kw: types.SimpleNamespace(**kw)), \
             patch.object(ib, "store_operational_event", return_value=None), \
             patch.object(ib, "produce", return_value=0), \
             patch.object(ib, "OTEL_EXPORTER_ENDPOINT", ""), \
             patch.object(ib.SESSION, "post") as mock_post:
            ib.process_alerts(alerts, trace_id="t1", source_topic="alerts.created")
            time.sleep(0.05)
            mock_post.assert_not_called()

    def test_span_trace_id_matches_inbound_traceparent(self):
        inbound = "00-0123456789abcdef0123456789abcdef-fedcba9876543210-01"
        alerts = [FakeAlert("alert-1", traceparent=inbound)]

        mock_resp = MagicMock()
        mock_resp.status_code = 200

        with patch.object(ib, "write_incidents", return_value=0), \
             patch.object(ib, "BuildRequest", lambda **kw: types.SimpleNamespace(**kw)), \
             patch.object(ib, "store_operational_event", return_value=None), \
             patch.object(ib, "produce", return_value=0), \
             patch.object(ib, "OTEL_EXPORTER_ENDPOINT", "http://collector/v1/traces"), \
             patch.object(ib.SESSION, "post", return_value=mock_resp) as mock_post:
            ib.process_alerts(alerts, trace_id="t1", source_topic="alerts.created")
            self.assertTrue(self._wait_for(lambda: mock_post.called))

        import json as _json
        body = mock_post.call_args.kwargs["data"]
        span = _json.loads(body)["resourceSpans"][0]["scopeSpans"][0]["spans"][0]
        self.assertEqual(span["traceId"], "0123456789abcdef0123456789abcdef")
        self.assertEqual(span["parentSpanId"], "fedcba9876543210")

    def test_build_route_still_returns_no_span_leakage(self):
        # /v1/build calls _build_incidents_core WITHOUT otel_spans_out -- its
        # JSON response shape must stay completely unaffected by phase 5.
        request = types.SimpleNamespace(alerts=[FakeAlert("alert-1")], trace_id="t1", source_topic="alerts.created")
        with patch.object(ib, "write_incidents", return_value=0):
            result = ib._build_incidents_core(request)
        import json as _json
        _json.dumps(result)  # raises TypeError if a Span object leaked into the response


if __name__ == "__main__":
    unittest.main()
