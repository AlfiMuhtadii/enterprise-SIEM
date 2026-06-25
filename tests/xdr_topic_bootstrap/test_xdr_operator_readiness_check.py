"""Tests for scripts/xdr_operator_readiness_check.py — ENTERPRISE-041.

All tests are deterministic and offline.  _read_fn injection replaces
filesystem access — no real project tree is needed.
"""
from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).resolve().parent.parent.parent / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

import xdr_operator_readiness_check as orc  # noqa: E402


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_STUB = "# stub"


def _make_read_fn(present: set[Path | str]):
    """Return a _read_fn where paths in `present` exist; others return None."""
    normalised = {str(p) for p in present}

    def _read(path: Path) -> str | None:
        return _STUB if str(path) in normalised else None

    return _read


def _all_present_read_fn():
    """_read_fn that makes every ORC check pass."""
    return _make_read_fn({
        orc.RUNBOOK_PATH,
        orc.TOPIC_BOOTSTRAP_PATH,
        orc.POSTURE_CHECK_PATH,
        orc.PILOT_LIVE_PATH,
        orc.PROD_PROFILE_PATH,
        orc.RECOVERY_VALIDATE_PATH,
        orc.RESTORE_DRILL_PATH,
        orc.LIVE_SOAK_PATH,
        orc.EASM_SCAN_PATH,
        orc.EASM_HISTORY_PATH,
        orc.TENANT_ISOLATION_PATH,
        orc.SOAK_6H_SCRIPT_PATH,
        orc.BACKUP_RECOVERY_DOC,
        orc.OPERATIONAL_POSTURE_DOC,
        orc.PROD_PROFILE_DOC,
        orc.RULE_REGISTRY_PATH,
        orc.PROD_COMPOSE_PATH,
        orc.ENV_EXAMPLE_PATH,
    })


ROOT = Path("/fake/root")


# ---------------------------------------------------------------------------
# ORC-01  RUNBOOK_PRESENT
# ---------------------------------------------------------------------------

class TestOrc01Runbook(unittest.TestCase):

    def test_pass_when_present(self):
        fn = _make_read_fn({orc.RUNBOOK_PATH})
        r = orc.check_runbook(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.PASS)

    def test_fail_when_absent(self):
        fn = _make_read_fn(set())
        r = orc.check_runbook(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.FAIL)

    def test_fail_regardless_of_profile(self):
        fn = _make_read_fn(set())
        for profile in ("local", "staging", "production"):
            r = orc.check_runbook(ROOT, profile, fn)
            self.assertEqual(r["status"], orc.FAIL, f"profile={profile}")

    def test_check_id(self):
        fn = _make_read_fn({orc.RUNBOOK_PATH})
        r = orc.check_runbook(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "ORC-01")


# ---------------------------------------------------------------------------
# ORC-02 through ORC-11 — script presence checks (always FAIL when absent)
# ---------------------------------------------------------------------------

class TestScriptPresenceChecks(unittest.TestCase):

    _SCRIPT_CHECKS = [
        ("ORC-02", orc.check_topic_bootstrap, orc.TOPIC_BOOTSTRAP_PATH),
        ("ORC-03", orc.check_posture_check, orc.POSTURE_CHECK_PATH),
        ("ORC-04", orc.check_pilot_live_validate, orc.PILOT_LIVE_PATH),
        ("ORC-05", orc.check_prod_profile_validate, orc.PROD_PROFILE_PATH),
        ("ORC-06", orc.check_recovery_validate, orc.RECOVERY_VALIDATE_PATH),
        ("ORC-07", orc.check_restore_drill, orc.RESTORE_DRILL_PATH),
        ("ORC-08", orc.check_live_soak_validate, orc.LIVE_SOAK_PATH),
        ("ORC-09", orc.check_easm_scan, orc.EASM_SCAN_PATH),
        ("ORC-10", orc.check_easm_history, orc.EASM_HISTORY_PATH),
        ("ORC-11", orc.check_tenant_isolation, orc.TENANT_ISOLATION_PATH),
    ]

    def test_pass_when_all_scripts_present(self):
        for check_id, fn, path in self._SCRIPT_CHECKS:
            r = fn(ROOT, "local", _make_read_fn({path}))
            self.assertEqual(r["status"], orc.PASS, f"{check_id} should PASS when present")

    def test_fail_when_all_scripts_absent(self):
        for check_id, fn, path in self._SCRIPT_CHECKS:
            r = fn(ROOT, "local", _make_read_fn(set()))
            self.assertEqual(r["status"], orc.FAIL, f"{check_id} should FAIL when absent")

    def test_fail_regardless_of_profile_for_scripts(self):
        for check_id, fn, path in self._SCRIPT_CHECKS:
            for profile in ("local", "staging", "production"):
                r = fn(ROOT, profile, _make_read_fn(set()))
                self.assertEqual(r["status"], orc.FAIL,
                                 f"{check_id} should FAIL at profile={profile}")

    def test_check_ids_correct(self):
        for check_id, fn, path in self._SCRIPT_CHECKS:
            r = fn(ROOT, "local", _make_read_fn({path}))
            self.assertEqual(r["check_id"], check_id)


