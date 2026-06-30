"""Tests for incident-builder-service consumer offset recovery."""
from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "incident-builder-service"
sys.path.insert(0, str(SERVICES_DIR))

# Remove any previously cached 'main' from alert-writer-service imports so the
# incident-builder's main.py is loaded fresh when both suites are discovered together.
sys.modules.pop("main", None)

# Stub heavy optional dependencies not installed in the test environment.
for _mod in ("fastapi", "pydantic", "xdr_event_contracts"):
    if _mod not in sys.modules:
        _stub = types.ModuleType(_mod)
        _stub.FastAPI = MagicMock(return_value=MagicMock())  # type: ignore[attr-defined]
        _stub.BaseModel = object  # type: ignore[attr-defined]
        _stub.Field = lambda *a, **kw: None  # type: ignore[attr-defined]
        # fastapi extras used by internal-auth and DLQ endpoint signatures
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


def _make_offset_err():
    import requests as req
    resp = MagicMock()
    resp.text = '{"error_code":40002,"message":"offset_out_of_range"}'
    err = req.HTTPError("400 Bad Request")
    err.response = resp
    return err


# ---------------------------------------------------------------------------
# Test 1: consumer_create sends auto.offset.reset in payload
# ---------------------------------------------------------------------------

class TestConsumerCreateIncludesOffsetReset(unittest.TestCase):
    def _post_capture(self):
        captured: dict = {}

        def fake_post(url, json=None, headers=None, timeout=None):
            captured["json"] = json
            mock_resp = MagicMock()
            mock_resp.json.return_value = {
                "base_uri": "http://localhost:8082/consumers/g/instances/n"
            }
            mock_resp.raise_for_status.return_value = None
            return mock_resp

        return captured, fake_post

    def test_default_offset_reset_is_earliest(self):
        captured, fake_post = self._post_capture()
        with patch.object(ib.SESSION, "post", side_effect=fake_post):
            ib.consumer_create("g", "n")
        self.assertIn("auto.offset.reset", captured["json"])
        self.assertEqual(captured["json"]["auto.offset.reset"], "earliest")

    def test_offset_reset_configurable_via_parameter(self):
        captured, fake_post = self._post_capture()
        with patch.object(ib.SESSION, "post", side_effect=fake_post):
            ib.consumer_create("g", "n", offset_reset="latest")
        self.assertEqual(captured["json"]["auto.offset.reset"], "latest")


# ---------------------------------------------------------------------------
# Test 2: _is_offset_range_error detects known signals
# ---------------------------------------------------------------------------

class TestIsOffsetRangeError(unittest.TestCase):
    def test_detects_offset_out_of_range_in_body(self):
        self.assertTrue(ib._is_offset_range_error(_make_offset_err()))

    def test_detects_40002_in_message_string(self):
        self.assertTrue(
            ib._is_offset_range_error(Exception("HTTP 400: error_code=40002 offset does not exist"))
        )

    def test_does_not_flag_unrelated_error(self):
        self.assertFalse(
            ib._is_offset_range_error(Exception("psycopg2.OperationalError: connection refused"))
        )


# ---------------------------------------------------------------------------
# Test 3: consumer_delete is best-effort
# ---------------------------------------------------------------------------

class TestConsumerDelete(unittest.TestCase):
    def test_delete_calls_requests_delete(self):
        delete_calls = []
        with patch.object(
            ib.SESSION, "delete",
            side_effect=lambda url, **kw: delete_calls.append(url),
        ):
            ib.consumer_delete("http://localhost:8082/consumers/g/instances/n")
        self.assertEqual(len(delete_calls), 1)

    def test_delete_does_not_raise_on_network_error(self):
        with patch.object(ib.SESSION, "delete", side_effect=ConnectionError("refused")):
            ib.consumer_delete("http://localhost:8082/consumers/g/instances/n")


# ---------------------------------------------------------------------------
# Test 4: event_loop recreates consumer on offset_out_of_range
# ---------------------------------------------------------------------------

