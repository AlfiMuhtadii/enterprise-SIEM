"""
Tests for scripts/xdr_restore_drill.py — ENTERPRISE-037.

All tests are deterministic and offline (no real DB, no subprocess side-effects).
"""
import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch, call

# ---------------------------------------------------------------------------
# Bootstrap: make project scripts importable
# ---------------------------------------------------------------------------
_SCRIPTS = Path(__file__).resolve().parent.parent.parent / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

import xdr_restore_drill as drill


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _full_env() -> dict:
    return {
        "DB_HOST": "localhost",
        "DB_PORT": "5432",
        "DB_DATABASE": "xdr_db",
        "DB_USERNAME": "xdr_user",
        "DB_PASSWORD": "s3cr3t",
    }


def _args(execute=False, target_db="", output="", quiet=True) -> object:
    ns = types.SimpleNamespace()
    ns.execute = execute
    ns.target_db = target_db
    ns.output = output
    ns.quiet = quiet
    return ns


# ---------------------------------------------------------------------------
# TestEnvVarCheck
# ---------------------------------------------------------------------------

class TestEnvVarCheck(unittest.TestCase):
    def test_passes_with_all_vars(self):
        r = drill.check_env_vars(_full_env())
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "PRE-01")

    def test_fails_when_password_missing(self):
        env = _full_env()
        del env["DB_PASSWORD"]
        r = drill.check_env_vars(env)
        self.assertEqual(r["status"], "FAIL")
        self.assertIn("DB_PASSWORD", r["detail"])

    def test_fails_when_host_missing(self):
        env = _full_env()
        del env["DB_HOST"]
        r = drill.check_env_vars(env)
        self.assertEqual(r["status"], "FAIL")
        self.assertIn("DB_HOST", r["detail"])

    def test_fails_when_empty_value(self):
        env = _full_env()
        env["DB_USERNAME"] = ""
        r = drill.check_env_vars(env)
        self.assertEqual(r["status"], "FAIL")

    def test_fails_on_all_missing(self):
        r = drill.check_env_vars({})
        self.assertEqual(r["status"], "FAIL")

    def test_remediation_present_on_fail(self):
        r = drill.check_env_vars({})
        self.assertIn("DB_HOST", r["remediation"])


# ---------------------------------------------------------------------------
# TestToolCheck
# ---------------------------------------------------------------------------

class TestToolCheck(unittest.TestCase):
    def test_passes_when_tool_found(self):
        with patch("shutil.which", return_value="/usr/bin/pg_dump"):
            r = drill.check_tool("PRE-02", "pg_dump")
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "PRE-02")

    def test_fails_when_tool_missing(self):
        with patch("shutil.which", return_value=None):
            r = drill.check_tool("PRE-02", "pg_dump")
        self.assertEqual(r["status"], "FAIL")

    def test_advisory_tool_warns_when_missing(self):
        with patch("shutil.which", return_value=None):
            r = drill.check_tool("PRE-05", "dropdb", advisory=True)
        self.assertEqual(r["status"], "WARN")

    def test_advisory_tool_passes_when_found(self):
        with patch("shutil.which", return_value="/usr/bin/dropdb"):
            r = drill.check_tool("PRE-05", "dropdb", advisory=True)
        self.assertEqual(r["status"], "PASS")

    def test_tool_name_in_detail(self):
        with patch("shutil.which", return_value=None):
            r = drill.check_tool("PRE-03", "pg_restore")
        self.assertIn("pg_restore", r["detail"])


# ---------------------------------------------------------------------------
# TestTargetIsolation
# ---------------------------------------------------------------------------

class TestTargetIsolation(unittest.TestCase):
    def test_passes_when_different(self):
        r = drill.check_target_isolation("xdr_db", "xdr_db_restore_drill")
        self.assertEqual(r["status"], "PASS")

    def test_fails_when_same(self):
        r = drill.check_target_isolation("xdr_db", "xdr_db")
        self.assertEqual(r["status"], "FAIL")

    def test_fails_when_same_with_whitespace(self):
        r = drill.check_target_isolation("xdr_db", " xdr_db ")
        self.assertEqual(r["status"], "FAIL")

    def test_step_id_is_pre06(self):
        r = drill.check_target_isolation("a", "b")
        self.assertEqual(r["step_id"], "PRE-06")

    def test_remediation_present_on_fail(self):
        r = drill.check_target_isolation("x", "x")
        self.assertIn("--target-db", r["remediation"])


