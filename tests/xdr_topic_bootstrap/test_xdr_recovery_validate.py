"""Tests for BACKLOG-DR-027: Backup / Restore / Recovery Readiness Validation.

Covers:
- R-01 PG_DSN_CONFIGURED: all keys present, partial missing, all missing
- R-02 ENV_EXAMPLE_PRESENT: file present / absent; profile-based severity
- R-03 DOCKER_COMPOSE_PRESENT: present / absent; always FAIL when missing
- R-04 RULE_REGISTRY_INTEGRITY: correct counts, wrong count, wrong staged_active,
        missing file, invalid JSON
- R-05 MIGRATION_FILES_PRESENT: enough files, not enough, directory missing
- R-06 KEY_REPORTS_PRESENT: some present (PASS), none present (WARN)
- R-07 OPERATIONAL_DOCS_PRESENT: all present, one missing
- R-08 BACKUP_DOC_PRESENT: present / absent; profile-based severity
- Advisory RPO/RTO items: always INFO, all three profiles, A-04 pg_dump note
- run_checks: returns 12 items; no FAIL for a well-structured project root
- build_report: overall PASS/FAIL, counts, required fields
- CLI: exit 0 on PASS, exit 1 on FAIL, --output writes JSON, --dry-run flag
"""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_recovery_validate as rv  # noqa: E402


# ---------------------------------------------------------------------------
# Helpers — build minimal fake project trees in a temp dir
# ---------------------------------------------------------------------------

def _make_root(
    *,
    env_lines: list[str] | None = None,
    has_env_example: bool = True,
    has_docker_compose: bool = True,
    registry_rules: int = rv.EXPECTED_RULE_COUNT,
    registry_staged: int = rv.EXPECTED_STAGED_ACTIVE,
    registry_json: str | None = None,
    migration_count: int = rv.MIN_MIGRATION_COUNT,
    key_reports: list[str] | None = None,
    operational_docs: list[str] | None = None,
    has_backup_doc: bool = True,
) -> Path:
    d = Path(tempfile.mkdtemp())

    # .env
    lines = env_lines if env_lines is not None else [
        "DB_HOST=127.0.0.1",
        "DB_PORT=5432",
        "DB_DATABASE=detector",
        "DB_USERNAME=postgres",
    ]
    (d / ".env").write_text("\n".join(lines), encoding="utf-8")

    # .env.example
    if has_env_example:
        (d / ".env.example").write_text("DB_HOST=\nDB_PORT=\n", encoding="utf-8")

    # docker-compose.yml
    if has_docker_compose:
        (d / "docker-compose.yml").write_text("version: '3'\n", encoding="utf-8")

    # rule registry
    reg_dir = d / "docs" / "detection" / "rules"
    reg_dir.mkdir(parents=True, exist_ok=True)
    if registry_json is not None:
        (reg_dir / "registry.v1.json").write_text(registry_json, encoding="utf-8")
    else:
        rules = (
            [{"status": "staged_active"} for _ in range(registry_staged)]
            + [{"status": "shadow"} for _ in range(registry_rules - registry_staged)]
        )
        (reg_dir / "registry.v1.json").write_text(
            json.dumps({"rules": rules}), encoding="utf-8"
        )

    # migrations
    mig_dir = d / "database" / "migrations"
    mig_dir.mkdir(parents=True, exist_ok=True)
    for i in range(migration_count):
        (mig_dir / f"2024_{i:04d}_migration.php").write_text("<?php\n", encoding="utf-8")

    # key reports
    reports_dir = d / "reports"
    reports_dir.mkdir(exist_ok=True)
    for r in (key_reports or []):
        (reports_dir / r).write_text("{}", encoding="utf-8")

    # operational docs
    ops_dir = d / "docs" / "operations"
    val_dir = d / "docs" / "validation"
    ops_dir.mkdir(parents=True, exist_ok=True)
    val_dir.mkdir(parents=True, exist_ok=True)
    if operational_docs is None:
        operational_docs = [
            "docs/operations/OPERATIONAL_POSTURE.md",
            "docs/operations/PRODUCTION_RUNTIME_PROFILE.md",
            "docs/validation/VALIDATION_BASELINES.md",
        ]
    for rel in operational_docs:
        path = d / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("# doc\n", encoding="utf-8")
    # registry counts as an operational doc too
    # (already created above)

    # backup doc
    if has_backup_doc:
        (ops_dir / "BACKUP_RESTORE_RECOVERY.md").write_text("# backup\n", encoding="utf-8")

    return d


def _good_env() -> dict[str, str]:
    return {
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "5432",
        "DB_DATABASE": "detector",
        "DB_USERNAME": "postgres",
    }


