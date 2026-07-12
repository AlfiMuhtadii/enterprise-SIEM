#!/usr/bin/env python3
"""
Lightweight load/performance test runner for SOC operational endpoints.

The script intentionally uses the Python standard library so it can run in CI,
staging, or a production smoke-test host without extra dependencies.
"""

from __future__ import annotations

import argparse
import json
import math
import statistics
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Tuple


DEFAULT_PUBLIC_PATHS = ["/health/live", "/health/ready"]
DEFAULT_AUTH_PATHS = ["/soc", "/soc/api/stats", "/soc/api/metrics", "/soc/api/incidents", "/soc/api/alerts"]


def percentile(values: List[float], pct: float) -> float:
    if not values:
        return 0.0
    values = sorted(values)
    index = min(len(values) - 1, max(0, int(round((pct / 100) * (len(values) - 1)))))
    return values[index]


def request_once(base_url: str, path: str, cookie: str, timeout: float) -> Dict[str, object]:
    url = base_url.rstrip("/") + path
    headers = {"User-Agent": "detector-soc-load-test/1.0"}
    if cookie:
        headers["Cookie"] = cookie
    start = time.perf_counter()
    status = 0
    size = 0
    error = ""
    try:
        req = urllib.request.Request(url, headers=headers)
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read()
            status = int(resp.status)
            size = len(body)
    except urllib.error.HTTPError as exc:
        status = int(exc.code)
        error = str(exc)
    except Exception as exc:
        error = str(exc)
    latency_ms = (time.perf_counter() - start) * 1000
    return {"path": path, "status": status, "latency_ms": latency_ms, "bytes": size, "error": error}


def run_http_load(base_url: str, paths: List[str], cookie: str, duration: int, concurrency: int, timeout: float) -> List[Dict[str, object]]:
    deadline = time.time() + duration
    results: List[Dict[str, object]] = []
    lock = threading.Lock()

    def worker(worker_id: int) -> None:
        index = worker_id
        while time.time() < deadline:
            path = paths[index % len(paths)]
            item = request_once(base_url, path, cookie, timeout)
            with lock:
                results.append(item)
            index += concurrency

    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        futures = [pool.submit(worker, i) for i in range(concurrency)]
        for fut in as_completed(futures):
            fut.result()
    return results


def summarize_http(results: List[Dict[str, object]], duration: int) -> Dict[str, object]:
    latencies = [float(r["latency_ms"]) for r in results]
    by_path: Dict[str, List[float]] = {}
    status_counts: Dict[str, int] = {}
    errors = 0
    for row in results:
        by_path.setdefault(str(row["path"]), []).append(float(row["latency_ms"]))
        status_counts[str(row["status"])] = status_counts.get(str(row["status"]), 0) + 1
        if row.get("error"):
            errors += 1
    return {
        "requests": len(results),
        "throughput_rps": round(len(results) / max(duration, 1), 2),
        "errors": errors,
        "status_counts": status_counts,
        "latency_ms": {
            "avg": round(statistics.mean(latencies), 2) if latencies else 0,
            "p50": round(percentile(latencies, 50), 2),
            "p95": round(percentile(latencies, 95), 2),
            "p99": round(percentile(latencies, 99), 2),
            "max": round(max(latencies), 2) if latencies else 0,
        },
        "by_path": {
            path: {
                "requests": len(vals),
                "p95_ms": round(percentile(vals, 95), 2),
                "avg_ms": round(statistics.mean(vals), 2),
            }
            for path, vals in sorted(by_path.items())
        },
    }


def validate_telemetry_jsonl(path: Path) -> Dict[str, object]:
    if not path:
        return {"enabled": False}
    start = time.perf_counter()
    total = invalid = 0
    try:
        from telemetry_event_contract import validate_event
    except Exception:
        validate_event = None
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            total += 1
            try:
                event = json.loads(line)
                if validate_event:
                    ok, _errors = validate_event(event)
                    if not ok:
                        invalid += 1
            except Exception:
                invalid += 1
    elapsed = max(time.perf_counter() - start, 0.001)
    return {
        "enabled": True,
        "file": str(path),
        "events": total,
        "invalid": invalid,
        "validation_events_per_sec": round(total / elapsed, 2),
        "elapsed_sec": round(elapsed, 3),
    }


def split_jsonl(path: Path, parts: int) -> List[Path]:
    """Splits a JSONL file into `parts` roughly-equal-line temp shards for
    concurrent ingestion -- each concurrent worker gets its own file (and
    its own offset file, assigned by the caller), so parallel
    ingest_telemetry_events.py subprocesses never race on shared
    offset-tracking state. parts<=1 (the pre-existing default) returns the
    original path unchanged -- no split, no temp files, identical behavior
    to before concurrency support existed."""
    if parts <= 1:
        return [path]
    lines = path.read_text(encoding="utf-8").splitlines()
    if len(lines) < parts:
        return [path]
    chunk_size = math.ceil(len(lines) / parts)
    tmp_dir = Path(tempfile.mkdtemp(prefix="load_test_soc_split_"))
    shard_paths = []
    for i in range(parts):
        chunk = lines[i * chunk_size:(i + 1) * chunk_size]
        if not chunk:
            continue
        shard_path = tmp_dir / f"shard_{i}.jsonl"
        shard_path.write_text("\n".join(chunk) + "\n", encoding="utf-8")
        shard_paths.append(shard_path)
    return shard_paths


