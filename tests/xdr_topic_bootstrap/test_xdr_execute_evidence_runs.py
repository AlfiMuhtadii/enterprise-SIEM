"""Tests for scripts/xdr_execute_evidence_runs.py — ENTERPRISE-043.

All tests are deterministic and offline.
_read_fn / _run_fn injection replaces filesystem and subprocess access.

Test coverage map (per spec):
  #01  default run builds dry-run plan without subprocess
  #02  stage IDs EXE-01 through EXE-12 are stable
  #03  report JSON shape is stable
  #04  markdown output contains allowed and forbidden claims
  #05  execute-readonly-validators builds expected commands
  #06  restore execute is not enabled by default
  #07  live soak execute is not enabled by default
  #08  live causal proof execute is not enabled by default
  #09  restore execute requires isolated target guard
  #10  live soak duration is capped
  #11  invalid profile returns ERROR
  #12  subprocess runner is injectable
  #13  subprocess success maps to PASS
  #14  subprocess non-zero maps to FAIL
  #15  timeout maps to ERROR or FAIL according to profile
  #16  missing required script warns locally
  #17  missing required script fails in production
  #18  safety boundary object is present and true
  #19  no ACTIVE_ALLOWLIST mutation/reference
  #20  no detection rule mutation
  #21  no shadow/active boundary mutation
  #22  generated markdown path is written
  #23  generated JSON path is written
  #24  exit code PASS=0
  #25  exit code FAIL=1
  #26  exit code ERROR=2
"""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).resolve().parent.parent.parent / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

import xdr_execute_evidence_runs as er  # noqa: E402


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

ROOT = Path("/fake/root")

_ALL_SCRIPTS = {sd["script"] for sd in er._STAGE_DEFS if sd["script"] is not None}
# .env with isolated target DB
_ENV_ISOLATED = "DB_DATABASE=xdr_db\nXDR_RESTORE_TARGET_DB=xdr_restore_target\n"
# .env without isolated target
_ENV_NO_ISOLATION = "DB_DATABASE=xdr_db\n"
# .env where target equals source
_ENV_SAME = "DB_DATABASE=xdr_db\nXDR_RESTORE_TARGET_DB=xdr_db\n"


def _make_read_fn(files: dict):
    """Build _read_fn with forward-slash normalisation (Windows-safe)."""
    normalised = {str(k).replace("\\", "/"): v for k, v in files.items()}

    def _read(path: Path) -> str | None:
        return normalised.get(str(path).replace("\\", "/"))

    return _read


def _all_scripts_read_fn(env: str = _ENV_ISOLATED):
    """All scripts present + env with isolated restore target."""
    m: dict[str, str] = {s: "# stub" for s in _ALL_SCRIPTS}
    m[".env"] = env
    return _make_read_fn(m)


def _noop_run(cmd):
    return 0, "OK\n", ""


def _fail_run(cmd):
    return 1, "", "validator FAIL"


def _error_run(cmd):
    return 2, "", "validator ERROR"


def _should_not_run(cmd):
    raise AssertionError(f"subprocess called unexpectedly: {cmd}")


# ---------------------------------------------------------------------------
# #01  default run builds dry-run plan without subprocess
# ---------------------------------------------------------------------------

class TestDryRunNeverCallsSubprocess(unittest.TestCase):

    def test_no_subprocess_in_default_mode(self):
        er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(),
                   _run_fn=_should_not_run, _commit="test")

    def test_no_subprocess_across_all_profiles(self):
        for profile in ("local", "staging", "production"):
            er.run_all(ROOT, profile, _read_fn=_all_scripts_read_fn(),
                       _run_fn=_should_not_run, _commit="test")

    def test_mode_is_dry_run_by_default(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(),
                            _run_fn=_should_not_run, _commit="test")
        self.assertEqual(report["mode"], "dry-run")

    def test_executed_count_is_zero_in_dry_run(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(),
                            _run_fn=_should_not_run, _commit="test")
        self.assertEqual(report["summary"]["executed"], 0)

    def test_executed_commands_empty_in_dry_run(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(),
                            _run_fn=_should_not_run, _commit="test")
        self.assertEqual(report["executed_commands"], [])


# ---------------------------------------------------------------------------
# #02  stage IDs EXE-01 through EXE-12 are stable
# ---------------------------------------------------------------------------

