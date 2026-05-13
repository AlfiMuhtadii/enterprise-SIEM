#!/usr/bin/env python3
"""
Async telemetry ingestion worker for Redpanda/Kafka REST Proxy.

Features:
- consumer group worker partitioning
- batch insert with event_id deduplication
- retry with dead-letter JSONL
- backpressure protection through bounded batch/poll limits
"""

from __future__ import annotations

import argparse
import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Tuple

from ingest_telemetry_events import connect_db, insert_batch, map_row
from realtime_detector_consumer import build_dsn_from_env, http_json
from telemetry_event_contract import validate_event


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stream telemetry events from Redpanda REST into Postgres")
    parser.add_argument("--rest-url", default=os.getenv("KAFKA_REST_URL", "http://127.0.0.1:8082"))
    parser.add_argument("--topic", default=os.getenv("TELEMETRY_TOPIC", "telemetry_events"))
    parser.add_argument("--group-id", default=os.getenv("TELEMETRY_GROUP_ID", "telemetry-worker-v1"))
    parser.add_argument("--instance-id", default=f"telemetry-worker-{int(time.time())}")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--batch-size", type=int, default=250)
    parser.add_argument("--poll-timeout-ms", type=int, default=1000)
    parser.add_argument("--max-empty-polls", type=int, default=0)
    parser.add_argument("--dead-letter", default="storage/logs/telemetry_dead_letter.jsonl")
    return parser.parse_args()


def consumer_create(rest_url: str, group: str, instance: str) -> str:
    url = f"{rest_url.rstrip('/')}/consumers/{group}"
    body = {"name": instance, "format": "json", "auto.offset.reset": "earliest", "enable.auto.commit": "true"}
    data = http_json("POST", url, body, {"Content-Type": "application/vnd.kafka.v2+json"})
    return str(data["base_uri"])


def consumer_subscribe(base_uri: str, topic: str) -> None:
    http_json("POST", f"{base_uri}/subscription", {"topics": [topic]}, {"Content-Type": "application/vnd.kafka.v2+json"})


def consumer_poll(base_uri: str, timeout_ms: int) -> List[Dict[str, Any]]:
    req = urllib.request.Request(
        f"{base_uri}/records?timeout={timeout_ms}",
        headers={"Accept": "application/vnd.kafka.json.v2+json"},
        method="GET",
    )
    with urllib.request.urlopen(req, timeout=max(5, timeout_ms / 1000 + 5)) as resp:
        data = resp.read().decode("utf-8")
        return json.loads(data) if data else []


def write_dead_letter(path: Path, value: Any, reason: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as f:
        f.write(json.dumps({"reason": reason, "value": value}, separators=(",", ":"), ensure_ascii=False) + "\n")


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    dead_letter = (root / args.dead_letter).resolve()
    driver, conn = connect_db(dsn)
    conn.autocommit = False
    base_uri = consumer_create(args.rest_url, args.group_id, args.instance_id)
    consumer_subscribe(base_uri, args.topic)
    print(f"REST URL: {args.rest_url}")
    print(f"Topic: {args.topic}")
    print(f"Group: {args.group_id}")
    print(f"Instance: {args.instance_id}")
    processed = invalid = inserted = retries = empty = 0
    batch: List[Tuple[Any, ...]] = []
    try:
        while True:
            try:
                records = consumer_poll(base_uri, args.poll_timeout_ms)
            except urllib.error.URLError as exc:
                retries += 1
                print(f"poll_error={exc}; retry={retries}")
                time.sleep(min(10, 1 + retries))
                continue
            if not records:
                empty += 1
                if batch:
                    inserted += insert_batch(driver, conn, batch)
                    batch = []
                if args.max_empty_polls and empty >= args.max_empty_polls:
                    break
                continue
            empty = 0
            for rec in records[: args.batch_size]:
                value = rec.get("value")
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
                batch.append(map_row(value))
            if len(batch) >= args.batch_size:
                inserted += insert_batch(driver, conn, batch)
                print(f"batch_inserted={len(batch)} processed={processed} invalid={invalid}")
                batch = []
    finally:
        if batch:
            inserted += insert_batch(driver, conn, batch)
        conn.close()
    print(f"processed={processed}")
    print(f"inserted_attempted={inserted}")
    print(f"invalid={invalid}")
    print(f"retries={retries}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
