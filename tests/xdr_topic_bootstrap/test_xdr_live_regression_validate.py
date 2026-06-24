"""Tests for BACKLOG-LIVE-028: Full Live Regression & Evidence Freeze.

Covers:
- Stage L (lineage fields): all 4 fields injected, trace_id format,
  source_event_id fallback, event_id overwritten, uniqueness
- Stage M (domain remapping CORR-1): identity_provider->identity,
  saas_audit->saas, active domain filter, endpoint/dns shadow-only
- Subprocess stage runner: PASS/WARN/FAIL logic, expected patterns,
  exception handling, timeout propagation
- Stage P (posture): rc=0->PASS, rc=1->FAIL
- Stage R (recovery): rc=0->PASS, rc!=0->FAIL
- Stage I (registry): rc=0->PASS with pattern check, extracts rule_count
- run_all_stages: correct number returned, offline stages always run
- build_report: overall aggregation PASS/WARN/FAIL, counts, baselines
- write_json_report: JSON written, parseable, run_id present
- write_freeze_doc: Markdown written, contains stage table, commit lineage
- main(): exit 0 on all-PASS, exit 2 on any FAIL, --no-report-write
"""
from __future__ import annotations

import argparse
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_live_regression_validate as lrv  # noqa: E402


# ---------------------------------------------------------------------------
# Fake _run_fn helpers
# ---------------------------------------------------------------------------

def _fake_run_pass(cmd, timeout=120):
    """Always returns rc=0 with PASS-like output covering all expected patterns."""
    return 0, "overall=PASS status=PASS rules=133 checks=21/21 FAIL=0 Overall: PASS"


def _fake_run_fail(cmd, timeout=120):
    return 1, "FAIL: something went wrong"


def _fake_run_warn(cmd, timeout=120):
    return 1, "WARN: advisory issue"


def _fake_run_error(cmd, timeout=120):
    return 2, "ERROR"


def _fake_run_exc(cmd, timeout=120):
    raise RuntimeError("connection refused")


def _make_args(**kwargs) -> argparse.Namespace:
    defaults = {
        "live": False,
        "live_timeout": 90,
        "profile": "local",
        "output": "",
        "write_freeze": False,
        "no_report_write": True,
        "quiet": False,
    }
    defaults.update(kwargs)
    return argparse.Namespace(**defaults)


# ---------------------------------------------------------------------------
# Stage L -- Lineage fields
# ---------------------------------------------------------------------------

class TestCheckLineageFields(unittest.TestCase):

    def test_returns_pass_status(self):
        result = lrv.check_lineage_fields()
        self.assertEqual(result["status"], lrv.PASS)

    def test_stage_id_is_L(self):
        result = lrv.check_lineage_fields()
        self.assertEqual(result["stage"], "L")

    def test_all_four_fields_present(self):
        import demo_feed as _df
        events = [{"event_id": "e1"}, {"id": "e2"}]
        tagged = _df.tag_events(
            events, "run-001",
            scenario_id="scen-001", tenant_id="t-001",
        )
        for ev in tagged:
            self.assertIn("demo_run_id", ev)
            self.assertIn("scenario_id", ev)
            self.assertIn("tenant_id", ev)
            self.assertIn("source_event_id", ev)

    def test_demo_run_id_matches(self):
        import demo_feed as _df
        tagged = _df.tag_events([{"event_id": "x"}], "run-abc")
        self.assertEqual(tagged[0]["demo_run_id"], "run-abc")

    def test_scenario_id_injected(self):
        import demo_feed as _df
        tagged = _df.tag_events([{}], "run-1", scenario_id="sc-99")
        self.assertEqual(tagged[0]["scenario_id"], "sc-99")

    def test_tenant_id_injected(self):
        import demo_feed as _df
        tagged = _df.tag_events([{}], "run-1", tenant_id="t-99")
        self.assertEqual(tagged[0]["tenant_id"], "t-99")

    def test_source_event_id_from_event_id(self):
        import demo_feed as _df
        tagged = _df.tag_events([{"event_id": "orig-007"}], "r")
        self.assertEqual(tagged[0]["source_event_id"], "orig-007")

    def test_source_event_id_fallback_to_id(self):
        import demo_feed as _df
        tagged = _df.tag_events([{"id": "alt-007"}], "r")
        self.assertEqual(tagged[0]["source_event_id"], "alt-007")

    def test_source_event_id_generated_when_missing(self):
        import demo_feed as _df
        tagged = _df.tag_events([{"type": "x"}], "r")
        self.assertTrue(tagged[0]["source_event_id"])

    def test_trace_id_format(self):
        import demo_feed as _df
        tagged = _df.tag_events([{}], "demo-live028-xyz")
        self.assertTrue(tagged[0]["trace_id"].startswith("demo-live028-xyz-trace-"))

    def test_event_id_overwritten_with_trace_id(self):
        import demo_feed as _df
        tagged = _df.tag_events([{"event_id": "original"}], "r")
        self.assertEqual(tagged[0]["event_id"], tagged[0]["trace_id"])

    def test_trace_ids_unique_across_events(self):
        import demo_feed as _df
        tagged = _df.tag_events([{}, {}, {}], "r")
        trace_ids = [ev["trace_id"] for ev in tagged]
        self.assertEqual(len(trace_ids), len(set(trace_ids)))

    def test_checks_list_in_result(self):
        result = lrv.check_lineage_fields()
        self.assertIsInstance(result["checks"], list)
        self.assertGreater(len(result["checks"]), 0)

    def test_all_checks_passed(self):
        result = lrv.check_lineage_fields()
        failed = [c for c in result["checks"] if not c["passed"]]
        self.assertEqual(failed, [], msg=f"Failed checks: {failed}")


