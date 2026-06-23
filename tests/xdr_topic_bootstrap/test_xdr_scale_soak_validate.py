"""Tests for BACKLOG-SCALE-026: Controlled Load and Soak Validation.

Covers:
- Tier profile configuration validation (bounds consistency)
- Metrics evaluation against tier bounds
- Hard cap enforcement (MAX_REPLAY_AMP, MAX_EPS, MAX_ENDPOINTS)
- Dry-run mode (no metrics checks)
- Synthetic metrics generation
- Report structure
- CLI exit codes
"""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_scale_soak_validate as sv  # noqa: E402


class TestTierProfiles(unittest.TestCase):
    def test_all_three_profiles_defined(self):
        for p in ("small", "medium", "large"):
            self.assertIn(p, sv.TIER_PROFILES)

    def test_large_profile_at_hard_cap(self):
        cfg = sv.TIER_PROFILES["large"]
        self.assertEqual(cfg["endpoints"], sv.MAX_ENDPOINTS)
        self.assertEqual(cfg["eps"], sv.MAX_EPS)
        self.assertEqual(cfg["max_replay_amp"], sv.MAX_REPLAY_AMP)

    def test_small_profile_below_hard_cap(self):
        cfg = sv.TIER_PROFILES["small"]
        self.assertLess(cfg["endpoints"], sv.MAX_ENDPOINTS)
        self.assertLess(cfg["eps"], sv.MAX_EPS)
        self.assertLess(cfg["max_replay_amp"], sv.MAX_REPLAY_AMP)

    def test_medium_profile_between_small_and_large(self):
        s = sv.TIER_PROFILES["small"]
        m = sv.TIER_PROFILES["medium"]
        l = sv.TIER_PROFILES["large"]
        self.assertGreater(m["eps"], s["eps"])
        self.assertLess(m["eps"], l["eps"])


class TestValidateTierConfig(unittest.TestCase):
    def test_all_profiles_pass_config_check(self):
        for profile in sv.TIER_PROFILES:
            with self.subTest(profile=profile):
                results = sv.validate_tier_config(profile)
                for r in results:
                    self.assertEqual(r["status"], sv.PASS, msg=f"{profile}: {r}")

    def test_unknown_profile_returns_fail(self):
        results = sv.validate_tier_config("xlarge")
        self.assertTrue(any(r["status"] == sv.FAIL for r in results))

    def test_constants_are_consistent(self):
        self.assertGreater(sv.MAX_REPLAY_AMP, 0)
        self.assertGreater(sv.MAX_EPS, 0)
        self.assertGreater(sv.MAX_ENDPOINTS, 0)


class TestEvaluateMetrics(unittest.TestCase):
    def _passing_metrics(self, profile: str) -> dict:
        return sv.generate_synthetic_metrics(sv.TIER_PROFILES[profile])

    def test_synthetic_metrics_pass_all_profiles(self):
        for profile in sv.TIER_PROFILES:
            with self.subTest(profile=profile):
                cfg = sv.TIER_PROFILES[profile]
                metrics = sv.generate_synthetic_metrics(cfg)
                results = sv.evaluate_metrics(cfg, metrics)
                for r in results:
                    self.assertEqual(r["status"], sv.PASS, msg=f"{profile}: {r}")

    def test_low_throughput_fails(self):
        cfg = sv.TIER_PROFILES["small"]
        metrics = self._passing_metrics("small")
        metrics["observed_eps"] = cfg["eps"] * 0.50  # below 90% threshold
        results = sv.evaluate_metrics(cfg, metrics)
        statuses = {r["check"]: r["status"] for r in results}
        self.assertEqual(statuses["throughput_eps"], sv.FAIL)

    def test_high_latency_fails(self):
        cfg = sv.TIER_PROFILES["small"]
        metrics = self._passing_metrics("small")
        metrics["p95_latency_ms"] = cfg["max_p95_latency_ms"] + 50
        results = sv.evaluate_metrics(cfg, metrics)
        statuses = {r["check"]: r["status"] for r in results}
        self.assertEqual(statuses["p95_latency"], sv.FAIL)

    def test_high_replay_amp_fails(self):
        cfg = sv.TIER_PROFILES["medium"]
        metrics = self._passing_metrics("medium")
        metrics["replay_amplification"] = 2.5  # above medium's 2.0 cap
        results = sv.evaluate_metrics(cfg, metrics)
        statuses = {r["check"]: r["status"] for r in results}
        self.assertEqual(statuses["replay_amplification"], sv.FAIL)

    def test_replay_amp_above_global_cap_fails(self):
        cfg = sv.TIER_PROFILES["large"]
        metrics = self._passing_metrics("large")
        metrics["replay_amplification"] = sv.MAX_REPLAY_AMP + 0.1  # above global hard cap
        results = sv.evaluate_metrics(cfg, metrics)
        statuses = {r["check"]: r["status"] for r in results}
        self.assertEqual(statuses["replay_amp_global_cap"], sv.FAIL)

    def test_alert_delta_over_tolerance_fails(self):
        cfg = sv.TIER_PROFILES["small"]
        metrics = self._passing_metrics("small")
        metrics["alert_delta_pct"] = sv.MAX_ALERT_DELTA_PCT + 0.5
        results = sv.evaluate_metrics(cfg, metrics)
        statuses = {r["check"]: r["status"] for r in results}
        self.assertEqual(statuses["alert_count_delta"], sv.FAIL)

    def test_high_error_rate_fails(self):
        cfg = sv.TIER_PROFILES["small"]
        metrics = self._passing_metrics("small")
        metrics["error_rate_pct"] = 2.0  # above 1.0% threshold
        results = sv.evaluate_metrics(cfg, metrics)
        statuses = {r["check"]: r["status"] for r in results}
        self.assertEqual(statuses["publish_error_rate"], sv.FAIL)


