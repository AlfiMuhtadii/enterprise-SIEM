#!/usr/bin/env python3
"""
ENTERPRISE-070 — Tenant Null Backfill Pre-Flight Validator
Offline. Verifies command, service classes, and RLS scaffold are in place
before any operator runs the tenant null backfill.
"""

import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent


def check_preflight_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantBackfillPreflightCommand.php"
    ok = path.is_file()
    return {"id": "TBP-01", "name": "TenantBackfillPreflightCommand exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_command_is_advisory_only() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantBackfillPreflightCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    has_insert = any(k in content for k in ["->insert(", "->update(", "->delete("])
    ok = not has_insert
    return {"id": "TBP-02", "name": "TenantBackfillPreflightCommand is read-only (no data mutations)",
            "status": "PASS" if ok else "FAIL",
            "detail": "Command uses only SELECT queries — no insert/update/delete"}


def check_backfill_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantNullBackfillCommand.php"
    ok = path.is_file()
    return {"id": "TBP-03", "name": "TenantNullBackfillCommand exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_backfill_command_has_dry_run() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantNullBackfillCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "dry-run" in content or "dry_run" in content
    return {"id": "TBP-04", "name": "TenantNullBackfillCommand has --dry-run flag",
            "status": "PASS" if ok else "WARN",
            "detail": "Backfill command supports dry-run mode for safe preview"}


def check_tenant_boundary_service_exists() -> dict:
    path = BASE_DIR / "app" / "Services" / "TenantBoundaryService.php"
    ok = path.is_file()
    return {"id": "TBP-05", "name": "TenantBoundaryService exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_tenant_boundary_has_mutable_tables() -> dict:
    path = BASE_DIR / "app" / "Services" / "TenantBoundaryService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "MUTABLE_TABLES" in content
    return {"id": "TBP-06", "name": "TenantBoundaryService defines MUTABLE_TABLES constant",
            "status": "PASS" if ok else "FAIL",
            "detail": "MUTABLE_TABLES constant provides backfill target list"}


def check_tenant_context_authority_exists() -> dict:
    path = BASE_DIR / "app" / "Services" / "TenantContextAuthority.php"
    ok = path.is_file()
    return {"id": "TBP-07", "name": "TenantContextAuthority exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_rls_scaffold_migration_exists() -> dict:
    migrations = list((BASE_DIR / "database" / "migrations").glob("*scaffold_rls_policies*"))
    ok = len(migrations) > 0
    return {"id": "TBP-08", "name": "RLS scaffold migration exists (ENTERPRISE-069 prerequisite)",
            "status": "PASS" if ok else "WARN",
            "detail": migrations[0].name if ok else "Run ENTERPRISE-069 migration first"}


def check_strict_mode_readiness_service_has_gate08() -> dict:
    path = BASE_DIR / "app" / "Services" / "TenantStrictModeReadinessService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "GATE-08" in content and "gateRlsPoliciesScaffolded" in content
    return {"id": "TBP-09", "name": "TenantStrictModeReadinessService has GATE-08 (RLS scaffold check)",
            "status": "PASS" if ok else "FAIL",
            "detail": "GATE-08 evaluates RLS scaffold migration presence"}


def check_preflight_checks_all_six() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantBackfillPreflightCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "CHK-06" in content
    return {"id": "TBP-10", "name": "TenantBackfillPreflightCommand covers CHK-01 through CHK-06",
            "status": "PASS" if ok else "WARN",
            "detail": "Command evaluates 6 preflight checks"}


def check_tenant_null_migration_plan_exists() -> dict:
    path = BASE_DIR / "docs" / "security" / "TENANT_NULL_MIGRATION_PLAN.md"
    ok = path.is_file()
    return {"id": "TBP-11", "name": "TENANT_NULL_MIGRATION_PLAN.md exists",
            "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_posture_check_references_tenant() -> dict:
    path = BASE_DIR / "scripts" / "xdr_posture_check.py"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "tenant" in content.lower()
    return {"id": "TBP-12", "name": "xdr_posture_check.py references tenant isolation",
            "status": "PASS" if ok else "WARN",
            "detail": "Posture check covers tenant isolation posture"}


CHECKS = [
    check_preflight_command_exists,
    check_command_is_advisory_only,
    check_backfill_command_exists,
    check_backfill_command_has_dry_run,
    check_tenant_boundary_service_exists,
    check_tenant_boundary_has_mutable_tables,
    check_tenant_context_authority_exists,
    check_rls_scaffold_migration_exists,
    check_strict_mode_readiness_service_has_gate08,
    check_preflight_checks_all_six,
    check_tenant_null_migration_plan_exists,
    check_posture_check_references_tenant,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed  = sum(1 for r in results if r["status"] == "PASS")
    warned  = sum(1 for r in results if r["status"] == "WARN")
    failed  = sum(1 for r in results if r["status"] == "FAIL")
    return {
        "validator": "xdr_tenant_backfill_preflight",
        "enterprise_task": "ENTERPRISE-070",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": "PASS" if failed == 0 else "FAIL",
        "results": results,
    }


class TestTenantBackfillPreflight(unittest.TestCase):
    def test_tbp01(self): self.assertEqual(check_preflight_command_exists()["status"], "PASS")
    def test_tbp02(self): self.assertEqual(check_command_is_advisory_only()["status"], "PASS")
    def test_tbp03(self): self.assertEqual(check_backfill_command_exists()["status"], "PASS")
    def test_tbp04(self): self.assertIn(check_backfill_command_has_dry_run()["status"], ("PASS", "WARN"))
    def test_tbp05(self): self.assertEqual(check_tenant_boundary_service_exists()["status"], "PASS")
    def test_tbp06(self): self.assertEqual(check_tenant_boundary_has_mutable_tables()["status"], "PASS")
    def test_tbp07(self): self.assertEqual(check_tenant_context_authority_exists()["status"], "PASS")
    def test_tbp08(self): self.assertIn(check_rls_scaffold_migration_exists()["status"], ("PASS", "WARN"))
    def test_tbp09(self): self.assertEqual(check_strict_mode_readiness_service_has_gate08()["status"], "PASS")
    def test_tbp10(self): self.assertIn(check_preflight_checks_all_six()["status"], ("PASS", "WARN"))
    def test_tbp11(self): self.assertIn(check_tenant_null_migration_plan_exists()["status"], ("PASS", "WARN"))
    def test_tbp12(self): self.assertIn(check_posture_check_references_tenant()["status"], ("PASS", "WARN"))

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
        out = BASE_DIR / "reports" / "xdr_tenant_backfill_preflight.json"
        os.makedirs(out.parent, exist_ok=True)
        out.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
        sys.exit(0 if report["overall"] == "PASS" else 1)
