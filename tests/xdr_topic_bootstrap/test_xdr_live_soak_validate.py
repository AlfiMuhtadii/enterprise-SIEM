"""
Tests for scripts/xdr_live_soak_validate.py — ENTERPRISE-038.

All tests are deterministic and offline.  No real network calls, no real sleep.
"""
from __future__ import annotations

import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import patch, MagicMock

_SCRIPTS = Path(__file__).resolve().parent.parent.parent / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

import xdr_live_soak_validate as soak


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _args(
    execute=False, duration_minutes=2, events_per_batch=5,
    batch_interval_ms=2000, ingest_url="http://localhost:8091/v1/ingest",
    admin_url="http://localhost:9644", scenario_id="soak-test",
    tenant_id="tenant-test", profile="local", timeout_seconds=5,
    output="", quiet=True,
) -> types.SimpleNamespace:
    ns = types.SimpleNamespace()
    ns.execute = execute
    ns.duration_minutes = duration_minutes
    ns.events_per_batch = events_per_batch
    ns.batch_interval_ms = batch_interval_ms
    ns.ingest_url = ingest_url
    ns.admin_url = admin_url
    ns.scenario_id = scenario_id
    ns.tenant_id = tenant_id
    ns.profile = profile
    ns.timeout_seconds = timeout_seconds
    ns.output = output
    ns.quiet = quiet
    return ns


def _gateway_up(url, timeout):
    return 200, '{"status":"ok"}'


def _gateway_down(url, timeout):
    return None, "Connection refused"


def _post_202(url, headers, body, timeout):
    batch = json.loads(body)
    return 202, json.dumps({"accepted": len(batch), "latency_ms": 12})


def _post_429(url, headers, body, timeout):
    return 429, '{"error":"rate limited"}'


def _post_503(url, headers, body, timeout):
    return 503, '{"error":"circuit open"}'


def _post_timeout(url, headers, body, timeout):
    return 0, "OSError: timed out"


def _no_sleep(seconds):
    pass


# ---------------------------------------------------------------------------
# TestConstants
# ---------------------------------------------------------------------------

class TestConstants(unittest.TestCase):
    def test_max_duration_minutes(self):
        self.assertEqual(soak.MAX_DURATION_MINUTES, 60)

    def test_max_events_per_batch(self):
        self.assertEqual(soak.MAX_EVENTS_PER_BATCH, 50)

    def test_max_total_events(self):
        self.assertEqual(soak.MAX_TOTAL_EVENTS, 1000)

    def test_min_batch_interval_ms(self):
        self.assertLessEqual(soak.MIN_BATCH_INTERVAL_MS, 500)

    def test_soak_topics_present(self):
        for t in ("telemetry.raw", "telemetry.normalized", "xdr.alerts"):
            self.assertIn(t, soak._SOAK_TOPICS)

    def test_dlq_topics_present(self):
        for t in ("telemetry.normalization_failed", "xdr.alert_write_failed"):
            self.assertIn(t, soak._DLQ_TOPICS)

    def test_event_types_have_active_domain(self):
        domains = {e[1] for e in soak._EVENT_TYPES}
        self.assertTrue(domains & {"cloud", "identity", "saas"})


# ---------------------------------------------------------------------------
# TestSyntheticEvent
# ---------------------------------------------------------------------------

class TestSyntheticEvent(unittest.TestCase):
    def _ev(self, seq=0):
        return soak.make_event(seq, "soak-20260624-abc123", "scen-test", "tenant-test")

    def test_event_has_demo_run_id(self):
        ev = self._ev()
        self.assertEqual(ev["demo_run_id"], "soak-20260624-abc123")

    def test_event_has_scenario_id(self):
        ev = self._ev()
        self.assertEqual(ev["scenario_id"], "scen-test")

    def test_event_has_tenant_id(self):
        ev = self._ev()
        self.assertEqual(ev["tenant_id"], "tenant-test")

    def test_event_has_source_event_id(self):
        ev = self._ev()
        self.assertIn("source_event_id", ev)
        self.assertTrue(ev["source_event_id"])

    def test_event_id_equals_trace_id(self):
        ev = self._ev()
        self.assertEqual(ev["event_id"], ev["trace_id"])

    def test_event_has_active_domain(self):
        ev = self._ev()
        self.assertIn(ev["domain"], ("cloud", "identity", "saas"))

    def test_event_has_timestamp(self):
        ev = self._ev()
        self.assertIn("timestamp", ev)
        self.assertTrue(ev["timestamp"])

    def test_events_rotate_types(self):
        types_seen = {soak.make_event(i, "r", "s", "t")["event_type"]
                      for i in range(len(soak._EVENT_TYPES))}
        self.assertEqual(len(types_seen), len(soak._EVENT_TYPES))

    def test_batch_has_correct_size(self):
        batch = soak.make_batch(0, 5, "soak-run", "scen", "tenant")
        self.assertEqual(len(batch), 5)

    def test_batch_events_have_unique_ids(self):
        batch = soak.make_batch(0, 10, "soak-run", "scen", "tenant")
        ids = [ev["event_id"] for ev in batch]
        self.assertEqual(len(set(ids)), 10)