class TestDryRunMode(unittest.TestCase):
    def test_dry_run_no_metrics_checks(self):
        tier_checks = sv.validate_tier_config("small")
        report = sv.build_report("small", tier_checks, [], {}, dry_run=True)
        self.assertTrue(report["dry_run"])
        self.assertEqual(len(report["metrics_checks"]), 0)

    def test_dry_run_passes_all_profiles(self):
        for profile in sv.TIER_PROFILES:
            with self.subTest(profile=profile):
                result = sv.main(["--profile", profile, "--dry-run", "--quiet"])
                self.assertEqual(result, 0)


class TestCLIExitCodes(unittest.TestCase):
    def test_dry_run_exits_0(self):
        self.assertEqual(sv.main(["--profile", "small", "--dry-run", "--quiet"]), 0)

    def test_synthetic_run_exits_0(self):
        self.assertEqual(sv.main(["--profile", "small", "--quiet"]), 0)

    def test_unknown_profile_exits_2(self):
        # argparse raises SystemExit(2) for invalid choices before main() returns
        try:
            result = sv.main(["--profile", "xlarge", "--quiet"])
        except SystemExit as exc:
            result = exc.code
        self.assertEqual(result, 2)

    def test_bad_metrics_file_exits_2(self):
        import io
        import contextlib
        buf = io.StringIO()
        with contextlib.redirect_stderr(buf):
            result = sv.main(["--profile", "small", "--metrics-file",
                              "/nonexistent/metrics.json", "--quiet"])
        self.assertEqual(result, 2)

    def test_failing_metrics_exits_1(self):
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".json", delete=False, encoding="utf-8"
        ) as f:
            json.dump({"observed_eps": 1, "p95_latency_ms": 999, "replay_amplification": 0,
                       "alert_delta_pct": 0, "error_rate_pct": 0}, f)
            tmp = f.name
        result = sv.main(["--profile", "small", "--metrics-file", tmp, "--quiet"])
        self.assertEqual(result, 1)

    def test_output_writes_json(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            out = Path(tmpdir) / "scale_report.json"
            sv.main(["--profile", "medium", "--dry-run", "--output", str(out), "--quiet"])
            self.assertTrue(out.exists())
            report = json.loads(out.read_text())
            self.assertEqual(report["profile"], "medium")
            self.assertIn("tier_bounds", report)
            self.assertIn("overall", report)


class TestReportStructure(unittest.TestCase):
    def test_report_contains_required_fields(self):
        tier_checks = sv.validate_tier_config("large")
        cfg = sv.TIER_PROFILES["large"]
        metrics = sv.generate_synthetic_metrics(cfg)
        metric_checks = sv.evaluate_metrics(cfg, metrics)
        report = sv.build_report("large", tier_checks, metric_checks, metrics, dry_run=False)
        for field in ("profile", "dry_run", "overall", "pass_count", "fail_count",
                      "tier_bounds", "observed_metrics", "tier_config_checks",
                      "metrics_checks", "generated_at"):
            self.assertIn(field, report)

    def test_passing_report_overall_pass(self):
        tier_checks = sv.validate_tier_config("small")
        cfg = sv.TIER_PROFILES["small"]
        metrics = sv.generate_synthetic_metrics(cfg)
        metric_checks = sv.evaluate_metrics(cfg, metrics)
        report = sv.build_report("small", tier_checks, metric_checks, metrics, dry_run=False)
        self.assertEqual(report["overall"], sv.PASS)
        self.assertEqual(report["fail_count"], 0)


if __name__ == "__main__":
    unittest.main()
