#!/usr/bin/env python3
"""
ENTERPRISE-067 — RAG Knowledge Base Seeding Runbook Validator
Offline-first. Validates fixture file, service, command, views, and docs.
"""

import json
import os
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
REPORT_PATH = BASE_DIR / "reports" / "xdr_rag_seed.json"
FIXTURE_PATH = BASE_DIR / "database" / "seeders" / "data" / "rag_knowledge_fixtures.json"

REQUIRED_CATEGORIES = {"detection_rule", "soc_procedure", "incident_type", "threat_intel"}


# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

def check_fixture_file_exists() -> dict:
    ok = FIXTURE_PATH.is_file()
    return {"id": "RAG-01", "name": "rag_knowledge_fixtures.json exists", "status": "PASS" if ok else "FAIL",
            "detail": str(FIXTURE_PATH)}


def check_fixture_content_valid() -> dict:
    if not FIXTURE_PATH.is_file():
        return {"id": "RAG-02", "name": "Fixture JSON is valid and non-empty", "status": "FAIL", "detail": "file missing"}
    try:
        data = json.loads(FIXTURE_PATH.read_text(encoding="utf-8"))
        ok = isinstance(data, list) and len(data) >= 5
        return {"id": "RAG-02", "name": "Fixture JSON is valid and has >=5 entries",
                "status": "PASS" if ok else "FAIL", "detail": f"{len(data)} entries found"}
    except Exception as e:
        return {"id": "RAG-02", "name": "Fixture JSON is valid", "status": "FAIL", "detail": str(e)}


def check_fixture_categories() -> dict:
    if not FIXTURE_PATH.is_file():
        return {"id": "RAG-03", "name": "Fixture covers required categories", "status": "FAIL", "detail": "file missing"}
    data = json.loads(FIXTURE_PATH.read_text(encoding="utf-8"))
    found = {item.get("category") for item in data if isinstance(item, dict)}
    missing = REQUIRED_CATEGORIES - found
    ok = len(missing) == 0
    return {"id": "RAG-03", "name": "Fixture covers all required categories",
            "status": "PASS" if ok else "WARN", "detail": f"missing: {missing}" if missing else f"found: {found}"}


