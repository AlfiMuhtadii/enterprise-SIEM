#!/usr/bin/env python3
"""
ENTERPRISE-046: Tenant Strict Mode & Null Backfill Closure Validator

Validates that the tenant isolation infrastructure is complete:
- TenantBoundaryService constants (MUTABLE_TABLES, APPEND_ONLY_ISOLATED_TABLES)
- TenantNullBackfillCommand exists
- Migration plan document exists
- Mutable/append-only tables correctly classified

Exit codes: 0=PASS, 1=FAIL, 2=ERROR
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).parent.parent

BOUNDARY_SERVICE     = ROOT / "app" / "Services" / "TenantBoundaryService.php"
AUTHORITY_SERVICE    = ROOT / "app" / "Services" / "TenantContextAuthority.php"
AUDIT_COMMAND        = ROOT / "app" / "Console" / "Commands" / "TenantNullAuditCommand.php"
BACKFILL_COMMAND     = ROOT / "app" / "Console" / "Commands" / "TenantNullBackfillCommand.php"
MIGRATION_PLAN       = ROOT / "docs" / "security" / "TENANT_NULL_MIGRATION_PLAN.md"
ENV_EXAMPLE          = ROOT / ".env.example"

EXPECTED_MUTABLE  = {"security_alerts", "security_incidents", "dlq_records"}
EXPECTED_ISOLATED = 17  # total entries in ISOLATED_TABLES


def run_checks() -> list[dict]:
    results = []

    def check(code: str, desc: str, passed: bool, detail: str = "") -> None:
        results.append({"code": code, "description": desc,
                        "status": "PASS" if passed else "FAIL", "detail": detail})

    # TB-01: Core service files exist
    check("TB-01", "TenantBoundaryService.php exists",     BOUNDARY_SERVICE.exists())
    check("TB-02", "TenantContextAuthority.php exists",    AUTHORITY_SERVICE.exists())
    check("TB-03", "TenantNullAuditCommand.php exists",    AUDIT_COMMAND.exists())
    check("TB-04", "TenantNullBackfillCommand.php exists", BACKFILL_COMMAND.exists())
    check("TB-05", "TENANT_NULL_MIGRATION_PLAN.md exists", MIGRATION_PLAN.exists())

    if not BOUNDARY_SERVICE.exists():
        return results

    src = BOUNDARY_SERVICE.read_text(encoding="utf-8")

    # TB-06: MUTABLE_TABLES constant exists
    has_mutable = "MUTABLE_TABLES" in src
    check("TB-06", "MUTABLE_TABLES constant declared in TenantBoundaryService",
          has_mutable,
          "MUTABLE_TABLES not found in service" if not has_mutable else "")

    # TB-07: MUTABLE_TABLES covers required tables
    if has_mutable:
        missing = [t for t in EXPECTED_MUTABLE if t not in src]
        check("TB-07", f"MUTABLE_TABLES includes: {', '.join(sorted(EXPECTED_MUTABLE))}",
              len(missing) == 0,
              f"Missing: {missing}" if missing else "")
    else:
        check("TB-07", "MUTABLE_TABLES content check", False, "MUTABLE_TABLES not declared")

    # TB-08: APPEND_ONLY_ISOLATED_TABLES constant exists
    has_append_only = "APPEND_ONLY_ISOLATED_TABLES" in src
    check("TB-08", "APPEND_ONLY_ISOLATED_TABLES constant declared",
          has_append_only,
          "APPEND_ONLY_ISOLATED_TABLES not found" if not has_append_only else "")

    # TB-09: STRICT_MODE_DEFAULT is false (legacy default preserved)
    strict_default = re.search(r"STRICT_MODE_DEFAULT\s*=\s*(true|false)", src)
    is_false = strict_default and strict_default.group(1) == "false"
    check("TB-09", "STRICT_MODE_DEFAULT = false (legacy default preserved)",
          bool(is_false),
          f"Got: {strict_default.group(0) if strict_default else 'not found'}" if not is_false else "")

    # TB-10: TenantNullBackfillCommand has --dry-run and --tenant options
    if BACKFILL_COMMAND.exists():
        cmd_src = BACKFILL_COMMAND.read_text(encoding="utf-8")
        has_dry_run = "--dry-run" in cmd_src
        has_tenant  = "--tenant" in cmd_src
        has_batch   = "--batch" in cmd_src
        check("TB-10", "TenantNullBackfillCommand has --dry-run, --tenant, --batch flags",
              has_dry_run and has_tenant and has_batch,
              f"Missing: {[f for f, v in [('--dry-run', has_dry_run), ('--tenant', has_tenant), ('--batch', has_batch)] if not v]}")
    else:
        check("TB-10", "TenantNullBackfillCommand flag check", False, "File not found")

    # TB-11 (advisory): XDR_TENANT_STRICT_MODE in .env.example
    if ENV_EXAMPLE.exists():
        env_src = ENV_EXAMPLE.read_text(encoding="utf-8")
        has_strict = "XDR_TENANT_STRICT_MODE" in env_src
        check("TB-11", "XDR_TENANT_STRICT_MODE declared in .env.example",
              has_strict, ".env.example missing XDR_TENANT_STRICT_MODE" if not has_strict else "")
    else:
        check("TB-11", ".env.example exists for strict mode key check", False, str(ENV_EXAMPLE))

    # TB-12: dlq_records UPDATE policy documented
    if BACKFILL_COMMAND.exists():
        cmd_src = BACKFILL_COMMAND.read_text(encoding="utf-8")
        mentions_dlq = "dlq_records" in cmd_src
        check("TB-12", "TenantNullBackfillCommand includes dlq_records as mutable target",
              mentions_dlq,
              "dlq_records not referenced" if not mentions_dlq else "")
    else:
        check("TB-12", "dlq_records mutable target check", False, "Command file not found")

    # TB-13: advisory_findings NOT in MUTABLE_TABLES
    if has_mutable:
        mutable_block_match = re.search(
            r"MUTABLE_TABLES\s*=\s*\[(.*?)\];", src, re.DOTALL
        )
        if mutable_block_match:
            mutable_block = mutable_block_match.group(1)
            advisory_in_mutable = "advisory_findings" in mutable_block
            check("TB-13", "advisory_findings is NOT in MUTABLE_TABLES (append-only protection)",
                  not advisory_in_mutable,
                  "advisory_findings should NOT be in MUTABLE_TABLES" if advisory_in_mutable else "")
        else:
            check("TB-13", "MUTABLE_TABLES block parse", False, "Could not parse MUTABLE_TABLES block")
    else:
        check("TB-13", "advisory_findings not in MUTABLE_TABLES", False, "MUTABLE_TABLES not declared")

    return results


def main() -> int:
    results = run_checks()
    failures = [r for r in results if r["status"] == "FAIL"]
    passed   = [r for r in results if r["status"] == "PASS"]

    print(f"\n{'='*60}")
    print("ENTERPRISE-046 -- Tenant Strict Mode & Null Backfill Closure")
    print(f"{'='*60}")

    for r in results:
        icon   = "PASS" if r["status"] == "PASS" else "FAIL"
        detail = f" -- {r['detail']}" if r["detail"] else ""
        print(f"  [{icon}] {r['code']}: {r['description']}{detail}")

    print(f"\n{'='*60}")
    print(f"Result: {'PASS' if not failures else 'FAIL'} ({len(passed)} passed, {len(failures)} failed)")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
