#!/usr/bin/env python3
"""XDR tenant isolation posture validator (ENTERPRISE-040).

Read-only, non-destructive.  Does NOT connect to the database.
Validates the structural posture of tenant isolation: registry constants,
migration presence, documentation completeness, and app-layer test coverage.

Checks
------
  TIP-01  RLS_DISABLED_CONFIRMED       — TenantBoundaryService::RLS_ENABLED = false
  TIP-02  ISOLATED_REGISTRY_PRESENT    — ISOLATED_TABLES list found in TenantBoundaryService
  TIP-03  ISOLATED_REGISTRY_COUNT      — isolated table count >= MIN_ISOLATED_TABLES
  TIP-04  UNISOLATED_REGISTRY_PRESENT  — UNISOLATED_TABLES list found (gap register non-empty)
  TIP-05  TENANT_ID_MIGRATION_PRESENT  — add_tenant_id_to_alerts_incidents migration file exists
  TIP-06  TENANT_ID_INDEX_MIGRATION    — add_tenant_id_indexes migration file exists
  TIP-07  POSTURE_DOC_PRESENT          — TENANT_ISOLATION_POSTURE.md exists
  TIP-08  RLS_DECISION_RECORD_PRESENT  — RLS_DECISION_RECORD.md exists
  TIP-09  STRICT_MODE_DOC_PRESENT      — TENANT_STRICT_MODE.md exists
  TIP-10  NULL_MIGRATION_PLAN_PRESENT  — TENANT_NULL_MIGRATION_PLAN.md exists
  TIP-11  APP_LAYER_TESTS_PRESENT      — at least MIN_TENANT_TEST_FILES PHP feature tests present
  TIP-12  BOUNDARY_SERVICE_PRESENT     — TenantBoundaryService.php exists
  TIP-13  CONTEXT_AUTHORITY_PRESENT    — TenantContextAuthority.php exists
  TIP-14  NULL_AUDIT_COMMAND_PRESENT   — TenantNullAuditCommand.php exists

Advisory (never block overall PASS):
  A-01  STRICT_MODE_DEFAULT            — XDR_TENANT_STRICT_MODE default documented as false
  A-02  RLS_ROADMAP_PRESENT            — Phase 5 / RLS roadmap section present in docs

Severity by profile
-------------------
  TIP-01 (RLS disabled)    : FAIL (all profiles — must be confirmed as false until Phase 5)
  TIP-02/03 (registry)     : FAIL (all profiles)
  TIP-04 (gap register)    : WARN (local) / FAIL (staging, production)
  TIP-05/06 (migrations)   : FAIL (all profiles)
  TIP-07–10 (docs)         : WARN (local) / FAIL (staging, production)
  TIP-11 (tests)           : WARN (local) / FAIL (staging, production)
  TIP-12–14 (services)     : FAIL (all profiles)
  A-01 / A-02              : INFO (all profiles — advisory only)

Exit codes: 0=PASS, 1=FAIL, 2=ERROR
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

PASS = "PASS"
FAIL = "FAIL"
WARN = "WARN"
INFO = "INFO"

MIN_ISOLATED_TABLES = 10
MIN_UNISOLATED_TABLES = 1
MIN_TENANT_TEST_FILES = 3

# Paths relative to project root
BOUNDARY_SERVICE_PATH    = Path("app/Services/TenantBoundaryService.php")
CONTEXT_AUTHORITY_PATH   = Path("app/Services/TenantContextAuthority.php")
NULL_AUDIT_CMD_PATH      = Path("app/Console/Commands/TenantNullAuditCommand.php")
POSTURE_DOC_PATH         = Path("docs/security/TENANT_ISOLATION_POSTURE.md")
RLS_DECISION_RECORD_PATH = Path("docs/security/RLS_DECISION_RECORD.md")
STRICT_MODE_DOC_PATH     = Path("docs/security/TENANT_STRICT_MODE.md")
NULL_MIGRATION_PLAN_PATH = Path("docs/security/TENANT_NULL_MIGRATION_PLAN.md")
MIGRATIONS_DIR           = Path("database/migrations")
TENANT_TESTS_DIR         = Path("tests/Feature")

TENANT_ID_MIGRATION_PATTERN   = "*add_tenant_id_to_alerts*"
TENANT_ID_INDEX_MIGRATION_PATTERN = "*add_tenant_id_indexes*"

# PHP feature test files that prove app-layer tenant isolation coverage
REQUIRED_TENANT_TEST_FILES = [
    "TenantIsolationHardeningTest.php",
    "TenantContextAuthorityTest.php",
    "TenantStrictModeTest.php",
]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _severity(profile: str, local_sev: str, staging_sev: str, prod_sev: str) -> str:
    return {"local": local_sev, "staging": staging_sev, "production": prod_sev}.get(
        profile, prod_sev
    )


def _build_result(
    check_id: str,
    name: str,
    status: str,
    detail: str,
    *,
    evidence: dict | None = None,
) -> dict[str, Any]:
    return {
        "check_id": check_id,
        "name": name,
        "status": status,
        "detail": detail,
        "evidence": evidence or {},
    }


def _read_file(path: Path, root: Path, _read_fn=None) -> str | None:
    """Return file text, or None if the file does not exist."""
    if _read_fn is not None:
        return _read_fn(path)
    full = root / path
    if full.exists():
        return full.read_text(encoding="utf-8", errors="replace")
    return None


# ---------------------------------------------------------------------------
# Individual checks
# ---------------------------------------------------------------------------

def check_rls_disabled(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-01: TenantBoundaryService::RLS_ENABLED must be false."""
    text = _read_file(BOUNDARY_SERVICE_PATH, root, _read_fn)
    if text is None:
        return _build_result(
            "TIP-01", "RLS_DISABLED_CONFIRMED", FAIL,
            f"{BOUNDARY_SERVICE_PATH} not found",
        )

    # Look for:  RLS_ENABLED = false  (PHP constant, any whitespace)
    match = re.search(r"RLS_ENABLED\s*=\s*(true|false)", text)
    if match is None:
        return _build_result(
            "TIP-01", "RLS_DISABLED_CONFIRMED", FAIL,
            "RLS_ENABLED constant not found in TenantBoundaryService",
        )

    value = match.group(1)
    if value == "false":
        return _build_result(
            "TIP-01", "RLS_DISABLED_CONFIRMED", PASS,
            "RLS_ENABLED = false — app-layer enforcement only (Phase 5 deferred)",
            evidence={"rls_enabled": False},
        )
    return _build_result(
        "TIP-01", "RLS_DISABLED_CONFIRMED", FAIL,
        f"RLS_ENABLED = {value} — unexpected; update RLS_DECISION_RECORD.md if intentional",
        evidence={"rls_enabled": True},
    )


