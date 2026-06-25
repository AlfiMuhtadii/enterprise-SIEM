#!/usr/bin/env python3
"""XDR pilot operator readiness check (ENTERPRISE-041).

Read-only, non-destructive.  Does NOT connect to the database or network.
Verifies that every script, doc, and configuration file referenced in
docs/operations/PILOT_OPERATOR_RUNBOOK.md exists in the project tree.

If any referenced artifact is missing the operator cannot execute the runbook
procedure that depends on it.

Checks
------
  ORC-01  RUNBOOK_PRESENT              — PILOT_OPERATOR_RUNBOOK.md exists
  ORC-02  TOPIC_BOOTSTRAP_PRESENT      — scripts/xdr_topic_bootstrap.py
  ORC-03  POSTURE_CHECK_PRESENT        — scripts/xdr_posture_check.py
  ORC-04  PILOT_LIVE_VALIDATE_PRESENT  — scripts/xdr_pilot_live_validate.py
  ORC-05  PROD_PROFILE_VALIDATE_PRESENT— scripts/xdr_production_profile_validate.py
  ORC-06  RECOVERY_VALIDATE_PRESENT    — scripts/xdr_recovery_validate.py
  ORC-07  RESTORE_DRILL_PRESENT        — scripts/xdr_restore_drill.py
  ORC-08  LIVE_SOAK_VALIDATE_PRESENT   — scripts/xdr_live_soak_validate.py
  ORC-09  EASM_SCAN_PRESENT            — scripts/xdr_easm_passive_scan.py
  ORC-10  EASM_HISTORY_PRESENT         — scripts/xdr_easm_posture_history.py
  ORC-11  TENANT_ISOLATION_PRESENT     — scripts/xdr_tenant_isolation_posture.py
  ORC-12  SOAK_6H_SCRIPT_PRESENT       — scripts/run_xdr_correlation_soak_6h.ps1
  ORC-13  BACKUP_RECOVERY_DOC_PRESENT  — docs/operations/BACKUP_RESTORE_RECOVERY.md
  ORC-14  OPERATIONAL_POSTURE_PRESENT  — docs/operations/OPERATIONAL_POSTURE.md
  ORC-15  PROD_PROFILE_DOC_PRESENT     — docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md
  ORC-16  RULE_REGISTRY_PRESENT        — docs/detection/rules/registry.v1.json

Advisory (never block overall PASS):
  A-01  PROD_COMPOSE_PRESENT           — docker-compose.prod.yml
  A-02  ENV_EXAMPLE_PRESENT            — .env.example

Severity by profile
-------------------
  ORC-01 (runbook itself): FAIL (all profiles)
  ORC-02 to ORC-11 (scripts): FAIL (all profiles)
  ORC-12 (6h soak PS1): WARN (local) / FAIL (staging, production)
  ORC-13 to ORC-15 (ops docs): WARN (local) / FAIL (staging, production)
  ORC-16 (rule registry): FAIL (all profiles)
  A-01, A-02: INFO (all profiles)

Exit codes: 0=PASS, 1=FAIL, 2=ERROR
"""

from __future__ import annotations

import argparse
import json
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

# Paths relative to project root
RUNBOOK_PATH            = Path("docs/operations/PILOT_OPERATOR_RUNBOOK.md")
TOPIC_BOOTSTRAP_PATH    = Path("scripts/xdr_topic_bootstrap.py")
POSTURE_CHECK_PATH      = Path("scripts/xdr_posture_check.py")
PILOT_LIVE_PATH         = Path("scripts/xdr_pilot_live_validate.py")
PROD_PROFILE_PATH       = Path("scripts/xdr_production_profile_validate.py")
RECOVERY_VALIDATE_PATH  = Path("scripts/xdr_recovery_validate.py")
RESTORE_DRILL_PATH      = Path("scripts/xdr_restore_drill.py")
LIVE_SOAK_PATH          = Path("scripts/xdr_live_soak_validate.py")
EASM_SCAN_PATH          = Path("scripts/xdr_easm_passive_scan.py")
EASM_HISTORY_PATH       = Path("scripts/xdr_easm_posture_history.py")
TENANT_ISOLATION_PATH   = Path("scripts/xdr_tenant_isolation_posture.py")
SOAK_6H_SCRIPT_PATH     = Path("scripts/run_xdr_correlation_soak_6h.ps1")
BACKUP_RECOVERY_DOC     = Path("docs/operations/BACKUP_RESTORE_RECOVERY.md")
OPERATIONAL_POSTURE_DOC = Path("docs/operations/OPERATIONAL_POSTURE.md")
PROD_PROFILE_DOC        = Path("docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md")
RULE_REGISTRY_PATH      = Path("docs/detection/rules/registry.v1.json")

