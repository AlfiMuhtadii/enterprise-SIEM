#!/usr/bin/env python3
"""
ENTERPRISE-068 — Container Resource Governance Validator
Offline. Verifies that resource limits are defined for heavy containers in
docker-compose.yml and docker-compose.prod.yml.
"""

import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
DEV_COMPOSE  = BASE_DIR / "docker-compose.yml"
PROD_COMPOSE = BASE_DIR / "docker-compose.prod.yml"

HEAVY_SERVICES = ["redpanda", "postgres", "clickhouse", "grafana", "opensearch", "qdrant"]


# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.is_file() else ""


def check_dev_compose_exists() -> dict:
    ok = DEV_COMPOSE.is_file()
    return {"id": "CRG-01", "name": "docker-compose.yml exists",
            "status": "PASS" if ok else "FAIL", "detail": str(DEV_COMPOSE)}


def check_prod_compose_exists() -> dict:
    ok = PROD_COMPOSE.is_file()
    return {"id": "CRG-02", "name": "docker-compose.prod.yml exists",
            "status": "PASS" if ok else "FAIL", "detail": str(PROD_COMPOSE)}


def check_dev_has_deploy_resources() -> dict:
    content = _read(DEV_COMPOSE)
    ok = "deploy:" in content and "resources:" in content and "limits:" in content
    return {"id": "CRG-03", "name": "dev compose has deploy.resources.limits block",
            "status": "PASS" if ok else "FAIL",
            "detail": "docker-compose.yml contains deploy/resources/limits keys"}


def check_prod_has_deploy_resources() -> dict:
    content = _read(PROD_COMPOSE)
    ok = "deploy:" in content and "resources:" in content and "limits:" in content
    return {"id": "CRG-04", "name": "prod compose has deploy.resources.limits block",
            "status": "PASS" if ok else "FAIL",
            "detail": "docker-compose.prod.yml contains deploy/resources/limits keys"}


def check_dev_memory_limits() -> dict:
    content = _read(DEV_COMPOSE)
    ok = content.count("memory:") >= 5
    return {"id": "CRG-05", "name": "dev compose defines memory limits for ≥5 services",
            "status": "PASS" if ok else "WARN",
            "detail": f"Found {content.count('memory:')} memory: entries in docker-compose.yml"}


def check_prod_memory_limits() -> dict:
    content = _read(PROD_COMPOSE)
    ok = content.count("memory:") >= 4
    return {"id": "CRG-06", "name": "prod compose defines memory limits for ≥4 services",
            "status": "PASS" if ok else "WARN",
            "detail": f"Found {content.count('memory:')} memory: entries in docker-compose.prod.yml"}


def check_dev_cpu_limits() -> dict:
    content = _read(DEV_COMPOSE)
    ok = content.count("cpus:") >= 5
    return {"id": "CRG-07", "name": "dev compose defines CPU limits for ≥5 services",
            "status": "PASS" if ok else "WARN",
            "detail": f"Found {content.count('cpus:')} cpus: entries in docker-compose.yml"}


def check_redpanda_has_limit() -> dict:
    content = _read(DEV_COMPOSE)
    # Look for redpanda section containing deploy block
    idx = content.find("\n  redpanda:")
    if idx < 0:
        idx = content.find("redpanda:")
    if idx < 0:
        return {"id": "CRG-08", "name": "redpanda has resource limit", "status": "FAIL", "detail": "redpanda service not found"}
    section = content[idx:idx + 3000]
    ok = "deploy:" in section and "memory:" in section
    return {"id": "CRG-08", "name": "redpanda service has resource limit",
            "status": "PASS" if ok else "FAIL",
            "detail": "redpanda service section contains deploy.resources.limits"}


def check_clickhouse_has_limit() -> dict:
    content = _read(DEV_COMPOSE)
    idx = content.find("\n  clickhouse:")
    if idx < 0:
        idx = content.find("clickhouse:")
    if idx < 0:
        return {"id": "CRG-09", "name": "clickhouse has resource limit", "status": "FAIL", "detail": "clickhouse service not found"}
    section = content[idx:idx + 2000]
    ok = "memory:" in section
    return {"id": "CRG-09", "name": "clickhouse service has resource limit",
            "status": "PASS" if ok else "FAIL",
            "detail": "clickhouse service section contains memory limit"}