# ---------------------------------------------------------------------------
# R-01: PG_DSN_CONFIGURED
# ---------------------------------------------------------------------------

class TestCheckPgDsn(unittest.TestCase):
    def test_all_keys_present_passes(self):
        r = rv.check_pg_dsn(_good_env(), "local")
        self.assertEqual(r["status"], rv.PASS)
        self.assertEqual(r["check_id"], "R-01")

    def test_one_key_missing_warns_local(self):
        env = {k: v for k, v in _good_env().items() if k != "DB_DATABASE"}
        r = rv.check_pg_dsn(env, "local")
        self.assertEqual(r["status"], rv.WARN)

    def test_one_key_missing_fails_staging(self):
        env = {k: v for k, v in _good_env().items() if k != "DB_DATABASE"}
        r = rv.check_pg_dsn(env, "staging")
        self.assertEqual(r["status"], rv.FAIL)

    def test_one_key_missing_fails_production(self):
        env = {k: v for k, v in _good_env().items() if k != "DB_HOST"}
        r = rv.check_pg_dsn(env, "production")
        self.assertEqual(r["status"], rv.FAIL)

    def test_empty_env_warns_local(self):
        r = rv.check_pg_dsn({}, "local")
        self.assertEqual(r["status"], rv.WARN)

    def test_empty_env_fails_staging(self):
        r = rv.check_pg_dsn({}, "staging")
        self.assertEqual(r["status"], rv.FAIL)


# ---------------------------------------------------------------------------
# R-02: ENV_EXAMPLE_PRESENT
# ---------------------------------------------------------------------------

class TestCheckEnvExample(unittest.TestCase):
    def test_present_passes(self):
        root = _make_root()
        r = rv.check_env_example_present(root, "local")
        self.assertEqual(r["status"], rv.PASS)

    def test_absent_warns_local(self):
        root = _make_root(has_env_example=False)
        r = rv.check_env_example_present(root, "local")
        self.assertEqual(r["status"], rv.WARN)

    def test_absent_fails_staging(self):
        root = _make_root(has_env_example=False)
        r = rv.check_env_example_present(root, "staging")
        self.assertEqual(r["status"], rv.FAIL)

    def test_absent_fails_production(self):
        root = _make_root(has_env_example=False)
        r = rv.check_env_example_present(root, "production")
        self.assertEqual(r["status"], rv.FAIL)


# ---------------------------------------------------------------------------
# R-03: DOCKER_COMPOSE_PRESENT
# ---------------------------------------------------------------------------

class TestCheckDockerCompose(unittest.TestCase):
    def test_present_passes(self):
        root = _make_root()
        r = rv.check_docker_compose_present(root, "local")
        self.assertEqual(r["status"], rv.PASS)

    def test_absent_fails_local(self):
        root = _make_root(has_docker_compose=False)
        r = rv.check_docker_compose_present(root, "local")
        self.assertEqual(r["status"], rv.FAIL)

    def test_absent_fails_production(self):
        root = _make_root(has_docker_compose=False)
        r = rv.check_docker_compose_present(root, "production")
        self.assertEqual(r["status"], rv.FAIL)


# ---------------------------------------------------------------------------
# R-04: RULE_REGISTRY_INTEGRITY
# ---------------------------------------------------------------------------

class TestCheckRuleRegistry(unittest.TestCase):
    def test_correct_counts_passes(self):
        root = _make_root()
        r = rv.check_rule_registry_integrity(root, "local")
        self.assertEqual(r["status"], rv.PASS)
        self.assertIn(str(rv.EXPECTED_RULE_COUNT), r["detail"])

    def test_wrong_total_count_fails(self):
        root = _make_root(registry_rules=100, registry_staged=rv.EXPECTED_STAGED_ACTIVE)
        r = rv.check_rule_registry_integrity(root, "local")
        self.assertEqual(r["status"], rv.FAIL)

    def test_wrong_staged_active_fails(self):
        root = _make_root(registry_staged=5)
        r = rv.check_rule_registry_integrity(root, "local")
        self.assertEqual(r["status"], rv.FAIL)

    def test_missing_file_fails(self):
        root = _make_root()
        (root / "docs" / "detection" / "rules" / "registry.v1.json").unlink()
        r = rv.check_rule_registry_integrity(root, "local")
        self.assertEqual(r["status"], rv.FAIL)

    def test_invalid_json_fails(self):
        root = _make_root(registry_json="not-valid-json{{{")
        r = rv.check_rule_registry_integrity(root, "local")
        self.assertEqual(r["status"], rv.FAIL)

    def test_empty_rules_list_fails(self):
        root = _make_root(registry_json='{"rules": []}')
        r = rv.check_rule_registry_integrity(root, "local")
        self.assertEqual(r["status"], rv.FAIL)


