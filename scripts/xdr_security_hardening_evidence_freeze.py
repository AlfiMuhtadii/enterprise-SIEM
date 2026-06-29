#!/usr/bin/env python3
"""
ENTERPRISE-074: Security Hardening Evidence Freeze Validator
Advisory-only offline validator — reads files, never modifies anything.

Checks SHF-01 through SHF-12.
Usage: python scripts/xdr_security_hardening_evidence_freeze.py [--output=<path>]
"""

import argparse
import json
import os
import re
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent


def check_shf_01_config_cache_auth_secret() -> dict:
    """SHF-01: InternalAuthService uses config() not env() for internal auth secret."""
    path = BASE_DIR / "app" / "Services" / "InternalAuthService.php"
    if not path.exists():
        return {"id": "SHF-01", "status": "FAIL", "detail": "InternalAuthService.php not found"}
    content = path.read_text(encoding="utf-8")
    uses_config = "config('xdr.internal_auth_secret'" in content
    no_raw_env = "env('XDR_INTERNAL_AUTH_SECRET'" not in content
    passed = uses_config and no_raw_env
    return {
        "id": "SHF-01",
        "status": "PASS" if passed else "FAIL",
        "detail": "config() used — config:cache bypass prevented." if passed
                  else "env() still present — config:cache bypass possible.",
        "uses_config": uses_config,
        "no_raw_env": no_raw_env,
    }


def check_shf_02_internal_auth_secret_in_config() -> dict:
    """SHF-02: xdr.internal_auth_secret mapped in config/xdr.php."""
    path = BASE_DIR / "config" / "xdr.php"
    if not path.exists():
        return {"id": "SHF-02", "status": "FAIL", "detail": "config/xdr.php not found"}
    content = path.read_text(encoding="utf-8")
    mapped = "'internal_auth_secret'" in content and "XDR_INTERNAL_AUTH_SECRET" in content
    return {
        "id": "SHF-02",
        "status": "PASS" if mapped else "FAIL",
        "detail": "internal_auth_secret key present in config/xdr.php." if mapped
                  else "internal_auth_secret key missing from config/xdr.php.",
        "mapped": mapped,
    }


def check_shf_03_per_agent_hmac_secret_migration() -> dict:
    """SHF-03: Migration adding hmac_secret to endpoint_agents exists."""
    migrations = list((BASE_DIR / "database" / "migrations").glob(
        "*add_hmac_secret_and_tenant_id_to_endpoint_agents*"
    ))
    found = len(migrations) > 0
    return {
        "id": "SHF-03",
        "status": "PASS" if found else "FAIL",
        "detail": f"Migration found: {migrations[0].name}" if found
                  else "Migration add_hmac_secret_and_tenant_id_to_endpoint_agents not found.",
        "migration_found": found,
    }


def check_shf_04_endpoint_fleet_isolated_in_service() -> dict:
    """SHF-04: TenantBoundaryService ISOLATED_TABLES contains endpoint_agents."""
    path = BASE_DIR / "app" / "Services" / "TenantBoundaryService.php"
    if not path.exists():
        return {"id": "SHF-04", "status": "FAIL", "detail": "TenantBoundaryService.php not found"}
    content = path.read_text(encoding="utf-8")
    in_isolated = re.search(r"ISOLATED_TABLES\s*=\s*\[.*?endpoint_agents.*?\]", content, re.DOTALL)
    not_in_unisolated_block = "endpoint_agents" not in re.findall(
        r"UNISOLATED_TABLES\s*=\s*\[(.*?)\]", content, re.DOTALL
    )[0] if re.search(r"UNISOLATED_TABLES\s*=\s*\[", content) else True
    passed = bool(in_isolated) and not_in_unisolated_block
    return {
        "id": "SHF-04",
        "status": "PASS" if passed else "FAIL",
        "detail": "endpoint_agents in ISOLATED_TABLES, not in UNISOLATED_TABLES." if passed
                  else "endpoint_agents isolation gap in TenantBoundaryService.",
        "in_isolated": bool(in_isolated),
    }


def check_shf_05_workflow_tables_migration() -> dict:
    """SHF-05: Migration adding tenant_id to investigations/response_plans/entities/threat_hunts exists."""
    migrations = list((BASE_DIR / "database" / "migrations").glob(
        "*add_tenant_id_to_unscoped_tables*"
    ))
    found = len(migrations) > 0
    return {
        "id": "SHF-05",
        "status": "PASS" if found else "FAIL",
        "detail": f"Migration found: {migrations[0].name}" if found
                  else "Migration add_tenant_id_to_unscoped_tables not found.",
        "migration_found": found,
    }


