#!/usr/bin/env python3
"""
Telemetry ingestion worker — native Kafka consumer via kafka-python.

Consumes from Redpanda using the Kafka protocol directly (port 9092).
Replaces the previous HTTP Pandaproxy consumer-groups approach which is
not supported by Redpanda's Pandaproxy implementation.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any, List, Tuple

from kafka import KafkaConsumer  # type: ignore

from ingest_telemetry_events import connect_db, insert_batch, insert_batch_clickhouse, map_row, map_row_dict
from realtime_detector_consumer import build_dsn_from_env
from telemetry_event_contract import validate_event
from xdr_infra_clients import ClickHouseClient


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stream telemetry events from Redpanda into Postgres or ClickHouse")
    parser.add_argument("--bootstrap-servers", default=os.getenv("KAFKA_BOOTSTRAP_SERVERS", "redpanda:9092"))
    parser.add_argument("--topic",             default=os.getenv("TELEMETRY_TOPIC", "telemetry.raw"))
    parser.add_argument("--group-id",          default=os.getenv("TELEMETRY_GROUP_ID", "telemetry-worker-v1"))
    parser.add_argument("--dsn",               default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--batch-size",        type=int, default=250)
    parser.add_argument("--dead-letter",       default="storage/logs/telemetry_dead_letter.jsonl")
    # ARCH-DB-SPLIT: off by default (postgres) -- see ingest_telemetry_events.py's
    # --target for the exact same flag on the batch/offline ingester.
    parser.add_argument("--target", choices=["postgres", "clickhouse"], default=os.getenv("XDR_TELEMETRY_WRITE_TARGET", "postgres"))
    parser.add_argument("--clickhouse-url", default=os.getenv("XDR_CLICKHOUSE_HTTP_URL", "http://127.0.0.1:8123"))
    parser.add_argument("--clickhouse-db", default=os.getenv("XDR_CLICKHOUSE_DB", "detector_analytics"))
    parser.add_argument("--clickhouse-user", default=os.getenv("XDR_CLICKHOUSE_USER", "detector"))
    parser.add_argument("--clickhouse-password", default=os.getenv("XDR_CLICKHOUSE_PASSWORD", "detector"))
    return parser.parse_args()


def write_dead_letter(path: Path, value: Any, reason: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as f:
        f.write(json.dumps({"reason": reason, "value": value}, separators=(",", ":"), ensure_ascii=False) + "\n")


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dead_letter = (root / args.dead_letter).resolve()

    if args.target == "clickhouse":
        client = ClickHouseClient(args.clickhouse_url, args.clickhouse_db, args.clickhouse_user, args.clickhouse_password)
        row_mapper = map_row_dict
        flush = lambda rows: insert_batch_clickhouse(client, rows)  # noqa: E731
        close = lambda: None  # noqa: E731
    else:
        dsn = args.dsn.strip() or build_dsn_from_env(root)
        if not dsn:
            print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
            return 1
        driver, conn = connect_db(dsn)
        conn.autocommit = False
        row_mapper = map_row
        flush = lambda rows: insert_batch(driver, conn, rows)  # noqa: E731
        close = conn.close

    print(f"target:            {args.target}")
    print(f"bootstrap_servers: {args.bootstrap_servers}")
    print(f"topic:             {args.topic}")
    print(f"group_id:          {args.group_id}")

    consumer = KafkaConsumer(
        args.topic,
        bootstrap_servers=args.bootstrap_servers.split(","),
        group_id=args.group_id,
        auto_offset_reset="earliest",
        enable_auto_commit=True,
        value_deserializer=lambda raw: json.loads(raw.decode("utf-8")) if raw else None,
        consumer_timeout_ms=-1,
        max_poll_records=args.batch_size,
    )

    processed = invalid = inserted = 0
    batch: List[Any] = []

    try:
        for msg in consumer:
            value = msg.value
            if value is None:
                continue
            processed += 1
            if not isinstance(value, dict):
                invalid += 1
                write_dead_letter(dead_letter, value, "not_object")
                continue
            ok, errors = validate_event(value)
            if not ok:
                invalid += 1
                write_dead_letter(dead_letter, value, ";".join(errors))
                continue
            batch.append(row_mapper(value))
            if len(batch) >= args.batch_size:
                inserted += flush(batch)
                print(f"batch_inserted={len(batch)} processed={processed} invalid={invalid}")
                batch = []
    finally:
        if batch:
            inserted += flush(batch)
        close()
        consumer.close()

    print(f"processed={processed} inserted_attempted={inserted} invalid={invalid}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
