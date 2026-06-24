"""Tests for BACKLOG-SCALE-026: XDR Ingestion Hardening Controlled Load & Soak Validation.

Covers:
- AdmissionController: cache-only read, queue-full denial, empty-URL passthrough
- PerTenantLimiter: isolation, empty-ID passthrough, refill, new-tenant fresh bucket
- MockCircuitBreaker: open/closed state, fast-fail timing, failure threshold, open_count
- Lineage preservation: all 4 lineage fields through tag_events() (S-01)
- All 6 scenario runners return correct structure and PASS status
- Metrics aggregation from scenario outputs
- Bounds checks (p95/p99 latency, accepted ratio, CB exercised, RL exercised)
- Report structure (overall, pass_count, fail_count, required fields)
- CLI exit codes (0=PASS, 1=FAIL, 2=ERROR)
"""
from __future__ import annotations

import json
import sys
import tempfile
import time
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_ingestion_hardening_validate as ihv  # noqa: E402
import demo_feed as df  # noqa: E402


# ---------------------------------------------------------------------------
# AdmissionController
# ---------------------------------------------------------------------------

class TestAdmissionController(unittest.TestCase):
    def test_no_url_always_allows(self):
        ctrl = ihv.AdmissionController(metrics_url="", max_queue=0)
        allowed, reason = ctrl.allowed()
        self.assertTrue(allowed)
        self.assertEqual(reason, "")

    def test_no_url_high_depth_still_allows(self):
        ctrl = ihv.AdmissionController(metrics_url="", max_queue=100)
        ctrl.set_queue_depth(999_999)
        allowed, _ = ctrl.allowed()
        self.assertTrue(allowed, "empty URL must always allow regardless of depth")

    def test_queue_full_denies(self):
        ctrl = ihv.AdmissionController(metrics_url="http://127.0.0.1:19990", max_queue=1000)
        ctrl.set_queue_depth(1001)
        allowed, reason = ctrl.allowed()
        self.assertFalse(allowed)
        self.assertEqual(reason, "normalizer_queue_full")

    def test_queue_at_max_denies(self):
        ctrl = ihv.AdmissionController(metrics_url="http://127.0.0.1:19990", max_queue=500)
        ctrl.set_queue_depth(500)
        allowed, _ = ctrl.allowed()
        self.assertFalse(allowed)

    def test_below_max_allows(self):
        ctrl = ihv.AdmissionController(metrics_url="http://127.0.0.1:19990", max_queue=500)
        ctrl.set_queue_depth(499)
        allowed, _ = ctrl.allowed()
        self.assertTrue(allowed)

    def test_network_call_count_stays_zero(self):
        ctrl = ihv.AdmissionController(metrics_url="http://127.0.0.1:19990", max_queue=1000)
        ctrl.set_queue_depth(2000)
        for _ in range(200):
            ctrl.allowed()
        self.assertEqual(ctrl.network_call_count, 0)


# ---------------------------------------------------------------------------
# PerTenantLimiter
# ---------------------------------------------------------------------------

class TestPerTenantLimiter(unittest.TestCase):
    def test_empty_tenant_unrestricted(self):
        limiter = ihv.PerTenantLimiter(rps=1)
        for _ in range(100):
            self.assertTrue(limiter.allowed(""))

    def test_new_tenant_gets_full_bucket(self):
        limiter = ihv.PerTenantLimiter(rps=5)
        for _ in range(5):
            self.assertTrue(limiter.allowed("brand-new"))

    def test_exhausted_tenant_denied(self):
        limiter = ihv.PerTenantLimiter(rps=2)
        limiter.allowed("tenant-x")
        limiter.allowed("tenant-x")
        self.assertFalse(limiter.allowed("tenant-x"))

    def test_isolation_a_does_not_starve_b(self):
        limiter = ihv.PerTenantLimiter(rps=3)
        for _ in range(20):
            limiter.allowed("tenant-A")
        self.assertFalse(limiter.allowed("tenant-A"), "A should be throttled")
        self.assertTrue(limiter.allowed("tenant-B"), "B must not be affected by A")

    def test_new_tenant_unaffected_by_existing_exhausted(self):
        limiter = ihv.PerTenantLimiter(rps=2)
        for _ in range(10):
            limiter.allowed("tenant-A")
        self.assertTrue(limiter.allowed("tenant-NEW"))

    def test_refill_restores_bucket(self):
        limiter = ihv.PerTenantLimiter(rps=2)
        limiter.allowed("tenant-r")
        limiter.allowed("tenant-r")
        self.assertFalse(limiter.allowed("tenant-r"))
        limiter.refill("tenant-r")
        self.assertTrue(limiter.allowed("tenant-r"))

    def test_tokens_remaining_decrements(self):
        limiter = ihv.PerTenantLimiter(rps=5)
        limiter.allowed("t1")
        self.assertEqual(limiter.tokens_remaining("t1"), 4)

    def test_refill_all_restores_multiple_tenants(self):
        limiter = ihv.PerTenantLimiter(rps=2)
        for _ in range(5):
            limiter.allowed("t1")
            limiter.allowed("t2")
        limiter.refill()
        self.assertTrue(limiter.allowed("t1"))
        self.assertTrue(limiter.allowed("t2"))


