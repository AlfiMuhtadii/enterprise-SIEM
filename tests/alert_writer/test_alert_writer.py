"""Tests for alert-writer-service consumer offset recovery."""
from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "alert-writer-service"
sys.path.insert(0, str(SERVICES_DIR))

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

import main as aw  # noqa: E402 — must come after sys.modules stubs


def _make_consumer_post_mock(create_calls: list) -> object:
    """Returns a fake requests.post that captures consumer creation URLs."""
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
    return fake_post


def _make_offset_err():
    """Build a requests.HTTPError that looks like an offset_out_of_range Pandaproxy response."""
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
    def _post_capture(self) -> tuple[dict, object]:
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
        with patch.object(aw.SESSION, "post", side_effect=fake_post):
            aw.consumer_create("g", "n")
        self.assertIn("auto.offset.reset", captured["json"])
        self.assertEqual(captured["json"]["auto.offset.reset"], "earliest")

    def test_offset_reset_configurable_via_parameter(self):
        captured, fake_post = self._post_capture()
        with patch.object(aw.SESSION, "post", side_effect=fake_post):
            aw.consumer_create("g", "n", offset_reset="latest")
        self.assertEqual(captured["json"]["auto.offset.reset"], "latest")

    def test_env_var_propagates_to_event_loop(self):
        """XDR_ALERT_WRITER_AUTO_OFFSET_RESET env var is read in event_loop."""
        create_calls: list = []

        def fake_post(url, json=None, headers=None, timeout=None):
            mock_resp = MagicMock()
            mock_resp.raise_for_status.return_value = None
            if "/consumers/" in url and "/subscription" not in url:
                create_calls.append(json)
                mock_resp.json.return_value = {
                    "base_uri": "http://localhost:8082/consumers/g/instances/n1"
                }
            return mock_resp

        def fake_get(url, headers=None, timeout=None):
            aw.STOP.set()
            mock_resp = MagicMock()
            mock_resp.text = "[]"
            mock_resp.json.return_value = []
            mock_resp.raise_for_status.return_value = None
            return mock_resp

        aw.STOP.clear()
        aw.DLQ.clear()
        with patch.dict("os.environ", {"XDR_ALERT_WRITER_AUTO_OFFSET_RESET": "latest"}), \
             patch.object(aw.SESSION, "post", side_effect=fake_post), \
             patch.object(aw.SESSION, "get", side_effect=fake_get), \
             patch.object(aw.time, "sleep", return_value=None):
            aw.event_loop()

        self.assertTrue(create_calls, "Consumer creation was not captured")
        self.assertEqual(create_calls[0].get("auto.offset.reset"), "latest")


# ---------------------------------------------------------------------------
# Test 2: _is_offset_range_error detects known signals
# ---------------------------------------------------------------------------

class TestIsOffsetRangeError(unittest.TestCase):
    def test_detects_offset_out_of_range_in_body(self):
        self.assertTrue(aw._is_offset_range_error(_make_offset_err()))

    def test_detects_40002_in_body(self):
        import requests as req
        resp = MagicMock()
        resp.text = '{"error_code":40002}'
        err = req.HTTPError("400")
        err.response = resp
        self.assertTrue(aw._is_offset_range_error(err))

    def test_detects_out_of_range_in_message_string(self):
        self.assertTrue(
            aw._is_offset_range_error(Exception("400: requested offset out of range"))
        )

    def test_does_not_flag_connection_timeout(self):
        self.assertFalse(
            aw._is_offset_range_error(Exception("connection timed out after 10s"))
        )

    def test_does_not_flag_json_parse_error(self):
        self.assertFalse(
            aw._is_offset_range_error(Exception("json.decoder.JSONDecodeError"))
        )


# ---------------------------------------------------------------------------
# Test 3: consumer_delete is best-effort
# ---------------------------------------------------------------------------

class TestConsumerDelete(unittest.TestCase):
    def test_delete_calls_requests_delete(self):
        delete_calls = []
        with patch.object(
            aw.SESSION, "delete",
            side_effect=lambda url, **kw: delete_calls.append(url),
        ):
            aw.consumer_delete("http://localhost:8082/consumers/g/instances/n")
        self.assertEqual(len(delete_calls), 1)

    def test_delete_does_not_raise_on_network_error(self):
        with patch.object(
            aw.SESSION, "delete",
            side_effect=ConnectionError("connection refused"),
        ):
            aw.consumer_delete("http://localhost:8082/consumers/g/instances/n")
        # Must not propagate the exception


# ---------------------------------------------------------------------------
# Test 4: event_loop recreates consumer on offset_out_of_range
# ---------------------------------------------------------------------------

