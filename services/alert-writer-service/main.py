#!/usr/bin/env python3
"""Separated XDR alert writer service.

Consumes alert batches from xdr.alerts-compatible payloads and writes them to
PostgreSQL/OpenSearch when runtime endpoints are configured. The service is
safe to run in dry-run mode during strangler migration.
"""

from __future__ import annotations

import hashlib
import json
import os
import sys
import threading
import time
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse, urlunparse

import requests

# PERF-PYTHON-HTTP: reuse a single Session so outbound HTTP calls (Pandaproxy,
# OpenSearch) share a pooled, keep-alive connection instead of opening and tearing
# down a fresh TCP connection on every requests.<verb> call.
SESSION = requests.Session()
from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field
from xdr_event_contracts import envelope, is_envelope, unwrap_payload, validate_envelope

try:
    import psycopg
except Exception:  # pragma: no cover
    psycopg = None  # type: ignore


class AlertPayload(BaseModel):
    alert_id: Optional[str] = None
    alert_type: str
    severity: str = "medium"
    detected_at: Optional[str] = None
    ip: Optional[str] = None
    actor_key: Optional[str] = None
    score: Optional[float] = None
    evidence: Dict[str, Any] = Field(default_factory=dict)
    raw_event: Dict[str, Any] = Field(default_factory=dict)
    detector_name: str = "xdr-correlation"
    detector_version: str = "go-shadow"
    trace_id: Optional[str] = None
    tenant_id: Optional[str] = None


class WriteRequest(BaseModel):
    alerts: List[AlertPayload]
    trace_id: Optional[str] = None
    source_topic: str = "xdr.alerts"


app = FastAPI(title="Detector XDR Alert Writer", version="0.1.0")
METRICS: Dict[str, Any] = {
    "batches": 0,
    "alerts_seen": 0,
    "alerts_written": 0,
    "duplicates": 0,
    "postgres_failures": 0,
    "opensearch_failures": 0,
    "dlq_count": 0,
    "retry_count": 0,
    "write_latency_ms_last": 0,
    "write_latency_ms_total": 0.0,
    "idempotency_cache_size": 0,
    "consumer_polls": 0,
    "consumer_errors": 0,
    "events_published": 0,
    "contract_validation_failures": 0,
    "operational_events_stored": 0,
    # Shadow consumer metrics (separate counters — never mixed with active path)
    "shadow_polls": 0,
    "shadow_findings_seen": 0,
    "shadow_findings_written": 0,
    "shadow_findings_upserted": 0,
    "shadow_consumer_errors": 0,
    # DLQ consumer metrics (normalization_failed topic — separate from active path)
    "dlq_consumer_polls": 0,
    "dlq_consumer_records_seen": 0,
    "dlq_consumer_records_written": 0,
    "dlq_consumer_records_upserted": 0,
    "dlq_consumer_errors": 0,
    "internal_auth_mode": "permissive",
    "alert_write_dlq_written": 0,
    "alert_write_dlq_errors": 0,
    # Pipeline DLQ consumer metrics (xdr.correlation_failed / xdr.alert_write_failed)
    "pipeline_dlq_consumer_polls": 0,
    "pipeline_dlq_consumer_records_seen": 0,
    "pipeline_dlq_consumer_records_written": 0,
    "pipeline_dlq_consumer_records_upserted": 0,
    "pipeline_dlq_consumer_errors": 0,
}
SEEN: set[str] = set()
DLQ: List[Dict[str, Any]] = []
STOP = threading.Event()

# ---------------------------------------------------------------------------
# Shadow consumer constants
# ---------------------------------------------------------------------------
_SHADOW_PROMOTION_CONFIDENCE_THRESHOLD = 0.75
_SHADOW_TOPIC_TO_DOMAIN = {
    "xdr.alerts.shadow.endpoint": "endpoint",
    "xdr.alerts.shadow.network":  "network",
}


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def fingerprint(alert: AlertPayload) -> str:
    evidence_ids = alert.evidence.get("evidence_ids") or alert.evidence.get("event_ids") or []
    if not isinstance(evidence_ids, list):
        evidence_ids = [str(evidence_ids)]
    material = "|".join([
        alert.alert_type,
        alert.severity,
        alert.actor_key or alert.ip or "unknown",
        ",".join(sorted(str(item) for item in evidence_ids)),
    ])
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def alert_id(alert: AlertPayload, fp: str) -> str:
    return alert.alert_id or "xdr-" + fp[:40]


def connect_pg():
    dsn = os.getenv("SECURITY_INGEST_DSN") or os.getenv("DATABASE_URL") or ""
    if psycopg is None:
        return None
    if dsn:
        return psycopg.connect(dsn)
    host = os.getenv("DB_HOST", "")
    database = os.getenv("DB_DATABASE", "")
    user = os.getenv("DB_USERNAME", "")
    if not host or not database or not user:
        return None
    return psycopg.connect(
        host=host,
        port=int(os.getenv("DB_PORT", "5432")),
        dbname=database,
        user=user,
        password=os.getenv("DB_PASSWORD", ""),
    )


def postgres_configured() -> bool:
    return bool(
        os.getenv("SECURITY_INGEST_DSN")
        or os.getenv("DATABASE_URL")
        or (os.getenv("DB_HOST") and os.getenv("DB_DATABASE") and os.getenv("DB_USERNAME"))
    )


def write_postgres(alerts: List[AlertPayload], trace_id: Optional[str] = None) -> int:
    conn = connect_pg()
    if conn is None:
        return 0
    rows = []
    for alert in alerts:
        fp = fingerprint(alert)
        rows.append((
            alert_id(alert, fp),
            alert.detected_at or now_iso(),
            alert.alert_type,
            alert.detector_name,
            alert.detector_version,
            alert.severity,
            alert.ip,
            alert.actor_key,
            alert.score,
            fp,
            json.dumps(alert.evidence),
            json.dumps(alert.raw_event),
            alert.trace_id or trace_id,
            alert.tenant_id,
        ))
    with conn:
        with conn.cursor() as cur:
            for row in rows:
                cur.execute(
                    """
                    INSERT INTO security_alerts (
                        alert_id, detected_at, alert_type, detector_name, detector_version,
                        severity, ip, actor_key, score, alert_fingerprint, evidence, raw_event,
                        trace_id, tenant_id, created_at, updated_at
                    ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s::jsonb,%s,%s,now(),now())
                    ON CONFLICT (alert_id) DO UPDATE SET
                        updated_at=now(), evidence=excluded.evidence,
                        raw_event=excluded.raw_event,
                        trace_id=COALESCE(excluded.trace_id, security_alerts.trace_id),
                        tenant_id=COALESCE(excluded.tenant_id, security_alerts.tenant_id)
                    """,
                    row,
                )
    return len(rows)


