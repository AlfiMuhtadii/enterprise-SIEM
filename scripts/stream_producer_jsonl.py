#!/usr/bin/env python3
"""
Tail security.jsonl and publish each event to Redpanda via Pandaproxy REST.

No external Python package required.
"""

from __future__ import annotations

import argparse
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Dict, Optional


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Tail JSONL log and stream to Redpanda topic (REST).")
    parser.add_argument("--file", default="storage/logs/security.jsonl")
    parser.add_argument("--rest-url", default=os.getenv("KAFKA_REST_URL", "http://127.0.0.1:8082"))
    parser.add_argument(
        "--tls-ca",
        default=os.getenv("KAFKA_REST_TLS_CA"),
        help="CA certificate used to verify an HTTPS Pandaproxy endpoint",
    )
    parser.add_argument("--topic", default=os.getenv("KAFKA_TOPIC", "security_events"))
    parser.add_argument("--state-file", default="storage/app/redpanda_topic_offsets.json")
    parser.add_argument("--from-start", action="store_true")
    parser.add_argument("--poll-ms", type=int, default=400)
    return parser.parse_args(argv)


def build_tls_context(rest_url: str, tls_ca: Optional[str]) -> Optional[ssl.SSLContext]:
    if not tls_ca:
        return None
    if urllib.parse.urlsplit(rest_url).scheme.lower() != "https":
        raise ValueError("--tls-ca requires an HTTPS --rest-url")
    return ssl.create_default_context(cafile=tls_ca)


def maybe_parse_json(line: str) -> Optional[Dict[str, Any]]:
    line = line.strip()
    if not line:
        return None
    try:
        val = json.loads(line)
    except json.JSONDecodeError:
        return None
    if not isinstance(val, dict):
        return None
    return val


def post_record(
    rest_url: str,
    topic: str,
    key: str,
    payload: Dict[str, Any],
    ssl_context: Optional[ssl.SSLContext] = None,
) -> Dict[str, Any]:
    body = json.dumps({"records": [{"key": key, "value": payload}]}, separators=(",", ":")).encode("utf-8")
    req = urllib.request.Request(
        url=f"{rest_url.rstrip('/')}/topics/{topic}",
        data=body,
        headers={
            "Content-Type": "application/vnd.kafka.json.v2+json",
            "Accept": "application/vnd.kafka.v2+json",
        },
        method="POST",
    )
    open_options: Dict[str, Any] = {"timeout": 10}
    if ssl_context is not None:
        open_options["context"] = ssl_context
    with urllib.request.urlopen(req, **open_options) as resp:
        if resp.status >= 300:
            raise RuntimeError(f"Publish failed status={resp.status}")
        text = resp.read().decode("utf-8")
        if not text:
            return {}
        data = json.loads(text)
        return data if isinstance(data, dict) else {}


def update_state_file(path: Path, topic: str, produce_result: Dict[str, Any]) -> None:
    offsets = produce_result.get("offsets")
    if not isinstance(offsets, list):
        return
    data: Dict[str, Any] = {
        "topic": topic,
        "updated_at": time.time(),
        "partitions": {},
    }
    if path.exists():
        try:
            current = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(current, dict):
                data.update(current)
                if not isinstance(data.get("partitions"), dict):
                    data["partitions"] = {}
        except Exception:
            pass

    partitions = data["partitions"]
    for item in offsets:
        if not isinstance(item, dict):
            continue
        part = item.get("partition")
        off = item.get("offset")
        if part is None or off is None:
            continue
        pkey = str(int(part))
        offset = int(off)
        existing = partitions.get(pkey) if isinstance(partitions, dict) else None
        if not isinstance(existing, dict):
            existing = {"start_offset": offset, "latest_offset": offset, "next_offset": offset + 1}
        else:
            existing["start_offset"] = min(int(existing.get("start_offset", offset)), offset)
            existing["latest_offset"] = max(int(existing.get("latest_offset", offset)), offset)
            existing["next_offset"] = int(existing.get("latest_offset", offset)) + 1
        partitions[pkey] = existing
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2), encoding="utf-8")


def main(argv: Optional[list[str]] = None) -> int:
    args = parse_args(argv)
    try:
        ssl_context = build_tls_context(args.rest_url, args.tls_ca)
    except (OSError, ValueError) as exc:
        print(f"ERROR: invalid TLS configuration: {exc}", file=sys.stderr)
        return 2

    file_path = Path(args.file).resolve()
    state_path = Path(args.state_file).resolve()
    if not file_path.exists():
        print(f"ERROR: file not found: {file_path}")
        return 1

    print(f"Streaming file: {file_path}")
    print(f"REST URL: {args.rest_url}")
    print(f"Topic: {args.topic}")
    state_path.parent.mkdir(parents=True, exist_ok=True)
    state_path.write_text(
        json.dumps({"topic": args.topic, "updated_at": time.time(), "partitions": {}}, indent=2),
        encoding="utf-8",
    )

    with file_path.open("r", encoding="utf-8") as f:
        if not args.from_start:
            f.seek(0, os.SEEK_END)

        sent = 0
        while True:
            line = f.readline()
            if not line:
                time.sleep(max(args.poll_ms, 50) / 1000.0)
                continue

            payload = maybe_parse_json(line)
            if payload is None:
                continue

            key = str(payload.get("request_id") or payload.get("event") or "")
            try:
                produce_result = post_record(args.rest_url, args.topic, key, payload, ssl_context)
                update_state_file(state_path, args.topic, produce_result)
                sent += 1
                if sent % 100 == 0:
                    print(f"sent={sent}")
            except urllib.error.URLError as exc:
                print(f"publish_error={exc}")
                time.sleep(1.0)


if __name__ == "__main__":
    raise SystemExit(main())
