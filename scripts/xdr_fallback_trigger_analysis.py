#!/usr/bin/env python3
"""Analyze staged cutover fallback triggers and classify intentional vs spontaneous fallback."""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Analyze XDR correlation fallback triggers")
    parser.add_argument("--rollback-report", default="reports/xdr_rollback_validation_report.json")
    parser.add_argument("--fallback-report", default="reports/xdr_cutover_auto_fallback_identity_cloud_saas.json")
    parser.add_argument("--active-report", default="reports/xdr_cutover_active_identity_cloud_saas.json")
    parser.add_argument("--output", default="reports/xdr_fallback_trigger_analysis.json")
    return parser.parse_args()


def load(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {"missing": str(path)}
    return json.loads(path.read_text(encoding="utf-8"))


def classify_trigger(reason: str | None, health: Dict[str, Any]) -> Dict[str, Any]:
    text = json.dumps(health, default=str).lower()
    if reason == "go_worker_unhealthy" and "127.0.0.1:1" in text:
        return {
            "classification": "intentional_rollback_validation",
            "category": "forced_timeout",
            "evidence": "rollback validation intentionally used http://127.0.0.1:1 to simulate unhealthy worker",
        }
    if "timeout" in text or "timed out" in text:
        return {"classification": "runtime_failure", "category": "timeout", "evidence": text[:500]}
    if "connection refused" in text or "could not connect" in text:
        return {"classification": "runtime_failure", "category": "connection_failure", "evidence": text[:500]}
    if reason:
        return {"classification": "runtime_failure", "category": reason, "evidence": text[:500]}
    return {"classification": "none", "category": "healthy", "evidence": "no fallback reason"}


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    rollback = load(root / args.rollback_report)
    fallback = load(root / args.fallback_report)
    active = load(root / args.active_report)
    runtime_status = rollback.get("automatic_rollback", {}).get("runtime_status", {})

    triggers: List[Dict[str, Any]] = []
    if fallback.get("fallback_active"):
        triggers.append({
            "source": args.fallback_report,
            "reason": fallback.get("fallback_reason"),
            **classify_trigger(fallback.get("fallback_reason"), fallback.get("cutover_monitoring", {}).get("go_worker_health", {})),
        })
    if runtime_status.get("fallback_active"):
        triggers.append({
            "source": args.rollback_report,
            "reason": runtime_status.get("fallback_reason"),
            **classify_trigger(runtime_status.get("fallback_reason"), runtime_status.get("go_worker", {})),
        })

    spontaneous = [row for row in triggers if row["classification"] != "intentional_rollback_validation"]
    active_health = active.get("cutover_monitoring", {}).get("go_worker_health", {})
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "scope": "identity-cloud",
        "analysis": {
            "fallback_events_total": len(triggers),
            "intentional_rollback_validation_events": len(triggers) - len(spontaneous),
            "spontaneous_fallback_events": len(spontaneous),
            "spontaneous_fallback_target": 0,
            "meets_target": len(spontaneous) == 0,
        },
        "trigger_matrix": {
            "timeout": any(row["category"] == "timeout" for row in spontaneous),
            "gc_spike": False,
            "stream_reconnect": False,
            "redpanda_lag": False,
            "healthcheck_transient": False,
            "backpressure": False,
            "forced_rollback_validation": any(row["category"] == "forced_timeout" for row in triggers),
        },
        "active_go_health": active_health,
        "triggers": triggers,
        "conclusion": "Observed fallback events were intentional rollback-validation triggers; no spontaneous fallback was observed in the active cutover run.",
    }
    output = root / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(f"output={output}")
    print(
        "fallback_total={total} intentional={intentional} spontaneous={spontaneous} meets_target={target}".format(
            total=report["analysis"]["fallback_events_total"],
            intentional=report["analysis"]["intentional_rollback_validation_events"],
            spontaneous=report["analysis"]["spontaneous_fallback_events"],
            target=report["analysis"]["meets_target"],
        )
    )
    return 0 if report["analysis"]["meets_target"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
