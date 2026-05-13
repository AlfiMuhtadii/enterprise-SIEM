#!/usr/bin/env python3
"""Debug correlation soak fallback root cause from a soak report.

This script is intentionally read-only. It classifies fallback triggers so the
next soak run can distinguish real worker instability from test harness issues.
"""

from __future__ import annotations

import argparse
import json
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Analyze XDR correlation soak fallback failures")
    parser.add_argument("--input", default="reports/xdr_correlation_soak_6h.json")
    parser.add_argument("--output", default="reports/xdr_correlation_soak_fallback_debug.json")
    return parser.parse_args()


def load(path: Path) -> Dict[str, Any]:
    if not path.exists():
        raise SystemExit(f"ERROR: report not found: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def classify_error(text: str) -> str:
    lowered = text.lower()
    if "empty reply from server" in lowered or "remote end closed connection" in lowered:
        return "worker_closed_connection"
    if "winerror 10053" in lowered or "aborted by the software" in lowered:
        return "host_aborted_connection"
    if "timed out" in lowered or "timeout" in lowered:
        return "timeout"
    if "connection refused" in lowered or "could not connect" in lowered:
        return "connection_refused"
    if "cutover_status_failed" in lowered:
        return "cutover_status_command_failed"
    if "go_worker_unhealthy" in lowered:
        return "go_worker_unhealthy"
    if "redpanda" in lowered or "lag" in lowered:
        return "stream_or_lag"
    if "backpressure" in lowered:
        return "backpressure"
    return "unknown"


def status_text(status: Dict[str, Any]) -> str:
    return json.dumps(status, default=str, sort_keys=True)


def summarize(report: Dict[str, Any]) -> Dict[str, Any]:
    fallback_events: List[Dict[str, Any]] = report.get("fallback_events") or []
    failures: List[Dict[str, Any]] = report.get("failures") or []
    samples: List[Dict[str, Any]] = report.get("samples") or []
    categories = Counter()
    fallback_reasons = Counter()

    for event in fallback_events:
        status = event.get("status") or {}
        reason = str(status.get("fallback_reason") or "unknown")
        fallback_reasons[reason] += 1
        categories[classify_error(status_text(status))] += 1
    for failure in failures:
        categories[classify_error(str(failure.get("error") or ""))] += 1

    unhealthy_samples = [
        row for row in samples
        if row.get("worker_health") not in {None, "healthy", "ok"} or row.get("fallback_active")
    ]
    latency_values = [float(row.get("client_latency_ms") or 0) for row in samples if row.get("client_latency_ms") is not None]
    max_latency = max(latency_values) if latency_values else 0.0
    max_sample = next((row for row in samples if float(row.get("client_latency_ms") or 0) == max_latency), None)

    likely_root_causes = []
    if categories["worker_closed_connection"] or categories["host_aborted_connection"]:
        likely_root_causes.append("Go worker or Windows host closed HTTP connections during sustained burst traffic.")
    if categories["cutover_status_command_failed"]:
        likely_root_causes.append("Soak harness treated cutover-status command failure as fallback.")
    if categories["go_worker_unhealthy"]:
        likely_root_causes.append("Health endpoint returned unhealthy/offline during the run.")
    if not likely_root_causes:
        likely_root_causes.append("No dominant fallback category identified; inspect raw fallback_events and failures.")

    recommendations = [
        "Run a shorter 10-30 minute soak with the same batch size and capture Go worker logs around the first fallback timestamp.",
        "Separate healthcheck failures from correlate request failures in the next soak report.",
        "Add retry/debounce for cutover status healthcheck before counting fallback.",
        "If failures are only HTTP connection resets while p95/memory/goroutines are stable, tune server timeouts and client retry before declaring correlation logic unstable.",
        "Keep identity/cloud/SaaS in shadow or staged-active mode until fallback_count is 0 on a 6h run.",
    ]

    return {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "source_report_status": report.get("validation_status"),
        "scope": report.get("scope"),
        "duration_minutes_requested": report.get("duration_minutes_requested"),
        "metrics": report.get("metrics", {}),
        "checks": report.get("checks", {}),
        "fallback_event_sample_count_in_report": len(fallback_events),
        "failure_sample_count_in_report": len(failures),
        "fallback_reasons": dict(fallback_reasons),
        "failure_categories": dict(categories),
        "unhealthy_sample_count": len(unhealthy_samples),
        "max_latency_sample": max_sample,
        "likely_root_causes": likely_root_causes,
        "decision": "keep_shadow_or_staged_active",
        "do_not_set_default_go": True,
        "recommendations": recommendations,
    }


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    report = load(root / args.input)
    summary = summarize(report)
    output = root / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(summary, indent=2, default=str), encoding="utf-8")
    print(f"output={output}")
    print(
        "status={status} fallback_count={fallback} failure_count={failures} decision={decision}".format(
            status=summary["source_report_status"],
            fallback=summary["metrics"].get("fallback_count"),
            failures=summary["metrics"].get("failure_count"),
            decision=summary["decision"],
        )
    )
    print("categories=" + json.dumps(summary["failure_categories"], sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