def store_operational_event(
    event: Dict[str, Any],
    source_topic: str,
    aggregate_type: Optional[str] = None,
    aggregate_id: Optional[str] = None,
) -> None:
    conn = connect_pg()
    if conn is None:
        return
    try:
        metadata = dict(event.get("metadata") or {})
        if aggregate_type:
            metadata["aggregate_type"] = aggregate_type
        if aggregate_id:
            metadata["aggregate_id"] = aggregate_id
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO xdr_operational_events (
                        event_id,event_type,schema_version,source_topic,source_service,
                        aggregate_type,aggregate_id,trace_id,correlation_id,occurred_at,
                        payload,metadata,replayable,published_at,created_at,updated_at
                    ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s::jsonb,true,now(),now(),now())
                    ON CONFLICT (event_id) DO NOTHING
                    """,
                    (
                        event["event_id"],
                        event["event_type"],
                        event["schema_version"],
                        source_topic,
                        event["source_service"],
                        aggregate_type or metadata.get("aggregate_type"),
                        aggregate_id or metadata.get("aggregate_id"),
                        event.get("trace_id"),
                        metadata.get("correlation_id"),
                        event["occurred_at"],
                        json.dumps(event["payload"]),
                        json.dumps(metadata),
                    ),
                )
                METRICS["operational_events_stored"] += cur.rowcount
    except Exception as exc:
        DLQ.append({"ts": now_iso(), "target": "xdr_operational_events", "error": str(exc), "event_id": event.get("event_id")})
        METRICS["dlq_count"] = len(DLQ)


def write_opensearch(alerts: List[AlertPayload]) -> int:
    url = os.getenv("XDR_OPENSEARCH_URL", "").rstrip("/")
    if not url:
        return 0
    failures = 0
    for alert in alerts:
        fp = fingerprint(alert)
        doc = alert.model_dump()
        doc["alert_fingerprint"] = fp
        doc["indexed_at"] = now_iso()
        try:
            resp = SESSION.put(f"{url}/xdr-alerts/_doc/{alert_id(alert, fp)}", json=doc, timeout=5)
            if resp.status_code >= 300:
                failures += 1
        except Exception:
            failures += 1
    if failures:
        METRICS["opensearch_failures"] += failures
        DLQ.append({"ts": now_iso(), "target": "opensearch", "failures": failures, "reason": "index_failed_or_unreachable"})
        METRICS["dlq_count"] = len(DLQ)
    return len(alerts) - failures


def redpanda_rest() -> str:
    return os.getenv("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082").rstrip("/")


def normalize_consumer_base_uri(base_uri: str) -> str:
    """Kafka REST can return externally advertised hostnames unusable in containers."""
    advertised = urlparse(base_uri)
    internal = urlparse(redpanda_rest())
    if advertised.netloc and internal.netloc:
        return urlunparse((internal.scheme, internal.netloc, advertised.path, "", "", ""))
    return base_uri


def produce(topic: str, events: List[Dict[str, Any]]) -> int:
    if not events:
        return 0
    payload = {"records": [{"value": event} for event in events]}
    resp = SESSION.post(
        f"{redpanda_rest()}/topics/{topic}",
        json=payload,
        headers={"Content-Type": "application/vnd.kafka.json.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()
    METRICS["events_published"] += len(events)
    return len(events)


def write_alert_failure(
    reason: str,
    error_message: str,
    source_topic: str,
    trace_id: Optional[str] = None,
    alert_count: int = 0,
) -> bool:
    """Write structured alert write failure to xdr.alert_write_failed. Returns True on success.

    Failure topics preserve source coordinates so operators can triage write errors
    without losing context. If this write itself fails, the error is logged in-memory
    only (DLQ write failure must not cascade into another DLQ write).
    """
    dlq_topic = os.getenv("XDR_ALERT_WRITE_FAILED_TOPIC") or "xdr.alert_write_failed"
    record: Dict[str, Any] = {
        "dlq_event_type": "alert_write_failed",
        "source_topic": source_topic,
        "error_message": error_message,
        "reason": reason,
        "alert_count": alert_count,
        "ts": now_iso(),
    }
    if trace_id:
        record["trace_id"] = trace_id
    try:
        produce(dlq_topic, [record])
        METRICS["alert_write_dlq_written"] += 1
        return True
    except Exception as exc:
        METRICS["alert_write_dlq_errors"] += 1
        print(f"[alert-writer] WARN: dlq write failed topic={dlq_topic}: {exc}", flush=True)
        return False


# Signals that identify an offset-out-of-range response from Pandaproxy.
_OFFSET_RANGE_SIGNALS = frozenset([
    "offset_out_of_range",
    "40002",
    "offset does not exist",
    "requested offset",
    "out of range",
])


def _is_offset_range_error(exc: Exception) -> bool:
    msg = str(exc).lower()
    body = ""
    if hasattr(exc, "response") and exc.response is not None:
        try:
            body = exc.response.text.lower()
        except Exception:
            pass
    combined = msg + " " + body
    return any(sig in combined for sig in _OFFSET_RANGE_SIGNALS)


def consumer_create(group: str, name: str, offset_reset: str = "earliest") -> str:
    resp = SESSION.post(
        f"{redpanda_rest()}/consumers/{group}",
        json={"name": name, "format": "json", "auto.offset.reset": offset_reset},
        headers={"Content-Type": "application/vnd.kafka.v2+json", "Accept": "application/vnd.kafka.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()
    return normalize_consumer_base_uri(str(resp.json()["base_uri"]))


def consumer_delete(base_uri: str) -> None:
    try:
        SESSION.delete(
            base_uri,
            headers={"Content-Type": "application/vnd.kafka.v2+json"},
            timeout=5,
        )
    except Exception:
        pass  # best-effort; stale instances are cleaned up by Redpanda session timeout


def consumer_subscribe(base_uri: str, topic: str) -> None:
    resp = SESSION.post(
        f"{base_uri}/subscription",
        json={"topics": [topic]},
        headers={"Content-Type": "application/vnd.kafka.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()


def consumer_poll(base_uri: str) -> List[Dict[str, Any]]:
    resp = SESSION.get(
        f"{base_uri}/records?timeout=1000&max_bytes=1048576",
        headers={"Accept": "application/vnd.kafka.json.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()
    return resp.json() if resp.text.strip() else []


def normalize_records(records: List[Dict[str, Any]]) -> List[AlertPayload]:
    alerts: List[AlertPayload] = []
    for record in records:
        value = record.get("value") or {}
        try:
            value = unwrap_payload(value, "xdr.alerts") if is_envelope(value) else value
        except ValueError:
            METRICS["contract_validation_failures"] += 1
            DLQ.append({"ts": now_iso(), "target": "xdr.alerts", "error": "contract_validation_failed", "event": value})
            METRICS["dlq_count"] = len(DLQ)
            continue
        batch_trace_id = value.get("trace_id") if isinstance(value, dict) else None
        rows = value.get("alerts") if isinstance(value, dict) else None
        if rows is None:
            rows = [value.get("alert")] if isinstance(value, dict) and "alert" in value else [value]
        for row in rows:
            if isinstance(row, dict):
                if not row.get("trace_id") and batch_trace_id:
                    row["trace_id"] = batch_trace_id
                alerts.append(AlertPayload(**row))
    return alerts


def process_alerts(alerts: List[AlertPayload], trace_id: Optional[str], source_topic: str) -> Dict[str, Any]:
    result = write(WriteRequest(alerts=alerts, trace_id=trace_id, source_topic=source_topic))
    created_topic = os.getenv("XDR_ALERTS_CREATED_TOPIC", "alerts.created")
    created_events = []
    for alert in alerts:
        fp = fingerprint(alert)
        alert_trace_id = alert.trace_id or trace_id
        payload = {
            "trace_id": alert_trace_id,
            "alert": alert.model_dump(),
            "alert_id": alert_id(alert, fp),
            "alert_fingerprint": fp,
            "created_at": now_iso(),
            "source": "alert-writer-service",
        }
        created_events.append(envelope(
            topic=created_topic,
            payload=payload,
            source_service="alert-writer-service",
            trace_id=alert_trace_id,
            aggregate_type="alert",
            aggregate_id=payload["alert_id"],
            metadata={"source_topic": source_topic},
        ))
    try:
        for event in created_events:
            errors = validate_envelope(event, created_topic)
            if errors:
                raise ValueError(";".join(errors))
            store_operational_event(event, created_topic, "alert", event["payload"].get("alert_id"))
        produce(created_topic, created_events)
    except Exception as exc:
        METRICS["retry_count"] += 1
        DLQ.append({"ts": now_iso(), "target": created_topic, "error": str(exc), "events": created_events[:20]})
        METRICS["dlq_count"] = len(DLQ)
    return result


def event_loop() -> None:
    topic = os.getenv("XDR_ALERTS_TOPIC", "xdr.alerts")
    offset_reset = os.getenv("XDR_ALERT_WRITER_AUTO_OFFSET_RESET", "earliest")
    _MAX_ERRORS_BEFORE_RECREATE = 3

    # Use ms-resolution timestamp so rapid restarts get distinct group names.
    def _new_ids() -> tuple[str, str]:
        ts = int(time.time() * 1000)
        g = f"{os.getenv('XDR_ALERT_WRITER_GROUP', 'alert-writer-v1')}-{ts}"
        n = f"alert-writer-{ts}"
        return g, n

    group, name = _new_ids()
    base_uri: Optional[str] = None
    consecutive_errors = 0

    def _setup_consumer(g: str, n: str) -> str:
        uri = consumer_create(g, n, offset_reset=offset_reset)
        consumer_subscribe(uri, topic)
        print(
            f"[alert-writer] consumer ready  topic={topic}  group={g}"
            f"  instance={n}  auto.offset.reset={offset_reset}",
            flush=True,
        )
        return uri

    print(
        f"[alert-writer] consumer starting  topic={topic}  group={group}"
        f"  instance={name}  auto.offset.reset={offset_reset}",
        flush=True,
    )
    try:
        base_uri = _setup_consumer(group, name)
    except Exception as exc:
        METRICS["consumer_errors"] += 1
        DLQ.append({"ts": now_iso(), "target": topic, "error": f"consumer_start_failed: {exc}"})
        METRICS["dlq_count"] = len(DLQ)
        return

    while not STOP.is_set():
        try:
            records = consumer_poll(base_uri)
            METRICS["consumer_polls"] += 1
            consecutive_errors = 0
            alerts = normalize_records(records)
            if alerts:
                print(
                    f"[alert-writer] consumed {len(records)} records → {len(alerts)} alerts"
                    f"  group={group}  topic={topic}",
                    flush=True,
                )
                batch_trace_id = next((a.trace_id for a in alerts if a.trace_id), None)
                process_alerts(alerts, trace_id=batch_trace_id, source_topic=topic)
        except Exception as exc:
            METRICS["consumer_errors"] += 1
            consecutive_errors += 1
            is_offset_err = _is_offset_range_error(exc)
            dlq_ok = write_alert_failure("event_loop_error", str(exc), topic)
            DLQ.append({
                "ts": now_iso(),
                "target": topic,
                "error": str(exc),
                "consecutive_errors": consecutive_errors,
                "offset_range_error": is_offset_err,
            })
            METRICS["dlq_count"] = len(DLQ)

            should_recreate = is_offset_err or consecutive_errors >= _MAX_ERRORS_BEFORE_RECREATE or not dlq_ok
            if should_recreate:
                reason = "offset_out_of_range" if is_offset_err else f"{consecutive_errors}_consecutive_errors"
                print(
                    f"[alert-writer] WARN: consumer recovery triggered ({reason})"
                    f"  group={group}  topic={topic}  — deleting and recreating",
                    flush=True,
                )
                if base_uri:
                    consumer_delete(base_uri)
                group, name = _new_ids()
                consecutive_errors = 0
                try:
                    base_uri = _setup_consumer(group, name)
                except Exception as setup_exc:
                    print(
                        f"[alert-writer] ERROR: consumer recreate failed: {setup_exc}",
                        flush=True,
                    )
                    METRICS["consumer_errors"] += 1
                    time.sleep(5)
            else:
                time.sleep(2)


def _auth_mode() -> str:
    return "enforced" if os.getenv("XDR_ENFORCE_INTERNAL_AUTH", "false").lower() in {"1", "true", "yes"} else "permissive"


@app.get("/health")
def health() -> Dict[str, Any]:
    shadow_enabled     = os.getenv("XDR_SHADOW_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}
    dlq_enabled        = os.getenv("XDR_DLQ_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}
    corr_dlq_enabled   = os.getenv("XDR_CORRELATION_DLQ_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}
    write_dlq_enabled  = os.getenv("XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}
    auth_mode = _auth_mode()
    return {
        "status": "ok",
        "service": "alert-writer",
        "mode": "event-driven",
        "consumes": os.getenv("XDR_ALERTS_TOPIC", "xdr.alerts"),
        "produces": os.getenv("XDR_ALERTS_CREATED_TOPIC", "alerts.created"),
        "shadow_consumer_enabled": shadow_enabled,
        "shadow_endpoint_topic": os.getenv("XDR_SHADOW_ENDPOINT_TOPIC", "xdr.alerts.shadow.endpoint"),
        "shadow_network_topic":  os.getenv("XDR_SHADOW_NETWORK_TOPIC",  "xdr.alerts.shadow.network"),
        "dlq_consumer_enabled":              dlq_enabled,
        "dlq_topic":                         os.getenv("XDR_DLQ_TOPIC", "telemetry.normalization_failed"),
        "alert_write_failed_topic":          os.getenv("XDR_ALERT_WRITE_FAILED_TOPIC") or "xdr.alert_write_failed",
        "correlation_failed_topic":          os.getenv("XDR_CORRELATION_FAILED_TOPIC") or "xdr.correlation_failed",
        "correlation_dlq_consumer_enabled":  corr_dlq_enabled,
        "alert_write_dlq_consumer_enabled":  write_dlq_enabled,
        "internal_auth_mode":                auth_mode,
    }


@app.get("/metrics")
def metrics() -> Dict[str, Any]:
    METRICS["idempotency_cache_size"] = len(SEEN)
    METRICS["internal_auth_mode"] = _auth_mode()
    return METRICS


@app.get("/dlq")
def dlq(x_internal_service_token: Optional[str] = Header(default=None)) -> Dict[str, Any]:
    if not verify_internal_token(x_internal_service_token or ""):
        raise HTTPException(status_code=401, detail="unauthorized")
    items = [
        {k: (str(v)[:120] if k in ("event", "error") and isinstance(v, str) and len(str(v)) > 120 else v)
         for k, v in item.items()}
        for item in DLQ[-20:]
    ]
    return {"count": len(DLQ), "items": items}


@app.post("/v1/write")
def write(
    request: WriteRequest,
    x_internal_service_token: Optional[str] = Header(default=None),
) -> Dict[str, Any]:
    if not verify_internal_token(x_internal_service_token or ""):
        raise HTTPException(status_code=401, detail="unauthorized")
    started = time.perf_counter()
    METRICS["batches"] += 1
    METRICS["alerts_seen"] += len(request.alerts)
    unique: List[AlertPayload] = []
    duplicates = 0
    for alert in request.alerts:
        fp = fingerprint(alert)
        if fp in SEEN:
            duplicates += 1
            continue
        SEEN.add(fp)
        unique.append(alert)
    METRICS["duplicates"] += duplicates

    written_pg = 0
    written_os = 0
    try:
        written_pg = write_postgres(unique, trace_id=request.trace_id)
    except Exception as exc:
        METRICS["postgres_failures"] += len(unique)
        METRICS["retry_count"] += 1
        DLQ.append({"ts": now_iso(), "trace_id": request.trace_id, "error": str(exc), "alerts": [a.model_dump() for a in unique[:20]]})
        METRICS["dlq_count"] = len(DLQ)
        write_alert_failure("postgres_write_failed", str(exc), request.source_topic, request.trace_id, len(unique))
    try:
        written_os = write_opensearch(unique)
    except Exception as exc:
        METRICS["opensearch_failures"] += len(unique)
        DLQ.append({"ts": now_iso(), "trace_id": request.trace_id, "error": str(exc), "target": "opensearch"})
        METRICS["dlq_count"] = len(DLQ)
        write_alert_failure("opensearch_write_failed", str(exc), request.source_topic, request.trace_id, len(unique))

    dry_run = not postgres_configured() and not os.getenv("XDR_OPENSEARCH_URL")
    METRICS["alerts_written"] += max(written_pg, written_os, len(unique) if dry_run else 0)
    elapsed = (time.perf_counter() - started) * 1000
    METRICS["write_latency_ms_last"] = round(elapsed, 3)
    METRICS["write_latency_ms_total"] += elapsed
    return {
        "ok": True,
        "trace_id": request.trace_id,
        "source_topic": request.source_topic,
        "received": len(request.alerts),
        "unique": len(unique),
        "duplicates": duplicates,
        "postgres_written": written_pg,
        "opensearch_written": written_os,
        "dlq_count": len(DLQ),
        "write_latency_ms": round(elapsed, 3),
        "dry_run": dry_run,
    }


@app.post("/v1/process")
def process(
    request: WriteRequest,
    x_internal_service_token: Optional[str] = Header(default=None),
) -> Dict[str, Any]:
    if not verify_internal_token(x_internal_service_token or ""):
        raise HTTPException(status_code=401, detail="unauthorized")
    return process_alerts(request.alerts, request.trace_id, request.source_topic)


# ---------------------------------------------------------------------------
# Shadow alert consumer — advisory findings path
# Does NOT publish to alerts.created. Does NOT create incidents.
# Writes to advisory_findings table only (separate from security_alerts).
# ---------------------------------------------------------------------------

def shadow_fingerprint(rule_id: str, actor: str, evidence_ids: List[str]) -> str:
    material = "|".join([rule_id, actor or "unknown", ",".join(sorted(str(e) for e in evidence_ids))])
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def normalize_shadow_records(records: List[Dict[str, Any]], source_topic: str) -> List[Dict[str, Any]]:
    """Parse EndpointAlert batch payloads from shadow topics into advisory finding dicts."""
    domain = _SHADOW_TOPIC_TO_DOMAIN.get(source_topic, "unknown")
    findings: List[Dict[str, Any]] = []
    for record in records:
        value = record.get("value") or {}
        if not isinstance(value, dict):
            continue
        batch_trace_id = value.get("trace_id", "")
        alerts = value.get("alerts") or []
        if not isinstance(alerts, list):
            continue
        for alert in alerts:
            if not isinstance(alert, dict):
                continue
            rule_id      = alert.get("rule_id") or alert.get("alert_type") or "unknown"
            actor        = alert.get("host") or alert.get("user") or alert.get("actor") or ""
            evidence_ids = alert.get("evidence_ids") or []
            confidence   = float(alert.get("confidence") or 0.0)
            fp           = shadow_fingerprint(rule_id, actor, evidence_ids)
            findings.append({
                "finding_id":       "adv-" + fp[:36],
                "rule_id":          rule_id,
                "domain":           domain,
                "source_topic":     source_topic,
                "alert_type":       alert.get("event_type") or rule_id,
                "severity":         (alert.get("severity") or "medium").lower(),
                "confidence":       confidence,
                "actor_key":        actor or None,
                "tenant_id":        alert.get("tenant_id") or None,
                "evidence":         json.dumps(alert.get("evidence") or {}),
                "source_event_ids": json.dumps(evidence_ids),
                "promotion_candidate": confidence >= _SHADOW_PROMOTION_CONFIDENCE_THRESHOLD,
                "promotion_blocker":   _shadow_promotion_blocker(domain, confidence),
                "fingerprint":     fp,
                "trace_id":        alert.get("trace_id") or batch_trace_id,
            })
    return findings


def _shadow_promotion_blocker(domain: str, confidence: float) -> str:
    reasons = [f"domain_soak_required: no 6h soak PASS for domain={domain}"]
    if confidence < _SHADOW_PROMOTION_CONFIDENCE_THRESHOLD:
        reasons.append(f"low_confidence: {confidence:.2f} < {_SHADOW_PROMOTION_CONFIDENCE_THRESHOLD}")
    return "; ".join(reasons)


def write_advisory_finding(finding: Dict[str, Any]) -> str:
    """Upsert an advisory finding. Returns 'inserted' or 'updated'."""
    conn = connect_pg()
    if conn is None:
        return "no_db"
    now = datetime.now(timezone.utc).isoformat()
    with conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO advisory_findings (
                    finding_id, rule_id, domain, source_topic, alert_type, severity,
                    confidence, actor_key, tenant_id, evidence, source_event_ids,
                    promotion_candidate, promotion_blocker, status, fingerprint,
                    occurrence_count, first_seen_at, last_seen_at, created_at, updated_at
                ) VALUES (
                    %s,%s,%s,%s,%s,%s,
                    %s,%s,%s,%s::jsonb,%s::jsonb,
                    %s,%s,'new',%s,
                    1,%s,%s,now(),now()
                )
                ON CONFLICT (fingerprint) DO UPDATE SET
                    occurrence_count = advisory_findings.occurrence_count + 1,
                    last_seen_at     = excluded.last_seen_at,
                    promotion_candidate = CASE
                        WHEN advisory_findings.promotion_candidate THEN true
                        ELSE excluded.promotion_candidate
                    END,
                    updated_at = now()
                RETURNING (xmax = 0) AS inserted
                """,
                (
                    finding["finding_id"],
                    finding["rule_id"],
                    finding["domain"],
                    finding["source_topic"],
                    finding["alert_type"],
                    finding["severity"],
                    finding["confidence"],
                    finding["actor_key"],
                    finding["tenant_id"],
                    finding["evidence"],
                    finding["source_event_ids"],
                    finding["promotion_candidate"],
                    finding["promotion_blocker"],
                    finding["fingerprint"],
                    now, now,
                ),
            )
            row = cur.fetchone()
    return "inserted" if (row and row[0]) else "updated"