# ---------------------------------------------------------------------------
# TestBuildPlan
# ---------------------------------------------------------------------------

class TestBuildPlan(unittest.TestCase):
    def _plan(self):
        return drill.build_plan(
            _full_env(), "xdr_db_restore_drill", Path("/tmp/dump.dump")
        )

    def test_plan_has_source_db(self):
        self.assertEqual(self._plan()["source_db"], "xdr_db")

    def test_plan_has_target_db(self):
        self.assertEqual(self._plan()["target_db"], "xdr_db_restore_drill")

    def test_plan_has_steps_list(self):
        p = self._plan()
        self.assertIsInstance(p["steps"], list)
        self.assertGreater(len(p["steps"]), 3)

    def test_plan_includes_pg_dump(self):
        p = self._plan()
        self.assertTrue(any("pg_dump" in s for s in p["steps"]))

    def test_plan_includes_createdb(self):
        p = self._plan()
        self.assertTrue(any("createdb" in s for s in p["steps"]))

    def test_plan_includes_pg_restore(self):
        p = self._plan()
        self.assertTrue(any("pg_restore" in s for s in p["steps"]))

    def test_plan_includes_dropdb_cleanup(self):
        p = self._plan()
        self.assertTrue(any("dropdb" in s for s in p["steps"]))

    def test_plan_dump_path(self):
        p = self._plan()
        self.assertIn("dump.dump", p["dump_path"])

    def test_plan_host_from_env(self):
        p = self._plan()
        self.assertEqual(p["host"], "localhost")


# ---------------------------------------------------------------------------
# TestDryRun (default mode)
# ---------------------------------------------------------------------------

class TestDryRun(unittest.TestCase):
    def _run(self, execute=False, target_db=""):
        env = _full_env()
        args = _args(execute=execute, target_db=target_db)
        with patch("shutil.which", return_value="/usr/bin/pg_dump"):
            steps, plan = drill.run_drill(args, root=Path("/fake"), env=env)
        return steps, plan

    def test_dry_run_returns_preflight_steps(self):
        steps, _ = self._run()
        step_ids = [s["step_id"] for s in steps]
        self.assertIn("PRE-01", step_ids)
        self.assertIn("PRE-02", step_ids)
        self.assertIn("PRE-06", step_ids)

    def test_dry_run_no_dump_step(self):
        steps, _ = self._run()
        self.assertFalse(any(s["step_id"] == "DUMP" for s in steps))

    def test_dry_run_no_restore_step(self):
        steps, _ = self._run()
        self.assertFalse(any(s["step_id"] == "RESTORE" for s in steps))

    def test_dry_run_plan_has_target(self):
        _, plan = self._run()
        self.assertIn("restore_drill", plan["target_db"])

    def test_dry_run_custom_target_db(self):
        _, plan = self._run(target_db="my_test_db")
        self.assertEqual(plan["target_db"], "my_test_db")

    def test_dry_run_overall_pass_when_tools_present(self):
        steps, plan = self._run()
        report = drill.build_report(steps, plan, _args(), "t1", "t2")
        self.assertEqual(report["overall"], "PASS")
        self.assertEqual(report["mode"], "dry-run")

    def test_dry_run_fails_when_tool_missing(self):
        env = _full_env()
        args = _args()
        with patch("shutil.which", return_value=None):
            steps, plan = drill.run_drill(args, root=Path("/fake"), env=env)
        report = drill.build_report(steps, plan, args, "t1", "t2")
        self.assertEqual(report["overall"], "FAIL")

    def test_dry_run_fails_when_env_missing(self):
        args = _args()
        with patch("shutil.which", return_value="/usr/bin/pg_dump"):
            steps, plan = drill.run_drill(args, root=Path("/fake"), env={})
        self.assertTrue(any(s["status"] == "FAIL" for s in steps))


# ---------------------------------------------------------------------------
# TestExecuteAbort (pre-flight fails → abort before any DB ops)
# ---------------------------------------------------------------------------

