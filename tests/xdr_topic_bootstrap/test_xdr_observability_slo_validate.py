"""Tests for BACKLOG-OBS-029: Runtime Observability & SLO Readiness.

Covers:
- evaluate_threshold: lower-is-better pass/warn/fail; fail=None; warn=None;
  both-none; zero thresholds (circuit breaker production)
- evaluate_threshold_higher_is_better: posture score pass/warn/fail
- compute_rate: normal, zero denominator, zero numerator
- check_obs_readiness: all present, missing file, WARN not FAIL
- check_ingestion_metrics: p95 warn, publish fail, rejection warn, CB fail
- check_normalizer_metrics: malformed warn, publish fail, dlq warn
- check_correlation_metrics: p95 warn, p95 fail, publish errors
- check_alert_writer_metrics: dlq writes, dlq errors, p95 latency
- check_incident_builder_metrics: dlq writes, errors
- check_dlq_metrics: error rate warn, pending records fail
- check_easm_metrics: advisory true, score warn, score fail, high score pass
- run_slo_checks: missing service → WARN; all pass; mixed; easm advisory
- summarize: overall PASS; WARN present; FAIL present; has_metrics field
- main(): no-input stdout; with-input file pass; with-input bad values FAIL;
  missing input file → exit 2
"""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_observability_slo_validate as obs  # noqa: E402


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _good_ig():
    return {
        "accepted": 1000, "rejected": 5, "rate_limited": 2,
        "publish_errors": 0, "p95_latency_ms": 45.0,
        "p99_latency_ms": 90.0, "circuit_breaker_opens": 0,
    }


def _good_nw():
    return {
        "processed": 995, "forwarded": 990, "malformed": 2,
        "publish_errors": 0, "dlq_writes": 0,
        "consumer_errors": 0, "reconnect_count": 0,
        "goroutines": 12, "heap_alloc_mb": 45.0,
    }


def _good_cw():
    return {
        "processed": 990, "alerts": 45, "published": 45,
        "publish_errors": 0, "p95_latency_ms": 78.0,
        "consumer_errors": 0, "reconnect_count": 0,
        "goroutines": 14, "heap_alloc_mb": 62.0,
    }


def _good_aw():
    return {
        "processed": 45, "written": 45, "dlq_writes": 0,
        "dlq_errors": 0, "p95_latency_ms": 120.0, "errors": 0,
    }


def _good_ib():
    return {"processed": 45, "created": 44, "dlq_writes": 0, "dlq_errors": 0, "errors": 0}


def _good_dlq():
    return {
        "total_records": 3, "pending_records": 1,
        "replayable_records": 3, "failed_records": 0,
        "dlq_error_rate_pct": 0.0,
    }


def _good_easm():
    return {
        "total_assets": 3, "open_findings_high": 0, "open_findings_medium": 1,
        "min_posture_score": 90, "avg_posture_score": 92, "is_advisory": True,
    }


def _good_metrics():
    return {
        "ingestion_gateway": _good_ig(),
        "normalizer_worker": _good_nw(),
        "correlation_worker": _good_cw(),
        "alert_writer": _good_aw(),
        "incident_builder": _good_ib(),
        "dlq": _good_dlq(),
        "easm": _good_easm(),
    }


# ---------------------------------------------------------------------------
# evaluate_threshold (lower is better)
# ---------------------------------------------------------------------------


