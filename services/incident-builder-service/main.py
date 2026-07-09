#!/usr/bin/env python3
"""Separated XDR incident builder service."""

from __future__ import annotations

import contextlib
import hashlib
import hmac
import json
import logging
import os
import sys
import threading
import time
from collections import defaultdict, deque
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse, urlunparse

import requests
from fastapi import Depends, FastAPI, Header, HTTPException

# PERF-PYTHON-HTTP: reuse a single Session so outbound HTTP calls (Pandaproxy)
# share a pooled, keep-alive connection instead of opening and tearing down a
# fresh TCP connection on every requests.<verb> call.
SESSION = requests.Session()
from pydantic import BaseModel, Field
from xdr_event_contracts import envelope, is_envelope, unwrap_payload, validate_envelope
import traceparent as tp

try:
    import psycopg
except Exception:  # pragma: no cover
    psycopg = None  # type: ignore


class _JsonLogFormatter(logging.Formatter):
    """PY-PRINT-LOGGING: structured JSON-line log output (level/timestamp/service)
    replacing plain print(), so container log aggregators can filter by severity."""

    def format(self, record: logging.LogRecord) -> str:
        return json.dumps({
            "ts": self.formatTime(record, "%Y-%m-%dT%H:%M:%S"),
            "level": record.levelname,
            "service": "incident-builder",
            "message": record.getMessage(),
        })


log = logging.getLogger("incident-builder")
log.setLevel(logging.INFO)
log.propagate = False
if not log.handlers:
    _handler = logging.StreamHandler()
    _handler.setFormatter(_JsonLogFormatter())
    log.addHandler(_handler)


SEVERITY_RANK = {"low": 1, "medium": 2, "high": 3, "critical": 4}


class AlertPayload(BaseModel):
    alert_id: str
    alert_type: str
    severity: str = "medium"
    detected_at: Optional[str] = None
    actor_key: Optional[str] = None
    ip: Optional[str] = None
    score: Optional[float] = None
    evidence: Dict[str, Any] = Field(default_factory=dict)
    trace_id: Optional[str] = None
    traceparent: Optional[str] = None
    tenant_id: Optional[str] = None


class BuildRequest(BaseModel):
    alerts: List[AlertPayload]
    trace_id: Optional[str] = None
    traceparent: Optional[str] = None
    source_topic: str = "xdr.alerts"


@contextlib.asynccontextmanager
async def lifespan(_app: "FastAPI"):
    """Modern FastAPI lifespan replacing the deprecated startup/shutdown event hooks."""
    _startup_tasks()
    try:
        yield
    finally:
        _shutdown_tasks()


app = FastAPI(title="Detector XDR Incident Builder", version="0.1.0", lifespan=lifespan)
METRICS: Dict[str, Any] = {
    "batches": 0,
    "alerts_seen": 0,
    "incidents_built": 0,
    "incident_updates": 0,
    "failures": 0,
    "dlq_count": 0,
    "idempotent_links": 0,
    "latency_ms_last": 0,
    "latency_ms_total": 0.0,
    "consumer_polls": 0,
    "consumer_errors": 0,
    "events_published": 0,
    "contract_validation_failures": 0,
    "operational_events_stored": 0,
    "internal_auth_mode": "permissive",
    "incident_write_dlq_written": 0,
    "incident_write_dlq_errors": 0,
}
# Bounded in-memory DLQ ring (MEM-UNBOUNDED-STATE) — fixed-size buffer to prevent
# unbounded memory growth / OOM during a sustained failure storm. Cap is env-tunable.
_DLQ_MAX = max(1, int(os.getenv("XDR_INCIDENT_BUILDER_DLQ_MAX", "1000")))
DLQ: "deque[Dict[str, Any]]" = deque(maxlen=_DLQ_MAX)
STOP = threading.Event()


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


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


def alert_entities(alert: AlertPayload) -> List[str]:
    evidence = alert.evidence or {}
    entities = []
    for key in ["involved_users", "involved_hosts", "involved_cloud_accounts", "involved_external_ips", "involved_email_artifacts"]:
        value = evidence.get(key) or []
        if isinstance(value, list):
            entities.extend(str(item) for item in value if item)
    for value in [alert.actor_key, alert.ip]:
        if value:
            entities.append(str(value))
    return sorted(set(entities))


def group_key(alert: AlertPayload) -> str:
    entities = alert_entities(alert)
    anchor = entities[0] if entities else alert.actor_key or alert.ip or "unknown"
    family = alert.alert_type.split("_")[0]
    return f"{family}|{anchor}"


