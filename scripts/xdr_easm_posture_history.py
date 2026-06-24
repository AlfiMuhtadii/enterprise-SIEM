"""EASM Posture History Report — xdr_easm_posture_history.py

Advisory-only. Reads posture snapshots and finding changes from the database
via a Laravel artisan JSON endpoint, then produces a structured trend report.

This script is a standalone offline report tool — it does NOT perform scanning,
does NOT create incidents, does NOT modify detection rules.

Usage:
    python scripts/xdr_easm_posture_history.py \\
        --asset-id 1 --tenant-id tenant-001 \\
        --output reports/easm_posture_history.json \\
        [--limit 10]
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

ADVISORY_ONLY = True
DEFAULT_LIMIT = 10
MAX_LIMIT = 100

SEVERITY_DEDUCTIONS: dict[str, int] = {
    "high": 20,
    "medium": 10,
    "low": 3,
    "info": 1,
}

SEVERITY_ORDER = ["info", "low", "medium", "high"]

RISK_TIERS = [
    (80, "low"),
    (60, "medium"),
    (40, "high"),
    (0, "critical"),
]


# ---------------------------------------------------------------------------
# Core logic (pure functions — injectable for tests)
# ---------------------------------------------------------------------------


def compute_posture_score(findings: list[dict]) -> int:
    """Return 0-100 posture score from a list of findings with 'severity' keys."""
    deduction = sum(SEVERITY_DEDUCTIONS.get(f.get("severity", "info"), 0) for f in findings)
    return max(0, 100 - deduction)


def classify_risk_tier(score: int) -> str:
    """Map score to risk tier string."""
    for threshold, tier in RISK_TIERS:
        if score >= threshold:
            return tier
    return "critical"


def determine_trend_direction(current: int, previous: int | None) -> str:
    """Return improving / degrading / stable based on score change."""
    if previous is None:
        return "stable"
    if current > previous:
        return "improving"
    if current < previous:
        return "degrading"
    return "stable"


def diff_findings(previous: list[dict], current: list[dict]) -> list[dict]:
    """
    Compute finding-level diff between two snapshots.

    Each item must have 'finding_key' and 'severity'.
    Returns list of dicts with finding_key, change_type, severity_before, severity_after.
    """
    sev_rank = {s: i for i, s in enumerate(SEVERITY_ORDER)}

    prev_map = {f["finding_key"]: f for f in previous}
    curr_map = {f["finding_key"]: f for f in current}

    diffs: list[dict] = []

    for key, curr in curr_map.items():
        curr_sev = curr.get("severity", "info")
        if key not in prev_map:
            diffs.append({
                "finding_key": key,
                "change_type": "new",
                "severity_before": None,
                "severity_after": curr_sev,
            })
        else:
            prev_sev = prev_map[key].get("severity", "info")
            prev_rank = sev_rank.get(prev_sev, 0)
            curr_rank = sev_rank.get(curr_sev, 0)
            diffs.append({
                "finding_key": key,
                "change_type": "regressed" if curr_rank > prev_rank else "unchanged",
                "severity_before": prev_sev,
                "severity_after": curr_sev,
            })

    for key, prev in prev_map.items():
        if key not in curr_map:
            diffs.append({
                "finding_key": key,
                "change_type": "resolved",
                "severity_before": prev.get("severity", "info"),
                "severity_after": None,
            })

    return diffs


def build_trend_report(
    asset_id: int,
    tenant_id: str,
    snapshots: list[dict],
    risk_score_record: dict | None,
    limit: int = DEFAULT_LIMIT,
) -> dict:
    """Assemble the posture trend report from pre-loaded data."""
    limited = snapshots[:limit]

    current_score = risk_score_record.get("risk_score") if risk_score_record else None
    risk_tier = risk_score_record.get("risk_tier") if risk_score_record else None
    trend = risk_score_record.get("trend_direction", "stable") if risk_score_record else "stable"
    total_scans = risk_score_record.get("total_scans", 0) if risk_score_record else 0

    return {
        "asset_id": asset_id,
        "tenant_id": tenant_id,
        "is_advisory": True,
        "current_score": current_score,
        "risk_tier": risk_tier,
        "trend": trend,
        "total_scans": total_scans,
        "snapshot_count": len(limited),
        "snapshots": [
            {
                "score": s.get("posture_score"),
                "risk_tier": s.get("risk_tier"),
                "finding_count": s.get("finding_count_total", 0),
                "new_findings": s.get("new_findings", 0),
                "resolved_findings": s.get("resolved_findings", 0),
                "regressed_findings": s.get("regressed_findings", 0),
                "unchanged_findings": s.get("unchanged_findings", 0),
                "overall_status": s.get("overall_status"),
                "snapshot_at": s.get("snapshot_at"),
            }
            for s in limited
        ],
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }


def build_finding_change_summary(changes: list[dict]) -> dict:
    """Summarise finding changes by type and severity."""
    by_type: dict[str, int] = {"new": 0, "resolved": 0, "unchanged": 0, "regressed": 0}
    for c in changes:
        ct = c.get("change_type", "unchanged")
        by_type[ct] = by_type.get(ct, 0) + 1

    return {
        "total_changes": len(changes),
        "by_type": by_type,
        "is_advisory": True,
    }


# ---------------------------------------------------------------------------
# I/O helpers
# ---------------------------------------------------------------------------


def load_snapshot_data(input_path: str) -> dict:
    """Load pre-exported snapshot JSON from a file."""
    p = Path(input_path)
    if not p.exists():
        raise FileNotFoundError(f"Input file not found: {input_path}")
    with p.open() as f:
        return json.load(f)


def write_report(report: dict, output_path: str) -> None:
    p = Path(output_path)
    p.parent.mkdir(parents=True, exist_ok=True)
    with p.open("w") as f:
        json.dump(report, f, indent=2)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="EASM posture history report (advisory-only, no scanning)"
    )
    p.add_argument("--asset-id", type=int, required=True, help="Asset ID")
    p.add_argument("--tenant-id", type=str, required=True, help="Tenant ID")
    p.add_argument("--input", type=str, default=None,
                   help="Path to pre-exported snapshot JSON (optional)")
    p.add_argument("--output", type=str, default=None,
                   help="Path to write JSON report")
    p.add_argument("--limit", type=int, default=DEFAULT_LIMIT,
                   help=f"Max snapshots (default {DEFAULT_LIMIT}, max {MAX_LIMIT})")
    return p.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    limit = min(max(1, args.limit), MAX_LIMIT)

    snapshots: list[dict] = []
    risk_score_record: dict | None = None
    changes: list[dict] = []

    if args.input:
        try:
            data = load_snapshot_data(args.input)
            snapshots = data.get("snapshots", [])
            risk_score_record = data.get("risk_score")
            changes = data.get("changes", [])
        except (FileNotFoundError, json.JSONDecodeError, KeyError) as exc:
            print(f"[ERROR] Failed to load input: {exc}", file=sys.stderr)
            return 1

    report = build_trend_report(args.asset_id, args.tenant_id, snapshots, risk_score_record, limit)
    report["finding_change_summary"] = build_finding_change_summary(changes)

    if args.output:
        write_report(report, args.output)
        print(f"[OK] Report written to {args.output}")
    else:
        print(json.dumps(report, indent=2))

    return 0


if __name__ == "__main__":
    sys.exit(main())