class TestExecuteAbort(unittest.TestCase):
    def test_aborts_when_preflight_fails(self):
        env = _full_env()
        args = _args(execute=True)
        # Make pg_dump missing → PRE-02 FAIL
        with patch("shutil.which", return_value=None):
            steps, plan = drill.run_drill(args, root=Path("/fake"), env=env)
        step_ids = [s["step_id"] for s in steps]
        self.assertIn("ABORT", step_ids)
        self.assertNotIn("DUMP", step_ids)

    def test_abort_step_is_fail(self):
        args = _args(execute=True)
        with patch("shutil.which", return_value=None):
            steps, _ = drill.run_drill(args, root=Path("/fake"), env=_full_env())
        abort = next(s for s in steps if s["step_id"] == "ABORT")
        self.assertEqual(abort["status"], "FAIL")

    def test_aborts_when_same_db_name(self):
        env = _full_env()
        args = _args(execute=True, target_db="xdr_db")  # same as source
        with patch("shutil.which", return_value="/usr/bin/pg_dump"):
            steps, _ = drill.run_drill(args, root=Path("/fake"), env=env)
        self.assertTrue(any(s["step_id"] == "ABORT" for s in steps))


# ---------------------------------------------------------------------------
# TestRunDump
# ---------------------------------------------------------------------------

class TestRunDump(unittest.TestCase):
    def _fake_dump_path(self, exists=True, size=1024):
        """Return a MagicMock that behaves like a Path for run_dump."""
        p = MagicMock(spec=Path)
        p.__str__ = lambda self: "/fake/dump.dump"
        p.exists.return_value = exists
        p.stat.return_value = MagicMock(st_size=size)
        return p

    def test_passes_on_exit_zero(self):
        env = _full_env()
        dump_path = self._fake_dump_path(exists=True, size=1024)
        with patch.object(drill, "_run", return_value=(0, "", "")):
            r = drill.run_dump(env, dump_path, {})
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "DUMP")

    def test_fails_on_nonzero_exit(self):
        env = _full_env()
        dump_path = self._fake_dump_path(exists=False)
        with patch.object(drill, "_run", return_value=(1, "", "pg_dump: error")):
            r = drill.run_dump(env, dump_path, {})
        self.assertEqual(r["status"], "FAIL")

    def test_fails_when_dump_file_absent(self):
        env = _full_env()
        dump_path = self._fake_dump_path(exists=False)
        with patch.object(drill, "_run", return_value=(0, "", "")):
            r = drill.run_dump(env, dump_path, {})
        self.assertEqual(r["status"], "FAIL")


# ---------------------------------------------------------------------------
# TestRunCreateDb
# ---------------------------------------------------------------------------

class TestRunCreateDb(unittest.TestCase):
    def test_passes_on_exit_zero(self):
        with patch.object(drill, "_run", return_value=(0, "", "")):
            r = drill.run_createdb(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "RESTORE-CREATE")

    def test_fails_on_nonzero_exit(self):
        with patch.object(drill, "_run", return_value=(1, "", "already exists")):
            r = drill.run_createdb(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "FAIL")


# ---------------------------------------------------------------------------
# TestRunRestore
# ---------------------------------------------------------------------------

class TestRunRestore(unittest.TestCase):
    def test_passes_on_exit_zero(self):
        with patch.object(drill, "_run", return_value=(0, "", "")):
            r = drill.run_restore(_full_env(), "xdr_drill", Path("/tmp/x.dump"), {})
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "RESTORE")

    def test_passes_on_exit_one_warnings(self):
        # pg_restore exits 1 for warnings (missing roles etc.) — treated as PASS
        with patch.object(drill, "_run", return_value=(1, "", "WARNING: role not found")):
            r = drill.run_restore(_full_env(), "xdr_drill", Path("/tmp/x.dump"), {})
        self.assertEqual(r["status"], "PASS")

    def test_fails_on_exit_two(self):
        with patch.object(drill, "_run", return_value=(2, "", "fatal error")):
            r = drill.run_restore(_full_env(), "xdr_drill", Path("/tmp/x.dump"), {})
        self.assertEqual(r["status"], "FAIL")


# ---------------------------------------------------------------------------
# TestRunDropDb
# ---------------------------------------------------------------------------

class TestRunDropDb(unittest.TestCase):
    def test_passes_on_exit_zero(self):
        with patch.object(drill, "_run", return_value=(0, "", "")):
            r = drill.run_dropdb(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "CLEANUP")

    def test_warns_on_nonzero_exit(self):
        with patch.object(drill, "_run", return_value=(1, "", "does not exist")):
            r = drill.run_dropdb(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "WARN")