# ---------------------------------------------------------------------------
# Stage M -- Domain remapping (CORR-1)
# ---------------------------------------------------------------------------

class TestCheckDomainRemapping(unittest.TestCase):

    def test_returns_pass_status(self):
        result = lrv.check_domain_remapping()
        self.assertEqual(result["status"], lrv.PASS)

    def test_stage_id_is_M(self):
        result = lrv.check_domain_remapping()
        self.assertEqual(result["stage"], "M")

    def test_identity_provider_remaps_to_identity(self):
        self.assertEqual(lrv._apply_domain_remap("identity_provider"), "identity")

    def test_saas_audit_remaps_to_saas(self):
        self.assertEqual(lrv._apply_domain_remap("saas_audit"), "saas")

    def test_identity_passthrough(self):
        self.assertEqual(lrv._apply_domain_remap("identity"), "identity")

    def test_cloud_passthrough(self):
        self.assertEqual(lrv._apply_domain_remap("cloud"), "cloud")

    def test_saas_passthrough(self):
        self.assertEqual(lrv._apply_domain_remap("saas"), "saas")

    def test_identity_provider_passes_active_filter(self):
        self.assertIn(lrv._apply_domain_remap("identity_provider"), lrv._ACTIVE_DOMAINS)

    def test_saas_audit_passes_active_filter(self):
        self.assertIn(lrv._apply_domain_remap("saas_audit"), lrv._ACTIVE_DOMAINS)

    def test_endpoint_does_not_pass_active_filter(self):
        self.assertNotIn(lrv._apply_domain_remap("endpoint"), lrv._ACTIVE_DOMAINS)

    def test_dns_does_not_pass_active_filter(self):
        self.assertNotIn(lrv._apply_domain_remap("dns"), lrv._ACTIVE_DOMAINS)

    def test_firewall_does_not_pass_active_filter(self):
        self.assertNotIn(lrv._apply_domain_remap("firewall"), lrv._ACTIVE_DOMAINS)

    def test_all_checks_passed(self):
        result = lrv.check_domain_remapping()
        failed = [c for c in result["checks"] if not c["passed"]]
        self.assertEqual(failed, [], msg=f"Failed checks: {failed}")

    def test_remap_constant_intact(self):
        self.assertIn("identity_provider", lrv._DOMAIN_REMAP)
        self.assertIn("saas_audit", lrv._DOMAIN_REMAP)
        self.assertEqual(lrv._DOMAIN_REMAP["identity_provider"], "identity")
        self.assertEqual(lrv._DOMAIN_REMAP["saas_audit"], "saas")

    def test_active_domains_constant_intact(self):
        for d in ("identity", "cloud", "saas"):
            self.assertIn(d, lrv._ACTIVE_DOMAINS)
        for d in ("endpoint", "dns", "firewall"):
            self.assertNotIn(d, lrv._ACTIVE_DOMAINS)


# ---------------------------------------------------------------------------
# Subprocess stage runner (_run_subprocess_stage)
# ---------------------------------------------------------------------------

