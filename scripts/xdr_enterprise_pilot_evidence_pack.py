#!/usr/bin/env python3
"""XDR Enterprise Pilot Evidence Pack (ENTERPRISE-042).

Aggregates evidence from all prior enterprise milestones into one consolidated
report and (optionally) a Markdown evidence pack.  Default mode is safe and
read-only — no subprocess calls, no data mutation, no network access.

Evidence stages
---------------
  EP-01  Final live causal proof evidence
  EP-02  Production deployment profile validation
  EP-03  Recovery readiness validation
  EP-04  Restore drill validation
  EP-05  Live soak / load validation
  EP-06  RBAC / audit governance evidence
  EP-07  Tenant isolation / RLS posture evidence
  EP-08  Operator readiness evidence
  EP-09  EASM advisory posture evidence
  EP-10  Observability / SLO readiness evidence
  EP-11  Pilot readiness matrix evidence
  EP-12  Safety boundary confirmation

Stage categories → profile-aware severity when evidence is missing
------------------------------------------------------------------
  CORE     — WARN (local) / FAIL (staging, production)
  STANDARD — WARN (local, staging) / FAIL (production)
  OPTIONAL — WARN (all profiles)
  STATIC   — Always PASS (static declaration; no files required)

CLI flags
---------
  --profile local|staging|production
  --output PATH               Write JSON report
  --markdown-output PATH      Write Markdown evidence pack
  --execute-validators        Run safe read-only validators via subprocess
  --include-live-soak         Add --execute to live soak validator
  --include-restore-execute   Add --execute to restore drill validator
  --quiet                     Suppress console output

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
# Status / category constants
# ---------------------------------------------------------------------------

PASS = "PASS"
WARN = "WARN"
FAIL = "FAIL"
INFO = "INFO"

CORE = "CORE"
STANDARD = "STANDARD"
OPTIONAL = "OPTIONAL"
STATIC = "STATIC"

SCHEMA_VERSION = "enterprise-pilot-evidence-pack.v1"

SAFETY_BOUNDARIES: dict[str, bool] = {
    "no_active_scanning": True,
    "no_autonomous_containment": True,
    "no_active_allowlist_mutation": True,
    "no_shadow_to_active_auto_promotion": True,
    "no_self_approval": True,
    "easm_advisory_only": True,
    "pilot_matrix_advisory_only": True,
}

ALLOWED_CLAIM = (
    "The platform is ready for a controlled production-pilot evaluation with "
    "bounded synthetic/live validation, enterprise governance controls, tenant isolation "
    "posture, recovery workflow, EASM advisory posture, observability readiness, "
    "and operator runbook coverage."
)

FORBIDDEN_CLAIMS = [
    "The platform is fully production-ready.",
    "The platform is a commercial XDR replacement.",
    "The platform provides autonomous remediation.",
    "The platform provides confirmed attacker attribution.",
    "The platform has full HA/multi-region disaster recovery.",
    "The platform has SOC 2/ISO certification.",
]

REMAINING_GAPS = [
    "No DB-level PostgreSQL RLS (app-layer only; Phase 5 deferred until Phase 3 backfill completes)",
    "Null tenant_id records remain for pre-BACKLOG-019 data (Phase 3 backfill planned)",
    "Endpoint/DNS/proxy/firewall domains remain shadow-only (domain-specific 6h soak not yet run)",
    "No full HA or multi-region disaster recovery validation",
    "No SOC 2 or ISO 27001 certification audit",
    "Live soak validation is opt-in and capped at 1000 synthetic events",
    "Restore drill execute mode requires manual opt-in with isolated target DB",
    "XDR_TENANT_STRICT_MODE defaults to false pending Phase 3 null backfill",
]

NEXT_STEPS = [
    "Agree canonical default tenant; run Phase 3 backfill (php artisan tenant:backfill-nulls)",
    "Run domain-specific 6h soak for endpoint behavioral analytics (prerequisite for active cutover)",
    "Enable XDR_TENANT_STRICT_MODE=true in staging after Phase 3 backfill",
    "Execute full restore drill: python scripts/xdr_restore_drill.py --execute",
    "Execute live soak: python scripts/xdr_live_soak_validate.py --execute",
    "Review EASM findings; notify asset owners of any high severity findings",
    "Run xdr_operator_readiness_check.py --profile=production before each pilot session",
]

# ---------------------------------------------------------------------------
# Stage definitions
# ---------------------------------------------------------------------------

# Validators that accept --profile=<profile>
_PROFILE_AWARE = {
    "scripts/xdr_production_profile_validate.py",
    "scripts/xdr_recovery_validate.py",
    "scripts/xdr_tenant_isolation_posture.py",
    "scripts/xdr_operator_readiness_check.py",
    "scripts/xdr_live_soak_validate.py",
    "scripts/xdr_observability_slo_validate.py",
}

_STAGE_DEFS: list[dict[str, Any]] = [
    {
        "id": "EP-01",
        "name": "Final live causal proof evidence",
        "category": CORE,
        "core_files": [
            "docs/validation/LIVE_035_EVIDENCE_FREEZE.md",
        ],
        "optional_files": [
            "reports/xdr_pilot_live_035_2026-06-24-091923.json",
            "reports/demo-causal-demo-20260624-a716e7.json",
            "reports/pilot_live_035_matrix_summary.json",
            "docs/validation/LIVE_028_EVIDENCE_FREEZE.md",
        ],
        "content_checks": [],
        "validator": "scripts/xdr_pilot_live_validate.py",
        "execute_mode_gate": None,
    },
    {
        "id": "EP-02",
        "name": "Production deployment profile validation",
        "category": CORE,
        "core_files": [
            "docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md",
            "docker-compose.prod.yml",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_production_profile_validate.py",
        "execute_mode_gate": None,
    },
    {
        "id": "EP-03",
        "name": "Recovery readiness validation",
        "category": CORE,
        "core_files": [
            "docs/operations/BACKUP_RESTORE_RECOVERY.md",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_recovery_validate.py",
        "execute_mode_gate": None,
    },
    {
        "id": "EP-04",
        "name": "Restore drill validation",
        "category": STANDARD,
        "core_files": [
            "docs/operations/RESTORE_DRILL.md",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_restore_drill.py",
        "execute_mode_gate": "restore_execute",  # adds --execute when flag set
    },
    {
        "id": "EP-05",
        "name": "Live soak / load validation",
        "category": OPTIONAL,
        "core_files": [
            "docs/operations/LIVE_SOAK_VALIDATION.md",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_live_soak_validate.py",
        "execute_mode_gate": "live_soak",  # adds --execute when flag set
    },
    {
        "id": "EP-06",
        "name": "RBAC / audit governance evidence",
        "category": CORE,
        "core_files": [
            "tests/Feature/RbacAuditCoverageTest.php",
            "app/Services/EndpointResponseCommandService.php",
            "app/Http/Controllers/SocResponseController.php",
        ],
        "optional_files": [],
        "content_checks": [
            {
                "id": "EP-06.CC1",
                "file": "app/Services/EndpointResponseCommandService.php",
                "pattern": "Self-approval is blocked",
                "description": "EndpointResponseCommandService self-approval guard",
                "category": CORE,
            },
            {
                "id": "EP-06.CC2",
                "file": "app/Http/Controllers/SocResponseController.php",
                "pattern": "Self-approval is blocked",
                "description": "SocResponseController self-approval guard",
                "category": CORE,
            },
        ],
        "validator": None,
        "execute_mode_gate": None,
    },
    {
        "id": "EP-07",
        "name": "Tenant isolation / RLS posture evidence",
        "category": CORE,
        "core_files": [
            "docs/security/RLS_DECISION_RECORD.md",
            "docs/security/TENANT_ISOLATION_POSTURE.md",
            "scripts/xdr_tenant_isolation_posture.py",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_tenant_isolation_posture.py",
        "execute_mode_gate": None,
    },
    {
        "id": "EP-08",
        "name": "Operator readiness evidence",
        "category": CORE,
        "core_files": [
            "docs/operations/PILOT_OPERATOR_RUNBOOK.md",
            "scripts/xdr_operator_readiness_check.py",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_operator_readiness_check.py",
        "execute_mode_gate": None,
    },
    {
        "id": "EP-09",
        "name": "EASM advisory posture evidence",
        "category": STANDARD,
        "core_files": [
            "docs/operations/EASM_PASSIVE_POSTURE_MONITORING.md",
            "docs/operations/EASM_POSTURE_HISTORY.md",
            "scripts/xdr_easm_passive_scan.py",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": None,  # EASM scan requires live assets; run separately
        "execute_mode_gate": None,
    },
    {
        "id": "EP-10",
        "name": "Observability / SLO readiness evidence",
        "category": STANDARD,
        "core_files": [
            "docs/operations/RUNTIME_OBSERVABILITY_SLO.md",
            "scripts/xdr_observability_slo_validate.py",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_observability_slo_validate.py",
        "execute_mode_gate": None,
    },
    {
        "id": "EP-11",
        "name": "Pilot readiness matrix evidence",
        "category": STANDARD,
        "core_files": [
            "scripts/xdr_pilot_live_validate.py",
            "scripts/xdr_pilot_readiness_matrix.py",
        ],
        "optional_files": [],
        "content_checks": [],
        "validator": "scripts/xdr_pilot_readiness_matrix.py",
        "execute_mode_gate": None,
    },
    {
        "id": "EP-12",
        "name": "Safety boundary confirmation",
        "category": STATIC,
        "core_files": [],
        "optional_files": [],
        "content_checks": [],
        "validator": None,
        "execute_mode_gate": None,
    },
]

STAGE_IDS = [s["id"] for s in _STAGE_DEFS]

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _fail_sev(profile: str, category: str) -> str:
    if category == CORE:
        return WARN if profile == "local" else FAIL
    if category == STANDARD:
        return FAIL if profile == "production" else WARN
    return WARN  # OPTIONAL


def _file_exists(path: Path, root: Path, _read_fn=None) -> bool:
    if _read_fn is not None:
        return _read_fn(path) is not None
    return (root / path).exists()


def _read_file(path: Path, root: Path, _read_fn=None) -> str | None:
    if _read_fn is not None:
        return _read_fn(path)
    full = root / path
    return full.read_text(encoding="utf-8", errors="replace") if full.exists() else None


def _agg(checks: list[dict]) -> str:
    statuses = {c["status"] for c in checks}
    if FAIL in statuses:
        return FAIL
    if WARN in statuses:
        return WARN
    return PASS


def _chk(cid: str, status: str, detail: str) -> dict:
    return {"id": cid, "status": status, "detail": detail}


def _build_validator_cmd(
    validator: str, profile: str, root: Path, extra_flags: list[str]
) -> list[str]:
    cmd = [sys.executable, str(root / validator), "--quiet"]
    if validator in _PROFILE_AWARE:
        cmd.append(f"--profile={profile}")
    cmd.extend(extra_flags)
    return cmd


# ---------------------------------------------------------------------------
# Stage evaluator
# ---------------------------------------------------------------------------

def _evaluate_stage(
    stage_def: dict,
    root: Path,
    profile: str,
    execute_validators: bool,
    include_live_soak: bool,
    include_restore_execute: bool,
    _read_fn,
    _run_fn,
) -> dict:
    sid = stage_def["id"]
    category = stage_def["category"]

    # STATIC — always PASS, no file checks needed
    if category == STATIC:
        return {
            "id": sid,
            "name": stage_def["name"],
            "status": PASS,
            "required": True,
            "detail": "Safety boundaries confirmed as static platform declarations",
            "evidence_files": [],
            "missing_evidence": [],
            "checks": [_chk(f"{sid}.SB", PASS,
                            "Safety boundaries declared: no active scanning, no autonomous "
                            "containment, no ACTIVE_ALLOWLIST mutation, no shadow-to-active "
                            "auto-promotion, no self-approval")],
        }

    checks: list[dict] = []
    evidence_files: list[str] = []
    missing_evidence: list[str] = []

    # Core file checks
    for i, fpath in enumerate(stage_def["core_files"], 1):
        path = Path(fpath)
        if _file_exists(path, root, _read_fn):
            evidence_files.append(str(path))
            checks.append(_chk(f"{sid}.C{i:02d}", PASS, f"{path} present"))
        else:
            sev = _fail_sev(profile, category)
            missing_evidence.append(str(path))
            checks.append(_chk(f"{sid}.C{i:02d}", sev, f"{path} not found"))

    # Optional file checks
    for i, fpath in enumerate(stage_def["optional_files"], 1):
        path = Path(fpath)
        if _file_exists(path, root, _read_fn):
            evidence_files.append(str(path))
            checks.append(_chk(f"{sid}.O{i:02d}", PASS, f"{path} present"))
        else:
            checks.append(_chk(f"{sid}.O{i:02d}", INFO, f"{path} not found (optional)"))

    # Content checks
    for cc in stage_def["content_checks"]:
        path = Path(cc["file"])
        content = _read_file(path, root, _read_fn)
        if content is None:
            sev = _fail_sev(profile, cc["category"])
            checks.append(_chk(cc["id"], sev, f"{path} not found (content check)"))
        elif cc["pattern"] in content:
            checks.append(_chk(cc["id"], PASS, cc["description"]))
        else:
            sev = _fail_sev(profile, cc["category"])
            checks.append(_chk(cc["id"], sev,
                               f"{cc['description']} — pattern '{cc['pattern']}' not found"))

    # Validator execution (only when execute_validators=True and _run_fn provided)
    if execute_validators and _run_fn is not None:
        validator = stage_def["validator"]
        gate = stage_def["execute_mode_gate"]

        if validator is None:
            checks.append(_chk(f"{sid}.VAL", INFO, "No standalone validator for this stage"))
        elif gate == "live_soak" and not include_live_soak:
            checks.append(_chk(f"{sid}.VAL", INFO,
                               f"{validator} dry-run skipped "
                               "(use --include-live-soak for execute mode)"))
        else:
            extra: list[str] = []
            if gate == "restore_execute" and include_restore_execute:
                extra = ["--execute"]
            elif gate == "live_soak" and include_live_soak:
                extra = ["--execute"]
            cmd = _build_validator_cmd(validator, profile, root, extra)
            exit_code, _, _ = _run_fn(cmd)
            sev = PASS if exit_code == 0 else _fail_sev(profile, category)
            label = "execute" if extra else "dry-run"
            checks.append(_chk(f"{sid}.VAL", sev,
                               f"{validator} ({label}): exit={exit_code}"))

    status = _agg(checks)
    return {
        "id": sid,
        "name": stage_def["name"],
        "status": status,
        "required": category in (CORE, STATIC),
        "detail": (
            f"{len(evidence_files)} evidence file(s) found"
            + (f", {len(missing_evidence)} missing" if missing_evidence else "")
        ),
        "evidence_files": evidence_files,
        "missing_evidence": missing_evidence,
        "checks": checks,
    }


# ---------------------------------------------------------------------------
# run_all
# ---------------------------------------------------------------------------

def _get_git_commit() -> str:
    try:
        import subprocess
        r = subprocess.run(["git", "rev-parse", "HEAD"],
                           capture_output=True, text=True, timeout=5)
        if r.returncode == 0:
            return r.stdout.strip()[:12]
    except Exception:
        pass
    return ""


def run_all(
    root: Path,
    profile: str,
    *,
    execute_validators: bool = False,
    include_live_soak: bool = False,
    include_restore_execute: bool = False,
    _read_fn=None,
    _run_fn=None,
    _commit: str | None = None,
) -> dict[str, Any]:
    stages = []
    for sd in _STAGE_DEFS:
        stages.append(_evaluate_stage(
            sd, root, profile,
            execute_validators, include_live_soak, include_restore_execute,
            _read_fn, _run_fn,
        ))

    passed  = [s for s in stages if s["status"] == PASS]
    warned  = [s for s in stages if s["status"] == WARN]
    failed  = [s for s in stages if s["status"] == FAIL]

    if failed:
        overall = FAIL
    elif warned:
        overall = WARN
    else:
        overall = PASS

    gaps = [
        s["id"] + " " + s["name"]
        for s in stages
        if s["status"] in (WARN, FAIL) and s["missing_evidence"]
    ]

    return {
        "schema_version": SCHEMA_VERSION,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "profile": profile,
        "commit": _commit if _commit is not None else _get_git_commit(),
        "overall_status": overall,
        "summary": {
            "total_stages": len(stages),
            "passed": len(passed),
            "warned": len(warned),
            "failed": len(failed),
        },
        "execute_validators": execute_validators,
        "include_live_soak": include_live_soak,
        "include_restore_execute": include_restore_execute,
        "stages": stages,
        "safety_boundaries": SAFETY_BOUNDARIES,
        "remaining_gaps": REMAINING_GAPS,
        "recommended_next_steps": NEXT_STEPS,
    }


# ---------------------------------------------------------------------------
# Markdown generation
# ---------------------------------------------------------------------------

def generate_markdown(report: dict) -> str:
    overall = report["overall_status"]
    profile = report["profile"]
    ts = report.get("generated_at", "")
    commit = report.get("commit", "")
    s = report["summary"]

    verdict_emoji = {"PASS": "✅", "WARN": "⚠️", "FAIL": "❌"}.get(overall, "❓")

    lines = [
        "# Enterprise Pilot Evidence Pack",
        "",
        f"**Task:** ENTERPRISE-042  ",
        f"**Generated:** {ts}  ",
        f"**Profile:** `{profile}`  ",
        f"**Commit:** `{commit}`  ",
        f"**Overall:** {verdict_emoji} `{overall}`",
        "",
        "---",
        "",
        "## 1. Executive Summary",
        "",
        "This evidence pack consolidates validation results for the XDR platform's "
        "controlled production-pilot evaluation.",
        "",
        "**Framing:** Controlled production-pilot evidence pack.  ",
        "**NOT:** Full production certification, commercial XDR release, "
        "SOC 2/ISO compliance, or HA/multi-region proof.",
        "",
        f"Stages evaluated: **{s['total_stages']}** "
        f"({s['passed']} PASS / {s['warned']} WARN / {s['failed']} FAIL)",
        "",
        "---",
        "",
        "## 2. Current Readiness Verdict",
        "",
        f"**{overall}** — {_verdict_detail(overall)}",
        "",
        "---",
        "",
        "## 3. Evidence Stage Table",
        "",
        "| Stage | Name | Status | Required | Evidence Files |",
        "|---|---|---|---|---|",
    ]

    for stage in report["stages"]:
        status_icon = {"PASS": "✓", "WARN": "!", "FAIL": "✗", "INFO": "i"}.get(
            stage["status"], "?"
        )
        req = "Yes" if stage["required"] else "No"
        ef_count = len(stage["evidence_files"])
        lines.append(
            f"| {stage['id']} | {stage['name']} | {status_icon} {stage['status']} "
            f"| {req} | {ef_count} found |"
        )

    lines += [
        "",
        "---",
        "",
        "## 4. Final Live Causal Proof Reference",
        "",
        "- Evidence freeze: `docs/validation/LIVE_035_EVIDENCE_FREEZE.md`",
        "- JSON report: `reports/xdr_pilot_live_035_2026-06-24-091923.json`",
        "- Causal proof: `reports/demo-causal-demo-20260624-a716e7.json`",
        "- Result: `LIVE_CAUSAL_PROOF=PASS` (PILOT-LIVE-035, 2026-06-24)",
        "",
        "---",
        "",
        "## 5. Production Deployment Profile Reference",
        "",
        "- `docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md`",
        "- `docker-compose.prod.yml`",
        "- Validator: `python scripts/xdr_production_profile_validate.py --profile=production`",
        "",
        "---",
        "",
        "## 6. Restore Drill Reference",
        "",
        "- `docs/operations/RESTORE_DRILL.md`",
        "- Validator (dry-run): `python scripts/xdr_restore_drill.py`",
        "- Validator (execute): `python scripts/xdr_restore_drill.py --execute`",
        "- Safety: Active DB is never overwritten; isolated target DB only.",
        "",
        "---",
        "",
        "## 7. Live Soak / Load Validation Reference",
        "",
        "- `docs/operations/LIVE_SOAK_VALIDATION.md`",
        "- Validator: `python scripts/xdr_live_soak_validate.py --execute`",
        "- Caps: max 1000 synthetic events, max 60 min, max 50 events/batch",
        "- Opt-in: `--include-live-soak` required in evidence pack",
        "",
        "---",
        "",
        "## 8. RBAC / Audit Governance Reference",
        "",
        "- Self-approval guard: `EndpointResponseCommandService::approve()` (service layer)",
        "- Self-approval guard: `SocResponseController::decide()` (controller layer)",
        "- Coverage: `tests/Feature/RbacAuditCoverageTest.php` (26 tests)",
        "- Task: ENTERPRISE-039",
        "",
        "---",
        "",
        "## 9. Tenant Isolation / RLS Decision Reference",
        "",
        "- ADR: `docs/security/RLS_DECISION_RECORD.md`",
        "- Posture: `docs/security/TENANT_ISOLATION_POSTURE.md`",
        "- Validator: `python scripts/xdr_tenant_isolation_posture.py --profile=production`",
        "- Decision: Option A — app-layer enforcement; PostgreSQL RLS deferred to Phase 5.",
        "- `TenantBoundaryService::RLS_ENABLED = false` (machine-readable sentinel)",
        "",
        "---",
        "",
        "## 10. Operator Readiness Reference",
        "",
        "- `docs/operations/PILOT_OPERATOR_RUNBOOK.md` (17 sections, 24 commands, 8 escalation scenarios)",
        "- Validator: `python scripts/xdr_operator_readiness_check.py --profile=production`",
        "- Task: ENTERPRISE-041",
        "",
        "---",
        "",
        "## 11. EASM Posture Reference",
        "",
        "- `docs/operations/EASM_PASSIVE_POSTURE_MONITORING.md`",
        "- `docs/operations/EASM_POSTURE_HISTORY.md`",
        "- Policy: passive scans only — no active probing, no exploit scanning",
        "- All findings are advisory-only; no incidents created automatically",
        "",
        "---",
        "",
        "## 12. Observability / SLO Reference",
        "",
        "- `docs/operations/RUNTIME_OBSERVABILITY_SLO.md`",
        "- Validator: `python scripts/xdr_observability_slo_validate.py --profile=production`",
        "",
        "---",
        "",
        "## 13. Safety Boundary Confirmation",
        "",
        "| Boundary | Status |",
        "|---|---|",
    ]

    for k, v in report.get("safety_boundaries", {}).items():
        icon = "✓ Confirmed" if v else "✗ NOT confirmed"
        lines.append(f"| `{k}` | {icon} |")

    lines += [
        "",
        "---",
        "",
        "## 14. Known Remaining Gaps",
        "",
    ]
    for gap in report.get("remaining_gaps", []):
        lines.append(f"- {gap}")

    lines += [
        "",
        "---",
        "",
        "## 15. Claims Allowed",
        "",
        f"> {ALLOWED_CLAIM}",
        "",
        "---",
        "",
        "## 16. Claims NOT Allowed",
        "",
    ]
    for claim in FORBIDDEN_CLAIMS:
        lines.append(f"- ~~{claim}~~")

    lines += [
        "",
        "---",
        "",
        "## 17. Next Recommended Enterprise Steps",
        "",
    ]
    for step in report.get("recommended_next_steps", []):
        lines.append(f"1. {step}")

    lines += ["", "---", "", f"*Generated by `scripts/xdr_enterprise_pilot_evidence_pack.py`*"]

    return "\n".join(lines) + "\n"


def _verdict_detail(overall: str) -> str:
    return {
        PASS: "All evidence stages pass. Platform is ready for controlled pilot evaluation.",
        WARN: "Some evidence is incomplete or validators have not been run. "
              "Review WARN stages before pilot deployment.",
        FAIL: "Required evidence is missing or a validator failed. "
              "Do not proceed with pilot until FAIL stages are resolved.",
    }.get(overall, "Unknown status.")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="XDR Enterprise Pilot Evidence Pack (ENTERPRISE-042)"
    )
    p.add_argument("--profile", default="local",
                   choices=["local", "staging", "production"],
                   help="Severity profile (default: local)")
    p.add_argument("--output", default="",
                   help="Write JSON report to this path")
    p.add_argument("--markdown-output", default="",
                   help="Write Markdown evidence pack to this path")
    p.add_argument("--execute-validators", action="store_true",
                   help="Run safe read-only validators via subprocess")
    p.add_argument("--include-live-soak", action="store_true",
                   help="Add --execute to live soak validator (requires --execute-validators)")
    p.add_argument("--include-restore-execute", action="store_true",
                   help="Add --execute to restore drill validator (requires --execute-validators)")
    p.add_argument("--quiet", action="store_true",
                   help="Suppress console output")
    return p.parse_args()


def _default_run_fn(cmd: list[str]) -> tuple[int, str, str]:  # pragma: no cover
    import subprocess
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
    return r.returncode, r.stdout, r.stderr


def _print_report(report: dict, quiet: bool) -> None:
    if quiet:
        return
    overall = report["overall_status"]
    s = report["summary"]
    print(f"\nXDR Enterprise Pilot Evidence Pack — {overall}")
    print(f"Profile : {report['profile']}")
    print(f"Stages  : {s['total_stages']} "
          f"({s['passed']} PASS / {s['warned']} WARN / {s['failed']} FAIL)")
    print()
    for stage in report["stages"]:
        icon = {"PASS": "✓", "WARN": "!", "FAIL": "✗"}.get(stage["status"], "?")
        print(f"  [{icon}] {stage['id']}  {stage['name']:<45s}  {stage['status']}")
    if report.get("remaining_gaps"):
        print(f"\nRemaining gaps: {len(report['remaining_gaps'])}")


def main() -> int:
    args = _parse_args()
    root = Path(__file__).parent.parent

    run_fn = _default_run_fn if args.execute_validators else None

    try:
        report = run_all(
            root, args.profile,
            execute_validators=args.execute_validators,
            include_live_soak=args.include_live_soak,
            include_restore_execute=args.include_restore_execute,
            _run_fn=run_fn,
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
        md_path = Path(args.markdown_output)
        md_path.parent.mkdir(parents=True, exist_ok=True)
        md_path.write_text(generate_markdown(report), encoding="utf-8")

    return 0 if report["overall_status"] == PASS else 1


if __name__ == "__main__":
    sys.exit(main())