class TestEvaluateThreshold(unittest.TestCase):
    def test_pass_below_warn(self):
        self.assertEqual("PASS", obs.evaluate_threshold(10, 100, 200))

    def test_warn_above_warn_below_fail(self):
        self.assertEqual("WARN", obs.evaluate_threshold(150, 100, 200))

    def test_fail_above_fail(self):
        self.assertEqual("FAIL", obs.evaluate_threshold(250, 100, 200))

    def test_fail_none_only_warn_possible(self):
        self.assertEqual("WARN", obs.evaluate_threshold(150, 100, None))

    def test_warn_none_only_fail_possible(self):
        # warn=None, fail=200: no WARN, only FAIL
        self.assertEqual("FAIL", obs.evaluate_threshold(250, None, 200))

    def test_both_none_always_pass(self):
        self.assertEqual("PASS", obs.evaluate_threshold(9999, None, None))

    def test_zero_fail_threshold_any_positive_fails(self):
        # circuit breaker production: warn=0, fail=0
        self.assertEqual("FAIL", obs.evaluate_threshold(1, 0, 0))

    def test_zero_thresholds_value_zero_passes(self):
        self.assertEqual("PASS", obs.evaluate_threshold(0, 0, 0))

    def test_warn_boundary_exact_value_passes(self):
        # value == warn_threshold: 10 > 10 is False → PASS
        self.assertEqual("PASS", obs.evaluate_threshold(10, 10, 20))

    def test_fail_boundary_exact_value_warns(self):
        # value == fail_threshold (20): 20 > 20 is False so no FAIL,
        # but 20 > warn_threshold (10) → WARN
        self.assertEqual("WARN", obs.evaluate_threshold(20, 10, 20))


# ---------------------------------------------------------------------------
# evaluate_threshold_higher_is_better (posture score)
# ---------------------------------------------------------------------------


class TestEvaluateThresholdHigherIsBetter(unittest.TestCase):
    def test_pass_above_both_thresholds(self):
        self.assertEqual("PASS", obs.evaluate_threshold_higher_is_better(90, 60, 40))

    def test_warn_below_warn_above_fail(self):
        self.assertEqual("WARN", obs.evaluate_threshold_higher_is_better(55, 60, 40))

    def test_fail_below_fail(self):
        self.assertEqual("FAIL", obs.evaluate_threshold_higher_is_better(30, 60, 40))

    def test_fail_none_only_warn(self):
        self.assertEqual("WARN", obs.evaluate_threshold_higher_is_better(55, 60, None))

    def test_warn_none_score_above_fail_passes(self):
        self.assertEqual("PASS", obs.evaluate_threshold_higher_is_better(50, None, 40))


# ---------------------------------------------------------------------------
# compute_rate
# ---------------------------------------------------------------------------


class TestComputeRate(unittest.TestCase):
    def test_normal_rate(self):
        self.assertAlmostEqual(10.0, obs.compute_rate(10, 100), places=1)

    def test_zero_denominator_returns_zero(self):
        self.assertEqual(0.0, obs.compute_rate(5, 0))

    def test_zero_numerator_returns_zero(self):
        self.assertEqual(0.0, obs.compute_rate(0, 100))

    def test_rate_100_pct(self):
        self.assertAlmostEqual(100.0, obs.compute_rate(100, 100), places=1)


# ---------------------------------------------------------------------------
# check_obs_readiness
# ---------------------------------------------------------------------------