# ---------------------------------------------------------------------------
# TestHMAC
# ---------------------------------------------------------------------------

class TestHMAC(unittest.TestCase):
    def test_sign_returns_sha256_prefix(self):
        sig = soak._sign("mysecret", b"hello")
        self.assertTrue(sig.startswith("sha256="))

    def test_sign_hex_length(self):
        sig = soak._sign("mysecret", b"hello")
        hex_part = sig[len("sha256="):]
        self.assertEqual(len(hex_part), 64)

    def test_sign_different_secrets(self):
        sig1 = soak._sign("secret1", b"body")
        sig2 = soak._sign("secret2", b"body")
        self.assertNotEqual(sig1, sig2)

    def test_sign_empty_secret_still_works(self):
        sig = soak._sign("", b"body")
        self.assertTrue(sig.startswith("sha256="))


# ---------------------------------------------------------------------------
# TestBuildPlan
# ---------------------------------------------------------------------------

class TestBuildPlan(unittest.TestCase):
    def _plan(self, dur=2, epb=5, ms=2000):
        return soak.build_plan(dur, epb, ms, "http://localhost:8091/v1/ingest",
                               "soak-run-id", "scen", "tenant")

    def test_plan_has_soak_run_id(self):
        p = self._plan()
        self.assertEqual(p["soak_run_id"], "soak-run-id")

    def test_plan_total_events_capped(self):
        # 60min × (60000/200ms) = 60 × 300 = 18000 batches × 5 = 90000 → capped at 1000
        p = soak.build_plan(60, 5, 200, "http://x", "r", "s", "t")
        self.assertLessEqual(p["total_events_planned"], soak.MAX_TOTAL_EVENTS)

    def test_plan_cap_flag_set(self):
        p = soak.build_plan(60, 5, 200, "http://x", "r", "s", "t")
        self.assertTrue(p["capped"])

    def test_plan_not_capped_for_short_run(self):
        # 1min × (60000/5000ms) = 12 batches × 5 = 60 events < 1000
        p = soak.build_plan(1, 5, 5000, "http://x", "r", "s", "t")
        self.assertFalse(p["capped"])
        self.assertEqual(p["total_events_planned"], 60)

    def test_plan_throughput_positive(self):
        p = self._plan()
        self.assertGreater(p["throughput_eps"], 0)

    def test_plan_includes_topics_monitored(self):
        p = self._plan()
        self.assertIn("telemetry.raw", p["topics_monitored"])
        self.assertIn("xdr.alerts", p["topics_monitored"])

    def test_plan_ingest_url(self):
        p = self._plan()
        self.assertEqual(p["ingest_url"], "http://localhost:8091/v1/ingest")


# ---------------------------------------------------------------------------
# TestPreflightChecks
# ---------------------------------------------------------------------------

