"""Tests for BACKLOG-EASM-031: EASM Posture History & Risk Trend.

Covers:
- advisory_only: constant, no active methods
- compute_posture_score: empty, each severity, mixed, floor zero, unknown
- classify_risk_tier: all 4 tiers, boundaries
- determine_trend_direction: stable null, improving, degrading, stable equal
- diff_findings: new, resolved, unchanged, regressed, empty both, mixed
- build_trend_report: structure, is_advisory, limit respected, no snapshots
- build_finding_change_summary: all types, empty
- main() CLI: no-input stdout, with-input file, output file written
"""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_easm_posture_history as hist  # noqa: E402


# ---------------------------------------------------------------------------
# Advisory-only (4 tests)
# ---------------------------------------------------------------------------


class TestAdvisoryOnly(unittest.TestCase):
    def test_advisory_only_constant(self):
        self.assertTrue(hist.ADVISORY_ONLY)

    def test_no_active_scan(self):
        self.assertFalse(hasattr(hist, "active_scan"))

    def test_no_exploit_payload(self):
        self.assertFalse(hasattr(hist, "inject_exploit"))

    def test_no_create_incident(self):
        self.assertFalse(hasattr(hist, "create_security_incident"))


# ---------------------------------------------------------------------------
# compute_posture_score (8 tests)
# ---------------------------------------------------------------------------


class TestComputePostureScore(unittest.TestCase):
    def test_empty_findings_is_100(self):
        self.assertEqual(100, hist.compute_posture_score([]))

    def test_single_high_deducts_20(self):
        self.assertEqual(80, hist.compute_posture_score([{"severity": "high"}]))

    def test_single_medium_deducts_10(self):
        self.assertEqual(90, hist.compute_posture_score([{"severity": "medium"}]))

    def test_single_low_deducts_3(self):
        self.assertEqual(97, hist.compute_posture_score([{"severity": "low"}]))

    def test_single_info_deducts_1(self):
        self.assertEqual(99, hist.compute_posture_score([{"severity": "info"}]))

    def test_mixed_findings(self):
        findings = [
            {"severity": "high"},
            {"severity": "medium"},
            {"severity": "low"},
            {"severity": "info"},
        ]
        self.assertEqual(66, hist.compute_posture_score(findings))

    def test_floor_is_zero(self):
        findings = [{"severity": "high"}] * 10
        self.assertEqual(0, hist.compute_posture_score(findings))

    def test_unknown_severity_no_deduction(self):
        self.assertEqual(100, hist.compute_posture_score([{"severity": "unknown"}]))


# ---------------------------------------------------------------------------
# classify_risk_tier (5 tests)
# ---------------------------------------------------------------------------


class TestClassifyRiskTier(unittest.TestCase):
    def test_100_is_low(self):
        self.assertEqual("low", hist.classify_risk_tier(100))

    def test_80_is_low(self):
        self.assertEqual("low", hist.classify_risk_tier(80))

    def test_79_is_medium(self):
        self.assertEqual("medium", hist.classify_risk_tier(79))

    def test_40_is_high(self):
        self.assertEqual("high", hist.classify_risk_tier(40))

    def test_0_is_critical(self):
        self.assertEqual("critical", hist.classify_risk_tier(0))


# ---------------------------------------------------------------------------
# determine_trend_direction (4 tests)
# ---------------------------------------------------------------------------


class TestDetermineTrendDirection(unittest.TestCase):
    def test_stable_when_no_previous(self):
        self.assertEqual("stable", hist.determine_trend_direction(80, None))

    def test_improving_when_increased(self):
        self.assertEqual("improving", hist.determine_trend_direction(90, 70))

    def test_degrading_when_decreased(self):
        self.assertEqual("degrading", hist.determine_trend_direction(60, 80))

    def test_stable_when_equal(self):
        self.assertEqual("stable", hist.determine_trend_direction(75, 75))


# ---------------------------------------------------------------------------
# diff_findings (8 tests)
# ---------------------------------------------------------------------------


