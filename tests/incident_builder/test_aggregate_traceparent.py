"""Tests that aggregate() correctly propagates traceparent as a new child span
for the incident-builder hop, using SimpleNamespace fake alerts (duck-typed,
bypassing the heavy fastapi/pydantic stub used by test_incident_builder.py --
the same technique test_alert_identity.py already uses for fingerprint()).
"""
from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import MagicMock

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
        _stub.envelope = lambda **kw: {}  # type: ignore[attr-defined]
        _stub.is_envelope = lambda v: False  # type: ignore[attr-defined]
        _stub.unwrap_payload = lambda v, t: v  # type: ignore[attr-defined]
        _stub.validate_envelope = lambda ev, t: []  # type: ignore[attr-defined]
        sys.modules[_mod] = _stub

import main as ib  # noqa: E402
import traceparent as tp  # noqa: E402


def make_alert(**overrides):
    defaults = dict(
        alert_id="alert-1",
        alert_type="IDENTITY_MFA_FAILURE_BURST",
        severity="high",
        detected_at="2026-07-10T00:00:00Z",
        actor_key="alice",
        ip="10.0.0.1",
        score=0.8,
        evidence={},
        trace_id=None,
        traceparent=None,
        tenant_id=None,
    )
    defaults.update(overrides)
    return SimpleNamespace(**defaults)


class AggregateTraceparentTest(unittest.TestCase):
    def test_propagates_traceparent_as_child_span(self):
        inbound = tp.generate()
        inbound_parsed = tp.parse(inbound)
        group = [make_alert(traceparent=inbound), make_alert(alert_id="alert-2", traceparent=None)]

        incident = ib.aggregate(group, "test|alice")

        out_parsed = tp.parse(incident["traceparent"])
        self.assertIsNotNone(out_parsed)
        self.assertEqual(out_parsed.trace_id, inbound_parsed.trace_id)
        self.assertNotEqual(out_parsed.span_id, inbound_parsed.span_id)

    def test_generates_root_traceparent_when_no_alert_carries_one(self):
        group = [make_alert(traceparent=None)]
        incident = ib.aggregate(group, "test|alice")
        self.assertIsNotNone(tp.parse(incident["traceparent"]))


class AggregateOtelSpanTest(unittest.TestCase):
    """OBS-OTEL-TRACING phase 5: aggregate()'s optional otel_spans_out param."""

    def test_none_by_default_leaves_incident_dict_unaffected(self):
        group = [make_alert()]
        incident = ib.aggregate(group, "test|alice")
        self.assertNotIn("_otel_span", incident)
        self.assertEqual(set(incident.keys()) & {"otel_spans_out"}, set())

    def test_appends_one_span_when_list_provided(self):
        inbound = tp.generate()
        group = [make_alert(traceparent=inbound)]
        spans: list = []
        ib.aggregate(group, "test|alice", spans)
        self.assertEqual(len(spans), 1)

    def test_span_never_leaks_into_the_returned_incident_dict(self):
        spans: list = []
        incident = ib.aggregate([make_alert()], "test|alice", spans)
        self.assertEqual(len(spans), 1)
        # The incident dict must remain JSON-serializable — no Span object anywhere in it.
        import json
        json.dumps(incident)  # raises TypeError if a non-serializable value leaked in

    def test_span_trace_id_and_parent_match_traceparent_chain(self):
        inbound = tp.generate()
        inbound_parsed = tp.parse(inbound)
        spans: list = []
        incident = ib.aggregate([make_alert(traceparent=inbound)], "test|alice", spans)
        out_parsed = tp.parse(incident["traceparent"])

        self.assertEqual(spans[0].trace_id, out_parsed.trace_id)
        self.assertEqual(spans[0].span_id, out_parsed.span_id)
        self.assertEqual(spans[0].parent_span_id, inbound_parsed.span_id)

    def test_span_root_has_empty_parent_when_no_alert_carries_traceparent(self):
        spans: list = []
        ib.aggregate([make_alert(traceparent=None)], "test|alice", spans)
        self.assertEqual(spans[0].parent_span_id, "")


if __name__ == "__main__":
    unittest.main()