# ---------------------------------------------------------------------------
# ORC-12  SOAK_6H_SCRIPT_PRESENT (WARN locally, FAIL staging/production)
# ---------------------------------------------------------------------------

class TestOrc12Soak6hScript(unittest.TestCase):

    def test_pass_when_present(self):
        fn = _make_read_fn({orc.SOAK_6H_SCRIPT_PATH})
        r = orc.check_soak_6h_script(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.PASS)

    def test_warn_local_when_absent(self):
        fn = _make_read_fn(set())
        r = orc.check_soak_6h_script(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.WARN)

    def test_fail_staging_when_absent(self):
        fn = _make_read_fn(set())
        r = orc.check_soak_6h_script(ROOT, "staging", fn)
        self.assertEqual(r["status"], orc.FAIL)

    def test_fail_production_when_absent(self):
        fn = _make_read_fn(set())
        r = orc.check_soak_6h_script(ROOT, "production", fn)
        self.assertEqual(r["status"], orc.FAIL)

    def test_check_id(self):
        fn = _make_read_fn(set())
        r = orc.check_soak_6h_script(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "ORC-12")


# ---------------------------------------------------------------------------
# ORC-13 / ORC-14 / ORC-15 — doc checks (WARN locally, FAIL staging/production)
# ---------------------------------------------------------------------------

class TestDocPresenceChecks(unittest.TestCase):

    _DOC_CHECKS = [
        ("ORC-13", orc.check_backup_recovery_doc, orc.BACKUP_RECOVERY_DOC),
        ("ORC-14", orc.check_operational_posture_doc, orc.OPERATIONAL_POSTURE_DOC),
        ("ORC-15", orc.check_prod_profile_doc, orc.PROD_PROFILE_DOC),
    ]

    def test_pass_when_doc_present(self):
        for check_id, fn, path in self._DOC_CHECKS:
            r = fn(ROOT, "local", _make_read_fn({path}))
            self.assertEqual(r["status"], orc.PASS, f"{check_id} should PASS when present")

    def test_warn_local_when_absent(self):
        for check_id, fn, path in self._DOC_CHECKS:
            r = fn(ROOT, "local", _make_read_fn(set()))
            self.assertEqual(r["status"], orc.WARN, f"{check_id} should WARN locally when absent")

    def test_fail_staging_when_absent(self):
        for check_id, fn, path in self._DOC_CHECKS:
            r = fn(ROOT, "staging", _make_read_fn(set()))
            self.assertEqual(r["status"], orc.FAIL, f"{check_id} should FAIL at staging")

    def test_fail_production_when_absent(self):
        for check_id, fn, path in self._DOC_CHECKS:
            r = fn(ROOT, "production", _make_read_fn(set()))
            self.assertEqual(r["status"], orc.FAIL, f"{check_id} should FAIL at production")


# ---------------------------------------------------------------------------
# ORC-16  RULE_REGISTRY_PRESENT
# ---------------------------------------------------------------------------