# ---------------------------------------------------------------------------
# TestPostRestoreChecks
# ---------------------------------------------------------------------------

class TestPostRestoreChecks(unittest.TestCase):
    def test_migrations_passes_when_table_found(self):
        with patch.object(drill, "_psql_query", return_value=(0, "1")):
            r = drill.check_migrations_table(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "POST-01")

    def test_migrations_fails_when_table_missing(self):
        with patch.object(drill, "_psql_query", return_value=(0, "0")):
            r = drill.check_migrations_table(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "FAIL")

    def test_migrations_fails_on_psql_error(self):
        with patch.object(drill, "_psql_query", return_value=(1, "")):
            r = drill.check_migrations_table(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "FAIL")

    def test_append_only_passes_when_all_present(self):
        with patch.object(drill, "_psql_query", return_value=(0, "1")):
            r = drill.check_append_only_tables(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "POST-02")

    def test_append_only_fails_when_table_missing(self):
        def mock_query(env, db, sql, pg_env):
            if "security_alerts" in sql:
                return 0, "0"
            return 0, "1"
        with patch.object(drill, "_psql_query", side_effect=mock_query):
            r = drill.check_append_only_tables(_full_env(), "xdr_drill", {})
        self.assertEqual(r["status"], "FAIL")
        self.assertIn("security_alerts", r["detail"])

    def test_rule_registry_passes_with_correct_counts(self):
        import tempfile, json as _json
        root = Path(tempfile.mkdtemp())
        reg_dir = root / "docs" / "detection" / "rules"
        reg_dir.mkdir(parents=True)
        rules = [{"id": f"R{i:03}", "status": "shadow"} for i in range(121)]
        rules += [{"id": f"A{i:02}", "status": "staged_active"} for i in range(12)]
        (reg_dir / "registry.v1.json").write_text(
            _json.dumps({"rules": rules}), encoding="utf-8"
        )
        r = drill.check_rule_registry(root)
        self.assertEqual(r["status"], "PASS")
        self.assertEqual(r["step_id"], "POST-03")

    def test_rule_registry_warns_when_count_wrong(self):
        import tempfile, json as _json
        root = Path(tempfile.mkdtemp())
        reg_dir = root / "docs" / "detection" / "rules"
        reg_dir.mkdir(parents=True)
        rules = [{"id": "R001", "status": "shadow"}]
        (reg_dir / "registry.v1.json").write_text(
            _json.dumps({"rules": rules}), encoding="utf-8"
        )
        r = drill.check_rule_registry(root)
        self.assertEqual(r["status"], "WARN")

    def test_rule_registry_warns_when_file_missing(self):
        root = Path("/nonexistent_root_xdr_test")
        r = drill.check_rule_registry(root)
        self.assertEqual(r["status"], "WARN")

    def test_tenant_null_audit_skipped_when_no_artisan(self):
        root = Path("/nonexistent_root_xdr_test")
        r = drill.check_tenant_null_audit(root)
        self.assertIn(r["status"], ("INFO", "WARN"))
        self.assertEqual(r["step_id"], "POST-04")


# ---------------------------------------------------------------------------
# TestBuildReport
# ---------------------------------------------------------------------------

class TestBuildReport(unittest.TestCase):
    def _steps_all_pass(self):
        return [
            {"step_id": "PRE-01", "name": "env", "status": "PASS", "detail": "", "remediation": ""},
            {"step_id": "PRE-02", "name": "pg_dump", "status": "PASS", "detail": "", "remediation": ""},
        ]

    def _steps_with_fail(self):
        steps = self._steps_all_pass()
        steps.append({"step_id": "DUMP", "name": "dump", "status": "FAIL", "detail": "", "remediation": ""})
        return steps

    def _plan(self):
        return drill.build_plan(_full_env(), "xdr_db_restore_drill", Path("/tmp/x.dump"))

    def test_overall_pass_when_no_fail(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(), "t1", "t2")
        self.assertEqual(r["overall"], "PASS")

    def test_overall_fail_when_any_fail(self):
        r = drill.build_report(self._steps_with_fail(), self._plan(), _args(), "t1", "t2")
        self.assertEqual(r["overall"], "FAIL")

    def test_task_is_enterprise037(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(), "t1", "t2")
        self.assertEqual(r["task"], "ENTERPRISE-037")

    def test_mode_dry_run(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(execute=False), "t1", "t2")
        self.assertEqual(r["mode"], "dry-run")

    def test_mode_execute(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(execute=True), "t1", "t2")
        self.assertEqual(r["mode"], "execute")

    def test_counts_correct(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(), "t1", "t2")
        self.assertEqual(r["counts"]["pass"], 2)
        self.assertEqual(r["counts"]["fail"], 0)
        self.assertEqual(r["counts"]["total"], 2)

    def test_report_has_plan(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(), "t1", "t2")
        self.assertIn("plan", r)
        self.assertIn("steps", r["plan"])

    def test_report_has_source_and_target(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(), "t1", "t2")
        self.assertEqual(r["source_db"], "xdr_db")
        self.assertIn("restore_drill", r["target_db"])

    def test_status_line_format(self):
        r = drill.build_report(self._steps_all_pass(), self._plan(), _args(), "t1", "t2")
        self.assertIn("PASS=", r["status_line"])
        self.assertIn("FAIL=", r["status_line"])
        self.assertIn("WARN=", r["status_line"])


