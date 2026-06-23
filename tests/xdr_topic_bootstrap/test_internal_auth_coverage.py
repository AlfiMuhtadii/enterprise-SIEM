"""Tests for BACKLOG-SECURITY-015: Internal Auth Coverage Expansion.

Covers:
- alert-writer-service: verify_internal_token in permissive/enforced modes
- alert-writer-service: validate_startup_secrets fail-fast when enforced + no token
- alert-writer-service: internal_auth_mode in METRICS
- incident-builder-service: same three areas
- validate_live_xdr_pipeline: check_internal_auth_posture_service for all three new checks
"""
from __future__ import annotations

import json
import os
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


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

# Import alert-writer service
_AW_PATH = str(Path(__file__).parent.parent.parent / "services" / "alert-writer-service")
if _AW_PATH not in sys.path:
    sys.path.insert(0, _AW_PATH)
sys.modules.pop("main", None)
import main as aw  # noqa: E402

# Import incident-builder service (swap path to avoid collision)
_IB_PATH = str(Path(__file__).parent.parent.parent / "services" / "incident-builder-service")
try:
    sys.path.remove(_AW_PATH)
except ValueError:
    pass
if _IB_PATH not in sys.path:
    sys.path.insert(0, _IB_PATH)
sys.modules.pop("main", None)
import main as ib  # noqa: E402

# Restore AW path and scripts path
if _AW_PATH not in sys.path:
    sys.path.insert(0, _AW_PATH)

_SCRIPTS = str(Path(__file__).parent.parent.parent / "scripts")
if _SCRIPTS not in sys.path:
    sys.path.insert(0, _SCRIPTS)
sys.modules.pop("validate_live_xdr_pipeline", None)
import validate_live_xdr_pipeline as vlp  # noqa: E402


# ===========================================================================
# Alert-writer verify_internal_token
# ===========================================================================

