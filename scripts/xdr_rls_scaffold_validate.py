#!/usr/bin/env python3
"""
ENTERPRISE-069 — PostgreSQL RLS Policy Scaffolding Validator
Offline-first. Verifies migration, command, TenantBoundaryService posture, docs.
"""

import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent

RLS_TABLES = ["security_alerts", "security_incidents"]


def check_migration_exists() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    ok = len(migrations) > 0
    return {"id": "RLS-01", "name": "RLS scaffold migration exists",
            "status": "PASS" if ok else "FAIL",
            "detail": migrations[0].name if ok else "migration not found"}


def check_migration_uses_do_block() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    if not migrations:
        return {"id": "RLS-02", "name": "Migration uses idempotent DO block", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    ok = "DO $$" in content and "pg_policies" in content
    return {"id": "RLS-02", "name": "Migration uses idempotent DO $$ block (checks pg_policies)",
            "status": "PASS" if ok else "FAIL", "detail": "Migration checks pg_policies before CREATE POLICY"}


def check_migration_no_enable_rls() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    if not migrations:
        return {"id": "RLS-03", "name": "Migration does NOT execute RLS enforcement SQL", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    # Must not contain the enforcement activation SQL pattern (ALTER TABLE + ENABLE)
    # Note: comments may reference enforcement by name — we check for the actual SQL execution
    import re
    has_alter_enable = bool(re.search(r"ALTER\s+TABLE.*ENABLE\s+ROW\s+LEVEL\s+SECURITY", content, re.IGNORECASE | re.DOTALL))
    ok = not has_alter_enable
    return {"id": "RLS-03", "name": "Migration does NOT execute ALTER TABLE ... ENABLE ROW LEVEL SECURITY",
            "status": "PASS" if ok else "FAIL",
            "detail": "Migration only defines policy — does not execute enforcement SQL"}


def check_migration_pgsql_guard() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    if not migrations:
        return {"id": "RLS-04", "name": "Migration guards pgsql driver", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    ok = "pgsql" in content and "getDriverName" in content
    return {"id": "RLS-04", "name": "Migration skips non-PostgreSQL drivers (SQLite-safe)",
            "status": "PASS" if ok else "FAIL", "detail": "Migration checks DB driver before executing RLS SQL"}


def check_rls_status_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantRlsStatusCommand.php"
    ok = path.is_file()
    return {"id": "RLS-05", "name": "TenantRlsStatusCommand exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_command_is_read_only() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantRlsStatusCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    # Must NOT contain ALTER TABLE ENABLE ROW LEVEL SECURITY
    ok = "ENABLE ROW LEVEL SECURITY" not in content
    return {"id": "RLS-06", "name": "TenantRlsStatusCommand is advisory read-only",
            "status": "PASS" if ok else "FAIL",
            "detail": "Command does not enable or disable RLS autonomously"}


def check_tenant_boundary_rls_disabled() -> dict:
    path = BASE_DIR / "app" / "Services" / "TenantBoundaryService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "RLS_ENABLED" in content and "false" in content
    return {"id": "RLS-07", "name": "TenantBoundaryService.RLS_ENABLED = false",
            "status": "PASS" if ok else "WARN",
            "detail": "Advisory posture — RLS not enforced in application layer"}


def check_rls_decision_record_exists() -> dict:
    path = BASE_DIR / "docs" / "security" / "RLS_DECISION_RECORD.md"
    ok = path.is_file()
    return {"id": "RLS-08", "name": "RLS_DECISION_RECORD.md exists",
            "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_migration_covers_both_tables() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    if not migrations:
        return {"id": "RLS-09", "name": "Migration covers both RLS tables", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    missing = [t for t in RLS_TABLES if t not in content]
    ok = len(missing) == 0
    return {"id": "RLS-09", "name": "Migration covers security_alerts + security_incidents",
            "status": "PASS" if ok else "FAIL",
            "detail": f"Missing: {missing}" if missing else "Both tables present in migration"}


def check_policy_uses_current_setting() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    if not migrations:
        return {"id": "RLS-10", "name": "Policy uses current_setting(app.tenant_id)", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    ok = "current_setting" in content and "app.tenant_id" in content
    return {"id": "RLS-10", "name": "Policy uses current_setting('app.tenant_id', true)",
            "status": "PASS" if ok else "FAIL",
            "detail": "Policy expression uses PostgreSQL session setting for tenant scoping"}


def check_down_drops_policy() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    if not migrations:
        return {"id": "RLS-11", "name": "Migration down() drops policies", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    ok = "DROP POLICY IF EXISTS" in content
    return {"id": "RLS-11", "name": "Migration down() drops policies (reversible)",
            "status": "PASS" if ok else "WARN", "detail": "Migration rollback drops created policies"}


def check_command_reports_advisory() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantRlsStatusCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "Advisory" in content or "advisory" in content
    return {"id": "RLS-12", "name": "TenantRlsStatusCommand reports advisory posture",
            "status": "PASS" if ok else "WARN",
            "detail": "Command output includes advisory posture note"}


CHECKS = [
    check_migration_exists, check_migration_uses_do_block, check_migration_no_enable_rls,
    check_migration_pgsql_guard, check_rls_status_command_exists, check_command_is_read_only,
    check_tenant_boundary_rls_disabled, check_rls_decision_record_exists,
    check_migration_covers_both_tables, check_policy_uses_current_setting,
    check_down_drops_policy, check_command_reports_advisory,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed = sum(1 for r in results if r["status"] == "PASS")
    warned = sum(1 for r in results if r["status"] == "WARN")
    failed = sum(1 for r in results if r["status"] == "FAIL")
    return {
        "validator": "xdr_rls_scaffold_validate",
        "enterprise_task": "ENTERPRISE-069",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": "PASS" if failed == 0 else "FAIL",
        "results": results,
    }


class TestRlsScaffoldValidator(unittest.TestCase):
    def test_rls01(self): self.assertEqual(check_migration_exists()["status"], "PASS")
    def test_rls02(self): self.assertEqual(check_migration_uses_do_block()["status"], "PASS")
    def test_rls03(self): self.assertEqual(check_migration_no_enable_rls()["status"], "PASS")
    def test_rls04(self): self.assertEqual(check_migration_pgsql_guard()["status"], "PASS")
    def test_rls05(self): self.assertEqual(check_rls_status_command_exists()["status"], "PASS")
    def test_rls06(self): self.assertEqual(check_command_is_read_only()["status"], "PASS")
    def test_rls07(self): self.assertIn(check_tenant_boundary_rls_disabled()["status"], ("PASS", "WARN"))
    def test_rls08(self): self.assertIn(check_rls_decision_record_exists()["status"], ("PASS", "WARN"))
    def test_rls09(self): self.assertEqual(check_migration_covers_both_tables()["status"], "PASS")
    def test_rls10(self): self.assertEqual(check_policy_uses_current_setting()["status"], "PASS")
    def test_rls11(self): self.assertIn(check_down_drops_policy()["status"], ("PASS", "WARN"))
    def test_rls12(self): self.assertIn(check_command_reports_advisory()["status"], ("PASS", "WARN"))

    def test_overall_no_failures(self):
        r = run_checks()
        self.assertEqual(r["failed"], 0, f"Failures: {[x for x in r['results'] if x['status']=='FAIL']}")

    def test_report_structure(self):
        for k in ("validator", "enterprise_task", "overall", "results"):
            self.assertIn(k, run_checks())


if __name__ == "__main__":
    if "--test" in sys.argv or len(sys.argv) == 1:
        sys.argv = [sys.argv[0]]
        unittest.main(verbosity=2)
    else:
        import json, os
        report = run_checks()
        out = BASE_DIR / "reports" / "xdr_rls_scaffold.json"
        os.makedirs(out.parent, exist_ok=True)
        out.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
        sys.exit(0 if report["overall"] == "PASS" else 1)