class TestPreflightChecks(unittest.TestCase):
    def test_duration_passes_within_bounds(self):
        r = soak.check_duration_bounds(5)
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "PRE-01")

    def test_duration_fails_at_zero(self):
        r = soak.check_duration_bounds(0)
        self.assertEqual(r["status"], "FAIL")

    def test_duration_fails_above_max(self):
        r = soak.check_duration_bounds(soak.MAX_DURATION_MINUTES + 1)
        self.assertEqual(r["status"], "FAIL")

    def test_duration_passes_at_max(self):
        r = soak.check_duration_bounds(soak.MAX_DURATION_MINUTES)
        self.assertEqual(r["status"], "PASS")

    def test_batch_bounds_pass(self):
        r = soak.check_batch_bounds(5, 2000)
        self.assertEqual(r["status"], "PASS")

    def test_batch_bounds_fails_on_high_epb(self):
        r = soak.check_batch_bounds(soak.MAX_EVENTS_PER_BATCH + 1, 2000)
        self.assertEqual(r["status"], "FAIL")

    def test_batch_bounds_fails_on_low_interval(self):
        r = soak.check_batch_bounds(5, soak.MIN_BATCH_INTERVAL_MS - 1)
        self.assertEqual(r["status"], "FAIL")

    def test_total_cap_passes(self):
        r = soak.check_total_events_cap(100)
        self.assertEqual(r["status"], "PASS")

    def test_total_cap_fails_above_max(self):
        r = soak.check_total_events_cap(soak.MAX_TOTAL_EVENTS + 1)
        self.assertEqual(r["status"], "FAIL")

    def test_gateway_passes_on_200(self):
        r = soak.check_gateway_reachable("http://x/v1/ingest", 5, True,
                                         _get_fn=_gateway_up)
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "PRE-04")

    def test_gateway_fails_in_execute_on_unreachable(self):
        r = soak.check_gateway_reachable("http://x/v1/ingest", 5, True,
                                         _get_fn=_gateway_down)
        self.assertEqual(r["status"], "FAIL")

    def test_gateway_warns_in_dryrun_on_unreachable(self):
        r = soak.check_gateway_reachable("http://x/v1/ingest", 5, False,
                                         _get_fn=_gateway_down)
        self.assertEqual(r["status"], "WARN")


# ---------------------------------------------------------------------------
# TestPercentiles
# ---------------------------------------------------------------------------

class TestPercentiles(unittest.TestCase):
    def test_p95_of_sorted_list(self):
        vals = list(range(1, 101))  # 1..100
        p95 = soak._pct(vals, 95)
        self.assertAlmostEqual(p95, 95, delta=1)

    def test_p99_of_sorted_list(self):
        vals = list(range(1, 101))
        p99 = soak._pct(vals, 99)
        self.assertAlmostEqual(p99, 99, delta=1)

    def test_empty_list_returns_zero(self):
        self.assertEqual(soak._pct([], 95), 0.0)
        self.assertEqual(soak._pct([], 99), 0.0)

    def test_single_element(self):
        self.assertEqual(soak._pct([42.0], 95), 42.0)

    def test_mean_empty(self):
        self.assertEqual(soak._mean([]), 0.0)

    def test_mean_values(self):
        self.assertAlmostEqual(soak._mean([10.0, 20.0, 30.0]), 20.0)


# ---------------------------------------------------------------------------
# TestPrometheusParser
# ---------------------------------------------------------------------------

class TestPrometheusParser(unittest.TestCase):
    _SAMPLE = """\
# HELP redpanda_kafka_max_offset High watermark
# TYPE redpanda_kafka_max_offset gauge
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_topic="telemetry.raw"} 150
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_topic="xdr.alerts"} 42
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_topic="telemetry.normalized"} 0
"""

    def test_parses_nonzero_offset(self):
        v = soak._parse_prometheus_gauge(
            self._SAMPLE, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": "telemetry.raw"},
        )
        self.assertEqual(v, 150.0)

    def test_parses_zero_offset(self):
        v = soak._parse_prometheus_gauge(
            self._SAMPLE, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": "telemetry.normalized"},
        )
        self.assertEqual(v, 0.0)

    def test_returns_none_for_missing_topic(self):
        v = soak._parse_prometheus_gauge(
            self._SAMPLE, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": "nonexistent"},
        )
        self.assertIsNone(v)

    def test_returns_none_on_empty_text(self):
        v = soak._parse_prometheus_gauge("", "redpanda_kafka_max_offset",
                                         {"redpanda_topic": "x"})
        self.assertIsNone(v)


# ---------------------------------------------------------------------------
# TestWatermarkCollection
# ---------------------------------------------------------------------------