class TestOrc16RuleRegistry(unittest.TestCase):

    def test_pass_when_present(self):
        fn = _make_read_fn({orc.RULE_REGISTRY_PATH})
        r = orc.check_rule_registry(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.PASS)

    def test_fail_when_absent(self):
        fn = _make_read_fn(set())
        r = orc.check_rule_registry(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.FAIL)

    def test_fail_regardless_of_profile(self):
        fn = _make_read_fn(set())
        for profile in ("local", "staging", "production"):
            r = orc.check_rule_registry(ROOT, profile, fn)
            self.assertEqual(r["status"], orc.FAIL, f"profile={profile}")

    def test_check_id(self):
        fn = _make_read_fn({orc.RULE_REGISTRY_PATH})
        r = orc.check_rule_registry(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "ORC-16")


# ---------------------------------------------------------------------------
# Advisory A-01  PROD_COMPOSE_PRESENT
# ---------------------------------------------------------------------------

class TestAdvisoryProdCompose(unittest.TestCase):

    def test_info_when_present(self):
        fn = _make_read_fn({orc.PROD_COMPOSE_PATH})
        r = orc.advisory_prod_compose(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.INFO)
        self.assertTrue(r["evidence"]["exists"])

    def test_info_when_absent(self):
        fn = _make_read_fn(set())
        r = orc.advisory_prod_compose(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.INFO)
        self.assertFalse(r["evidence"]["exists"])

    def test_info_regardless_of_profile(self):
        fn = _make_read_fn(set())
        for profile in ("local", "staging", "production"):
            r = orc.advisory_prod_compose(ROOT, profile, fn)
            self.assertEqual(r["status"], orc.INFO, f"profile={profile}")

    def test_check_id(self):
        fn = _make_read_fn(set())
        r = orc.advisory_prod_compose(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "A-01")


# ---------------------------------------------------------------------------
# Advisory A-02  ENV_EXAMPLE_PRESENT
# ---------------------------------------------------------------------------

class TestAdvisoryEnvExample(unittest.TestCase):

    def test_info_when_present(self):
        fn = _make_read_fn({orc.ENV_EXAMPLE_PATH})
        r = orc.advisory_env_example(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.INFO)
        self.assertTrue(r["evidence"]["exists"])

    def test_info_when_absent(self):
        fn = _make_read_fn(set())
        r = orc.advisory_env_example(ROOT, "local", fn)
        self.assertEqual(r["status"], orc.INFO)
        self.assertFalse(r["evidence"]["exists"])

    def test_info_regardless_of_profile(self):
        fn = _make_read_fn(set())
        for profile in ("local", "staging", "production"):
            r = orc.advisory_env_example(ROOT, profile, fn)
            self.assertEqual(r["status"], orc.INFO, f"profile={profile}")

    def test_check_id(self):
        fn = _make_read_fn(set())
        r = orc.advisory_env_example(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "A-02")


# ---------------------------------------------------------------------------
# run_all — aggregate report
# ---------------------------------------------------------------------------