PROD_COMPOSE_PATH       = Path("docker-compose.prod.yml")
ENV_EXAMPLE_PATH        = Path(".env.example")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _severity(profile: str, local_sev: str, staging_sev: str, prod_sev: str) -> str:
    return {"local": local_sev, "staging": staging_sev, "production": prod_sev}.get(
        profile, prod_sev
    )


def _build(
    check_id: str, name: str, status: str, detail: str,
    *, evidence: dict | None = None,
) -> dict[str, Any]:
    return {
        "check_id": check_id,
        "name": name,
        "status": status,
        "detail": detail,
        "evidence": evidence or {},
    }


def _exists(path: Path, root: Path, _read_fn=None) -> bool:
    if _read_fn is not None:
        return _read_fn(path) is not None
    return (root / path).exists()


def _check_file(
    check_id: str, name: str, path: Path,
    root: Path, profile: str,
    fail_sev: str, _read_fn=None,
) -> dict:
    found = _exists(path, root, _read_fn)
    if found:
        return _build(check_id, name, PASS, f"{path} present", evidence={"path": str(path)})
    return _build(check_id, name, fail_sev,
                  f"{path} not found", evidence={"path": str(path)})


# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

def check_runbook(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-01", "RUNBOOK_PRESENT", RUNBOOK_PATH, root, profile, FAIL, _read_fn)


def check_topic_bootstrap(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-02", "TOPIC_BOOTSTRAP_PRESENT", TOPIC_BOOTSTRAP_PATH, root, profile, FAIL, _read_fn)


def check_posture_check(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-03", "POSTURE_CHECK_PRESENT", POSTURE_CHECK_PATH, root, profile, FAIL, _read_fn)


def check_pilot_live_validate(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-04", "PILOT_LIVE_VALIDATE_PRESENT", PILOT_LIVE_PATH, root, profile, FAIL, _read_fn)


def check_prod_profile_validate(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-05", "PROD_PROFILE_VALIDATE_PRESENT", PROD_PROFILE_PATH, root, profile, FAIL, _read_fn)


def check_recovery_validate(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-06", "RECOVERY_VALIDATE_PRESENT", RECOVERY_VALIDATE_PATH, root, profile, FAIL, _read_fn)


def check_restore_drill(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-07", "RESTORE_DRILL_PRESENT", RESTORE_DRILL_PATH, root, profile, FAIL, _read_fn)


def check_live_soak_validate(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-08", "LIVE_SOAK_VALIDATE_PRESENT", LIVE_SOAK_PATH, root, profile, FAIL, _read_fn)


def check_easm_scan(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-09", "EASM_SCAN_PRESENT", EASM_SCAN_PATH, root, profile, FAIL, _read_fn)


def check_easm_history(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-10", "EASM_HISTORY_PRESENT", EASM_HISTORY_PATH, root, profile, FAIL, _read_fn)


def check_tenant_isolation(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-11", "TENANT_ISOLATION_PRESENT", TENANT_ISOLATION_PATH, root, profile, FAIL, _read_fn)


def check_soak_6h_script(root: Path, profile: str, _read_fn=None) -> dict:
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check_file("ORC-12", "SOAK_6H_SCRIPT_PRESENT", SOAK_6H_SCRIPT_PATH, root, profile, sev, _read_fn)


def check_backup_recovery_doc(root: Path, profile: str, _read_fn=None) -> dict:
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check_file("ORC-13", "BACKUP_RECOVERY_DOC_PRESENT", BACKUP_RECOVERY_DOC, root, profile, sev, _read_fn)


def check_operational_posture_doc(root: Path, profile: str, _read_fn=None) -> dict:
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check_file("ORC-14", "OPERATIONAL_POSTURE_PRESENT", OPERATIONAL_POSTURE_DOC, root, profile, sev, _read_fn)


def check_prod_profile_doc(root: Path, profile: str, _read_fn=None) -> dict:
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check_file("ORC-15", "PROD_PROFILE_DOC_PRESENT", PROD_PROFILE_DOC, root, profile, sev, _read_fn)


def check_rule_registry(root: Path, profile: str, _read_fn=None) -> dict:
    return _check_file("ORC-16", "RULE_REGISTRY_PRESENT", RULE_REGISTRY_PATH, root, profile, FAIL, _read_fn)


# ---------------------------------------------------------------------------
# Advisory checks
# ---------------------------------------------------------------------------

def advisory_prod_compose(root: Path, profile: str, _read_fn=None) -> dict:
    found = _exists(PROD_COMPOSE_PATH, root, _read_fn)
    return _build(
        "A-01", "PROD_COMPOSE_PRESENT",
        INFO,
        f"{PROD_COMPOSE_PATH} present" if found
        else f"{PROD_COMPOSE_PATH} not found — production overlay not available",
        evidence={"exists": found},
    )


def advisory_env_example(root: Path, profile: str, _read_fn=None) -> dict:
    found = _exists(ENV_EXAMPLE_PATH, root, _read_fn)
    return _build(
        "A-02", "ENV_EXAMPLE_PRESENT",
        INFO,
        f"{ENV_EXAMPLE_PATH} present" if found
        else f"{ENV_EXAMPLE_PATH} not found — operators cannot copy env template",
        evidence={"exists": found},
    )


# ---------------------------------------------------------------------------
# Run all
# ---------------------------------------------------------------------------

ALL_CHECKS = [
    check_runbook,
    check_topic_bootstrap,
    check_posture_check,
    check_pilot_live_validate,
    check_prod_profile_validate,
    check_recovery_validate,
    check_restore_drill,
    check_live_soak_validate,
    check_easm_scan,
    check_easm_history,
    check_tenant_isolation,
    check_soak_6h_script,
    check_backup_recovery_doc,
    check_operational_posture_doc,
    check_prod_profile_doc,
    check_rule_registry,
]

ALL_ADVISORY = [
    advisory_prod_compose,
    advisory_env_example,
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

    missing = [r["evidence"].get("path", r["check_id"])
               for r in failed + warned]

    return {
        "task": "ENTERPRISE-041",
        "validator": "xdr_operator_readiness_check",
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
        "missing_artifacts": missing,
        "checks": results,
    }


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="XDR pilot operator readiness check (ENTERPRISE-041)"
    )
    p.add_argument(
        "--profile", default="local",
        choices=["local", "staging", "production"],
        help="Severity profile (default: local)",
    )
    p.add_argument("--output", default="", help="Write JSON report to this path (optional)")
    p.add_argument("--quiet", action="store_true", help="Suppress console output")
    return p.parse_args()


def _print_report(report: dict, quiet: bool) -> None:
    if quiet:
        return
    overall = report["overall"]
    s = report["summary"]
    print(f"\nXDR Operator Readiness — {overall}")
    print(f"Profile : {report['profile']}")
    print(f"Checks  : {s['total']} ({s['passed']} PASS / {s['warned']} WARN / {s['failed']} FAIL)")
    print(f"Advisory: {s['advisory']}")
    print()
    for c in report["checks"]:
        icon = {"PASS": "✓", "WARN": "!", "FAIL": "✗", "INFO": "i"}.get(c["status"], "?")
        print(f"  [{icon}] {c['check_id']:6s}  {c['name']:35s}  {c['status']:4s}  {c['detail']}")
    if report["missing_artifacts"]:
        print(f"\nMissing artifacts: {', '.join(report['missing_artifacts'])}")


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