class TestWatermarkCollection(unittest.TestCase):
    _METRICS = """\
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_topic="telemetry.raw"} 100
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_topic="xdr.alerts"} 20
"""

    def _get_fn_ok(self, url, timeout):
        return 200, self._METRICS

    def _get_fn_err(self, url, timeout):
        return None, "error"

    def test_returns_offsets_on_200(self):
        wm = soak.collect_watermarks("http://x", ["telemetry.raw", "xdr.alerts"],
                                     5, self._get_fn_ok)
        self.assertEqual(wm["telemetry.raw"], 100)
        self.assertEqual(wm["xdr.alerts"], 20)

    def test_returns_none_on_error(self):
        wm = soak.collect_watermarks("http://x", ["telemetry.raw"], 5, self._get_fn_err)
        self.assertIsNone(wm["telemetry.raw"])

    def test_missing_topic_returns_none(self):
        wm = soak.collect_watermarks("http://x", ["nonexistent.topic"], 5, self._get_fn_ok)
        self.assertIsNone(wm.get("nonexistent.topic"))


# ---------------------------------------------------------------------------
# TestBoundsEvaluation
# ---------------------------------------------------------------------------

class TestBoundsEvaluation(unittest.TestCase):
    def _raw(self, accepted=95, rate_limited=2, rejected=3,
             pub_fail=0, cb=0, lats=None):
        lats = lats or [50.0] * 100
        return {
            "total_attempted": accepted + rate_limited + rejected,
            "accepted": accepted, "rate_limited": rate_limited,
            "rejected": rejected, "publish_failures": pub_fail,
            "circuit_breaker_opens": cb, "latencies_ms": lats,
        }

    def test_passes_with_good_metrics(self):
        bounds = soak.evaluate_bounds(self._raw(), "local")
        statuses = {b["bound_id"]: b["status"] for b in bounds}
        self.assertEqual(statuses["B-01"], "PASS")
        self.assertEqual(statuses["B-03"], "PASS")
        self.assertEqual(statuses["B-05"], "PASS")

    def test_fails_on_low_accepted_rate(self):
        raw = self._raw(accepted=50, rejected=50)
        bounds = soak.evaluate_bounds(raw, "local")
        b01 = next(b for b in bounds if b["bound_id"] == "B-01")
        self.assertEqual(b01["status"], "FAIL")

    def test_warns_on_border_accepted_rate(self):
        # 85% accepted → WARN (< 0.90 but >= 0.80)
        raw = self._raw(accepted=85, rejected=15)
        bounds = soak.evaluate_bounds(raw, "local")
        b01 = next(b for b in bounds if b["bound_id"] == "B-01")
        self.assertEqual(b01["status"], "WARN")

    def test_fails_on_high_p95_latency(self):
        # 600ms latencies → p95 = 600 → FAIL (>= 500)
        raw = self._raw(lats=[600.0] * 100)
        bounds = soak.evaluate_bounds(raw, "local")
        b03 = next(b for b in bounds if b["bound_id"] == "B-03")
        self.assertEqual(b03["status"], "FAIL")

    def test_passes_on_low_latency(self):
        raw = self._raw(lats=[50.0] * 100)
        bounds = soak.evaluate_bounds(raw, "local")
        b03 = next(b for b in bounds if b["bound_id"] == "B-03")
        self.assertEqual(b03["status"], "PASS")

    def test_warns_on_one_publish_failure(self):
        raw = self._raw(pub_fail=1)
        bounds = soak.evaluate_bounds(raw, "local")
        b05 = next(b for b in bounds if b["bound_id"] == "B-05")
        self.assertEqual(b05["status"], "WARN")

    def test_fails_on_high_publish_failures(self):
        raw = self._raw(pub_fail=3)
        bounds = soak.evaluate_bounds(raw, "local")
        b05 = next(b for b in bounds if b["bound_id"] == "B-05")
        self.assertEqual(b05["status"], "FAIL")

    def test_fails_on_circuit_breaker_open(self):
        raw = self._raw(cb=2)
        bounds = soak.evaluate_bounds(raw, "local")
        b06 = next(b for b in bounds if b["bound_id"] == "B-06")
        self.assertEqual(b06["status"], "FAIL")

    def test_six_bounds_returned(self):
        bounds = soak.evaluate_bounds(self._raw(), "local")
        self.assertEqual(len(bounds), 6)

    def test_bound_ids_are_b01_to_b06(self):
        bounds = soak.evaluate_bounds(self._raw(), "local")
        ids = {b["bound_id"] for b in bounds}
        self.assertEqual(ids, {"B-01", "B-02", "B-03", "B-04", "B-05", "B-06"})


# ---------------------------------------------------------------------------
# TestSoakLoop
# ---------------------------------------------------------------------------

