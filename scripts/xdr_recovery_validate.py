#!/usr/bin/env python3
"""XDR backup / restore / recovery readiness validation (BACKLOG-DR-027).

Read-only, non-destructive.  Does NOT perform an actual pg_dump or restore.
Verifies that the backup preconditions and structural invariants required for
a successful recovery are all in place.

Checks
------
  R-01  PG_DSN_CONFIGURED        — DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME in .env
  R-02  ENV_EXAMPLE_PRESENT      — .env.example exists as a recovery template
  R-03  DOCKER_COMPOSE_PRESENT   — docker-compose.yml present (infra topology)
  R-04  RULE_REGISTRY_INTEGRITY  — registry.v1.json parseable; rules=133, staged_active=12
  R-05  MIGRATION_FILES_PRESENT  — database/migrations/ has >= MIN_MIGRATION_COUNT files
  R-06  KEY_REPORTS_PRESENT      — at least 1 named key report exists under reports/ (advisory)
  R-07  OPERATIONAL_DOCS_PRESENT — required operational docs present
  R-08  BACKUP_DOC_PRESENT       — docs/operations/BACKUP_RESTORE_RECOVERY.md present

Advisory RPO/RTO items (always INFO, never block overall status):
  A-01  RPO_RTO_LOCAL
  A-02  RPO_RTO_STAGING
  A-03  RPO_RTO_PRODUCTION
  A-04  PG_DUMP_TOOL             — advisory note on pg_dump availability

Severity by profile
-------------------
  R-01 (PG DSN)        : PASS / WARN (local) / FAIL (staging, production)
  R-02 (env.example)   : PASS / WARN (local) / FAIL (staging, production)
  R-03 (docker-compose): PASS / FAIL (all profiles)
  R-04 (rule registry) : PASS / FAIL (all profiles)
  R-05 (migrations)    : PASS / FAIL (all profiles)
  R-06 (key reports)   : PASS / WARN (all profiles — advisory)
  R-07 (ops docs)      : PASS / FAIL (all profiles)
  R-08 (backup doc)    : PASS / WARN (local) / FAIL (staging, production)

Exit codes: 0=PASS, 1=FAIL, 2=ERROR
"""

from __future__ import annotations

import argparse
import json
import shutil
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

EXPECTED_RULE_COUNT = 133
EXPECTED_STAGED_ACTIVE = 12
MIN_MIGRATION_COUNT = 100

REQUIRED_DB_ENV_KEYS = ["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME"]

# Relative to project root
RULE_REGISTRY_PATH = Path("docs/detection/rules/registry.v1.json")
MIGRATIONS_DIR = Path("database/migrations")
BACKUP_DOC_PATH = Path("docs/operations/BACKUP_RESTORE_RECOVERY.md")
ENV_EXAMPLE_PATH = Path(".env.example")
DOCKER_COMPOSE_PATH = Path("docker-compose.yml")

KEY_REPORTS = [
    "xdr_contract_validation.json",
    "xdr_correlation_soak_6h.json",
    "xdr_rule_registry_validation.json",
    "xdr_fleet_simulation_validation.json",
]

REQUIRED_OPERATIONAL_DOCS = [
    Path("docs/operations/OPERATIONAL_POSTURE.md"),
    Path("docs/operations/PRODUCTION_RUNTIME_PROFILE.md"),
    Path("docs/validation/VALIDATION_BASELINES.md"),
    Path("docs/detection/rules/registry.v1.json"),
]

RPO_RTO_TABLE: dict[str, dict[str, str]] = {
    "local": {
        "rpo": "N/A (dev only — no production state)",
        "rto": "< 5 minutes (restart docker compose + migrate:fresh)",
        "pg_backup": "Not required for local development",
    },
    "staging": {
        "rpo": "24 hours (daily pg_dump)",
        "rto": "< 60 minutes (restore from daily dump)",
        "pg_backup": "pg_dump -Fc detector > detector_$(date +%Y%m%d).dump",
    },
    "production": {
        "rpo": "4 hours (every-4h pg_dump or WAL-based PITR)",
        "rto": "< 30 minutes (restore from latest snapshot)",
        "pg_backup": "pg_dump -Fc detector > detector_$(date +%Y%m%d_%H).dump",
    },
}