def check_shf_06_rate_limit_bypass_fix() -> dict:
    """SHF-06: ingestion-gateway validates X-Tenant-ID vs payload tenant_id."""
    path = BASE_DIR / "services" / "ingestion-gateway" / "main.go"
    if not path.exists():
        return {"id": "SHF-06", "status": "FAIL", "detail": "ingestion-gateway/main.go not found"}
    content = path.read_text(encoding="utf-8")
    has_helper = "extractPayloadTenantID" in content
    has_reject = "tenant_id_header_mismatch" in content
    passed = has_helper and has_reject
    return {
        "id": "SHF-06",
        "status": "PASS" if passed else "FAIL",
        "detail": "X-Tenant-ID header validated against payload tenant_id." if passed
                  else "ingestion-gateway missing tenant_id header validation.",
        "has_helper": has_helper,
        "has_reject": has_reject,
    }


def check_shf_07_rls_scaffold_migration() -> dict:
    """SHF-07: RLS scaffold migration (advisory, no enforcement) exists."""
    migrations = list((BASE_DIR / "database" / "migrations").glob(
        "*scaffold_rls_policies*"
    ))
    found = len(migrations) > 0
    if found:
        content = migrations[0].read_text(encoding="utf-8")
        no_enforce = not re.search(
            r"ALTER\s+TABLE.*ENABLE\s+ROW\s+LEVEL\s+SECURITY",
            content, re.IGNORECASE | re.DOTALL
        )
    else:
        no_enforce = False
    passed = found and no_enforce
    return {
        "id": "SHF-07",
        "status": "PASS" if passed else "FAIL",
        "detail": "RLS scaffold migration present, no enforcement." if passed
                  else ("RLS scaffold found but contains enforcement." if found else "RLS scaffold migration missing."),
        "migration_found": found,
        "no_enforcement": no_enforce,
    }


def check_shf_08_container_resource_limits() -> dict:
    """SHF-08: docker-compose.yml has deploy.resources.limits."""
    path = BASE_DIR / "docker-compose.yml"
    if not path.exists():
        return {"id": "SHF-08", "status": "FAIL", "detail": "docker-compose.yml not found"}
    content = path.read_text(encoding="utf-8")
    has_resources = "resources:" in content
    has_limits = "limits:" in content
    passed = has_resources and has_limits
    return {
        "id": "SHF-08",
        "status": "PASS" if passed else "FAIL",
        "detail": "deploy.resources.limits present in docker-compose.yml." if passed
                  else "docker-compose.yml missing resource limits.",
        "has_resources": has_resources,
        "has_limits": has_limits,
    }


def check_shf_09_freeze_service_exists() -> dict:
    """SHF-09: SecurityHardeningEvidenceFreezeService exists with correct constants."""
    path = BASE_DIR / "app" / "Services" / "SecurityHardeningEvidenceFreezeService.php"
    if not path.exists():
        return {"id": "SHF-09", "status": "FAIL", "detail": "SecurityHardeningEvidenceFreezeService.php not found"}
    content = path.read_text(encoding="utf-8")
    has_advisory = "ADVISORY_ONLY" in content
    has_self_approve = "SELF_APPROVE_BLOCKED" in content
    has_version = "FREEZE_VERSION" in content
    passed = has_advisory and has_self_approve and has_version
    return {
        "id": "SHF-09",
        "status": "PASS" if passed else "FAIL",
        "detail": "SecurityHardeningEvidenceFreezeService has required constants." if passed
                  else "Service missing advisory constants.",
        "has_advisory": has_advisory,
        "has_self_approve": has_self_approve,
        "has_version": has_version,
    }


def check_shf_10_freeze_migration_exists() -> dict:
    """SHF-10: ENTERPRISE-074 migration creating freeze tables exists."""
    migrations = list((BASE_DIR / "database" / "migrations").glob(
        "*create_security_hardening_freeze_tables*"
    ))
    found = len(migrations) > 0
    return {
        "id": "SHF-10",
        "status": "PASS" if found else "FAIL",
        "detail": f"Freeze tables migration found: {migrations[0].name}" if found
                  else "create_security_hardening_freeze_tables migration missing.",
        "migration_found": found,
    }


def check_shf_11_freeze_command_exists() -> dict:
    """SHF-11: SecurityHardeningFreezeCommand exists and is advisory-only."""
    path = BASE_DIR / "app" / "Console" / "Commands" / "SecurityHardeningFreezeCommand.php"
    if not path.exists():
        return {"id": "SHF-11", "status": "FAIL", "detail": "SecurityHardeningFreezeCommand.php not found"}
    content = path.read_text(encoding="utf-8")
    has_signature = "security:hardening-freeze" in content
    no_db_write = "DB::update" not in content and "DB::delete" not in content
    passed = has_signature and no_db_write
    return {
        "id": "SHF-11",
        "status": "PASS" if passed else "FAIL",
        "detail": "SecurityHardeningFreezeCommand exists and is advisory-only." if passed
                  else "Command missing or contains DB mutations.",
        "has_signature": has_signature,
        "no_db_write": no_db_write,
    }


