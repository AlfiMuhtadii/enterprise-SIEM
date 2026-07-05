"""Tests for ai-rag-service retrieval stub labelling (AIRAG-STUB-CITATIONS)."""
from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "ai-rag-service"
sys.path.insert(0, str(SERVICES_DIR))

# Remove any previously cached 'main' from another service's stubbed import so
# ai-rag-service's main.py is loaded fresh when suites are discovered together.
sys.modules.pop("main", None)

# Stub heavy optional dependencies not installed in the test environment.
for _mod in ("fastapi", "pydantic", "xdr_event_contracts"):
    if _mod not in sys.modules:
        _stub = types.ModuleType(_mod)
        _stub.FastAPI = MagicMock(return_value=MagicMock())  # type: ignore[attr-defined]
        _stub.BaseModel = object  # type: ignore[attr-defined]
        _stub.envelope = lambda **kw: {}  # type: ignore[attr-defined]
        sys.modules[_mod] = _stub

import main as ai_rag  # noqa: E402


class TestRetrieveStubLabelling(unittest.TestCase):
    """AIRAG-STUB-CITATIONS: /v1/retrieve has no real vector store wired up yet, so
    its response must be explicitly labelled as a non-grounded stub — never
    mistakable for a real, evidence-backed retrieval result."""

    def test_retrieve_route_labels_provider_stub_and_ungrounded(self):
        import inspect
        src = inspect.getsource(ai_rag)
        start = src.index('@app.post("/v1/retrieve")')
        end = src.index('@app.post("/v1/embed")')
        route_src = src[start:end]
        self.assertIn('"provider": "stub"', route_src)
        self.assertIn('"grounded": False', route_src)


if __name__ == "__main__":
    unittest.main(verbosity=2)
