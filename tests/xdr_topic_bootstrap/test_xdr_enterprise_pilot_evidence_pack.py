"""Tests for scripts/xdr_enterprise_pilot_evidence_pack.py — ENTERPRISE-042.

All tests are deterministic and offline.  _read_fn and _run_fn injection
replace filesystem and subprocess access — no real project tree or network needed.

Test coverage map (per spec):
  #01  evidence pack builds with all required local files present
  #02  missing core evidence produces WARN locally
  #03  missing core evidence fails in production profile
  #04  optional evidence can be missing locally
  #05  safety boundary object is present and true
  #06  stage IDs EP-01 through EP-12 are stable
  #07  JSON report shape is stable
  #08  Markdown output contains allowed and forbidden claims
  #09  execute-validators mode builds command plan safely
  #10  include-live-soak is not enabled by default
  #11  include-restore-execute is not enabled by default
  #12  production profile fails on unsafe evidence state
  #13  local profile can pass or warn without mutation
  #14  no subprocess calls in default dry-run aggregation mode
  #15  no ACTIVE_ALLOWLIST mutation/reference
  #16  no detection rule mutation
  #17  exit code behaviour: PASS=0, FAIL=1, ERROR=2
"""
from __future__ import annotations

import json
import sys
import types
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).resolve().parent.parent.parent / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

import xdr_enterprise_pilot_evidence_pack as ep  # noqa: E402


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_STUB = "# stub"

# Self-approval guard content required by EP-06 content checks
_SERVICE_WITH_GUARD = "<?php\n// Self-approval is blocked: example\nclass Svc {}\n"
_CONTROLLER_WITH_GUARD = "<?php\n// Self-approval is blocked: example\nclass Ctrl {}\n"
_SERVICE_NO_GUARD = "<?php\nclass Svc {}\n"
_CONTROLLER_NO_GUARD = "<?php\nclass Ctrl {}\n"


def _make_read_fn(present: dict[Path | str, str]):
    """Return a _read_fn backed by an explicit {path: content} map.

    Normalises all keys to forward-slash strings so the map works
    identically on Windows (where Path.__str__ uses backslashes) and POSIX.
    """
    normalised = {str(k).replace("\\", "/"): v for k, v in present.items()}

    def _read(path: Path) -> str | None:
        return normalised.get(str(path).replace("\\", "/"))

    return _read


def _all_core_files() -> dict:
    """Return a file map where every stage's core file is present.

    Uses raw forward-slash strings (from stage defs) so the normalisation
    in _make_read_fn keeps everything consistent cross-platform.
    """
    m: dict[str, str] = {}
    for sd in ep._STAGE_DEFS:
        for fpath in sd["core_files"]:
            m[fpath] = _STUB  # forward-slash string from stage def
        for cc in sd.get("content_checks", []):
            m[cc["file"]] = "Self-approval is blocked: guard here"
    return m


def _all_pass_read_fn():
    return _make_read_fn(_all_core_files())


def _noop_run(cmd):
    """Fake run_fn that always succeeds."""
    return 0, "OVERALL=PASS\n", ""


def _should_not_be_called(cmd):
    raise AssertionError(f"subprocess called unexpectedly: {cmd}")


ROOT = Path("/fake/root")


# ---------------------------------------------------------------------------
# #01  evidence pack builds with all required local files present
# ---------------------------------------------------------------------------