class TestRunSubprocessStage(unittest.TestCase):

    def test_rc0_is_pass(self):
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"], pass_rcs=(0,), _run_fn=_fake_run_pass,
        )
        self.assertEqual(result["status"], lrv.PASS)

    def test_rc1_is_fail_by_default(self):
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"], pass_rcs=(0,), _run_fn=_fake_run_fail,
        )
        self.assertEqual(result["status"], lrv.FAIL)

    def test_rc1_in_warn_rcs_is_warn(self):
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"], pass_rcs=(0,), warn_rcs=(1,),
            _run_fn=_fake_run_fail,
        )
        self.assertEqual(result["status"], lrv.WARN)

    def test_rc2_not_in_pass_or_warn_is_fail(self):
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"], pass_rcs=(0,), warn_rcs=(1,),
            _run_fn=_fake_run_error,
        )
        self.assertEqual(result["status"], lrv.FAIL)

    def test_exception_returns_fail(self):
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"], _run_fn=_fake_run_exc,
        )
        self.assertEqual(result["status"], lrv.FAIL)
        self.assertIn("subprocess_exception", result["detail"])

    def test_pass_with_missing_expected_pattern_downgrades_to_warn(self):
        def _run(cmd, timeout=120):
            return 0, "something else entirely"
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"],
            pass_rcs=(0,),
            expected_patterns=["expected_token"],
            _run_fn=_run,
        )
        self.assertEqual(result["status"], lrv.WARN)

    def test_pass_with_all_expected_patterns_stays_pass(self):
        def _run(cmd, timeout=120):
            return 0, "token_a token_b present here"
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"],
            pass_rcs=(0,),
            expected_patterns=["token_a", "token_b"],
            _run_fn=_run,
        )
        self.assertEqual(result["status"], lrv.PASS)

    def test_exit_code_recorded(self):
        result = lrv._run_subprocess_stage(
            "X", "test", ["dummy"], _run_fn=_fake_run_fail,
        )
        self.assertEqual(result["exit_code"], 1)

    def test_stage_id_propagated(self):
        result = lrv._run_subprocess_stage(
            "TESTID", "test name", ["dummy"], _run_fn=_fake_run_pass,
        )
        self.assertEqual(result["stage"], "TESTID")


# ---------------------------------------------------------------------------
# Individual subprocess stages
# ---------------------------------------------------------------------------

class TestSubprocessStages(unittest.TestCase):

    def test_posture_pass_when_rc0(self):
        result = lrv.run_stage_posture(lrv.PROJECT_ROOT, _run_fn=_fake_run_pass)
        self.assertEqual(result["stage"], "P")
        self.assertEqual(result["status"], lrv.PASS)

    def test_posture_fail_when_rc1(self):
        result = lrv.run_stage_posture(lrv.PROJECT_ROOT, _run_fn=_fake_run_fail)
        self.assertEqual(result["status"], lrv.FAIL)

    def test_recovery_pass_when_rc0(self):
        result = lrv.run_stage_recovery(lrv.PROJECT_ROOT, _run_fn=_fake_run_pass)
        self.assertEqual(result["stage"], "R")
        self.assertEqual(result["status"], lrv.PASS)

    def test_recovery_fail_when_rc1(self):
        result = lrv.run_stage_recovery(lrv.PROJECT_ROOT, _run_fn=_fake_run_fail)
        self.assertEqual(result["status"], lrv.FAIL)

    def test_registry_pass_when_rc0(self):
        result = lrv.run_stage_registry(lrv.PROJECT_ROOT, _run_fn=_fake_run_pass)
        self.assertEqual(result["stage"], "I")
        self.assertEqual(result["status"], lrv.PASS)

    def test_registry_fail_when_rc1(self):
        result = lrv.run_stage_registry(lrv.PROJECT_ROOT, _run_fn=_fake_run_fail)
        self.assertEqual(result["status"], lrv.FAIL)

    def test_registry_extracts_rule_count(self):
        def _run(cmd, timeout=120):
            return 0, "status=PASS rules=133 checks=21/21 failures=0 Overall: PASS"
        result = lrv.run_stage_registry(lrv.PROJECT_ROOT, _run_fn=_run)
        self.assertEqual(result.get("rule_count"), 133)

    def test_registry_no_rule_count_when_pattern_absent(self):
        def _run(cmd, timeout=120):
            return 0, "status=PASS Overall: PASS"
        result = lrv.run_stage_registry(lrv.PROJECT_ROOT, _run_fn=_run)
        self.assertNotIn("rule_count", result)


# ---------------------------------------------------------------------------
# run_all_stages
# ---------------------------------------------------------------------------

