from __future__ import annotations

import hashlib
from typing import Any


def fingerprint(alert: Any) -> str:
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


def alert_id(alert: Any, fp: str) -> str:
    return alert.alert_id or "xdr-" + fp[:40]
