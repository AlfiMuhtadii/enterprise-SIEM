#!/usr/bin/env python3
"""Benchmark Python/Laravel XDR correlation against Go shadow correlation."""

from __future__ import annotations

import argparse
import json
import subprocess
import time
import urllib.request
from collections import Counter
from pathlib import Path
from typing import Any, Dict, List, Tuple

from xdr_correlation_detector import detect_cloud_saas, detect_cross_domain, detect_identity
from xdr_infra_clients import load_jsonl


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Compare legacy Python correlation with Go shadow correlation")
    parser.add_argument("--dataset", default="storage/logs/xdr_realistic_large.jsonl")
    parser.add_argument("--output", default="reports/xdr_correlation_shadow_benchmark.json")
    parser.add_argument("--events", type=int, default=50000)
    parser.add_argument("--runs", type=int, default=5)
    parser.add_argument("--correlation-url", default="http://127.0.0.1:8093")
    parser.add_argument("--app-key", default="demo-alert-key")
    return parser.parse_args()


def normalize(row: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "id": row.get("id"),
        "ts": row.get("ts"),
        "event_id": row.get("event_id"),
        "telemetry_type": str(row.get("telemetry_type") or "").lower(),
        "event_type": str(row.get("event_type") or "").lower(),
        "user": row.get("user") or row.get("xdr_user"),
        "host": row.get("host") or row.get("host_id"),
        "source_ip": row.get("source_ip") or row.get("src_ip"),
        "destination_ip": row.get("destination_ip") or row.get("dst_ip"),
        "domain": row.get("domain") or row.get("query"),
        "file_hash": row.get("file_hash") or row.get("sha256"),
        "email_sender": row.get("email_sender") or row.get("sender"),
        "email_recipient": row.get("email_recipient") or row.get("recipient"),
        "cloud_account": row.get("cloud_account") or row.get("account_id"),
        "action": row.get("action") or row.get("operation"),
        "result": row.get("result") or row.get("outcome"),
        "risk_score": float(row.get("risk_score") or 0),
        "event_source": row.get("event_source") or row.get("source_adapter"),
    }


def python_correlation(events: List[Dict[str, Any]], app_key: str) -> Tuple[List[Dict[str, Any]], Dict[str, Any]]:
    started = time.perf_counter()
    rows = []
    rows.extend(detect_identity(events, app_key))
    rows.extend(detect_cloud_saas(events, app_key))
    rows.extend(detect_cross_domain(events, app_key))
    latency_ms = (time.perf_counter() - started) * 1000
    alerts = []
    for row in rows:
        evidence = json.loads(row[15]) if isinstance(row[15], str) else {}
        alerts.append({
            "alert_id": row[0],
            "alert_type": row[2],
            "actor": row[8],
            "severity": row[5],
            "score": float(row[12] or 0),
            "evidence_ids": sorted([item.get("event_id") for item in evidence.get("evidence_chain", []) if item.get("event_id")]),
        })
    return alerts, {
        "latency_ms": round(latency_ms, 3),
        "throughput_eps": round(len(events) / max(latency_ms / 1000, 0.001), 2),
        "alert_count": len(alerts),
    }