class TestAlertWriterVerifyToken(unittest.TestCase):

    def test_permissive_no_token_configured_accepts_any(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_ALERT_WRITER_INTERNAL_TOKEN": ""}):
            self.assertTrue(aw.verify_internal_token("anything"))

    def test_permissive_no_token_configured_accepts_empty(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_ALERT_WRITER_INTERNAL_TOKEN": ""}):
            self.assertTrue(aw.verify_internal_token(""))

    def test_permissive_with_token_accepts_correct(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_ALERT_WRITER_INTERNAL_TOKEN": "secret123"}):
            self.assertTrue(aw.verify_internal_token("secret123"))

    def test_permissive_with_token_rejects_wrong(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_ALERT_WRITER_INTERNAL_TOKEN": "secret123"}):
            self.assertFalse(aw.verify_internal_token("wrong"))

    def test_enforced_correct_token_accepted(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_ALERT_WRITER_INTERNAL_TOKEN": "mytoken"}):
            self.assertTrue(aw.verify_internal_token("mytoken"))

    def test_enforced_wrong_token_rejected(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_ALERT_WRITER_INTERNAL_TOKEN": "mytoken"}):
            self.assertFalse(aw.verify_internal_token("badtoken"))

    def test_enforced_no_token_configured_rejects_all(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_ALERT_WRITER_INTERNAL_TOKEN": ""}):
            self.assertFalse(aw.verify_internal_token("anything"))

    def test_enforce_flag_yes_accepted(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "yes", "XDR_ALERT_WRITER_INTERNAL_TOKEN": "tok"}):
            self.assertTrue(aw.verify_internal_token("tok"))


# ===========================================================================
# Alert-writer validate_startup_secrets
# ===========================================================================

class TestAlertWriterValidateStartup(unittest.TestCase):

    def test_permissive_no_token_warns_does_not_exit(self):
        env = {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_ALERT_WRITER_INTERNAL_TOKEN": ""}
        with patch.dict(os.environ, env):
            with patch("sys.exit") as mock_exit:
                aw.validate_startup_secrets()
                mock_exit.assert_not_called()

    def test_enforced_with_token_does_not_exit(self):
        env = {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_ALERT_WRITER_INTERNAL_TOKEN": "realtoken"}
        with patch.dict(os.environ, env):
            with patch("sys.exit") as mock_exit:
                aw.validate_startup_secrets()
                mock_exit.assert_not_called()

    def test_enforced_no_token_exits_with_1(self):
        env = {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_ALERT_WRITER_INTERNAL_TOKEN": ""}
        with patch.dict(os.environ, env):
            with self.assertRaises(SystemExit) as cm:
                aw.validate_startup_secrets()
            self.assertEqual(cm.exception.code, 1)


# ===========================================================================
# Alert-writer internal_auth_mode metric
# ===========================================================================

class TestAlertWriterInternalAuthMode(unittest.TestCase):

    def test_permissive_mode_returns_permissive(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false"}):
            self.assertEqual(aw._auth_mode(), "permissive")

    def test_enforced_mode_returns_enforced(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true"}):
            self.assertEqual(aw._auth_mode(), "enforced")


# ===========================================================================
# Incident-builder verify_internal_token
# ===========================================================================

class TestIncidentBuilderVerifyToken(unittest.TestCase):

    def test_permissive_no_token_configured_accepts_any(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": ""}):
            self.assertTrue(ib.verify_internal_token("anything"))

    def test_permissive_no_token_configured_accepts_empty(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": ""}):
            self.assertTrue(ib.verify_internal_token(""))

    def test_permissive_with_token_accepts_correct(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "secret456"}):
            self.assertTrue(ib.verify_internal_token("secret456"))

    def test_permissive_with_token_rejects_wrong(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "secret456"}):
            self.assertFalse(ib.verify_internal_token("wrong"))

    def test_enforced_correct_token_accepted(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "ibtoken"}):
            self.assertTrue(ib.verify_internal_token("ibtoken"))

    def test_enforced_wrong_token_rejected(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "ibtoken"}):
            self.assertFalse(ib.verify_internal_token("bad"))

    def test_enforced_no_token_configured_rejects_all(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": ""}):
            self.assertFalse(ib.verify_internal_token("anything"))

    def test_enforce_flag_1_accepted(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "1", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "tok"}):
            self.assertTrue(ib.verify_internal_token("tok"))


# ===========================================================================
# Incident-builder validate_startup_secrets
# ===========================================================================

class TestIncidentBuilderValidateStartup(unittest.TestCase):

    def test_permissive_no_token_warns_does_not_exit(self):
        env = {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": ""}
        with patch.dict(os.environ, env):
            with patch("sys.exit") as mock_exit:
                ib.validate_startup_secrets()
                mock_exit.assert_not_called()

    def test_enforced_with_token_does_not_exit(self):
        env = {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "ibtoken"}
        with patch.dict(os.environ, env):
            with patch("sys.exit") as mock_exit:
                ib.validate_startup_secrets()
                mock_exit.assert_not_called()

    def test_enforced_no_token_exits_with_1(self):
        env = {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": ""}
        with patch.dict(os.environ, env):
            with self.assertRaises(SystemExit) as cm:
                ib.validate_startup_secrets()
            self.assertEqual(cm.exception.code, 1)


# ===========================================================================
# Incident-builder internal_auth_mode metric
# ===========================================================================

class TestIncidentBuilderInternalAuthMode(unittest.TestCase):

    def test_permissive_mode_returns_permissive(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "false"}):
            self.assertEqual(ib._auth_mode(), "permissive")

    def test_enforced_mode_returns_enforced(self):
        with patch.dict(os.environ, {"XDR_ENFORCE_INTERNAL_AUTH": "true"}):
            self.assertEqual(ib._auth_mode(), "enforced")


# ===========================================================================
# Validator: check_internal_auth_posture_service (checks 17, 18, 19)
# ===========================================================================

class TestValidatorInternalAuthServiceChecks(unittest.TestCase):

    def _run(self, component: str, token_env: str, env_vars: dict, live_mode: str = "permissive") -> dict:
        metrics_body = json.dumps({"internal_auth_mode": live_mode})

        def fake_http_get(url, timeout, max_bytes=512):
            if "metrics" in url:
                return 200, metrics_body
            return 200, ""

        with patch.object(vlp, "http_get", side_effect=fake_http_get):
            return vlp.check_internal_auth_posture_service(
                component, "http://127.0.0.1:9999", token_env, env_vars, 3
            )

    def test_alert_writer_enforced_with_token_passes(self):
        result = self._run(
            "alert-writer", "XDR_ALERT_WRITER_INTERNAL_TOKEN",
            {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_ALERT_WRITER_INTERNAL_TOKEN": "tok"},
            live_mode="enforced",
        )
        self.assertEqual(result["status"], vlp.PASS)

    def test_alert_writer_no_enforce_warns(self):
        result = self._run(
            "alert-writer", "XDR_ALERT_WRITER_INTERNAL_TOKEN",
            {"XDR_ENFORCE_INTERNAL_AUTH": ""},
        )
        self.assertEqual(result["status"], vlp.WARN)

    def test_incident_builder_enforced_with_token_passes(self):
        result = self._run(
            "incident-builder", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN",
            {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "tok"},
            live_mode="enforced",
        )
        self.assertEqual(result["status"], vlp.PASS)

    def test_incident_builder_no_enforce_warns(self):
        result = self._run(
            "incident-builder", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN",
            {},
        )
        self.assertEqual(result["status"], vlp.WARN)

    def test_correlation_worker_enforced_with_token_passes(self):
        result = self._run(
            "correlation-worker", "XDR_CORRELATION_INTERNAL_TOKEN",
            {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_CORRELATION_INTERNAL_TOKEN": "tok"},
            live_mode="enforced",
        )
        self.assertEqual(result["status"], vlp.PASS)

    def test_correlation_worker_no_enforce_warns(self):
        result = self._run(
            "correlation-worker", "XDR_CORRELATION_INTERNAL_TOKEN",
            {},
        )
        self.assertEqual(result["status"], vlp.WARN)

    def test_warn_evidence_lists_token_env_var(self):
        result = self._run(
            "alert-writer", "XDR_ALERT_WRITER_INTERNAL_TOKEN",
            {"XDR_ENFORCE_INTERNAL_AUTH": ""},
        )
        self.assertIn("XDR_ALERT_WRITER_INTERNAL_TOKEN not set", result["evidence"])

    def test_warn_evidence_lists_enforce_flag_missing(self):
        result = self._run(
            "incident-builder", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN",
            {},
        )
        self.assertIn("XDR_ENFORCE_INTERNAL_AUTH not set", result["evidence"])

    def test_pass_evidence_shows_live_mode(self):
        result = self._run(
            "correlation-worker", "XDR_CORRELATION_INTERNAL_TOKEN",
            {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_CORRELATION_INTERNAL_TOKEN": "tok"},
            live_mode="enforced",
        )
        self.assertIn("enforced", result["evidence"])

    def test_all_checks_are_not_required(self):
        for component, token_env in [
            ("alert-writer", "XDR_ALERT_WRITER_INTERNAL_TOKEN"),
            ("incident-builder", "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN"),
            ("correlation-worker", "XDR_CORRELATION_INTERNAL_TOKEN"),
        ]:
            result = self._run(component, token_env, {})
            self.assertFalse(result["required"], f"{component} check should not be required")

    def test_service_unreachable_returns_warn_with_unknown_mode(self):
        with patch.object(vlp, "http_get", return_value=(None, "refused")):
            result = vlp.check_internal_auth_posture_service(
                "alert-writer", "http://127.0.0.1:9999",
                "XDR_ALERT_WRITER_INTERNAL_TOKEN", {}, 3
            )
        self.assertEqual(result["status"], vlp.WARN)
        self.assertIn("unknown", result["evidence"])


if __name__ == "__main__":
    unittest.main()