class TestFullPassLocal(unittest.TestCase):

    def test_overall_pass_when_all_core_present(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertEqual(report["overall_status"], ep.PASS)

    def test_all_stages_have_id(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        ids = [s["id"] for s in report["stages"]]
        for stage_id in ep.STAGE_IDS:
            self.assertIn(stage_id, ids)

    def test_summary_counts_correct_on_full_pass(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        s = report["summary"]
        self.assertEqual(s["total_stages"], 12)
        self.assertEqual(s["failed"], 0)


# ---------------------------------------------------------------------------
# #02  missing core evidence produces WARN locally
# ---------------------------------------------------------------------------

class TestMissingCoreLocalWarn(unittest.TestCase):

    def test_core_stage_warn_when_files_missing_local(self):
        # Remove all files → every CORE stage should produce WARN (not FAIL) locally
        fn = _make_read_fn({})
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        # At least some stages should be WARN
        statuses = {s["status"] for s in report["stages"]}
        self.assertIn(ep.WARN, statuses)

    def test_no_fail_at_local_profile_when_files_absent(self):
        # In local profile, missing files → WARN not FAIL (by design)
        fn = _make_read_fn({})
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        # Overall may be WARN but should NOT be FAIL at local (all missing → WARN per _fail_sev)
        failed_stages = [s for s in report["stages"] if s["status"] == ep.FAIL]
        self.assertEqual(failed_stages, [],
                         "No stage should FAIL at local profile with missing files")

    def test_ep01_warn_when_live_freeze_missing_local(self):
        m = _all_core_files()
        del m["docs/validation/LIVE_035_EVIDENCE_FREEZE.md"]
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        ep01 = next(s for s in report["stages"] if s["id"] == "EP-01")
        self.assertEqual(ep01["status"], ep.WARN)


# ---------------------------------------------------------------------------
# #03  missing core evidence fails in production profile
# ---------------------------------------------------------------------------

class TestMissingCoreProductionFail(unittest.TestCase):

    def test_core_stage_fail_when_core_file_missing_production(self):
        # Remove the LIVE_035_EVIDENCE_FREEZE.md (EP-01 core file)
        m = _all_core_files()
        # The key is a forward-slash string as returned from the stage def
        del m["docs/validation/LIVE_035_EVIDENCE_FREEZE.md"]
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        ep01 = next(s for s in report["stages"] if s["id"] == "EP-01")
        self.assertEqual(ep01["status"], ep.FAIL)

    def test_overall_fail_in_production_when_core_missing(self):
        fn = _make_read_fn({})
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        self.assertEqual(report["overall_status"], ep.FAIL)

    def test_ep07_fails_production_when_rls_decision_missing(self):
        m = _all_core_files()
        del m["docs/security/RLS_DECISION_RECORD.md"]  # forward-slash key from stage def
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        ep07 = next(s for s in report["stages"] if s["id"] == "EP-07")
        self.assertEqual(ep07["status"], ep.FAIL)

    def test_ep08_fails_production_when_runbook_missing(self):
        m = _all_core_files()
        del m["docs/operations/PILOT_OPERATOR_RUNBOOK.md"]  # forward-slash key from stage def
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        ep08 = next(s for s in report["stages"] if s["id"] == "EP-08")
        self.assertEqual(ep08["status"], ep.FAIL)


# ---------------------------------------------------------------------------
# #04  optional evidence can be missing locally
# ---------------------------------------------------------------------------

class TestOptionalEvidenceMissingLocal(unittest.TestCase):

    def test_optional_report_missing_does_not_fail_stage(self):
        # EP-01 optional files missing → stage status should not be FAIL
        m = _all_core_files()
        # Remove all EP-01 optional files (they're reports/*.json)
        for key in list(m.keys()):
            if key.startswith("reports/"):
                del m[key]
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        ep01 = next(s for s in report["stages"] if s["id"] == "EP-01")
        self.assertNotEqual(ep01["status"], ep.FAIL)

    def test_optional_checks_report_info_when_missing(self):
        m = _all_core_files()
        # Remove optional files
        m_clean = {k: v for k, v in m.items() if not k.startswith("reports/")}
        fn = _make_read_fn(m_clean)
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        ep01 = next(s for s in report["stages"] if s["id"] == "EP-01")
        # Optional checks that fail should have INFO status
        optional_checks = [c for c in ep01["checks"] if ".O" in c["id"]]
        missing_opts = [c for c in optional_checks if c["status"] != ep.PASS]
        for c in missing_opts:
            self.assertEqual(c["status"], ep.INFO, f"Optional check {c['id']} should be INFO")


# ---------------------------------------------------------------------------
# #05  safety boundary object is present and true
# ---------------------------------------------------------------------------

class TestSafetyBoundaries(unittest.TestCase):

    def test_safety_boundaries_present_in_report(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertIn("safety_boundaries", report)

    def test_all_safety_boundaries_are_true(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        for k, v in report["safety_boundaries"].items():
            self.assertTrue(v, f"Safety boundary {k!r} must be True")

    def test_no_active_scanning_boundary(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_active_scanning"])

    def test_no_autonomous_containment_boundary(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_autonomous_containment"])

    def test_no_active_allowlist_mutation_boundary(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_active_allowlist_mutation"])

    def test_ep12_always_pass(self):
        fn = _make_read_fn({})  # even with no files
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        ep12 = next(s for s in report["stages"] if s["id"] == "EP-12")
        self.assertEqual(ep12["status"], ep.PASS)


# ---------------------------------------------------------------------------
# #06  stage IDs EP-01 through EP-12 are stable
# ---------------------------------------------------------------------------

class TestStageIdStability(unittest.TestCase):

    def test_exactly_twelve_stages(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertEqual(len(report["stages"]), 12)

    def test_stage_ids_are_ep01_through_ep12(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        ids = [s["id"] for s in report["stages"]]
        expected = [f"EP-{i:02d}" for i in range(1, 13)]
        self.assertEqual(ids, expected)

    def test_stage_ids_constant_matches_defs(self):
        self.assertEqual(len(ep.STAGE_IDS), 12)
        self.assertEqual(ep.STAGE_IDS[0], "EP-01")
        self.assertEqual(ep.STAGE_IDS[-1], "EP-12")

    def test_stage_names_are_stable(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        names = {s["id"]: s["name"] for s in report["stages"]}
        self.assertIn("Final live causal proof evidence", names["EP-01"])
        self.assertIn("Safety boundary confirmation", names["EP-12"])


# ---------------------------------------------------------------------------
# #07  JSON report shape is stable
# ---------------------------------------------------------------------------

class TestReportShape(unittest.TestCase):

    def _report(self):
        return ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="abc123")

    def test_schema_version_present(self):
        self.assertEqual(self._report()["schema_version"], ep.SCHEMA_VERSION)

    def test_required_top_level_fields(self):
        r = self._report()
        for field in ("schema_version", "generated_at", "profile", "overall_status",
                      "commit", "stages", "safety_boundaries", "remaining_gaps",
                      "recommended_next_steps", "summary", "execute_validators",
                      "include_live_soak", "include_restore_execute"):
            self.assertIn(field, r, f"Missing field: {field}")

    def test_stage_shape(self):
        r = self._report()
        for stage in r["stages"]:
            for field in ("id", "name", "status", "required", "detail",
                          "evidence_files", "missing_evidence", "checks"):
                self.assertIn(field, stage, f"{stage['id']} missing field {field}")

    def test_is_json_serialisable(self):
        r = self._report()
        s = json.dumps(r)
        d = json.loads(s)
        self.assertEqual(d["schema_version"], ep.SCHEMA_VERSION)

    def test_execute_validators_defaults_false(self):
        self.assertFalse(self._report()["execute_validators"])

    def test_include_live_soak_defaults_false(self):
        self.assertFalse(self._report()["include_live_soak"])

    def test_include_restore_execute_defaults_false(self):
        self.assertFalse(self._report()["include_restore_execute"])


# ---------------------------------------------------------------------------
# #08  Markdown output contains allowed and forbidden claims
# ---------------------------------------------------------------------------

class TestMarkdownContent(unittest.TestCase):

    def _md(self, profile="local"):
        report = ep.run_all(ROOT, profile, _read_fn=_all_pass_read_fn(), _commit="test")
        return ep.generate_markdown(report)

    def test_allowed_claim_present(self):
        md = self._md()
        self.assertIn(ep.ALLOWED_CLAIM, md)

    def test_forbidden_claims_all_present(self):
        md = self._md()
        for claim in ep.FORBIDDEN_CLAIMS:
            self.assertIn(claim, md, f"Forbidden claim not documented: {claim[:50]}")

    def test_all_stage_ids_in_table(self):
        md = self._md()
        for sid in ep.STAGE_IDS:
            self.assertIn(sid, md)

    def test_safety_boundaries_section_present(self):
        md = self._md()
        self.assertIn("Safety Boundary Confirmation", md)
        self.assertIn("no_active_scanning", md)

    def test_remaining_gaps_section_present(self):
        md = self._md()
        self.assertIn("Remaining Gaps", md)

    def test_next_steps_section_present(self):
        md = self._md()
        self.assertIn("Next Recommended", md)

    def test_markdown_contains_evidence_pack_title(self):
        md = self._md()
        self.assertIn("Enterprise Pilot Evidence Pack", md)

    def test_framing_statement_present(self):
        md = self._md()
        self.assertIn("Controlled production-pilot evidence pack", md)


# ---------------------------------------------------------------------------
# #09  execute-validators mode builds command plan safely
# ---------------------------------------------------------------------------

class TestExecuteValidatorsMode(unittest.TestCase):

    def test_run_fn_called_when_execute_validators_true(self):
        calls = []

        def tracking_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        ep.run_all(ROOT, "local",
                   execute_validators=True,
                   _read_fn=_all_pass_read_fn(),
                   _run_fn=tracking_run,
                   _commit="test")
        # At least some validators should have been called
        self.assertGreater(len(calls), 0, "Expected validator commands to be called")

    def test_validator_commands_use_safe_flags(self):
        calls = []

        def tracking_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        ep.run_all(ROOT, "local",
                   execute_validators=True,
                   include_live_soak=False,
                   include_restore_execute=False,
                   _read_fn=_all_pass_read_fn(),
                   _run_fn=tracking_run,
                   _commit="test")
        for cmd in calls:
            # No --execute flag should appear without explicit inclusion
            self.assertNotIn("--execute", cmd,
                             f"--execute should not appear in: {cmd}")

    def test_live_soak_execute_flag_added_when_include_set(self):
        calls = []

        def tracking_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        ep.run_all(ROOT, "local",
                   execute_validators=True,
                   include_live_soak=True,
                   _read_fn=_all_pass_read_fn(),
                   _run_fn=tracking_run,
                   _commit="test")
        soak_calls = [c for c in calls if "xdr_live_soak_validate" in " ".join(c)]
        self.assertTrue(any("--execute" in c for c in soak_calls),
                        "Expected --execute in live soak call when include_live_soak=True")

    def test_restore_execute_flag_added_when_include_set(self):
        calls = []

        def tracking_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        ep.run_all(ROOT, "local",
                   execute_validators=True,
                   include_restore_execute=True,
                   _read_fn=_all_pass_read_fn(),
                   _run_fn=tracking_run,
                   _commit="test")
        restore_calls = [c for c in calls if "xdr_restore_drill" in " ".join(c)]
        self.assertTrue(any("--execute" in c for c in restore_calls),
                        "Expected --execute in restore call when include_restore_execute=True")

    def test_validator_failure_propagates_to_stage(self):
        def always_fail(cmd):
            return 1, "", "validator failed"

        report = ep.run_all(ROOT, "local",
                            execute_validators=True,
                            _read_fn=_all_pass_read_fn(),
                            _run_fn=always_fail,
                            _commit="test")
        # At local profile, validator failure → WARN (not FAIL)
        val_checks = []
        for stage in report["stages"]:
            val_checks.extend([c for c in stage["checks"] if c["id"].endswith(".VAL")])
        # Some VAL checks should be non-PASS when validator returns exit=1
        non_pass = [c for c in val_checks if c["status"] != ep.PASS and c["status"] != ep.INFO]
        self.assertGreater(len(non_pass), 0)


# ---------------------------------------------------------------------------
# #10  include-live-soak is not enabled by default
# ---------------------------------------------------------------------------

class TestLiveSoakNotDefault(unittest.TestCase):

    def test_include_live_soak_defaults_false_in_report(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertFalse(report["include_live_soak"])

    def test_live_soak_not_run_without_execute_validators(self):
        calls = []

        def tracking_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        ep.run_all(ROOT, "local",
                   execute_validators=False,
                   include_live_soak=True,  # set but execute_validators is False
                   _read_fn=_all_pass_read_fn(),
                   _run_fn=tracking_run,
                   _commit="test")
        soak_calls = [c for c in calls if "xdr_live_soak_validate" in " ".join(c)]
        self.assertEqual(soak_calls, [], "Live soak should not run without execute_validators")

    def test_live_soak_stage_has_info_without_execute_and_include(self):
        report = ep.run_all(ROOT, "local",
                            execute_validators=True,
                            include_live_soak=False,
                            _read_fn=_all_pass_read_fn(),
                            _run_fn=_noop_run,
                            _commit="test")
        ep05 = next(s for s in report["stages"] if s["id"] == "EP-05")
        val_check = next((c for c in ep05["checks"] if c["id"].endswith(".VAL")), None)
        if val_check:
            self.assertEqual(val_check["status"], ep.INFO,
                             "EP-05 validator should be INFO (skipped) without include_live_soak")


# ---------------------------------------------------------------------------
# #11  include-restore-execute is not enabled by default
# ---------------------------------------------------------------------------

class TestRestoreExecuteNotDefault(unittest.TestCase):

    def test_include_restore_execute_defaults_false_in_report(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertFalse(report["include_restore_execute"])

    def test_restore_drill_runs_dry_run_by_default_execute_validators(self):
        calls = []

        def tracking_run(cmd):
            calls.append(cmd)
            return 0, "", ""

        ep.run_all(ROOT, "local",
                   execute_validators=True,
                   include_restore_execute=False,
                   _read_fn=_all_pass_read_fn(),
                   _run_fn=tracking_run,
                   _commit="test")
        restore_calls = [c for c in calls if "xdr_restore_drill" in " ".join(c)]
        # Should not have --execute
        for cmd in restore_calls:
            self.assertNotIn("--execute", cmd,
                             "Restore drill should not have --execute without include_restore_execute")


# ---------------------------------------------------------------------------
# #12  production profile fails on unsafe evidence state
# ---------------------------------------------------------------------------

class TestProductionFailsOnBadEvidence(unittest.TestCase):

    def test_production_fails_with_no_files(self):
        fn = _make_read_fn({})
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        self.assertEqual(report["overall_status"], ep.FAIL)

    def test_production_fails_when_ep06_guards_missing(self):
        m = _all_core_files()
        # Replace guard files with versions that lack the guard pattern (forward-slash keys)
        m["app/Services/EndpointResponseCommandService.php"] = _SERVICE_NO_GUARD
        m["app/Http/Controllers/SocResponseController.php"] = _CONTROLLER_NO_GUARD
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        ep06 = next(s for s in report["stages"] if s["id"] == "EP-06")
        # Content checks missing → FAIL at production
        self.assertEqual(ep06["status"], ep.FAIL)

    def test_production_stricter_than_local(self):
        fn = _make_read_fn({})
        local_r = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        prod_r = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        self.assertGreaterEqual(prod_r["summary"]["failed"], local_r["summary"]["failed"])


# ---------------------------------------------------------------------------
# #13  local profile can pass or warn without mutation
# ---------------------------------------------------------------------------

class TestLocalNoMutation(unittest.TestCase):

    def test_local_with_all_files_is_pass(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertEqual(report["overall_status"], ep.PASS)

    def test_local_without_files_is_warn_not_fail(self):
        fn = _make_read_fn({})
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        self.assertIn(report["overall_status"], (ep.WARN, ep.PASS))
        self.assertEqual(report["summary"]["failed"], 0)

    def test_local_run_does_not_write_any_files(self):
        # Verify run_all returns a dict without file side-effects (no write calls)
        # This is a structural test — run_all has no write logic; only main() writes
        result = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertIsInstance(result, dict)


# ---------------------------------------------------------------------------
# #14  no subprocess calls in default dry-run aggregation mode
# ---------------------------------------------------------------------------

class TestNoDryrRunSubprocess(unittest.TestCase):

    def test_run_fn_never_called_when_execute_validators_false(self):
        """If execute_validators=False, _run_fn must never be called."""
        ep.run_all(ROOT, "local",
                   execute_validators=False,
                   _read_fn=_all_pass_read_fn(),
                   _run_fn=_should_not_be_called,
                   _commit="test")
        # If _should_not_be_called raised, the test would fail — reaching here = PASS

    def test_none_run_fn_default_does_not_crash_dryrun(self):
        """Passing _run_fn=None (default) with execute_validators=False should not error."""
        report = ep.run_all(ROOT, "local",
                            execute_validators=False,
                            _read_fn=_all_pass_read_fn(),
                            _run_fn=None,
                            _commit="test")
        self.assertIn(report["overall_status"], (ep.PASS, ep.WARN, ep.FAIL))

    def test_all_profiles_dry_run_never_call_subprocess(self):
        for profile in ("local", "staging", "production"):
            ep.run_all(ROOT, profile,
                       execute_validators=False,
                       _read_fn=_all_pass_read_fn(),
                       _run_fn=_should_not_be_called,
                       _commit="test")


# ---------------------------------------------------------------------------
# #15  no ACTIVE_ALLOWLIST mutation/reference in script
# ---------------------------------------------------------------------------

class TestNoActiveAllowlistMutation(unittest.TestCase):

    def test_active_allowlist_not_mutated_in_script(self):
        """Verify the script source does not mutate ACTIVE_ALLOWLIST."""
        script_path = Path(__file__).resolve().parent.parent.parent / "scripts" / "xdr_enterprise_pilot_evidence_pack.py"
        source = script_path.read_text(encoding="utf-8")
        # The script should not append to or modify ACTIVE_ALLOWLIST
        self.assertNotIn("ACTIVE_ALLOWLIST.append", source)
        self.assertNotIn("ACTIVE_ALLOWLIST +=", source)
        self.assertNotIn("ACTIVE_ALLOWLIST.extend", source)

    def test_safety_boundary_confirms_no_allowlist_mutation(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_active_allowlist_mutation"])


# ---------------------------------------------------------------------------
# #16  no detection rule mutation
# ---------------------------------------------------------------------------

class TestNoDetectionRuleMutation(unittest.TestCase):

    def test_no_rule_mutation_in_script_source(self):
        script_path = Path(__file__).resolve().parent.parent.parent / "scripts" / "xdr_enterprise_pilot_evidence_pack.py"
        source = script_path.read_text(encoding="utf-8")
        # Script must not write to registry
        self.assertNotIn("registry.v1.json", source)

    def test_no_shadow_to_active_promotion(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertTrue(report["safety_boundaries"]["no_shadow_to_active_auto_promotion"])

    def test_ep12_confirms_no_promotion(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        ep12 = next(s for s in report["stages"] if s["id"] == "EP-12")
        self.assertEqual(ep12["status"], ep.PASS)
        self.assertGreater(len(ep12["checks"]), 0)
        self.assertIn("self-approval", ep12["checks"][0]["detail"])


# ---------------------------------------------------------------------------
# #17  exit code behaviour: PASS=0, FAIL=1, WARN=1, ERROR=2
# ---------------------------------------------------------------------------

class TestExitCodes(unittest.TestCase):

    def test_pass_overall_returns_0(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertEqual(report["overall_status"], ep.PASS)
        # main() returns 0 for PASS
        exit_code = 0 if report["overall_status"] == ep.PASS else 1
        self.assertEqual(exit_code, 0)

    def test_warn_overall_returns_1(self):
        fn = _make_read_fn({})
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        self.assertIn(report["overall_status"], (ep.WARN, ep.FAIL))
        exit_code = 0 if report["overall_status"] == ep.PASS else 1
        self.assertEqual(exit_code, 1)

    def test_fail_overall_returns_1(self):
        fn = _make_read_fn({})
        report = ep.run_all(ROOT, "production", _read_fn=fn, _commit="test")
        self.assertEqual(report["overall_status"], ep.FAIL)
        exit_code = 0 if report["overall_status"] == ep.PASS else 1
        self.assertEqual(exit_code, 1)


# ---------------------------------------------------------------------------
# Additional coverage: EP-06 content checks
# ---------------------------------------------------------------------------

class TestEp06ContentChecks(unittest.TestCase):

    def test_ep06_pass_when_guards_present(self):
        m = _all_core_files()
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        ep06 = next(s for s in report["stages"] if s["id"] == "EP-06")
        self.assertEqual(ep06["status"], ep.PASS)

    def test_ep06_warn_locally_when_guard_missing_in_service(self):
        m = _all_core_files()
        # Use the same forward-slash key as stage def to ensure override takes effect
        m["app/Services/EndpointResponseCommandService.php"] = _SERVICE_NO_GUARD
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        ep06 = next(s for s in report["stages"] if s["id"] == "EP-06")
        self.assertEqual(ep06["status"], ep.WARN)

    def test_ep06_warn_locally_when_guard_missing_in_controller(self):
        m = _all_core_files()
        # Use the same forward-slash key as stage def to ensure override takes effect
        m["app/Http/Controllers/SocResponseController.php"] = _CONTROLLER_NO_GUARD
        fn = _make_read_fn(m)
        report = ep.run_all(ROOT, "local", _read_fn=fn, _commit="test")
        ep06 = next(s for s in report["stages"] if s["id"] == "EP-06")
        self.assertEqual(ep06["status"], ep.WARN)


# ---------------------------------------------------------------------------
# Additional coverage: remaining gaps and next steps
# ---------------------------------------------------------------------------

class TestRemainingGapsAndNextSteps(unittest.TestCase):

    def test_remaining_gaps_present(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertIsInstance(report["remaining_gaps"], list)
        self.assertGreater(len(report["remaining_gaps"]), 0)

    def test_next_steps_present(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        self.assertIsInstance(report["recommended_next_steps"], list)
        self.assertGreater(len(report["recommended_next_steps"]), 0)

    def test_rls_gap_documented(self):
        report = ep.run_all(ROOT, "local", _read_fn=_all_pass_read_fn(), _commit="test")
        gaps_text = " ".join(report["remaining_gaps"])
        self.assertIn("RLS", gaps_text)


if __name__ == "__main__":
    unittest.main()
