#!/usr/bin/env python3
"""
SOC workflow operations for incidents.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any, Dict

from realtime_detector_consumer import build_dsn_from_env, connect_db


VALID_STATUS = {"open", "triaged", "investigating", "resolved", "false_positive"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Manage incident SOC workflow")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    sub = parser.add_subparsers(dest="cmd", required=True)
    assign = sub.add_parser("assign")
    assign.add_argument("--incident-id", required=True)
    assign.add_argument("--analyst", required=True)
    note = sub.add_parser("note")
    note.add_argument("--incident-id", required=True)
    note.add_argument("--author", default="analyst")
    note.add_argument("--body", required=True)
    note.add_argument("--note-type", default="note")
    status = sub.add_parser("status")
    status.add_argument("--incident-id", required=True)
    status.add_argument("--status", required=True, choices=sorted(VALID_STATUS))
    sev = sub.add_parser("severity")
    sev.add_argument("--incident-id", required=True)
    sev.add_argument("--severity", required=True, choices=["low", "medium", "high", "critical"])
    fp = sub.add_parser("false-positive")
    fp.add_argument("--incident-id", required=True)
    fp.add_argument("--author", default="analyst")
    fp.add_argument("--reason", required=True)
    list_cmd = sub.add_parser("list")
    list_cmd.add_argument("--limit", type=int, default=20)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    _driver, conn = connect_db(dsn)
    conn.autocommit = False
    with conn.cursor() as cur:
        if args.cmd == "assign":
            cur.execute("SELECT assigned_analyst,status FROM security_incidents WHERE incident_id=%s", (args.incident_id,))
            before = cur.fetchone()
            cur.execute("UPDATE security_incidents SET assigned_analyst=%s, status='triaged', updated_at=now() WHERE incident_id=%s", (args.analyst, args.incident_id))
            cur.execute(
                "INSERT INTO security_incident_activities (incident_id, actor, activity_type, before_state, after_state, metadata, created_at, updated_at) VALUES (%s,%s,'assign',%s::jsonb,%s::jsonb,%s::jsonb,now(),now())",
                (args.incident_id, args.analyst, json.dumps({"assigned_analyst": before[0] if before else None, "status": before[1] if before else None}), json.dumps({"assigned_analyst": args.analyst, "status": "triaged"}), json.dumps({})),
            )
            cur.execute(
                "INSERT INTO security_audit_trails (occurred_at, actor, action, target_type, target_id, before_state, after_state, meta, created_at, updated_at) VALUES (now(),%s,'incident.assign','incident',%s,%s::jsonb,%s::jsonb,%s::jsonb,now(),now())",
                (args.analyst, args.incident_id, json.dumps({"assigned_analyst": before[0] if before else None}), json.dumps({"assigned_analyst": args.analyst}), json.dumps({"source": "soc_workflow"})),
            )
        elif args.cmd == "note":
            cur.execute(
                "INSERT INTO security_incident_notes (incident_id, author, note_type, body, metadata, created_at, updated_at) VALUES (%s,%s,%s,%s,%s::jsonb,now(),now())",
                (args.incident_id, args.author, args.note_type, args.body, json.dumps({})),
            )
            cur.execute(
                "INSERT INTO security_audit_trails (occurred_at, actor, action, target_type, target_id, before_state, after_state, meta, created_at, updated_at) VALUES (now(),%s,'incident.note','incident',%s,NULL,%s::jsonb,%s::jsonb,now(),now())",
                (args.author, args.incident_id, json.dumps({"note_type": args.note_type, "body": args.body}), json.dumps({"source": "soc_workflow"})),
            )
        elif args.cmd == "status":
            cur.execute("SELECT status FROM security_incidents WHERE incident_id=%s", (args.incident_id,))
            before = cur.fetchone()
            cur.execute("UPDATE security_incidents SET status=%s, updated_at=now() WHERE incident_id=%s", (args.status, args.incident_id))
            cur.execute(
                "INSERT INTO security_audit_trails (occurred_at, actor, action, target_type, target_id, before_state, after_state, meta, created_at, updated_at) VALUES (now(),'cli','incident.status','incident',%s,%s::jsonb,%s::jsonb,%s::jsonb,now(),now())",
                (args.incident_id, json.dumps({"status": before[0] if before else None}), json.dumps({"status": args.status}), json.dumps({"source": "soc_workflow"})),
            )
        elif args.cmd == "severity":
            cur.execute("SELECT severity FROM security_incidents WHERE incident_id=%s", (args.incident_id,))
            before = cur.fetchone()
            cur.execute("UPDATE security_incidents SET severity=%s, updated_at=now() WHERE incident_id=%s", (args.severity, args.incident_id))
            cur.execute(
                "INSERT INTO security_audit_trails (occurred_at, actor, action, target_type, target_id, before_state, after_state, meta, created_at, updated_at) VALUES (now(),'cli','incident.severity','incident',%s,%s::jsonb,%s::jsonb,%s::jsonb,now(),now())",
                (args.incident_id, json.dumps({"severity": before[0] if before else None}), json.dumps({"severity": args.severity}), json.dumps({"source": "soc_workflow"})),
            )
        elif args.cmd == "false-positive":
            cur.execute("UPDATE security_incidents SET status='false_positive', updated_at=now() WHERE incident_id=%s", (args.incident_id,))
            cur.execute(
                "INSERT INTO security_incident_notes (incident_id, author, note_type, body, metadata, created_at, updated_at) VALUES (%s,%s,'false_positive',%s,%s::jsonb,now(),now())",
                (args.incident_id, args.author, args.reason, json.dumps({"action": "false_positive_mark"})),
            )
            cur.execute(
                "UPDATE security_alerts SET is_suppressed=true, updated_at=now() WHERE incident_id=%s",
                (args.incident_id,),
            )
        elif args.cmd == "list":
            cur.execute(
                "SELECT incident_id,status,severity,assigned_analyst,first_seen_at,last_seen_at,title FROM security_incidents ORDER BY last_seen_at DESC LIMIT %s",
                (args.limit,),
            )
            rows = [
                {
                    "incident_id": r[0],
                    "status": r[1],
                    "severity": r[2],
                    "assigned_analyst": r[3],
                    "first_seen_at": str(r[4]),
                    "last_seen_at": str(r[5]),
                    "title": r[6],
                }
                for r in cur.fetchall()
            ]
            print(json.dumps(rows, indent=2, ensure_ascii=False))
    conn.commit()
    conn.close()
    if args.cmd != "list":
        print("workflow_update=ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
