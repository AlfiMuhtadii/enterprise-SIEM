#!/usr/bin/env python3
"""
Optional date partition manager for high-volume telemetry replay.

Creates a partitioned telemetry_events_partitioned table and monthly partitions.
This is additive and does not destructively replace telemetry_events.
"""

from __future__ import annotations

import argparse
import os
from datetime import datetime, timezone
from pathlib import Path

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Create/manage telemetry partition tables")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--year", type=int, default=datetime.now(timezone.utc).year)
    parser.add_argument("--months-ahead", type=int, default=3)
    parser.add_argument("--copy-existing", action="store_true")
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
    created = 0
    with conn.cursor() as cur:
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS telemetry_events_partitioned (
                id BIGSERIAL,
                ts TIMESTAMPTZ NOT NULL,
                event_id VARCHAR(80) NOT NULL,
                telemetry_type VARCHAR(32),
                event_type VARCHAR(80),
                host_id VARCHAR(128),
                src_ip VARCHAR(64),
                dst_ip VARCHAR(64),
                dst_port INTEGER,
                protocol VARCHAR(24),
                process_name VARCHAR(160),
                user_name_hash VARCHAR(64),
                payload JSONB,
                created_at TIMESTAMPTZ DEFAULT now(),
                updated_at TIMESTAMPTZ DEFAULT now(),
                PRIMARY KEY (id, ts),
                UNIQUE (event_id, ts)
            ) PARTITION BY RANGE (ts)
            """
        )
        for offset in range(args.months_ahead):
            month = ((datetime.now(timezone.utc).month - 1 + offset) % 12) + 1
            year = args.year + ((datetime.now(timezone.utc).month - 1 + offset) // 12)
            next_month = 1 if month == 12 else month + 1
            next_year = year + 1 if month == 12 else year
            name = f"telemetry_events_p_{year}_{month:02d}"
            start = f"{year}-{month:02d}-01 00:00:00+00"
            end = f"{next_year}-{next_month:02d}-01 00:00:00+00"
            cur.execute(
                f"""
                CREATE TABLE IF NOT EXISTS {name}
                PARTITION OF telemetry_events_partitioned
                FOR VALUES FROM ('{start}') TO ('{end}')
                """
            )
            cur.execute(f"CREATE INDEX IF NOT EXISTS {name}_type_ts_idx ON {name} (telemetry_type, event_type, ts)")
            cur.execute(f"CREATE INDEX IF NOT EXISTS {name}_src_ts_idx ON {name} (src_ip, ts)")
            created += 1
        if args.copy_existing:
            cur.execute(
                """
                INSERT INTO telemetry_events_partitioned (
                    ts,event_id,telemetry_type,event_type,host_id,src_ip,dst_ip,dst_port,
                    protocol,process_name,user_name_hash,payload,created_at,updated_at
                )
                SELECT ts,event_id,telemetry_type,event_type,host_id,src_ip,dst_ip,dst_port,
                       protocol,process_name,user_name_hash,payload,created_at,updated_at
                FROM telemetry_events
                ON CONFLICT DO NOTHING
                """
            )
            copied = cur.rowcount
        else:
            copied = 0
    conn.commit()
    conn.close()
    print(f"partitions_created={created}")
    print(f"rows_copied={copied}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
