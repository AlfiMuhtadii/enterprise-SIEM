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


class TestInternalTokenConstantTime(unittest.TestCase):
    """AUTH-TIMING-CMP: internal-token compare must be constant-time."""

    _TOK = "s3cr3t-token-value"

    def _env(self, enforce, token):
        return patch.dict("os.environ", {
            "XDR_ENFORCE_INTERNAL_AUTH": enforce,
            "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": token,
        })

    def test_correct_token_accepted_when_enforced(self):
        with self._env("true", self._TOK):
            self.assertTrue(ib.verify_internal_token(self._TOK))

    def test_wrong_token_same_length_rejected(self):
        with self._env("true", self._TOK):
            self.assertFalse(ib.verify_internal_token("X3cr3t-token-valuX"))

    def test_wrong_length_rejected(self):
        with self._env("true", "s3cr3t"):
            self.assertFalse(ib.verify_internal_token("s3cr3t-longer"))

    def test_permissive_when_not_configured(self):
        with self._env("false", ""):
            self.assertTrue(ib.verify_internal_token("anything"))

    def test_uses_compare_digest(self):
        import inspect
        src = inspect.getsource(ib.verify_internal_token)
        self.assertIn("compare_digest", src)
        self.assertNotIn("== expected", src)


class TestBoundedInMemoryState(unittest.TestCase):
    """MEM-UNBOUNDED-STATE: DLQ must be a bounded ring buffer to prevent OOM."""

    def test_dlq_is_bounded_ring(self):
        from collections import deque
        self.assertIsInstance(ib.DLQ, deque)
        self.assertIsNotNone(ib.DLQ.maxlen)
        self.assertEqual(ib.DLQ.maxlen, ib._DLQ_MAX)

    def test_dlq_overflow_drops_oldest(self):
        from collections import deque
        with patch.object(ib, "DLQ", deque(maxlen=5)):
            for i in range(20):
                ib.DLQ.append({"i": i})
            self.assertEqual(len(ib.DLQ), 5)
            self.assertEqual(ib.DLQ[0]["i"], 15)   # oldest dropped
            self.assertEqual(ib.DLQ[-1]["i"], 19)  # newest retained


class TestFastapiLifespan(unittest.TestCase):
    """FASTAPI-LIFESPAN: use the lifespan context manager, not deprecated on_event."""

    def test_no_deprecated_on_event(self):
        import inspect
        self.assertNotIn("on_event", inspect.getsource(ib))

    def test_lifespan_helpers_exist(self):
        self.assertTrue(hasattr(ib, "lifespan"))
        self.assertTrue(callable(ib._startup_tasks))
        self.assertTrue(callable(ib._shutdown_tasks))

    def test_shutdown_sets_stop(self):
        ib.STOP.clear()
        try:
            ib._shutdown_tasks()
            self.assertTrue(ib.STOP.is_set())
        finally:
            ib.STOP.clear()


class TestPipeConsumerAuthFix(unittest.TestCase):
    """PIPE-CONSUMER-AUTH-500: the internal event-loop path must never run through
    the HTTP-auth-checking route function — it must call the auth-free core directly,
    otherwise every batch fails once XDR_ENFORCE_INTERNAL_AUTH=true + a token is
    configured (the docker-compose.prod.yml posture), stalling the whole pipeline."""

    def test_process_alerts_calls_core_not_route(self):
        import inspect
        src = inspect.getsource(ib.process_alerts)
        self.assertIn("_build_incidents_core", src)
        self.assertNotIn("= build(", src)

    def test_core_function_has_no_token_parameter(self):
        import inspect
        sig = inspect.signature(ib._build_incidents_core)
        self.assertNotIn("x_internal_service_token", sig.parameters)

    def test_core_function_runs_without_auth_even_when_enforced(self):
        class _FakeRequest:
            alerts: list = []
            trace_id = None
            source_topic = "xdr.alerts"

        with patch.dict("os.environ", {
            "XDR_ENFORCE_INTERNAL_AUTH": "true",
            "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "secret",
        }):
            result = ib._build_incidents_core(_FakeRequest())
        self.assertTrue(result["ok"])

    def test_build_route_source_still_checks_auth(self):
        # The stubbed @app.post decorator (see module header) shadows `build` with a
        # MagicMock in this test harness, so the route can't be invoked directly here —
        # assert the auth check is still present in source instead.
        import inspect
        src = inspect.getsource(ib)
        route_src = src[src.index('@app.post("/v1/build")'):]
        route_src = route_src[:route_src.index("@app.post(\"/v1/process\")")]
        self.assertIn("verify_internal_token", route_src)
        self.assertIn("HTTPException", route_src)


if __name__ == "__main__":
    unittest.main(verbosity=2)