class TestSoakLoop(unittest.TestCase):
    def _plan(self, epb=5, batches=3, interval_ms=0):
        return {
            "soak_run_id": "soak-test", "scenario_id": "s", "tenant_id": "t",
            "ingest_url": "http://localhost:8091/v1/ingest",
            "events_per_batch": epb, "batch_interval_ms": interval_ms,
            "total_batches": batches,
        }

    def test_accepted_count_on_all_202(self):
        raw = soak.run_soak_loop(self._plan(), "secret", 5,
                                 _post_fn=_post_202, _sleep_fn=_no_sleep)
        self.assertEqual(raw["accepted"], 15)  # 3 batches × 5 events

    def test_rate_limited_count_on_all_429(self):
        raw = soak.run_soak_loop(self._plan(), "secret", 5,
                                 _post_fn=_post_429, _sleep_fn=_no_sleep)
        self.assertEqual(raw["rate_limited"], 15)
        self.assertEqual(raw["accepted"], 0)

    def test_circuit_breaker_opens_on_consecutive_503(self):
        # 3 batches of 503 → circuit_breaker_opens = 1
        raw = soak.run_soak_loop(self._plan(batches=3), "secret", 5,
                                 _post_fn=_post_503, _sleep_fn=_no_sleep)
        self.assertEqual(raw["circuit_breaker_opens"], 1)

    def test_publish_failures_on_timeout(self):
        raw = soak.run_soak_loop(self._plan(batches=2), "secret", 5,
                                 _post_fn=_post_timeout, _sleep_fn=_no_sleep)
        self.assertEqual(raw["publish_failures"], 2)

    def test_latencies_recorded_per_batch(self):
        raw = soak.run_soak_loop(self._plan(batches=4), "secret", 5,
                                 _post_fn=_post_202, _sleep_fn=_no_sleep)
        self.assertEqual(len(raw["latencies_ms"]), 4)

    def test_batch_results_count(self):
        raw = soak.run_soak_loop(self._plan(batches=3), "secret", 5,
                                 _post_fn=_post_202, _sleep_fn=_no_sleep)
        self.assertEqual(len(raw["batch_results"]), 3)

    def test_no_sleep_called_on_zero_interval(self):
        sleep_called = []
        def fake_sleep(s):
            sleep_called.append(s)
        soak.run_soak_loop(self._plan(interval_ms=0, batches=3), "secret", 5,
                           _post_fn=_post_202, _sleep_fn=fake_sleep)
        self.assertEqual(len(sleep_called), 0)

    def test_sleep_called_with_interval(self):
        sleep_called = []
        def fake_sleep(s):
            sleep_called.append(s)
        soak.run_soak_loop(self._plan(interval_ms=500, batches=3), "secret", 5,
                           _post_fn=_post_202, _sleep_fn=fake_sleep)
        # Sleep called between batches (not after last)
        self.assertEqual(len(sleep_called), 2)
        self.assertAlmostEqual(sleep_called[0], 0.5, places=2)


# ---------------------------------------------------------------------------
# TestBuildReport
# ---------------------------------------------------------------------------