def shadow_event_loop(source_topic: str) -> None:
    """Consumer loop for one shadow topic. Runs in a daemon thread.

    Writes to advisory_findings only. Never publishes to alerts.created.
    Uses the same offset-recovery pattern as event_loop().
    """
    offset_reset = "earliest"
    _MAX_ERRORS = 3

    def _new_ids() -> tuple[str, str]:
        ts  = int(time.time() * 1000)
        safe = source_topic.replace(".", "-").replace(":", "-")
        g   = f"shadow-consumer-{safe}-{ts}"
        n   = f"shadow-{safe}-{ts}"
        return g, n

    group, name = _new_ids()
    base_uri: Optional[str] = None
    consecutive_errors = 0

    def _setup(g: str, n: str) -> str:
        uri = consumer_create(g, n, offset_reset=offset_reset)
        consumer_subscribe(uri, source_topic)
        print(f"[shadow-consumer] ready  topic={source_topic}  group={g}", flush=True)
        return uri

    try:
        base_uri = _setup(group, name)
    except Exception as exc:
        METRICS["shadow_consumer_errors"] += 1
        DLQ.append({"ts": now_iso(), "target": source_topic, "error": f"shadow_start_failed: {exc}"})
        METRICS["dlq_count"] = len(DLQ)
        return

    while not STOP.is_set():
        try:
            records = consumer_poll(base_uri)
            METRICS["shadow_polls"] += 1
            consecutive_errors = 0
            findings = normalize_shadow_records(records, source_topic)
            METRICS["shadow_findings_seen"] += len(findings)
            for f in findings:
                outcome = write_advisory_finding(f)
                METRICS["shadow_findings_written"] += 1
                if outcome == "updated":
                    METRICS["shadow_findings_upserted"] += 1
            if findings:
                print(
                    f"[shadow-consumer] {len(findings)} findings  topic={source_topic}",
                    flush=True,
                )
        except Exception as exc:
            METRICS["shadow_consumer_errors"] += 1
            consecutive_errors += 1
            is_offset_err = _is_offset_range_error(exc)
            DLQ.append({
                "ts": now_iso(), "target": source_topic,
                "error": str(exc), "consecutive_errors": consecutive_errors,
            })
            METRICS["dlq_count"] = len(DLQ)
            should_recreate = is_offset_err or consecutive_errors >= _MAX_ERRORS
            if should_recreate:
                reason = "offset_out_of_range" if is_offset_err else f"{consecutive_errors}_errors"
                print(
                    f"[shadow-consumer] WARN: consumer recovery ({reason}) topic={source_topic}",
                    flush=True,
                )
                if base_uri:
                    consumer_delete(base_uri)
                group, name = _new_ids()
                consecutive_errors = 0
                try:
                    base_uri = _setup(group, name)
                except Exception as se:
                    METRICS["shadow_consumer_errors"] += 1
                    print(f"[shadow-consumer] ERROR: recreate failed: {se}", flush=True)
                    time.sleep(5)
            else:
                time.sleep(2)