# ---------------------------------------------------------------------------
# R-05: MIGRATION_FILES_PRESENT
# ---------------------------------------------------------------------------

class TestCheckMigrationFiles(unittest.TestCase):
    def test_enough_files_passes(self):
        root = _make_root(migration_count=rv.MIN_MIGRATION_COUNT)
        r = rv.check_migration_files_present(root, "local")
        self.assertEqual(r["status"], rv.PASS)
        self.assertIn(str(rv.MIN_MIGRATION_COUNT), r["detail"])

    def test_too_few_fails(self):
        root = _make_root(migration_count=rv.MIN_MIGRATION_COUNT - 1)
        r = rv.check_migration_files_present(root, "local")
        self.assertEqual(r["status"], rv.FAIL)

    def test_directory_missing_fails(self):
        root = _make_root(migration_count=0)
        import shutil
        shutil.rmtree(root / "database" / "migrations")
        r = rv.check_migration_files_present(root, "local")
        self.assertEqual(r["status"], rv.FAIL)


# ---------------------------------------------------------------------------
# R-06: KEY_REPORTS_PRESENT
# ---------------------------------------------------------------------------

class TestCheckKeyReports(unittest.TestCase):
    def test_one_report_present_passes(self):
        root = _make_root(key_reports=["xdr_contract_validation.json"])
        r = rv.check_key_reports_present(root, "local")
        self.assertEqual(r["status"], rv.PASS)

    def test_all_reports_present_passes(self):
        root = _make_root(key_reports=rv.KEY_REPORTS)
        r = rv.check_key_reports_present(root, "local")
        self.assertEqual(r["status"], rv.PASS)
        self.assertIn("4/4", r["detail"])

    def test_no_reports_warns_all_profiles(self):
        for profile in ("local", "staging", "production"):
            root = _make_root(key_reports=[])
            r = rv.check_key_reports_present(root, profile)
            self.assertEqual(r["status"], rv.WARN, f"profile={profile}")


# ---------------------------------------------------------------------------
# R-07: OPERATIONAL_DOCS_PRESENT
# ---------------------------------------------------------------------------

class TestCheckOperationalDocs(unittest.TestCase):
    def _full_root(self):
        return _make_root(operational_docs=[
            "docs/operations/OPERATIONAL_POSTURE.md",
            "docs/operations/PRODUCTION_RUNTIME_PROFILE.md",
            "docs/validation/VALIDATION_BASELINES.md",
        ])

    def test_all_present_passes(self):
        root = self._full_root()
        r = rv.check_operational_docs_present(root, "local")
        self.assertEqual(r["status"], rv.PASS)

    def test_one_missing_fails(self):
        root = self._full_root()
        (root / "docs" / "operations" / "OPERATIONAL_POSTURE.md").unlink()
        r = rv.check_operational_docs_present(root, "local")
        self.assertEqual(r["status"], rv.FAIL)


# ---------------------------------------------------------------------------
# R-08: BACKUP_DOC_PRESENT
# ---------------------------------------------------------------------------

class TestCheckBackupDoc(unittest.TestCase):
    def test_present_passes(self):
        root = _make_root()
        r = rv.check_backup_doc_present(root, "local")
        self.assertEqual(r["status"], rv.PASS)

    def test_absent_warns_local(self):
        root = _make_root(has_backup_doc=False)
        r = rv.check_backup_doc_present(root, "local")
        self.assertEqual(r["status"], rv.WARN)

    def test_absent_fails_staging(self):
        root = _make_root(has_backup_doc=False)
        r = rv.check_backup_doc_present(root, "staging")
        self.assertEqual(r["status"], rv.FAIL)

    def test_absent_fails_production(self):
        root = _make_root(has_backup_doc=False)
        r = rv.check_backup_doc_present(root, "production")
        self.assertEqual(r["status"], rv.FAIL)


# ---------------------------------------------------------------------------
# Advisory RPO/RTO items
# ---------------------------------------------------------------------------

class TestAdvisoryRpoRto(unittest.TestCase):
    def setUp(self):
        self.items = rv.advisory_rpo_rto()
        self.by_id = {i["check_id"]: i for i in self.items}

    def test_four_advisory_items_returned(self):
        self.assertEqual(len(self.items), 4)

    def test_all_are_info_status(self):
        for item in self.items:
            self.assertEqual(item["status"], rv.INFO, item["check_id"])

    def test_local_rpo_rto_present(self):
        self.assertIn("A-01", self.by_id)
        self.assertIn("RPO", self.by_id["A-01"]["detail"])
        self.assertIn("RTO", self.by_id["A-01"]["detail"])

    def test_staging_rpo_rto_present(self):
        self.assertIn("A-02", self.by_id)
        self.assertIn("24 hours", self.by_id["A-02"]["detail"])

    def test_production_rpo_rto_present(self):
        self.assertIn("A-03", self.by_id)
        self.assertIn("4 hours", self.by_id["A-03"]["detail"])

    def test_pg_dump_tool_present(self):
        self.assertIn("A-04", self.by_id)
        self.assertIn("pg_dump", self.by_id["A-04"]["detail"].lower())