class TestEventLoopOffsetRecovery(unittest.TestCase):
    def test_consumer_recreated_on_offset_error(self):
        """After offset_out_of_range poll error, event_loop deletes and recreates consumer."""
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
            # After recovery succeeded, stop the loop.
            aw.STOP.set()
            mock_resp = MagicMock()
            mock_resp.text = "[]"
            mock_resp.json.return_value = []
            mock_resp.raise_for_status.return_value = None
            return mock_resp

        def fake_delete(url, headers=None, timeout=None):
            delete_calls.append(url)
            return MagicMock()

        aw.STOP.clear()
        aw.DLQ.clear()
        with patch.object(aw.SESSION, "post", side_effect=fake_post), \
             patch.object(aw.SESSION, "get", side_effect=fake_get), \
             patch.object(aw.SESSION, "delete", side_effect=fake_delete), \
             patch.object(aw.time, "sleep", return_value=None):
            aw.event_loop()

        self.assertGreaterEqual(len(create_calls), 2,
                                "Consumer must be recreated after offset_out_of_range")
        self.assertGreaterEqual(len(delete_calls), 1,
                                "consumer_delete must be called before recreation")
        offset_dlq = [e for e in aw.DLQ if e.get("offset_range_error")]
        self.assertTrue(len(offset_dlq) >= 1,
                        "DLQ must record offset_range_error=True")


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
                aw.STOP.set()
            mock_resp = MagicMock()
            mock_resp.text = "[]"
            mock_resp.json.return_value = []
            mock_resp.raise_for_status.return_value = None
            return mock_resp

        aw.STOP.clear()
        aw.DLQ.clear()
        with patch.object(aw.SESSION, "post", side_effect=fake_post), \
             patch.object(aw.SESSION, "get", side_effect=fake_get), \
             patch.object(aw.time, "sleep", return_value=None):
            aw.event_loop()

        self.assertEqual(len(create_calls), 1,
                         "Consumer should be created exactly once during normal polling")
        self.assertGreaterEqual(len(poll_calls), 3,
                                "Consumer should poll at least 3 times")
        error_dlq = [e for e in aw.DLQ if "error" in e]
        self.assertEqual(len(error_dlq), 0, "No DLQ errors during normal polling")


class TestHttpSessionPooling(unittest.TestCase):
    """PERF-PYTHON-HTTP: outbound HTTP must go through a reused Session."""

    def test_module_exposes_requests_session(self):
        import requests as req
        self.assertIsInstance(aw.SESSION, req.Session)


class TestInternalTokenConstantTime(unittest.TestCase):
    """AUTH-TIMING-CMP: internal-token compare must be constant-time."""

    _TOK = "s3cr3t-token-value"

    def _env(self, enforce, token):
        return patch.dict("os.environ", {
            "XDR_ENFORCE_INTERNAL_AUTH": enforce,
            "XDR_ALERT_WRITER_INTERNAL_TOKEN": token,
        })

    def test_correct_token_accepted_when_enforced(self):
        with self._env("true", self._TOK):
            self.assertTrue(aw.verify_internal_token(self._TOK))

    def test_wrong_token_same_length_rejected(self):
        with self._env("true", self._TOK):
            self.assertFalse(aw.verify_internal_token("X3cr3t-token-valuX"))

    def test_wrong_length_rejected(self):
        with self._env("true", "s3cr3t"):
            self.assertFalse(aw.verify_internal_token("s3cr3t-longer"))

    def test_permissive_when_not_configured(self):
        with self._env("false", ""):
            self.assertTrue(aw.verify_internal_token("anything"))

    def test_uses_compare_digest(self):
        import inspect
        src = inspect.getsource(aw.verify_internal_token)
        self.assertIn("compare_digest", src)
        self.assertNotIn("== expected", src)


class TestBoundedInMemoryState(unittest.TestCase):
    """MEM-UNBOUNDED-STATE: SEEN (LRU) and DLQ (ring) must be bounded to prevent OOM."""

    def test_dlq_is_bounded_ring(self):
        from collections import deque
        self.assertIsInstance(aw.DLQ, deque)
        self.assertIsNotNone(aw.DLQ.maxlen)
        self.assertEqual(aw.DLQ.maxlen, aw._DLQ_MAX)

    def test_dlq_overflow_drops_oldest(self):
        from collections import deque
        with patch.object(aw, "DLQ", deque(maxlen=5)):
            for i in range(20):
                aw.DLQ.append({"i": i})
            self.assertEqual(len(aw.DLQ), 5)
            self.assertEqual(aw.DLQ[0]["i"], 15)   # oldest 15 kept, 0..14 dropped
            self.assertEqual(aw.DLQ[-1]["i"], 19)  # newest retained

    def test_seen_lru_evicts_oldest_over_cap(self):
        with patch.object(aw, "SEEN", aw.SEEN.__class__()), \
             patch.object(aw, "_SEEN_MAX", 3):
            for fp in ["a", "b", "c", "d", "e"]:
                aw._seen_add(fp)
            self.assertEqual(len(aw.SEEN), 3)
            self.assertNotIn("a", aw.SEEN)  # oldest evicted
            self.assertIn("e", aw.SEEN)     # newest retained

    def test_seen_add_is_idempotent(self):
        with patch.object(aw, "SEEN", aw.SEEN.__class__()), \
             patch.object(aw, "_SEEN_MAX", 100):
            aw._seen_add("x")
            aw._seen_add("x")
            self.assertEqual(len(aw.SEEN), 1)


