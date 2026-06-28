#!/usr/bin/env python3
"""
ENTERPRISE-065 — Tenant Backfill + Strict Mode Readiness Validator
Offline-first. Validates readiness posture without requiring live DB.
"""

import json
import os
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
REPORT_PATH = BASE_DIR / "reports" / "xdr_strict_mode_readiness.json"


# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

def check_backfill_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "TenantNullBackfillCommand.php"
    ok = path.is_file()
    return {"id": "SMR-01", "name": "TenantNullBackfillCommand exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_strict_mode_readiness_service() -> dict:
    path = BASE_DIR / "app" / "Services" / "TenantStrictModeReadinessService.php"
    ok = path.is_file()
    return {"id": "SMR-02", "name": "TenantStrictModeReadinessService exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_rls_decision_record() -> dict:
    path = BASE_DIR / "docs" / "security" / "RLS_DECISION_RECORD.md"
    ok = path.is_file()
    return {"id": "SMR-03", "name": "RLS_DECISION_RECORD.md documented", "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_null_migration_plan() -> dict:
    path = BASE_DIR / "docs" / "security" / "TENANT_NULL_MIGRATION_PLAN.md"
    ok = path.is_file()
    return {"id": "SMR-04", "name": "TENANT_NULL_MIGRATION_PLAN.md exists", "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_strict_mode_doc() -> dict:
    path = BASE_DIR / "docs" / "security" / "TENANT_STRICT_MODE.md"
    ok = path.is_file()
    return {"id": "SMR-05", "name": "TENANT_STRICT_MODE.md exists", "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_migration_exists() -> dict:
    pattern = "create_tenant_strict_mode_readiness_tables"
    migrations = list((BASE_DIR / "database" / "migrations").glob(f"*{pattern}*"))
    ok = len(migrations) > 0
    return {"id": "SMR-06", "name": "Strict mode readiness migration exists", "status": "PASS" if ok else "FAIL",
            "detail": migrations[0].name if ok else "not found"}


def check_controller_exists() -> dict:
    path = BASE_DIR / "app" / "Http" / "Controllers" / "TenantStrictModeReadinessController.php"
    ok = path.is_file()
    return {"id": "SMR-07", "name": "TenantStrictModeReadinessController exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_routes_registered() -> dict:
    routes_file = BASE_DIR / "routes" / "web.php"
    content = routes_file.read_text(encoding="utf-8")
    ok = "strict-mode-readiness" in content and "TenantStrictModeReadinessController" in content
    return {"id": "SMR-08", "name": "Strict mode readiness routes registered", "status": "PASS" if ok else "FAIL",
            "detail": "routes/web.php contains strict-mode-readiness routes"}


def check_rbac_permissions() -> dict:
    soc_config = BASE_DIR / "config" / "soc.php"
    content = soc_config.read_text(encoding="utf-8")
    ok = "tenant.strict-mode.view" in content and "tenant.strict-mode.assess" in content
    return {"id": "SMR-09", "name": "RBAC permissions registered (tenant.strict-mode.*)", "status": "PASS" if ok else "FAIL",
            "detail": "config/soc.php contains tenant.strict-mode.* permissions"}


def check_artisan_command() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "StrictModeReadinessCommand.php"
    ok = path.is_file()
    return {"id": "SMR-10", "name": "StrictModeReadinessCommand artisan command exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_mutable_tables_documented() -> dict:
    service = BASE_DIR / "app" / "Services" / "TenantBoundaryService.php"
    content = service.read_text(encoding="utf-8")
    has_mutable = "MUTABLE_TABLES" in content
    has_append = "APPEND_ONLY_ISOLATED_TABLES" in content
    ok = has_mutable and has_append
    return {"id": "SMR-11", "name": "MUTABLE_TABLES and APPEND_ONLY_ISOLATED_TABLES defined", "status": "PASS" if ok else "FAIL",
            "detail": "TenantBoundaryService.php defines both table class constants"}


def check_views_exist() -> dict:
    view_dir = BASE_DIR / "resources" / "views" / "soc" / "tenant-strict-mode-readiness"
    index_ok = (view_dir / "index.blade.php").is_file()
    history_ok = (view_dir / "history.blade.php").is_file()
    ok = index_ok and history_ok
    return {"id": "SMR-12", "name": "Blade views exist (index + history)", "status": "PASS" if ok else "FAIL",
            "detail": str(view_dir)}


# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------

CHECKS = [
    check_backfill_command_exists,
    check_strict_mode_readiness_service,
    check_rls_decision_record,
    check_null_migration_plan,
    check_strict_mode_doc,
    check_migration_exists,
    check_controller_exists,
    check_routes_registered,
    check_rbac_permissions,
    check_artisan_command,
    check_mutable_tables_documented,
    check_views_exist,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed = sum(1 for r in results if r["status"] == "PASS")
    warned = sum(1 for r in results if r["status"] == "WARN")
    failed = sum(1 for r in results if r["status"] == "FAIL")
    overall = "PASS" if failed == 0 else "FAIL"
    return {
        "validator": "xdr_strict_mode_readiness_validate",
        "enterprise_task": "ENTERPRISE-065",
        "checks_total": len(results),
        "passed": passed,
        "warned": warned,
        "failed": failed,
        "overall": overall,
        "results": results,
    }


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestStrictModeReadinessValidator(unittest.TestCase):

    def test_smr01_backfill_command_exists(self):
        r = check_backfill_command_exists()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr02_service_exists(self):
        r = check_strict_mode_readiness_service()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr03_rls_decision_record(self):
        r = check_rls_decision_record()
        self.assertIn(r["status"], ("PASS", "WARN"), r["detail"])

    def test_smr04_null_migration_plan(self):
        r = check_null_migration_plan()
        self.assertIn(r["status"], ("PASS", "WARN"), r["detail"])

    def test_smr05_strict_mode_doc(self):
        r = check_strict_mode_doc()
        self.assertIn(r["status"], ("PASS", "WARN"), r["detail"])

    def test_smr06_migration_exists(self):
        r = check_migration_exists()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr07_controller_exists(self):
        r = check_controller_exists()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr08_routes_registered(self):
        r = check_routes_registered()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr09_rbac_permissions(self):
        r = check_rbac_permissions()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr10_artisan_command(self):
        r = check_artisan_command()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr11_mutable_tables_documented(self):
        r = check_mutable_tables_documented()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_smr12_views_exist(self):
        r = check_views_exist()
        self.assertEqual(r["status"], "PASS", r["detail"])

    def test_overall_no_failures(self):
        report = run_checks()
        self.assertEqual(report["failed"], 0,
                         f"Failed checks: {[r for r in report['results'] if r['status'] == 'FAIL']}")

    def test_report_structure(self):
        report = run_checks()
        for key in ("validator", "enterprise_task", "checks_total", "passed", "failed", "overall", "results"):
            self.assertIn(key, report)

    def test_all_results_have_required_fields(self):
        report = run_checks()
        for r in report["results"]:
            self.assertIn("id", r)
            self.assertIn("name", r)
            self.assertIn("status", r)
            self.assertIn(r["status"], ("PASS", "WARN", "FAIL"))


if __name__ == "__main__":
    if "--test" in sys.argv or len(sys.argv) == 1:
        sys.argv = [sys.argv[0]]
        unittest.main(verbosity=2)
    else:
        report = run_checks()
        os.makedirs(REPORT_PATH.parent, exist_ok=True)
        REPORT_PATH.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
        sys.exit(0 if report["overall"] == "PASS" else 1)
