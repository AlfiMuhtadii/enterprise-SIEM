"""Incident grouping/aggregation logic for incident-builder-service.

Extracted from main.py (CODE-STRUCT-DECOMPOSE): the alert-to-incident
grouping and aggregation logic is pure (no FastAPI/Pandaproxy/DB
dependency), so it is independently unit-testable, matching the
alert-writer-service/alert_identity.py precedent for this codebase. Alert
objects are duck-typed (Any) rather than importing AlertPayload from
main.py, avoiding a circular import -- same technique alert_identity.py
already uses.
"""
from __future__ import annotations

import hashlib
from datetime import datetime, timezone
from typing import Any, Dict, List

import traceparent as tp

SEVERITY_RANK = {"low": 1, "medium": 2, "high": 3, "critical": 4}


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def alert_entities(alert: Any) -> List[str]:
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


def group_key(alert: Any) -> str:
    entities = alert_entities(alert)
    anchor = entities[0] if entities else alert.actor_key or alert.ip or "unknown"
    family = alert.alert_type.split("_")[0]
    return f"{family}|{anchor}"


def incident_id_for(key: str) -> str:
    return "xdr-inc-" + hashlib.sha256(key.encode("utf-8")).hexdigest()[:24]


def aggregate(group: List[Any], key: str) -> Dict[str, Any]:
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
