#!/usr/bin/env python3
"""
Export incidents/alerts to JSONL, webhook, Slack/Discord, SIEM JSONL, or STIX-like bundle.
"""

from __future__ import annotations

import argparse
import json
import os
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Export alerts/incidents to integrations")
    parser.add_argument("--format", choices=["jsonl", "webhook", "slack", "discord", "siem", "stix"], default="jsonl")
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--output", default="reports/integration_export.jsonl")
    parser.add_argument("--url", default=os.getenv("INTEGRATION_WEBHOOK_URL", ""))
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


def fetch(conn: Any, minutes: int) -> List[Dict[str, Any]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT alert_id, detected_at, alert_type, severity, ip, actor_key, incident_id, score, evidence
            FROM security_alerts
            WHERE detected_at >= now() - (%s::text)::interval AND COALESCE(is_suppressed, false)=false
            ORDER BY detected_at DESC
            """,
            (f"{max(1, minutes)} minutes",),
        )
        return [
            {
                "alert_id": r[0],
                "detected_at": str(r[1]),
                "alert_type": r[2],
                "severity": r[3],
                "ip": r[4],
                "actor_key": r[5],
                "incident_id": r[6],
                "score": r[7],
                "evidence": parse_json(r[8]),
            }
            for r in cur.fetchall()
        ]


def stix_bundle(alerts: List[Dict[str, Any]]) -> Dict[str, Any]:
    objects = []
    for alert in alerts:
        objects.append(
            {
                "type": "indicator",
                "id": f"indicator--{alert['alert_id'][:8]}-{alert['alert_id'][8:12]}-4000-8000-{alert['alert_id'][-12:]}",
                "created": datetime.now(timezone.utc).isoformat(),
                "modified": datetime.now(timezone.utc).isoformat(),
                "name": alert["alert_type"],
                "description": f"{alert['severity']} alert linked to {alert.get('incident_id')}",
                "pattern": f"[ipv4-addr:value = '{alert.get('ip') or '0.0.0.0'}']",
                "pattern_type": "stix",
            }
        )
    return {"type": "bundle", "id": "bundle--detector-export", "objects": objects}


def post_json(url: str, payload: Dict[str, Any]) -> None:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=10) as resp:
        resp.read()


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    _driver, conn = connect_db(dsn)
    try:
        alerts = fetch(conn, args.minutes)
    finally:
        conn.close()
    out = (root / args.output).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    if args.format in {"jsonl", "siem"}:
        with out.open("w", encoding="utf-8") as f:
            for alert in alerts:
                f.write(json.dumps(alert, separators=(",", ":"), ensure_ascii=False, default=str) + "\n")
    elif args.format == "stix":
        out.write_text(json.dumps(stix_bundle(alerts), indent=2, ensure_ascii=False), encoding="utf-8")
    else:
        if not args.url:
            print("ERROR: --url or INTEGRATION_WEBHOOK_URL required for webhook/slack/discord")
            return 1
        if args.format == "slack":
            payload = {"text": f"Detector export: {len(alerts)} alerts", "attachments": [{"text": json.dumps(a, default=str)[:1500]} for a in alerts[:5]]}
        elif args.format == "discord":
            payload = {"content": f"Detector export: {len(alerts)} alerts", "embeds": [{"title": a["alert_type"], "description": json.dumps(a, default=str)[:1500]} for a in alerts[:5]]}
        else:
            payload = {"alerts": alerts}
        post_json(args.url, payload)
    print(f"alerts_exported={len(alerts)}")
    print(f"output={out if args.format in {'jsonl','siem','stix'} else args.url}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