# ---------------------------------------------------------------------------
# DLQ normalization consumer — telemetry.normalization_failed
# Persists DLQ records to dlq_records table for SOC review.
# Does NOT publish to alerts.created. Does NOT create incidents.
# Replay is handled exclusively by Artisan dlq:replay command.
# ---------------------------------------------------------------------------

def dlq_consumer_fingerprint(
    dlq_event_type: str,
    source_topic: str,
    source_partition: Optional[int],
    source_offset: Optional[int],
    error_message: str,
) -> str:
    # Position-based fingerprint for event types where offset uniquely identifies the failure.
    # correlation_parse_error includes source coordinates from the structured envelope.
    position_based = (
        dlq_event_type in ("poison_message_isolated", "correlation_parse_error")
        and source_partition is not None
        and source_offset is not None
    )
    if position_based:
        material = f"{dlq_event_type}|{source_topic}|{source_partition}|{source_offset}"
    else:
        material = f"{dlq_event_type}|{(error_message or '')[:200]}"
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def normalize_dlq_records(records: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Parse DLQ records from telemetry.normalization_failed into structured dicts."""
    results: List[Dict[str, Any]] = []
    for record in records:
        value = record.get("value") or {}
        if not isinstance(value, dict):
            continue

        dlq_event_type = value.get("dlq_event_type") or "unknown"
        error_code     = value.get("error_code")
        error_message  = str(value.get("error_message") or value.get("error") or "")
        source_topic   = value.get("source_topic") or "unknown"
        source_part    = value.get("source_partition")
        source_off     = value.get("source_offset")
        consumer_group = value.get("consumer_group")
        isolated_at    = value.get("isolated_at")
        tenant_id: Optional[str] = None

        # Attempt to extract tenant_id and raw_payload depending on record type
        raw_payload: Optional[Dict[str, Any]] = None
        if dlq_event_type == "poison_message_isolated":
            # Pandaproxy serialization failure — raw bytes not recoverable
            raw_payload = None
        elif "event" in value and isinstance(value["event"], dict):
            raw_payload = value["event"]
            tenant_id = raw_payload.get("tenant_id")
        elif "record" in value and isinstance(value["record"], dict):
            # malformed record value wrapper
            raw_payload = value["record"]
        elif "raw" in value and isinstance(value["raw"], str):
            # invalid_json from file processing
            raw_payload = {"raw_text": value["raw"]}

        # Determine descriptive dlq_event_type from error field if not structured
        if dlq_event_type == "unknown" and error_message:
            if "invalid_json" in error_message:
                dlq_event_type = "invalid_json"
            elif "invalid_record_value" in error_message:
                dlq_event_type = "malformed_record"
            elif "missing_required_fields" in error_message or "normalization" in error_message.lower():
                dlq_event_type = "normalization_failure"
            elif "publish" in error_message.lower():
                dlq_event_type = "publish_failure"

        # Replayability: structural errors (can't fix by retrying) vs transient (worth retrying)
        if dlq_event_type in ("poison_message_isolated", "invalid_json", "malformed_record", "unknown"):
            replayable = False
        elif dlq_event_type in ("normalization_failure", "publish_failure"):
            replayable = raw_payload is not None
        else:
            replayable = False

        fp = dlq_consumer_fingerprint(dlq_event_type, source_topic, source_part, source_off, error_message)

        results.append({
            "dlq_event_type":   dlq_event_type,
            "source_topic":     source_topic,
            "source_partition": source_part,
            "source_offset":    source_off,
            "consumer_group":   consumer_group,
            "error_code":       int(error_code) if error_code is not None else None,
            "error_message":    error_message or None,
            "raw_payload":      json.dumps(raw_payload) if raw_payload is not None else None,
            "isolated_at":      isolated_at,
            "tenant_id":        tenant_id,
            "replayable":       replayable,
            "error_reason":     None,
            "fingerprint":      fp,
        })
    return results


def write_dlq_record(record: Dict[str, Any]) -> str:
    """Upsert a DLQ normalization record. Returns 'inserted' or 'updated'."""
    conn = connect_pg()
    if conn is None:
        return "no_db"
    now = datetime.now(timezone.utc).isoformat()
    with conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO dlq_records (
                    record_id, dlq_event_type, source_topic, source_partition, source_offset,
                    consumer_group, error_code, error_message, raw_payload, isolated_at,
                    tenant_id, replayable, error_reason, status, fingerprint, occurrence_count,
                    first_seen_at, last_seen_at, created_at, updated_at
                ) VALUES (
                    %s,%s,%s,%s,%s,
                    %s,%s,%s,%s::jsonb,%s,
                    %s,%s,%s,'new',%s,1,
                    %s,%s,now(),now()
                )
                ON CONFLICT (fingerprint) DO UPDATE SET
                    occurrence_count = dlq_records.occurrence_count + 1,
                    last_seen_at     = excluded.last_seen_at,
                    updated_at       = now()
                RETURNING (xmax = 0) AS inserted
                """,
                (
                    "dlq-" + hashlib.sha256((record["fingerprint"] + now).encode()).hexdigest()[:32],
                    record["dlq_event_type"],
                    record["source_topic"],
                    record["source_partition"],
                    record["source_offset"],
                    record["consumer_group"],
                    record["error_code"],
                    record["error_message"],
                    record["raw_payload"],
                    record["isolated_at"],
                    record["tenant_id"],
                    record.get("replayable", False),
                    record.get("error_reason"),
                    record["fingerprint"],
                    now, now,
                ),
            )
            row = cur.fetchone()
    return "inserted" if (row and row[0]) else "updated"


