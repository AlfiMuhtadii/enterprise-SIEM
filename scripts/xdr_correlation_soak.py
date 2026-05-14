#!/usr/bin/env python3
"""Sustained identity/cloud/SaaS correlation soak test for the Go worker."""

from __future__ import annotations

import argparse
import json
import statistics
import subprocess
import sys
import time
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

from xdr_correlation_shadow_benchmark import event_in_scope, normalize, percentile
from xdr_infra_clients import load_jsonl


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run sustained Go correlation soak test for identity/cloud/SaaS")
    parser.add_argument("--dataset", default="storage/logs/xdr_realistic_large.jsonl")
    parser.add_argument("--duration-minutes", type=float, default=60)
    parser.add_argument("--batch-size", type=int, default=5000)
    parser.add_argument("--sleep-ms", type=int, default=250)
    parser.add_argument("--correlation-url", default="http://127.0.0.1:8093")
    parser.add_argument("--request-timeout-sec", type=int, default=180)
    parser.add_argument("--correlate-retries", type=int, default=2)
    parser.add_argument("--correlate-retry-sleep-ms", type=int, default=250)
    parser.add_argument("--status-retries", type=int, default=2)
    parser.add_argument("--status-retry-sleep-ms", type=int, default=250)
    parser.add_argument("--status-timeout-sec", type=int, default=90)
    parser.add_argument("--status-check-interval-sec", type=float, default=60)
    parser.add_argument("--output", default="reports/xdr_correlation_soak_report.json")
    parser.add_argument("--max-p95-ms", type=float, default=300)
    parser.add_argument("--max-fallback-count", type=int, default=0)
    parser.add_argument("--max-memory-growth-mb", type=float, default=128)
    parser.add_argument("--max-goroutine-growth", type=int, default=8)
    return parser.parse_args()