class TestEventLoopOffsetRecovery(unittest.TestCase):
    def test_consumer_recreated_on_offset_error(self):
        """After offset_out_of_range, event_loop deletes and recreates the consumer."""
        create_calls: list = []
        delete_calls: list = []
        offset_err = _make_offset_err()
        poll_count = [0]

        def fake_post(url, json=None, headers=None, timeout=None):
            mock_resp = MagicMock()
            mock_resp.raise_for_status.return_value = None
            if "/consumers/" in url and "/subscription" not in url:
                create_calls.append(url)
                mock_resp.json.return_value = {
                    "base_uri": (
                        f"http://localhost:8082/consumers/g/instances/n{len(create_calls)}"
                    )
                }
            return mock_resp

        def fake_get(url, headers=None, timeout=None):
            poll_count[0] += 1
            if poll_count[0] == 1:
                raise offset_err
            # Recovery complete — stop after first successful post-recovery poll.
            ib.STOP.set()
            mock_resp = MagicMock()
            mock_resp.text = "[]"
            mock_resp.json.return_value = []
            mock_resp.raise_for_status.return_value = None
            return mock_resp

        def fake_delete(url, headers=None, timeout=None):
            delete_calls.append(url)
            return MagicMock()

        ib.STOP.clear()
        ib.DLQ.clear()
        with patch.object(ib.SESSION, "post", side_effect=fake_post), \
             patch.object(ib.SESSION, "get", side_effect=fake_get), \
             patch.object(ib.SESSION, "delete", side_effect=fake_delete), \
             patch.object(ib.time, "sleep", return_value=None):
            ib.event_loop()

        self.assertGreaterEqual(len(create_calls), 2,
                                "Consumer must be recreated after offset_out_of_range")
        self.assertGreaterEqual(len(delete_calls), 1,
                                "consumer_delete must be called before recreation")
        offset_dlq = [e for e in ib.DLQ if e.get("offset_range_error")]
        self.assertTrue(len(offset_dlq) >= 1, "DLQ must record offset_range_error=True")


# ---------------------------------------------------------------------------
# Test 5: normal polling does not recreate consumer
# ---------------------------------------------------------------------------

class TestEventLoopNormalPolling(unittest.TestCase):
    def test_normal_polling_does_not_recreate_consumer(self):
        """Without errors, consumer_create is called exactly once."""
        create_calls: list = []
        poll_calls: list = []

        def fake_post(url, json=None, headers=None, timeout=None):
            mock_resp = MagicMock()
            mock_resp.raise_for_status.return_value = None
            if "/consumers/" in url and "/subscription" not in url:
                create_calls.append(url)
                mock_resp.json.return_value = {
                    "base_uri": "http://localhost:8082/consumers/g/instances/n1"
                }
            return mock_resp

        def fake_get(url, headers=None, timeout=None):
            poll_calls.append(url)
            if len(poll_calls) >= 3:
                ib.STOP.set()
            mock_resp = MagicMock()
            mock_resp.text = "[]"
            mock_resp.json.return_value = []
            mock_resp.raise_for_status.return_value = None
            return mock_resp

        ib.STOP.clear()
        ib.DLQ.clear()
        with patch.object(ib.SESSION, "post", side_effect=fake_post), \
             patch.object(ib.SESSION, "get", side_effect=fake_get), \
             patch.object(ib.time, "sleep", return_value=None):
            ib.event_loop()

        self.assertEqual(len(create_calls), 1,
                         "Consumer should be created exactly once during normal polling")
        self.assertGreaterEqual(len(poll_calls), 3,
                                "Consumer should poll at least 3 times")
        error_dlq = [e for e in ib.DLQ if "error" in e]
        self.assertEqual(len(error_dlq), 0, "No DLQ errors during normal polling")


class TestHttpSessionPooling(unittest.TestCase):
    """PERF-PYTHON-HTTP: outbound HTTP must go through a reused Session."""

    def test_module_exposes_requests_session(self):
        import requests as req
        self.assertIsInstance(ib.SESSION, req.Session)


if __name__ == "__main__":
    unittest.main(verbosity=2)