def dlq_consumer_event_loop() -> None:
    """Consumer loop for telemetry.normalization_failed. Runs in a daemon thread.

    Persists DLQ records to dlq_records table only.
    Never publishes to alerts.created. Never creates incidents.
    Replay runs exclusively via Artisan dlq:replay command.
    """
    source_topic = os.getenv("XDR_DLQ_TOPIC", "telemetry.normalization_failed")
    offset_reset = "earliest"
    _MAX_ERRORS  = 3

    def _new_ids() -> tuple[str, str]:
        ts = int(time.time() * 1000)
        g  = f"dlq-consumer-v1-{ts}"
        n  = f"dlq-consumer-{ts}"
        return g, n

    group, name = _new_ids()
    base_uri: Optional[str] = None
    consecutive_errors = 0

    def _setup(g: str, n: str) -> str:
        uri = consumer_create(g, n, offset_reset=offset_reset)
        consumer_subscribe(uri, source_topic)
        print(f"[dlq-consumer] ready  topic={source_topic}  group={g}", flush=True)
        return uri

    try:
        base_uri = _setup(group, name)
    except Exception as exc:
        METRICS["dlq_consumer_errors"] += 1
        DLQ.append({"ts": now_iso(), "target": source_topic, "error": f"dlq_consumer_start_failed: {exc}"})
        METRICS["dlq_count"] = len(DLQ)
        return

    while not STOP.is_set():
        try:
            records = consumer_poll(base_uri)
            METRICS["dlq_consumer_polls"] += 1
            consecutive_errors = 0
            parsed = normalize_dlq_records(records)
            METRICS["dlq_consumer_records_seen"] += len(parsed)
            for r in parsed:
                outcome = write_dlq_record(r)
                METRICS["dlq_consumer_records_written"] += 1
                if outcome == "updated":
                    METRICS["dlq_consumer_records_upserted"] += 1
            if parsed:
                print(f"[dlq-consumer] {len(parsed)} records  topic={source_topic}", flush=True)
        except Exception as exc:
            METRICS["dlq_consumer_errors"] += 1
            consecutive_errors += 1
            is_offset_err = _is_offset_range_error(exc)
            DLQ.append({
                "ts": now_iso(), "target": source_topic,
                "error": str(exc), "consecutive_errors": consecutive_errors,
            })
            METRICS["dlq_count"] = len(DLQ)
            should_recreate = is_offset_err or consecutive_errors >= _MAX_ERRORS
            if should_recreate:
                reason = "offset_out_of_range" if is_offset_err else f"{consecutive_errors}_errors"
                print(f"[dlq-consumer] WARN: consumer recovery ({reason}) topic={source_topic}", flush=True)
                if base_uri:
                    consumer_delete(base_uri)
                group, name = _new_ids()
                consecutive_errors = 0
                try:
                    base_uri = _setup(group, name)
                except Exception as se:
                    METRICS["dlq_consumer_errors"] += 1
                    print(f"[dlq-consumer] ERROR: recreate failed: {se}", flush=True)
                    time.sleep(5)
            else:
                time.sleep(2)


