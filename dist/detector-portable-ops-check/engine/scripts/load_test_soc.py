#!/usr/bin/env python3
"""
Lightweight load/performance test runner for SOC operational endpoints.

The script intentionally uses the Python standard library so it can run in CI,
staging, or a production smoke-test host without extra dependencies.
"""

from __future__ import annotations

import argparse
import json
import statistics
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Dict, Iterable, List, Tuple


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
    parser.add_argument("--output", default="reports/performance/soc_load_report.json")
    args = parser.parse_args()

    paths = list(DEFAULT_PUBLIC_PATHS)
    if args.include_auth:
        paths.extend(DEFAULT_AUTH_PATHS)

    before_metrics = fetch_metrics(args.base_url, args.cookie, args.timeout)
    results = run_http_load(args.base_url, paths, args.cookie, args.duration, args.concurrency, args.timeout)
    after_metrics = fetch_metrics(args.base_url, args.cookie, args.timeout)
    telemetry = validate_telemetry_jsonl(Path(args.telemetry_jsonl)) if args.telemetry_jsonl else {"enabled": False}

    report = {
        "base_url": args.base_url,
        "duration_sec": args.duration,
        "concurrency": args.concurrency,
        "paths": paths,
        "http": summarize_http(results, args.duration),
        "telemetry_ingestion_validation": telemetry,
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