class TestRunAll(unittest.TestCase):

    def test_overall_pass_when_all_present(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        self.assertEqual(report["overall"], orc.PASS)

    def test_overall_fail_when_required_script_absent(self):
        # Remove the runbook — ORC-01 fails
        fn = _make_read_fn(set())
        report = orc.run_all(ROOT, "local", fn)
        self.assertEqual(report["overall"], orc.FAIL)

    def test_overall_warn_local_when_only_optional_absent(self):
        # Keep all ORC-01..11 + ORC-16 (always FAIL); remove only WARN-locally items
        fn = _make_read_fn({
            orc.RUNBOOK_PATH,
            orc.TOPIC_BOOTSTRAP_PATH,
            orc.POSTURE_CHECK_PATH,
            orc.PILOT_LIVE_PATH,
            orc.PROD_PROFILE_PATH,
            orc.RECOVERY_VALIDATE_PATH,
            orc.RESTORE_DRILL_PATH,
            orc.LIVE_SOAK_PATH,
            orc.EASM_SCAN_PATH,
            orc.EASM_HISTORY_PATH,
            orc.TENANT_ISOLATION_PATH,
            orc.RULE_REGISTRY_PATH,
            # ORC-12/13/14/15 absent → WARN locally
        })
        report = orc.run_all(ROOT, "local", fn)
        self.assertIn(report["overall"], (orc.WARN, orc.PASS))
        # Specifically should be WARN (4 items absent)
        self.assertEqual(report["overall"], orc.WARN)

    def test_overall_fail_production_when_optional_absent(self):
        # Same absent set but production profile → FAIL
        fn = _make_read_fn({
            orc.RUNBOOK_PATH,
            orc.TOPIC_BOOTSTRAP_PATH,
            orc.POSTURE_CHECK_PATH,
            orc.PILOT_LIVE_PATH,
            orc.PROD_PROFILE_PATH,
            orc.RECOVERY_VALIDATE_PATH,
            orc.RESTORE_DRILL_PATH,
            orc.LIVE_SOAK_PATH,
            orc.EASM_SCAN_PATH,
            orc.EASM_HISTORY_PATH,
            orc.TENANT_ISOLATION_PATH,
            orc.RULE_REGISTRY_PATH,
        })
        report = orc.run_all(ROOT, "production", fn)
        self.assertEqual(report["overall"], orc.FAIL)

    def test_summary_counts_correct(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        s = report["summary"]
        self.assertEqual(s["total"], 16)
        self.assertEqual(s["advisory"], 2)
        self.assertEqual(s["failed"], 0)
        self.assertEqual(s["passed"], 16)

    def test_checks_list_length(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        # 16 primary + 2 advisory
        self.assertEqual(len(report["checks"]), 18)

    def test_required_report_fields(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        for field in ("task", "validator", "profile", "timestamp", "overall",
                      "summary", "missing_artifacts", "checks"):
            self.assertIn(field, report)

    def test_task_field_value(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        self.assertEqual(report["task"], "ENTERPRISE-041")

    def test_validator_field_value(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        self.assertEqual(report["validator"], "xdr_operator_readiness_check")

    def test_missing_artifacts_empty_on_full_pass(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        self.assertEqual(report["missing_artifacts"], [])

    def test_missing_artifacts_populated_on_fail(self):
        fn = _make_read_fn(set())
        report = orc.run_all(ROOT, "local", fn)
        self.assertGreater(len(report["missing_artifacts"]), 0)

    def test_production_profile_stricter_than_local(self):
        fn = _make_read_fn({
            orc.RUNBOOK_PATH,
            orc.TOPIC_BOOTSTRAP_PATH,
            orc.POSTURE_CHECK_PATH,
            orc.PILOT_LIVE_PATH,
            orc.PROD_PROFILE_PATH,
            orc.RECOVERY_VALIDATE_PATH,
            orc.RESTORE_DRILL_PATH,
            orc.LIVE_SOAK_PATH,
            orc.EASM_SCAN_PATH,
            orc.EASM_HISTORY_PATH,
            orc.TENANT_ISOLATION_PATH,
            orc.RULE_REGISTRY_PATH,
        })
        local_r = orc.run_all(ROOT, "local", fn)
        prod_r = orc.run_all(ROOT, "production", fn)
        self.assertGreaterEqual(prod_r["summary"]["failed"],
                                local_r["summary"]["failed"])


# ---------------------------------------------------------------------------
# JSON serialisability
# ---------------------------------------------------------------------------

class TestJsonSerialisability(unittest.TestCase):

    def test_full_pass_report_is_serialisable(self):
        report = orc.run_all(ROOT, "local", _all_present_read_fn())
        s = json.dumps(report)
        self.assertIsInstance(s, str)
        decoded = json.loads(s)
        self.assertEqual(decoded["overall"], orc.PASS)

    def test_full_fail_report_is_serialisable(self):
        fn = _make_read_fn(set())
        report = orc.run_all(ROOT, "production", fn)
        s = json.dumps(report)
        decoded = json.loads(s)
        self.assertEqual(decoded["overall"], orc.FAIL)


if __name__ == "__main__":
    unittest.main()
