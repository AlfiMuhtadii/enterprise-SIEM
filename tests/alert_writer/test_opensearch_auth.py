"""Tests for write_opensearch() auth/TLS wiring — ENT-SEC-OPENSEARCH-OPEN."""
from __future__ import annotations

import os
import sys
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
        _stub.envelope = lambda **kw: {}  # type: ignore[attr-defined]
        _stub.is_envelope = lambda v: False  # type: ignore[attr-defined]
        _stub.unwrap_payload = lambda v, t: v  # type: ignore[attr-defined]
        _stub.validate_envelope = lambda ev, t: []  # type: ignore[attr-defined]
        sys.modules[_mod] = _stub

import main as aw  # noqa: E402 — must come after sys.modules stubs


class _FakeAlert:
    """Stand-in for AlertPayload — the stubbed pydantic BaseModel has no
    real .model_dump(), so tests use a plain object with the same attribute
    surface write_opensearch()/fingerprint()/alert_id() actually touch."""

    def __init__(self, **kw):
        self.alert_id = kw.get("alert_id")
        self.alert_type = kw.get("alert_type", "TEST_TYPE")
        self.severity = kw.get("severity", "high")
        self.actor_key = kw.get("actor_key")
        self.ip = kw.get("ip", "10.0.0.1")
        self.evidence = kw.get("evidence", {})
        self._dump = dict(kw)

    def model_dump(self):
        return dict(self._dump)


class TestWriteOpensearchAuth(unittest.TestCase):
    def setUp(self):
        self._env_backup = {
            k: os.environ.get(k)
            for k in ("XDR_OPENSEARCH_URL", "XDR_OPENSEARCH_USER", "XDR_OPENSEARCH_PASSWORD", "XDR_OPENSEARCH_VERIFY_TLS")
        }

    def tearDown(self):
        for k, v in self._env_backup.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v

    def test_no_auth_sent_when_user_unset(self):
        os.environ["XDR_OPENSEARCH_URL"] = "http://opensearch:9200"
        os.environ.pop("XDR_OPENSEARCH_USER", None)
        os.environ.pop("XDR_OPENSEARCH_PASSWORD", None)

        calls = []

        def fake_put(url, json=None, timeout=None, auth=None, verify=None):
            calls.append({"auth": auth, "verify": verify})
            resp = MagicMock()
            resp.status_code = 201
            return resp

        with patch.object(aw.SESSION, "put", side_effect=fake_put):
            aw.write_opensearch([_FakeAlert()])

        self.assertEqual(1, len(calls))
        self.assertIsNone(calls[0]["auth"])
        self.assertTrue(calls[0]["verify"])

    def test_basic_auth_sent_when_user_configured(self):
        os.environ["XDR_OPENSEARCH_URL"] = "https://opensearch:9200"
        os.environ["XDR_OPENSEARCH_USER"] = "admin"
        os.environ["XDR_OPENSEARCH_PASSWORD"] = "strong-pw"

        calls = []

        def fake_put(url, json=None, timeout=None, auth=None, verify=None):
            calls.append({"auth": auth, "verify": verify})
            resp = MagicMock()
            resp.status_code = 201
            return resp

        with patch.object(aw.SESSION, "put", side_effect=fake_put):
            aw.write_opensearch([_FakeAlert()])

        self.assertEqual(("admin", "strong-pw"), calls[0]["auth"])

    def test_verify_false_when_configured(self):
        os.environ["XDR_OPENSEARCH_URL"] = "https://opensearch:9200"
        os.environ["XDR_OPENSEARCH_USER"] = "admin"
        os.environ["XDR_OPENSEARCH_PASSWORD"] = "strong-pw"
        os.environ["XDR_OPENSEARCH_VERIFY_TLS"] = "false"

        calls = []

        def fake_put(url, json=None, timeout=None, auth=None, verify=None):
            calls.append({"verify": verify})
            resp = MagicMock()
            resp.status_code = 201
            return resp

        with patch.object(aw.SESSION, "put", side_effect=fake_put):
            aw.write_opensearch([_FakeAlert()])

        self.assertFalse(calls[0]["verify"])

    def test_verify_defaults_true_when_unset(self):
        os.environ["XDR_OPENSEARCH_URL"] = "https://opensearch:9200"
        os.environ.pop("XDR_OPENSEARCH_VERIFY_TLS", None)

        calls = []

        def fake_put(url, json=None, timeout=None, auth=None, verify=None):
            calls.append({"verify": verify})
            resp = MagicMock()
            resp.status_code = 201
            return resp

        with patch.object(aw.SESSION, "put", side_effect=fake_put):
            aw.write_opensearch([_FakeAlert()])

        self.assertTrue(calls[0]["verify"])

    def test_returns_zero_when_url_unset(self):
        os.environ.pop("XDR_OPENSEARCH_URL", None)
        self.assertEqual(0, aw.write_opensearch([_FakeAlert()]))


if __name__ == "__main__":
    unittest.main()