class TestBuildReport(unittest.TestCase):
    def _minimal(self):
        plan = soak.build_plan(2, 5, 2000, "http://x", "r", "s", "t")
        preflight = [{"step_id": "PRE-01", "name": "n", "status": "PASS",
                      "detail": "", "remediation": ""}]
        raw = {"total_attempted": 10, "accepted": 10, "rate_limited": 0,
               "rejected": 0, "publish_failures": 0, "timeouts": 0,
               "circuit_breaker_opens": 0, "latencies_ms": [50.0] * 10,
               "batch_results": [], "total_batches_run": 2}
        bounds = soak.evaluate_bounds(raw, "local")
        return soak.build_report(plan, preflight, raw, bounds, {}, {},
                                 _args(), "t1", "t2")

    def test_task_is_enterprise038(self):
        r = self._minimal()
        self.assertEqual(r["task"], "ENTERPRISE-038")

    def test_overall_pass_on_good_metrics(self):
        r = self._minimal()
        self.assertEqual(r["overall"], "PASS")

    def test_overall_fail_on_preflight_fail(self):
        plan = soak.build_plan(2, 5, 2000, "http://x", "r", "s", "t")
        pf = [{"step_id": "PRE-04", "name": "n", "status": "FAIL",
               "detail": "", "remediation": ""}]
        raw = {"total_attempted": 0, "accepted": 0, "rate_limited": 0,
               "rejected": 0, "publish_failures": 0, "timeouts": 0,
               "circuit_breaker_opens": 0, "latencies_ms": [],
               "batch_results": [], "total_batches_run": 0}
        r = soak.build_report(plan, pf, raw, [], {}, {}, _args(), "t1", "t2")
        self.assertEqual(r["overall"], "FAIL")

    def test_overall_fail_on_bound_fail(self):
        plan = soak.build_plan(2, 5, 2000, "http://x", "r", "s", "t")
        pf = [{"step_id": "PRE-01", "name": "n", "status": "PASS",
               "detail": "", "remediation": ""}]
        raw = {"total_attempted": 100, "accepted": 50, "rate_limited": 0,
               "rejected": 50, "publish_failures": 0, "timeouts": 0,
               "circuit_breaker_opens": 0, "latencies_ms": [50.0] * 100,
               "batch_results": [], "total_batches_run": 20}
        bounds = soak.evaluate_bounds(raw, "local")
        r = soak.build_report(plan, pf, raw, bounds, {}, {}, _args(), "t1", "t2")
        self.assertEqual(r["overall"], "FAIL")

    def test_report_has_metrics_block(self):
        r = self._minimal()
        m = r["metrics"]
        for k in ("total_attempted", "accepted", "p95_latency_ms", "p99_latency_ms"):
            self.assertIn(k, m)

    def test_report_has_plan(self):
        r = self._minimal()
        self.assertIn("plan", r)
        self.assertIn("soak_run_id", r["plan"])

    def test_report_has_watermarks(self):
        r = self._minimal()
        self.assertIn("watermarks_before", r)
        self.assertIn("watermarks_after", r)

    def test_mode_dry_run(self):
        r = self._minimal()
        self.assertEqual(r["mode"], "dry-run")

    def test_mode_execute(self):
        plan = soak.build_plan(2, 5, 2000, "http://x", "r", "s", "t")
        raw = {"total_attempted": 0, "accepted": 0, "rate_limited": 0,
               "rejected": 0, "publish_failures": 0, "timeouts": 0,
               "circuit_breaker_opens": 0, "latencies_ms": [],
               "batch_results": [], "total_batches_run": 0}
        r = soak.build_report(plan, [], raw, [], {}, {},
                               _args(execute=True), "t1", "t2")
        self.assertEqual(r["mode"], "execute")

    def test_status_line_contains_accepted(self):
        r = self._minimal()
        self.assertIn("accepted=", r["status_line"])


# ---------------------------------------------------------------------------
# TestDryRunBehavior
# ---------------------------------------------------------------------------

class TestDryRunBehavior(unittest.TestCase):
    def test_dry_run_never_calls_post(self):
        post_called = []
        def fake_post(url, headers, body, timeout):
            post_called.append(1)
            return 202, '{"accepted":1}'
        soak.run_validate(_args(execute=False), root=Path("/fake"),
                          env={}, _get_fn=_gateway_up, _post_fn=fake_post,
                          _sleep_fn=_no_sleep)
        self.assertEqual(len(post_called), 0)

    def test_dry_run_exits_zero_when_gateway_up(self):
        rc = soak.main(_args(execute=False, quiet=True), root=Path("/fake"),
                       env={}, _get_fn=_gateway_up, _sleep_fn=_no_sleep)
        self.assertEqual(rc, 0)

    def test_dry_run_exits_zero_when_gateway_down(self):
        # Dry-run uses WARN not FAIL for unreachable gateway
        rc = soak.main(_args(execute=False, quiet=True), root=Path("/fake"),
                       env={}, _get_fn=_gateway_down, _sleep_fn=_no_sleep)
        self.assertEqual(rc, 0)

    def test_dry_run_report_has_no_metrics(self):
        report = soak.run_validate(_args(execute=False), root=Path("/fake"),
                                   env={}, _get_fn=_gateway_up, _sleep_fn=_no_sleep)
        self.assertEqual(report["metrics"]["total_attempted"], 0)


# ---------------------------------------------------------------------------
# TestExecuteAbort
# ---------------------------------------------------------------------------