def normalize_pipeline_dlq_records(records: List[Dict[str, Any]], source_topic: str) -> List[Dict[str, Any]]:
    """Parse structured failure records from xdr.correlation_failed / xdr.alert_write_failed.

    Replayability classification:
    - correlation_parse_error: structural (invalid JSON at source) — not replayable
    - correlation_publish_error: transient (alert publish failed) — replayable
    - alert_write_failed with postgres_write_failed/opensearch_write_failed: transient — replayable
    - alert_write_failed with event_loop_error: unknown consumer exception — not replayable
    """
    results: List[Dict[str, Any]] = []
    for record in records:
        value = record.get("value") or {}
        if not isinstance(value, dict):
            continue

        dlq_event_type = value.get("dlq_event_type") or "unknown"
        error_message  = str(value.get("error_message") or "")
        error_reason   = value.get("reason")
        source_part    = value.get("source_partition")
        source_off     = value.get("source_offset")
        tenant_id      = value.get("tenant_id")

        if dlq_event_type == "correlation_publish_error":
            replayable = True
        elif dlq_event_type == "alert_write_failed" and error_reason in (
            "postgres_write_failed", "opensearch_write_failed"
        ):
            replayable = True
        else:
            replayable = False

        fp = dlq_consumer_fingerprint(dlq_event_type, source_topic, source_part, source_off, error_message)

        results.append({
            "dlq_event_type":   dlq_event_type,
            "source_topic":     source_topic,
            "source_partition": source_part,
            "source_offset":    source_off,
            "consumer_group":   None,
            "error_code":       None,
            "error_message":    error_message or None,
            "raw_payload":      None,
            "isolated_at":      value.get("ts"),
            "tenant_id":        tenant_id,
            "replayable":       replayable,
            "error_reason":     error_reason,
            "fingerprint":      fp,
        })
    return results