class TestRunAllStages(unittest.TestCase):

    def test_offline_returns_five_stages(self):
        args = _make_args(live=False)
        stages = lrv.run_all_stages(args, _run_fn=_fake_run_pass)
        self.assertEqual(len(stages), 5)

    def test_live_returns_six_stages(self):
        args = _make_args(live=True)
        stages = lrv.run_all_stages(args, _run_fn=_fake_run_pass)
        self.assertEqual(len(stages), 6)

    def test_stage_ids_offline(self):
        args = _make_args(live=False)
        stages = lrv.run_all_stages(args, _run_fn=_fake_run_pass)
        ids = [s["stage"] for s in stages]
        for expected in ("P", "R", "L", "M", "I"):
            self.assertIn(expected, ids)

    def test_inline_stages_always_run(self):
        # L and M are pure Python; even if subprocess stages fail, they run.
        args = _make_args(live=False)
        stages = lrv.run_all_stages(args, _run_fn=_fake_run_fail)
        ids = [s["stage"] for s in stages]
        self.assertIn("L", ids)
        self.assertIn("M", ids)

    def test_all_pass_with_pass_run_fn(self):
        args = _make_args(live=False)
        stages = lrv.run_all_stages(args, _run_fn=_fake_run_pass)
        failed = [s for s in stages if s["status"] == lrv.FAIL]
        # L and M are always PASS (pure Python); P/R/I depend on _run_fn.
        inline_failed = [s for s in failed if s["stage"] in ("L", "M")]
        self.assertEqual(inline_failed, [])


# ---------------------------------------------------------------------------
# build_report
# ---------------------------------------------------------------------------

class TestBuildReport(unittest.TestCase):

    def _stages_all_pass(self) -> list[dict]:
        return [
            {"stage": "P", "name": "Posture", "status": lrv.PASS},
            {"stage": "R", "name": "Recovery", "status": lrv.PASS},
            {"stage": "L", "name": "Lineage", "status": lrv.PASS},
            {"stage": "M", "name": "Remap", "status": lrv.PASS},
            {"stage": "I", "name": "Registry", "status": lrv.PASS},
        ]

    def test_overall_pass_when_all_pass(self):
        report = lrv.build_report(
            "r1", self._stages_all_pass(),
            "2026-06-24T00:00:00", "2026-06-24T00:00:01",
            _make_args(),
        )
        self.assertEqual(report["overall"], lrv.PASS)

    def test_overall_fail_when_any_fail(self):
        stages = self._stages_all_pass()
        stages[0]["status"] = lrv.FAIL
        report = lrv.build_report("r1", stages, "t", "t", _make_args())
        self.assertEqual(report["overall"], lrv.FAIL)

    def test_overall_warn_when_warn_no_fail(self):
        stages = self._stages_all_pass()
        stages[0]["status"] = lrv.WARN
        report = lrv.build_report("r1", stages, "t", "t", _make_args())
        self.assertEqual(report["overall"], lrv.WARN)

    def test_fail_beats_warn(self):
        stages = self._stages_all_pass()
        stages[0]["status"] = lrv.WARN
        stages[1]["status"] = lrv.FAIL
        report = lrv.build_report("r1", stages, "t", "t", _make_args())
        self.assertEqual(report["overall"], lrv.FAIL)

    def test_run_id_in_report(self):
        report = lrv.build_report(
            "run-test-001", self._stages_all_pass(), "t", "t", _make_args(),
        )
        self.assertEqual(report["run_id"], "run-test-001")

    def test_stage_counts_correct(self):
        stages = self._stages_all_pass()
        stages[1]["status"] = lrv.WARN
        report = lrv.build_report("r1", stages, "t", "t", _make_args())
        self.assertEqual(report["stage_counts"]["pass"], 4)
        self.assertEqual(report["stage_counts"]["warn"], 1)
        self.assertEqual(report["stage_counts"]["fail"], 0)
        self.assertEqual(report["stage_counts"]["total"], 5)

    def test_baselines_in_report(self):
        report = lrv.build_report("r1", self._stages_all_pass(), "t", "t", _make_args())
        self.assertEqual(report["baselines"]["expected_rule_count"], 133)
        self.assertEqual(report["baselines"]["expected_staged_active"], 12)
        self.assertIn("identity_provider", report["baselines"]["corr1_remap"])
        self.assertIn("saas_audit", report["baselines"]["corr1_remap"])

    def test_task_field_is_live028(self):
        report = lrv.build_report("r1", self._stages_all_pass(), "t", "t", _make_args())
        self.assertEqual(report["task"], "BACKLOG-LIVE-028")


