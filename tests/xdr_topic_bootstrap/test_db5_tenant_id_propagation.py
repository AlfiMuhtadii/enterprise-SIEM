"""Tests for DB-5: tenant_id propagation in alert-writer and incident-builder write paths.

Covers:
- AlertPayload.tenant_id field present in both services
- write_postgres() includes tenant_id in INSERT row tuple
- aggregate() propagates tenant_id from group's first non-None alert
- write_incidents() includes tenant_id in INSERT column list
- tenant_id=None correctly propagated (not defaulted)
"""
from __future__ import annotations

import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, call, patch

_ALERT_WRITER = Path(__file__).parent.parent.parent / "services" / "alert-writer-service"
_INCIDENT_BUILDER = Path(__file__).parent.parent.parent / "services" / "incident-builder-service"


def _stub_deps(module_name: str):
    """Inject stub modules so the service can be imported without runtime deps."""

    class _FakeHTTPException(Exception):
        def __init__(self, status_code: int = 200, detail: object = None) -> None:
            super().__init__(detail)
            self.status_code = status_code
            self.detail = detail

    for _mod in ("fastapi", "pydantic", "xdr_event_contracts", "requests"):
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
        _stub.post = MagicMock()  # type: ignore[attr-defined]


# ---------------------------------------------------------------------------
# Alert-writer tests
# ---------------------------------------------------------------------------

sys.path.insert(0, str(_ALERT_WRITER))
_stub_deps("alert-writer-service")
sys.modules.pop("main", None)
import main as aw  # noqa: E402


class TestAlertWriterTenantId(unittest.TestCase):
    def _make_alert(self, tenant_id=None):
        a = aw.AlertPayload.__new__(aw.AlertPayload)
        a.alert_id = "xdr-test-001"
        a.alert_type = "BRUTE_FORCE"
        a.severity = "high"
        a.detected_at = "2026-06-24T00:00:00+00:00"
        a.ip = "10.0.0.1"
        a.actor_key = "user@example.com"
        a.score = 0.9
        a.evidence = {}
        a.raw_event = {}
        a.detector_name = "xdr-correlation"
        a.detector_version = "go-shadow"
        a.trace_id = "trace-abc"
        a.tenant_id = tenant_id
        return a

    def test_alert_payload_has_tenant_id_field(self):
        a = self._make_alert(tenant_id="tenant-xyz")
        self.assertEqual(a.tenant_id, "tenant-xyz")

    def test_alert_payload_tenant_id_defaults_none(self):
        a = self._make_alert()
        self.assertIsNone(a.tenant_id)

    def test_write_postgres_row_includes_tenant_id(self):
        alert = self._make_alert(tenant_id="tenant-001")
        rows_captured = []

        class FakeCursor:
            def execute(self, sql, row):
                rows_captured.append((sql, row))
            def __enter__(self): return self
            def __exit__(self, *a): pass

        class FakeConn:
            def cursor(self): return FakeCursor()
            def __enter__(self): return self
            def __exit__(self, *a): pass

        with patch.object(aw, "connect_pg", return_value=FakeConn()):
            aw.write_postgres([alert])

        self.assertEqual(len(rows_captured), 1)
        sql, row = rows_captured[0]
        self.assertIn("tenant_id", sql)
        # tenant_id is 14th positional param (index 13)
        self.assertEqual(row[13], "tenant-001")

    def test_write_postgres_row_none_tenant_id(self):
        alert = self._make_alert(tenant_id=None)
        rows_captured = []

        class FakeCursor:
            def execute(self, sql, row):
                rows_captured.append((sql, row))
            def __enter__(self): return self
            def __exit__(self, *a): pass

        class FakeConn:
            def cursor(self): return FakeCursor()
            def __enter__(self): return self
            def __exit__(self, *a): pass

        with patch.object(aw, "connect_pg", return_value=FakeConn()):
            aw.write_postgres([alert])

        _, row = rows_captured[0]
        self.assertIsNone(row[13])

    def test_write_postgres_on_conflict_includes_tenant_id(self):
        alert = self._make_alert(tenant_id="t1")
        sqls = []

        class FakeCursor:
            def execute(self, sql, row):
                sqls.append(sql)
            def __enter__(self): return self
            def __exit__(self, *a): pass

        class FakeConn:
            def cursor(self): return FakeCursor()
            def __enter__(self): return self
            def __exit__(self, *a): pass

        with patch.object(aw, "connect_pg", return_value=FakeConn()):
            aw.write_postgres([alert])

        self.assertTrue(any("tenant_id=COALESCE" in s for s in sqls))