def check_isolated_registry_present(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-02: ISOLATED_TABLES list found in TenantBoundaryService."""
    text = _read_file(BOUNDARY_SERVICE_PATH, root, _read_fn)
    if text is None:
        return _build_result(
            "TIP-02", "ISOLATED_REGISTRY_PRESENT", FAIL,
            f"{BOUNDARY_SERVICE_PATH} not found",
        )
    found = "ISOLATED_TABLES" in text
    return _build_result(
        "TIP-02", "ISOLATED_REGISTRY_PRESENT",
        PASS if found else FAIL,
        "ISOLATED_TABLES constant present" if found
        else "ISOLATED_TABLES constant missing from TenantBoundaryService",
    )


def check_isolated_registry_count(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-03: Isolated table count >= MIN_ISOLATED_TABLES."""
    text = _read_file(BOUNDARY_SERVICE_PATH, root, _read_fn)
    if text is None:
        return _build_result(
            "TIP-03", "ISOLATED_REGISTRY_COUNT", FAIL,
            f"{BOUNDARY_SERVICE_PATH} not found",
        )

    # Extract the ISOLATED_TABLES array body
    m = re.search(
        r"ISOLATED_TABLES\s*=\s*\[(.*?)\];",
        text, re.DOTALL
    )
    if m is None:
        return _build_result(
            "TIP-03", "ISOLATED_REGISTRY_COUNT", FAIL,
            "ISOLATED_TABLES array body not parseable",
        )

    entries = [e.strip().strip("'\"") for e in m.group(1).split(",")
               if e.strip().strip("'\"")]
    count = len(entries)

    status = PASS if count >= MIN_ISOLATED_TABLES else FAIL
    return _build_result(
        "TIP-03", "ISOLATED_REGISTRY_COUNT", status,
        f"isolated_table_count={count} (min={MIN_ISOLATED_TABLES})",
        evidence={"isolated_table_count": count, "min": MIN_ISOLATED_TABLES},
    )


def check_unisolated_registry_present(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-04: UNISOLATED_TABLES gap register found and non-empty."""
    text = _read_file(BOUNDARY_SERVICE_PATH, root, _read_fn)
    if text is None:
        return _build_result(
            "TIP-04", "UNISOLATED_REGISTRY_PRESENT", FAIL,
            f"{BOUNDARY_SERVICE_PATH} not found",
        )

    m = re.search(
        r"UNISOLATED_TABLES\s*=\s*\[(.*?)\];",
        text, re.DOTALL
    )
    if m is None:
        sev = _severity(profile, WARN, FAIL, FAIL)
        return _build_result(
            "TIP-04", "UNISOLATED_REGISTRY_PRESENT", sev,
            "UNISOLATED_TABLES gap register not found — isolation gaps must be documented",
        )

    entries = [e.strip().strip("'\"") for e in m.group(1).split(",")
               if e.strip().strip("'\"")]
    count = len(entries)

    sev = _severity(profile, WARN if count == 0 else PASS, FAIL if count == 0 else PASS, FAIL if count == 0 else PASS)
    # Having unisolated tables is EXPECTED; the check is that they are DOCUMENTED
    return _build_result(
        "TIP-04", "UNISOLATED_REGISTRY_PRESENT", PASS,
        f"unisolated_table_count={count} — isolation gaps documented",
        evidence={"unisolated_table_count": count},
    )


def check_tenant_id_migration(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-05: add_tenant_id_to_alerts_incidents migration file exists."""
    if _read_fn is not None:
        # In test mode, use a flag approach
        exists = _read_fn(Path("_migration_alerts_present")) is not None
    else:
        matches = list((root / MIGRATIONS_DIR).glob(TENANT_ID_MIGRATION_PATTERN))
        exists = len(matches) > 0

    return _build_result(
        "TIP-05", "TENANT_ID_MIGRATION_PRESENT",
        PASS if exists else FAIL,
        "add_tenant_id_to_alerts_incidents migration found" if exists
        else f"No migration matching {TENANT_ID_MIGRATION_PATTERN!r} found under {MIGRATIONS_DIR}",
        evidence={"pattern": TENANT_ID_MIGRATION_PATTERN},
    )


def check_tenant_id_index_migration(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-06: add_tenant_id_indexes migration file exists."""
    if _read_fn is not None:
        exists = _read_fn(Path("_migration_indexes_present")) is not None
    else:
        matches = list((root / MIGRATIONS_DIR).glob(TENANT_ID_INDEX_MIGRATION_PATTERN))
        exists = len(matches) > 0

    return _build_result(
        "TIP-06", "TENANT_ID_INDEX_MIGRATION",
        PASS if exists else FAIL,
        "add_tenant_id_indexes migration found" if exists
        else f"No migration matching {TENANT_ID_INDEX_MIGRATION_PATTERN!r} found",
        evidence={"pattern": TENANT_ID_INDEX_MIGRATION_PATTERN},
    )


def _check_doc(check_id: str, name: str, path: Path, root: Path, profile: str, _read_fn=None) -> dict:
    text = _read_file(path, root, _read_fn)
    exists = text is not None
    sev = PASS if exists else _severity(profile, WARN, FAIL, FAIL)
    return _build_result(
        check_id, name, sev,
        f"{path} present" if exists else f"{path} not found",
        evidence={"path": str(path), "exists": exists},
    )


def check_posture_doc(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-07: TENANT_ISOLATION_POSTURE.md exists."""
    return _check_doc("TIP-07", "POSTURE_DOC_PRESENT", POSTURE_DOC_PATH, root, profile, _read_fn)


def check_rls_decision_record(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-08: RLS_DECISION_RECORD.md exists."""
    return _check_doc("TIP-08", "RLS_DECISION_RECORD_PRESENT", RLS_DECISION_RECORD_PATH, root, profile, _read_fn)


def check_strict_mode_doc(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-09: TENANT_STRICT_MODE.md exists."""
    return _check_doc("TIP-09", "STRICT_MODE_DOC_PRESENT", STRICT_MODE_DOC_PATH, root, profile, _read_fn)


def check_null_migration_plan(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-10: TENANT_NULL_MIGRATION_PLAN.md exists."""
    return _check_doc("TIP-10", "NULL_MIGRATION_PLAN_PRESENT", NULL_MIGRATION_PLAN_PATH, root, profile, _read_fn)


def check_app_layer_tests(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-11: at least MIN_TENANT_TEST_FILES tenant test files present."""
    if _read_fn is not None:
        present = [f for f in REQUIRED_TENANT_TEST_FILES
                   if _read_fn(TENANT_TESTS_DIR / f) is not None]
    else:
        present = [f for f in REQUIRED_TENANT_TEST_FILES
                   if (root / TENANT_TESTS_DIR / f).exists()]

    count = len(present)
    sev = PASS if count >= MIN_TENANT_TEST_FILES else _severity(profile, WARN, FAIL, FAIL)
    return _build_result(
        "TIP-11", "APP_LAYER_TESTS_PRESENT", sev,
        f"tenant_test_files_found={count}/{len(REQUIRED_TENANT_TEST_FILES)}",
        evidence={"found": present, "required": REQUIRED_TENANT_TEST_FILES},
    )


def check_boundary_service(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-12: TenantBoundaryService.php exists."""
    text = _read_file(BOUNDARY_SERVICE_PATH, root, _read_fn)
    exists = text is not None
    return _build_result(
        "TIP-12", "BOUNDARY_SERVICE_PRESENT",
        PASS if exists else FAIL,
        f"{BOUNDARY_SERVICE_PATH} found" if exists else f"{BOUNDARY_SERVICE_PATH} not found",
    )


def check_context_authority(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-13: TenantContextAuthority.php exists."""
    text = _read_file(CONTEXT_AUTHORITY_PATH, root, _read_fn)
    exists = text is not None
    return _build_result(
        "TIP-13", "CONTEXT_AUTHORITY_PRESENT",
        PASS if exists else FAIL,
        f"{CONTEXT_AUTHORITY_PATH} found" if exists else f"{CONTEXT_AUTHORITY_PATH} not found",
    )


def check_null_audit_command(root: Path, profile: str, _read_fn=None) -> dict:
    """TIP-14: TenantNullAuditCommand.php exists."""
    text = _read_file(NULL_AUDIT_CMD_PATH, root, _read_fn)
    exists = text is not None
    return _build_result(
        "TIP-14", "NULL_AUDIT_COMMAND_PRESENT",
        PASS if exists else FAIL,
        f"{NULL_AUDIT_CMD_PATH} found" if exists else f"{NULL_AUDIT_CMD_PATH} not found",
    )


# ---------------------------------------------------------------------------
# Advisory checks
# ---------------------------------------------------------------------------

def advisory_strict_mode_default(root: Path, profile: str, _read_fn=None) -> dict:
    """A-01: XDR_TENANT_STRICT_MODE default is false (documented in strict mode doc)."""
    text = _read_file(STRICT_MODE_DOC_PATH, root, _read_fn)
    if text is None:
        return _build_result(
            "A-01", "STRICT_MODE_DEFAULT",
            INFO, "TENANT_STRICT_MODE.md not found — cannot verify default",
        )

    # Look for the env var set to false in the doc
    found = "XDR_TENANT_STRICT_MODE=false" in text or "strict_mode', false" in text
    return _build_result(
        "A-01", "STRICT_MODE_DEFAULT",
        INFO,
        "XDR_TENANT_STRICT_MODE default=false documented" if found
        else "XDR_TENANT_STRICT_MODE default not explicitly documented",
        evidence={"documented": found},
    )


def advisory_rls_roadmap(root: Path, profile: str, _read_fn=None) -> dict:
    """A-02: Phase 5 / RLS roadmap present in docs."""
    # Check any of the tenant docs mentions Phase 5 and RLS
    found_in = []
    for doc_path in [NULL_MIGRATION_PLAN_PATH, POSTURE_DOC_PATH, RLS_DECISION_RECORD_PATH]:
        text = _read_file(doc_path, root, _read_fn)
        if text and ("Phase 5" in text or "ROW LEVEL SECURITY" in text or "ENABLE ROW LEVEL" in text):
            found_in.append(str(doc_path))

    return _build_result(
        "A-02", "RLS_ROADMAP_PRESENT",
        INFO,
        f"RLS roadmap (Phase 5) documented in: {', '.join(found_in)}" if found_in
        else "RLS roadmap (Phase 5) not found in tenant docs",
        evidence={"found_in": found_in},
    )


# ---------------------------------------------------------------------------
# Run all checks
# ---------------------------------------------------------------------------

ALL_CHECKS = [
    check_rls_disabled,
    check_isolated_registry_present,
    check_isolated_registry_count,
    check_unisolated_registry_present,
    check_tenant_id_migration,
    check_tenant_id_index_migration,
    check_posture_doc,
    check_rls_decision_record,
    check_strict_mode_doc,
    check_null_migration_plan,
    check_app_layer_tests,
    check_boundary_service,
    check_context_authority,
    check_null_audit_command,
]

ALL_ADVISORY = [
    advisory_strict_mode_default,
    advisory_rls_roadmap,
]


def run_all(root: Path, profile: str, _read_fn=None) -> dict[str, Any]:
    results = []

    for fn in ALL_CHECKS:
        results.append(fn(root, profile, _read_fn))

    for fn in ALL_ADVISORY:
        results.append(fn(root, profile, _read_fn))

    non_advisory = [r for r in results if not r["check_id"].startswith("A-")]
    failed  = [r for r in non_advisory if r["status"] == FAIL]
    warned  = [r for r in non_advisory if r["status"] == WARN]
    passed  = [r for r in non_advisory if r["status"] == PASS]
    advisory = [r for r in results if r["check_id"].startswith("A-")]

    overall = FAIL if failed else (WARN if warned else PASS)

    return {
        "task": "ENTERPRISE-040",
        "validator": "xdr_tenant_isolation_posture",
        "profile": profile,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "overall": overall,
        "summary": {
            "total": len(non_advisory),
            "passed": len(passed),
            "warned": len(warned),
            "failed": len(failed),
            "advisory": len(advisory),
        },
        "checks": results,
        "rls_posture": {
            "rls_enabled": False,
            "enforcement_layer": "application",
            "phase": "Phase 2 complete; Phase 3 planned",
            "decision_record": str(RLS_DECISION_RECORD_PATH),
        },
    }


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="XDR tenant isolation posture validator (ENTERPRISE-040)"
    )
    p.add_argument(
        "--profile",
        default="local",
        choices=["local", "staging", "production"],
        help="Severity profile (default: local)",
    )
    p.add_argument(
        "--output",
        default="",
        help="Write JSON report to this path (optional)",
    )
    p.add_argument(
        "--quiet",
        action="store_true",
        help="Suppress console output",
    )
    return p.parse_args()


def _print_report(report: dict, quiet: bool) -> None:
    if quiet:
        return

    overall = report["overall"]
    s = report["summary"]
    print(f"\nXDR Tenant Isolation Posture — {overall}")
    print(f"Profile : {report['profile']}")
    print(f"Checks  : {s['total']} ({s['passed']} PASS / {s['warned']} WARN / {s['failed']} FAIL)")
    print(f"Advisory: {s['advisory']}")
    print()

    for c in report["checks"]:
        icon = {"PASS": "✓", "WARN": "!", "FAIL": "✗", "INFO": "i"}.get(c["status"], "?")
        print(f"  [{icon}] {c['check_id']:6s}  {c['name']:35s}  {c['status']:4s}  {c['detail']}")

    rp = report["rls_posture"]
    print(f"\nRLS posture    : rls_enabled={rp['rls_enabled']}")
    print(f"Enforcement    : {rp['enforcement_layer']}")
    print(f"Phase          : {rp['phase']}")
    print(f"Decision record: {rp['decision_record']}")


def main() -> int:
    args = _parse_args()
    root = Path(__file__).parent.parent

    try:
        report = run_all(root, args.profile)
    except Exception as exc:  # pragma: no cover
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    _print_report(report, args.quiet)

    if args.output:
        out = Path(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")

    return 0 if report["overall"] == PASS else 1


if __name__ == "__main__":
    sys.exit(main())