def check_shf_12_threat_hunting_domains_updated() -> dict:
    """SHF-12: ThreatHuntingService SUPPORTED_DOMAINS includes freeze domains (+5 = 177)."""
    path = BASE_DIR / "app" / "Services" / "ThreatHuntingService.php"
    if not path.exists():
        return {"id": "SHF-12", "status": "FAIL", "detail": "ThreatHuntingService.php not found"}
    content = path.read_text(encoding="utf-8")
    freeze_domains = [
        "security_hardening_freeze_runs",
        "security_hardening_freeze_checks",
        "security_hardening_freeze_coverage_reports",
        "security_hardening_freeze_control_evidence",
        "security_hardening_freeze_audit_events",
    ]
    missing = [d for d in freeze_domains if d not in content]
    passed = len(missing) == 0
    return {
        "id": "SHF-12",
        "status": "PASS" if passed else "FAIL",
        "detail": "All 5 freeze domains present in ThreatHuntingService." if passed
                  else f"Missing freeze domains: {missing}",
        "missing_domains": missing,
    }


ALL_CHECKS = [
    check_shf_01_config_cache_auth_secret,
    check_shf_02_internal_auth_secret_in_config,
    check_shf_03_per_agent_hmac_secret_migration,
    check_shf_04_endpoint_fleet_isolated_in_service,
    check_shf_05_workflow_tables_migration,
    check_shf_06_rate_limit_bypass_fix,
    check_shf_07_rls_scaffold_migration,
    check_shf_08_container_resource_limits,
    check_shf_09_freeze_service_exists,
    check_shf_10_freeze_migration_exists,
    check_shf_11_freeze_command_exists,
    check_shf_12_threat_hunting_domains_updated,
]


def run_all_checks():
    results = [fn() for fn in ALL_CHECKS]
    passed = sum(1 for r in results if r["status"] == "PASS")
    total  = len(results)
    overall = "PASS" if passed == total else "FAIL"
    return {"overall": overall, "passed": passed, "total": total, "checks": results}


# =============================================================================
# Unit tests
# =============================================================================

class TestSecurityHardeningEvidenceFreeze(unittest.TestCase):

    def test_shf_01_config_cache_auth_secret_returns_dict(self):
        result = check_shf_01_config_cache_auth_secret()
        self.assertIn("status", result)
        self.assertIn(result["status"], ["PASS", "FAIL"])

    def test_shf_01_config_cache_auth_secret_passes(self):
        result = check_shf_01_config_cache_auth_secret()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_02_internal_auth_secret_mapped_passes(self):
        result = check_shf_02_internal_auth_secret_in_config()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_03_hmac_secret_migration_passes(self):
        result = check_shf_03_per_agent_hmac_secret_migration()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_04_endpoint_fleet_isolated_passes(self):
        result = check_shf_04_endpoint_fleet_isolated_in_service()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_05_workflow_tables_migration_passes(self):
        result = check_shf_05_workflow_tables_migration()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_06_rate_limit_bypass_fix_passes(self):
        result = check_shf_06_rate_limit_bypass_fix()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_07_rls_scaffold_passes(self):
        result = check_shf_07_rls_scaffold_migration()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_08_container_resource_limits_passes(self):
        result = check_shf_08_container_resource_limits()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_09_freeze_service_exists_passes(self):
        result = check_shf_09_freeze_service_exists()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_10_freeze_migration_passes(self):
        result = check_shf_10_freeze_migration_exists()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_11_freeze_command_passes(self):
        result = check_shf_11_freeze_command_exists()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_shf_12_threat_hunting_domains_passes(self):
        result = check_shf_12_threat_hunting_domains_updated()
        self.assertEqual("PASS", result["status"], result.get("detail"))

    def test_run_all_checks_returns_12_checks(self):
        report = run_all_checks()
        self.assertEqual(12, report["total"])

    def test_run_all_checks_overall_pass(self):
        report = run_all_checks()
        self.assertEqual("PASS", report["overall"],
                         f"Failed checks: {[c for c in report['checks'] if c['status'] == 'FAIL']}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="ENTERPRISE-074 Security Hardening Evidence Freeze Validator")
    parser.add_argument("--output", help="Write JSON report to this path")
    parser.add_argument("--test", action="store_true", help="Run unit tests")
    args = parser.parse_args()

    if args.test:
        sys.exit(0 if unittest.main(exit=False).result.wasSuccessful() else 1)

    report = run_all_checks()
    print(json.dumps(report, indent=2))

    if args.output:
        with open(args.output, "w") as f:
            json.dump(report, f, indent=2)
        print(f"\nReport written to: {args.output}", file=sys.stderr)

    sys.exit(0 if report["overall"] == "PASS" else 1)