# ---------------------------------------------------------------------------
# run_checks integration
# ---------------------------------------------------------------------------

class TestRunChecks(unittest.TestCase):
    def test_returns_twelve_checks(self):
        root = _make_root(key_reports=["xdr_contract_validation.json"])
        checks = rv.run_checks(_good_env(), root, "local")
        self.assertEqual(len(checks), 12)

    def test_all_check_ids_present(self):
        root = _make_root(key_reports=["xdr_contract_validation.json"])
        checks = rv.run_checks(_good_env(), root, "local")
        ids = {c["check_id"] for c in checks}
        for cid in ("R-01", "R-02", "R-03", "R-04", "R-05",
                    "R-06", "R-07", "R-08", "A-01", "A-02", "A-03", "A-04"):
            self.assertIn(cid, ids)

    def test_no_fail_for_well_structured_root(self):
        root = _make_root(
            key_reports=["xdr_contract_validation.json"],
            operational_docs=[
                "docs/operations/OPERATIONAL_POSTURE.md",
                "docs/operations/PRODUCTION_RUNTIME_PROFILE.md",
                "docs/validation/VALIDATION_BASELINES.md",
            ],
        )
        checks = rv.run_checks(_good_env(), root, "local")
        fails = [c for c in checks if c["status"] == rv.FAIL]
        self.assertEqual(fails, [], f"unexpected FAIL: {fails}")


# ---------------------------------------------------------------------------
# build_report
# ---------------------------------------------------------------------------

class TestBuildReport(unittest.TestCase):
    def _passing_checks(self):
        return [
            {"check_id": f"R-0{i}", "name": f"check_{i}",
             "status": rv.PASS, "detail": "ok"}
            for i in range(1, 9)
        ] + [
            {"check_id": f"A-0{i}", "name": f"adv_{i}",
             "status": rv.INFO, "detail": "info"}
            for i in range(1, 5)
        ]

    def test_all_pass_overall_pass(self):
        report = rv.build_report("local", self._passing_checks(), False)
        self.assertEqual(report["overall"], rv.PASS)

    def test_one_fail_overall_fail(self):
        checks = self._passing_checks()
        checks[0]["status"] = rv.FAIL
        report = rv.build_report("local", checks, False)
        self.assertEqual(report["overall"], rv.FAIL)

    def test_warn_does_not_cause_fail(self):
        checks = self._passing_checks()
        checks[0]["status"] = rv.WARN
        report = rv.build_report("local", checks, False)
        self.assertEqual(report["overall"], rv.PASS)

    def test_required_report_fields(self):
        report = rv.build_report("staging", self._passing_checks(), True)
        for field in ("validation", "title", "profile", "dry_run", "overall",
                      "pass_count", "warn_count", "fail_count", "checks", "generated_at"):
            self.assertIn(field, report)

    def test_dry_run_flag_preserved(self):
        report = rv.build_report("local", self._passing_checks(), True)
        self.assertTrue(report["dry_run"])

    def test_profile_preserved(self):
        report = rv.build_report("staging", self._passing_checks(), False)
        self.assertEqual(report["profile"], "staging")


# ---------------------------------------------------------------------------
# CLI main()
# ---------------------------------------------------------------------------

class TestMainCli(unittest.TestCase):
    def test_exits_0_on_pass_local(self):
        rc = rv.main(["--profile=local", "--quiet"])
        self.assertEqual(rc, 0)

    def test_dry_run_exits_0(self):
        rc = rv.main(["--dry-run", "--quiet"])
        self.assertEqual(rc, 0)

    def test_output_writes_json(self):
        import tempfile, os
        with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as f:
            path = f.name
        try:
            rv.main(["--profile=local", "--output", path, "--quiet"])
            data = json.loads(Path(path).read_text(encoding="utf-8"))
            self.assertEqual(data["validation"], "BACKLOG-DR-027")
            self.assertIn("overall", data)
            self.assertEqual(data["profile"], "local")
        finally:
            os.unlink(path)

    def test_output_report_has_twelve_checks(self):
        import tempfile, os
        with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as f:
            path = f.name
        try:
            rv.main(["--profile=local", "--output", path, "--quiet"])
            data = json.loads(Path(path).read_text(encoding="utf-8"))
            self.assertEqual(len(data["checks"]), 12)
        finally:
            os.unlink(path)


if __name__ == "__main__":
    unittest.main()
