from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from typing import Any, Dict, Iterable, List, Optional


SCHEMA_VERSION = 1

EVENT_TYPES = {
    "xdr.alerts": "xdr.alert.raised",
    "alerts.created": "alert.created",
    "incidents.updated": "incident.updated",
    "ai.analysis.requests": "ai.analysis.requested",
    "ai.analysis.results": "ai.analysis.resulted",
    "ai.analysis.completed": "ai.analysis.completed",
}


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def stable_event_id(event_type: str, payload: Dict[str, Any], trace_id: Optional[str] = None) -> str:
    material = json.dumps(
        {"event_type": event_type, "payload": payload, "trace_id": trace_id},
        sort_keys=True,
        separators=(",", ":"),
        default=str,
    )
    return f"evt-{hashlib.sha256(material.encode('utf-8')).hexdigest()[:40]}"


def envelope(
    topic: str,
    payload: Dict[str, Any],
    source_service: str,
    trace_id: Optional[str] = None,
    traceparent: Optional[str] = None,
    aggregate_type: Optional[str] = None,
    aggregate_id: Optional[str] = None,
    metadata: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    event_type = EVENT_TYPES.get(topic, topic)
    meta = dict(metadata or {})
    if aggregate_type:
        meta["aggregate_type"] = aggregate_type
    if aggregate_id:
        meta["aggregate_id"] = aggregate_id
    id_payload = payload
    if aggregate_id:
        id_payload = {"aggregate_type": aggregate_type, "aggregate_id": aggregate_id}
    return {
        "event_id": stable_event_id(event_type, id_payload, trace_id),
        "event_type": event_type,
        "schema_version": SCHEMA_VERSION,
        "occurred_at": now_iso(),
        "trace_id": trace_id,
        # traceparent is not part of stable_event_id's hash material: it mints a
        # fresh span-id on every propagate() call, so including it would break
        # idempotent event_id generation for an otherwise-identical replay.
        "traceparent": traceparent,
        "source_service": source_service,
        "payload": payload,
        "metadata": meta,
    }


def is_envelope(value: Any) -> bool:
    return isinstance(value, dict) and "event_type" in value and "schema_version" in value and "payload" in value


def validate_envelope(value: Dict[str, Any], expected_topic: Optional[str] = None) -> List[str]:
    errors: List[str] = []
    for key in ["event_id", "event_type", "schema_version", "occurred_at", "source_service", "payload"]:
        if key not in value:
            errors.append(f"missing:{key}")
    if value.get("schema_version") != SCHEMA_VERSION:
        errors.append(f"unsupported_schema_version:{value.get('schema_version')}")
    if expected_topic and value.get("event_type") != EVENT_TYPES.get(expected_topic, expected_topic):
        errors.append(f"unexpected_event_type:{value.get('event_type')}")
    if not isinstance(value.get("payload"), dict):
        errors.append("payload_not_object")
    return errors


def unwrap_payload(value: Dict[str, Any], expected_topic: Optional[str] = None) -> Dict[str, Any]:
    if not is_envelope(value):
        return value
    errors = validate_envelope(value, expected_topic)
    if errors:
        raise ValueError(";".join(errors))
    return dict(value["payload"])


def records_from_payloads(topic: str, payloads: Iterable[Dict[str, Any]], source_service: str, trace_id: Optional[str] = None) -> List[Dict[str, Any]]:
    return [
        envelope(topic=topic, payload=payload, source_service=source_service, trace_id=trace_id)
        for payload in payloads
    ]
