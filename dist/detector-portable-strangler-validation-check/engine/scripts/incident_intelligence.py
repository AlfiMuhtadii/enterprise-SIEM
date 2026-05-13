#!/usr/bin/env python3
"""
Generate incident intelligence summary from alerts, evidence, entities, and MITRE.
"""

from __future__ import annotations

import argparse
import json
import os
from collections import Counter
from pathlib import Path
from typing import Any, Dict, List

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_json(value: Any) -> Any:
    if isinstance(value, (dict, list)):
        return value
    if isinstance(value, str):
        try:
            return json.loads(value)
        except json.JSONDecodeError:
            return None
    return None


def fetch(incident_id: str, dsn: str) -> Dict[str, Any]:
    root = Path(__file__).resolve().parents[1]
    _driver, conn = connect_db(dsn or build_dsn_from_env(root))
    with conn.cursor() as cur:
        cur.execute("SELECT * FROM security_incidents WHERE incident_id=%s", (incident_id,))
        incident_row = cur.fetchone()
        incident_cols = [d[0] for d in cur.description] if incident_row else []
        incident = dict(zip(incident_cols, incident_row)) if incident_row else {}
        cur.execute("SELECT alert_type,severity,ip,score,evidence,detected_at FROM security_alerts WHERE incident_id=%s ORDER BY detected_at ASC", (incident_id,))
        alerts = [{"alert_type": r[0], "severity": r[1], "ip": r[2], "score": r[3], "evidence": parse_json(r[4]) or {}, "detected_at": str(r[5])} for r in cur.fetchall()]
        cur.execute("SELECT author,note_type,body,created_at FROM security_incident_notes WHERE incident_id=%s ORDER BY created_at ASC", (incident_id,))
        notes = [{"author": r[0], "note_type": r[1], "body": r[2], "created_at": str(r[3])} for r in cur.fetchall()]
    conn.close()
    return {"incident": incident, "alerts": alerts, "notes": notes}


def summarize(data: Dict[str, Any]) -> Dict[str, Any]:
    alerts = data["alerts"]
    ips = sorted({a["ip"] for a in alerts if a.get("ip")})
    alert_types = Counter(a["alert_type"] for a in alerts)
    severities = Counter(a["severity"] for a in alerts)
    techniques = []
    evidence_events = []
    for alert in alerts:
        ev = alert.get("evidence") if isinstance(alert.get("evidence"), dict) else {}
        for item in ev.get("mitre_attack", []):
            if isinstance(item, dict) and item.get("technique"):
                techniques.append(item["technique"])
        for item in ev.get("evidence_chain", []):
            if isinstance(item, dict):
                evidence_events.append(item)
    recommendations = []
    if "BRUTE_FORCE_IP" in alert_types or "CREDENTIAL_STUFFING" in alert_types:
        recommendations.append("Review authentication logs, lock suspicious accounts, and verify MFA coverage.")
    if "C2_DNS_BEACON_PATTERN" in alert_types:
        recommendations.append("Isolate affected host, collect DNS/process evidence, and block suspicious domain/IP.")
    if "LATERAL_MOVEMENT_SUSPECTED" in alert_types:
        recommendations.append("Validate remote admin activity, check privileged account use, and inspect neighboring hosts.")
    if not recommendations:
        recommendations.append("Review evidence chain, validate business context, and decide triage outcome.")
    return {
        "incident_id": data["incident"].get("incident_id"),
        "title": data["incident"].get("title"),
        "status": data["incident"].get("status"),
        "severity": data["incident"].get("severity"),
        "confidence": data["incident"].get("confidence"),
        "alert_count": len(alerts),
        "alert_types": dict(alert_types),
        "severity_distribution": dict(severities),
        "iocs": {"ips": ips, "techniques": sorted(set(techniques))},
        "evidence_event_count": len(evidence_events),
        "first_alert": alerts[0]["detected_at"] if alerts else None,
        "last_alert": alerts[-1]["detected_at"] if alerts else None,
        "recommendations": recommendations,
        "notes_count": len(data["notes"]),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate incident intelligence report")
    parser.add_argument("--incident-id", required=True)
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--output", default="")
    args = parser.parse_args()
    data = fetch(args.incident_id, args.dsn)
    report = {"summary": summarize(data), "raw": data}
    out = Path(args.output or f"reports/incidents/{args.incident_id}_intelligence.json")
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False, default=str), encoding="utf-8")
    print(json.dumps(report["summary"], indent=2, ensure_ascii=False, default=str))
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