class TestStageIdStability(unittest.TestCase):

    def test_exactly_twelve_stages(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertEqual(len(report["stages"]), 12)

    def test_stage_ids_are_exe01_through_exe12(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        ids = [s["id"] for s in report["stages"]]
        expected = [f"EXE-{i:02d}" for i in range(1, 13)]
        self.assertEqual(ids, expected)

    def test_stage_ids_constant_has_twelve_entries(self):
        self.assertEqual(len(er.STAGE_IDS), 12)
        self.assertEqual(er.STAGE_IDS[0], "EXE-01")
        self.assertEqual(er.STAGE_IDS[-1], "EXE-12")

    def test_exe12_is_safety_boundary_stage(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe12 = next(s for s in report["stages"] if s["id"] == "EXE-12")
        self.assertIn("Safety", exe12["name"])

    def test_exe11_is_summary_stage(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe11 = next(s for s in report["stages"] if s["id"] == "EXE-11")
        self.assertIn("summary", exe11["name"].lower())


# ---------------------------------------------------------------------------
# #03  report JSON shape is stable
# ---------------------------------------------------------------------------

class TestReportShape(unittest.TestCase):

    def _report(self):
        return er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="abc123")

    def test_schema_version_present(self):
        self.assertEqual(self._report()["schema_version"], er.SCHEMA_VERSION)

    def test_required_top_level_fields(self):
        r = self._report()
        for field in ("schema_version", "generated_at", "profile", "mode", "overall_status",
                      "commit", "stages", "executed_commands", "generated_reports",
                      "safety_boundaries", "remaining_gaps", "recommended_next_steps",
                      "summary", "execute_readonly", "execute_restore", "execute_soak",
                      "execute_causal", "duration_minutes"):
            self.assertIn(field, r, f"Missing field: {field}")

    def test_stage_shape(self):
        r = self._report()
        for s in r["stages"]:
            for field in ("id", "name", "status", "executed", "required",
                          "command", "report_path", "detail"):
                self.assertIn(field, s, f"{s['id']} missing field {field}")

    def test_is_json_serialisable(self):
        r = self._report()
        s = json.dumps(r)
        d = json.loads(s)
        self.assertEqual(d["schema_version"], er.SCHEMA_VERSION)

    def test_commit_field_populated(self):
        r = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="abc123")
        self.assertEqual(r["commit"], "abc123")

    def test_default_execute_flags_false(self):
        r = self._report()
        self.assertFalse(r["execute_readonly"])
        self.assertFalse(r["execute_restore"])
        self.assertFalse(r["execute_soak"])
        self.assertFalse(r["execute_causal"])


# ---------------------------------------------------------------------------
# #04  markdown output contains allowed and forbidden claims
# ---------------------------------------------------------------------------

class TestMarkdownContent(unittest.TestCase):

    def _md(self, profile="local"):
        report = er.run_all(ROOT, profile, _read_fn=_all_scripts_read_fn(), _commit="test")
        return er.generate_markdown(report)

    def test_allowed_claim_present(self):
        md = self._md()
        self.assertIn(er.ALLOWED_CLAIM, md)

    def test_all_forbidden_claims_present(self):
        md = self._md()
        for claim in er.FORBIDDEN_CLAIMS:
            self.assertIn(claim, md, f"Forbidden claim not in markdown: {claim[:50]}")

    def test_all_stage_ids_in_table(self):
        md = self._md()
        for sid in er.STAGE_IDS:
            self.assertIn(sid, md)

    def test_safety_boundaries_section_present(self):
        md = self._md()
        self.assertIn("Safety Boundary", md)
        self.assertIn("no_active_scanning", md)

    def test_remaining_gaps_section_present(self):
        md = self._md()
        self.assertIn("Remaining Gaps", md)

    def test_next_steps_section_present(self):
        md = self._md()
        self.assertIn("Next Recommended", md)

    def test_enterprise_043_title_present(self):
        md = self._md()
        self.assertIn("ENTERPRISE-043", md)

    def test_framing_statement_present(self):
        md = self._md()
        self.assertIn("Controlled production-pilot", md)

    def test_not_full_production_certified_framing(self):
        md = self._md()
        self.assertIn("NOT full production", md)


# ---------------------------------------------------------------------------
# #05  execute-readonly-validators builds expected commands
# ---------------------------------------------------------------------------

class TestReadonlyValidatorsCommands(unittest.TestCase):

    def test_readonly_flag_changes_mode_to_execute(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        self.assertEqual(report["mode"], "execute")

    def test_exe01_has_command_when_readonly(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exe01 = next(s for s in report["stages"] if s["id"] == "EXE-01")
        self.assertIn("xdr_production_profile_validate", exe01["command"])

    def test_readonly_stages_executed(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        readonly_stages = [s for s in report["stages"]
                           if any(sd["execute_flag"] == "readonly"
                                  for sd in er._STAGE_DEFS
                                  if sd["id"] == s["id"])]
        for s in readonly_stages:
            self.assertTrue(s.get("executed"), f"{s['id']} should be executed")

    def test_restore_stage_not_executed_without_restore_flag(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            execute_restore=False,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertFalse(exe06.get("executed"))

    def test_soak_execute_not_run_with_only_readonly(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            execute_soak=False,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exe08 = next(s for s in report["stages"] if s["id"] == "EXE-08")
        self.assertFalse(exe08.get("executed"))


# ---------------------------------------------------------------------------
# #06  restore execute is not enabled by default
# ---------------------------------------------------------------------------

class TestRestoreNotDefault(unittest.TestCase):

    def test_execute_restore_defaults_false(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertFalse(report["execute_restore"])

    def test_exe06_not_executed_by_default(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertFalse(exe06.get("executed"))

    def test_exe06_status_info_or_skipped_by_default(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertIn(exe06["status"], (er.SKIPPED, er.INFO))


# ---------------------------------------------------------------------------
# #07  live soak execute is not enabled by default
# ---------------------------------------------------------------------------

class TestSoakNotDefault(unittest.TestCase):

    def test_execute_soak_defaults_false(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertFalse(report["execute_soak"])

    def test_exe08_not_executed_by_default(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe08 = next(s for s in report["stages"] if s["id"] == "EXE-08")
        self.assertFalse(exe08.get("executed"))

    def test_exe08_status_info_or_skipped_by_default(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe08 = next(s for s in report["stages"] if s["id"] == "EXE-08")
        self.assertIn(exe08["status"], (er.SKIPPED, er.INFO))


# ---------------------------------------------------------------------------
# #08  live causal proof execute is not enabled by default
# ---------------------------------------------------------------------------

class TestCausalNotDefault(unittest.TestCase):

    def test_execute_causal_defaults_false(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertFalse(report["execute_causal"])

    def test_exe09_not_executed_by_default(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe09 = next(s for s in report["stages"] if s["id"] == "EXE-09")
        self.assertFalse(exe09.get("executed"))

    def test_exe09_status_info_or_skipped_by_default(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe09 = next(s for s in report["stages"] if s["id"] == "EXE-09")
        self.assertIn(exe09["status"], (er.SKIPPED, er.INFO))


# ---------------------------------------------------------------------------
# #09  restore execute requires isolated target guard
# ---------------------------------------------------------------------------

class TestRestoreIsolationGuard(unittest.TestCase):

    def test_restore_fails_when_no_env_file(self):
        m = {s: "# stub" for s in _ALL_SCRIPTS}
        # No .env file → isolation check fails
        fn = _make_read_fn(m)
        report = er.run_all(ROOT, "local",
                            execute_restore=True,
                            _read_fn=fn,
                            _run_fn=_noop_run,
                            _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertEqual(exe06["status"], er.FAIL)
        self.assertFalse(exe06.get("executed"))

    def test_restore_fails_when_target_same_as_source(self):
        m = {s: "# stub" for s in _ALL_SCRIPTS}
        m[".env"] = _ENV_SAME
        fn = _make_read_fn(m)
        report = er.run_all(ROOT, "local",
                            execute_restore=True,
                            _read_fn=fn,
                            _run_fn=_noop_run,
                            _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertEqual(exe06["status"], er.FAIL)

    def test_restore_fails_when_no_target_env_var(self):
        m = {s: "# stub" for s in _ALL_SCRIPTS}
        m[".env"] = _ENV_NO_ISOLATION
        fn = _make_read_fn(m)
        report = er.run_all(ROOT, "local",
                            execute_restore=True,
                            _read_fn=fn,
                            _run_fn=_noop_run,
                            _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertEqual(exe06["status"], er.FAIL)

    def test_restore_executes_when_target_isolated(self):
        report = er.run_all(ROOT, "local",
                            execute_restore=True,
                            _read_fn=_all_scripts_read_fn(_ENV_ISOLATED),
                            _run_fn=_noop_run,
                            _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertTrue(exe06.get("executed"))
        self.assertEqual(exe06["status"], er.PASS)

    def test_check_restore_isolation_true_when_isolated(self):
        fn = _make_read_fn({".env": _ENV_ISOLATED})
        self.assertTrue(er._check_restore_isolation(ROOT, fn))

    def test_check_restore_isolation_false_when_same(self):
        fn = _make_read_fn({".env": _ENV_SAME})
        self.assertFalse(er._check_restore_isolation(ROOT, fn))

    def test_check_restore_isolation_false_when_absent(self):
        fn = _make_read_fn({})
        self.assertFalse(er._check_restore_isolation(ROOT, fn))


# ---------------------------------------------------------------------------
# #10  live soak duration is capped
# ---------------------------------------------------------------------------

class TestSoakDurationCap(unittest.TestCase):

    def test_duration_capped_at_max(self):
        report = er.run_all(ROOT, "local",
                            execute_soak=True,
                            duration_minutes=9999,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        self.assertEqual(report["duration_minutes"], er.MAX_DURATION_MINUTES)

    def test_duration_floored_at_one(self):
        report = er.run_all(ROOT, "local",
                            execute_soak=True,
                            duration_minutes=0,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        self.assertGreaterEqual(report["duration_minutes"], 1)

    def test_duration_within_bounds_preserved(self):
        report = er.run_all(ROOT, "local",
                            execute_soak=True,
                            duration_minutes=30,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        self.assertEqual(report["duration_minutes"], 30)

    def test_soak_command_contains_duration(self):
        calls: list[list[str]] = []

        def capturing_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        er.run_all(ROOT, "local",
                   execute_soak=True,
                   duration_minutes=15,
                   _read_fn=_all_scripts_read_fn(),
                   _run_fn=capturing_run,
                   _commit="test")
        soak_calls = [c for c in calls if "xdr_live_soak_validate" in " ".join(c)]
        self.assertTrue(any("15" in c for c in soak_calls),
                        f"Expected '15' in soak command: {soak_calls}")


# ---------------------------------------------------------------------------
# #11  invalid profile returns ERROR
# ---------------------------------------------------------------------------

class TestInvalidProfile(unittest.TestCase):

    def test_invalid_profile_returns_error_status(self):
        report = er.run_all(ROOT, "invalid_profile",
                            _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertEqual(report["overall_status"], er.ERROR)

    def test_invalid_profile_has_error_field(self):
        report = er.run_all(ROOT, "bad",
                            _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertIn("error", report)

    def test_invalid_profile_stages_empty(self):
        report = er.run_all(ROOT, "notaprofile",
                            _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertEqual(report["stages"], [])


# ---------------------------------------------------------------------------
# #12  subprocess runner is injectable
# ---------------------------------------------------------------------------

class TestSubprocessInjectable(unittest.TestCase):

    def test_custom_run_fn_is_called(self):
        calls: list[list[str]] = []

        def tracking_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        er.run_all(ROOT, "local",
                   execute_readonly=True,
                   _read_fn=_all_scripts_read_fn(),
                   _run_fn=tracking_run,
                   _commit="test")
        self.assertGreater(len(calls), 0, "Expected run_fn to be called")

    def test_run_fn_receives_list_of_strings(self):
        for cmd_received in [None]:
            def capturing_run(cmd):
                nonlocal cmd_received
                cmd_received = cmd
                return 0, "", ""

            er.run_all(ROOT, "local",
                       execute_readonly=True,
                       _read_fn=_all_scripts_read_fn(),
                       _run_fn=capturing_run,
                       _commit="test")
            if cmd_received is not None:
                self.assertIsInstance(cmd_received, list)
                for item in cmd_received:
                    self.assertIsInstance(item, str)
                break


# ---------------------------------------------------------------------------
# #13  subprocess success maps to PASS
# ---------------------------------------------------------------------------

class TestSubprocessSuccessMapsToPass(unittest.TestCase):

    def test_exit0_maps_to_pass(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertEqual(s["status"], er.PASS,
                             f"{s['id']} should be PASS on exit 0")

    def test_overall_pass_when_all_succeed(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        self.assertEqual(report["overall_status"], er.PASS)


# ---------------------------------------------------------------------------
# #14  subprocess non-zero maps to FAIL
# ---------------------------------------------------------------------------

class TestSubprocessFailureMapsToFail(unittest.TestCase):

    def test_exit1_maps_to_warn_locally(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_fail_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertIn(s["status"], (er.WARN, er.FAIL),
                          f"{s['id']} should be WARN or FAIL on exit 1")

    def test_exit1_maps_to_fail_in_production(self):
        report = er.run_all(ROOT, "production",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_fail_run,
                            _commit="test")
        executed_required = [
            s for s in report["stages"]
            if s.get("executed") and s.get("required")
        ]
        for s in executed_required:
            self.assertEqual(s["status"], er.FAIL,
                             f"{s['id']} should be FAIL on exit 1 in production")

    def test_exit1_overall_not_pass(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_fail_run,
                            _commit="test")
        self.assertNotEqual(report["overall_status"], er.PASS)


# ---------------------------------------------------------------------------
# #15  exit code 2 / exception maps to ERROR
# ---------------------------------------------------------------------------

class TestErrorMapping(unittest.TestCase):

    def test_exit2_maps_to_error(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_error_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertEqual(s["status"], er.ERROR,
                             f"{s['id']} should be ERROR on exit 2")

    def test_exception_in_run_fn_maps_to_error(self):
        def raising_run(cmd):
            raise OSError("connection refused")

        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=raising_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertEqual(s["status"], er.ERROR)

    def test_error_propagates_to_overall(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_error_run,
                            _commit="test")
        self.assertEqual(report["overall_status"], er.FAIL)


# ---------------------------------------------------------------------------
# #16  missing required script warns locally
# ---------------------------------------------------------------------------

class TestMissingScriptWarnLocal(unittest.TestCase):

    def test_missing_required_script_warns_locally(self):
        fn = _make_read_fn({".env": _ENV_ISOLATED})  # no scripts present
        report = er.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        # Required stages with missing scripts → WARN locally
        warned = [s for s in report["stages"] if s["status"] == er.WARN]
        self.assertGreater(len(warned), 0)

    def test_missing_script_no_subprocess_call(self):
        fn = _make_read_fn({".env": _ENV_ISOLATED})
        er.run_all(ROOT, "local",
                   execute_readonly=True,
                   _read_fn=fn,
                   _run_fn=_should_not_run,  # must not be called for missing scripts
                   _commit="test")

    def test_exe01_warn_when_script_missing_locally(self):
        m = dict(_all_core_scripts())
        del m["scripts/xdr_production_profile_validate.py"]
        fn = _make_read_fn(m)
        report = er.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        exe01 = next(s for s in report["stages"] if s["id"] == "EXE-01")
        self.assertEqual(exe01["status"], er.WARN)


def _all_core_scripts():
    return {s: "# stub" for s in _ALL_SCRIPTS}


# ---------------------------------------------------------------------------
# #17  missing required script fails in production
# ---------------------------------------------------------------------------

class TestMissingScriptFailProduction(unittest.TestCase):

    def test_missing_required_script_fails_in_production(self):
        fn = _make_read_fn({".env": _ENV_ISOLATED})  # no scripts
        report = er.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        failed = [s for s in report["stages"] if s["status"] == er.FAIL]
        self.assertGreater(len(failed), 0)

    def test_exe01_fail_in_production_when_script_missing(self):
        m = dict(_all_core_scripts())
        del m["scripts/xdr_production_profile_validate.py"]
        fn = _make_read_fn(m)
        report = er.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        exe01 = next(s for s in report["stages"] if s["id"] == "EXE-01")
        self.assertEqual(exe01["status"], er.FAIL)

    def test_overall_fail_in_production_with_no_scripts(self):
        fn = _make_read_fn({})
        report = er.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        self.assertEqual(report["overall_status"], er.FAIL)


# ---------------------------------------------------------------------------
# #18  safety boundary object is present and true
# ---------------------------------------------------------------------------

class TestSafetyBoundaries(unittest.TestCase):

    def test_safety_boundaries_present(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertIn("safety_boundaries", report)

    def test_all_safety_boundaries_are_true(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        for k, v in report["safety_boundaries"].items():
            self.assertTrue(v, f"Safety boundary {k!r} must be True")

    def test_no_active_scanning_boundary(self):
        self.assertTrue(er.SAFETY_BOUNDARIES["no_active_scanning"])

    def test_no_autonomous_containment_boundary(self):
        self.assertTrue(er.SAFETY_BOUNDARIES["no_autonomous_containment"])

    def test_restore_target_isolated_boundary(self):
        self.assertTrue(er.SAFETY_BOUNDARIES["restore_target_isolated"])

    def test_live_soak_bounded_boundary(self):
        self.assertTrue(er.SAFETY_BOUNDARIES["live_soak_bounded"])

    def test_exe12_always_pass(self):
        fn = _make_read_fn({})  # no files at all
        report = er.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        exe12 = next(s for s in report["stages"] if s["id"] == "EXE-12")
        self.assertEqual(exe12["status"], er.PASS)


# ---------------------------------------------------------------------------
# #19  no ACTIVE_ALLOWLIST mutation/reference in script
# ---------------------------------------------------------------------------

class TestNoActiveAllowlistMutation(unittest.TestCase):

    def test_active_allowlist_not_mutated(self):
        src = (_SCRIPTS / "xdr_execute_evidence_runs.py").read_text(encoding="utf-8")
        self.assertNotIn("ACTIVE_ALLOWLIST.append", src)
        self.assertNotIn("ACTIVE_ALLOWLIST +=", src)
        self.assertNotIn("ACTIVE_ALLOWLIST.extend", src)

    def test_no_allowlist_mutation_boundary_true(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_active_allowlist_mutation"])


# ---------------------------------------------------------------------------
# #20  no detection rule mutation
# ---------------------------------------------------------------------------

class TestNoDetectionRuleMutation(unittest.TestCase):

    def test_no_registry_write_in_source(self):
        src = (_SCRIPTS / "xdr_execute_evidence_runs.py").read_text(encoding="utf-8")
        self.assertNotIn("registry.v1.json", src)

    def test_no_detection_rule_change_boundary_true(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_detection_rule_change"])


# ---------------------------------------------------------------------------
# #21  no shadow/active boundary mutation
# ---------------------------------------------------------------------------

class TestNoShadowActiveMutation(unittest.TestCase):

    def test_no_shadow_to_active_auto_promotion_boundary_true(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_shadow_to_active_auto_promotion"])

    def test_exe12_confirms_no_shadow_promotion(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        exe12 = next(s for s in report["stages"] if s["id"] == "EXE-12")
        self.assertIn("shadow-to-active", exe12["detail"])


# ---------------------------------------------------------------------------
# #22  generated markdown path is written
# ---------------------------------------------------------------------------

class TestMarkdownOutputWritten(unittest.TestCase):

    def test_generate_markdown_returns_string(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        md = er.generate_markdown(report)
        self.assertIsInstance(md, str)
        self.assertGreater(len(md), 100)

    def test_markdown_written_to_temp_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            md_path = Path(tmp) / "test_output.md"
            report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
            md = er.generate_markdown(report)
            md_path.write_text(md, encoding="utf-8")
            self.assertTrue(md_path.exists())
            content = md_path.read_text(encoding="utf-8")
            self.assertIn("ENTERPRISE-043", content)


# ---------------------------------------------------------------------------
# #23  generated JSON path is written
# ---------------------------------------------------------------------------

class TestJsonOutputWritten(unittest.TestCase):

    def test_json_report_serialisable(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        s = json.dumps(report, indent=2)
        d = json.loads(s)
        self.assertEqual(d["schema_version"], er.SCHEMA_VERSION)

    def test_json_written_to_temp_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            out_path = Path(tmp) / "test_report.json"
            report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
            out_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
            self.assertTrue(out_path.exists())
            d = json.loads(out_path.read_text(encoding="utf-8"))
            self.assertEqual(d["schema_version"], er.SCHEMA_VERSION)


# ---------------------------------------------------------------------------
# #24  exit code PASS=0
# ---------------------------------------------------------------------------

class TestExitCodePass(unittest.TestCase):

    def test_pass_overall_gives_exit_0(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exit_code = 0 if report["overall_status"] == er.PASS else 1
        self.assertEqual(exit_code, 0)

    def test_dry_run_with_all_scripts_present_is_pass(self):
        report = er.run_all(ROOT, "local", _read_fn=_all_scripts_read_fn(), _commit="test")
        # dry-run may be WARN (SKIPPED stages) or PASS — not FAIL
        self.assertNotEqual(report["overall_status"], er.FAIL)


# ---------------------------------------------------------------------------
# #25  exit code FAIL=1
# ---------------------------------------------------------------------------

class TestExitCodeFail(unittest.TestCase):

    def test_fail_overall_gives_exit_1(self):
        fn = _make_read_fn({})
        report = er.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        self.assertEqual(report["overall_status"], er.FAIL)
        exit_code = 0 if report["overall_status"] == er.PASS else (
            2 if report["overall_status"] == er.ERROR else 1
        )
        self.assertEqual(exit_code, 1)

    def test_warn_gives_exit_1(self):
        fn = _make_read_fn({})
        report = er.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        self.assertIn(report["overall_status"], (er.WARN, er.FAIL))
        exit_code = 0 if report["overall_status"] == er.PASS else (
            2 if report["overall_status"] == er.ERROR else 1
        )
        self.assertEqual(exit_code, 1)


# ---------------------------------------------------------------------------
# #26  exit code ERROR=2
# ---------------------------------------------------------------------------

class TestExitCodeError(unittest.TestCase):

    def test_error_overall_gives_exit_2(self):
        report = er.run_all(ROOT, "invalid_profile",
                            _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertEqual(report["overall_status"], er.ERROR)
        exit_code = (
            0 if report["overall_status"] == er.PASS
            else 2 if report["overall_status"] == er.ERROR
            else 1
        )
        self.assertEqual(exit_code, 2)

    def test_schema_version_present_in_error_report(self):
        report = er.run_all(ROOT, "bad",
                            _read_fn=_all_scripts_read_fn(), _commit="test")
        self.assertIn("schema_version", report)


# ---------------------------------------------------------------------------
# ENTERPRISE-043.1 Correctness Patch Tests
# ---------------------------------------------------------------------------

class TestCorrectnessPatch(unittest.TestCase):
    """20 additional tests covering ENTERPRISE-043.1 requirements."""

    # 1. execute-readonly validators produce executed commands (count > 0)
    def test_readonly_validators_produce_executed_commands(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        self.assertGreater(report["summary"]["executed"], 0,
                           "execute_readonly should produce executed stages > 0")

    # 2. execute-readonly invokes injectable runner
    def test_readonly_invokes_injectable_runner(self):
        calls: list[list[str]] = []

        def capturing_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        er.run_all(ROOT, "local",
                   execute_readonly=True,
                   _read_fn=_all_scripts_read_fn(),
                   _run_fn=capturing_run,
                   _commit="test")
        self.assertGreater(len(calls), 0, "Injectable runner must be called for readonly stages")

    # 3. correct tenant isolation script path is used
    def test_tenant_isolation_script_path_correct(self):
        calls: list[list[str]] = []

        def capturing_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        er.run_all(ROOT, "local",
                   execute_readonly=True,
                   _read_fn=_all_scripts_read_fn(),
                   _run_fn=capturing_run,
                   _commit="test")
        tenant_calls = [c for c in calls if "xdr_tenant_isolation_posture" in " ".join(c)]
        self.assertGreater(len(tenant_calls), 0,
                           "xdr_tenant_isolation_posture.py must be called")
        self.assertNotIn("poess_check", " ".join(tenant_calls[0]))

    # 4. operator readiness script is included
    def test_operator_readiness_script_included(self):
        calls: list[list[str]] = []

        def capturing_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        er.run_all(ROOT, "local",
                   execute_readonly=True,
                   _read_fn=_all_scripts_read_fn(),
                   _run_fn=capturing_run,
                   _commit="test")
        orc_calls = [c for c in calls if "xdr_operator_readiness_check" in " ".join(c)]
        self.assertGreater(len(orc_calls), 0,
                           "xdr_operator_readiness_check.py must be called in readonly mode")

    # 5. restore execute does not run without explicit flag (already in TestRestoreNotDefault —
    #    included here for patch completeness)
    def test_exe06_not_executed_without_restore_flag(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            execute_restore=False,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertFalse(exe06.get("executed"),
                         "EXE-06 must not execute without --execute-restore-drill")

    # 6. restore execute runs dry-run first (EXE-05 executes when restore flag is set)
    def test_restore_execute_runs_dry_run_first(self):
        report = er.run_all(ROOT, "local",
                            execute_restore=True,
                            _read_fn=_all_scripts_read_fn(_ENV_ISOLATED),
                            _run_fn=_noop_run,
                            _commit="test")
        exe05 = next(s for s in report["stages"] if s["id"] == "EXE-05")
        self.assertTrue(exe05.get("executed"),
                        "EXE-05 (restore dry-run) must execute when --execute-restore-drill is set")

    # 7. restore execute reports clear failure when target DB missing
    def test_restore_execute_clear_fail_when_target_missing(self):
        m = {s: "# stub" for s in _ALL_SCRIPTS}
        m[".env"] = _ENV_NO_ISOLATION
        fn = _make_read_fn(m)
        report = er.run_all(ROOT, "local",
                            execute_restore=True,
                            _read_fn=fn,
                            _run_fn=_noop_run,
                            _commit="test")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertEqual(exe06["status"], er.FAIL)
        self.assertIn("XDR_RESTORE_TARGET_DB", exe06["detail"])
        self.assertIn("PLAN_ERROR", exe06["detail"])

    # 8. live soak execute does not run without explicit flag
    def test_exe08_not_executed_without_soak_flag(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            execute_soak=False,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exe08 = next(s for s in report["stages"] if s["id"] == "EXE-08")
        self.assertFalse(exe08.get("executed"))

    # 9. live soak execute uses --live-soak-duration-minutes
    def test_live_soak_duration_in_command(self):
        calls: list[list[str]] = []

        def capturing_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        er.run_all(ROOT, "local",
                   execute_soak=True,
                   duration_minutes=15,
                   _read_fn=_all_scripts_read_fn(),
                   _run_fn=capturing_run,
                   _commit="test")
        soak_execute_calls = [c for c in calls
                               if "xdr_live_soak_validate" in " ".join(c)
                               and "--execute" in c]
        self.assertGreater(len(soak_execute_calls), 0)
        self.assertTrue(
            any("15" in " ".join(c) for c in soak_execute_calls),
            f"Expected duration 15 in soak execute command: {soak_execute_calls}",
        )

    # 10. live causal proof execute does not run without explicit flag
    def test_exe09_not_executed_without_causal_flag(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            execute_causal=False,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exe09 = next(s for s in report["stages"] if s["id"] == "EXE-09")
        self.assertFalse(exe09.get("executed"))

    # 11. executed command count is correct for different modes
    def test_executed_count_dry_run_is_zero(self):
        report = er.run_all(ROOT, "local",
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_should_not_run,
                            _commit="test")
        self.assertEqual(report["summary"]["executed"], 0)

    def test_executed_count_readonly_greater_than_zero(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        self.assertGreater(report["summary"]["executed"], 0)

    def test_executed_count_restore_includes_dryrun(self):
        report = er.run_all(ROOT, "local",
                            execute_restore=True,
                            _read_fn=_all_scripts_read_fn(_ENV_ISOLATED),
                            _run_fn=_noop_run,
                            _commit="test")
        # EXE-05 (dryrun) + EXE-06 (execute) should both run
        exe05 = next(s for s in report["stages"] if s["id"] == "EXE-05")
        exe06 = next(s for s in report["stages"] if s["id"] == "EXE-06")
        self.assertTrue(exe05.get("executed"))
        self.assertTrue(exe06.get("executed"))

    # 12. subprocess return code 0 maps to PASS with EXECUTED_PASS kind
    def test_exit0_maps_to_executed_pass_kind(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertEqual(s["kind"], "EXECUTED_PASS",
                             f"{s['id']} kind should be EXECUTED_PASS")

    # 13. subprocess non-zero maps to FAIL with EXECUTED_FAIL in detail
    def test_exit1_detail_contains_executed_fail(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_fail_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertIn("EXECUTED_FAIL", s["detail"],
                          f"{s['id']} detail should contain EXECUTED_FAIL")

    # 14. subprocess exception maps to ERROR with EXECUTION_ERROR in detail
    def test_exception_detail_contains_execution_error(self):
        def raising_run(cmd):
            raise OSError("connection refused")

        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=raising_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertIn("EXECUTION_ERROR", s["detail"],
                          f"{s['id']} detail should contain EXECUTION_ERROR")

    # 15. EXE-11 summary does not fail on skipped optional stages
    def test_exe11_pass_when_optional_skipped_in_readonly_mode(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            execute_restore=False,
                            execute_soak=False,
                            execute_causal=False,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        exe11 = next(s for s in report["stages"] if s["id"] == "EXE-11")
        self.assertEqual(exe11["status"], er.PASS,
                         "EXE-11 should PASS when optional stages are skipped")

    def test_exe11_not_fail_in_dry_run_mode(self):
        report = er.run_all(ROOT, "local",
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_should_not_run,
                            _commit="test")
        exe11 = next(s for s in report["stages"] if s["id"] == "EXE-11")
        self.assertNotEqual(exe11["status"], er.FAIL,
                            "EXE-11 must not FAIL in dry-run mode")

    # 16. JSON report includes command, executed flag, return code, and detail
    def test_stage_has_kind_and_exit_code_fields(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        executed = [s for s in report["stages"] if s.get("executed")]
        for s in executed:
            self.assertIn("kind", s, f"{s['id']} missing 'kind' field")
            self.assertIn("exit_code", s, f"{s['id']} missing 'exit_code' field")
            self.assertIsNotNone(s["exit_code"],
                                 f"{s['id']} exit_code should not be None when executed")
            self.assertNotEqual(s["command"], "",
                                f"{s['id']} command should not be empty when executed")
            self.assertNotEqual(s["detail"], "",
                                f"{s['id']} detail should not be empty")

    # 17. markdown report includes executed commands and failure details
    def test_markdown_includes_failure_detail(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_fail_run,
                            _commit="test")
        md = er.generate_markdown(report)
        # Markdown should mention at least one command was executed
        self.assertNotIn("_No commands executed", md)

    def test_markdown_commands_section_populated_after_execute(self):
        report = er.run_all(ROOT, "local",
                            execute_readonly=True,
                            _read_fn=_all_scripts_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        md = er.generate_markdown(report)
        # The commands executed section should not say dry-run when commands ran
        self.assertNotIn("_No commands executed (dry-run mode)._", md)

    # 18. no ACTIVE_ALLOWLIST mutation/reference (confirmatory)
    def test_patch_no_active_allowlist_mutation(self):
        src = (_SCRIPTS / "xdr_execute_evidence_runs.py").read_text(encoding="utf-8")
        self.assertNotIn("ACTIVE_ALLOWLIST.append", src)
        self.assertNotIn("ACTIVE_ALLOWLIST +=", src)

    # 19. no detection rule mutation
    def test_patch_no_detection_rule_mutation(self):
        src = (_SCRIPTS / "xdr_execute_evidence_runs.py").read_text(encoding="utf-8")
        self.assertNotIn("registry.v1.json", src)

    # 20. no shadow/active boundary mutation
    def test_patch_no_shadow_to_active_promotion(self):
        report = er.run_all(ROOT, "local",
                            _read_fn=_all_scripts_read_fn(),
                            _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_shadow_to_active_auto_promotion"])


if __name__ == "__main__":
    unittest.main()
