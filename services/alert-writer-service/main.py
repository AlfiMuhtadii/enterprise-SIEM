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
import threading
import time
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse, urlunparse

import requests
from fastapi import FastAPI
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
}
SEEN: set[str] = set()
DLQ: List[Dict[str, Any]] = []
STOP = threading.Event()


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


def write_postgres(alerts: List[AlertPayload]) -> int:
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
        ))
    with conn:
        with conn.cursor() as cur:
            for row in rows:
                cur.execute(
                    """
                    INSERT INTO security_alerts (
                        alert_id, detected_at, alert_type, detector_name, detector_version,
                        severity, ip, actor_key, score, alert_fingerprint, evidence, raw_event,
                        created_at, updated_at
                    ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s::jsonb,now(),now())
                    ON CONFLICT (alert_id) DO UPDATE SET
                        updated_at=now(), evidence=excluded.evidence, raw_event=excluded.raw_event
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
            resp = requests.put(f"{url}/xdr-alerts/_doc/{alert_id(alert, fp)}", json=doc, timeout=5)
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
    resp = requests.post(
        f"{redpanda_rest()}/topics/{topic}",
        json=payload,
        headers={"Content-Type": "application/vnd.kafka.json.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()
    METRICS["events_published"] += len(events)
    return len(events)


def consumer_create(group: str, name: str) -> str:
    resp = requests.post(
        f"{redpanda_rest()}/consumers/{group}",
        json={"name": name, "format": "json", "auto.offset.reset": "earliest"},
        headers={"Content-Type": "application/vnd.kafka.v2+json", "Accept": "application/vnd.kafka.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()
    return normalize_consumer_base_uri(str(resp.json()["base_uri"]))


def consumer_subscribe(base_uri: str, topic: str) -> None:
    resp = requests.post(
        f"{base_uri}/subscription",
        json={"topics": [topic]},
        headers={"Content-Type": "application/vnd.kafka.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()


def consumer_poll(base_uri: str) -> List[Dict[str, Any]]:
    resp = requests.get(
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
        rows = value.get("alerts") if isinstance(value, dict) else None
        if rows is None:
            rows = [value.get("alert")] if isinstance(value, dict) and "alert" in value else [value]
        for row in rows:
            if isinstance(row, dict):
                alerts.append(AlertPayload(**row))
    return alerts


def process_alerts(alerts: List[AlertPayload], trace_id: Optional[str], source_topic: str) -> Dict[str, Any]:
    result = write(WriteRequest(alerts=alerts, trace_id=trace_id, source_topic=source_topic))
    created_topic = os.getenv("XDR_ALERTS_CREATED_TOPIC", "alerts.created")
    created_events = []
    for alert in alerts:
        fp = fingerprint(alert)
        payload = {
            "trace_id": trace_id,
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
            trace_id=trace_id,
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
    start_id = int(time.time())
    group = f"{os.getenv('XDR_ALERT_WRITER_GROUP', 'alert-writer-v1')}-{start_id}"
    name = f"alert-writer-{start_id}"
    try:
        base_uri = consumer_create(group, name)
        consumer_subscribe(base_uri, topic)
    except Exception as exc:
        METRICS["consumer_errors"] += 1
        DLQ.append({"ts": now_iso(), "target": topic, "error": f"consumer_start_failed: {exc}"})
        METRICS["dlq_count"] = len(DLQ)
        return
    while not STOP.is_set():
        try:
            records = consumer_poll(base_uri)
            METRICS["consumer_polls"] += 1
            alerts = normalize_records(records)
            if alerts:
                process_alerts(alerts, trace_id=f"stream-{int(time.time())}", source_topic=topic)
        except Exception as exc:
            METRICS["consumer_errors"] += 1
            DLQ.append({"ts": now_iso(), "target": topic, "error": str(exc)})
            METRICS["dlq_count"] = len(DLQ)
            time.sleep(2)


@app.get("/health")
def health() -> Dict[str, Any]:
    return {
        "status": "ok",
        "service": "alert-writer",
        "mode": "event-driven",
        "consumes": os.getenv("XDR_ALERTS_TOPIC", "xdr.alerts"),
        "produces": os.getenv("XDR_ALERTS_CREATED_TOPIC", "alerts.created"),
    }


@app.get("/metrics")
def metrics() -> Dict[str, Any]:
    METRICS["idempotency_cache_size"] = len(SEEN)
    return METRICS


@app.get("/dlq")
def dlq() -> Dict[str, Any]:
    return {"count": len(DLQ), "items": DLQ[-20:]}


@app.post("/v1/write")
def write(request: WriteRequest) -> Dict[str, Any]:
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
        written_pg = write_postgres(unique)
    except Exception as exc:
        METRICS["postgres_failures"] += len(unique)
        METRICS["retry_count"] += 1
        DLQ.append({"ts": now_iso(), "trace_id": request.trace_id, "error": str(exc), "alerts": [a.model_dump() for a in unique[:20]]})
        METRICS["dlq_count"] = len(DLQ)
    try:
        written_os = write_opensearch(unique)
    except Exception as exc:
        METRICS["opensearch_failures"] += len(unique)
        DLQ.append({"ts": now_iso(), "trace_id": request.trace_id, "error": str(exc), "target": "opensearch"})
        METRICS["dlq_count"] = len(DLQ)

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
def process(request: WriteRequest) -> Dict[str, Any]:
    return process_alerts(request.alerts, request.trace_id, request.source_topic)


@app.on_event("startup")
def startup() -> None:
    if os.getenv("XDR_EVENT_LOOP_ENABLED", "false").lower() in {"1", "true", "yes"}:
        threading.Thread(target=event_loop, daemon=True).start()


@app.on_event("shutdown")
def shutdown() -> None:
    STOP.set()