def incident_id_for(key: str) -> str:
    return "xdr-inc-" + hashlib.sha256(key.encode("utf-8")).hexdigest()[:24]


def aggregate(group: List[AlertPayload], key: str) -> Dict[str, Any]:
    ordered = sorted(group, key=lambda a: a.detected_at or "")
    severity = max((a.severity for a in group), key=lambda s: SEVERITY_RANK.get(s, 0), default="medium")
    confidence = max((float(a.score or 0) for a in group), default=0.5)
    entities = sorted(set(item for alert in group for item in alert_entities(alert)))
    mitre = sorted(set(item for alert in group for item in (alert.evidence.get("mitre_attack") or [])))
    trace_id = next((a.trace_id for a in group if a.trace_id), None)
    # OBS-OTEL-TRACING: mint a new child span for this hop (not a passthrough
    # like trace_id) so a future OTLP collector sees a distinct incident-builder span.
    traceparent = tp.propagate(next((a.traceparent for a in group if a.traceparent), None))
    tenant_id = next((a.tenant_id for a in group if a.tenant_id), None)
    timeline = []
    for alert in ordered:
        chain = alert.evidence.get("evidence_chain") or []
        timeline.append({
            "alert_id": alert.alert_id,
            "alert_type": alert.alert_type,
            "severity": alert.severity,
            "detected_at": alert.detected_at,
            "evidence_chain": chain[:20] if isinstance(chain, list) else [],
        })
    domains = sorted(set(item for alert in group for item in (alert.evidence.get("xdr_domains") or [])))
    incident_id = incident_id_for(key)
    return {
        "incident_id": incident_id,
        "title": f"XDR grouped incident: {key}",
        "status": "open",
        "severity": severity,
        "confidence": confidence,
        "first_seen_at": ordered[0].detected_at or now_iso(),
        "last_seen_at": ordered[-1].detected_at or now_iso(),
        "affected_entities": entities,
        "timeline": timeline,
        "mitre_mapping": mitre,
        "metadata": {"source": "incident-builder-service", "group_key": key, "alert_count": len(group)},
        "xdr_domains": domains,
        "alert_ids": [a.alert_id for a in group],
        "trace_id": trace_id,
        "traceparent": traceparent,
        "tenant_id": tenant_id,
    }


def write_incidents(incidents: List[Dict[str, Any]]) -> int:
    conn = connect_pg()
    if conn is None:
        return 0
    with conn:
        with conn.cursor() as cur:
            for inc in incidents:
                cur.execute(
                    """
                    INSERT INTO security_incidents (
                        incident_id,title,status,severity,confidence,first_seen_at,last_seen_at,
                        affected_entities,timeline,mitre_mapping,metadata,xdr_domains,trace_id,tenant_id,created_at,updated_at
                    ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s::jsonb,%s::jsonb,%s::jsonb,%s::jsonb,%s,%s,now(),now())
                    ON CONFLICT (incident_id) DO UPDATE SET
                        last_seen_at=excluded.last_seen_at,
                        severity=excluded.severity,
                        confidence=GREATEST(security_incidents.confidence, excluded.confidence),
                        affected_entities=excluded.affected_entities,
                        timeline=excluded.timeline,
                        mitre_mapping=excluded.mitre_mapping,
                        metadata=excluded.metadata,
                        xdr_domains=excluded.xdr_domains,
                        trace_id=COALESCE(excluded.trace_id, security_incidents.trace_id),
                        tenant_id=COALESCE(excluded.tenant_id, security_incidents.tenant_id),
                        updated_at=now()
                    """,
                    (
                        inc["incident_id"], inc["title"], inc["status"], inc["severity"], inc["confidence"],
                        inc["first_seen_at"], inc["last_seen_at"], json.dumps(inc["affected_entities"]),
                        json.dumps(inc["timeline"]), json.dumps(inc["mitre_mapping"]), json.dumps(inc["metadata"]),
                        json.dumps(inc["xdr_domains"]), inc.get("trace_id"), inc.get("tenant_id"),
                    ),
                )
                for alert_id in inc["alert_ids"]:
                    cur.execute(
                        "INSERT INTO security_incident_alerts (incident_id,alert_id,created_at,updated_at) VALUES (%s,%s,now(),now()) ON CONFLICT DO NOTHING",
                        (inc["incident_id"], alert_id),
                    )
                    cur.execute("UPDATE security_alerts SET incident_id=%s, updated_at=now() WHERE alert_id=%s", (inc["incident_id"], alert_id))
    return len(incidents)


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
    resp = SESSION.post(
        f"{redpanda_rest()}/topics/{topic}",
        json={"records": [{"value": event} for event in events]},
        headers={"Content-Type": "application/vnd.kafka.json.v2+json"},
        timeout=10,
    )
    resp.raise_for_status()
    METRICS["events_published"] += len(events)
    return len(events)