def go_correlation(events: List[Dict[str, Any]], url: str) -> Tuple[List[Dict[str, Any]], Dict[str, Any]]:
    body = json.dumps(events, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(f"{url.rstrip('/')}/v1/correlate", data=body, method="POST", headers={"Content-Type": "application/json"})
    started = time.perf_counter()
    with urllib.request.urlopen(req, timeout=120) as resp:
        payload = json.loads(resp.read().decode("utf-8"))
    latency_ms = (time.perf_counter() - started) * 1000
    alerts = payload.get("alerts", [])
    return alerts, {
        "latency_ms": round(latency_ms, 3),
        "worker_latency_ms": payload.get("latency_ms"),
        "throughput_eps": round(len(events) / max(latency_ms / 1000, 0.001), 2),
        "alert_count": len(alerts),
    }


def get_json(url: str) -> Dict[str, Any]:
    try:
        with urllib.request.urlopen(url, timeout=10) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception as exc:
        return {"error": str(exc)}


def percentile(values: List[float], pct: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    idx = min(len(ordered) - 1, max(0, round((pct / 100) * (len(ordered) - 1))))
    return round(ordered[int(idx)], 3)


def type_counter(alerts: List[Dict[str, Any]]) -> Counter:
    return Counter(str(alert.get("alert_type")) for alert in alerts)


def duplicate_rate(alerts: List[Dict[str, Any]]) -> float:
    keys = [f"{a.get('alert_type')}|{a.get('actor')}|{','.join(a.get('evidence_ids') or [])}" for a in alerts]
    if not keys:
        return 0.0
    return round(1 - (len(set(keys)) / len(keys)), 4)


def evidence_match_rate(left: List[Dict[str, Any]], right: List[Dict[str, Any]]) -> float:
    left_map = {(a.get("alert_type"), a.get("actor")): set(a.get("evidence_ids") or []) for a in left}
    scored = 0
    total = 0
    for alert in right:
        key = (alert.get("alert_type"), alert.get("actor"))
        if key not in left_map:
            continue
        total += 1
        if left_map[key].intersection(set(alert.get("evidence_ids") or [])):
            scored += 1
    return round(scored / max(total, 1), 4)


def docker_stats(name: str) -> Dict[str, Any]:
    proc = subprocess.run(["docker", "stats", "--no-stream", "--format", "{{json .}}", name], text=True, capture_output=True, timeout=20)
    if proc.returncode == 0 and proc.stdout.strip():
        return json.loads(proc.stdout.strip().splitlines()[-1])
    return {"error": proc.stderr.strip()}


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dataset = (root / args.dataset).resolve()
    events = [normalize(row) for row in load_jsonl(dataset)[: args.events]]
    python_runs = []
    go_runs = []
    python_alerts: List[Dict[str, Any]] = []
    go_alerts: List[Dict[str, Any]] = []
    for idx in range(max(1, args.runs)):
        python_alerts, python_metrics = python_correlation(events, args.app_key)
        go_alerts, go_metrics = go_correlation(events, args.correlation_url)
        python_runs.append(python_metrics)
        go_runs.append(go_metrics)
    py_types = type_counter(python_alerts)
    go_types = type_counter(go_alerts)
    matched_types = sum(min(py_types[k], go_types[k]) for k in set(py_types) | set(go_types))
    report = {
        "validation_status": "SHADOW_PASS" if go_metrics["alert_count"] > 0 else "SHADOW_WARN",
        "mode": "shadow",
        "source_of_truth": "python_laravel",
        "events": len(events),
        "runs": max(1, args.runs),
        "python_laravel_correlation": {
            **python_runs[-1],
            "p95_latency_ms": percentile([row["latency_ms"] for row in python_runs], 95),
            "avg_throughput_eps": round(sum(row["throughput_eps"] for row in python_runs) / len(python_runs), 2),
        },
        "go_shadow_correlation": {
            **go_runs[-1],
            "p95_latency_ms": percentile([row["latency_ms"] for row in go_runs], 95),
            "worker_p95_latency_ms": percentile([float(row.get("worker_latency_ms") or 0) for row in go_runs], 95),
            "avg_throughput_eps": round(sum(row["throughput_eps"] for row in go_runs) / len(go_runs), 2),
        },
        "comparison": {
            "alert_count_delta": go_metrics["alert_count"] - python_metrics["alert_count"],
            "alert_type_match_rate": round(matched_types / max(sum(py_types.values()), 1), 4),
            "evidence_match_rate": evidence_match_rate(python_alerts, go_alerts),
            "python_duplicate_rate": duplicate_rate(python_alerts),
            "go_duplicate_rate": duplicate_rate(go_alerts),
            "false_positive_estimate_delta": "requires labeled analyst review; shadow comparison only",
            "incident_creation_latency_ms": "not measured; Go shadow mode does not create incidents",
        },
        "alert_types": {
            "python": dict(py_types),
            "go": dict(go_types),
        },
        "cutover_plan": [
            "Keep Python/Laravel as source of truth.",
            "Run Go shadow for identity/cloud only until alert type/evidence match is stable.",
            "Expand shadow to endpoint/DNS/proxy after identity/cloud stability.",
            "Move incident creation last after duplicate and evidence parity are acceptable.",
        ],
        "pressure": {
            "go_correlation_worker": docker_stats("detector-xdr-correlation-worker"),
            "go_correlation_metrics_endpoint": get_json(f"{args.correlation_url.rstrip('/')}/metrics"),
        },
    }
    output = (root / args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(f"output={output}")
    print(f"events={len(events)} python_alerts={python_metrics['alert_count']} go_alerts={go_metrics['alert_count']} go_eps={go_metrics['throughput_eps']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