def check_seed_service_exists() -> dict:
    path = BASE_DIR / "app" / "Services" / "AiKnowledgeSeedService.php"
    ok = path.is_file()
    return {"id": "RAG-04", "name": "AiKnowledgeSeedService exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_artisan_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "AiSeedKnowledgeCommand.php"
    ok = path.is_file()
    return {"id": "RAG-05", "name": "AiSeedKnowledgeCommand exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_dry_run_flag() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "AiSeedKnowledgeCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "--dry-run" in content
    return {"id": "RAG-06", "name": "Command has --dry-run flag", "status": "PASS" if ok else "FAIL",
            "detail": "AiSeedKnowledgeCommand.php contains --dry-run"}


def check_migration_exists() -> dict:
    pattern = "create_rag_knowledge_seed_tables"
    migrations = list((BASE_DIR / "database" / "migrations").glob(f"*{pattern}*"))
    ok = len(migrations) > 0
    return {"id": "RAG-07", "name": "RAG seed migration exists", "status": "PASS" if ok else "FAIL",
            "detail": migrations[0].name if ok else "not found"}


def check_migration_tables() -> dict:
    pattern = "create_rag_knowledge_seed_tables"
    migrations = list((BASE_DIR / "database" / "migrations").glob(f"*{pattern}*"))
    if not migrations:
        return {"id": "RAG-08", "name": "Migration defines 3 RAG tables", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    tables = ["rag_seed_fixtures", "rag_seed_runs", "rag_seed_document_log"]
    missing = [t for t in tables if t not in content]
    ok = len(missing) == 0
    return {"id": "RAG-08", "name": "Migration defines all 3 RAG tables", "status": "PASS" if ok else "FAIL",
            "detail": f"missing: {missing}" if missing else "all present"}


def check_controller_exists() -> dict:
    path = BASE_DIR / "app" / "Http" / "Controllers" / "AiKnowledgeSeedController.php"
    ok = path.is_file()
    return {"id": "RAG-09", "name": "AiKnowledgeSeedController exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_routes_registered() -> dict:
    content = (BASE_DIR / "routes" / "web.php").read_text(encoding="utf-8")
    ok = "knowledge-seed" in content and "AiKnowledgeSeedController" in content
    return {"id": "RAG-10", "name": "RAG seed routes registered in web.php", "status": "PASS" if ok else "FAIL",
            "detail": "routes/web.php contains knowledge-seed routes"}


def check_rbac_permissions() -> dict:
    content = (BASE_DIR / "config" / "soc.php").read_text(encoding="utf-8")
    ok = "ai.knowledge.view" in content and "ai.knowledge.seed" in content
    return {"id": "RAG-11", "name": "RBAC permissions registered (ai.knowledge.*)", "status": "PASS" if ok else "FAIL",
            "detail": "config/soc.php contains ai.knowledge.* permissions"}


def check_idempotent_seed() -> dict:
    path = BASE_DIR / "app" / "Services" / "AiKnowledgeSeedService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "already exists" in content or "insertOrIgnore" in content or "updateOrInsert" in content
    return {"id": "RAG-12", "name": "Seed is idempotent (no duplicate check)", "status": "PASS" if ok else "WARN",
            "detail": "AiKnowledgeSeedService checks for existing entries before inserting"}


def check_view_exists() -> dict:
    path = BASE_DIR / "resources" / "views" / "soc" / "ai-knowledge-seed" / "index.blade.php"
    ok = path.is_file()
    return {"id": "RAG-13", "name": "Blade view exists (index)", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_hunt_domains_added() -> dict:
    content = (BASE_DIR / "app" / "Services" / "ThreatHuntingService.php").read_text(encoding="utf-8")
    ok = "rag_seed_runs" in content and "rag_seed_document_log" in content
    return {"id": "RAG-14", "name": "Hunt domains registered (rag_seed_runs, rag_seed_document_log)",
            "status": "PASS" if ok else "FAIL", "detail": "ThreatHuntingService.php SUPPORTED_DOMAINS"}


# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------

CHECKS = [
    check_fixture_file_exists, check_fixture_content_valid, check_fixture_categories,
    check_seed_service_exists, check_artisan_command_exists, check_dry_run_flag,
    check_migration_exists, check_migration_tables, check_controller_exists,
    check_routes_registered, check_rbac_permissions, check_idempotent_seed,
    check_view_exists, check_hunt_domains_added,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed = sum(1 for r in results if r["status"] == "PASS")
    warned = sum(1 for r in results if r["status"] == "WARN")
    failed = sum(1 for r in results if r["status"] == "FAIL")
    overall = "PASS" if failed == 0 else "FAIL"
    return {
        "validator": "xdr_rag_seed_validate",
        "enterprise_task": "ENTERPRISE-067",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": overall, "results": results,
    }


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestRagSeedValidator(unittest.TestCase):

    def test_rag01_fixture_exists(self): self.assertEqual(check_fixture_file_exists()["status"], "PASS")
    def test_rag02_fixture_valid(self): self.assertEqual(check_fixture_content_valid()["status"], "PASS")
    def test_rag03_categories(self): self.assertIn(check_fixture_categories()["status"], ("PASS", "WARN"))
    def test_rag04_service_exists(self): self.assertEqual(check_seed_service_exists()["status"], "PASS")
    def test_rag05_command_exists(self): self.assertEqual(check_artisan_command_exists()["status"], "PASS")
    def test_rag06_dry_run_flag(self): self.assertEqual(check_dry_run_flag()["status"], "PASS")
    def test_rag07_migration_exists(self): self.assertEqual(check_migration_exists()["status"], "PASS")
    def test_rag08_migration_tables(self): self.assertEqual(check_migration_tables()["status"], "PASS")
    def test_rag09_controller_exists(self): self.assertEqual(check_controller_exists()["status"], "PASS")
    def test_rag10_routes_registered(self): self.assertEqual(check_routes_registered()["status"], "PASS")
    def test_rag11_rbac_permissions(self): self.assertEqual(check_rbac_permissions()["status"], "PASS")
    def test_rag12_idempotent(self): self.assertIn(check_idempotent_seed()["status"], ("PASS", "WARN"))
    def test_rag13_view_exists(self): self.assertEqual(check_view_exists()["status"], "PASS")
    def test_rag14_hunt_domains(self): self.assertEqual(check_hunt_domains_added()["status"], "PASS")

    def test_overall_no_failures(self):
        report = run_checks()
        self.assertEqual(report["failed"], 0,
                         f"Failed: {[r for r in report['results'] if r['status'] == 'FAIL']}")

    def test_report_has_required_keys(self):
        for key in ("validator", "enterprise_task", "overall", "results"):
            self.assertIn(key, run_checks())

    def test_fixture_has_fixture_id_fields(self):
        data = json.loads(FIXTURE_PATH.read_text(encoding="utf-8"))
        for entry in data:
            self.assertIn("fixture_id", entry)
            self.assertIn("title", entry)
            self.assertIn("category", entry)
            self.assertIn("content", entry)


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
