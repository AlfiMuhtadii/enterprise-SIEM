#!/usr/bin/env python3
"""
Build an entity graph and HTML visualization from telemetry events and alerts.
Entities: host, user, process, domain, IP, session.
"""

from __future__ import annotations

import argparse
import html
import json
import os
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Set, Tuple

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build telemetry entity graph and evidence HTML report")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--output-json", default="reports/entity_graph.json")
    parser.add_argument("--output-html", default="reports/evidence_graph.html")
    return parser.parse_args()


def parse_payload(value: Any) -> Dict[str, Any]:
    if isinstance(value, dict):
        return value
    if isinstance(value, str):
        try:
            data = json.loads(value)
            return data if isinstance(data, dict) else {}
        except json.JSONDecodeError:
            return {}
    return {}


def fetch(conn: Any, minutes: int) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, ts, event_id, telemetry_type, event_type, host_id, src_ip, dst_ip,
                   dst_port, protocol, process_name, user_name_hash, payload
            FROM telemetry_events
            WHERE ts >= now() - (%s::text)::interval
            ORDER BY ts ASC
            """,
            (f"{max(1, minutes)} minutes",),
        )
        events = [
            {
                "id": r[0],
                "ts": str(r[1]),
                "event_id": r[2],
                "telemetry_type": r[3],
                "event_type": r[4],
                "host_id": r[5],
                "src_ip": r[6],
                "dst_ip": r[7],
                "dst_port": r[8],
                "protocol": r[9],
                "process_name": r[10],
                "user_name_hash": r[11],
                "payload": parse_payload(r[12]),
            }
            for r in cur.fetchall()
        ]
        cur.execute(
            """
            SELECT alert_id, detected_at, alert_type, severity, ip, actor_key, evidence
            FROM security_alerts
            WHERE detected_at >= now() - (%s::text)::interval
            ORDER BY detected_at ASC
            """,
            (f"{max(1, minutes)} minutes",),
        )
        alerts = [
            {
                "alert_id": r[0],
                "detected_at": str(r[1]),
                "alert_type": r[2],
                "severity": r[3],
                "ip": r[4],
                "actor_key": r[5],
                "evidence": parse_payload(r[6]),
            }
            for r in cur.fetchall()
        ]
    return events, alerts


def node_id(kind: str, value: Any) -> str:
    return f"{kind}:{value}"


def add_node(nodes: Dict[str, Dict[str, Any]], kind: str, value: Any) -> str:
    val = str(value or "").strip()
    if not val:
        return ""
    nid = node_id(kind, val)
    nodes.setdefault(nid, {"id": nid, "kind": kind, "label": val, "weight": 0})
    nodes[nid]["weight"] += 1
    return nid


def add_edge(edges: Dict[Tuple[str, str, str], Dict[str, Any]], src: str, dst: str, rel: str, event: Dict[str, Any]) -> None:
    if not src or not dst:
        return
    key = (src, dst, rel)
    edge = edges.setdefault(key, {"source": src, "target": dst, "relationship": rel, "count": 0, "events": []})
    edge["count"] += 1
    if len(edge["events"]) < 10:
        edge["events"].append({"ts": event.get("ts"), "event_id": event.get("event_id"), "event_type": event.get("event_type")})


def build_graph(events: List[Dict[str, Any]], alerts: List[Dict[str, Any]]) -> Dict[str, Any]:
    nodes: Dict[str, Dict[str, Any]] = {}
    edges: Dict[Tuple[str, str, str], Dict[str, Any]] = {}
    for ev in events:
        payload = ev.get("payload") if isinstance(ev.get("payload"), dict) else {}
        host = add_node(nodes, "host", ev.get("host_id"))
        src_ip = add_node(nodes, "ip", ev.get("src_ip"))
        dst_ip = add_node(nodes, "ip", ev.get("dst_ip"))
        proc = add_node(nodes, "process", ev.get("process_name"))
        user = add_node(nodes, "user", ev.get("user_name_hash"))
        domain = add_node(nodes, "domain", payload.get("query") or ev.get("query"))
        session = add_node(nodes, "session", payload.get("session_id") or payload.get("uid") or ev.get("event_id"))
        add_edge(edges, host, proc, "executed_process", ev)
        add_edge(edges, host, src_ip, "has_ip", ev)
        add_edge(edges, proc, dst_ip, "connected_to", ev)
        add_edge(edges, src_ip, dst_ip, f"network:{ev.get('dst_port')}", ev)
        add_edge(edges, host, domain, "queried_domain", ev)
        add_edge(edges, user, host, "used_host", ev)
        add_edge(edges, session, host, "session_on_host", ev)
    for alert in alerts:
        alert_node = add_node(nodes, "alert", f"{alert['alert_type']}:{alert['alert_id'][:8]}")
        actor = add_node(nodes, "actor", alert.get("actor_key") or alert.get("ip"))
        add_edge(edges, alert_node, actor, "alert_actor", {"ts": alert.get("detected_at"), "event_id": alert.get("alert_id"), "event_type": alert.get("alert_type")})
        chain = alert.get("evidence", {}).get("evidence_chain", [])
        if isinstance(chain, list):
            prev = alert_node
            for item in chain[:12]:
                ev_node = add_node(nodes, "event", item.get("event_id") or item.get("db_id"))
                add_edge(edges, prev, ev_node, "evidence_chain", {"ts": item.get("ts"), "event_id": item.get("event_id"), "event_type": item.get("event_type")})
                prev = ev_node
    return {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "summary": {"nodes": len(nodes), "edges": len(edges), "events": len(events), "alerts": len(alerts)},
        "nodes": list(nodes.values()),
        "edges": list(edges.values()),
        "alerts": alerts,
    }


def write_html(graph: Dict[str, Any], path: Path) -> None:
    data = html.escape(json.dumps(graph, indent=2, ensure_ascii=False))
    rows = "\n".join(
        f"<tr><td>{html.escape(a['detected_at'])}</td><td>{html.escape(a['alert_type'])}</td><td>{html.escape(a['severity'])}</td><td>{html.escape(str(a.get('actor_key') or ''))}</td></tr>"
        for a in graph.get("alerts", [])[:100]
    )
    content = f"""<!doctype html>
