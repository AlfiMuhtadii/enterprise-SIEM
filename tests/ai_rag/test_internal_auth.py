"""AI-1: ai-rag-service's /v1/analyze, /v1/retrieve, /v1/embed were completely
unauthenticated -- any caller with network access to the service could invoke
them directly. Mirrors alert-writer-service/incident-builder-service's
established verify_internal_token() pattern exactly: permissive by default
(no behavior change until an operator sets a token), enforced + constant-time
comparison once XDR_ENFORCE_INTERNAL_AUTH=true and XDR_AI_RAG_INTERNAL_TOKEN
are both set."""
from __future__ import annotations

import inspect
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "ai-rag-service"
sys.path.insert(0, str(SERVICES_DIR))

sys.modules.pop("main", None)

for _mod in ("fastapi", "pydantic", "xdr_event_contracts"):
    if _mod not in sys.modules:
        _stub = types.ModuleType(_mod)
        _stub.FastAPI = MagicMock(return_value=MagicMock())  # type: ignore[attr-defined]
        _stub.Header = lambda default=None: default  # type: ignore[attr-defined]
        _stub.HTTPException = type("HTTPException", (Exception,), {  # type: ignore[attr-defined]
            "__init__": lambda self, status_code=400, detail="": Exception.__init__(self, detail)
        })
        _stub.BaseModel = object  # type: ignore[attr-defined]
        _stub.envelope = lambda **kw: {}  # type: ignore[attr-defined]
        sys.modules[_mod] = _stub

import main as ai_rag  # noqa: E402


class TestInternalTokenConstantTime(unittest.TestCase):
    _TOK = "s3cr3t-token-value"

    def _env(self, enforce, token):
        return patch.dict("os.environ", {
            "XDR_ENFORCE_INTERNAL_AUTH": enforce,
            "XDR_AI_RAG_INTERNAL_TOKEN": token,
        })

    def test_correct_token_accepted_when_enforced(self):
        with self._env("true", self._TOK):
            self.assertTrue(ai_rag.verify_internal_token(self._TOK))

    def test_wrong_token_same_length_rejected(self):
        with self._env("true", self._TOK):
            self.assertFalse(ai_rag.verify_internal_token("X3cr3t-token-valuX"))

    def test_wrong_length_rejected(self):
        with self._env("true", "s3cr3t"):
            self.assertFalse(ai_rag.verify_internal_token("s3cr3t-longer"))

    def test_permissive_when_not_configured(self):
        with self._env("false", ""):
            self.assertTrue(ai_rag.verify_internal_token("anything"))

    def test_enforced_without_configured_token_rejects_everything(self):
        with self._env("true", ""):
            self.assertFalse(ai_rag.verify_internal_token("anything"))

    def test_uses_compare_digest(self):
        src = inspect.getsource(ai_rag.verify_internal_token)
        self.assertIn("hmac.compare_digest", src)


class TestValidateStartupSecrets(unittest.TestCase):
    def test_enforced_without_token_exits(self):
        with patch.dict("os.environ", {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_AI_RAG_INTERNAL_TOKEN": ""}):
            with self.assertRaises(SystemExit):
                ai_rag.validate_startup_secrets()

    def test_enforced_with_token_does_not_exit(self):
        with patch.dict("os.environ", {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_AI_RAG_INTERNAL_TOKEN": "tok"}):
            ai_rag.validate_startup_secrets()  # must not raise

    def test_permissive_without_token_does_not_exit(self):
        with patch.dict("os.environ", {"XDR_ENFORCE_INTERNAL_AUTH": "false", "XDR_AI_RAG_INTERNAL_TOKEN": ""}):
            ai_rag.validate_startup_secrets()  # must not raise


class TestRoutesEnforceInternalToken(unittest.TestCase):
    """Every mutating/compute route must check verify_internal_token() before
    doing any work -- verified via source inspection since the FastAPI stub's
    @app.post(...) decorator shadows the real function object at module scope
    (matches this repo's established testing technique for these services)."""

    def _route_source(self, start_marker: str, end_marker: str) -> str:
        src = inspect.getsource(ai_rag)
        start = src.index(start_marker)
        end = src.index(end_marker, start + 1)
        return src[start:end]

    def test_analyze_route_checks_token(self):
        route_src = self._route_source('@app.post("/v1/analyze")', '@app.post("/v1/retrieve")')
        self.assertIn("verify_internal_token", route_src)
        self.assertIn("401", route_src)

    def test_retrieve_route_checks_token(self):
        route_src = self._route_source('@app.post("/v1/retrieve")', '@app.post("/v1/embed")')
        self.assertIn("verify_internal_token", route_src)
        self.assertIn("401", route_src)
        # AIRAG-STUB-CITATIONS regression guard -- must still be present after this change.
        self.assertIn('"provider": "stub"', route_src)
        self.assertIn('"grounded": False', route_src)

    def test_embed_route_checks_token(self):
        route_src = self._route_source('@app.post("/v1/embed")', 'def main() -> int:')
        self.assertIn("verify_internal_token", route_src)
        self.assertIn("401", route_src)

    def test_metrics_route_reports_auth_mode(self):
        route_src = self._route_source('@app.get("/metrics")', '@app.post("/v1/analyze")')
        self.assertIn("internal_auth_mode", route_src)

    def test_lifespan_validates_startup_secrets(self):
        src = inspect.getsource(ai_rag)
        self.assertIn("validate_startup_secrets()", src)


if __name__ == "__main__":
    unittest.main(verbosity=2)