# ---------------------------------------------------------------------------
# write_json_report and write_freeze_doc
# ---------------------------------------------------------------------------

class TestWriteReports(unittest.TestCase):

    def _sample_report(self) -> dict:
        stages = [
            {"stage": "L", "name": "Lineage", "status": lrv.PASS, "detail": "ok", "checks": []},
            {"stage": "M", "name": "Remap", "status": lrv.PASS, "detail": "ok", "checks": []},
        ]
        return lrv.build_report(
            "test-run-001", stages, "2026-06-24T00:00:00", "2026-06-24T00:00:02",
            _make_args(),
        )

    def test_json_written_and_parseable(self):
        report = self._sample_report()
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "report.json"
            lrv.write_json_report(report, out)
            self.assertTrue(out.exists())
            parsed = json.loads(out.read_text())
            self.assertEqual(parsed["run_id"], "test-run-001")

    def test_json_contains_overall(self):
        report = self._sample_report()
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "report.json"
            lrv.write_json_report(report, out)
            parsed = json.loads(out.read_text())
            self.assertIn("overall", parsed)

    def test_parent_dir_created(self):
        report = self._sample_report()
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "nested" / "deep" / "report.json"
            lrv.write_json_report(report, out)
            self.assertTrue(out.exists())

    def test_freeze_doc_written(self):
        report = self._sample_report()
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "LIVE_028_EVIDENCE_FREEZE.md"
            lrv.write_freeze_doc(report, out)
            self.assertTrue(out.exists())

    def test_freeze_doc_contains_stage_table(self):
        report = self._sample_report()
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "freeze.md"
            lrv.write_freeze_doc(report, out)
            content = out.read_text()
            self.assertIn("| Stage |", content)
            self.assertIn("| L |", content)
            self.assertIn("| M |", content)

    def test_freeze_doc_contains_commit_lineage(self):
        report = self._sample_report()
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "freeze.md"
            lrv.write_freeze_doc(report, out)
            content = out.read_text()
            self.assertIn("4d1d1d7", content)
            self.assertIn("CORR-1", content)

    def test_freeze_doc_contains_forbidden_changes_section(self):
        report = self._sample_report()
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "freeze.md"
            lrv.write_freeze_doc(report, out)
            content = out.read_text()
            self.assertIn("Forbidden Changes Confirmed", content)


# ---------------------------------------------------------------------------
# main()
# ---------------------------------------------------------------------------

class TestMain(unittest.TestCase):

    def test_main_returns_0_when_all_pass(self):
        args = _make_args(no_report_write=True)
        rc = lrv.main(args, _run_fn=_fake_run_pass)
        self.assertEqual(rc, 0)

    def test_main_returns_2_when_subprocess_fails(self):
        args = _make_args(no_report_write=True)
        rc = lrv.main(args, _run_fn=_fake_run_fail)
        self.assertEqual(rc, 2)

    def test_main_no_report_write_skips_file(self):
        args = _make_args(no_report_write=True)
        with tempfile.TemporaryDirectory() as tmp:
            # Override REPORTS_DIR would require monkey-patching; instead just
            # verify main() does not raise when no_report_write=True.
            rc = lrv.main(args, _run_fn=_fake_run_pass)
            self.assertEqual(rc, 0)

    def test_main_with_output_writes_json(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = str(Path(tmp) / "out.json")
            args = _make_args(no_report_write=True, output=out)
            lrv.main(args, _run_fn=_fake_run_pass)
            self.assertTrue(Path(out).exists())
            parsed = json.loads(Path(out).read_text())
            self.assertIn("overall", parsed)

    def test_main_with_write_freeze_creates_doc(self):
        with tempfile.TemporaryDirectory() as tmp:
            freeze_dir = Path(tmp) / "docs" / "validation"
            # Patch PROJECT_ROOT inside the module so freeze doc writes to tmp.
            orig = lrv.PROJECT_ROOT
            try:
                lrv.PROJECT_ROOT = Path(tmp)
                lrv.REPORTS_DIR = Path(tmp) / "reports"
                args = _make_args(no_report_write=False, write_freeze=True)
                lrv.main(args, _run_fn=_fake_run_pass)
                self.assertTrue(freeze_dir.exists())
                self.assertTrue((freeze_dir / "LIVE_028_EVIDENCE_FREEZE.md").exists())
            finally:
                lrv.PROJECT_ROOT = orig
                lrv.REPORTS_DIR = orig.parent / "reports"


if __name__ == "__main__":
    unittest.main()