class TestCheckObsReadiness(unittest.TestCase):
    def test_all_missing_returns_warns(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            # Empty directory — all files missing
            results = obs.check_obs_readiness(Path(tmpdir))
        self.assertEqual(8, len(results))
        statuses = {r["check_id"]: r["status"] for r in results}
        for check_id in ("OBS-01", "OBS-02", "OBS-03", "OBS-04",
                         "OBS-05", "OBS-06", "OBS-07", "OBS-08"):
            self.assertEqual("WARN", statuses[check_id],
                             f"{check_id} should be WARN when file is missing")

    def test_present_file_returns_pass(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            root = Path(tmpdir)
            # Create OBS-02 (scripts/xdr_posture_check.py)
            (root / "scripts").mkdir(parents=True)
            (root / "scripts" / "xdr_posture_check.py").write_text("# stub")
            results = obs.check_obs_readiness(root)
        statuses = {r["check_id"]: r["status"] for r in results}
        self.assertEqual("PASS", statuses["OBS-02"])

    def test_missing_file_is_not_fail(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            results = obs.check_obs_readiness(Path(tmpdir))
        for r in results:
            self.assertNotEqual("FAIL", r["status"],
                                f"{r['check_id']} missing file should be WARN, not FAIL")

    def test_returns_8_checks(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            results = obs.check_obs_readiness(Path(tmpdir))
        self.assertEqual(8, len(results))

    def test_all_present_all_pass(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            root = Path(tmpdir)
            for rel_path in obs._PATHS.values():
                full = root / rel_path
                full.parent.mkdir(parents=True, exist_ok=True)
                full.write_text("# stub")
            results = obs.check_obs_readiness(root)
        for r in results:
            self.assertEqual("PASS", r["status"])


# ---------------------------------------------------------------------------
# check_ingestion_metrics
# ---------------------------------------------------------------------------


class TestCheckIngestionMetrics(unittest.TestCase):
    def test_all_pass_local(self):
        checks = obs.check_ingestion_metrics(_good_ig(), "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        for sid in ("SLO-01", "SLO-02", "SLO-03", "SLO-04"):
            self.assertEqual("PASS", statuses[sid])

    def test_p95_latency_warn_local(self):
        ig = _good_ig()
        ig["p95_latency_ms"] = 250  # > local warn threshold of 200
        checks = obs.check_ingestion_metrics(ig, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-01"])

    def test_p95_latency_fail_staging(self):
        ig = _good_ig()
        ig["p95_latency_ms"] = 350  # > staging fail threshold of 300
        checks = obs.check_ingestion_metrics(ig, "staging")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-01"])

    def test_publish_errors_fail_local(self):
        ig = _good_ig()
        ig["publish_errors"] = 25  # > local fail threshold of 20
        checks = obs.check_ingestion_metrics(ig, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-02"])

    def test_rejection_rate_warn_local(self):
        # 10% rejection rate > local warn of 5.0%
        ig = _good_ig()
        ig["accepted"] = 90
        ig["rejected"] = 10
        checks = obs.check_ingestion_metrics(ig, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-03"])

    def test_circuit_breaker_fail_production(self):
        ig = _good_ig()
        ig["circuit_breaker_opens"] = 1  # > production fail threshold of 0
        checks = obs.check_ingestion_metrics(ig, "production")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-04"])

    def test_circuit_breaker_zero_passes_production(self):
        ig = _good_ig()
        ig["circuit_breaker_opens"] = 0
        checks = obs.check_ingestion_metrics(ig, "production")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("PASS", statuses["SLO-04"])


# ---------------------------------------------------------------------------
# check_normalizer_metrics
# ---------------------------------------------------------------------------


class TestCheckNormalizerMetrics(unittest.TestCase):
    def test_all_pass_local(self):
        checks = obs.check_normalizer_metrics(_good_nw(), "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        for sid in ("SLO-05", "SLO-06", "SLO-07"):
            self.assertEqual("PASS", statuses[sid])

    def test_malformed_rate_warn_local(self):
        nw = _good_nw()
        nw["processed"] = 100
        nw["malformed"] = 5   # 5% > local warn 2.0%
        checks = obs.check_normalizer_metrics(nw, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-05"])

    def test_malformed_rate_fail_staging(self):
        nw = _good_nw()
        nw["processed"] = 100
        nw["malformed"] = 8   # 8% > staging fail 5.0%
        checks = obs.check_normalizer_metrics(nw, "staging")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-05"])

    def test_publish_errors_warn(self):
        nw = _good_nw()
        nw["publish_errors"] = 1  # > local warn 0
        checks = obs.check_normalizer_metrics(nw, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-06"])

    def test_dlq_writes_warn(self):
        nw = _good_nw()
        nw["dlq_writes"] = 1  # > local warn 0
        checks = obs.check_normalizer_metrics(nw, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-07"])


# ---------------------------------------------------------------------------
# check_correlation_metrics
# ---------------------------------------------------------------------------


class TestCheckCorrelationMetrics(unittest.TestCase):
    def test_all_pass_local(self):
        checks = obs.check_correlation_metrics(_good_cw(), "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        for sid in ("SLO-08", "SLO-09"):
            self.assertEqual("PASS", statuses[sid])

    def test_p95_warn_local(self):
        cw = _good_cw()
        cw["p95_latency_ms"] = 350  # > local warn 300
        checks = obs.check_correlation_metrics(cw, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-08"])

    def test_p95_fail_staging(self):
        cw = _good_cw()
        cw["p95_latency_ms"] = 600  # > staging fail 500
        checks = obs.check_correlation_metrics(cw, "staging")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-08"])

    def test_publish_errors_warn(self):
        cw = _good_cw()
        cw["publish_errors"] = 1
        checks = obs.check_correlation_metrics(cw, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-09"])


# ---------------------------------------------------------------------------
# check_alert_writer_metrics
# ---------------------------------------------------------------------------


class TestCheckAlertWriterMetrics(unittest.TestCase):
    def test_all_pass_local(self):
        checks = obs.check_alert_writer_metrics(_good_aw(), "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        for sid in ("SLO-10", "SLO-11", "SLO-12"):
            self.assertEqual("PASS", statuses[sid])

    def test_dlq_writes_warn(self):
        aw = _good_aw()
        aw["dlq_writes"] = 1
        checks = obs.check_alert_writer_metrics(aw, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-11"])

    def test_dlq_errors_fail_production(self):
        aw = _good_aw()
        aw["dlq_errors"] = 1  # > production fail threshold of 0
        checks = obs.check_alert_writer_metrics(aw, "production")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-12"])

    def test_p95_warn_staging(self):
        aw = _good_aw()
        aw["p95_latency_ms"] = 500  # > staging warn 300
        checks = obs.check_alert_writer_metrics(aw, "staging")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-10"])


# ---------------------------------------------------------------------------
# check_incident_builder_metrics
# ---------------------------------------------------------------------------


class TestCheckIncidentBuilderMetrics(unittest.TestCase):
    def test_all_pass_local(self):
        checks = obs.check_incident_builder_metrics(_good_ib(), "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("PASS", statuses["SLO-13"])
        self.assertEqual("PASS", statuses["SLO-14"])

    def test_dlq_writes_warn(self):
        ib = _good_ib()
        ib["dlq_writes"] = 1
        checks = obs.check_incident_builder_metrics(ib, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-13"])

    def test_errors_fail_production(self):
        ib = _good_ib()
        ib["errors"] = 1  # > production fail 0
        checks = obs.check_incident_builder_metrics(ib, "production")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-14"])


# ---------------------------------------------------------------------------
# check_dlq_metrics
# ---------------------------------------------------------------------------


class TestCheckDlqMetrics(unittest.TestCase):
    def test_all_pass_local(self):
        checks = obs.check_dlq_metrics(_good_dlq(), "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("PASS", statuses["SLO-15"])
        self.assertEqual("PASS", statuses["SLO-16"])

    def test_error_rate_warn_staging(self):
        dlq = _good_dlq()
        dlq["dlq_error_rate_pct"] = 1.0  # > staging warn 0.5%
        checks = obs.check_dlq_metrics(dlq, "staging")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-15"])

    def test_error_rate_fail_staging(self):
        dlq = _good_dlq()
        dlq["dlq_error_rate_pct"] = 3.0  # > staging fail 2.0%
        checks = obs.check_dlq_metrics(dlq, "staging")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-15"])

    def test_pending_records_fail_production(self):
        dlq = _good_dlq()
        dlq["pending_records"] = 150  # > production fail 100
        checks = obs.check_dlq_metrics(dlq, "production")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("FAIL", statuses["SLO-16"])


# ---------------------------------------------------------------------------
# check_easm_metrics (advisory-only)
# ---------------------------------------------------------------------------


class TestCheckEasmMetrics(unittest.TestCase):
    def test_advisory_note_in_detail(self):
        checks = obs.check_easm_metrics(_good_easm(), "local")
        self.assertIn("advisory", checks[0]["detail"].lower())

    def test_high_score_passes_all_profiles(self):
        easm = _good_easm()
        easm["min_posture_score"] = 95
        for profile in ("local", "staging", "production"):
            checks = obs.check_easm_metrics(easm, profile)
            self.assertEqual("PASS", checks[0]["status"],
                             f"Expected PASS for profile={profile} score=95")

    def test_low_score_warn_local(self):
        easm = _good_easm()
        easm["min_posture_score"] = 35  # < local warn 40
        checks = obs.check_easm_metrics(easm, "local")
        self.assertEqual("WARN", checks[0]["status"])

    def test_very_low_score_fail_production(self):
        easm = _good_easm()
        easm["min_posture_score"] = 50  # < production fail 60
        checks = obs.check_easm_metrics(easm, "production")
        self.assertEqual("FAIL", checks[0]["status"])

    def test_easm_remediation_includes_advisory(self):
        easm = _good_easm()
        easm["min_posture_score"] = 20
        checks = obs.check_easm_metrics(easm, "production")
        self.assertIn("advisory", checks[0]["remediation"].lower())


# ---------------------------------------------------------------------------
# run_slo_checks
# ---------------------------------------------------------------------------


class TestRunSloChecks(unittest.TestCase):
    def test_empty_metrics_all_warn(self):
        checks = obs.run_slo_checks({}, "local")
        self.assertEqual(17, len(checks))
        statuses = {c["check_id"]: c["status"] for c in checks}
        for sid in [f"SLO-{i:02d}" for i in range(1, 18)]:
            self.assertEqual("WARN", statuses[sid],
                             f"Expected WARN for {sid} when metrics missing")

    def test_all_good_metrics_all_pass(self):
        checks = obs.run_slo_checks(_good_metrics(), "local")
        for c in checks:
            self.assertEqual("PASS", c["status"],
                             f"{c['check_id']} should PASS with good metrics")

    def test_mixed_metrics_partial_pass(self):
        m = _good_metrics()
        m["ingestion_gateway"]["p95_latency_ms"] = 250  # warn local
        m["alert_writer"]["dlq_errors"] = 10  # fail local
        checks = obs.run_slo_checks(m, "local")
        statuses = {c["check_id"]: c["status"] for c in checks}
        self.assertEqual("WARN", statuses["SLO-01"])
        self.assertEqual("FAIL", statuses["SLO-12"])

    def test_returns_17_checks(self):
        checks = obs.run_slo_checks(_good_metrics(), "production")
        self.assertEqual(17, len(checks))

    def test_slo17_easm_is_advisory(self):
        checks = obs.run_slo_checks(_good_metrics(), "local")
        slo17 = next(c for c in checks if c["check_id"] == "SLO-17")
        self.assertIn("advisory", slo17["detail"].lower())


# ---------------------------------------------------------------------------
# summarize
# ---------------------------------------------------------------------------


class TestSummarize(unittest.TestCase):
    def _obs_pass(self):
        return [{"check_id": f"OBS-0{i}", "name": f"OBS {i}", "status": "PASS",
                 "detail": "", "remediation": ""}
                for i in range(1, 9)]

    def test_overall_pass_when_no_failures(self):
        slo_checks = [{"check_id": "SLO-01", "name": "x", "status": "PASS",
                       "detail": "", "remediation": ""}]
        report = obs.summarize(self._obs_pass(), slo_checks, "local", True)
        self.assertEqual("PASS", report["overall"])

    def test_overall_warn_when_warn_present(self):
        slo_checks = [{"check_id": "SLO-01", "name": "x", "status": "WARN",
                       "detail": "", "remediation": ""}]
        report = obs.summarize(self._obs_pass(), slo_checks, "local", True)
        self.assertEqual("WARN", report["overall"])

    def test_overall_fail_when_fail_present(self):
        slo_checks = [{"check_id": "SLO-01", "name": "x", "status": "FAIL",
                       "detail": "", "remediation": ""}]
        report = obs.summarize(self._obs_pass(), slo_checks, "local", True)
        self.assertEqual("FAIL", report["overall"])

    def test_has_metrics_input_recorded(self):
        report = obs.summarize(self._obs_pass(), [], "staging", False)
        self.assertFalse(report["has_metrics_input"])

    def test_report_structure_keys(self):
        report = obs.summarize(self._obs_pass(), [], "production", True)
        for key in ("profile", "overall", "has_metrics_input", "pass_count",
                    "warn_count", "fail_count", "info_count",
                    "obs_checks", "slo_checks", "generated_at"):
            self.assertIn(key, report)


# ---------------------------------------------------------------------------
# main() CLI
# ---------------------------------------------------------------------------


class TestMainCli(unittest.TestCase):
    def _make_metrics_file(self, tmpdir: str, metrics: dict) -> str:
        path = f"{tmpdir}/metrics.json"
        Path(path).write_text(json.dumps(metrics))
        return path

    def test_main_no_input_exits_0_or_1(self):
        # Without --input, SLO checks WARN (not FAIL), OBS checks depend on
        # whether files exist. Exit 0 or 1 both acceptable; not 2.
        with tempfile.TemporaryDirectory() as tmpdir:
            rc = obs.main(["--profile", "local",
                           "--repo-root", tmpdir,
                           "--quiet"])
        self.assertIn(rc, (0, 1))

    def test_main_with_good_input_writes_output(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            mf = self._make_metrics_file(tmpdir, _good_metrics())
            outfile = f"{tmpdir}/report.json"
            rc = obs.main([
                "--profile", "local",
                "--input", mf,
                "--output", outfile,
                "--repo-root", tmpdir,
                "--quiet",
            ])
            self.assertIn(rc, (0, 1))
            data = json.loads(Path(outfile).read_text())
            self.assertTrue(data["has_metrics_input"])
            self.assertEqual(17, len(data["slo_checks"]))

    def test_main_with_bad_metrics_returns_nonzero(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            bad_metrics = _good_metrics()
            bad_metrics["ingestion_gateway"]["publish_errors"] = 999  # > local fail 20
            bad_metrics["alert_writer"]["dlq_errors"] = 100  # > local fail 5
            mf = self._make_metrics_file(tmpdir, bad_metrics)
            rc = obs.main([
                "--profile", "local",
                "--input", mf,
                "--repo-root", tmpdir,
                "--quiet",
            ])
            self.assertEqual(1, rc)

    def test_main_missing_input_file_returns_2(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            rc = obs.main([
                "--profile", "local",
                "--input", "/nonexistent/path/metrics.json",
                "--repo-root", tmpdir,
                "--quiet",
            ])
            self.assertEqual(2, rc)

    def test_main_all_files_present_obs_pass(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            root = Path(tmpdir)
            for rel_path in obs._PATHS.values():
                full = root / rel_path
                full.parent.mkdir(parents=True, exist_ok=True)
                full.write_text("# stub")
            mf = self._make_metrics_file(tmpdir, _good_metrics())
            outfile = f"{tmpdir}/report.json"
            obs.main([
                "--profile", "local",
                "--input", mf,
                "--output", outfile,
                "--repo-root", tmpdir,
                "--quiet",
            ])
            data = json.loads(Path(outfile).read_text())
            obs_statuses = {c["check_id"]: c["status"] for c in data["obs_checks"]}
            for check_id in obs_statuses:
                self.assertEqual("PASS", obs_statuses[check_id])


if __name__ == "__main__":
    unittest.main()