class TestExecuteAbort(unittest.TestCase):
    def test_aborts_when_gateway_unreachable_in_execute(self):
        report = soak.run_validate(
            _args(execute=True), root=Path("/fake"), env={},
            _get_fn=_gateway_down, _sleep_fn=_no_sleep,
        )
        step_ids = [s["step_id"] for s in report["preflight"]]
        self.assertIn("ABORT", step_ids)

    def test_aborts_when_duration_out_of_bounds(self):
        args = _args(execute=True, duration_minutes=0)
        report = soak.run_validate(args, root=Path("/fake"), env={},
                                   _get_fn=_gateway_up, _sleep_fn=_no_sleep)
        step_ids = [s["step_id"] for s in report["preflight"]]
        self.assertIn("ABORT", step_ids)


# ---------------------------------------------------------------------------
# TestMainExitCode
# ---------------------------------------------------------------------------

class TestMainExitCode(unittest.TestCase):
    def test_exits_zero_on_dry_run_all_pass(self):
        rc = soak.main(_args(execute=False, quiet=True), root=Path("/fake"),
                       env={}, _get_fn=_gateway_up, _sleep_fn=_no_sleep)
        self.assertEqual(rc, 0)

    def test_exits_one_on_execute_all_rejections(self):
        # 3 batches × 5 events = 15 attempted, 0 accepted → accepted_rate = 0 → FAIL
        rc = soak.main(
            _args(execute=True, quiet=True, duration_minutes=1,
                  events_per_batch=5, batch_interval_ms=soak.MIN_BATCH_INTERVAL_MS),
            root=Path("/fake"), env={},
            _get_fn=_gateway_up, _post_fn=_post_429, _sleep_fn=_no_sleep,
        )
        self.assertEqual(rc, 1)

    def test_output_writes_json(self):
        import tempfile
        tmp = Path(tempfile.mktemp(suffix=".json"))
        soak.main(_args(execute=False, quiet=True, output=str(tmp)),
                  root=Path("/fake"), env={}, _get_fn=_gateway_up,
                  _sleep_fn=_no_sleep)
        self.assertTrue(tmp.exists())
        data = json.loads(tmp.read_text())
        self.assertEqual(data["task"], "ENTERPRISE-038")
        tmp.unlink(missing_ok=True)

    def test_default_args_is_dry_run(self):
        ns = soak._parse_args([])
        self.assertFalse(ns.execute)


# ---------------------------------------------------------------------------
# TestSafetyInvariants
# ---------------------------------------------------------------------------

class TestSafetyInvariants(unittest.TestCase):
    def _src(self):
        return (Path(__file__).resolve().parent.parent.parent
                / "scripts" / "xdr_live_soak_validate.py").read_text(encoding="utf-8")

    def test_no_active_allowlist_logic(self):
        # ACTIVE_ALLOWLIST may appear in docstring; must never appear in code logic
        src = self._src()
        lines_with = [l for l in src.splitlines()
                      if "ACTIVE_ALLOWLIST" in l and not l.lstrip().startswith(("-", "#", '"', "'"))]
        self.assertEqual(len(lines_with), 0,
                         f"ACTIVE_ALLOWLIST found in logic: {lines_with}")

    def test_no_containment_code(self):
        src = self._src()
        for bad in ("XDR_LIVE_CONTAINMENT", "execute_containment", "block_ip"):
            self.assertNotIn(bad, src)

    def test_no_autonomous_remediation(self):
        src = self._src()
        self.assertNotIn("XDR_AUTONOMOUS_RESPONSE_ENABLED", src)
        self.assertNotIn("auto_remediate", src)

    def test_events_use_soak_domain(self):
        for ev_type, domain, source, action in soak._EVENT_TYPES:
            self.assertIn(domain, ("cloud", "identity", "saas"),
                          f"Event type {ev_type} uses non-active domain {domain!r}")

    def test_max_total_events_capped(self):
        self.assertLessEqual(soak.MAX_TOTAL_EVENTS, 5000)

    def test_default_execute_is_false(self):
        self.assertFalse(soak._parse_args([]).execute)

    def test_no_sql_mutation(self):
        src = self._src()
        for bad in ("INSERT INTO", "UPDATE ", "DELETE FROM", "TRUNCATE "):
            self.assertNotIn(bad, src, f"Found unsafe SQL: {bad!r}")


if __name__ == "__main__":
    unittest.main()
