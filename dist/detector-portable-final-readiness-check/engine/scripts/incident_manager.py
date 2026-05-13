#!/usr/bin/env python3
"""
Create and update SOC incidents from related alerts.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Set

from realtime_detector_consumer import build_dsn_from_env, connect_db


SEVERITY_RANK = {"low": 1, "medium": 2, "high": 3, "critical": 4}
SLA_HOURS = {"critical": 4, "high": 12, "medium": 24, "low": 72}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build incidents from related alerts")
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    return parser.parse_args()


def parse_json(value: Any) -> Dict[str, Any]:
    if isinstance(value, dict):
        return value
    if isinstance(value, str):
        try:
            data = json.loads(value)
            return data if isinstance(data, dict) else {}
        except json.JSONDecodeError:
            return {}
    return {}


def incident_key(alert: Dict[str, Any]) -> str:
    actor = alert.get("actor_key") or alert.get("ip") or "unknown"
    mitre = ",".join(sorted(alert.get("mitre", []))) or alert.get("alert_type", "unknown")
    return f"{actor}|{mitre}"


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    driver, conn = connect_db(dsn)
    conn.autocommit = False
    created = linked = 0
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT alert_id, detected_at, alert_type, severity, ip, actor_key, score, evidence
            FROM security_alerts
            WHERE detected_at >= now() - (%s::text)::interval AND COALESCE(is_suppressed, false)=false
            ORDER BY detected_at ASC
            """,
            (f"{max(1, args.minutes)} minutes",),
        )
        alerts: List[Dict[str, Any]] = []
        for r in cur.fetchall():
            evidence = parse_json(r[7])
            mitre = []
            for item in evidence.get("mitre_attack", []):
                if isinstance(item, dict) and item.get("technique"):
                    mitre.append(str(item["technique"]))
            alerts.append(
                {
                    "alert_id": r[0],
                    "detected_at": r[1],
                    "alert_type": r[2],
                    "severity": r[3],
                    "ip": r[4],
                    "actor_key": r[5],
                    "score": r[6],
                    "evidence": evidence,
                    "mitre": mitre,
                }
            )
        groups: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
        for alert in alerts:
            groups[incident_key(alert)].append(alert)
        for key, group in groups.items():
            incident_id = "INC-" + hashlib.sha256(key.encode("utf-8")).hexdigest()[:16]
            severest = max(group, key=lambda a: SEVERITY_RANK.get(str(a["severity"]), 0))
            confidence = max(float(a.get("score") or 0.0) for a in group)
            first_seen = min(a["detected_at"] for a in group)
            last_seen = max(a["detected_at"] for a in group)
            entities: Set[str] = set()
            mitre: Set[str] = set()
            timeline = []
            for alert in group:
                entities.add(str(alert.get("actor_key") or alert.get("ip") or "unknown"))
                mitre.update(alert.get("mitre", []))
                timeline.append({"ts": str(alert["detected_at"]), "alert_id": alert["alert_id"], "alert_type": alert["alert_type"], "severity": alert["severity"]})
            title = f"{severest['alert_type']} involving {next(iter(entities))}"
            sla_due_at = last_seen + __import__("datetime").timedelta(hours=SLA_HOURS.get(str(severest["severity"]), 24))
            cur.execute(
                """
                INSERT INTO security_incidents (
                    incident_id, title, status, severity, confidence, assigned_analyst, sla_due_at, escalation_level, first_seen_at, last_seen_at,
                    affected_entities, timeline, mitre_mapping, metadata, created_at, updated_at
                ) VALUES (%s,%s,'open',%s,%s,NULL,%s,0,%s,%s,%s::jsonb,%s::jsonb,%s::jsonb,%s::jsonb,now(),now())
                ON CONFLICT (incident_id) DO UPDATE SET
                    last_seen_at=excluded.last_seen_at,
                    severity=excluded.severity,
                    confidence=GREATEST(security_incidents.confidence, excluded.confidence),
                    sla_due_at=CASE WHEN security_incidents.status IN ('resolved','false_positive') THEN security_incidents.sla_due_at ELSE excluded.sla_due_at END,
                    affected_entities=excluded.affected_entities,
                    timeline=excluded.timeline,
                    mitre_mapping=excluded.mitre_mapping,
                    updated_at=now()
                """,
                (
                    incident_id,
                    title[:200],
                    severest["severity"],
                    confidence,
                    sla_due_at,
                    first_seen,
                    last_seen,
                    json.dumps(sorted(entities)),
                    json.dumps(timeline, default=str),
                    json.dumps(sorted(mitre)),
                    json.dumps({"group_key": key, "alert_count": len(group)}),
                ),
            )
            created += 1
            cur.execute(
                """
                INSERT INTO security_incident_activities (
                    incident_id, actor, activity_type, before_state, after_state, metadata, created_at, updated_at
                ) VALUES (%s,'system','incident_upsert',NULL,%s::jsonb,%s::jsonb,now(),now())
                """,
                (
                    incident_id,
                    json.dumps({"severity": severest["severity"], "confidence": confidence, "last_seen_at": str(last_seen)}),
                    json.dumps({"alert_count": len(group), "source": "incident_manager"}),
                ),
            )
            cur.execute(
                """
                INSERT INTO security_audit_trails (
                    occurred_at, actor, action, target_type, target_id, before_state, after_state, meta, created_at, updated_at
                ) VALUES (now(),'system','incident.upsert','incident',%s,NULL,%s::jsonb,%s::jsonb,now(),now())
                """,
                (
                    incident_id,
                    json.dumps({"severity": severest["severity"], "confidence": confidence}),
                    json.dumps({"source": "incident_manager", "alert_count": len(group)}),
                ),
            )
            for alert in group:
                cur.execute(
                    "INSERT INTO security_incident_alerts (incident_id, alert_id, created_at, updated_at) VALUES (%s,%s,now(),now()) ON CONFLICT DO NOTHING",
                    (incident_id, alert["alert_id"]),
                )
                cur.execute("UPDATE security_alerts SET incident_id=%s, updated_at=now() WHERE alert_id=%s", (incident_id, alert["alert_id"]))
                linked += 1
    conn.commit()
    conn.close()
    print(f"incidents_upserted={created}")
    print(f"alerts_linked={linked}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
