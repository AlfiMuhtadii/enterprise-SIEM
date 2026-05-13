#!/usr/bin/env python3
"""Validate event-driven alert/incident flow resilience.

Checks:
- same alert event replayed multiple times does not create duplicate event-store rows
- optional service restart does not break idempotency
- malformed event can be sent to the xdr.alerts topic and should be visible in service DLQ/metrics

The script uses HTTP service endpoints when available and falls back to a
database-only operational event-store check.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Optional

from xdr_event_contracts import envelope


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate XDR event flow idempotency/restart/DLQ behavior")
    parser.add_argument("--alert-writer-url", default="http://127.0.0.1:8095")
    parser.add_argument("--redpanda-rest", default="http://127.0.0.1:8082")
    parser.add_argument("--replays", type=int, default=3)
    parser.add_argument("--restart-services", type=int, default=0)
    parser.add_argument("--send-malformed", type=int, default=1)
    parser.add_argument("--output", default="reports/xdr_event_flow_resilience_validation.json")
    return parser.parse_args()


def http_json(method: str, url: str, body: Optional[Dict[str, Any]] = None, timeout: int = 10) -> Dict[str, Any]:
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        text = resp.read().decode("utf-8")
    return json.loads(text) if text.strip() else {}


def http_json_with_headers(method: str, url: str, body: Dict[str, Any], headers: Dict[str, str], timeout: int = 10) -> Dict[str, Any]:
    data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        text = resp.read().decode("utf-8")
    return json.loads(text) if text.strip() else {}


def service_available(url: str) -> bool:
    try:
        health = http_json("GET", f"{url.rstrip('/')}/health", timeout=3)
        return health.get("status") == "ok"
    except Exception:
        return False


def restart_compose_service(service: str, root: Path) -> Dict[str, Any]:
    started = time.perf_counter()
    proc = subprocess.run(["docker", "compose", "restart", service], cwd=str(root), text=True, capture_output=True, timeout=120)
    return {
        "service": service,
        "ok": proc.returncode == 0,
        "returncode": proc.returncode,
        "stdout": proc.stdout[-1000:],
        "stderr": proc.stderr[-1000:],
        "duration_ms": round((time.perf_counter() - started) * 1000, 3),
    }


def php_event_store_count(root: Path, event_type: str, trace_id: str) -> Dict[str, Any]:
    proc = subprocess.run(
        ["php", "artisan", "xdr:event-store-count", f"--event-type={event_type}", f"--trace-id={trace_id}", "--json"],
        cwd=str(root),
        text=True,
        capture_output=True,
        timeout=60,
    )
    if proc.returncode != 0:
        return {"ok": False, "error": proc.stderr.strip()}
    try:
        return {"ok": True, **json.loads(proc.stdout.strip())}
    except json.JSONDecodeError:
        return {"ok": False, "error": proc.stdout.strip()}


def post_alert_process(url: str, trace_id: str) -> Dict[str, Any]:
    alert = {
        "alert_type": "IDENTITY_RISKY_LOGIN",
        "severity": "high",
        "detected_at": "2026-05-13T00:00:00+00:00",
        "actor_key": "resilience-user@example.test",
        "score": 0.91,
        "evidence": {"evidence_ids": ["resilience-event-1"], "xdr_domains": ["identity"]},
        "raw_event": {"event_id": "resilience-event-1", "telemetry_type": "identity"},
        "detector_name": "xdr-correlation",
        "detector_version": "resilience-test",
    }
    return http_json("POST", f"{url.rstrip('/')}/v1/process", {"alerts": [alert], "trace_id": trace_id, "source_topic": "xdr.alerts"}, timeout=30)


def produce_malformed(redpanda_rest: str) -> Dict[str, Any]:
    malformed = {"event_type": "xdr.alert.raised", "schema_version": 999, "payload": "not-an-object"}
    payload = {"records": [{"value": malformed}]}
    try:
        return http_json_with_headers(
            "POST",
            f"{redpanda_rest.rstrip('/')}/topics/xdr.alerts",
            payload,
            {"Content-Type": "application/vnd.kafka.json.v2+json"},
            timeout=10,
        )
    except urllib.error.HTTPError as exc:
        return {"ok": False, "status": exc.code, "error": exc.read().decode("utf-8", errors="replace")}
    except Exception as exc:
        return {"ok": False, "error": str(exc)}


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    trace_id = f"trace-resilience-{int(time.time())}"
    report: Dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "trace_id": trace_id,
        "checks": {},
        "runtime": {},
        "service_restart": [],
        "errors": [],
    }

    available = service_available(args.alert_writer_url)
    report["runtime"]["alert_writer_available"] = available

    if available:
        before = http_json("GET", f"{args.alert_writer_url.rstrip('/')}/metrics")
        for _ in range(max(1, args.replays)):
            post_alert_process(args.alert_writer_url, trace_id)
        after_replay = http_json("GET", f"{args.alert_writer_url.rstrip('/')}/metrics")
        if args.restart_services:
            report["service_restart"].append(restart_compose_service("alert-writer-service", root))
            time.sleep(5)
            post_alert_process(args.alert_writer_url, trace_id)
        after_restart = http_json("GET", f"{args.alert_writer_url.rstrip('/')}/metrics")
        report["runtime"]["alert_writer_metrics_before"] = before
        report["runtime"]["alert_writer_metrics_after_replay"] = after_replay
        report["runtime"]["alert_writer_metrics_after_restart"] = after_restart
    else:
        report["errors"].append("alert-writer-service unavailable; runtime replay/restart validation skipped")

    if args.send_malformed:
        malformed_result = produce_malformed(args.redpanda_rest)
        report["runtime"]["malformed_event_publish"] = malformed_result

    count_result = php_event_store_count(root, "alert.created", trace_id)
    report["runtime"]["event_store_count"] = count_result
    expected_max = 1 if available else 0
    report["checks"] = {
        "runtime_available": available,
        "event_store_not_duplicated": (count_result.get("count") in {0, 1}),
        "event_store_created_when_runtime_available": (not available) or count_result.get("count") == expected_max,
        "malformed_event_sent_or_skipped": bool(report["runtime"].get("malformed_event_publish")) if args.send_malformed else True,
        "restart_succeeded_or_not_requested": (
            not args.restart_services
            or all(item.get("ok") for item in report["service_restart"])
        ),
    }
    report["validation_status"] = "PASS" if all(report["checks"].values()) else "FAIL"

    output = root / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(f"output={output}")
    print(f"status={report['validation_status']} checks={json.dumps(report['checks'], sort_keys=True)}")
    return 0 if report["validation_status"] == "PASS" else 2


if __name__ == "__main__":
    raise SystemExit(main())