# ---------------------------------------------------------------------------
# Incident-builder tests
# ---------------------------------------------------------------------------

sys.path.insert(0, str(_INCIDENT_BUILDER))
sys.modules.pop("main", None)
import main as ib  # noqa: E402


class TestIncidentBuilderTenantId(unittest.TestCase):
    def _make_alert(self, alert_id, tenant_id=None):
        a = ib.AlertPayload.__new__(ib.AlertPayload)
        a.alert_id = alert_id
        a.alert_type = "BRUTE_FORCE"
        a.severity = "high"
        a.detected_at = "2026-06-24T00:00:00+00:00"
        a.actor_key = "user@example.com"
        a.ip = "10.0.0.1"
        a.score = 0.9
        a.evidence = {}
        a.trace_id = "trace-abc"
        a.tenant_id = tenant_id
        return a

    def test_alert_payload_has_tenant_id_field(self):
        a = self._make_alert("a1", tenant_id="t1")
        self.assertEqual(a.tenant_id, "t1")

    def test_alert_payload_tenant_id_defaults_none(self):
        a = self._make_alert("a1")
        self.assertIsNone(a.tenant_id)

    def test_aggregate_propagates_tenant_id(self):
        group = [self._make_alert("a1", tenant_id="tenant-abc"),
                 self._make_alert("a2", tenant_id="tenant-abc")]
        key = "BRUTE_FORCE|user@example.com"
        inc = ib.aggregate(group, key)
        self.assertEqual(inc["tenant_id"], "tenant-abc")

    def test_aggregate_uses_first_non_none_tenant_id(self):
        group = [self._make_alert("a1", tenant_id=None),
                 self._make_alert("a2", tenant_id="tenant-xyz")]
        key = "BRUTE_FORCE|user@example.com"
        inc = ib.aggregate(group, key)
        self.assertEqual(inc["tenant_id"], "tenant-xyz")

    def test_aggregate_tenant_id_none_when_all_none(self):
        group = [self._make_alert("a1", tenant_id=None),
                 self._make_alert("a2", tenant_id=None)]
        key = "BRUTE_FORCE|user@example.com"
        inc = ib.aggregate(group, key)
        self.assertIsNone(inc["tenant_id"])

    def test_write_incidents_includes_tenant_id_column(self):
        group = [self._make_alert("a1", tenant_id="tenant-007")]
        key = "BRUTE_FORCE|user@example.com"
        inc = ib.aggregate(group, key)
        sqls = []
        rows_captured = []

        class FakeCursor:
            def execute(self, sql, row=None):
                sqls.append(sql)
                if row is not None:
                    rows_captured.append(row)
            def __enter__(self): return self
            def __exit__(self, *a): pass

        class FakeConn:
            def cursor(self): return FakeCursor()
            def __enter__(self): return self
            def __exit__(self, *a): pass

        with patch.object(ib, "connect_pg", return_value=FakeConn()):
            ib.write_incidents([inc])

        insert_sql = sqls[0]
        self.assertIn("tenant_id", insert_sql)
        # tenant_id is the 14th positional param (index 13)
        self.assertEqual(rows_captured[0][13], "tenant-007")

    def test_write_incidents_on_conflict_includes_tenant_id(self):
        group = [self._make_alert("a1", tenant_id="t1")]
        key = "BRUTE_FORCE|user@example.com"
        inc = ib.aggregate(group, key)
        sqls = []

        class FakeCursor:
            def execute(self, sql, row=None):
                sqls.append(sql)
            def __enter__(self): return self
            def __exit__(self, *a): pass

        class FakeConn:
            def cursor(self): return FakeCursor()
            def __enter__(self): return self
            def __exit__(self, *a): pass

        with patch.object(ib, "connect_pg", return_value=FakeConn()):
            ib.write_incidents([inc])

        self.assertTrue(any("tenant_id=COALESCE" in s for s in sqls))

    def test_write_incidents_none_tenant_id(self):
        group = [self._make_alert("a1", tenant_id=None)]
        key = "BRUTE_FORCE|user@example.com"
        inc = ib.aggregate(group, key)
        rows_captured = []

        class FakeCursor:
            def execute(self, sql, row=None):
                if row is not None:
                    rows_captured.append(row)
            def __enter__(self): return self
            def __exit__(self, *a): pass

        class FakeConn:
            def cursor(self): return FakeCursor()
            def __enter__(self): return self
            def __exit__(self, *a): pass

        with patch.object(ib, "connect_pg", return_value=FakeConn()):
            ib.write_incidents([inc])

        self.assertIsNone(rows_captured[0][13])


if __name__ == "__main__":
    unittest.main()