class TestFastapiLifespan(unittest.TestCase):
    """FASTAPI-LIFESPAN: use the lifespan context manager, not deprecated on_event."""

    def test_no_deprecated_on_event(self):
        import inspect
        self.assertNotIn("on_event", inspect.getsource(aw))

    def test_lifespan_helpers_exist(self):
        self.assertTrue(hasattr(aw, "lifespan"))
        self.assertTrue(callable(aw._startup_tasks))
        self.assertTrue(callable(aw._shutdown_tasks))

    def test_shutdown_sets_stop(self):
        aw.STOP.clear()
        try:
            aw._shutdown_tasks()
            self.assertTrue(aw.STOP.is_set())
        finally:
            aw.STOP.clear()


class TestPipeConsumerAuthFix(unittest.TestCase):
    """PIPE-CONSUMER-AUTH-500: the internal event-loop path must never run through
    the HTTP-auth-checking route function — it must call the auth-free core directly,
    otherwise every batch fails once XDR_ENFORCE_INTERNAL_AUTH=true + a token is
    configured (the docker-compose.prod.yml posture), stalling the whole pipeline."""

    def test_process_alerts_calls_core_not_route(self):
        import inspect
        src = inspect.getsource(aw.process_alerts)
        self.assertIn("_write_alerts_core", src)
        self.assertNotIn("= write(", src)

    def test_core_function_has_no_token_parameter(self):
        import inspect
        sig = inspect.signature(aw._write_alerts_core)
        self.assertNotIn("x_internal_service_token", sig.parameters)

    def test_core_function_runs_without_auth_even_when_enforced(self):
        class _FakeRequest:
            alerts: list = []
            trace_id = None
            source_topic = "xdr.alerts"

        with patch.dict("os.environ", {
            "XDR_ENFORCE_INTERNAL_AUTH": "true",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN": "secret",
        }):
            result = aw._write_alerts_core(_FakeRequest())
        self.assertTrue(result["ok"])

    def test_write_route_source_still_checks_auth(self):
        # The stubbed @app.post decorator (see module header) shadows `write` with a
        # MagicMock in this test harness, so the route can't be invoked directly here —
        # assert the auth check is still present in source instead.
        import inspect
        src = inspect.getsource(aw)
        route_src = src[src.index('@app.post("/v1/write")'):]
        route_src = route_src[:route_src.index("@app.post(\"/v1/process\")")]
        self.assertIn("verify_internal_token", route_src)
        self.assertIn("HTTPException", route_src)


class _FakeAlert:
    """Minimal stand-in for AlertPayload — the stubbed pydantic BaseModel in this
    test harness can't construct a real one via kwargs (see module header)."""

    def __init__(self, alert_type="TEST_ALERT", severity="high", actor_key="host-1",
                 ip=None, evidence=None, alert_id=None, trace_id=None):
        self.alert_type = alert_type
        self.severity = severity
        self.actor_key = actor_key
        self.ip = ip
        self.evidence = evidence or {}
        self.raw_event = {}
        self.alert_id = alert_id
        self.trace_id = trace_id
        self.detected_at = None
        self.detector_name = "test"
        self.detector_version = "v1"
        self.tenant_id = None

    def model_dump(self):
        return {"alert_type": self.alert_type, "severity": self.severity}


class _FakeWriteRequest:
    """Stand-in for WriteRequest — same stub construction limitation as _FakeAlert."""

    def __init__(self, alerts, trace_id="t1", source_topic="xdr.alerts"):
        self.alerts = alerts
        self.trace_id = trace_id
        self.source_topic = source_topic