def pipeline_dlq_consumer_event_loop(source_topic: str) -> None:
    """Consumer loop for xdr.correlation_failed or xdr.alert_write_failed. Runs in a daemon thread.

    Persists structured failure records to dlq_records table for SOC review.
    Never publishes to alerts.created. Never creates incidents. Never auto-replays.
    """
    offset_reset = "earliest"
    _MAX_ERRORS  = 3

    def _new_ids() -> tuple[str, str]:
        ts   = int(time.time() * 1000)
        safe = source_topic.replace(".", "-").replace(":", "-")
        g    = f"pipeline-dlq-consumer-{safe}-{ts}"
        n    = f"pipeline-dlq-{safe}-{ts}"
        return g, n

    group, name = _new_ids()
    base_uri: Optional[str] = None
    consecutive_errors = 0

    def _setup(g: str, n: str) -> str:
        uri = consumer_create(g, n, offset_reset=offset_reset)
        consumer_subscribe(uri, source_topic)
        print(f"[pipeline-dlq-consumer] ready  topic={source_topic}  group={g}", flush=True)
        return uri

    try:
        base_uri = _setup(group, name)
    except Exception as exc:
        METRICS["pipeline_dlq_consumer_errors"] += 1
        DLQ.append({"ts": now_iso(), "target": source_topic, "error": f"pipeline_dlq_start_failed: {exc}"})
        METRICS["dlq_count"] = len(DLQ)
        return

    while not STOP.is_set():
        try:
            records = consumer_poll(base_uri)
            METRICS["pipeline_dlq_consumer_polls"] += 1
            consecutive_errors = 0
            parsed = normalize_pipeline_dlq_records(records, source_topic)
            METRICS["pipeline_dlq_consumer_records_seen"] += len(parsed)
            for r in parsed:
                outcome = write_dlq_record(r)
                METRICS["pipeline_dlq_consumer_records_written"] += 1
                if outcome == "updated":
                    METRICS["pipeline_dlq_consumer_records_upserted"] += 1
            if parsed:
                print(f"[pipeline-dlq-consumer] {len(parsed)} records  topic={source_topic}", flush=True)
        except Exception as exc:
            METRICS["pipeline_dlq_consumer_errors"] += 1
            consecutive_errors += 1
            is_offset_err = _is_offset_range_error(exc)
            DLQ.append({
                "ts": now_iso(), "target": source_topic,
                "error": str(exc), "consecutive_errors": consecutive_errors,
            })
            METRICS["dlq_count"] = len(DLQ)
            should_recreate = is_offset_err or consecutive_errors >= _MAX_ERRORS
            if should_recreate:
                reason = "offset_out_of_range" if is_offset_err else f"{consecutive_errors}_errors"
                print(
                    f"[pipeline-dlq-consumer] WARN: consumer recovery ({reason}) topic={source_topic}",
                    flush=True,
                )
                if base_uri:
                    consumer_delete(base_uri)
                group, name = _new_ids()
                consecutive_errors = 0
                try:
                    base_uri = _setup(group, name)
                except Exception as se:
                    METRICS["pipeline_dlq_consumer_errors"] += 1
                    print(f"[pipeline-dlq-consumer] ERROR: recreate failed: {se}", flush=True)
                    time.sleep(5)
            else:
                time.sleep(2)