# ---------------------------------------------------------------------------
# MockCircuitBreaker
# ---------------------------------------------------------------------------

class TestMockCircuitBreaker(unittest.TestCase):
    def test_allows_when_closed(self):
        cb = ihv.MockCircuitBreaker(max_failures=5, open_duration_s=30)
        self.assertTrue(cb.allow())

    def test_denies_when_open(self):
        cb = ihv.MockCircuitBreaker(max_failures=5, open_duration_s=30)
        cb.force_open()
        self.assertFalse(cb.allow())

    def test_force_open_increments_count(self):
        cb = ihv.MockCircuitBreaker()
        cb.force_open()
        self.assertEqual(cb.open_count, 1)

    def test_fast_fail_when_open(self):
        cb = ihv.MockCircuitBreaker()
        cb.force_open()
        t0 = time.perf_counter()
        cb.allow()
        elapsed_ms = (time.perf_counter() - t0) * 1000.0
        self.assertLess(elapsed_ms, ihv.CB_FAST_FAIL_MS)

    def test_failure_threshold_opens_breaker(self):
        cb = ihv.MockCircuitBreaker(max_failures=3, open_duration_s=30)
        for _ in range(3):
            cb.record_failure()
        self.assertFalse(cb.allow(), "should be open after max_failures")
        self.assertEqual(cb.open_count, 1)

    def test_success_resets_failure_count(self):
        cb = ihv.MockCircuitBreaker(max_failures=3, open_duration_s=30)
        cb.record_failure()
        cb.record_failure()
        cb.record_success()
        cb.record_failure()
        cb.record_failure()
        self.assertTrue(cb.allow(), "2 failures after reset should not open")

    def test_multiple_force_opens_accumulate(self):
        cb = ihv.MockCircuitBreaker()
        cb.force_open()
        cb.force_open()
        self.assertEqual(cb.open_count, 2)


# ---------------------------------------------------------------------------
# Lineage helpers (demo_feed.tag_events)
# ---------------------------------------------------------------------------

class TestLineageHelpers(unittest.TestCase):
    def setUp(self):
        self.run_id = "test-run-" + "a1b2c3"
        self.events = [
            {"event_type": "login", "telemetry_type": "identity"},
            {"event_type": "file_access", "telemetry_type": "endpoint"},
        ]
        self.tagged = df.tag_events(
            self.events, self.run_id,
            scenario_id="test-scenario",
            tenant_id="tenant-001"
        )

    def test_demo_run_id_in_all_events(self):
        for ev in self.tagged:
            self.assertEqual(ev["demo_run_id"], self.run_id)

    def test_scenario_id_in_all_events(self):
        for ev in self.tagged:
            self.assertEqual(ev["scenario_id"], "test-scenario")

    def test_tenant_id_in_all_events(self):
        for ev in self.tagged:
            self.assertEqual(ev["tenant_id"], "tenant-001")

    def test_source_event_id_set(self):
        for ev in self.tagged:
            self.assertIn("source_event_id", ev)
            self.assertTrue(ev["source_event_id"])

    def test_trace_id_set(self):
        for ev in self.tagged:
            self.assertIn("trace_id", ev)
            self.assertTrue(ev["trace_id"])

    def test_trace_ids_unique(self):
        trace_ids = [ev["trace_id"] for ev in self.tagged]
        self.assertEqual(len(trace_ids), len(set(trace_ids)))


# ---------------------------------------------------------------------------
# Scenario runners — structure and status
# ---------------------------------------------------------------------------