class TestDedupeAfterCommit(unittest.TestCase):
    """AW-DEDUPE-BEFORE-COMMIT: SEEN must only be marked once Postgres actually
    persisted the batch (or in pure dry-run with no backing store at all) —
    otherwise a failed write poisons SEEN and a later retry of the exact same
    batch is silently dropped as a duplicate despite never being written."""

    def setUp(self):
        aw.SEEN.clear()
        aw.DLQ.clear()

    def _request(self, alert):
        return _FakeWriteRequest(alerts=[alert])

    def test_seen_not_marked_when_postgres_write_fails(self):
        # postgres_configured() must return True here — otherwise dry_run=True
        # (no store configured at all in this sandboxed env) would mark SEEN
        # regardless of write outcome, masking the exact bug under test.
        alert = _FakeAlert()
        fp = aw.fingerprint(alert)
        with patch.object(aw, "postgres_configured", return_value=True), \
             patch.object(aw, "write_postgres", side_effect=Exception("db down")), \
             patch.object(aw, "write_opensearch", return_value=0):
            result = aw._write_alerts_core(self._request(alert))
        self.assertEqual(result["unique"], 1)
        self.assertNotIn(fp, aw.SEEN)

    def test_seen_marked_when_postgres_write_succeeds(self):
        alert = _FakeAlert()
        fp = aw.fingerprint(alert)
        with patch.object(aw, "postgres_configured", return_value=True), \
             patch.object(aw, "write_postgres", return_value=1), \
             patch.object(aw, "write_opensearch", return_value=0):
            aw._write_alerts_core(self._request(alert))
        self.assertIn(fp, aw.SEEN)

    def test_retry_after_failed_write_is_not_dropped_as_duplicate(self):
        alert = _FakeAlert()
        with patch.object(aw, "postgres_configured", return_value=True), \
             patch.object(aw, "write_postgres", side_effect=Exception("db down")), \
             patch.object(aw, "write_opensearch", return_value=0):
            first = aw._write_alerts_core(self._request(alert))
        self.assertEqual(first["unique"], 1)
        self.assertEqual(first["duplicates"], 0)

        # Retry the identical batch once the store recovers — must NOT be dropped.
        with patch.object(aw, "postgres_configured", return_value=True), \
             patch.object(aw, "write_postgres", return_value=1), \
             patch.object(aw, "write_opensearch", return_value=0):
            second = aw._write_alerts_core(self._request(alert))
        self.assertEqual(second["unique"], 1)
        self.assertEqual(second["duplicates"], 0)
        self.assertEqual(second["postgres_written"], 1)

    def test_intra_batch_duplicate_still_deduped(self):
        alert_a = _FakeAlert()
        alert_b = _FakeAlert()  # identical fingerprint material
        request = _FakeWriteRequest(alerts=[alert_a, alert_b])
        with patch.object(aw, "write_postgres", return_value=1), \
             patch.object(aw, "write_opensearch", return_value=0):
            result = aw._write_alerts_core(request)
        self.assertEqual(result["unique"], 1)
        self.assertEqual(result["duplicates"], 1)


class TestPoisonRecordIsolation(unittest.TestCase):
    """PY-POISON-RECORD-BATCH: a malformed record must be isolated to the DLQ,
    not raise and abort the whole poll batch (which would otherwise recreate
    the consumer from earliest forever on the same poison record)."""

    def setUp(self):
        aw.DLQ.clear()

    def test_malformed_record_does_not_raise(self):
        # AlertPayload construction always fails in this stubbed harness (see
        # module header) — every record below exercises the isolation path.
        records = [{"value": {"alert_type": "A"}}, {"value": {"alert_type": "B"}}]
        alerts = aw.normalize_records(records)  # must not raise
        self.assertEqual(alerts, [])

    def test_malformed_record_isolated_to_dlq(self):
        records = [{"value": {"alert_type": "A"}}, {"value": {"alert_type": "B"}}]
        aw.normalize_records(records)
        errors = [e for e in aw.DLQ if str(e.get("error", "")).startswith("alert_payload_invalid")]
        self.assertEqual(len(errors), 2)

    def test_construction_wrapped_in_try_except(self):
        import inspect
        src = inspect.getsource(aw.normalize_records)
        self.assertIn("try:", src)
        self.assertIn("alerts.append(AlertPayload(**row))", src)


class TestIncidentWriteFailedClassification(unittest.TestCase):
    """IB-DLQ-NOT-DURABLE: alert-writer's unified pipeline-DLQ classification must
    also cover xdr.incident_write_failed records (mirrors alert_write_failed)."""

    def test_incident_write_failed_postgres_error_is_replayable(self):
        records = [{"value": {
            "dlq_event_type": "incident_write_failed",
            "reason": "postgres_write_failed",
            "error_message": "db down",
            "ts": "2026-07-05T00:00:00Z",
        }}]
        parsed = aw.normalize_pipeline_dlq_records(records, "xdr.incident_write_failed")
        self.assertEqual(len(parsed), 1)
        self.assertTrue(parsed[0]["replayable"])

    def test_incident_write_failed_event_loop_error_is_not_replayable(self):
        records = [{"value": {
            "dlq_event_type": "incident_write_failed",
            "reason": "event_loop_error",
            "error_message": "unknown",
            "ts": "2026-07-05T00:00:00Z",
        }}]
        parsed = aw.normalize_pipeline_dlq_records(records, "xdr.incident_write_failed")
        self.assertFalse(parsed[0]["replayable"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
