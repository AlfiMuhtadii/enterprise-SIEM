#!/usr/bin/env python3
"""Run staged identity/cloud/SaaS Go correlation cutover validation."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate staged Go XDR correlation cutover for identity/cloud/SaaS only")
    parser.add_argument("--dataset", default="storage/logs/xdr_realistic_large.jsonl")
    parser.add_argument("--events", type=int, default=50000)
    parser.add_argument("--runs", type=int, default=5)
    parser.add_argument("--output-dir", default="reports")
    parser.add_argument("--correlation-url", default="http://127.0.0.1:8093")
    parser.add_argument("--app-key", default="demo-alert-key")
    parser.add_argument("--scope", default="identity-cloud", choices=["identity-cloud"])
    return parser.parse_args()


def run(cmd: List[str], cwd: Path, env: Dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    proc = subprocess.run(cmd, cwd=str(cwd), text=True, capture_output=True, env=env)
    if proc.returncode != 0:
        raise RuntimeError(
            "Command failed: "
            + " ".join(cmd)
            + "\nSTDOUT:\n"
            + proc.stdout
            + "\nSTDERR:\n"
            + proc.stderr
        )
    return proc


def run_benchmark(root: Path, args: argparse.Namespace, engine: str, output: Path, url: str | None = None) -> Dict[str, Any]:
    cmd = [
        sys.executable,
        "scripts/xdr_correlation_shadow_benchmark.py",
        "--dataset",
        args.dataset,
        "--events",
        str(args.events),
        "--runs",
        str(args.runs),
        "--scope",
        args.scope,
        "--engine",
        engine,
        "--correlation-url",
        url or args.correlation_url,
        "--app-key",
        args.app_key,
        "--output",
        str(output.relative_to(root)),
    ]
    run(cmd, root)
    return load_json(output)


def load_json(path: Path) -> Dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def php_status(root: Path, engine: str, audit: int = 0, env: Dict[str, str] | None = None) -> Dict[str, Any]:
    proc = run(
        [
            "php",
            "artisan",
            "xdr:correlation-cutover-status",
            f"--engine={engine}",
            "--scope=identity-cloud",
            f"--audit={audit}",
            "--json",
        ],
        root,
        env=env,
    )
    return json.loads(proc.stdout)


def gate_pass(report: Dict[str, Any]) -> bool:
    return bool(report.get("cutover_gate", {}).get("passed"))


def monitoring(report: Dict[str, Any]) -> Dict[str, Any]:
    go = report.get("go_correlation", {})
    comparison = report.get("comparison", {})
    diff = report.get("diff", {})
    return {
        "alert_count_delta": comparison.get("alert_count_delta"),
        "alert_type_match": comparison.get("alert_type_match_rate"),
        "evidence_match": comparison.get("evidence_match_rate"),
        "severity_mismatch": diff.get("mismatched_severity_count"),
        "entity_mismatch": diff.get("mismatched_entity_key_count"),
        "duplicate_rate": comparison.get("go_duplicate_rate"),
        "p95_latency_ms": go.get("p95_latency_ms"),
        "throughput_eps": go.get("avg_throughput_eps"),
        "worker_p95_latency_ms": go.get("worker_p95_latency_ms"),
        "fallback_active": report.get("fallback_active"),
        "fallback_reason": report.get("fallback_reason"),
        "stream_lag": report.get("cutover_monitoring", {}).get("stream_lag"),
    }


def write_json(path: Path, payload: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    output_dir = (root / args.output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)
    started_at = datetime.now(timezone.utc).isoformat()

    shadow_path = output_dir / "xdr_cutover_shadow_identity_cloud_saas.json"
    active_path = output_dir / "xdr_cutover_active_identity_cloud_saas.json"
    fallback_path = output_dir / "xdr_cutover_auto_fallback_identity_cloud_saas.json"

    shadow = run_benchmark(root, args, "shadow", shadow_path)
    active = run_benchmark(root, args, "go", active_path)
    fallback = run_benchmark(root, args, "go", fallback_path, url="http://127.0.0.1:1")

    manual_rollback = php_status(root, "legacy", audit=0)
    active_status = php_status(root, "go", audit=0)
    bad_env = os.environ.copy()
    bad_env["XDR_CORRELATION_WORKER_URL"] = "http://127.0.0.1:1"
    automatic_rollback = php_status(root, "go", audit=1, env=bad_env)
    recovery = php_status(root, "shadow", audit=0)

    fallback_count = int(bool(fallback.get("fallback_active"))) + int(bool(automatic_rollback.get("fallback_active")))
    reports = {
        "staged_cutover_report": {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "started_at": started_at,
            "scope": args.scope,
            "domains": ["identity", "cloud", "saas"],
            "replay_events_requested": args.events,
            "runs": args.runs,
            "shadow_report": str(shadow_path.relative_to(root)),
            "active_report": str(active_path.relative_to(root)),
            "active_source_of_truth": active.get("source_of_truth"),
            "legacy_shadow_comparison": active.get("comparison_engine") == "legacy_shadow",
            "go_cutover_gate_passed": gate_pass(active),
            "shadow_gate_passed": gate_pass(shadow),
            "fallback_activation_count": fallback_count,
            "metrics": monitoring(active),
            "wider_migration_guard": "endpoint/dns/proxy/firewall stay legacy until separate golden, large replay parity, and latency gates pass",
        },
        "parity_drift_report": {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "scope": args.scope,
            "shadow": monitoring(shadow),
            "active": monitoring(active),
            "active_alert_types": active.get("alert_types", {}),
            "active_diff": active.get("diff", {}),
            "degraded_parity": not gate_pass(active),
        },
        "rollback_validation_report": {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "manual_rollback": {
                "requested_engine": "legacy",
                "effective_engine": manual_rollback.get("effective_engine"),
                "passed": manual_rollback.get("effective_engine") == "legacy",
            },
            "automatic_rollback": {
                "fallback_report_status": fallback.get("validation_status"),
                "fallback_report_effective_engine": fallback.get("effective_engine"),
                "runtime_status": automatic_rollback,
                "passed": fallback.get("effective_engine") == "legacy" and automatic_rollback.get("fallback_active") is True,
            },
            "shadow_reactivation": {
                "configured_engine": recovery.get("configured_engine"),
                "effective_engine": recovery.get("effective_engine"),
                "comparison_engine": recovery.get("comparison_engine"),
                "passed": recovery.get("configured_engine") == "shadow" and recovery.get("comparison_engine") == "go_shadow",
            },
            "audit_expected": "xdr.correlation.rollback_auto is written by automatic rollback runtime validation",
        },
        "replay_stability_report": {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "scope": args.scope,
            "requested_events": args.events,
            "scoped_events": active.get("events"),
            "runs": args.runs,
            "shadow_status": shadow.get("validation_status"),
            "active_status": active.get("validation_status"),
            "fallback_status": fallback.get("validation_status"),
            "stable": gate_pass(shadow) and gate_pass(active) and active.get("events", 0) > 0,
            "replay_degradation": not gate_pass(active),
            "stream_lag": active.get("cutover_monitoring", {}).get("stream_lag"),
        },
        "correlation_latency_trend_report": {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "scope": args.scope,
            "shadow_p95_latency_ms": shadow.get("go_correlation", {}).get("p95_latency_ms"),
            "active_p95_latency_ms": active.get("go_correlation", {}).get("p95_latency_ms"),
            "active_worker_p95_latency_ms": active.get("go_correlation", {}).get("worker_p95_latency_ms"),
            "active_throughput_eps": active.get("go_correlation", {}).get("avg_throughput_eps"),
            "shadow_runs": shadow.get("go_correlation", {}).get("runs", []),
            "active_runs": active.get("go_correlation", {}).get("runs", []),
            "legacy_shadow_runs": active.get("python_laravel_correlation", {}).get("runs", []),
            "worker_health": active.get("cutover_monitoring", {}).get("go_worker_health"),
            "latency_gate_passed": active.get("cutover_gate", {}).get("gates", {}).get("go_p95_latency_lt_300ms"),
        },
    }

    report_paths = {
        "staged_cutover_report": output_dir / "xdr_staged_cutover_report.json",
        "parity_drift_report": output_dir / "xdr_parity_drift_report.json",
        "rollback_validation_report": output_dir / "xdr_rollback_validation_report.json",
        "replay_stability_report": output_dir / "xdr_replay_stability_report.json",
        "correlation_latency_trend_report": output_dir / "xdr_correlation_latency_trend_report.json",
    }
    for key, path in report_paths.items():
        write_json(path, reports[key])

    passed = (
        reports["staged_cutover_report"]["go_cutover_gate_passed"]
        and reports["staged_cutover_report"]["shadow_gate_passed"]
        and reports["rollback_validation_report"]["manual_rollback"]["passed"]
        and reports["rollback_validation_report"]["automatic_rollback"]["passed"]
        and reports["rollback_validation_report"]["shadow_reactivation"]["passed"]
        and reports["replay_stability_report"]["stable"]
    )
    summary = {
        "validation_status": "PASS" if passed else "FAIL",
        "scope": args.scope,
        "domains": ["identity", "cloud", "saas"],
        "reports": {key: str(path.relative_to(root)) for key, path in report_paths.items()},
        "raw_reports": {
            "shadow": str(shadow_path.relative_to(root)),
            "active": str(active_path.relative_to(root)),
            "fallback": str(fallback_path.relative_to(root)),
        },
        "key_metrics": reports["staged_cutover_report"]["metrics"],
        "fallback_activation_count": fallback_count,
    }
    summary_path = output_dir / "xdr_staged_cutover_summary.json"
    write_json(summary_path, summary)
    print(f"output={summary_path}")
    print(
        "status={status} scoped_events={events} active_p95_ms={latency} throughput_eps={eps} fallback_count={fallback}".format(
            status=summary["validation_status"],
            events=active.get("events"),
            latency=reports["staged_cutover_report"]["metrics"]["p95_latency_ms"],
            eps=reports["staged_cutover_report"]["metrics"]["throughput_eps"],
            fallback=fallback_count,
        )
    )
    return 0 if passed else 2


if __name__ == "__main__":
    raise SystemExit(main())