class TestDiffFindings(unittest.TestCase):
    def test_new_finding_not_in_previous(self):
        diffs = hist.diff_findings([], [{"finding_key": "tls_expired", "severity": "high"}])
        self.assertEqual(1, len(diffs))
        self.assertEqual("new", diffs[0]["change_type"])
        self.assertIsNone(diffs[0]["severity_before"])
        self.assertEqual("high", diffs[0]["severity_after"])

    def test_resolved_finding_not_in_current(self):
        diffs = hist.diff_findings([{"finding_key": "tls_expired", "severity": "high"}], [])
        self.assertEqual(1, len(diffs))
        self.assertEqual("resolved", diffs[0]["change_type"])
        self.assertEqual("high", diffs[0]["severity_before"])
        self.assertIsNone(diffs[0]["severity_after"])

    def test_unchanged_same_severity(self):
        prev = [{"finding_key": "missing_hsts", "severity": "medium"}]
        curr = [{"finding_key": "missing_hsts", "severity": "medium"}]
        diffs = hist.diff_findings(prev, curr)
        self.assertEqual("unchanged", diffs[0]["change_type"])

    def test_regressed_severity_increased(self):
        prev = [{"finding_key": "missing_hsts", "severity": "low"}]
        curr = [{"finding_key": "missing_hsts", "severity": "high"}]
        diffs = hist.diff_findings(prev, curr)
        self.assertEqual("regressed", diffs[0]["change_type"])

    def test_severity_decrease_is_unchanged(self):
        prev = [{"finding_key": "missing_hsts", "severity": "high"}]
        curr = [{"finding_key": "missing_hsts", "severity": "low"}]
        diffs = hist.diff_findings(prev, curr)
        self.assertEqual("unchanged", diffs[0]["change_type"])

    def test_empty_both_returns_empty(self):
        diffs = hist.diff_findings([], [])
        self.assertEqual(0, len(diffs))

    def test_mixed_changes(self):
        prev = [
            {"finding_key": "tls_expired", "severity": "high"},
            {"finding_key": "missing_csp", "severity": "medium"},
        ]
        curr = [
            {"finding_key": "missing_csp", "severity": "medium"},
            {"finding_key": "missing_hsts", "severity": "low"},
        ]
        diffs = hist.diff_findings(prev, curr)
        types = {d["change_type"] for d in diffs}
        self.assertIn("resolved", types)
        self.assertIn("unchanged", types)
        self.assertIn("new", types)
        self.assertEqual(3, len(diffs))

    def test_all_resolved_when_current_empty(self):
        prev = [
            {"finding_key": "a", "severity": "high"},
            {"finding_key": "b", "severity": "medium"},
        ]
        diffs = hist.diff_findings(prev, [])
        self.assertEqual(2, len(diffs))
        self.assertTrue(all(d["change_type"] == "resolved" for d in diffs))


# ---------------------------------------------------------------------------
# build_trend_report (7 tests)
# ---------------------------------------------------------------------------


class TestBuildTrendReport(unittest.TestCase):
    def _make_snapshot(self, score=90, status="PASS"):
        return {
            "posture_score": score,
            "risk_tier": hist.classify_risk_tier(score),
            "finding_count_total": 0,
            "new_findings": 0,
            "resolved_findings": 0,
            "regressed_findings": 0,
            "unchanged_findings": 0,
            "overall_status": status,
            "snapshot_at": "2026-06-24T10:00:00+00:00",
        }

    def test_structure_keys_present(self):
        report = hist.build_trend_report(1, "t1", [], None)
        for key in ("asset_id", "tenant_id", "is_advisory", "current_score",
                    "risk_tier", "trend", "total_scans", "snapshot_count", "snapshots",
                    "generated_at"):
            self.assertIn(key, report)

    def test_is_advisory_true(self):
        report = hist.build_trend_report(1, "t1", [], None)
        self.assertTrue(report["is_advisory"])

    def test_snapshots_populated(self):
        snaps = [self._make_snapshot(90), self._make_snapshot(80)]
        report = hist.build_trend_report(1, "t1", snaps, None)
        self.assertEqual(2, report["snapshot_count"])
        self.assertEqual(2, len(report["snapshots"]))

    def test_limit_respected(self):
        snaps = [self._make_snapshot(90 - i) for i in range(15)]
        report = hist.build_trend_report(1, "t1", snaps, None, limit=10)
        self.assertLessEqual(report["snapshot_count"], 10)

    def test_no_snapshots_empty_array(self):
        report = hist.build_trend_report(1, "t1", [], None)
        self.assertEqual(0, report["snapshot_count"])
        self.assertEqual([], report["snapshots"])

    def test_risk_score_record_propagated(self):
        rs = {"risk_score": 75, "risk_tier": "medium", "trend_direction": "improving", "total_scans": 3}
        report = hist.build_trend_report(1, "t1", [], rs)
        self.assertEqual(75, report["current_score"])
        self.assertEqual("medium", report["risk_tier"])
        self.assertEqual("improving", report["trend"])
        self.assertEqual(3, report["total_scans"])

    def test_tenant_id_in_report(self):
        report = hist.build_trend_report(42, "my-tenant", [], None)
        self.assertEqual("my-tenant", report["tenant_id"])
        self.assertEqual(42, report["asset_id"])


