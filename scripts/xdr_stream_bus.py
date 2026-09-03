#!/usr/bin/env python3
"""
Small Redpanda/Kafka-compatible stream abstraction for XDR service separation.

The default implementation uses local JSONL topic files so it is deterministic
in demos. Set --backend redpanda to call Redpanda HTTP proxy endpoints.
"""

from __future__ import annotations

import argparse
import json
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Dict, Iterable


TOPICS = [
    "telemetry.raw",
    "telemetry.normalized",
    "xdr.alerts",
    "alerts.created",
    "incidents.updated",
    "ai.analysis.requests",
    "ai.analysis.results",
    "ai.analysis.completed",
]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Produce/replay XDR topic messages")
    parser.add_argument("action", choices=["produce", "replay", "lag", "topics"])
    parser.add_argument("--topic", default="telemetry.raw")
    parser.add_argument("--file", default="")
    parser.add_argument("--backend", choices=["jsonl", "redpanda"], default="jsonl")
    parser.add_argument("--redpanda-rest", default="http://127.0.0.1:8082")
    parser.add_argument(
        "--redpanda-tls-ca",
        default=None,
        help="Private CA bundle for hostname-verified Pandaproxy HTTPS",
    )
    parser.add_argument("--topic-dir", default="storage/streams")
    parser.add_argument("--consumer-group", default="xdr-local-consumer")
    return parser.parse_args(argv)


def build_redpanda_tls_context(
    action: str,
    backend: str,
    rest_url: str,
    tls_ca: str | None,
) -> ssl.SSLContext | None:
    if not tls_ca:
        return None
    if action != "produce" or backend != "redpanda":
        raise ValueError(
            "--redpanda-tls-ca requires the produce action with --backend redpanda"
        )
    if urllib.parse.urlsplit(rest_url).scheme.lower() != "https":
        raise ValueError("--redpanda-tls-ca requires an https:// Pandaproxy URL")
    return ssl.create_default_context(cafile=tls_ca)


def topic_path(root: Path, topic: str) -> Path:
    return root / f"{topic}.jsonl"


def iter_jsonl(path: Path) -> Iterable[Dict[str, Any]]:
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            if not line.strip():
                continue
            yield json.loads(line)


def produce_jsonl(
    topic_file: Path,
    rows: Iterable[Dict[str, Any]],
    *,
    dlq: bool = False,
) -> int:
    topic_file.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with topic_file.open("a", encoding="utf-8") as out:
        for row in rows:
            envelope = {
                "topic_ts": time.time(),
                "event": row,
                "retry_count": 0,
                "dlq": dlq,
            }
            out.write(json.dumps(envelope, separators=(",", ":"), ensure_ascii=False) + "\n")
            count += 1
    return count


def produce_redpanda(
    rest: str,
    topic: str,
    rows: Iterable[Dict[str, Any]],
    ssl_context: ssl.SSLContext | None = None,
) -> tuple[int, int]:
    count = 0
    failures = 0
    url = f"{rest.rstrip('/')}/topics/{topic}"
    for row in rows:
        payload = json.dumps({"records": [{"value": row}]}).encode("utf-8")
        req = urllib.request.Request(url, data=payload, headers={"Content-Type": "application/vnd.kafka.json.v2+json"})
        try:
            options: Dict[str, Any] = {"timeout": 10}
            if ssl_context is not None:
                options["context"] = ssl_context
            with urllib.request.urlopen(req, **options) as response:
                response.read()
            count += 1
        except (OSError, urllib.error.HTTPError, urllib.error.URLError) as exc:
            dlq_path = Path("storage/streams") / f"{topic}.dlq.jsonl"
            produce_jsonl(
                dlq_path,
                [{"error": str(exc), "event": row}],
                dlq=True,
            )
            failures += 1
    return count, failures


def count_lines(path: Path) -> int:
    if not path.exists():
        return 0
    with path.open("r", encoding="utf-8", errors="replace") as f:
        return sum(1 for line in f if line.strip())


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        ssl_context = build_redpanda_tls_context(
            args.action,
            args.backend,
            args.redpanda_rest,
            args.redpanda_tls_ca,
        )
    except (OSError, ValueError) as exc:
        print(f"ERROR: invalid Pandaproxy TLS configuration: {exc}", file=sys.stderr)
        return 2

    if args.action == "topics":
        for topic in TOPICS:
            print(topic)
        return 0
    if args.topic not in TOPICS:
        print(f"ERROR: unsupported topic {args.topic}")
        return 2

    root = Path(args.topic_dir)
    stream_file = topic_path(root, args.topic)
    source_file = Path(args.file) if args.file else None

    if args.action == "produce":
        if not source_file or not source_file.exists():
            print("ERROR: --file is required for produce")
            return 1
        rows = iter_jsonl(source_file)
        if args.backend == "redpanda":
            count, failures = produce_redpanda(
                args.redpanda_rest,
                args.topic,
                rows,
                ssl_context,
            )
        else:
            count = produce_jsonl(stream_file, rows)
            failures = 0
        print(
            f"topic={args.topic} produced={count} failed={failures} "
            f"backend={args.backend}"
        )
        return 1 if failures else 0

    if args.action == "replay":
        print(f"topic={args.topic} replay_available={count_lines(stream_file)} backend=jsonl")
        return 0

    if args.action == "lag":
        produced = count_lines(stream_file)
        consumed_marker = root / f"{args.topic}.{args.consumer_group}.offset"
        consumed = int(consumed_marker.read_text(encoding="utf-8")) if consumed_marker.exists() else 0
        print(f"topic={args.topic} consumer_group={args.consumer_group} produced={produced} consumed={consumed} lag={max(0, produced - consumed)}")
        return 0

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
