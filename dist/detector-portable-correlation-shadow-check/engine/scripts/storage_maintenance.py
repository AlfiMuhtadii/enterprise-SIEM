#!/usr/bin/env python3
"""
Storage scalability operations: retention, archive, cleanup, and query stats.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
from pathlib import Path
from typing import Any, Iterable

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Maintain telemetry/alert storage")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--retention-days", type=int, default=30)
    parser.add_argument("--archive", action="store_true")
    parser.add_argument("--cleanup", action="store_true")
    parser.add_argument("--stats", action="store_true")
    parser.add_argument("--archive-dir", default="storage/archive")
    parser.add_argument("--batch-size", type=int, default=10000)
    return parser.parse_args()


def dump_rows(path: Path, columns: list[str], rows: Iterable[Any]) -> int:
    path.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with path.open("w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(columns)
        for row in rows:
            writer.writerow(row)
            count += 1
    return count


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    _driver, conn = connect_db(dsn)
    conn.autocommit = False
    archive_dir = (root / args.archive_dir).resolve()
    report: dict[str, Any] = {}
    with conn.cursor() as cur:
        if args.stats:
            cur.execute("SELECT count(*), min(ts), max(ts) FROM telemetry_events")
            report["telemetry_events"] = [str(x) for x in cur.fetchone()]
            cur.execute("SELECT count(*), min(detected_at), max(detected_at) FROM security_alerts")
            report["security_alerts"] = [str(x) for x in cur.fetchone()]
            cur.execute("SELECT telemetry_type, count(*) FROM telemetry_events GROUP BY telemetry_type ORDER BY count(*) DESC")
            report["telemetry_by_type"] = cur.fetchall()
        if args.archive:
            cur.execute(
                "SELECT id, ts, event_id, telemetry_type, event_type, host_id, src_ip, dst_ip, dst_port, process_name, payload FROM telemetry_events WHERE ts < now() - (%s::text)::interval LIMIT %s",
                (f"{args.retention_days} days", args.batch_size),
            )
            rows = cur.fetchall()
            report["archived_telemetry_rows"] = dump_rows(archive_dir / "telemetry_events_archive.csv", ["id", "ts", "event_id", "telemetry_type", "event_type", "host_id", "src_ip", "dst_ip", "dst_port", "process_name", "payload"], rows)
            cur.execute(
                "SELECT id, alert_id, detected_at, alert_type, severity, ip, actor_key, evidence FROM security_alerts WHERE detected_at < now() - (%s::text)::interval LIMIT %s",
                (f"{args.retention_days} days", args.batch_size),
            )
            rows = cur.fetchall()
            report["archived_alert_rows"] = dump_rows(archive_dir / "security_alerts_archive.csv", ["id", "alert_id", "detected_at", "alert_type", "severity", "ip", "actor_key", "evidence"], rows)
        if args.cleanup:
            cur.execute("DELETE FROM telemetry_events WHERE ts < now() - (%s::text)::interval", (f"{args.retention_days} days",))
            report["deleted_telemetry"] = cur.rowcount
            cur.execute("DELETE FROM security_alerts WHERE detected_at < now() - (%s::text)::interval AND COALESCE(incident_id,'')=''", (f"{args.retention_days} days",))
            report["deleted_unlinked_alerts"] = cur.rowcount
            conn.commit()
            conn.autocommit = True
            cur.execute("VACUUM ANALYZE telemetry_events")
            cur.execute("VACUUM ANALYZE security_alerts")
    if not conn.autocommit:
        conn.commit()
    conn.close()
    print(json.dumps(report, indent=2, default=str))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
