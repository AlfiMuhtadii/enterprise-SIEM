#!/usr/bin/env python3
"""
Operational alert deduplication and suppression.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from datetime import timedelta
from pathlib import Path
from typing import Any, Dict

from realtime_detector_consumer import build_dsn_from_env, connect_db
from telemetry_correlation_detector import parse_dt


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Deduplicate security_alerts")
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--suppression-window-seconds", type=int, default=300)
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


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    driver, conn = connect_db(dsn)
    conn.autocommit = False
    processed = suppressed = updated = 0
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, alert_id, detected_at, alert_type, actor_key, ip, evidence
            FROM security_alerts
            WHERE detected_at >= now() - (%s::text)::interval
            ORDER BY detected_at ASC
            """,
            (f"{max(1, args.minutes)} minutes",),
        )
        rows = cur.fetchall()
        last_seen: Dict[str, Any] = {}
        for row in rows:
            processed += 1
            db_id, alert_id, detected_at, alert_type, actor_key, ip, evidence_raw = row
            evidence = parse_json(evidence_raw)
            dedup_group = evidence.get("dedup_group") or f"{alert_type}|{actor_key or ip or 'unknown'}"
            fingerprint = evidence.get("alert_fingerprint") or hashlib.sha256(dedup_group.encode("utf-8")).hexdigest()
            ts = parse_dt(detected_at)
            is_suppressed = False
            suppress_until = None
            if dedup_group in last_seen and (ts - last_seen[dedup_group]).total_seconds() <= args.suppression_window_seconds:
                is_suppressed = True
                suppressed += 1
                suppress_until = (last_seen[dedup_group] + timedelta(seconds=args.suppression_window_seconds)).isoformat()
            else:
                last_seen[dedup_group] = ts
            cur.execute(
                """
                UPDATE security_alerts
                SET alert_fingerprint=%s, dedup_group=%s, is_suppressed=%s, suppressed_until=%s, updated_at=now()
                WHERE id=%s
                """,
                (fingerprint, dedup_group[:160], is_suppressed, suppress_until, db_id),
            )
            updated += 1
    conn.commit()
    conn.close()
    print(f"processed={processed}")
    print(f"updated={updated}")
    print(f"suppressed_duplicates={suppressed}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
