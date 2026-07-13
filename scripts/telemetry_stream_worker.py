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
import signal
import time
from pathlib import Path
from typing import Any, Callable, Dict, Iterable, List, Optional

from ingest_telemetry_events import connect_db, insert_batch, insert_batch_clickhouse, map_row, map_row_dict
from realtime_detector_consumer import build_dsn_from_env
from telemetry_event_contract import validate_event
from xdr_infra_clients import ClickHouseClient

MAX_FLUSH_RETRIES = 10
FLUSH_BASE_DELAY_SECONDS = 1
FLUSH_MAX_DELAY_SECONDS = 60
MAX_DB_CONNECT_RETRIES = 10
DB_CONNECT_RETRY_DELAY_SECONDS = 3
HEARTBEAT_STALE_SECONDS = 90


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stream telemetry events from Redpanda into Postgres or ClickHouse")
    parser.add_argument("--bootstrap-servers", default=os.getenv("KAFKA_BOOTSTRAP_SERVERS", "redpanda:9092"))
    parser.add_argument("--topic",             default=os.getenv("TELEMETRY_TOPIC", "telemetry.raw"))
    parser.add_argument("--group-id",          default=os.getenv("TELEMETRY_GROUP_ID", "telemetry-worker-v1"))
    parser.add_argument("--dsn",               default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--batch-size",        type=int, default=250)
    parser.add_argument("--dead-letter",       default="storage/logs/telemetry_dead_letter.jsonl")
    parser.add_argument("--heartbeat-file",    default=os.getenv("TELEMETRY_WORKER_HEARTBEAT_FILE", "storage/app/telemetry_worker_heartbeat"))
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


def write_heartbeat(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(str(int(time.time())), encoding="utf-8")


def heartbeat_is_fresh(path: Path, *, max_age_seconds: int = HEARTBEAT_STALE_SECONDS, now: Optional[float] = None) -> bool:
    """Used by the container healthcheck: a heartbeat that exists but has
    gone stale means the process is alive but stuck (e.g. blocked on a
    retry loop or a hung consumer), which is exactly what a py_compile-only
    healthcheck could never detect."""
    if not path.exists():
        return False
    try:
        written_at = float(path.read_text(encoding="utf-8").strip())
    except (TypeError, ValueError):
        return False
    return (now if now is not None else time.time()) - written_at < max_age_seconds


def flush_with_retry(
    flush_fn: Callable[[list], int],
    batch: list,
    *,
    max_retries: int = MAX_FLUSH_RETRIES,
    base_delay: float = FLUSH_BASE_DELAY_SECONDS,
    max_delay: float = FLUSH_MAX_DELAY_SECONDS,
    sleep_fn: Callable[[float], None] = time.sleep,
) -> int:
    """Retries a durable-write flush with exponential backoff. Raises the
    last exception after exhausting retries so the caller can exit non-zero
    without committing offsets -- the batch is safely redelivered (and,
    since telemetry_events writes are idempotent via ON CONFLICT DO NOTHING /
    ClickHouse's ReplacingMergeTree, safely re-persisted) on restart, rather
    than being silently dropped."""
    attempt = 0
    delay = base_delay
    last_error: Optional[BaseException] = None
    while attempt <= max_retries:
        try:
            return flush_fn(batch)
        except Exception as exc:  # noqa: BLE001 - any durable-write failure is retryable here
            last_error = exc
            attempt += 1
            if attempt > max_retries:
                break
            print(f"WARNING: flush attempt {attempt}/{max_retries} failed: {exc}; retrying in {delay}s")
            sleep_fn(delay)
            delay = min(delay * 2, max_delay)
    raise RuntimeError(f"flush failed after {max_retries} retries") from last_error


def connect_db_with_retry(
    dsn: str,
    *,
    max_retries: int = MAX_DB_CONNECT_RETRIES,
    delay: float = DB_CONNECT_RETRY_DELAY_SECONDS,
    sleep_fn: Callable[[float], None] = time.sleep,
):
    attempt = 0
    last_error: Optional[BaseException] = None
    while attempt <= max_retries:
        try:
            return connect_db(dsn)
        except Exception as exc:  # noqa: BLE001 - any connect failure is retryable at startup
            last_error = exc
            attempt += 1
            if attempt > max_retries:
                break
            print(f"WARNING: db connect attempt {attempt}/{max_retries} failed: {exc}; retrying in {delay}s")
            sleep_fn(delay)
    raise RuntimeError(f"db connect failed after {max_retries} retries") from last_error


def run_consume_loop(
    messages: Iterable[Any],
    *,
    row_mapper: Callable[[dict], Any],
    flush_fn: Callable[[list], int],
    commit_fn: Callable[[], None],
    dead_letter_writer: Callable[[Any, str], None],
    heartbeat_writer: Callable[[], None],
    batch_size: int,
) -> Dict[str, int]:
    """Core batch/commit loop, decoupled from KafkaConsumer so it's directly
    unit-testable against a fake message iterable. `flush_fn` is expected to
    already be wrapped with retry (flush_with_retry) by the caller -- a
    flush_fn exception propagates here uncaught, so `commit_fn()` is never
    reached for that batch and offsets stay uncommitted."""
    processed = invalid = inserted = 0
    batch: List[Any] = []

    for value in messages:
        if value is None:
            continue
        processed += 1
        if not isinstance(value, dict):
            invalid += 1
            dead_letter_writer(value, "not_object")
            continue
        ok, errors = validate_event(value)
        if not ok:
            invalid += 1
            dead_letter_writer(value, ";".join(errors))
            continue
        batch.append(row_mapper(value))
        if len(batch) >= batch_size:
            inserted += flush_fn(batch)
            commit_fn()
            heartbeat_writer()
            print(f"batch_inserted={len(batch)} processed={processed} invalid={invalid}")
            batch = []

    if batch:
        inserted += flush_fn(batch)
        commit_fn()
        heartbeat_writer()

    return {"processed": processed, "inserted_attempted": inserted, "invalid": invalid}


def iter_consumer_messages(
    consumer: "KafkaConsumer",
    should_stop: Callable[[], bool],
    *,
    on_idle_wake: Callable[[], None] = lambda: None,
) -> Iterable[Any]:
    """`consumer_timeout_ms` (bounded, not -1) makes the `for msg in
    consumer:` loop raise StopIteration -- ending normally -- after a
    period of no new messages, which is what lets this outer loop re-check
    `should_stop()` periodically instead of blocking forever and ignoring
    SIGTERM until the next message arrives. `on_idle_wake()` fires on every
    such wake-up (not just when a batch is durably flushed) so the
    heartbeat/health check reflects "process is alive and polling", not
    "topic has recent traffic" -- an idle topic must never look unhealthy."""
    while not should_stop():
        for msg in consumer:
            yield msg.value
            if should_stop():
                return
        on_idle_wake()


def main() -> int:
    from kafka import KafkaConsumer  # type: ignore  # imported lazily so this module can be unit-tested without kafka-python installed

    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dead_letter = (root / args.dead_letter).resolve()
    heartbeat_path = (root / args.heartbeat_file).resolve()

    if args.target == "clickhouse":
        client = ClickHouseClient(args.clickhouse_url, args.clickhouse_db, args.clickhouse_user, args.clickhouse_password)
        row_mapper = map_row_dict
        raw_flush = lambda rows: insert_batch_clickhouse(client, rows)  # noqa: E731
        close = lambda: None  # noqa: E731
    else:
        dsn = args.dsn.strip() or build_dsn_from_env(root)
        if not dsn:
            print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
            return 1
        driver, conn = connect_db_with_retry(dsn)
        conn.autocommit = False
        row_mapper = map_row
        raw_flush = lambda rows: insert_batch(driver, conn, rows)  # noqa: E731
        close = conn.close

    print(f"target:            {args.target}")
    print(f"bootstrap_servers: {args.bootstrap_servers}")
    print(f"topic:             {args.topic}")
    print(f"group_id:          {args.group_id}")

    stop_requested = {"flag": False}

    def handle_signal(signum, _frame):
        print(f"received signal {signum}, stopping after current message/batch")
        stop_requested["flag"] = True

    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)

    consumer = KafkaConsumer(
        args.topic,
        bootstrap_servers=args.bootstrap_servers.split(","),
        group_id=args.group_id,
        auto_offset_reset="earliest",
        # TELEMETRY-WORKER-COMMIT-AFTER-WRITE: manual commit only, issued
        # from run_consume_loop() after a batch is durably flushed -- the
        # previous enable_auto_commit=True let kafka-python's background
        # auto-commit thread advance offsets on its own timer regardless of
        # whether the batch sitting in memory had actually been persisted,
        # which could silently drop events on a crash between commit and
        # flush.
        enable_auto_commit=False,
        value_deserializer=lambda raw: json.loads(raw.decode("utf-8")) if raw else None,
        consumer_timeout_ms=1000,
        max_poll_records=args.batch_size,
    )

    try:
        stats = run_consume_loop(
            iter_consumer_messages(
                consumer,
                lambda: stop_requested["flag"],
                on_idle_wake=lambda: write_heartbeat(heartbeat_path),
            ),
            row_mapper=row_mapper,
            flush_fn=lambda rows: flush_with_retry(raw_flush, rows),
            commit_fn=consumer.commit,
            dead_letter_writer=lambda value, reason: write_dead_letter(dead_letter, value, reason),
            heartbeat_writer=lambda: write_heartbeat(heartbeat_path),
            batch_size=args.batch_size,
        )
    except Exception as exc:  # noqa: BLE001 - a batch durably failed after exhausting retries
        print(f"ERROR: {exc}")
        close()
        consumer.close()
        return 1

    close()
    consumer.close()
    print(f"processed={stats['processed']} inserted_attempted={stats['inserted_attempted']} invalid={stats['invalid']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