_PROJECT_ROOT = Path(__file__).resolve().parent.parent


# ---------------------------------------------------------------------------
# .env loader
# ---------------------------------------------------------------------------

def _load_dotenv(path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    if not path.exists():
        return result
    with path.open(encoding="utf-8-sig") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, _, v = line.partition("=")
            result[k.strip()] = v.strip().strip('"').strip("'")
    return result


# ---------------------------------------------------------------------------
# Individual checks
# ---------------------------------------------------------------------------

def check_pg_dsn(env: dict[str, str], profile: str) -> dict[str, Any]:
    missing = [k for k in REQUIRED_DB_ENV_KEYS if not env.get(k)]
    if not missing:
        return {
            "check_id": "R-01",
            "name": "PG_DSN_CONFIGURED",
            "status": PASS,
            "detail": (
                f"All required DB keys present: {', '.join(REQUIRED_DB_ENV_KEYS)}"
            ),
        }
    gap = f"Missing keys: {', '.join(missing)}"
    severity = WARN if profile == "local" else FAIL
    return {
        "check_id": "R-01",
        "name": "PG_DSN_CONFIGURED",
        "status": severity,
        "detail": f"{gap}. PostgreSQL backup/restore depends on these values.",
    }


def check_env_example_present(root: Path, profile: str) -> dict[str, Any]:
    path = root / ENV_EXAMPLE_PATH
    if path.exists():
        return {
            "check_id": "R-02",
            "name": "ENV_EXAMPLE_PRESENT",
            "status": PASS,
            "detail": f"{ENV_EXAMPLE_PATH} exists -- provides recovery template for .env",
        }
    severity = WARN if profile == "local" else FAIL
    return {
        "check_id": "R-02",
        "name": "ENV_EXAMPLE_PRESENT",
        "status": severity,
        "detail": (
            f"{ENV_EXAMPLE_PATH} missing. Without this template, re-creating .env "
            "after a configuration loss requires manual reconstruction of all keys."
        ),
    }


def check_docker_compose_present(root: Path, profile: str) -> dict[str, Any]:  # noqa: ARG001
    path = root / DOCKER_COMPOSE_PATH
    if path.exists():
        return {
            "check_id": "R-03",
            "name": "DOCKER_COMPOSE_PRESENT",
            "status": PASS,
            "detail": f"{DOCKER_COMPOSE_PATH} exists -- infrastructure topology config",
        }
    return {
        "check_id": "R-03",
        "name": "DOCKER_COMPOSE_PRESENT",
        "status": FAIL,
        "detail": (
            f"{DOCKER_COMPOSE_PATH} missing. Cannot recover infrastructure "
            "topology without this file."
        ),
    }


def check_rule_registry_integrity(root: Path, profile: str) -> dict[str, Any]:  # noqa: ARG001
    path = root / RULE_REGISTRY_PATH
    if not path.exists():
        return {
            "check_id": "R-04",
            "name": "RULE_REGISTRY_INTEGRITY",
            "status": FAIL,
            "detail": f"{RULE_REGISTRY_PATH} missing — detection rules cannot be recovered",
        }
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        return {
            "check_id": "R-04",
            "name": "RULE_REGISTRY_INTEGRITY",
            "status": FAIL,
            "detail": f"{RULE_REGISTRY_PATH} is not valid JSON: {exc}",
        }
    rules = data.get("rules", [])
    total = len(rules)
    staged = sum(1 for r in rules if r.get("status") == "staged_active")
    if total != EXPECTED_RULE_COUNT or staged != EXPECTED_STAGED_ACTIVE:
        return {
            "check_id": "R-04",
            "name": "RULE_REGISTRY_INTEGRITY",
            "status": FAIL,
            "detail": (
                f"Rule count mismatch: got rules={total} staged_active={staged}; "
                f"expected rules={EXPECTED_RULE_COUNT} staged_active={EXPECTED_STAGED_ACTIVE}. "
                "Registry may be corrupted or out of sync."
            ),
        }
    return {
        "check_id": "R-04",
        "name": "RULE_REGISTRY_INTEGRITY",
        "status": PASS,
        "detail": (
            f"Registry parseable: rules={total} staged_active={staged} "
            f"(expected {EXPECTED_RULE_COUNT}/{EXPECTED_STAGED_ACTIVE})"
        ),
    }


def check_migration_files_present(root: Path, profile: str) -> dict[str, Any]:  # noqa: ARG001
    migrations = root / MIGRATIONS_DIR
    if not migrations.is_dir():
        return {
            "check_id": "R-05",
            "name": "MIGRATION_FILES_PRESENT",
            "status": FAIL,
            "detail": (
                f"{MIGRATIONS_DIR}/ directory missing — cannot run "
                "php artisan migrate after restore"
            ),
        }
    count = sum(1 for f in migrations.iterdir() if f.suffix == ".php")
    if count < MIN_MIGRATION_COUNT:
        return {
            "check_id": "R-05",
            "name": "MIGRATION_FILES_PRESENT",
            "status": FAIL,
            "detail": (
                f"Only {count} migration files found; expected >= {MIN_MIGRATION_COUNT}. "
                "Migrations may be missing from the working tree."
            ),
        }
    return {
        "check_id": "R-05",
        "name": "MIGRATION_FILES_PRESENT",
        "status": PASS,
        "detail": f"{count} migration files in {MIGRATIONS_DIR}/",
    }


def check_key_reports_present(root: Path, profile: str) -> dict[str, Any]:  # noqa: ARG001
    reports_dir = root / "reports"
    found = [r for r in KEY_REPORTS if (reports_dir / r).exists()]
    if found:
        return {
            "check_id": "R-06",
            "name": "KEY_REPORTS_PRESENT",
            "status": PASS,
            "detail": (
                f"Found {len(found)}/{len(KEY_REPORTS)} key validation reports: "
                + ", ".join(found)
            ),
        }
    return {
        "check_id": "R-06",
        "name": "KEY_REPORTS_PRESENT",
        "status": WARN,
        "detail": (
            "No key validation reports found under reports/. "
            "Reports are regeneratable — run xdr_rule_registry_validate.py and "
            "xdr_contract_validate.py after a restore. "
            "See docs/operations/BACKUP_RESTORE_RECOVERY.md §4."
        ),
    }


def check_operational_docs_present(root: Path, profile: str) -> dict[str, Any]:  # noqa: ARG001
    missing = [str(p) for p in REQUIRED_OPERATIONAL_DOCS if not (root / p).exists()]
    if not missing:
        return {
            "check_id": "R-07",
            "name": "OPERATIONAL_DOCS_PRESENT",
            "status": PASS,
            "detail": (
                f"All {len(REQUIRED_OPERATIONAL_DOCS)} required operational docs present"
            ),
        }
    return {
        "check_id": "R-07",
        "name": "OPERATIONAL_DOCS_PRESENT",
        "status": FAIL,
        "detail": f"Missing operational docs: {', '.join(missing)}",
    }


def check_backup_doc_present(root: Path, profile: str) -> dict[str, Any]:
    path = root / BACKUP_DOC_PATH
    if path.exists():
        return {
            "check_id": "R-08",
            "name": "BACKUP_DOC_PRESENT",
            "status": PASS,
            "detail": f"{BACKUP_DOC_PATH} present -- recovery runbook available",
        }
    severity = WARN if profile == "local" else FAIL
    return {
        "check_id": "R-08",
        "name": "BACKUP_DOC_PRESENT",
        "status": severity,
        "detail": (
            f"{BACKUP_DOC_PATH} missing. Recovery runbook is required before "
            "staging or production deployment."
        ),
    }


# ---------------------------------------------------------------------------
# Advisory RPO/RTO items
# ---------------------------------------------------------------------------

def advisory_rpo_rto() -> list[dict[str, Any]]:
    items = []
    for env_label, letter in [("local", "A-01"), ("staging", "A-02"), ("production", "A-03")]:
        t = RPO_RTO_TABLE[env_label]
        items.append({
            "check_id": letter,
            "name": f"RPO_RTO_{env_label.upper()}",
            "status": INFO,
            "detail": (
                f"RPO={t['rpo']} | RTO={t['rto']} | "
                f"backup_cmd={t['pg_backup']}"
            ),
        })
    pg_dump = shutil.which("pg_dump")
    items.append({
        "check_id": "A-04",
        "name": "PG_DUMP_TOOL",
        "status": INFO,
        "detail": (
            f"pg_dump found at: {pg_dump}"
            if pg_dump
            else "pg_dump not found on PATH. Install PostgreSQL client tools before running backups."
        ),
    })
    return items


# ---------------------------------------------------------------------------
# Run all checks
# ---------------------------------------------------------------------------

def run_checks(
    env: dict[str, str],
    root: Path,
    profile: str,
) -> list[dict[str, Any]]:
    checks: list[dict[str, Any]] = [
        check_pg_dsn(env, profile),
        check_env_example_present(root, profile),
        check_docker_compose_present(root, profile),
        check_rule_registry_integrity(root, profile),
        check_migration_files_present(root, profile),
        check_key_reports_present(root, profile),
        check_operational_docs_present(root, profile),
        check_backup_doc_present(root, profile),
    ]
    checks.extend(advisory_rpo_rto())
    return checks


# ---------------------------------------------------------------------------
# Report builder
# ---------------------------------------------------------------------------

def build_report(
    profile: str,
    checks: list[dict[str, Any]],
    dry_run: bool,
) -> dict[str, Any]:
    gate_checks = [c for c in checks if c["status"] in (PASS, FAIL, WARN)]
    fail_count = sum(1 for c in gate_checks if c["status"] == FAIL)
    overall = FAIL if fail_count > 0 else PASS
    return {
        "validation": "BACKLOG-DR-027",
        "title": "XDR Backup / Restore / Recovery Readiness",
        "profile": profile,
        "dry_run": dry_run,
        "overall": overall,
        "pass_count": sum(1 for c in gate_checks if c["status"] == PASS),
        "warn_count": sum(1 for c in gate_checks if c["status"] == WARN),
        "fail_count": fail_count,
        "checks": checks,
        "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    }


def _print_report(report: dict[str, Any]) -> None:
    sep = "=" * 72
    profile_label = report["profile"].upper()
    dry = " [DRY-RUN]" if report["dry_run"] else ""
    print(f"\n{sep}")
    print(f"  XDR Recovery Validate -- BACKLOG-DR-027 [{profile_label}]{dry}")
    print(
        f"  Overall: {report['overall']}  "
        f"(PASS={report['pass_count']} WARN={report['warn_count']} "
        f"FAIL={report['fail_count']})"
    )
    print(sep)
    for c in report["checks"]:
        status = c["status"]
        marker = "+" if status == PASS else ("!" if status == FAIL else "~")
        print(f"  [{marker}] {c['check_id']} {c['name']} [{status}]: {c['detail']}")
    print(sep)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="XDR backup/restore/recovery readiness validation"
    )
    parser.add_argument(
        "--profile",
        choices=["local", "staging", "production"],
        default="local",
        help="Deployment profile: local | staging | production (default: local)",
    )
    parser.add_argument(
        "--env-file",
        default="",
        dest="env_file",
        help="Path to .env file (default: <project_root>/.env)",
    )
    parser.add_argument(
        "--output",
        default="",
        help="Write JSON report to this path (optional)",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Suppress console output",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        dest="dry_run",
        help="Mark report as dry-run; all checks still execute (all checks are read-only)",
    )
    args = parser.parse_args(argv)

    root = _PROJECT_ROOT
    env_path = Path(args.env_file) if args.env_file else root / ".env"
    env = _load_dotenv(env_path)

    try:
        checks = run_checks(env, root, args.profile)
    except Exception as exc:
        print(f"ERROR: check execution failed: {exc}", file=sys.stderr)
        return 2

    report = build_report(args.profile, checks, args.dry_run)

    if not args.quiet:
        _print_report(report)

    if args.output:
        out_path = Path(args.output)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(report, indent=2), encoding="utf-8")

    return 0 if report["overall"] == PASS else 1


if __name__ == "__main__":
    sys.exit(main())