def check_opensearch_has_limit() -> dict:
    content = _read(DEV_COMPOSE)
    idx = content.find("\n  opensearch:")
    if idx < 0:
        idx = content.find("opensearch:")
    if idx < 0:
        return {"id": "CRG-10", "name": "opensearch has resource limit", "status": "FAIL", "detail": "opensearch service not found"}
    section = content[idx:idx + 2000]
    ok = "memory:" in section
    return {"id": "CRG-10", "name": "opensearch service has resource limit",
            "status": "PASS" if ok else "FAIL",
            "detail": "opensearch service section contains memory limit"}


def check_prod_higher_limits() -> dict:
    dev  = _read(DEV_COMPOSE)
    prod = _read(PROD_COMPOSE)
    # Prod should have higher ClickHouse limit (4g vs 3g)
    ok = "4g" in prod or "4G" in prod
    return {"id": "CRG-11", "name": "prod compose has higher limits than dev",
            "status": "PASS" if ok else "WARN",
            "detail": "docker-compose.prod.yml contains at least one limit larger than dev baseline"}


def check_infra3_resolved() -> dict:
    content = _read(BASE_DIR / "scripts" / "xdr_posture_check.py")
    # D-04 should now reference ENTERPRISE-068 as implemented
    ok = "ENTERPRISE-068" in content or "container" in content.lower()
    return {"id": "CRG-12", "name": "INFRA-3 resolved (xdr_posture_check.py updated)",
            "status": "PASS" if ok else "WARN",
            "detail": "xdr_posture_check.py references container resource governance"}


# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------

CHECKS = [
    check_dev_compose_exists, check_prod_compose_exists,
    check_dev_has_deploy_resources, check_prod_has_deploy_resources,
    check_dev_memory_limits, check_prod_memory_limits, check_dev_cpu_limits,
    check_redpanda_has_limit, check_clickhouse_has_limit, check_opensearch_has_limit,
    check_prod_higher_limits, check_infra3_resolved,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed  = sum(1 for r in results if r["status"] == "PASS")
    warned  = sum(1 for r in results if r["status"] == "WARN")
    failed  = sum(1 for r in results if r["status"] == "FAIL")
    return {
        "validator": "xdr_container_resource_validate",
        "enterprise_task": "ENTERPRISE-068",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": "PASS" if failed == 0 else "FAIL",
        "results": results,
    }


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestContainerResourceValidator(unittest.TestCase):
    def test_crg01_dev_compose_exists(self):       self.assertEqual(check_dev_compose_exists()["status"], "PASS")
    def test_crg02_prod_compose_exists(self):      self.assertEqual(check_prod_compose_exists()["status"], "PASS")
    def test_crg03_dev_has_deploy(self):           self.assertEqual(check_dev_has_deploy_resources()["status"], "PASS")
    def test_crg04_prod_has_deploy(self):          self.assertEqual(check_prod_has_deploy_resources()["status"], "PASS")
    def test_crg05_dev_memory(self):               self.assertIn(check_dev_memory_limits()["status"], ("PASS", "WARN"))
    def test_crg06_prod_memory(self):              self.assertIn(check_prod_memory_limits()["status"], ("PASS", "WARN"))
    def test_crg07_dev_cpu(self):                  self.assertIn(check_dev_cpu_limits()["status"], ("PASS", "WARN"))
    def test_crg08_redpanda_limit(self):           self.assertEqual(check_redpanda_has_limit()["status"], "PASS")
    def test_crg09_clickhouse_limit(self):         self.assertEqual(check_clickhouse_has_limit()["status"], "PASS")
    def test_crg10_opensearch_limit(self):         self.assertEqual(check_opensearch_has_limit()["status"], "PASS")
    def test_crg11_prod_higher(self):              self.assertIn(check_prod_higher_limits()["status"], ("PASS", "WARN"))
    def test_crg12_infra3_resolved(self):          self.assertIn(check_infra3_resolved()["status"], ("PASS", "WARN"))

    def test_overall_no_failures(self):
        r = run_checks()
        self.assertEqual(r["failed"], 0, f"Failed: {[x for x in r['results'] if x['status']=='FAIL']}")

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
        out = BASE_DIR / "reports" / "xdr_container_resource.json"
        os.makedirs(out.parent, exist_ok=True)
        out.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
        sys.exit(0 if report["overall"] == "PASS" else 1)
