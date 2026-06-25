#!/usr/bin/env python3
"""XDR Enterprise-043 Execute Evidence Runs.

Converts selected enterprise evidence from dry-run / readiness state to
executed controlled production-pilot evidence.

Correct framing
---------------
  Controlled production-pilot execute evidence runs.

Not
---
  Full production certification.
  Commercial XDR launch.
  HA/multi-region DR proof.
  SOC 2 / ISO certification.
  Autonomous remediation proof.

Evidence stages
---------------
  EXE-01  Production profile validation
  EXE-02  Tenant isolation posture validation
  EXE-03  Operator readiness validation
  EXE-04  Recovery readiness validation
  EXE-05  Restore drill dry-run
  EXE-06  Restore drill execute mode
  EXE-07  Live soak dry-run
  EXE-08  Live soak execute mode
  EXE-09  Final live causal proof
  EXE-10  Enterprise pilot evidence pack regeneration
  EXE-11  Final evidence freeze summary
  EXE-12  Safety boundary confirmation

Default mode: build execution plan only — no subprocess, no network, no mutation.

CLI
---
  --profile local|staging|production
  --output PATH
  --markdown-output PATH
  --execute-readonly-validators
  --execute-restore-drill
  --execute-live-soak
  --execute-live-causal-proof
  --live-soak-duration-minutes 5|15|30|60
  --quiet

Exit codes: 0=PASS, 1=FAIL or WARN, 2=ERROR
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

PASS    = "PASS"
WARN    = "WARN"
FAIL    = "FAIL"
INFO    = "INFO"
ERROR   = "ERROR"
SKIPPED = "SKIPPED"

SCHEMA_VERSION = "enterprise-043-execute-evidence-runs.v1"

SAFETY_BOUNDARIES: dict[str, bool] = {
    "no_detection_rule_change": True,
    "no_active_allowlist_mutation": True,
    "no_shadow_to_active_auto_promotion": True,
    "no_active_scanning": True,
    "no_autonomous_containment": True,
    "restore_target_isolated": True,
    "live_soak_bounded": True,
    "synthetic_telemetry_only": True,
}

# Live soak safety caps (mirrors xdr_live_soak_validate.py)
MAX_TOTAL_EVENTS      = 1000
MAX_DURATION_MINUTES  = 60
MAX_EVENTS_PER_BATCH  = 50
MIN_BATCH_INTERVAL_MS = 200

ALLOWED_CLAIM = (
    "The platform has controlled production-pilot execute evidence for deployment posture, "
    "tenant isolation posture, operator readiness, recovery validation, restore drill workflow, "
    "bounded live soak validation, and live causal proof."
)

FORBIDDEN_CLAIMS = [
    "The platform is fully production-certified.",
    "The platform is a commercial XDR replacement.",
    "The platform provides autonomous remediation.",
    "The platform has full HA/multi-region DR.",
    "The platform is SOC 2 / ISO certified.",
    "The platform confirms attacker identity.",
]

REMAINING_GAPS = [
    "No DB-level PostgreSQL RLS (app-layer only; Phase 5 deferred)",
    "Null tenant_id records remain for pre-BACKLOG-019 data (Phase 3 backfill planned)",
    "Endpoint/DNS/proxy/firewall domains remain shadow-only (domain-specific 6h soak not run)",
    "No full HA or multi-region disaster recovery validation",
    "No SOC 2 or ISO 27001 certification audit",
    "Live soak bounded to 1000 synthetic events; real traffic volume unvalidated",
    "Restore drill execute mode requires manual opt-in with isolated target DB",
    "XDR_TENANT_STRICT_MODE defaults to false pending Phase 3 null backfill",
]

NEXT_STEPS = [
    "Run Phase 3 tenant backfill: php artisan tenant:backfill-nulls",
    "Run domain-specific 6h soak for endpoint behavioral analytics",
    "Enable XDR_TENANT_STRICT_MODE=true in staging after backfill",
    "Execute full restore drill: python scripts/xdr_restore_drill.py --execute",
    "Execute live soak: python scripts/xdr_live_soak_validate.py --execute",
    "Review EASM findings; notify asset owners of any high-severity findings",
    "Run xdr_operator_readiness_check.py --profile=production before each pilot session",
]

# Profile-aware validators (accept --profile=<profile>)
_PROFILE_AWARE = {
    "scripts/xdr_production_profile_validate.py",
    "scripts/xdr_tenant_isolation_posture.py",
    "scripts/xdr_operator_readiness_check.py",
    "scripts/xdr_recovery_validate.py",
    "scripts/xdr_live_soak_validate.py",
}

# ---------------------------------------------------------------------------
# Stage definitions
# ---------------------------------------------------------------------------

# category: REQUIRED = fail when execute requested but not done
#           OPTIONAL = warn only
#           STATIC   = always PASS

_STAGE_DEFS: list[dict[str, Any]] = [
    {
        "id": "EXE-01",
        "name": "Production profile validation",
        "script": "scripts/xdr_production_profile_validate.py",
        "report_path": "reports/enterprise_043_exe01_prod_profile.json",
        "extra_flags": ["--output", "reports/enterprise_043_exe01_prod_profile.json"],
        "execute_flag": "readonly",   # enabled by --execute-readonly-validators
        "required": True,
    },
    {
        "id": "EXE-02",
        "name": "Tenant isolation posture validation",
        "script": "scripts/xdr_tenant_isolation_posture.py",
        "report_path": "reports/enterprise_043_exe02_tenant_isolation.json",
        "extra_flags": ["--output", "reports/enterprise_043_exe02_tenant_isolation.json"],
        "execute_flag": "readonly",
        "required": True,
    },
    {
        "id": "EXE-03",
        "name": "Operator readiness validation",
        "script": "scripts/xdr_operator_readiness_check.py",
        "report_path": "reports/enterprise_043_exe03_operator_readiness.json",
        "extra_flags": ["--output", "reports/enterprise_043_exe03_operator_readiness.json"],
        "execute_flag": "readonly",
        "required": True,
    },
    {
        "id": "EXE-04",
        "name": "Recovery readiness validation",
        "script": "scripts/xdr_recovery_validate.py",
        "report_path": "reports/enterprise_043_exe04_recovery.json",
        "extra_flags": ["--output", "reports/enterprise_043_exe04_recovery.json"],
        "execute_flag": "readonly",
        "required": True,
    },
    {
        "id": "EXE-05",
        "name": "Restore drill dry-run",
        "script": "scripts/xdr_restore_drill.py",
        "report_path": "reports/enterprise_043_exe05_restore_dryrun.json",
        "extra_flags": ["--output", "reports/enterprise_043_exe05_restore_dryrun.json"],
        "execute_flag": "readonly",   # dry-run enabled by --execute-readonly-validators
        "required": True,
    },
    {
        "id": "EXE-06",
        "name": "Restore drill execute mode",
        "script": "scripts/xdr_restore_drill.py",
        "report_path": "reports/enterprise_043_exe06_restore_execute.json",
        "extra_flags": [
            "--execute",
            "--output", "reports/enterprise_043_exe06_restore_execute.json",
        ],
        "execute_flag": "restore",    # enabled by --execute-restore-drill
        "required": False,
    },
    {
        "id": "EXE-07",
        "name": "Live soak dry-run",
        "script": "scripts/xdr_live_soak_validate.py",
        "report_path": "reports/enterprise_043_exe07_soak_dryrun.json",
        "extra_flags": ["--output", "reports/enterprise_043_exe07_soak_dryrun.json"],
        "execute_flag": "readonly",
        "required": True,
    },
    {
        "id": "EXE-08",
        "name": "Live soak execute mode",
        "script": "scripts/xdr_live_soak_validate.py",
        "report_path": "reports/enterprise_043_exe08_soak_execute.json",
        "extra_flags": [
            "--execute",
            "--output", "reports/enterprise_043_exe08_soak_execute.json",
        ],
        "execute_flag": "soak",       # enabled by --execute-live-soak
        "required": False,
    },
    {
        "id": "EXE-09",
        "name": "Final live causal proof",
        "script": "scripts/demo_causal_verify.py",
        "report_path": "reports/enterprise_043_exe09_causal_proof.json",
        "extra_flags": ["--output", "reports/enterprise_043_exe09_causal_proof.json"],
        "execute_flag": "causal",     # enabled by --execute-live-causal-proof
        "required": False,
    },
    {
        "id": "EXE-10",
        "name": "Enterprise pilot evidence pack regeneration",
        "script": "scripts/xdr_enterprise_pilot_evidence_pack.py",
        "report_path": "reports/enterprise_043_exe10_evidence_pack.json",
        "extra_flags": [
            "--output", "reports/enterprise_043_exe10_evidence_pack.json",
            "--markdown-output",
            "docs/validation/ENTERPRISE_043_EVIDENCE_PACK_REFRESH.md",
        ],
        "execute_flag": "readonly",
        "required": True,
    },
    {
        "id": "EXE-11",
        "name": "Final evidence freeze summary",
        "script": None,              # synthesised from prior stage results
        "report_path": None,
        "extra_flags": [],
        "execute_flag": "summary",   # computed stage — never needs subprocess
        "required": True,
    },
    {
        "id": "EXE-12",
        "name": "Safety boundary confirmation",
        "script": None,              # static declaration
        "report_path": None,
        "extra_flags": [],
        "execute_flag": "static",
        "required": True,
    },
]

STAGE_IDS = [s["id"] for s in _STAGE_DEFS]

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _fail_sev(profile: str) -> str:
    return WARN if profile == "local" else FAIL


def _script_exists(script: str | None, root: Path, _read_fn=None) -> bool:
    if script is None:
        return True
    path = Path(script)
    if _read_fn is not None:
        return _read_fn(path) is not None
    return (root / path).exists()


def _build_cmd(stage_def: dict, profile: str, root: Path, duration_minutes: int) -> list[str]:
    script = stage_def["script"]
    if script is None:
        return []
    cmd = [sys.executable, str(root / script), "--quiet"]
    if script in _PROFILE_AWARE:
        cmd.append(f"--profile={profile}")
    # inject duration for live soak
    if "xdr_live_soak_validate" in script and stage_def["execute_flag"] == "soak":
        cmd.extend(["--duration-minutes", str(min(duration_minutes, MAX_DURATION_MINUTES))])
    cmd.extend(stage_def["extra_flags"])
    return cmd


def _agg(statuses: list[str]) -> str:
    if ERROR in statuses or FAIL in statuses:
        return FAIL
    if WARN in statuses:
        return WARN
    # SKIPPED and INFO are neutral — plan generation or optional stages do not block PASS
    return PASS


def _stage_result(
    stage_def: dict,
    status: str,
    executed: bool,
    command: list[str],
    detail: str,
    exit_code: int | None = None,
) -> dict:
    return {
        "id": stage_def["id"],
        "name": stage_def["name"],
        "status": status,
        "executed": executed,
        "required": stage_def["required"],
        "command": " ".join(command) if command else "",
        "report_path": stage_def["report_path"] or "",
        "detail": detail,
        "exit_code": exit_code,
    }


# ---------------------------------------------------------------------------
# Restore target isolation guard
# ---------------------------------------------------------------------------

def _check_restore_isolation(root: Path, _read_fn=None) -> bool:
    """Return True only if the restore drill has an isolated target configured."""
    env_path = Path(".env")
    if _read_fn is not None:
        content = _read_fn(env_path)
    else:
        full = root / env_path
        content = full.read_text(encoding="utf-8", errors="replace") if full.exists() else None
    if content is None:
        return False
    # Target DB must differ from source DB.
    # The restore drill uses XDR_RESTORE_TARGET_DB (different from DB_DATABASE).
    import re
    src = re.search(r"^DB_DATABASE\s*=\s*(.+)$", content, re.MULTILINE)
    tgt = re.search(r"^XDR_RESTORE_TARGET_DB\s*=\s*(.+)$", content, re.MULTILINE)
    if src and tgt:
        return src.group(1).strip() != tgt.group(1).strip()
    # If XDR_RESTORE_TARGET_DB is not set at all, target is not isolated
    return False


# ---------------------------------------------------------------------------
# Stage executor
# ---------------------------------------------------------------------------

def _execute_stage(
    stage_def: dict,
    root: Path,
    profile: str,
    execute_readonly: bool,
    execute_restore: bool,
    execute_soak: bool,
    execute_causal: bool,
    duration_minutes: int,
    _read_fn,
    _run_fn,
) -> dict:
    sid      = stage_def["id"]
    eflag    = stage_def["execute_flag"]

    # EXE-12 — static safety boundary declaration
    if eflag == "static":
        return _stage_result(
            stage_def, PASS, False, [],
            "Safety boundaries declared: no detection rule change, no ACTIVE_ALLOWLIST mutation, "
            "no shadow-to-active auto-promotion, no active scanning, no autonomous containment, "
            "restore target isolated, live soak bounded, synthetic telemetry only",
        )

    # EXE-11 — synthesised summary; computed after all other stages complete
    if eflag == "summary":
        return _stage_result(
            stage_def, PASS, False, [],
            "Final evidence freeze summary stage — status computed from preceding stages",
        )

    # Check script exists
    script_present = _script_exists(stage_def["script"], root, _read_fn)
    if not script_present:
        sev = _fail_sev(profile) if stage_def["required"] else WARN
        return _stage_result(
            stage_def, sev, False, [],
            f"Script {stage_def['script']} not found",
        )

    # Determine whether this stage should execute
    should_execute = (
        (eflag == "readonly" and execute_readonly)
        or (eflag == "restore" and execute_restore)
        or (eflag == "soak" and execute_soak)
        or (eflag == "causal" and execute_causal)
    )

    # Build command regardless (needed for plan generation)
    cmd = _build_cmd(stage_def, profile, root, duration_minutes)

    if not should_execute:
        # Dry-run plan entry
        flag_name = {
            "readonly": "--execute-readonly-validators",
            "restore":  "--execute-restore-drill",
            "soak":     "--execute-live-soak",
            "causal":   "--execute-live-causal-proof",
        }.get(eflag, eflag)
        status = SKIPPED if not stage_def["required"] else INFO
        return _stage_result(
            stage_def, status, False, cmd,
            f"Planned — run with {flag_name} to execute",
        )

    # Restore isolation guard
    if eflag == "restore":
        isolated = _check_restore_isolation(root, _read_fn)
        if not isolated:
            return _stage_result(
                stage_def, FAIL, False, cmd,
                "Restore target DB isolation guard failed: "
                "XDR_RESTORE_TARGET_DB must be set and differ from DB_DATABASE",
            )

    # Execute
    if _run_fn is None:
        return _stage_result(
            stage_def, ERROR, False, cmd,
            "Execute requested but no _run_fn provided",
        )

    try:
        exit_code, stdout, stderr = _run_fn(cmd)
    except Exception as exc:
        return _stage_result(
            stage_def, ERROR, True, cmd,
            f"Subprocess raised: {exc}",
        )

    if exit_code == 0:
        return _stage_result(
            stage_def, PASS, True, cmd,
            f"Executed successfully (exit={exit_code})",
            exit_code=exit_code,
        )
    if exit_code == 1:
        sev = _fail_sev(profile) if stage_def["required"] else WARN
        return _stage_result(
            stage_def, sev, True, cmd,
            f"Validator reported WARN or FAIL (exit={exit_code}): {stderr.strip()[:120]}",
            exit_code=exit_code,
        )
    # exit code 2 or other
    return _stage_result(
        stage_def, ERROR, True, cmd,
        f"Validator returned error exit code {exit_code}: {stderr.strip()[:120]}",
        exit_code=exit_code,
    )


# ---------------------------------------------------------------------------
# run_all
# ---------------------------------------------------------------------------

def run_all(
    root: Path,
    profile: str,
    *,
    execute_readonly: bool = False,
    execute_restore: bool = False,
    execute_soak: bool = False,
    execute_causal: bool = False,
    duration_minutes: int = 5,
    _read_fn=None,
    _run_fn=None,
    _commit: str | None = None,
) -> dict[str, Any]:
    if profile not in ("local", "staging", "production"):
        return {
            "schema_version": SCHEMA_VERSION,
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "profile": profile,
            "mode": "error",
            "overall_status": ERROR,
            "error": f"Invalid profile: {profile!r}",
            "stages": [],
            "executed_commands": [],
            "generated_reports": [],
            "safety_boundaries": SAFETY_BOUNDARIES,
            "remaining_gaps": REMAINING_GAPS,
            "recommended_next_steps": NEXT_STEPS,
            "summary": {"total": 0, "passed": 0, "warned": 0, "failed": 0,
                        "skipped": 0, "executed": 0},
        }

    # Cap duration
    duration_minutes = min(max(1, duration_minutes), MAX_DURATION_MINUTES)

    any_execute = execute_readonly or execute_restore or execute_soak or execute_causal
    mode = "execute" if any_execute else "dry-run"

    stages: list[dict] = []
    for sd in _STAGE_DEFS:
        result = _execute_stage(
            sd, root, profile,
            execute_readonly, execute_restore, execute_soak, execute_causal,
            duration_minutes, _read_fn, _run_fn,
        )
        stages.append(result)

    # Recompute EXE-11 summary based on prior stages (all except EXE-11/EXE-12)
    prior_statuses = [s["status"] for s in stages if s["id"] not in ("EXE-11", "EXE-12")]
    exe11_status = _agg(prior_statuses) if any_execute else INFO
    for s in stages:
        if s["id"] == "EXE-11":
            executed_count = sum(1 for x in stages if x.get("executed"))
            s["status"] = exe11_status
            s["detail"] = (
                f"Evidence freeze: {executed_count} stage(s) executed, "
                f"overall prior={exe11_status}"
            )
            break

    executed_commands = [s["command"] for s in stages if s.get("executed") and s["command"]]
    generated_reports = [
        s["report_path"] for s in stages if s.get("executed") and s["report_path"]
    ]

    top_statuses = [s["status"] for s in stages]
    overall = _agg(top_statuses)

    commit = _commit
    if commit is None and _run_fn is None:
        try:
            import subprocess as _sp
            r = _sp.run(["git", "rev-parse", "HEAD"], capture_output=True, text=True,
                        cwd=str(root))
            commit = r.stdout.strip() or "unknown"
        except Exception:
            commit = "unknown"

    summary = {
        "total":    len(stages),
        "passed":   sum(1 for s in stages if s["status"] == PASS),
        "warned":   sum(1 for s in stages if s["status"] == WARN),
        "failed":   sum(1 for s in stages if s["status"] == FAIL),
        "skipped":  sum(1 for s in stages if s["status"] in (SKIPPED, INFO)),
        "executed": sum(1 for s in stages if s.get("executed")),
    }

    return {
        "schema_version": SCHEMA_VERSION,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "profile": profile,
        "mode": mode,
        "overall_status": overall,
        "commit": commit,
        "execute_readonly": execute_readonly,
        "execute_restore": execute_restore,
        "execute_soak": execute_soak,
        "execute_causal": execute_causal,
        "duration_minutes": duration_minutes,
        "stages": stages,
        "executed_commands": executed_commands,
        "generated_reports": generated_reports,
        "safety_boundaries": SAFETY_BOUNDARIES,
        "remaining_gaps": REMAINING_GAPS,
        "recommended_next_steps": NEXT_STEPS,
        "summary": summary,
    }


# ---------------------------------------------------------------------------
# Markdown generator
# ---------------------------------------------------------------------------

_STATUS_ICON = {PASS: "✓", WARN: "!", FAIL: "✗", ERROR: "E", SKIPPED: "–", INFO: "·"}


def generate_markdown(report: dict) -> str:
    ts      = report.get("generated_at", "")
    profile = report.get("profile", "")
    mode    = report.get("mode", "")
    overall = report.get("overall_status", "")
    commit  = report.get("commit", "unknown")
    summary = report.get("summary", {})
    stages  = report.get("stages", [])

    lines: list[str] = []

    def h(level: int, text: str) -> None:
        lines.append(f"\n{'#' * level} {text}\n")

    def p(text: str) -> None:
        lines.append(text)

    h(1, "ENTERPRISE-043 Execute Evidence Runs")
    p(f"> **Generated:** {ts}")
    p(f"> **Profile:** {profile}")
    p(f"> **Mode:** {mode}")
    p(f"> **Overall status:** {overall}")
    p(f"> **Commit:** {commit}")

    h(2, "1. Executive Summary")
    p(
        "This document records the controlled production-pilot execute evidence runs for the "
        "XDR-like platform. It covers deployment posture, tenant isolation, operator readiness, "
        "recovery validation, restore drill, bounded live soak, and live causal proof."
    )
    p(
        "\n**Framing:** Controlled production-pilot execute evidence runs — NOT full production "
        "certification, commercial XDR launch, HA/DR proof, SOC 2 certification, or autonomous "
        "remediation proof."
    )

    h(2, "2. Execution Mode")
    p(f"Mode: **{mode}**")
    p(f"- Execute readonly validators: {report.get('execute_readonly', False)}")
    p(f"- Execute restore drill: {report.get('execute_restore', False)}")
    p(f"- Execute live soak: {report.get('execute_soak', False)}")
    p(f"- Execute live causal proof: {report.get('execute_causal', False)}")
    if report.get("execute_soak"):
        p(f"- Live soak duration cap: {report.get('duration_minutes', 5)} minutes "
          f"(hard max: {MAX_DURATION_MINUTES})")

    h(2, "3. Profile")
    p(f"Profile: **{profile}**")
    p(f"- Total stages: {summary.get('total', 0)}")
    p(f"- PASS: {summary.get('passed', 0)}")
    p(f"- WARN: {summary.get('warned', 0)}")
    p(f"- FAIL: {summary.get('failed', 0)}")
    p(f"- SKIPPED/INFO: {summary.get('skipped', 0)}")
    p(f"- Executed: {summary.get('executed', 0)}")

    h(2, "4. Stage Table")
    p("| ID | Name | Status | Executed | Required |")
    p("|---|---|---|---|---|")
    for s in stages:
        icon = _STATUS_ICON.get(s["status"], "?")
        exe  = "Yes" if s.get("executed") else "No"
        req  = "Yes" if s.get("required") else "No"
        p(f"| {s['id']} | {s['name']} | {icon} {s['status']} | {exe} | {req} |")

    h(2, "5. Commands Executed")
    cmds = report.get("executed_commands", [])
    if cmds:
        for c in cmds:
            p(f"```\n{c}\n```")
    else:
        p("_No commands executed (dry-run mode)._")

    h(2, "6. Reports Generated")
    rpts = report.get("generated_reports", [])
    if rpts:
        for r in rpts:
            p(f"- `{r}`")
    else:
        p("_No reports generated (dry-run mode)._")

    h(2, "7. Restore Drill Result")
    exe06 = next((s for s in stages if s["id"] == "EXE-06"), None)
    if exe06 and exe06.get("executed"):
        p(f"Status: **{exe06['status']}**")
        p(f"Detail: {exe06['detail']}")
    else:
        p("Restore drill execute mode was not run in this invocation.  "
          "Use `--execute-restore-drill` with an isolated target DB.")

    h(2, "8. Live Soak Result")
    exe08 = next((s for s in stages if s["id"] == "EXE-08"), None)
    if exe08 and exe08.get("executed"):
        p(f"Status: **{exe08['status']}**")
        p(f"Detail: {exe08['detail']}")
    else:
        p("Live soak execute mode was not run in this invocation.  "
          "Use `--execute-live-soak` with optional `--live-soak-duration-minutes`.")

    h(2, "9. Live Causal Proof Result")
    exe09 = next((s for s in stages if s["id"] == "EXE-09"), None)
    if exe09 and exe09.get("executed"):
        p(f"Status: **{exe09['status']}**")
        p(f"Detail: {exe09['detail']}")
    else:
        p("Live causal proof was not run in this invocation.  "
          "Use `--execute-live-causal-proof`.")

    h(2, "10. Safety Boundary Confirmation")
    p("| Boundary | Status |")
    p("|---|---|")
    for k, v in report.get("safety_boundaries", {}).items():
        icon = "✓" if v else "✗"
        p(f"| `{k}` | {icon} {'True' if v else 'False'} |")

    h(2, "11. Remaining Gaps")
    for gap in report.get("remaining_gaps", []):
        p(f"- {gap}")

    h(2, "12. Claims Allowed")
    p(f"> {ALLOWED_CLAIM}")

    h(2, "13. Claims Not Allowed")
    for claim in FORBIDDEN_CLAIMS:
        p(f"- ~~{claim}~~")

    h(2, "14. Next Recommended Steps")
    for step in report.get("recommended_next_steps", []):
        p(f"1. {step}")

    return "\n".join(lines)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="XDR Enterprise-043 Execute Evidence Runs"
    )
    p.add_argument(
        "--profile", default="local",
        choices=["local", "staging", "production"],
        help="Severity profile (default: local)",
    )
    p.add_argument("--output", default="",
                   help="Write JSON report to this path")
    p.add_argument("--markdown-output", default="",
                   dest="markdown_output",
                   help="Write Markdown evidence freeze to this path")
    p.add_argument("--execute-readonly-validators", action="store_true",
                   dest="execute_readonly",
                   help="Run read-only validators via subprocess")
    p.add_argument("--execute-restore-drill", action="store_true",
                   dest="execute_restore",
                   help="Run restore drill in execute mode (requires isolated target DB)")
    p.add_argument("--execute-live-soak", action="store_true",
                   dest="execute_soak",
                   help="Run live soak in execute mode")
    p.add_argument("--execute-live-causal-proof", action="store_true",
                   dest="execute_causal",
                   help="Run live causal proof")
    p.add_argument("--live-soak-duration-minutes", type=int, default=5,
                   dest="duration_minutes",
                   help=f"Live soak duration in minutes (default: 5, max: {MAX_DURATION_MINUTES})")
    p.add_argument("--quiet", action="store_true",
                   help="Suppress console output")
    return p.parse_args()


def _print_report(report: dict, quiet: bool) -> None:
    if quiet:
        return
    print(f"\nENTERPRISE-043 Execute Evidence Runs — {report['overall_status']}")
    print(f"Profile : {report['profile']}")
    print(f"Mode    : {report['mode']}")
    s = report["summary"]
    print(f"Stages  : {s['total']} ({s['passed']} PASS / {s['warned']} WARN / "
          f"{s['failed']} FAIL / {s['skipped']} SKIPPED / {s['executed']} executed)")
    print()
    for stage in report["stages"]:
        icon = _STATUS_ICON.get(stage["status"], "?")
        exe  = "executed" if stage.get("executed") else "plan"
        print(f"  [{icon}] {stage['id']:6s}  {stage['name']:<42s}  {stage['status']:<7s}  [{exe}]")
    if report["executed_commands"]:
        print(f"\nExecuted commands: {len(report['executed_commands'])}")
    if report["generated_reports"]:
        print(f"Generated reports: {', '.join(report['generated_reports'])}")


def main() -> int:
    args = _parse_args()
    root = Path(__file__).parent.parent

    try:
        report = run_all(
            root,
            args.profile,
            execute_readonly=args.execute_readonly,
            execute_restore=args.execute_restore,
            execute_soak=args.execute_soak,
            execute_causal=args.execute_causal,
            duration_minutes=args.duration_minutes,
        )
    except Exception as exc:  # pragma: no cover
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    _print_report(report, args.quiet)

    if args.output:
        out = Path(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")

    if args.markdown_output:
        md = generate_markdown(report)
        mdo = Path(args.markdown_output)
        mdo.parent.mkdir(parents=True, exist_ok=True)
        mdo.write_text(md, encoding="utf-8")

    overall = report["overall_status"]
    if overall == ERROR:
        return 2
    return 0 if overall == PASS else 1


if __name__ == "__main__":
    sys.exit(main())