# ---------------------------------------------------------------------------
# TestMainExitCode
# ---------------------------------------------------------------------------

class TestMainExitCode(unittest.TestCase):
    def test_exits_zero_on_pass_dry_run(self):
        args = _args(execute=False, quiet=True)
        with patch("shutil.which", return_value="/usr/bin/pg_dump"):
            rc = drill.main(args, root=Path("/fake"), env=_full_env())
        self.assertEqual(rc, 0)

    def test_exits_one_on_fail(self):
        args = _args(execute=False, quiet=True)
        with patch("shutil.which", return_value=None):
            rc = drill.main(args, root=Path("/fake"), env=_full_env())
        self.assertEqual(rc, 1)

    def test_output_writes_json(self):
        import tempfile
        tmp = Path(tempfile.mktemp(suffix=".json"))
        args = _args(execute=False, quiet=True, output=str(tmp))
        with patch("shutil.which", return_value="/usr/bin/pg_dump"):
            drill.main(args, root=Path("/fake"), env=_full_env())
        self.assertTrue(tmp.exists())
        data = json.loads(tmp.read_text())
        self.assertEqual(data["task"], "ENTERPRISE-037")
        tmp.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# TestSafetyInvariants
# ---------------------------------------------------------------------------

class TestSafetyInvariants(unittest.TestCase):
    """Verify that dry-run never touches a real DB subprocess path."""

    def test_dry_run_never_calls_subprocess(self):
        args = _args(execute=False, quiet=True)
        env = _full_env()
        with patch("shutil.which", return_value="/usr/bin/pg_dump"), \
             patch("subprocess.run") as mock_sub:
            drill.run_drill(args, root=Path("/fake"), env=env)
        mock_sub.assert_not_called()

    def test_target_db_defaults_to_source_plus_suffix(self):
        args = _args(execute=False)
        env = _full_env()
        with patch("shutil.which", return_value="/usr/bin/pg_dump"):
            _, plan = drill.run_drill(args, root=Path("/fake"), env=env)
        self.assertEqual(plan["target_db"], "xdr_db_restore_drill")

    def test_default_mode_is_dry_run(self):
        ns = drill._parse_args([])
        self.assertFalse(ns.execute)

    def test_execute_requires_explicit_flag(self):
        ns = drill._parse_args(["--execute"])
        self.assertTrue(ns.execute)

    def test_active_domain_boundaries_unchanged(self):
        # Confirm the script does not reference promotion logic
        src = Path(__file__).resolve().parent.parent.parent / "scripts" / "xdr_restore_drill.py"
        text = src.read_text(encoding="utf-8")
        self.assertNotIn("ACTIVE_ALLOWLIST", text)
        # No promotion-triggering code — "staged_active" only allowed in string literals
        # (advisory registry check), never in logic that would expand active domain scope
        self.assertNotIn("promote_to_active", text)
        self.assertNotIn("XDR_CORRELATION_SCOPE", text)

    def test_no_append_only_mutation_in_script(self):
        src = Path(__file__).resolve().parent.parent.parent / "scripts" / "xdr_restore_drill.py"
        text = src.read_text(encoding="utf-8")
        for bad in ("INSERT INTO", "UPDATE ", "DELETE FROM", "TRUNCATE "):
            self.assertNotIn(bad, text, f"Found unsafe SQL: {bad!r}")


if __name__ == "__main__":
    unittest.main()
