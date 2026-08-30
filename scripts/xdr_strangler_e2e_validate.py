#!/usr/bin/env python3
"""Validate extracted Go/FastAPI XDR services against the existing Python path."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Dict, Iterable, List

from xdr_infra_clients import RedpandaClient, load_jsonl


def parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run strangler end-to-end service validation")
    parser.add_argument("--dataset", default="storage/logs/xdr_realistic_large.jsonl")
    parser.add_argument("--output", default="reports/xdr_strangler_e2e_validation.json")
    parser.add_argument("--events", type=int, default=50000)
    parser.add_argument("--baseline-events", type=int, default=5000)
    parser.add_argument("--batch-size", type=int, default=1000)
    parser.add_argument("--gateway-url", default="http://127.0.0.1:8091")
    parser.add_argument("--normalizer-url", default="http://127.0.0.1:8092")
    parser.add_argument("--ai-url", default="http://127.0.0.1:8094")
    parser.add_argument("--redpanda-rest", default="http://127.0.0.1:8082")
    parser.add_argument("--secret", default="dev-secret-change-me")
    parser.add_argument("--mtls-enabled", action="store_true", dest="mtls_enabled")
    parser.add_argument("--mtls-ca", default=None, dest="mtls_ca")
    parser.add_argument("--mtls-client-cert", default=None, dest="mtls_client_cert")
    parser.add_argument("--mtls-client-key", default=None, dest="mtls_client_key")
    return parser.parse_args(argv)


def build_mtls_context(args: argparse.Namespace) -> ssl.SSLContext | None:
    """Build one client context for the three first-party service URLs."""
    if not getattr(args, "mtls_enabled", False):
        return None

    service_urls = {
        "--gateway-url": args.gateway_url,
        "--normalizer-url": args.normalizer_url,
        "--ai-url": args.ai_url,
    }
    insecure = [
        option for option, url in service_urls.items()
        if urllib.parse.urlsplit(url).scheme.lower() != "https"
    ]
    if insecure:
        raise ValueError(
            "--mtls-enabled requires https:// for " + ", ".join(insecure)
        )

    material = {
        "--mtls-ca": getattr(args, "mtls_ca", None),
        "--mtls-client-cert": getattr(args, "mtls_client_cert", None),
        "--mtls-client-key": getattr(args, "mtls_client_key", None),
    }
    missing = [option for option, value in material.items() if not value]
    if missing:
        raise ValueError(
            "--mtls-enabled requires complete TLS material; missing "
            + ", ".join(missing)
        )

    context = ssl.create_default_context(cafile=material["--mtls-ca"])
    context.load_cert_chain(
        certfile=material["--mtls-client-cert"],
        keyfile=material["--mtls-client-key"],
    )
    return context


def post_json(
    url: str,
    payload: Any,
    headers: Dict[str, str] | None = None,
    timeout: int = 30,
    ssl_context: ssl.SSLContext | None = None,
) -> Dict[str, Any]:
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(url, data=body, method="POST", headers={"Content-Type": "application/json", **(headers or {})})
    started = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ssl_context) as resp:
            text = resp.read().decode("utf-8", errors="replace")
            return {"ok": 200 <= resp.status < 300, "status": resp.status, "latency_ms": (time.perf_counter() - started) * 1000, "body": safe_json(text)}
    except urllib.error.HTTPError as exc:
        return {"ok": False, "status": exc.code, "latency_ms": (time.perf_counter() - started) * 1000, "body": exc.read().decode("utf-8", errors="replace")}
    except Exception as exc:
        return {"ok": False, "status": 0, "latency_ms": (time.perf_counter() - started) * 1000, "error": str(exc)}


def get_json(
    url: str,
    timeout: int = 5,
    ssl_context: ssl.SSLContext | None = None,
) -> Dict[str, Any]:
    started = time.perf_counter()
    try:
        with urllib.request.urlopen(url, timeout=timeout, context=ssl_context) as resp:
            text = resp.read().decode("utf-8", errors="replace")
            return {"ok": 200 <= resp.status < 300, "status": resp.status, "latency_ms": (time.perf_counter() - started) * 1000, "body": safe_json(text)}
    except Exception as exc:
        return {"ok": False, "status": 0, "latency_ms": (time.perf_counter() - started) * 1000, "error": str(exc)}


def safe_json(text: str) -> Any:
    try:
        return json.loads(text)
    except Exception:
        return text


def chunks(rows: List[Dict[str, Any]], size: int) -> Iterable[List[Dict[str, Any]]]:
    for idx in range(0, len(rows), size):
        yield rows[idx : idx + size]


def signature(secret: str, payload: Any) -> str:
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()


def normalize_python(row: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "schema_version": 1,
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
        "payload": row,
    }


def run_go_ingestion(
    rows: List[Dict[str, Any]],
    args: argparse.Namespace,
    ssl_context: ssl.SSLContext | None = None,
) -> Dict[str, Any]:
    accepted = failed = 0
    latencies = []
    started = time.perf_counter()
    for batch in chunks(rows, args.batch_size):
        res = post_json(
            f"{args.gateway_url}/v1/ingest",
            batch,
            {"X-XDR-Signature": signature(args.secret, batch)},
            timeout=60,
            ssl_context=ssl_context,
        )
        latencies.append(float(res.get("latency_ms") or 0))
        if res.get("ok"):
            accepted += len(batch)
        else:
            failed += len(batch)
    elapsed = max(time.perf_counter() - started, 0.001)
    return {"events": len(rows), "accepted": accepted, "failed": failed, "elapsed_sec": elapsed, "throughput_eps": round(accepted / elapsed, 2), "batch_latency_ms": percentiles(latencies)}


def run_go_normalizer(
    rows: List[Dict[str, Any]],
    args: argparse.Namespace,
    ssl_context: ssl.SSLContext | None = None,
) -> Dict[str, Any]:
    enqueued = malformed = failed = 0
    latencies = []
    before = get_json(f"{args.normalizer_url}/metrics", ssl_context=ssl_context)
    before_body = before.get("body") if isinstance(before.get("body"), dict) else {}
    before_forwarded = int(before_body.get("forwarded") or 0)
    started = time.perf_counter()
    for batch in chunks(rows, args.batch_size):
        res = post_json(
            f"{args.normalizer_url}/v1/normalize",
            batch,
            timeout=60,
            ssl_context=ssl_context,
        )
        latencies.append(float(res.get("latency_ms") or 0))
        body = res.get("body") if isinstance(res.get("body"), dict) else {}
        if res.get("ok"):
            enqueued += int(body.get("enqueued") or body.get("forwarded") or 0)
            malformed += int(body.get("malformed") or 0)
        else:
            failed += len(batch)
    enqueue_elapsed = max(time.perf_counter() - started, 0.001)
    wait_started = time.perf_counter()
    forwarded_total = before_forwarded
    queue_depth = 0
    while time.perf_counter() - wait_started < 180:
        metrics = get_json(
            f"{args.normalizer_url}/metrics", ssl_context=ssl_context
        )
        body = metrics.get("body") if isinstance(metrics.get("body"), dict) else {}
        forwarded_total = int(body.get("forwarded") or 0)
        queue_depth = int(body.get("queue_depth") or 0)
        if (forwarded_total - before_forwarded) >= enqueued and queue_depth == 0:
            break
        time.sleep(0.25)
    forwarded = max(0, forwarded_total - before_forwarded)
    drain_elapsed = max(time.perf_counter() - wait_started, 0.001)
    total_elapsed = max(time.perf_counter() - started, 0.001)
    return {
        "events": len(rows),
        "enqueued": enqueued,
        "forwarded": forwarded,
        "queue_depth": queue_depth,
        "malformed": malformed,
        "failed": failed,
        "enqueue_elapsed_sec": enqueue_elapsed,
        "drain_elapsed_sec": drain_elapsed,
        "elapsed_sec": total_elapsed,
        "enqueue_throughput_eps": round(enqueued / enqueue_elapsed, 2),
        "end_to_end_throughput_eps": round(forwarded / total_elapsed, 2),
        "batch_latency_ms": percentiles(latencies),
    }


def run_python_baseline(rows: List[Dict[str, Any]], redpanda: RedpandaClient) -> Dict[str, Any]:
    sample = rows
    started = time.perf_counter()
    normalized = [normalize_python(row) for row in sample]
    normalize_elapsed = max(time.perf_counter() - started, 0.001)
    publish_started = time.perf_counter()
    sent, failed = redpanda.produce("telemetry.normalized", normalized)
    publish_elapsed = max(time.perf_counter() - publish_started, 0.001)
    return {
        "events": len(sample),
        "normalization_eps": round(len(sample) / normalize_elapsed, 2),
        "python_redpanda_publish_eps": round(sent / publish_elapsed, 2),
        "published": sent,
        "failed": failed,
    }


def percentiles(values: List[float]) -> Dict[str, float]:
    if not values:
        return {"p50": 0.0, "p95": 0.0, "p99": 0.0}
    ordered = sorted(values)
    def at(pct: float) -> float:
        idx = min(len(ordered) - 1, max(0, int(round((pct / 100) * (len(ordered) - 1)))))
        return round(ordered[idx], 3)
    return {"p50": at(50), "p95": at(95), "p99": at(99)}


def docker_stats() -> Dict[str, Any]:
    names = ["detector-xdr-ingestion-gateway", "detector-xdr-normalizer-worker", "detector-xdr-ai-rag-service", "detector-redpanda"]
    stats: Dict[str, Any] = {}
    for name in names:
        try:
            proc = subprocess.run(["docker", "stats", "--no-stream", "--format", "{{json .}}", name], text=True, capture_output=True, timeout=15)
            if proc.returncode == 0 and proc.stdout.strip():
                stats[name] = safe_json(proc.stdout.strip().splitlines()[-1])
        except Exception as exc:
            stats[name] = {"error": str(exc)}
    return stats


def main(args: argparse.Namespace | None = None) -> int:
    args = args or parse_args()
    try:
        ssl_context = build_mtls_context(args)
    except (OSError, ValueError) as exc:
        print(f"ERROR: Invalid mTLS configuration: {exc}", file=sys.stderr)
        return 2

    root = Path(__file__).resolve().parents[1]
    dataset = (root / args.dataset).resolve()
    if not dataset.exists():
        subprocess.run([sys.executable, str(root / "scripts" / "xdr_generate_realistic_dataset.py"), "--events", str(args.events), "--output", str(dataset)], check=True)
    rows = load_jsonl(dataset)[: args.events]
    baseline_rows = rows[: min(args.baseline_events, len(rows))]
    redpanda = RedpandaClient(args.redpanda_rest, timeout=10)

    health = {
        "ingestion_gateway": get_json(
            f"{args.gateway_url}/health", ssl_context=ssl_context
        ),
        "normalizer": get_json(
            f"{args.normalizer_url}/health", ssl_context=ssl_context
        ),
        "ai_rag": get_json(f"{args.ai_url}/health", ssl_context=ssl_context),
        "redpanda": redpanda.health(),
    }
    go_ingestion = run_go_ingestion(rows, args, ssl_context)
    go_normalization = run_go_normalizer(rows, args, ssl_context)
    python_baseline = run_python_baseline(baseline_rows, redpanda)
    ai_probe = post_json(
        f"{args.ai_url}/v1/analyze",
        {"incident_id": "strangler-validation", "evidence": rows[:10]},
        ssl_context=ssl_context,
    )
    gateway_metrics = get_json(
        f"{args.gateway_url}/metrics", ssl_context=ssl_context
    )
    normalizer_metrics = get_json(
        f"{args.normalizer_url}/metrics", ssl_context=ssl_context
    )
    ai_metrics = get_json(f"{args.ai_url}/metrics", ssl_context=ssl_context)
    stats = docker_stats()

    normalized_lag = max(0, go_ingestion["accepted"] - go_normalization["forwarded"])
    recovery_started = time.perf_counter()
    recovery_health = {
        "gateway": get_json(
            f"{args.gateway_url}/health", ssl_context=ssl_context
        ),
        "normalizer": get_json(
            f"{args.normalizer_url}/health", ssl_context=ssl_context
        ),
        "ai_rag": get_json(f"{args.ai_url}/health", ssl_context=ssl_context),
    }
    recovery_time = round((time.perf_counter() - recovery_started) * 1000, 2)

    report = {
        "validation_status": "PASS" if go_ingestion["failed"] == 0 and go_normalization["failed"] == 0 and normalized_lag <= args.batch_size else "WARN",
        "first_party_mtls_enabled": bool(ssl_context),
        "dataset": str(dataset),
        "events": len(rows),
        "service_health": health,
        "go_ingestion": go_ingestion,
        "go_normalization": go_normalization,
        "python_laravel_baseline": python_baseline,
        "comparison": {
            "ingestion_go_eps": go_ingestion["throughput_eps"],
            "python_publish_eps": python_baseline["python_redpanda_publish_eps"],
            "normalization_go_enqueue_eps": go_normalization["enqueue_throughput_eps"],
            "normalization_go_end_to_end_eps": go_normalization["end_to_end_throughput_eps"],
            "normalization_python_local_eps": python_baseline["normalization_eps"],
            "note": "Python baseline publishes sample events through the existing Python Redpanda client; Go path uses batch HTTP services.",
        },
        "stream_lag": {
            "raw_accepted_minus_normalized_forwarded": normalized_lag,
            "gateway_accepted": go_ingestion["accepted"],
            "normalizer_forwarded": go_normalization["forwarded"],
        },
        "recovery": {"health_check_ms": recovery_time, "status": recovery_health},
        "pressure": {"docker_stats": stats},
        "service_metrics": {"gateway": gateway_metrics, "normalizer": normalizer_metrics, "ai_rag": ai_metrics},
        "ai_probe": ai_probe,
    }
    output = (root / args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(f"output={output}")
    print(f"status={report['validation_status']} events={len(rows)} go_ingest_eps={go_ingestion['throughput_eps']} go_norm_e2e_eps={go_normalization['end_to_end_throughput_eps']} lag={normalized_lag}")
    return 0 if report["validation_status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main(parse_args()))