def get_json(url: str, timeout: int = 10) -> Dict[str, Any]:
    try:
        with urllib.request.urlopen(url, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception as exc:
        return {"error": str(exc)}


def classify_failure(text: str) -> str:
    lowered = text.lower()
    if "empty reply from server" in lowered or "remote end closed connection" in lowered:
        return "worker_closed_connection"
    if "winerror 10053" in lowered or "aborted by the software" in lowered:
        return "host_aborted_connection"
    if "timeout" in lowered or "timed out" in lowered:
        return "timeout"
    if "connection refused" in lowered or "could not connect" in lowered:
        return "connection_refused"
    if "cutover_status_failed" in lowered:
        return "cutover_status_command_failed"
    if "go_worker_unhealthy" in lowered:
        return "go_worker_unhealthy"
    return "unknown"


def post_correlate(url: str, events: List[Dict[str, Any]], timeout_sec: int, retries: int, retry_sleep_ms: int) -> Dict[str, Any]:
    body = json.dumps(events, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    last_error: Exception | None = None
    for attempt in range(max(0, retries) + 1):
        req = urllib.request.Request(
            f"{url.rstrip('/')}/v1/correlate?scope=identity-cloud",
            data=body,
            method="POST",
            headers={"Content-Type": "application/json", "Connection": "keep-alive"},
        )
        started = time.perf_counter()
        try:
            with urllib.request.urlopen(req, timeout=timeout_sec) as resp:
                payload = json.loads(resp.read().decode("utf-8"))
            elapsed = (time.perf_counter() - started) * 1000
            payload["client_latency_ms"] = round(elapsed, 3)
            payload["throughput_eps"] = round(len(events) / max(elapsed / 1000, 0.001), 2)
            payload["attempts"] = attempt + 1
            return payload
        except Exception as exc:
            last_error = exc
            if attempt < retries and retry_sleep_ms > 0:
                time.sleep(retry_sleep_ms / 1000)
    raise last_error or RuntimeError("correlate_failed")


def php_cutover_status(root: Path, retries: int, retry_sleep_ms: int, timeout_sec: int) -> Dict[str, Any]:
    last = None
    for attempt in range(max(0, retries) + 1):
        try:
            proc = subprocess.run(
                ["php", "artisan", "xdr:correlation-cutover-status", "--engine=go", "--scope=identity-cloud", "--audit=0", "--json"],
                cwd=str(root),
                text=True,
                capture_output=True,
                timeout=timeout_sec,
            )
        except subprocess.TimeoutExpired as exc:
            last = exc
            if attempt < retries and retry_sleep_ms > 0:
                time.sleep(retry_sleep_ms / 1000)
            continue
        last = proc
        if proc.returncode == 0:
            status = json.loads(proc.stdout)
            status["status_attempts"] = attempt + 1
            return status
        if attempt < retries and retry_sleep_ms > 0:
            time.sleep(retry_sleep_ms / 1000)
    if isinstance(last, subprocess.TimeoutExpired):
        return {
            "fallback_active": False,
            "fallback_reason": None,
            "status_command_failed": True,
            "status_failure_reason": "cutover_status_timeout",
            "status_attempts": max(0, retries) + 1,
            "error": f"cutover status timed out after {timeout_sec}s",
            "go_worker": {"status": "status_unknown"},
            "monitoring": {},
        }
    return {
        "fallback_active": False,
        "fallback_reason": None,
        "status_command_failed": True,
        "status_failure_reason": "cutover_status_failed",
        "status_attempts": max(0, retries) + 1,
        "stderr": last.stderr if last else "",
        "go_worker": {"status": "status_unknown"},
        "monitoring": {},
    }


def docker_stats(name: str) -> Dict[str, Any]:
    try:
        proc = subprocess.run(
            ["docker", "stats", "--no-stream", "--format", "{{json .}}", name],
            text=True,
            capture_output=True,
            timeout=60,
        )
    except subprocess.TimeoutExpired:
        return {"available": False, "error": "docker_stats_timeout", "container": name}
    except Exception as exc:
        return {"available": False, "error": "docker_stats_failed", "details": str(exc), "container": name}
    if proc.returncode == 0 and proc.stdout.strip():
        return json.loads(proc.stdout.strip().splitlines()[-1])
    return {"available": False, "error": "docker_stats_failed", "details": proc.stderr.strip(), "container": name}


def next_batch(events: List[Dict[str, Any]], start: int, size: int) -> tuple[List[Dict[str, Any]], int]:
    if not events:
        return [], 0
    batch = []
    idx = start
    while len(batch) < size:
        batch.append(events[idx % len(events)])
        idx += 1
    return batch, idx % len(events)


def trend(values: List[float]) -> Dict[str, Any]:
    if not values:
        return {"start": None, "end": None, "delta": None, "max": None}
    return {
        "start": round(values[0], 3),
        "end": round(values[-1], 3),
        "delta": round(values[-1] - values[0], 3),
        "max": round(max(values), 3),
    }


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dataset = (root / args.dataset).resolve()
    events = [normalize(row) for row in load_jsonl(dataset)]
    events = [event for event in events if event_in_scope(event, "identity-cloud")]
    if not events:
        raise SystemExit("ERROR: no identity/cloud/SaaS events found")

    deadline = time.monotonic() + (args.duration_minutes * 60)
    cursor = 0
    iterations = 0
    fallback_events: List[Dict[str, Any]] = []
    failures: List[Dict[str, Any]] = []
    status_failures: List[Dict[str, Any]] = []
    failure_categories: Dict[str, int] = {}
    latencies: List[float] = []
    worker_latencies: List[float] = []
    throughputs: List[float] = []
    heap_values: List[float] = []
    goroutine_values: List[int] = []
    samples: List[Dict[str, Any]] = []
    started_at = datetime.now(timezone.utc).isoformat()
    last_status: Dict[str, Any] = {
        "fallback_active": False,
        "go_worker": {"status": "not_checked_yet"},
        "monitoring": {},
    }
    last_status_check = 0.0

    while time.monotonic() < deadline:
        batch, cursor = next_batch(events, cursor, args.batch_size)
        sample_started = datetime.now(timezone.utc).isoformat()
        if (time.monotonic() - last_status_check) >= args.status_check_interval_sec:
            last_status = php_cutover_status(root, args.status_retries, args.status_retry_sleep_ms, args.status_timeout_sec)
            last_status_check = time.monotonic()
            if last_status.get("status_command_failed"):
                category = classify_failure(json.dumps(last_status, default=str))
                failure_categories[category] = failure_categories.get(category, 0) + 1
                status_failures.append({
                    "ts": sample_started,
                    "error": last_status.get("error") or last_status.get("stderr") or last_status.get("status_failure_reason"),
                    "category": category,
                    "status": last_status,
                })
        status = last_status
        if status.get("fallback_active"):
            fallback_events.append({"ts": sample_started, "status": status})
            category = classify_failure(json.dumps(status, default=str))
            failure_categories[category] = failure_categories.get(category, 0) + 1
        try:
            result = post_correlate(
                args.correlation_url,
                batch,
                args.request_timeout_sec,
                args.correlate_retries,
                args.correlate_retry_sleep_ms,
            )
            metrics = get_json(f"{args.correlation_url.rstrip('/')}/metrics")
            latencies.append(float(result.get("client_latency_ms") or 0))
            worker_latencies.append(float(result.get("latency_ms") or 0))
            throughputs.append(float(result.get("throughput_eps") or 0))
            if "heap_alloc_mb" in metrics:
                heap_values.append(float(metrics["heap_alloc_mb"]))
            if "goroutines" in metrics:
                goroutine_values.append(int(metrics["goroutines"]))
            samples.append({
                "ts": sample_started,
                "events": len(batch),
                "alerts": result.get("alert_count"),
                "client_latency_ms": result.get("client_latency_ms"),
                "worker_latency_ms": result.get("latency_ms"),
                "throughput_eps": result.get("throughput_eps"),
                "heap_alloc_mb": metrics.get("heap_alloc_mb"),
                "goroutines": metrics.get("goroutines"),
                "fallback_active": bool(status.get("fallback_active")),
                "worker_health": status.get("go_worker", {}).get("status"),
                "stream_lag": status.get("monitoring", {}).get("stream_lag"),
            })
        except Exception as exc:
            category = classify_failure(str(exc))
            failure_categories[category] = failure_categories.get(category, 0) + 1
            failures.append({"ts": sample_started, "error": str(exc), "category": category})
        iterations += 1
        if args.sleep_ms > 0:
            time.sleep(args.sleep_ms / 1000)

    p95 = percentile(latencies, 95)
    worker_p95 = percentile(worker_latencies, 95)
    memory_growth = (heap_values[-1] - heap_values[0]) if len(heap_values) >= 2 else 0.0
    goroutine_growth = (goroutine_values[-1] - goroutine_values[0]) if len(goroutine_values) >= 2 else 0
    checks = {
        "fallback_count_zero": len(fallback_events) <= args.max_fallback_count,
        "no_request_failures": len(failures) == 0,
        "no_status_failures": len(status_failures) == 0,
        "p95_under_target": p95 < args.max_p95_ms,
        "memory_growth_under_target": memory_growth <= args.max_memory_growth_mb,
        "goroutine_growth_under_target": goroutine_growth <= args.max_goroutine_growth,
    }
    report = {
        "validation_status": "PASS" if all(checks.values()) else "FAIL",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "started_at": started_at,
        "duration_minutes_requested": args.duration_minutes,
        "scope": "identity-cloud",
        "domains": ["identity", "cloud", "saas"],
        "dataset": str(dataset.relative_to(root)),
        "scoped_events_available": len(events),
        "batch_size": args.batch_size,
        "iterations": iterations,
        "total_events_processed": iterations * args.batch_size,
        "checks": checks,
        "targets": {
            "fallback_count": args.max_fallback_count,
            "p95_latency_ms": args.max_p95_ms,
            "memory_growth_mb": args.max_memory_growth_mb,
            "goroutine_growth": args.max_goroutine_growth,
        },
        "metrics": {
            "fallback_count": len(fallback_events),
            "failure_count": len(failures),
            "status_failure_count": len(status_failures),
            "failure_categories": failure_categories,
            "p50_latency_ms": statistics.median(latencies) if latencies else 0,
            "p95_latency_ms": p95,
            "worker_p95_latency_ms": worker_p95,
            "avg_throughput_eps": round(sum(throughputs) / max(len(throughputs), 1), 2),
            "memory_growth_mb": round(memory_growth, 3),
            "goroutine_growth": goroutine_growth,
            "heap_alloc_mb_trend": trend(heap_values),
            "goroutine_trend": trend([float(v) for v in goroutine_values]),
            "latency_ms_trend": trend(latencies),
        },
        "fallback_events": fallback_events[:20],
        "failures": failures[:20],
        "status_failures": status_failures[:20],
        "debug_report_hint": "Run scripts/xdr_soak_fallback_debug.py against this report for root-cause classification.",
        "samples": samples,
        "core_soak_completed": True,
        "note": "For production confidence, run this script for 6-24 hours. Short runs are smoke/stability checks only.",
    }
    output = root / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(f"output={output}")
    print(
        "status={status} iterations={iterations} events={events} fallback={fallback} failures={failures} status_failures={status_failures} p95_ms={p95} memory_growth_mb={mem} goroutine_growth={goroutines}".format(
            status=report["validation_status"],
            iterations=iterations,
            events=report["total_events_processed"],
            fallback=len(fallback_events),
            failures=len(failures),
            status_failures=len(status_failures),
            p95=p95,
            mem=round(memory_growth, 3),
            goroutines=goroutine_growth,
        )
    )
    stats = docker_stats("detector-xdr-correlation-worker")
    docker_available = stats.get("available", True)
    report["docker_stats_final"] = stats
    report["docker_stats_available"] = docker_available
    report["docker_stats_error"] = stats.get("error") if not docker_available else None
    output.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    return 0 if report["validation_status"] == "PASS" else 2


if __name__ == "__main__":
    raise SystemExit(main())
