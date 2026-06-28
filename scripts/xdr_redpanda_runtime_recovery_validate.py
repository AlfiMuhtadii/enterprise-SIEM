#!/usr/bin/env python3
"""
ENTERPRISE-066 — Redpanda Topic Bootstrap + Runtime Recovery Hardening Validator
Offline-first. Validates service code, migration, and documentation posture.
"""

import json
import os
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
REPORT_PATH = BASE_DIR / "reports" / "xdr_redpanda_runtime_recovery.json"

EXPECTED_TOPICS = [
    "telemetry.raw",
    "telemetry.normalized",
    "xdr.alerts",
    "xdr.alerts.shadow.endpoint",
    "alerts.created",
    "incidents.updated",
    "telemetry.normalization_failed",
    "xdr.correlation_failed",
    "xdr.alert_write_failed",
]

EXPECTED_CONSUMER_GROUPS = [
    "normalizer-worker-group",
    "correlation-worker-group",
    "alert-writer-group",
    "incident-builder-group",
    "shadow-alert-consumer-group",
    "dlq-consumer-group",
]


# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

def check_service_exists() -> dict:
    path = BASE_DIR / "app" / "Services" / "RedpandaRecoveryHardeningService.php"
    ok = path.is_file()
    return {"id": "RRH-01", "name": "RedpandaRecoveryHardeningService exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_expected_topics_defined() -> dict:
    service = BASE_DIR / "app" / "Services" / "RedpandaRecoveryHardeningService.php"
    content = service.read_text(encoding="utf-8") if service.is_file() else ""
    missing = [t for t in EXPECTED_TOPICS if t not in content]
    ok = len(missing) == 0
    return {"id": "RRH-02", "name": f"All {len(EXPECTED_TOPICS)} expected topics defined in service",
            "status": "PASS" if ok else "FAIL", "detail": f"missing: {missing}" if missing else "all present"}


def check_consumer_groups_defined() -> dict:
    service = BASE_DIR / "app" / "Services" / "RedpandaRecoveryHardeningService.php"
    content = service.read_text(encoding="utf-8") if service.is_file() else ""
    missing = [g for g in EXPECTED_CONSUMER_GROUPS if g not in content]
    ok = len(missing) == 0
    return {"id": "RRH-03", "name": f"All {len(EXPECTED_CONSUMER_GROUPS)} consumer groups defined",
            "status": "PASS" if ok else "FAIL", "detail": f"missing: {missing}" if missing else "all present"}


def check_migration_exists() -> dict:
    pattern = "create_redpanda_recovery_hardening_tables"
    migrations = list((BASE_DIR / "database" / "migrations").glob(f"*{pattern}*"))
    ok = len(migrations) > 0
    return {"id": "RRH-04", "name": "Redpanda recovery migration exists", "status": "PASS" if ok else "FAIL",
            "detail": migrations[0].name if ok else "not found"}


def check_controller_exists() -> dict:
    path = BASE_DIR / "app" / "Http" / "Controllers" / "RedpandaHealthController.php"
    ok = path.is_file()
    return {"id": "RRH-05", "name": "RedpandaHealthController exists", "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_routes_registered() -> dict:
    routes = (BASE_DIR / "routes" / "web.php").read_text(encoding="utf-8")
    ok = "redpanda.health" in routes and "RedpandaHealthController" in routes
    return {"id": "RRH-06", "name": "Redpanda health routes registered", "status": "PASS" if ok else "FAIL",
            "detail": "routes/web.php contains redpanda.health routes"}


def check_rbac_permissions() -> dict:
    content = (BASE_DIR / "config" / "soc.php").read_text(encoding="utf-8")
    ok = "redpanda.health.view" in content and "redpanda.health.check" in content
    return {"id": "RRH-07", "name": "RBAC permissions registered (redpanda.health.*)", "status": "PASS" if ok else "FAIL",
            "detail": "config/soc.php contains redpanda.health.* permissions"}


def check_topic_bootstrap_script_exists() -> dict:
    path = BASE_DIR / "scripts" / "xdr_topic_bootstrap.py"
    ok = path.is_file()
    return {"id": "RRH-08", "name": "xdr_topic_bootstrap.py exists (prerequisite)", "status": "PASS" if ok else "WARN",
            "detail": str(path)}


def check_views_exist() -> dict:
    view_dir = BASE_DIR / "resources" / "views" / "soc" / "redpanda-health"
    ok = (view_dir / "index.blade.php").is_file() and (view_dir / "events.blade.php").is_file()
    return {"id": "RRH-09", "name": "Blade views exist (index + events)", "status": "PASS" if ok else "FAIL",
            "detail": str(view_dir)}


def check_three_tables_in_migration() -> dict:
    pattern = "create_redpanda_recovery_hardening_tables"
    migrations = list((BASE_DIR / "database" / "migrations").glob(f"*{pattern}*"))
    if not migrations:
        return {"id": "RRH-10", "name": "Migration defines 3 tables", "status": "FAIL", "detail": "migration not found"}
    content = migrations[0].read_text(encoding="utf-8")
    tables = ["redpanda_topic_health_runs", "redpanda_consumer_group_health_runs", "redpanda_recovery_events"]
    missing = [t for t in tables if t not in content]
    ok = len(missing) == 0
    return {"id": "RRH-10", "name": "Migration defines all 3 recovery tables", "status": "PASS" if ok else "FAIL",
            "detail": f"missing: {missing}" if missing else "all present"}


def check_recovery_event_method() -> dict:
    path = BASE_DIR / "app" / "Services" / "RedpandaRecoveryHardeningService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "recordRecoveryEvent" in content
    return {"id": "RRH-11", "name": "recordRecoveryEvent() method exists in service", "status": "PASS" if ok else "FAIL",
            "detail": "RedpandaRecoveryHardeningService::recordRecoveryEvent()"}


def check_hunt_domains_added() -> dict:
    path = BASE_DIR / "app" / "Services" / "ThreatHuntingService.php"
    content = path.read_text(encoding="utf-8")
    domains = ["redpanda_topic_health_runs", "redpanda_consumer_group_health_runs", "redpanda_recovery_events"]
    missing = [d for d in domains if d not in content]
    ok = len(missing) == 0
    return {"id": "RRH-12", "name": "Hunt domains registered in ThreatHuntingService", "status": "PASS" if ok else "FAIL",
            "detail": f"missing: {missing}" if missing else "all 3 domains registered"}


# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------

CHECKS = [
    check_service_exists, check_expected_topics_defined, check_consumer_groups_defined,
    check_migration_exists, check_controller_exists, check_routes_registered,
    check_rbac_permissions, check_topic_bootstrap_script_exists, check_views_exist,
    check_three_tables_in_migration, check_recovery_event_method, check_hunt_domains_added,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed = sum(1 for r in results if r["status"] == "PASS")
    warned = sum(1 for r in results if r["status"] == "WARN")
    failed = sum(1 for r in results if r["status"] == "FAIL")
    overall = "PASS" if failed == 0 else "FAIL"
    return {
        "validator": "xdr_redpanda_runtime_recovery_validate",
        "enterprise_task": "ENTERPRISE-066",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": overall, "results": results,
    }


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestRedpandaRecoveryValidator(unittest.TestCase):

    def test_rrh01_service_exists(self):
        self.assertEqual(check_service_exists()["status"], "PASS")

    def test_rrh02_topics_defined(self):
        self.assertEqual(check_expected_topics_defined()["status"], "PASS")

    def test_rrh03_consumer_groups_defined(self):
        self.assertEqual(check_consumer_groups_defined()["status"], "PASS")

    def test_rrh04_migration_exists(self):
        self.assertEqual(check_migration_exists()["status"], "PASS")

    def test_rrh05_controller_exists(self):
        self.assertEqual(check_controller_exists()["status"], "PASS")

    def test_rrh06_routes_registered(self):
        self.assertEqual(check_routes_registered()["status"], "PASS")

    def test_rrh07_rbac_permissions(self):
        self.assertEqual(check_rbac_permissions()["status"], "PASS")

    def test_rrh08_bootstrap_script(self):
        r = check_topic_bootstrap_script_exists()
        self.assertIn(r["status"], ("PASS", "WARN"))

    def test_rrh09_views_exist(self):
        self.assertEqual(check_views_exist()["status"], "PASS")

    def test_rrh10_three_tables(self):
        self.assertEqual(check_three_tables_in_migration()["status"], "PASS")

    def test_rrh11_recovery_event_method(self):
        self.assertEqual(check_recovery_event_method()["status"], "PASS")

    def test_rrh12_hunt_domains(self):
        self.assertEqual(check_hunt_domains_added()["status"], "PASS")

    def test_overall_no_failures(self):
        report = run_checks()
        self.assertEqual(report["failed"], 0,
                         f"Failed: {[r for r in report['results'] if r['status'] == 'FAIL']}")

    def test_report_structure(self):
        report = run_checks()
        for key in ("validator", "enterprise_task", "checks_total", "overall", "results"):
            self.assertIn(key, report)

    def test_all_results_have_status(self):
        for r in run_checks()["results"]:
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
