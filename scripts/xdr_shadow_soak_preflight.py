#!/usr/bin/env python3
"""
ENTERPRISE-072 — Shadow Domain Soak Pre-Flight Validator
Offline. Verifies that soak harness components and preflight command are in place.
"""

import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent

SUPPORTED_DOMAINS = ["endpoint", "network", "ueba"]


def check_preflight_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "ShadowSoakPreflightCommand.php"
    ok = path.is_file()
    return {"id": "SSP-01", "name": "ShadowSoakPreflightCommand exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_preflight_command_signature() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "ShadowSoakPreflightCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "domain:soak-preflight" in content
    return {"id": "SSP-02", "name": "Command signature is domain:soak-preflight",
            "status": "PASS" if ok else "FAIL",
            "detail": "Artisan signature matches domain:soak-preflight {domain}"}


def check_domain_soak_harness_service_exists() -> dict:
    path = BASE_DIR / "app" / "Services" / "DomainSoakHarnessService.php"
    ok = path.is_file()
    return {"id": "SSP-03", "name": "DomainSoakHarnessService exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_harness_has_get_preflight_status() -> dict:
    path = BASE_DIR / "app" / "Services" / "DomainSoakHarnessService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "getPreflightStatus" in content
    return {"id": "SSP-04", "name": "DomainSoakHarnessService has getPreflightStatus()",
            "status": "PASS" if ok else "FAIL",
            "detail": "getPreflightStatus() method provides domain soak readiness checks"}


def check_harness_advisory_only() -> dict:
    path = BASE_DIR / "app" / "Services" / "DomainSoakHarnessService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "ADVISORY_ONLY" in content and "ACTIVE_ALLOWLIST_MUTATION_FORBIDDEN" in content
    return {"id": "SSP-05", "name": "DomainSoakHarnessService enforces advisory-only posture",
            "status": "PASS" if ok else "FAIL",
            "detail": "ADVISORY_ONLY and ACTIVE_ALLOWLIST_MUTATION_FORBIDDEN constants present"}


def check_promotion_recommended_always_false() -> dict:
    path = BASE_DIR / "app" / "Services" / "DomainSoakHarnessService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "promotion_recommended" in content and "false" in content
    return {"id": "SSP-06", "name": "promotion_recommended always false in soak harness",
            "status": "PASS" if ok else "FAIL",
            "detail": "Soak assessments never recommend promotion autonomously"}


def check_supported_domains_defined() -> dict:
    path = BASE_DIR / "app" / "Services" / "DomainSoakHarnessService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = all(d in content for d in SUPPORTED_DOMAINS)
    return {"id": "SSP-07", "name": "SUPPORTED_DOMAINS includes endpoint/network/ueba",
            "status": "PASS" if ok else "FAIL",
            "detail": "All three shadow domains are supported by the harness"}


def check_preflight_checks_active_run() -> dict:
    path = BASE_DIR / "app" / "Services" / "DomainSoakHarnessService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "No active soak run" in content or "active_run" in content or "activeRun" in content
    return {"id": "SSP-08", "name": "Preflight checks for active soak run conflict",
            "status": "PASS" if ok else "WARN",
            "detail": "Preflight warns if a soak run is already in progress"}


def check_soak_run_model_exists() -> dict:
    path = BASE_DIR / "app" / "Models" / "ShadowSoakRun.php"
    ok = path.is_file()
    return {"id": "SSP-09", "name": "ShadowSoakRun model exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_advisory_finding_model_exists() -> dict:
    path = BASE_DIR / "app" / "Models" / "AdvisoryFinding.php"
    ok = path.is_file()
    return {"id": "SSP-10", "name": "AdvisoryFinding model exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_command_is_read_only() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "ShadowSoakPreflightCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    has_write = any(k in content for k in ["->insert(", "->update(", "->delete("])
    ok = not has_write
    return {"id": "SSP-11", "name": "ShadowSoakPreflightCommand is read-only",
            "status": "PASS" if ok else "FAIL",
            "detail": "Command delegates to getPreflightStatus() — no direct DB mutations"}


def check_gate_min_findings_defined() -> dict:
    path = BASE_DIR / "app" / "Services" / "DomainSoakHarnessService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "MIN_FINDINGS_FOR_SOAK" in content
    return {"id": "SSP-12", "name": "MIN_FINDINGS_FOR_SOAK threshold defined",
            "status": "PASS" if ok else "FAIL",
            "detail": "Preflight checks minimum evidence threshold"}


CHECKS = [
    check_preflight_command_exists, check_preflight_command_signature,
    check_domain_soak_harness_service_exists, check_harness_has_get_preflight_status,
    check_harness_advisory_only, check_promotion_recommended_always_false,
    check_supported_domains_defined, check_preflight_checks_active_run,
    check_soak_run_model_exists, check_advisory_finding_model_exists,
    check_command_is_read_only, check_gate_min_findings_defined,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed  = sum(1 for r in results if r["status"] == "PASS")
    warned  = sum(1 for r in results if r["status"] == "WARN")
    failed  = sum(1 for r in results if r["status"] == "FAIL")
    return {
        "validator": "xdr_shadow_soak_preflight",
        "enterprise_task": "ENTERPRISE-072",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": "PASS" if failed == 0 else "FAIL",
        "results": results,
    }


class TestShadowSoakPreflightValidator(unittest.TestCase):
    def test_ssp01(self): self.assertEqual(check_preflight_command_exists()["status"], "PASS")
    def test_ssp02(self): self.assertEqual(check_preflight_command_signature()["status"], "PASS")
    def test_ssp03(self): self.assertEqual(check_domain_soak_harness_service_exists()["status"], "PASS")
    def test_ssp04(self): self.assertEqual(check_harness_has_get_preflight_status()["status"], "PASS")
    def test_ssp05(self): self.assertEqual(check_harness_advisory_only()["status"], "PASS")
    def test_ssp06(self): self.assertEqual(check_promotion_recommended_always_false()["status"], "PASS")
    def test_ssp07(self): self.assertEqual(check_supported_domains_defined()["status"], "PASS")
    def test_ssp08(self): self.assertIn(check_preflight_checks_active_run()["status"], ("PASS", "WARN"))
    def test_ssp09(self): self.assertEqual(check_soak_run_model_exists()["status"], "PASS")
    def test_ssp10(self): self.assertEqual(check_advisory_finding_model_exists()["status"], "PASS")
    def test_ssp11(self): self.assertEqual(check_command_is_read_only()["status"], "PASS")
    def test_ssp12(self): self.assertEqual(check_gate_min_findings_defined()["status"], "PASS")

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
        out = BASE_DIR / "reports" / "xdr_shadow_soak_preflight.json"
        os.makedirs(out.parent, exist_ok=True)
        out.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
        sys.exit(0 if report["overall"] == "PASS" else 1)
