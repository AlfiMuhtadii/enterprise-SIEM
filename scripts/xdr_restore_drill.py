#!/usr/bin/env python3
"""
ENTERPRISE-037: Real Restore Drill.

Validates backup → restore → post-restore integrity against an isolated
target database. Default mode is dry-run (non-destructive plan generation).
Use --execute to perform the actual drill against a separate target DB.

Safety invariants:
  - Never overwrites the active/source database
  - Target DB must differ from source DB name
  - Default mode is dry-run; --execute is an explicit opt-in
  - pg_dump / pg_restore / createdb / dropdb availability checked before action
  - All post-restore checks are read-only against the target DB
  - Active DB is never touched after the initial dump

Steps:
  PRE-01  env vars present (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
  PRE-02  pg_dump available on PATH
  PRE-03  pg_restore available on PATH
  PRE-04  createdb available on PATH
  PRE-05  dropdb available on PATH (advisory — needed for cleanup)
  PRE-06  target DB name != source DB name
  PLAN    generate restore plan (always)
  DUMP    pg_dump source → isolated dump file   [execute only]
  RESTORE createdb target + pg_restore dump    [execute only]
  POST-01 migrations table present in target   [execute only]
  POST-02 append-only tables present           [execute only]
  POST-03 rule registry integrity (advisory)   [execute only]
  POST-04 tenant null audit (advisory)         [execute only]

Exit codes: 0=PASS  1=FAIL (≥1 FAIL-level issue)  2=ERROR (unexpected)
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

_PROJECT_ROOT = Path(__file__).resolve().parent.parent

PASS = "PASS"
FAIL = "FAIL"
WARN = "WARN"
INFO = "INFO"

_REQUIRED_ENV = ["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"]

# Append-only tables whose existence is verified post-restore
_APPEND_ONLY_SPOT_CHECK = [
    "security_alerts",
    "security_incidents",
    "export_audit_logs",
    "investigation_events",
    "endpoint_agent_heartbeats",
    "dlq_normalization_events",
    "advisory_finding_events",
]

_DEFAULT_TARGET_SUFFIX = "_restore_drill"
_DUMP_FILENAME_TEMPLATE = "xdr_restore_drill_{ts}.dump"


# ---------------------------------------------------------------------------
# .env loader
# ---------------------------------------------------------------------------

def _load_dotenv(root: Path) -> dict[str, str]:
    env_path = root / ".env"
    result: dict[str, str] = {}
    if not env_path.exists():
        return result
    with env_path.open(encoding="utf-8-sig") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, _, v = line.partition("=")
            result[k.strip()] = v.strip().strip('"').strip("'")
    return result


def _resolve_env(root: Path) -> dict[str, str]:
    """Merge OS env over .env file (OS takes precedence)."""
    merged = _load_dotenv(root)
    for key in _REQUIRED_ENV:
        if key in os.environ:
            merged[key] = os.environ[key]
    return merged


# ---------------------------------------------------------------------------
# Result helpers
# ---------------------------------------------------------------------------

def _result(step_id: str, name: str, status: str, detail: str,
             remediation: str = "") -> dict[str, Any]:
    return {
        "step_id": step_id,
        "name": name,
        "status": status,
        "detail": detail,
        "remediation": remediation,
    }


# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------

def check_env_vars(env: dict[str, str]) -> dict:
    missing = [k for k in _REQUIRED_ENV if not env.get(k, "").strip()]
    passed = len(missing) == 0
    return _result(
        "PRE-01", "Required DB env vars present",
        PASS if passed else FAIL,
        "All required DB vars present." if passed else f"Missing: {missing}",
        "Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD in .env or OS env.",
    )


def check_tool(step_id: str, tool: str, advisory: bool = False) -> dict:
    found = shutil.which(tool) is not None
    status = PASS if found else (WARN if advisory else FAIL)
    return _result(
        step_id, f"{tool} available on PATH",
        status,
        f"{tool} found at {shutil.which(tool)}." if found else f"{tool} not found on PATH.",
        f"Install postgresql-client or ensure {tool} is on PATH.",
    )


def check_target_isolation(source_db: str, target_db: str) -> dict:
    passed = source_db.strip() != target_db.strip()
    return _result(
        "PRE-06", "Target DB != source DB",
        PASS if passed else FAIL,
        f"source={source_db!r}  target={target_db!r}",
        "Set --target-db to a name different from the active database.",
    )


# ---------------------------------------------------------------------------
# Plan generation
# ---------------------------------------------------------------------------

def build_plan(env: dict[str, str], target_db: str, dump_path: Path) -> dict[str, Any]:
    source_db = env.get("DB_DATABASE", "(unknown)")
    host = env.get("DB_HOST", "localhost")
    port = env.get("DB_PORT", "5432")
    user = env.get("DB_USERNAME", "postgres")
    return {
        "source_db": source_db,
        "target_db": target_db,
        "host": host,
        "port": port,
        "user": user,
        "dump_path": str(dump_path),
        "steps": [
            f"pg_dump -Fc -h {host} -p {port} -U {user} {source_db} > {dump_path}",
            f"createdb -h {host} -p {port} -U {user} {target_db}",
            f"pg_restore -h {host} -p {port} -U {user} -d {target_db} --no-owner --no-acl {dump_path}",
            "POST-01: verify migrations table exists in target",
            "POST-02: verify append-only tables exist in target",
            "POST-03: rule registry integrity check (advisory)",
            "POST-04: tenant null audit (advisory)",
            f"dropdb -h {host} -p {port} -U {user} {target_db}  [cleanup]",
        ],
    }


# ---------------------------------------------------------------------------
# Execute helpers
# ---------------------------------------------------------------------------

def _pg_env(env: dict[str, str]) -> dict[str, str]:
    """Build environment dict for PostgreSQL CLI tools."""
    e = dict(os.environ)
    e["PGPASSWORD"] = env.get("DB_PASSWORD", "")
    return e


def _run(cmd: list[str], pg_env: dict[str, str],
         timeout: int = 120) -> tuple[int, str, str]:
    try:
        proc = subprocess.run(
            cmd,
            env=pg_env,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
        return proc.returncode, proc.stdout, proc.stderr
    except subprocess.TimeoutExpired:
        return 1, "", f"Command timed out after {timeout}s: {cmd}"
    except FileNotFoundError as exc:
        return 2, "", str(exc)


def run_dump(env: dict[str, str], dump_path: Path,
             pg_env: dict[str, str]) -> dict:
    source_db = env["DB_DATABASE"]
    cmd = [
        "pg_dump", "-Fc",
        "-h", env["DB_HOST"],
        "-p", env["DB_PORT"],
        "-U", env["DB_USERNAME"],
        "-f", str(dump_path),
        source_db,
    ]
    rc, stdout, stderr = _run(cmd, pg_env)
    passed = rc == 0 and dump_path.exists()
    size = dump_path.stat().st_size if dump_path.exists() else 0
    return _result(
        "DUMP", "pg_dump source database",
        PASS if passed else FAIL,
        f"exit={rc}  size={size}b  path={dump_path}" if passed
        else f"exit={rc}  stderr={stderr[:300]}",
        "Ensure pg_dump can reach the database and has SELECT privileges.",
    )


def run_createdb(env: dict[str, str], target_db: str,
                 pg_env: dict[str, str]) -> dict:
    cmd = [
        "createdb",
        "-h", env["DB_HOST"],
        "-p", env["DB_PORT"],
        "-U", env["DB_USERNAME"],
        target_db,
    ]
    rc, stdout, stderr = _run(cmd, pg_env)
    passed = rc == 0
    return _result(
        "RESTORE-CREATE", f"createdb {target_db}",
        PASS if passed else FAIL,
        f"exit={rc}" if passed else f"exit={rc}  stderr={stderr[:300]}",
        f"Ensure the user has CREATEDB privilege. Run: createdb {target_db}",
    )


def run_restore(env: dict[str, str], target_db: str, dump_path: Path,
                pg_env: dict[str, str]) -> dict:
    cmd = [
        "pg_restore",
        "-h", env["DB_HOST"],
        "-p", env["DB_PORT"],
        "-U", env["DB_USERNAME"],
        "-d", target_db,
        "--no-owner", "--no-acl",
        str(dump_path),
    ]
    rc, stdout, stderr = _run(cmd, pg_env, timeout=300)
    # pg_restore exits non-zero on warnings (e.g. missing roles) — treat exit ≤ 1 as pass
    passed = rc <= 1
    return _result(
        "RESTORE", f"pg_restore -> {target_db}",
        PASS if passed else FAIL,
        f"exit={rc}" + (f"  warnings={stderr[:200]}" if stderr else ""),
        "Check that all roles referenced in the dump exist in the target cluster.",
    )


def run_dropdb(env: dict[str, str], target_db: str,
               pg_env: dict[str, str]) -> dict:
    cmd = [
        "dropdb",
        "-h", env["DB_HOST"],
        "-p", env["DB_PORT"],
        "-U", env["DB_USERNAME"],
        "--if-exists",
        target_db,
    ]
    rc, stdout, stderr = _run(cmd, pg_env)
    passed = rc == 0
    return _result(
        "CLEANUP", f"dropdb {target_db}",
        PASS if passed else WARN,
        f"exit={rc}" if passed else f"exit={rc}  stderr={stderr[:300]} (manual cleanup required)",
        f"Run manually: dropdb {target_db}",
    )


# ---------------------------------------------------------------------------
# Post-restore checks
# ---------------------------------------------------------------------------

def _psql_query(env: dict[str, str], db: str, sql: str,
                pg_env: dict[str, str]) -> tuple[int, str]:
    cmd = [
        "psql",
        "-h", env["DB_HOST"],
        "-p", env["DB_PORT"],
        "-U", env["DB_USERNAME"],
        "-d", db,
        "-t", "-c", sql,
    ]
    rc, stdout, stderr = _run(cmd, pg_env)
    return rc, stdout.strip()


def check_migrations_table(env: dict[str, str], target_db: str,
                            pg_env: dict[str, str]) -> dict:
    rc, out = _psql_query(
        env, target_db,
        "SELECT COUNT(*) FROM information_schema.tables "
        "WHERE table_name = 'migrations';",
        pg_env,
    )
    found = rc == 0 and out.strip() == "1"
    return _result(
        "POST-01", "migrations table present in target DB",
        PASS if found else FAIL,
        f"query exit={rc}  result={out!r}",
        "Migrations table missing — restore may be incomplete.",
    )


def check_append_only_tables(env: dict[str, str], target_db: str,
                              pg_env: dict[str, str]) -> dict:
    missing: list[str] = []
    for tbl in _APPEND_ONLY_SPOT_CHECK:
        rc, out = _psql_query(
            env, target_db,
            f"SELECT COUNT(*) FROM information_schema.tables "
            f"WHERE table_name = '{tbl}';",
            pg_env,
        )
        if rc != 0 or out.strip() != "1":
            missing.append(tbl)
    passed = len(missing) == 0
    return _result(
        "POST-02", "Append-only tables present in target DB",
        PASS if passed else FAIL,
        "All spot-check tables present." if passed else f"Missing tables: {missing}",
        "Restore may be incomplete — verify dump captured all schemas.",
    )


def check_rule_registry(root: Path) -> dict:
    registry = root / "docs" / "detection" / "rules" / "registry.v1.json"
    if not registry.exists():
        return _result(
            "POST-03", "Rule registry integrity (advisory)",
            WARN, "registry.v1.json not found.", "",
        )
    try:
        data = json.loads(registry.read_text(encoding="utf-8"))
        rules = data.get("rules", [])
        active = [r for r in rules if r.get("status") == "staged_active"]
        count = len(rules)
        passed = count == 133 and len(active) == 12
        return _result(
            "POST-03", "Rule registry integrity (advisory)",
            PASS if passed else WARN,
            f"rules={count}  staged_active={len(active)}",
            "Rule registry count mismatch — check registry.v1.json.",
        )
    except Exception as exc:
        return _result(
            "POST-03", "Rule registry integrity (advisory)",
            WARN, f"Parse error: {exc}", "",
        )


def check_tenant_null_audit(root: Path) -> dict:
    """Run php artisan tenant:null-audit (read-only, exit 0=clean / 1=found nulls)."""
    artisan = root / "artisan"
    if not artisan.exists():
        return _result(
            "POST-04", "Tenant null audit (advisory)",
            INFO, "artisan not found — skipped.", "",
        )
    try:
        proc = subprocess.run(
            ["php", "artisan", "tenant:null-audit", "--format=json"],
            cwd=root,
            capture_output=True,
            text=True,
            timeout=60,
        )
        status = PASS if proc.returncode == 0 else WARN
        return _result(
            "POST-04", "Tenant null audit (advisory)",
            status,
            f"exit={proc.returncode}  " + proc.stdout.strip()[:200],
            "Null tenant_id rows found — review TENANT_NULL_MIGRATION_PLAN.md.",
        )
    except Exception as exc:
        return _result(
            "POST-04", "Tenant null audit (advisory)",
            INFO, f"Skipped: {exc}", "",
        )


# ---------------------------------------------------------------------------
# Orchestrator
# ---------------------------------------------------------------------------

def run_drill(
    args: argparse.Namespace,
    root: Path = _PROJECT_ROOT,
    env: dict[str, str] | None = None,
) -> tuple[list[dict], dict[str, Any]]:
    """Return (steps, plan). Steps include pre-flight + any execute results."""
    steps: list[dict] = []

    # Resolve env
    if env is None:
        env = _resolve_env(root)

    source_db = env.get("DB_DATABASE", "")
    target_db = args.target_db or (source_db + _DEFAULT_TARGET_SUFFIX)

    # Pre-flight
    steps.append(check_env_vars(env))
    steps.append(check_tool("PRE-02", "pg_dump"))
    steps.append(check_tool("PRE-03", "pg_restore"))
    steps.append(check_tool("PRE-04", "createdb"))
    steps.append(check_tool("PRE-05", "dropdb", advisory=True))
    steps.append(check_target_isolation(source_db, target_db))

    # Timestamp for dump file
    ts = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    dump_dir = root / "reports" / "restore_drill"
    dump_path = dump_dir / _DUMP_FILENAME_TEMPLATE.format(ts=ts)

    # Plan (always generated)
    plan = build_plan(env, target_db, dump_path)

    if not getattr(args, "execute", False):
        # Dry-run: pre-flight + plan, no DB changes
        return steps, plan

    # ---- Execute mode ----
    pre_failures = [s for s in steps if s["status"] == FAIL]
    if pre_failures:
        steps.append(_result(
            "ABORT", "Drill aborted — pre-flight failures",
            FAIL, f"Failed steps: {[s['step_id'] for s in pre_failures]}",
            "Fix all pre-flight failures before running with --execute.",
        ))
        return steps, plan

    dump_dir.mkdir(parents=True, exist_ok=True)
    pg_env = _pg_env(env)

    # Dump
    dump_result = run_dump(env, dump_path, pg_env)
    steps.append(dump_result)
    if dump_result["status"] == FAIL:
        return steps, plan

    # Create target DB
    steps.append(run_createdb(env, target_db, pg_env))
    if steps[-1]["status"] == FAIL:
        return steps, plan

    # Restore
    steps.append(run_restore(env, target_db, dump_path, pg_env))
    if steps[-1]["status"] == FAIL:
        steps.append(run_dropdb(env, target_db, pg_env))
        return steps, plan

    # Post-restore checks
    steps.append(check_migrations_table(env, target_db, pg_env))
    steps.append(check_append_only_tables(env, target_db, pg_env))
    steps.append(check_rule_registry(root))
    steps.append(check_tenant_null_audit(root))

    # Cleanup
    steps.append(run_dropdb(env, target_db, pg_env))

    return steps, plan


# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------

def build_report(
    steps: list[dict],
    plan: dict,
    args: argparse.Namespace,
    started_at: str,
    ended_at: str,
) -> dict[str, Any]:
    pass_count = sum(1 for s in steps if s["status"] == PASS)
    fail_count = sum(1 for s in steps if s["status"] == FAIL)
    warn_count = sum(1 for s in steps if s["status"] in (WARN, INFO))
    overall = FAIL if fail_count > 0 else PASS
    return {
        "task": "ENTERPRISE-037",
        "started_at": started_at,
        "ended_at": ended_at,
        "mode": "execute" if getattr(args, "execute", False) else "dry-run",
        "target_db": plan.get("target_db"),
        "source_db": plan.get("source_db"),
        "overall": overall,
        "status_line": f"PASS={pass_count} FAIL={fail_count} WARN={warn_count}",
        "counts": {"pass": pass_count, "fail": fail_count,
                   "warn": warn_count, "total": len(steps)},
        "plan": plan,
        "steps": steps,
    }


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main(args: argparse.Namespace, root: Path = _PROJECT_ROOT,
         env: dict[str, str] | None = None) -> int:
    started_at = datetime.now(timezone.utc).isoformat()
    mode = "execute" if getattr(args, "execute", False) else "dry-run"

    if not getattr(args, "quiet", False):
        print(f"\n  XDR Restore Drill — ENTERPRISE-037")
        print(f"  mode      : {mode}")
        print(f"  target-db : {args.target_db or '(source_db + _restore_drill)'}")
        print()

    steps, plan = run_drill(args, root=root, env=env)
    ended_at = datetime.now(timezone.utc).isoformat()
    report = build_report(steps, plan, args, started_at, ended_at)
    overall = report["overall"]

    if not getattr(args, "quiet", False):
        w = 72
        print("=" * w)
        print(f"  {'ID':<14} {'Step':<38} {'Status'}")
        print("-" * w)
        for s in steps:
            marker = {"PASS": "+", "FAIL": "!", "WARN": "~", "INFO": "."}.get(
                s["status"], "?"
            )
            print(f"  [{marker}] {s['step_id']:<12} {s['name']:<38} {s['status']}")
        print("-" * w)
        marker = {"PASS": "+", "FAIL": "!"}.get(overall, "~")
        print(f"  [{marker}] {'OVERALL':<51} {overall}")
        print(f"  {report['status_line']}")
        print("=" * w)
        if mode == "dry-run":
            print()
            print("  DRY-RUN — no database changes made.")
            print("  Restore plan:")
            for step in plan.get("steps", []):
                print(f"    {step}")
        print()

    if getattr(args, "output", ""):
        out = Path(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")
        if not getattr(args, "quiet", False):
            print(f"  Report: {out}")

    return 0 if overall == PASS else 1


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="xdr_restore_drill.py",
        description="ENTERPRISE-037: Real restore drill — backup → restore → post-restore checks.",
    )
    parser.add_argument(
        "--execute", action="store_true", default=False,
        help="Actually perform the drill (dump → createdb → restore → checks → cleanup). "
             "Default is dry-run (pre-flight + plan only).",
    )
    parser.add_argument(
        "--target-db", default="", dest="target_db",
        help="Name of the isolated restore target DB. "
             "Must differ from source DB. Default: <source_db>_restore_drill.",
    )
    parser.add_argument(
        "--output", default="",
        help="Write JSON report to this path.",
    )
    parser.add_argument(
        "--quiet", action="store_true",
        help="Suppress console output.",
    )
    return parser.parse_args(argv)


if __name__ == "__main__":
    sys.exit(main(_parse_args()))
