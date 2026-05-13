#!/usr/bin/env python3
"""
Tail security.jsonl and publish each event to Redpanda via Kafka protocol.
"""

from __future__ import annotations

import argparse
import json
import os
import time
from pathlib import Path
from typing import Any, Dict, Optional


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Tail JSONL log and stream to Redpanda using Kafka protocol.")
    parser.add_argument("--file", default="storage/logs/security.jsonl")
    parser.add_argument("--bootstrap-servers", default=os.getenv("KAFKA_BOOTSTRAP_SERVERS", "127.0.0.1:19092"))
    parser.add_argument("--topic", default=os.getenv("KAFKA_TOPIC", "security_events"))
    parser.add_argument("--from-start", action="store_true")
    parser.add_argument("--poll-ms", type=int, default=200)
    return parser.parse_args()


def maybe_parse_json(line: str) -> Optional[Dict[str, Any]]:
    line = line.strip()
    if not line:
        return None
    try:
        val = json.loads(line)
    except json.JSONDecodeError:
        return None
    return val if isinstance(val, dict) else None


def main() -> int:
    try:
        from confluent_kafka import Producer  # type: ignore
    except ImportError:
        print("ERROR: missing dependency confluent-kafka. Install: python -m pip install -r scripts/requirements-ingest.txt")
        return 1

    args = parse_args()
    file_path = Path(args.file).resolve()
    if not file_path.exists():
        print(f"ERROR: file not found: {file_path}")
        return 1

    producer = Producer(
        {
            "bootstrap.servers": args.bootstrap_servers,
            "client.id": "detector-jsonl-producer",
            "acks": "all",
            "enable.idempotence": True,
        }
    )

    print(f"Streaming file: {file_path}", flush=True)
    print(f"BootstrapServers: {args.bootstrap_servers}", flush=True)
    print(f"Topic: {args.topic}", flush=True)

    with file_path.open("r", encoding="utf-8") as f:
        if not args.from_start:
            f.seek(0, os.SEEK_END)

        sent = 0
        while True:
            line = f.readline()
            if not line:
                producer.poll(0)
                time.sleep(max(args.poll_ms, 50) / 1000.0)
                continue

            payload = maybe_parse_json(line)
            if payload is None:
                continue

            key = str(payload.get("request_id") or payload.get("event") or "")
            producer.produce(
                args.topic,
                key=key.encode("utf-8"),
                value=json.dumps(payload, separators=(",", ":")).encode("utf-8"),
            )
            producer.poll(0)
            sent += 1
            if sent % 100 == 0:
                producer.flush(10)
                print(f"sent={sent}", flush=True)


if __name__ == "__main__":
    raise SystemExit(main())