<html><head><meta charset="utf-8"><title>Detector Evidence Graph</title>
<style>
body{{font-family:Segoe UI,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:24px}}
.grid{{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}}.card{{background:#111827;border:1px solid #334155;padding:14px;border-radius:10px}}
table{{width:100%;border-collapse:collapse;margin-top:16px}}td,th{{border-bottom:1px solid #334155;padding:8px;text-align:left}}
pre{{white-space:pre-wrap;background:#020617;padding:16px;border-radius:10px;max-height:540px;overflow:auto}}
</style></head><body>
<h1>Detector Evidence Visualization</h1>
<div class="grid">
<div class="card"><b>Nodes</b><br>{graph['summary']['nodes']}</div>
<div class="card"><b>Edges</b><br>{graph['summary']['edges']}</div>
<div class="card"><b>Events</b><br>{graph['summary']['events']}</div>
<div class="card"><b>Alerts</b><br>{graph['summary']['alerts']}</div>
</div>
<h2>Timeline / MITRE Flow / Correlation Tree</h2>
<table><tr><th>Time</th><th>Alert</th><th>Severity</th><th>Actor</th></tr>{rows}</table>
<h2>Entity Relationship Graph Data</h2>
<pre id="graph">{data}</pre>
</body></html>"""
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    _driver, conn = connect_db(dsn)
    try:
        events, alerts = fetch(conn, args.minutes)
    finally:
        conn.close()
    graph = build_graph(events, alerts)
    out_json = (root / args.output_json).resolve()
    out_html = (root / args.output_html).resolve()
    out_json.parent.mkdir(parents=True, exist_ok=True)
    out_json.write_text(json.dumps(graph, indent=2, ensure_ascii=False), encoding="utf-8")
    write_html(graph, out_html)
    print(json.dumps(graph["summary"], indent=2))
    print(f"json={out_json}")
    print(f"html={out_html}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