def write_incident_failure(
    reason: str,
    error_message: str,
    source_topic: str,
    trace_id: Optional[str] = None,
    incident_count: int = 0,
) -> bool:
    """IB-DLQ-NOT-DURABLE: write a structured incident-write failure to
    xdr.incident_write_failed. Returns True on success.

    Mirrors alert-writer's write_alert_failure() — previously incident-builder
    failures lived only in the in-memory DLQ ring (lost on restart), asymmetric
    with alert-writer's durable xdr.alert_write_failed. If this write itself
    fails, the error is logged in-memory only (DLQ write failure must not
    cascade into another DLQ write).
    """
    dlq_topic = os.getenv("XDR_INCIDENT_WRITE_FAILED_TOPIC") or "xdr.incident_write_failed"
    record: Dict[str, Any] = {
        "dlq_event_type": "incident_write_failed",
        "source_topic": source_topic,
        "error_message": error_message,
        "reason": reason,
        "incident_count": incident_count,
        "ts": now_iso(),
    }
    if trace_id:
        record["trace_id"] = trace_id
    try:
        produce(dlq_topic, [record])
        METRICS["incident_write_dlq_written"] += 1
        return True
    except Exception as exc:
        METRICS["incident_write_dlq_errors"] += 1
        log.warning(f"[incident-builder] WARN: dlq write failed topic={dlq_topic}: {exc}")
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
            value = unwrap_payload(value, "alerts.created") if is_envelope(value) else value
        except ValueError:
            METRICS["contract_validation_failures"] += 1
            DLQ.append({"ts": now_iso(), "target": "alerts.created", "error": "contract_validation_failed", "event": value})
            METRICS["dlq_count"] = len(DLQ)
            continue
        row = value.get("alert") if isinstance(value, dict) and "alert" in value else value
        if isinstance(row, dict):
            if not row.get("alert_id") and isinstance(value, dict):
                row["alert_id"] = value.get("alert_id")
            if not row.get("trace_id") and isinstance(value, dict):
                row["trace_id"] = value.get("trace_id")
            if not row.get("traceparent") and isinstance(value, dict):
                row["traceparent"] = value.get("traceparent")
            try:
                alerts.append(AlertPayload(**row))
            except Exception as exc:
                # PY-POISON-RECORD-BATCH: isolate one malformed record instead of
                # letting it abort the whole poll batch (which would otherwise
                # recreate-from-earliest forever on the same poison record).
                METRICS["contract_validation_failures"] += 1
                DLQ.append({"ts": now_iso(), "target": "alerts.created", "error": f"alert_payload_invalid: {exc}", "event": row})
                METRICS["dlq_count"] = len(DLQ)
    return alerts


def process_alerts(alerts: List[AlertPayload], trace_id: Optional[str], source_topic: str) -> Dict[str, Any]:
    result = _build_incidents_core(BuildRequest(alerts=alerts, trace_id=trace_id, source_topic=source_topic))
    topic = os.getenv("XDR_INCIDENTS_TOPIC", "incidents.updated")
    events = []
    for incident in result.get("incidents", []):
        inc_trace_id = incident.get("trace_id") or trace_id
        inc_traceparent = incident.get("traceparent")
        payload = {
            "trace_id": inc_trace_id,
            "traceparent": inc_traceparent,
            "incident": incident,
            "incident_id": incident.get("incident_id"),
            "updated_at": now_iso(),
            "source": "incident-builder-service",
        }
        events.append(envelope(
            topic=topic,
            payload=payload,
            source_service="incident-builder-service",
            trace_id=inc_trace_id,
            traceparent=inc_traceparent,
            aggregate_type="incident",
            aggregate_id=payload["incident_id"],
            metadata={"source_topic": source_topic},
        ))
    try:
        for event in events:
            errors = validate_envelope(event, topic)
            if errors:
                raise ValueError(";".join(errors))
            store_operational_event(event, topic, "incident", event["payload"].get("incident_id"))
        produce(topic, events)
    except Exception as exc:
        METRICS["failures"] += len(events)
        DLQ.append({"ts": now_iso(), "target": topic, "error": str(exc), "events": events[:10]})
        METRICS["dlq_count"] = len(DLQ)
    return result