def validate_startup_secrets() -> None:
    enforce = os.getenv("XDR_ENFORCE_INTERNAL_AUTH", "false").lower() in {"1", "true", "yes"}
    token_set = bool(os.getenv("XDR_ALERT_WRITER_INTERNAL_TOKEN", "").strip())
    if enforce:
        if not token_set:
            print("[SECURITY-FATAL] alert-writer-service: XDR_ENFORCE_INTERNAL_AUTH=true but XDR_ALERT_WRITER_INTERNAL_TOKEN is not set — refusing to start", flush=True)
            sys.exit(1)
        print("[SECURITY] alert-writer-service: internal auth enforced — /v1/write and /v1/process require X-Internal-Service-Token", flush=True)
    else:
        if not token_set:
            print("[SECURITY-WARN] alert-writer-service: XDR_ALERT_WRITER_INTERNAL_TOKEN not set — /v1/write internal auth is permissive", flush=True)
    if not os.getenv("XDR_INTERNAL_AUTH_SECRET"):
        print("[SECURITY-WARN] alert-writer-service: XDR_INTERNAL_AUTH_SECRET not set — internal auth uses fallback", flush=True)
    ingest_secret = os.getenv("XDR_INGEST_SECRET", "")
    if ingest_secret == "dev-secret-change-me":
        print("[SECURITY-WARN] alert-writer-service: XDR_INGEST_SECRET is using dev default — replace in production", flush=True)


def verify_internal_token(token: str) -> bool:
    """Verify X-Internal-Service-Token. Permissive unless XDR_ENFORCE_INTERNAL_AUTH=true."""
    enforce = os.getenv("XDR_ENFORCE_INTERNAL_AUTH", "false").lower() in {"1", "true", "yes"}
    expected = os.getenv("XDR_ALERT_WRITER_INTERNAL_TOKEN", "")
    if enforce:
        if not expected:
            return False  # enforced but not configured — startup should have caught this
        return token == expected
    if not expected:
        return True  # permissive: not configured
    return token == expected


@app.on_event("startup")
def startup() -> None:
    validate_startup_secrets()
    if os.getenv("XDR_EVENT_LOOP_ENABLED", "false").lower() in {"1", "true", "yes"}:
        threading.Thread(target=event_loop, daemon=True).start()
    if os.getenv("XDR_SHADOW_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}:
        endpoint_topic = os.getenv("XDR_SHADOW_ENDPOINT_TOPIC", "xdr.alerts.shadow.endpoint")
        network_topic  = os.getenv("XDR_SHADOW_NETWORK_TOPIC",  "xdr.alerts.shadow.network")
        threading.Thread(target=shadow_event_loop, args=(endpoint_topic,), daemon=True).start()
        threading.Thread(target=shadow_event_loop, args=(network_topic,),  daemon=True).start()
        print(f"[shadow-consumer] started  endpoint_topic={endpoint_topic}  network_topic={network_topic}", flush=True)
    if os.getenv("XDR_DLQ_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}:
        dlq_topic = os.getenv("XDR_DLQ_TOPIC", "telemetry.normalization_failed")
        threading.Thread(target=dlq_consumer_event_loop, daemon=True).start()
        print(f"[dlq-consumer] started  topic={dlq_topic}", flush=True)
    if os.getenv("XDR_CORRELATION_DLQ_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}:
        corr_failed_topic = os.getenv("XDR_CORRELATION_FAILED_TOPIC") or "xdr.correlation_failed"
        threading.Thread(target=pipeline_dlq_consumer_event_loop, args=(corr_failed_topic,), daemon=True).start()
        print(f"[pipeline-dlq-consumer] started  topic={corr_failed_topic}", flush=True)
    if os.getenv("XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED", "false").lower() in {"1", "true", "yes"}:
        write_failed_topic = os.getenv("XDR_ALERT_WRITE_FAILED_TOPIC") or "xdr.alert_write_failed"
        threading.Thread(target=pipeline_dlq_consumer_event_loop, args=(write_failed_topic,), daemon=True).start()
        print(f"[pipeline-dlq-consumer] started  topic={write_failed_topic}", flush=True)


@app.on_event("shutdown")
def shutdown() -> None:
    STOP.set()