def ingest_worker(shard_path: Path, dsn: str, batch_size: int, target: str, clickhouse_opts: Dict[str, str]) -> Dict[str, object]:
    start = time.perf_counter()
    cmd = [
        "python",
        "scripts/ingest_telemetry_events.py",
        "--file",
        str(shard_path),
        "--from-start",
        "--batch-size",
        str(batch_size),
        "--target",
        target,
        "--offset-file",
        str(shard_path.with_suffix(".offset")),
    ]
    if dsn:
        cmd.extend(["--dsn", dsn])
    if target == "clickhouse":
        cmd.extend([
            "--clickhouse-url", clickhouse_opts.get("url", "http://127.0.0.1:8123"),
            "--clickhouse-db", clickhouse_opts.get("db", "detector_analytics"),
            "--clickhouse-user", clickhouse_opts.get("user", "detector"),
            "--clickhouse-password", clickhouse_opts.get("password", "detector"),
        ])
    proc = subprocess.run(cmd, text=True, capture_output=True)
    elapsed = max(time.perf_counter() - start, 0.001)
    processed = 0
    for line in proc.stdout.splitlines():
        if line.startswith("Processed:"):
            try:
                processed = int(line.split(":", 1)[1].strip())
            except ValueError:
                processed = 0
    return {
        "shard": str(shard_path),
        "exit_code": proc.returncode,
        "events": processed,
        "elapsed_sec": round(elapsed, 3),
        "stderr_tail": proc.stderr[-500:],
    }


def ingest_telemetry_load(
    path: Path,
    dsn: str,
    batch_size: int,
    target: str = "postgres",
    concurrency: int = 1,
    clickhouse_opts: Optional[Dict[str, str]] = None,
) -> Dict[str, object]:
    """ARCH-DB-SPLIT soak-test entry point: real concurrent write-throughput
    generator for telemetry ingestion, targeting either Postgres (existing
    default, unchanged single-writer behavior at concurrency=1) or the new
    ClickHouse write path (--ingest-target clickhouse), with N parallel
    ingest_telemetry_events.py subprocesses via ThreadPoolExecutor -- the
    same concurrency pattern this file already uses for
    run_workflow_load() -- rather than the single sequential subprocess the
    original ingest_telemetry_jsonl() ran. This is what actually lets an
    operator measure sustained concurrent write throughput against either
    backend, which is the entire point of preparing this system for a soak
    test: a single-writer number doesn't demonstrate anything about
    contention."""
    clickhouse_opts = clickhouse_opts or {}
    start = time.perf_counter()
    shard_paths = split_jsonl(path, concurrency)
    results: List[Dict[str, object]] = []
    with ThreadPoolExecutor(max_workers=len(shard_paths)) as pool:
        futures = [pool.submit(ingest_worker, shard, dsn, batch_size, target, clickhouse_opts) for shard in shard_paths]
        for fut in as_completed(futures):
            results.append(fut.result())
    elapsed = max(time.perf_counter() - start, 0.001)
    total_processed = sum(r["events"] for r in results)
    failures = [r for r in results if r["exit_code"] != 0]
    return {
        "enabled": True,
        "target": target,
        "concurrency": len(shard_paths),
        "exit_code": 1 if failures else 0,
        "events": total_processed,
        "elapsed_sec": round(elapsed, 3),
        "ingest_events_per_sec": round(total_processed / elapsed, 2),
        "worker_failures": len(failures),
        "workers": results,
    }


def run_workflow_load(incident_id: str, dsn: str, concurrency: int, iterations: int) -> Dict[str, object]:
    if not incident_id:
        return {"enabled": False}
    statuses = ["triaged", "investigating", "resolved", "open"]
    latencies: List[float] = []
    errors = 0
    lock = threading.Lock()

    def update_once(index: int) -> None:
        nonlocal errors
        status = statuses[index % len(statuses)]
        cmd = [
            "python",
            "scripts/soc_workflow.py",
            "status",
            "--incident-id",
            incident_id,
            "--status",
            status,
        ]
        if dsn:
            cmd.extend(["--dsn", dsn])
        start = time.perf_counter()
        proc = subprocess.run(cmd, text=True, capture_output=True)
        elapsed_ms = (time.perf_counter() - start) * 1000
        with lock:
            latencies.append(elapsed_ms)
            if proc.returncode != 0:
                errors += 1

    start_all = time.perf_counter()
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        futures = [pool.submit(update_once, i) for i in range(iterations)]
        for fut in as_completed(futures):
            fut.result()
    elapsed = max(time.perf_counter() - start_all, 0.001)
    return {
        "enabled": True,
        "incident_id": incident_id,
        "iterations": iterations,
        "concurrency": concurrency,
        "errors": errors,
        "workflow_updates_per_sec": round(iterations / elapsed, 2),
        "latency_ms": {
            "avg": round(statistics.mean(latencies), 2) if latencies else 0,
            "p95": round(percentile(latencies, 95), 2),
            "max": round(max(latencies), 2) if latencies else 0,
        },
    }