# ---------------------------------------------------------------------------
# build_finding_change_summary (4 tests)
# ---------------------------------------------------------------------------


class TestBuildFindingChangeSummary(unittest.TestCase):
    def test_empty_changes(self):
        s = hist.build_finding_change_summary([])
        self.assertEqual(0, s["total_changes"])
        self.assertTrue(s["is_advisory"])

    def test_counts_all_types(self):
        changes = [
            {"change_type": "new"},
            {"change_type": "resolved"},
            {"change_type": "unchanged"},
            {"change_type": "regressed"},
        ]
        s = hist.build_finding_change_summary(changes)
        self.assertEqual(4, s["total_changes"])
        self.assertEqual(1, s["by_type"]["new"])
        self.assertEqual(1, s["by_type"]["resolved"])
        self.assertEqual(1, s["by_type"]["unchanged"])
        self.assertEqual(1, s["by_type"]["regressed"])

    def test_multiple_same_type(self):
        changes = [{"change_type": "new"}] * 5
        s = hist.build_finding_change_summary(changes)
        self.assertEqual(5, s["by_type"]["new"])

    def test_summary_is_advisory_true(self):
        s = hist.build_finding_change_summary([])
        self.assertTrue(s["is_advisory"])


# ---------------------------------------------------------------------------
# main() CLI (5 tests)
# ---------------------------------------------------------------------------


class TestMainCli(unittest.TestCase):
    def test_main_no_input_prints_to_stdout(self):
        import io
        from unittest.mock import patch
        with patch("sys.stdout", new_callable=io.StringIO) as mock_out:
            rc = hist.main(["--asset-id", "1", "--tenant-id", "t1"])
        self.assertEqual(0, rc)
        output = mock_out.getvalue()
        data = json.loads(output)
        self.assertTrue(data["is_advisory"])

    def test_main_with_output_file(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            outfile = f"{tmpdir}/report.json"
            rc = hist.main(["--asset-id", "1", "--tenant-id", "t1", "--output", outfile])
            self.assertEqual(0, rc)
            self.assertTrue(Path(outfile).exists())
            data = json.loads(Path(outfile).read_text())
            self.assertTrue(data["is_advisory"])

    def test_main_with_input_file(self):
        input_data = {
            "snapshots": [
                {
                    "posture_score": 85,
                    "risk_tier": "low",
                    "finding_count_total": 1,
                    "new_findings": 1,
                    "resolved_findings": 0,
                    "regressed_findings": 0,
                    "unchanged_findings": 0,
                    "overall_status": "FINDINGS",
                    "snapshot_at": "2026-06-24T10:00:00+00:00",
                }
            ],
            "risk_score": {
                "risk_score": 85,
                "risk_tier": "low",
                "trend_direction": "stable",
                "total_scans": 1,
            },
            "changes": [],
        }
        with tempfile.TemporaryDirectory() as tmpdir:
            infile = f"{tmpdir}/input.json"
            outfile = f"{tmpdir}/report.json"
            Path(infile).write_text(json.dumps(input_data))

            rc = hist.main([
                "--asset-id", "1",
                "--tenant-id", "t1",
                "--input", infile,
                "--output", outfile,
            ])
            self.assertEqual(0, rc)
            data = json.loads(Path(outfile).read_text())
            self.assertEqual(1, data["snapshot_count"])
            self.assertEqual(85, data["current_score"])

    def test_main_missing_input_file_returns_1(self):
        rc = hist.main([
            "--asset-id", "1",
            "--tenant-id", "t1",
            "--input", "/nonexistent/path/file.json",
        ])
        self.assertEqual(1, rc)

    def test_main_limit_applied(self):
        import io
        from unittest.mock import patch
        with patch("sys.stdout", new_callable=io.StringIO) as mock_out:
            rc = hist.main(["--asset-id", "1", "--tenant-id", "t1", "--limit", "5"])
        self.assertEqual(0, rc)
        data = json.loads(mock_out.getvalue())
        self.assertIn("snapshots", data)


if __name__ == "__main__":
    unittest.main()