def event_loop() -> None:
    topic = os.getenv("XDR_ALERTS_CREATED_TOPIC", "alerts.created")
    offset_reset = os.getenv("XDR_INCIDENT_BUILDER_AUTO_OFFSET_RESET", "earliest")
    _MAX_ERRORS_BEFORE_RECREATE = 3

    # Stable group name: reused across restarts and across non-offset-error
    # reconnects, so Redpanda resumes from the last committed offset instead
    # of reprocessing the full topic history every time (CONSUMER-GROUP-EPHEMERAL).
    def _stable_group() -> str:
        return os.getenv("XDR_INCIDENT_BUILDER_GROUP", "incident-builder-v1")

    def _new_instance_id() -> str:
        return f"incident-builder-{int(time.time() * 1000)}"

    def _fresh_group_for_offset_reset() -> str:
        return f"{_stable_group()}-reset-{int(time.time() * 1000)}"

    group = _stable_group()
    name = _new_instance_id()
    base_uri: Optional[str] = None
    consecutive_errors = 0

    def _setup_consumer(g: str, n: str) -> str:
        uri = consumer_create(g, n, offset_reset=offset_reset)
        consumer_subscribe(uri, topic)
        log.info(
            f"[incident-builder] consumer ready  topic={topic}  group={g}"
            f"  instance={n}  auto.offset.reset={offset_reset}"
        )
        return uri

    log.info(
        f"[incident-builder] consumer starting  topic={topic}  group={group}"
        f"  instance={name}  auto.offset.reset={offset_reset}"
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
                log.info(
                    f"[incident-builder] consumed {len(records)} records → {len(alerts)} alerts"
                    f"  group={group}  topic={topic}"
                )
                batch_trace_id = next((a.trace_id for a in alerts if a.trace_id), None)
                process_alerts(alerts, trace_id=batch_trace_id, source_topic=topic)
        except Exception as exc:
            METRICS["consumer_errors"] += 1
            consecutive_errors += 1
            is_offset_err = _is_offset_range_error(exc)
            dlq_ok = write_incident_failure("event_loop_error", str(exc), topic)
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
                log.warning(
                    f"[incident-builder] WARN: consumer recovery triggered ({reason})"
                    f"  group={group}  topic={topic}  — deleting and recreating"
                )
                if base_uri:
                    consumer_delete(base_uri)
                if is_offset_err:
                    group = _fresh_group_for_offset_reset()
                name = _new_instance_id()
                consecutive_errors = 0
                try:
                    base_uri = _setup_consumer(group, name)
                except Exception as setup_exc:
                    log.error(f"[incident-builder] ERROR: consumer recreate failed: {setup_exc}")
                    METRICS["consumer_errors"] += 1
                    time.sleep(5)
            else:
                time.sleep(2)


def _auth_mode() -> str:
    return "enforced" if os.getenv("XDR_ENFORCE_INTERNAL_AUTH", "false").lower() in {"1", "true", "yes"} else "permissive"


@app.get("/health")
def health() -> Dict[str, Any]:
    auth_mode = _auth_mode()
    return {
        "status": "ok",
        "service": "incident-builder",
        "mode": "event-driven",
        "consumes": os.getenv("XDR_ALERTS_CREATED_TOPIC", "alerts.created"),
        "produces": os.getenv("XDR_INCIDENTS_TOPIC", "incidents.updated"),
        "incident_write_failed_topic": os.getenv("XDR_INCIDENT_WRITE_FAILED_TOPIC") or "xdr.incident_write_failed",
        "internal_auth_mode": auth_mode,
    }


@app.get("/metrics")
def metrics() -> Dict[str, Any]:
    METRICS["internal_auth_mode"] = _auth_mode()
    return METRICS


@app.get("/dlq")
def dlq(x_internal_service_token: Optional[str] = Header(default=None)) -> Dict[str, Any]:
    if not verify_internal_token(x_internal_service_token or ""):
        raise HTTPException(status_code=401, detail="unauthorized")
    items = [
        {k: (str(v)[:120] if k in ("event", "error") and isinstance(v, str) and len(str(v)) > 120 else v)
         for k, v in item.items()}
        for item in list(DLQ)[-20:]
    ]
    return {"count": len(DLQ), "items": items}


def _build_incidents_core(request: BuildRequest) -> Dict[str, Any]:
    """Core aggregate/persist logic — internal auth is checked only at the HTTP layer
    (the `build()` route below). PIPE-CONSUMER-AUTH-500: the event loop calls this
    directly so an internal-token check never runs against a non-HTTP caller."""
    started = time.perf_counter()
    METRICS["batches"] += 1
    METRICS["alerts_seen"] += len(request.alerts)
    groups: Dict[str, List[AlertPayload]] = defaultdict(list)
    for alert in request.alerts:
        groups[group_key(alert)].append(alert)
    incidents = [aggregate(group, key) for key, group in groups.items()]
    written = 0
    try:
        written = write_incidents(incidents)
    except Exception as exc:
        METRICS["failures"] += len(incidents)
        DLQ.append({"ts": now_iso(), "trace_id": request.trace_id, "error": str(exc), "incidents": incidents[:10]})
        METRICS["dlq_count"] = len(DLQ)
        write_incident_failure("postgres_write_failed", str(exc), request.source_topic, request.trace_id, len(incidents))
    METRICS["incidents_built"] += len(incidents)
    METRICS["incident_updates"] += written
    elapsed = (time.perf_counter() - started) * 1000
    METRICS["latency_ms_last"] = round(elapsed, 3)
    METRICS["latency_ms_total"] += elapsed
    return {
        "ok": True,
        "trace_id": request.trace_id,
        "source_topic": request.source_topic,
        "alerts": len(request.alerts),
        "incidents": incidents,
        "incident_count": len(incidents),
        "postgres_written": written,
        "dlq_count": len(DLQ),
        "latency_ms": round(elapsed, 3),
        "dry_run": connect_pg() is None,
    }


@app.post("/v1/build")
def build(
    request: BuildRequest,
    x_internal_service_token: Optional[str] = Header(default=None),
) -> Dict[str, Any]:
    if not verify_internal_token(x_internal_service_token or ""):
        raise HTTPException(status_code=401, detail="unauthorized")
    return _build_incidents_core(request)


@app.post("/v1/process")
def process(
    request: BuildRequest,
    x_internal_service_token: Optional[str] = Header(default=None),
) -> Dict[str, Any]:
    if not verify_internal_token(x_internal_service_token or ""):
        raise HTTPException(status_code=401, detail="unauthorized")
    return process_alerts(request.alerts, request.trace_id, request.source_topic)


def validate_startup_secrets() -> None:
    enforce = os.getenv("XDR_ENFORCE_INTERNAL_AUTH", "false").lower() in {"1", "true", "yes"}
    token_set = bool(os.getenv("XDR_INCIDENT_BUILDER_INTERNAL_TOKEN", "").strip())
    if enforce:
        if not token_set:
            log.error("[SECURITY-FATAL] incident-builder-service: XDR_ENFORCE_INTERNAL_AUTH=true but XDR_INCIDENT_BUILDER_INTERNAL_TOKEN is not set — refusing to start")
            sys.exit(1)
        log.info("[SECURITY] incident-builder-service: internal auth enforced — /v1/build and /v1/process require X-Internal-Service-Token")
    else:
        if not token_set:
            log.warning("[SECURITY-WARN] incident-builder-service: XDR_INCIDENT_BUILDER_INTERNAL_TOKEN not set — /v1/build internal auth is permissive")
    if not os.getenv("XDR_INTERNAL_AUTH_SECRET"):
        log.warning("[SECURITY-WARN] incident-builder-service: XDR_INTERNAL_AUTH_SECRET not set — internal auth uses fallback")


def verify_internal_token(token: str) -> bool:
    """Verify X-Internal-Service-Token. Permissive unless XDR_ENFORCE_INTERNAL_AUTH=true."""
    enforce = os.getenv("XDR_ENFORCE_INTERNAL_AUTH", "false").lower() in {"1", "true", "yes"}
    expected = os.getenv("XDR_INCIDENT_BUILDER_INTERNAL_TOKEN", "")
    if enforce:
        if not expected:
            return False  # enforced but not configured — startup should have caught this
        return hmac.compare_digest(token.encode("utf-8"), expected.encode("utf-8"))
    if not expected:
        return True  # permissive: not configured
    return hmac.compare_digest(token.encode("utf-8"), expected.encode("utf-8"))


def _startup_tasks() -> None:
    validate_startup_secrets()
    if os.getenv("XDR_EVENT_LOOP_ENABLED", "false").lower() in {"1", "true", "yes"}:
        threading.Thread(target=event_loop, daemon=True).start()


def _shutdown_tasks() -> None:
    STOP.set()