def fetch_metrics(base_url: str, cookie: str, timeout: float) -> Dict[str, object]:
    if not cookie:
        return {"available": False, "reason": "cookie not provided"}
    result = request_once(base_url, "/soc/api/metrics", cookie, timeout)
    if result["status"] != 200:
        return {"available": False, "status": result["status"], "error": result["error"]}
    req = urllib.request.Request(base_url.rstrip("/") + "/soc/api/metrics", headers={"Cookie": cookie})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return {"available": True, "data": json.loads(resp.read().decode("utf-8"))}


def main() -> int:
    parser = argparse.ArgumentParser(description="Run SOC platform load/performance test")
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--duration", type=int, default=30)
    parser.add_argument("--concurrency", type=int, default=8)
    parser.add_argument("--timeout", type=float, default=8)
    parser.add_argument("--cookie", default="", help="Authenticated Laravel session cookie for SOC endpoints")
    parser.add_argument("--include-auth", action="store_true", help="Include SOC dashboard/API endpoints; requires --cookie for 200 responses")
    parser.add_argument("--telemetry-jsonl", default="")
    parser.add_argument("--ingest-telemetry", action="store_true", help="Actually ingest --telemetry-jsonl into the configured database")
    parser.add_argument("--ingest-batch-size", type=int, default=500)
    # ARCH-DB-SPLIT: --ingest-target/--ingest-concurrency turn this from a
    # single-writer wall-clock measurement into a genuine concurrent
    # write-throughput soak against either backend. Defaults (postgres,
    # concurrency=1) reproduce the exact prior behavior unchanged.
    parser.add_argument("--ingest-target", choices=["postgres", "clickhouse"], default="postgres")
    parser.add_argument("--ingest-concurrency", type=int, default=1, help="Number of concurrent ingest_telemetry_events.py workers, each on its own file shard")
    parser.add_argument("--clickhouse-url", default="http://127.0.0.1:8123")
    parser.add_argument("--clickhouse-db", default="detector_analytics")
    parser.add_argument("--clickhouse-user", default="detector")
    parser.add_argument("--clickhouse-password", default="detector")
    parser.add_argument("--dsn", default="", help="Database DSN for telemetry ingest or workflow load modes")
    parser.add_argument("--workflow-incident-id", default="", help="Existing incident_id for concurrent workflow load test")
    parser.add_argument("--workflow-iterations", type=int, default=20)
    parser.add_argument("--output", default="reports/performance/soc_load_report.json")
    args = parser.parse_args()

    paths = list(DEFAULT_PUBLIC_PATHS)
    if args.include_auth:
        paths.extend(DEFAULT_AUTH_PATHS)

    before_metrics = fetch_metrics(args.base_url, args.cookie, args.timeout)
    results = run_http_load(args.base_url, paths, args.cookie, args.duration, args.concurrency, args.timeout)
    after_metrics = fetch_metrics(args.base_url, args.cookie, args.timeout)
    telemetry = validate_telemetry_jsonl(Path(args.telemetry_jsonl)) if args.telemetry_jsonl else {"enabled": False}
    telemetry_ingest = (
        ingest_telemetry_load(
            Path(args.telemetry_jsonl),
            args.dsn,
            args.ingest_batch_size,
            target=args.ingest_target,
            concurrency=args.ingest_concurrency,
            clickhouse_opts={
                "url": args.clickhouse_url,
                "db": args.clickhouse_db,
                "user": args.clickhouse_user,
                "password": args.clickhouse_password,
            },
        )
        if args.telemetry_jsonl and args.ingest_telemetry
        else {"enabled": False}
    )
    workflow = run_workflow_load(args.workflow_incident_id, args.dsn, args.concurrency, args.workflow_iterations)

    report = {
        "base_url": args.base_url,
        "duration_sec": args.duration,
        "concurrency": args.concurrency,
        "paths": paths,
        "http": summarize_http(results, args.duration),
        "telemetry_ingestion_validation": telemetry,
        "telemetry_ingestion_db": telemetry_ingest,
        "concurrent_incident_workflows": workflow,
        "ops_metrics_before": before_metrics,
        "ops_metrics_after": after_metrics,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(json.dumps(report["http"], indent=2))
    print(f"report={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