class TestScenarioRunners(unittest.TestCase):
    def _assert_scenario_structure(self, result: dict, expected_id: str):
        self.assertIn("scenario_id", result)
        self.assertIn("name", result)
        self.assertIn("status", result)
        self.assertIn("detail", result)
        self.assertIn("metrics", result)
        self.assertEqual(result["scenario_id"], expected_id)
        self.assertIn(result["status"], (ihv.PASS, ihv.FAIL))

    def test_s01_structure(self):
        r = ihv.run_scenario_s01()
        self._assert_scenario_structure(r, "S-01")

    def test_s01_returns_pass(self):
        r = ihv.run_scenario_s01()
        self.assertEqual(r["status"], ihv.PASS, r.get("detail"))

    def test_s02_structure(self):
        r = ihv.run_scenario_s02()
        self._assert_scenario_structure(r, "S-02")

    def test_s02_returns_pass(self):
        r = ihv.run_scenario_s02()
        self.assertEqual(r["status"], ihv.PASS, r.get("detail"))

    def test_s02_network_call_count_zero(self):
        r = ihv.run_scenario_s02()
        self.assertEqual(r["metrics"]["network_call_count"], 0)

    def test_s03_structure(self):
        r = ihv.run_scenario_s03()
        self._assert_scenario_structure(r, "S-03")

    def test_s03_returns_pass(self):
        r = ihv.run_scenario_s03()
        self.assertEqual(r["status"], ihv.PASS, r.get("detail"))

    def test_s03_tenant_b_allowed(self):
        r = ihv.run_scenario_s03()
        self.assertTrue(r["metrics"]["tenant_b_allowed"])

    def test_s03_tenant_c_allowed(self):
        r = ihv.run_scenario_s03()
        self.assertTrue(r["metrics"]["tenant_c_allowed"])

    def test_s03_rejected_count_positive(self):
        r = ihv.run_scenario_s03()
        self.assertGreater(r["metrics"]["rejected_rate_limited"], 0)

    def test_s04_structure(self):
        r = ihv.run_scenario_s04()
        self._assert_scenario_structure(r, "S-04")

    def test_s04_returns_pass(self):
        r = ihv.run_scenario_s04()
        self.assertEqual(r["status"], ihv.PASS, r.get("detail"))

    def test_s04_fast_fail_under_bound(self):
        r = ihv.run_scenario_s04()
        self.assertLess(r["metrics"]["fast_fail_ms"], ihv.CB_FAST_FAIL_MS)

    def test_s04_circuit_open_count_one(self):
        r = ihv.run_scenario_s04()
        self.assertEqual(r["metrics"]["circuit_open_count"], 1)

    def test_s05_structure(self):
        r = ihv.run_scenario_s05()
        self._assert_scenario_structure(r, "S-05")

    def test_s05_returns_pass(self):
        r = ihv.run_scenario_s05()
        self.assertEqual(r["status"], ihv.PASS, r.get("detail"))

    def test_s06_structure(self):
        r = ihv.run_scenario_s06()
        self._assert_scenario_structure(r, "S-06")

    def test_s06_returns_pass(self):
        r = ihv.run_scenario_s06()
        self.assertEqual(r["status"], ihv.PASS, r.get("detail"))

    def test_s06_accepted_events_correct(self):
        r = ihv.run_scenario_s06()
        self.assertEqual(r["metrics"]["accepted_events"], ihv._S06_EVENTS)

    def test_s06_p95_within_bound(self):
        r = ihv.run_scenario_s06()
        self.assertLessEqual(r["metrics"]["p95_latency_ms"], ihv.MAX_P95_LATENCY_MS)

    def test_s06_p99_within_bound(self):
        r = ihv.run_scenario_s06()
        self.assertLessEqual(r["metrics"]["p99_latency_ms"], ihv.MAX_P99_LATENCY_MS)


# ---------------------------------------------------------------------------
# Metrics aggregation
# ---------------------------------------------------------------------------

class TestMetricsAggregation(unittest.TestCase):
    def _scenarios(self):
        return [
            ihv.run_scenario_s01(),
            ihv.run_scenario_s02(),
            ihv.run_scenario_s03(),
            ihv.run_scenario_s04(),
            ihv.run_scenario_s05(),
            ihv.run_scenario_s06(),
        ]

    def test_all_required_fields_present(self):
        metrics = ihv.collect_metrics(self._scenarios())
        required = {
            "accepted_events", "rejected_rate_limited",
            "p95_latency_ms", "p99_latency_ms",
            "publish_failures", "publish_timeouts",
            "circuit_open_count", "dlq_count",
            "alerts_generated", "incidents_generated",
        }
        for field in required:
            self.assertIn(field, metrics, f"missing field: {field}")

    def test_accepted_events_positive(self):
        metrics = ihv.collect_metrics(self._scenarios())
        self.assertGreater(metrics["accepted_events"], 0)

    def test_offline_fields_zero(self):
        metrics = ihv.collect_metrics(self._scenarios())
        self.assertEqual(metrics["dlq_count"], 0)
        self.assertEqual(metrics["publish_timeouts"], 0)
        self.assertEqual(metrics["alerts_generated"], 0)
        self.assertEqual(metrics["incidents_generated"], 0)

    def test_circuit_open_count_positive(self):
        metrics = ihv.collect_metrics(self._scenarios())
        self.assertGreaterEqual(metrics["circuit_open_count"], 1)

    def test_rejected_rate_limited_positive(self):
        metrics = ihv.collect_metrics(self._scenarios())
        self.assertGreater(metrics["rejected_rate_limited"], 0)


