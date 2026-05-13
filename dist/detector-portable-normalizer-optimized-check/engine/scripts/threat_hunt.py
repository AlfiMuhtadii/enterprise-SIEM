#!/usr/bin/env python3
"""
Threat hunting queries over telemetry_events or normalized JSONL.
"""

from __future__ import annotations

import argparse
import json
import os
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Dict, Iterable, List

from realtime_detector_consumer import build_dsn_from_env, connect_db
from telemetry_correlation_detector import parse_dt


def load_jsonl(path: Path) -> List[Dict[str, Any]]:
    rows = []
    with path.open("r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if not line.strip():
                continue
            data = json.loads(line)
            if isinstance(data, dict):
                rows.append(data)
    return rows


def fetch_db(minutes: int, dsn: str) -> List[Dict[str, Any]]:
    root = Path(__file__).resolve().parents[1]
    _driver, conn = connect_db(dsn or build_dsn_from_env(root))
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT ts,event_id,telemetry_type,event_type,host_id,src_ip,dst_ip,dst_port,protocol,process_name,user_name_hash,payload
            FROM telemetry_events
            WHERE ts >= now() - (%s::text)::interval
            ORDER BY ts ASC
            """,
            (f"{max(1, minutes)} minutes",),
        )
        rows = []
        for r in cur.fetchall():
            payload = r[11] if isinstance(r[11], dict) else {}
            if isinstance(r[11], str):
                try:
                    payload = json.loads(r[11])
                except json.JSONDecodeError:
                    payload = {}
            rows.append({
                "ts": str(r[0]),
                "event_id": r[1],
                "telemetry_type": r[2],
                "event_type": r[3],
                "host_id": r[4],
                "src_ip": r[5],
                "dst_ip": r[6],
                "dst_port": r[7],
                "protocol": r[8],
                "process_name": r[9],
                "user_name_hash": r[10],
                "raw": payload,
                "query": payload.get("query") if isinstance(payload, dict) else None,
            })
    conn.close()
    return rows


def hunt_powershell_network(events: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    return [
        ev for ev in events
        if str(ev.get("process_name") or "").lower().endswith("powershell.exe")
        and ev.get("telemetry_type") == "network"
    ][:100]


def hunt_repeated_dns(events: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    counter: Counter[tuple[str, str]] = Counter()
    for ev in events:
        query = str(ev.get("query") or (ev.get("raw") or {}).get("query") or "").lower()
        host = str(ev.get("host_id") or ev.get("src_ip") or "unknown")
        if query:
            counter[(host, query)] += 1
    return [{"host": h, "query": q, "count": c} for (h, q), c in counter.items() if c >= 2]


def hunt_admin_fanout(events: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    admin_ports = {22, 135, 139, 445, 3389, 5985, 5986}
    by_src: Dict[str, set[str]] = defaultdict(set)
    for ev in events:
        if ev.get("dst_port") in admin_ports and ev.get("src_ip") and ev.get("dst_ip"):
            by_src[str(ev["src_ip"])].add(str(ev["dst_ip"]))
    return [{"src_ip": src, "unique_admin_dsts": len(dsts), "dst_ips": sorted(dsts)[:20]} for src, dsts in by_src.items() if len(dsts) >= 3]


def hunt_failed_logins(events: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    counter: Counter[str] = Counter()
    for ev in events:
        if ev.get("event_type") == "login_failed" and ev.get("src_ip"):
            counter[str(ev["src_ip"])] += 1
    return [{"src_ip": src, "failed_logins": total} for src, total in counter.items() if total >= 2]


def main() -> int:
    parser = argparse.ArgumentParser(description="Run threat hunting queries")
    parser.add_argument("--jsonl", default="")
    parser.add_argument("--minutes", type=int, default=1440)
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--output", default="reports/threat_hunt_report.json")
    args = parser.parse_args()
    events = load_jsonl(Path(args.jsonl)) if args.jsonl else fetch_db(args.minutes, args.dsn)
    report = {
        "events": len(events),
        "hunts": {
            "powershell_network": hunt_powershell_network(events),
            "repeated_dns": hunt_repeated_dns(events),
            "admin_port_fanout": hunt_admin_fanout(events),
            "failed_login_sources": hunt_failed_logins(events),
        },
    }
    report["summary"] = {name: len(rows) for name, rows in report["hunts"].items()}
    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report["summary"], indent=2))
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

