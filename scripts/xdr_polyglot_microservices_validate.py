#!/usr/bin/env python3
"""Validate the polyglot XDR microservices runtime.

This is a lightweight operational smoke test. It does not replace soak tests or
large replay validation. It verifies that service boundaries are reachable and
that the Go ingestion gateway accepts a signed telemetry batch.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Tuple

from xdr_infra_clients import env_bool, tls_context_for_url


INTERNAL_URL_FIELDS = (
    "gateway_url",
    "normalizer_url",
    "correlation_url",
    "ai_url",
    "alert_writer_url",
    "incident_builder_url",
)


def http_json(method: str, url: str, payload: Any | None = None, headers: Dict[str, str] | None = None,
              timeout: int = 10, ssl_context: ssl.SSLContext | None = None) -> Tuple[bool, Dict[str, Any]]:
    body = None
    req_headers = dict(headers or {})
    if payload is not None:
        body = json.dumps(payload).encode("utf-8")
        req_headers.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=body, method=method, headers=req_headers)
    try:
        options: Dict[str, Any] = {"timeout": timeout}
        if ssl_context is not None:
            options["context"] = ssl_context
        with urllib.request.urlopen(req, **options) as resp:
            text = resp.read().decode("utf-8", errors="replace")
            try:
                data: Any = json.loads(text) if text.strip() else {}
            except Exception:
                data = {"text": text.strip()}
            return 200 <= resp.status < 300, {"status": resp.status, "body": data}
    except urllib.error.HTTPError as exc:
        text = exc.read().decode("utf-8", errors="replace")
        try:
            body_data: Any = json.loads(text)
        except Exception:
            body_data = text
        return False, {"status": exc.code, "body": body_data}
    except Exception as exc:
        return False, {"error": str(exc)}


def signed_headers(secret: str, payload: Any) -> Dict[str, str]:
    body = json.dumps(payload).encode("utf-8")
    digest = hmac.new(secret.encode("utf-8"), body, hashlib.sha256).hexdigest()
    return {"X-XDR-Signature": "sha256=" + digest}


def sample_events() -> list[dict[str, Any]]:
    now = datetime.now(timezone.utc).isoformat()
    return [
        {
            "event_id": "polyglot-id-1",
            "ts": now,
            "telemetry_type": "identity",
            "event_type": "login_success",
            "user": "analyst.demo@example.com",
            "source_ip": "198.51.100.10",
            "event_source": "m365",
            "risk_score": 0.8,
            "result": "success",
        },
        {
            "event_id": "polyglot-id-2",
            "ts": now,
            "telemetry_type": "cloud",
            "event_type": "access_key_created",
            "user": "analyst.demo@example.com",
            "cloud_account": "aws-demo",
            "action": "CreateAccessKey",
            "event_source": "aws-cloudtrail",
            "risk_score": 0.9,
        },
        {
            "event_id": "polyglot-id-3",
            "ts": now,
            "telemetry_type": "saas",
            "event_type": "admin_activity",
            "user": "analyst.demo@example.com",
            "action": "AddAdminRole",
            "event_source": "google-workspace",
            "risk_score": 0.85,
        },
    ]


def parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate polyglot XDR microservices runtime")
    parser.add_argument("--gateway-url", default="http://127.0.0.1:8091")
    parser.add_argument("--normalizer-url", default="http://127.0.0.1:8092")
    parser.add_argument("--correlation-url", default="http://127.0.0.1:8093")
    parser.add_argument("--ai-url", default="http://127.0.0.1:8094")
    parser.add_argument("--alert-writer-url", default="http://127.0.0.1:8095")
    parser.add_argument("--incident-builder-url", default="http://127.0.0.1:8096")
    parser.add_argument("--redpanda-url", default="http://127.0.0.1:8082")
    parser.add_argument("--clickhouse-url", default="http://127.0.0.1:8123")
    parser.add_argument("--opensearch-url", default="http://127.0.0.1:9200")
    parser.add_argument("--qdrant-url", default=os.getenv("XDR_QDRANT_URL", os.getenv("SOC_QDRANT_BASE_URL", "http://127.0.0.1:6333")))
    parser.add_argument("--qdrant-ca-cert", default=os.getenv("XDR_QDRANT_CA_CERT", ""))
    parser.add_argument("--internal-mtls-enabled", action="store_true", dest="internal_mtls_enabled")
    parser.add_argument("--internal-mtls-ca", default=None, dest="internal_mtls_ca")
    parser.add_argument("--internal-mtls-client-cert", default=None, dest="internal_mtls_client_cert")
    parser.add_argument("--internal-mtls-client-key", default=None, dest="internal_mtls_client_key")
    parser.add_argument("--secret", default="dev-secret-change-me")
    parser.add_argument("--settle-sec", type=float, default=5.0)
    parser.add_argument("--output", default="reports/xdr_polyglot_microservices_validation.json")
    return parser.parse_args(argv)


def build_internal_mtls_context(args: argparse.Namespace) -> ssl.SSLContext | None:
    if not getattr(args, "internal_mtls_enabled", False):
        return None
    insecure = [
        f"--{field.replace('_', '-')}"
        for field in INTERNAL_URL_FIELDS
        if urllib.parse.urlsplit(getattr(args, field)).scheme.lower() != "https"
    ]
    if insecure:
        raise ValueError(
            "--internal-mtls-enabled requires https:// for " + ", ".join(insecure)
        )
    material = {
        "--internal-mtls-ca": getattr(args, "internal_mtls_ca", None),
        "--internal-mtls-client-cert": getattr(args, "internal_mtls_client_cert", None),
        "--internal-mtls-client-key": getattr(args, "internal_mtls_client_key", None),
    }
    missing = [option for option, value in material.items() if not value]
    if missing:
        raise ValueError(
            "--internal-mtls-enabled requires complete TLS material; missing "
            + ", ".join(missing)
        )
    context = ssl.create_default_context(cafile=material["--internal-mtls-ca"])
    context.load_cert_chain(
        certfile=material["--internal-mtls-client-cert"],
        keyfile=material["--internal-mtls-client-key"],
    )
    return context


def main(args: argparse.Namespace | None = None) -> int:
    args = args or parse_args()
    try:
        internal_context = build_internal_mtls_context(args)
    except (OSError, ValueError) as exc:
        print(f"ERROR: Invalid internal mTLS configuration: {exc}", file=sys.stderr)
        return 2

    checks: Dict[str, Any] = {}
    qdrant_context = tls_context_for_url(
        args.qdrant_url,
        env_bool("XDR_QDRANT_VERIFY_TLS", True),
        args.qdrant_ca_cert,
    )
    endpoints = {
        "ingestion_gateway": (f"{args.gateway_url}/health", internal_context),
        "normalizer_worker": (f"{args.normalizer_url}/health", internal_context),
        "correlation_worker": (f"{args.correlation_url}/health", internal_context),
        "ai_rag_service": (f"{args.ai_url}/health", internal_context),
        "alert_writer": (f"{args.alert_writer_url}/health", internal_context),
        "incident_builder": (f"{args.incident_builder_url}/health", internal_context),
        "redpanda_topics": (f"{args.redpanda_url}/topics", None),
        "clickhouse": (f"{args.clickhouse_url}/ping", None),
        "opensearch": (args.opensearch_url, None),
        "qdrant": (f"{args.qdrant_url}/healthz", qdrant_context),
    }
    for name, (url, context) in endpoints.items():
        ok, result = http_json("GET", url, timeout=10, ssl_context=context)
        checks[name] = {"ok": ok, **result}

    events = sample_events()
    ok, ingest_result = http_json(
        "POST",
        f"{args.gateway_url}/v1/ingest",
        payload=events,
        headers=signed_headers(args.secret, events),
        timeout=15,
        ssl_context=internal_context,
    )
    checks["signed_ingestion"] = {"ok": ok, **ingest_result, "events": len(events)}

    time.sleep(max(0.0, args.settle_sec))
    metrics = {}
    for name, url in {
        "ingestion_gateway": f"{args.gateway_url}/metrics",
        "normalizer_worker": f"{args.normalizer_url}/metrics",
        "correlation_worker": f"{args.correlation_url}/metrics",
        "alert_writer": f"{args.alert_writer_url}/metrics",
        "incident_builder": f"{args.incident_builder_url}/metrics",
        "ai_rag_service": f"{args.ai_url}/metrics",
    }.items():
        ok, result = http_json("GET", url, timeout=10, ssl_context=internal_context)
        metrics[name] = {"ok": ok, **result}

    required = [
        "ingestion_gateway",
        "normalizer_worker",
        "correlation_worker",
        "ai_rag_service",
        "alert_writer",
        "incident_builder",
        "redpanda_topics",
        "signed_ingestion",
    ]
    pass_status = all(checks.get(name, {}).get("ok") for name in required)
    report = {
        "status": "PASS" if pass_status else "FAIL",
        "checked_at": datetime.now(timezone.utc).isoformat(),
        "architecture": "polyglot-microservices",
        "internal_mtls_enabled": bool(internal_context),
        "languages": {"control_plane": "php-laravel", "stream_workers": "go", "soc_services": "python-fastapi"},
        "event_flow": ["telemetry.raw", "telemetry.normalized", "xdr.alerts", "alerts.created", "incidents.updated"],
        "checks": checks,
        "metrics": metrics,
    }
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(f"status={report['status']} output={output}")
    return 0 if pass_status else 2


if __name__ == "__main__":
    raise SystemExit(main())