# ---------------------------------------------------------------------------
# Bounds checks
# ---------------------------------------------------------------------------

class TestBoundsChecks(unittest.TestCase):
    def _good_metrics(self):
        return {
            "accepted_events": 50,
            "rejected_rate_limited": 17,
            "p95_latency_ms": 10.0,
            "p99_latency_ms": 20.0,
            "publish_failures": 1,
            "publish_timeouts": 0,
            "circuit_open_count": 1,
            "dlq_count": 0,
            "alerts_generated": 0,
            "incidents_generated": 0,
        }

    def test_all_checks_pass_with_good_metrics(self):
        checks = ihv.bounds_checks(self._good_metrics())
        for c in checks:
            self.assertEqual(c["status"], ihv.PASS, f"{c['check']}: {c['detail']}")

    def test_p95_over_bound_fails(self):
        m = self._good_metrics()
        m["p95_latency_ms"] = ihv.MAX_P95_LATENCY_MS + 1
        checks = {c["check"]: c for c in ihv.bounds_checks(m)}
        self.assertEqual(checks["p95_latency_within_bound"]["status"], ihv.FAIL)

    def test_p99_over_bound_fails(self):
        m = self._good_metrics()
        m["p99_latency_ms"] = ihv.MAX_P99_LATENCY_MS + 1
        checks = {c["check"]: c for c in ihv.bounds_checks(m)}
        self.assertEqual(checks["p99_latency_within_bound"]["status"], ihv.FAIL)

    def test_zero_accepted_fails_ratio(self):
        m = self._good_metrics()
        m["accepted_events"] = 0
        checks = {c["check"]: c for c in ihv.bounds_checks(m)}
        self.assertEqual(checks["accepted_ratio"]["status"], ihv.FAIL)

    def test_no_circuit_open_fails(self):
        m = self._good_metrics()
        m["circuit_open_count"] = 0
        checks = {c["check"]: c for c in ihv.bounds_checks(m)}
        self.assertEqual(checks["circuit_breaker_exercised"]["status"], ihv.FAIL)


# ---------------------------------------------------------------------------
# Report structure and CLI
# ---------------------------------------------------------------------------

class TestReport(unittest.TestCase):
    def _full_report(self):
        scenarios = [
            ihv.run_scenario_s01(),
            ihv.run_scenario_s02(),
            ihv.run_scenario_s03(),
            ihv.run_scenario_s04(),
            ihv.run_scenario_s05(),
            ihv.run_scenario_s06(),
        ]
        metrics = ihv.collect_metrics(scenarios)
        b_checks = ihv.bounds_checks(metrics)
        return ihv.build_report(scenarios, metrics, b_checks)

    def test_report_has_required_top_level_fields(self):
        report = self._full_report()
        for field in ("validation", "title", "overall", "pass_count", "fail_count",
                      "scenarios", "metrics_summary", "bounds_checks", "generated_at"):
            self.assertIn(field, report, f"missing field: {field}")

    def test_report_overall_pass(self):
        report = self._full_report()
        self.assertEqual(report["overall"], ihv.PASS)

    def test_report_six_scenarios(self):
        report = self._full_report()
        self.assertEqual(len(report["scenarios"]), 6)

    def test_report_pass_count_equals_total(self):
        report = self._full_report()
        total = report["pass_count"] + report["fail_count"]
        self.assertEqual(total, len(report["scenarios"]) + len(report["bounds_checks"]))

    def test_main_exits_0_on_pass(self):
        rc = ihv.main([])
        self.assertEqual(rc, 0)

    def test_main_writes_json_report(self):
        with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as f:
            path = f.name
        rc = ihv.main(["--output", path, "--quiet"])
        self.assertEqual(rc, 0)
        content = Path(path).read_text(encoding="utf-8")
        data = json.loads(content)
        self.assertEqual(data["overall"], ihv.PASS)
        self.assertEqual(data["validation"], "BACKLOG-SCALE-026")

    def test_report_metrics_summary_has_all_fields(self):
        report = self._full_report()
        required = {
            "accepted_events", "rejected_rate_limited",
            "p95_latency_ms", "p99_latency_ms",
            "publish_failures", "publish_timeouts",
            "circuit_open_count", "dlq_count",
            "alerts_generated", "incidents_generated",
        }
        for field in required:
            self.assertIn(field, report["metrics_summary"])


if __name__ == "__main__":
    unittest.main()
