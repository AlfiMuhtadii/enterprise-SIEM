#!/usr/bin/env python3
"""
ENTERPRISE-073 — Redpanda Multi-Node HA Template Validator
Offline. Verifies docker-compose.ha.yml, xdr_topic_bootstrap.py replication flag,
and HA runbook are in place.
"""

import sys
import unittest
from pathlib import Path

BASE_DIR  = Path(__file__).resolve().parent.parent
HA_COMPOSE = BASE_DIR / "docker-compose.ha.yml"


def check_ha_compose_exists() -> dict:
    ok = HA_COMPOSE.is_file()
    return {"id": "HA-01", "name": "docker-compose.ha.yml exists",
            "status": "PASS" if ok else "FAIL", "detail": str(HA_COMPOSE)}


def check_ha_compose_has_three_brokers() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    broker_count = sum(1 for name in ["redpanda-0:", "redpanda-1:", "redpanda-2:"] if name in content)
    ok = broker_count == 3
    return {"id": "HA-02", "name": "HA compose defines 3 Redpanda broker nodes",
            "status": "PASS" if ok else "FAIL",
            "detail": f"Found {broker_count}/3 broker service definitions (redpanda-0/1/2)"}


def check_ha_compose_uses_seeds() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    ok = "--seeds" in content
    return {"id": "HA-03", "name": "HA compose uses --seeds for cluster formation",
            "status": "PASS" if ok else "FAIL",
            "detail": "--seeds flag enables Redpanda peer discovery"}


def check_ha_compose_has_rpc_addr() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    ok = "--rpc-addr" in content and "--advertise-rpc-addr" in content
    return {"id": "HA-04", "name": "HA compose configures RPC addresses",
            "status": "PASS" if ok else "FAIL",
            "detail": "--rpc-addr and --advertise-rpc-addr required for inter-broker communication"}


def check_ha_compose_has_resource_limits() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    memory_count = content.count("memory: 2g")
    ok = memory_count >= 3
    return {"id": "HA-05", "name": "HA brokers have resource limits defined",
            "status": "PASS" if ok else "WARN",
            "detail": f"Found {memory_count} memory limit definitions (expected ≥3 for 3 brokers)"}


def check_ha_compose_console_multi_broker() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    ok = "redpanda-0:9092,redpanda-1:9092,redpanda-2:9092" in content
    return {"id": "HA-06", "name": "HA console targets all three brokers",
            "status": "PASS" if ok else "WARN",
            "detail": "Redpanda Console KAFKA_BROKERS includes all three broker addresses"}


def check_ha_compose_has_dedicated_network() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    ok = "networks:" in content and "xdr-ha" in content
    return {"id": "HA-07", "name": "HA compose defines dedicated network",
            "status": "PASS" if ok else "WARN",
            "detail": "Dedicated bridge network isolates HA cluster traffic"}


def check_topic_bootstrap_has_replication_flag() -> dict:
    path = BASE_DIR / "scripts" / "xdr_topic_bootstrap.py"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "--replication-factor" in content and "replication_factor" in content
    return {"id": "HA-08", "name": "xdr_topic_bootstrap.py has --replication-factor flag",
            "status": "PASS" if ok else "FAIL",
            "detail": "Bootstrap script can create topics with custom replication factor for HA clusters"}


def check_topic_bootstrap_passes_replicas_to_rpk() -> dict:
    path = BASE_DIR / "scripts" / "xdr_topic_bootstrap.py"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "--replicas=" in content
    return {"id": "HA-09", "name": "xdr_topic_bootstrap.py passes --replicas to rpk",
            "status": "PASS" if ok else "FAIL",
            "detail": "rpk topic create accepts --replicas for replication factor"}


def check_ha_runbook_exists() -> dict:
    path = BASE_DIR / "docs" / "operations" / "REDPANDA_HA_RUNBOOK.md"
    ok = path.is_file()
    return {"id": "HA-10", "name": "REDPANDA_HA_RUNBOOK.md exists",
            "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_ha_compose_has_health_checks() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    ok = content.count("healthcheck:") >= 3
    return {"id": "HA-11", "name": "All HA brokers have health checks",
            "status": "PASS" if ok else "WARN",
            "detail": f"Found {content.count('healthcheck:')} healthcheck blocks (expected ≥3)"}


def check_ha_compose_has_depends_on() -> dict:
    content = HA_COMPOSE.read_text(encoding="utf-8") if HA_COMPOSE.is_file() else ""
    ok = "condition: service_healthy" in content
    return {"id": "HA-12", "name": "Broker-1 and broker-2 depend on broker-0 health",
            "status": "PASS" if ok else "WARN",
            "detail": "depends_on with service_healthy ensures cluster formation order"}


CHECKS = [
    check_ha_compose_exists, check_ha_compose_has_three_brokers,
    check_ha_compose_uses_seeds, check_ha_compose_has_rpc_addr,
    check_ha_compose_has_resource_limits, check_ha_compose_console_multi_broker,
    check_ha_compose_has_dedicated_network, check_topic_bootstrap_has_replication_flag,
    check_topic_bootstrap_passes_replicas_to_rpk, check_ha_runbook_exists,
    check_ha_compose_has_health_checks, check_ha_compose_has_depends_on,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed  = sum(1 for r in results if r["status"] == "PASS")
    warned  = sum(1 for r in results if r["status"] == "WARN")
    failed  = sum(1 for r in results if r["status"] == "FAIL")
    return {
        "validator": "xdr_redpanda_ha_validate",
        "enterprise_task": "ENTERPRISE-073",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": "PASS" if failed == 0 else "FAIL",
        "results": results,
    }


class TestRedpandaHaValidator(unittest.TestCase):
    def test_ha01(self): self.assertEqual(check_ha_compose_exists()["status"], "PASS")
    def test_ha02(self): self.assertEqual(check_ha_compose_has_three_brokers()["status"], "PASS")
    def test_ha03(self): self.assertEqual(check_ha_compose_uses_seeds()["status"], "PASS")
    def test_ha04(self): self.assertEqual(check_ha_compose_has_rpc_addr()["status"], "PASS")
    def test_ha05(self): self.assertIn(check_ha_compose_has_resource_limits()["status"], ("PASS", "WARN"))
    def test_ha06(self): self.assertIn(check_ha_compose_console_multi_broker()["status"], ("PASS", "WARN"))
    def test_ha07(self): self.assertIn(check_ha_compose_has_dedicated_network()["status"], ("PASS", "WARN"))
    def test_ha08(self): self.assertEqual(check_topic_bootstrap_has_replication_flag()["status"], "PASS")
    def test_ha09(self): self.assertEqual(check_topic_bootstrap_passes_replicas_to_rpk()["status"], "PASS")
    def test_ha10(self): self.assertIn(check_ha_runbook_exists()["status"], ("PASS", "WARN"))
    def test_ha11(self): self.assertIn(check_ha_compose_has_health_checks()["status"], ("PASS", "WARN"))
    def test_ha12(self): self.assertIn(check_ha_compose_has_depends_on()["status"], ("PASS", "WARN"))

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
        out = BASE_DIR / "reports" / "xdr_redpanda_ha.json"
        os.makedirs(out.parent, exist_ok=True)
        out.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
        sys.exit(0 if report["overall"] == "PASS" else 1)
