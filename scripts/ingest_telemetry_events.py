#!/usr/bin/env python3
"""
Idempotent JSONL -> Postgres ingester for endpoint/network/DNS telemetry.

This is an adapter boundary: external collectors can write normalized JSONL
without exposing the protected application's source code.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple

from realtime_detector_consumer import build_dsn_from_env
from telemetry_event_contract import validate_event
from xdr_infra_clients import ClickHouseClient


INSERT_SQL = """
INSERT INTO telemetry_events (
    ts, tenant_id, event_id, telemetry_type, event_type, host_id, src_ip, dst_ip,
    dst_port, protocol, process_name, user_name_hash,
    xdr_user, xdr_host, source_ip, destination_ip, domain, file_hash,
    email_sender, email_recipient, cloud_account, xdr_action, xdr_result,
    risk_score, event_source, payload, created_at, updated_at
) VALUES (
    %s, %s, %s, %s, %s, %s, %s, %s,
    %s, %s, %s, %s,
    %s, %s, %s, %s, %s, %s,
    %s, %s, %s, %s, %s,
    %s, %s, %s::jsonb, now(), now()
)
ON CONFLICT (event_id) DO NOTHING
"""


def parse_args(argv: Optional[List[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingest normalized telemetry JSONL into Postgres or ClickHouse")
    parser.add_argument("--file", default="storage/logs/telemetry.jsonl")
    parser.add_argument("--offset-file", default="storage/app/telemetry_ingest.offset")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--batch-size", type=int, default=500)
    parser.add_argument("--from-start", action="store_true")
    # ARCH-DB-SPLIT: off by default (postgres) -- zero behavior change unless
    # an operator opts in. "clickhouse" routes the exact same validated,
    # batched events to the telemetry_events table in ClickHouse instead.
    parser.add_argument(
        "--target",
        choices=["postgres", "clickhouse"],
        default=os.getenv("XDR_TELEMETRY_WRITE_TARGET", "postgres"),
    )
    parser.add_argument("--clickhouse-url", default=os.getenv("XDR_CLICKHOUSE_HTTP_URL", "http://127.0.0.1:8123"))
    parser.add_argument("--clickhouse-db", default=os.getenv("XDR_CLICKHOUSE_DB", "detector_analytics"))
    parser.add_argument("--clickhouse-user", default=os.getenv("XDR_CLICKHOUSE_USER", "detector"))
    parser.add_argument("--clickhouse-password", default=os.getenv("XDR_CLICKHOUSE_PASSWORD", "detector"))
    return parser.parse_args(argv)


def connect_db(dsn: str):
    try:
        import psycopg  # type: ignore

        return "psycopg3", psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore

        return "psycopg2", psycopg2.connect(dsn)


def normalize_str(value: Any, limit: int) -> Optional[str]:
    if not isinstance(value, str):
        return None
    value = value.strip()
    return value[:limit] if value else None


def normalize_int(value: Any) -> Optional[int]:
    if value is None or value == "":
        return None


def normalize_float(value: Any) -> Optional[float]:
    if value is None or value == "":
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def map_row(event: Dict[str, Any]) -> Tuple[Any, ...]:
    return (
        str(event.get("ts", "")),
        normalize_str(event.get("tenant_id"), 64),
        normalize_str(event.get("event_id"), 80),
        normalize_str(event.get("telemetry_type"), 32),
        normalize_str(event.get("event_type"), 80),
        normalize_str(event.get("host_id"), 128),
        normalize_str(event.get("src_ip"), 64),
        normalize_str(event.get("dst_ip"), 64),
        normalize_int(event.get("dst_port")),
        normalize_str(event.get("protocol"), 24),
        normalize_str(event.get("process_name"), 160),
        normalize_str(event.get("user_name_hash"), 64),
        normalize_str(event.get("user"), 160),
        normalize_str(event.get("host"), 160),
        normalize_str(event.get("source_ip") or event.get("src_ip"), 64),
        normalize_str(event.get("destination_ip") or event.get("dst_ip"), 64),
        normalize_str(event.get("domain") or event.get("query"), 255),
        normalize_str(event.get("file_hash"), 128),
        normalize_str(event.get("email_sender"), 255),
        normalize_str(event.get("email_recipient"), 255),
        normalize_str(event.get("cloud_account"), 160),
        normalize_str(event.get("action"), 160),
        normalize_str(event.get("result"), 80),
        normalize_float(event.get("risk_score")),
        normalize_str(event.get("event_source") or event.get("source_adapter"), 120),
        json.dumps(event, separators=(",", ":"), ensure_ascii=False),
    )


def map_row_dict(event: Dict[str, Any]) -> Dict[str, Any]:
    """ClickHouse JSONEachRow equivalent of map_row() -- same field mapping,
    same normalize_str/int/float helpers, dict-shaped instead of positional
    so it lines up with ClickHouseClient.insert_json_each_row(). tenant_id
    is read directly off the event if present (telemetry_event_contract.py
    does not require it -- absent means '', ClickHouse's documented default
    for an unscoped/legacy event; map_row()'s Postgres path now reads the
    same event["tenant_id"] field, but leaves it None when absent -- NULL is
    Postgres's own null-tenant convention here, not an empty string)."""
    return {
        "ts": str(event.get("ts", "")),
        "event_id": normalize_str(event.get("event_id"), 80) or "",
        "tenant_id": normalize_str(event.get("tenant_id"), 64) or "",
        "telemetry_type": normalize_str(event.get("telemetry_type"), 32) or "",
        "event_type": normalize_str(event.get("event_type"), 80) or "",
        "host_id": normalize_str(event.get("host_id"), 128) or "",
        "src_ip": normalize_str(event.get("src_ip"), 64) or "",
        "dst_ip": normalize_str(event.get("dst_ip"), 64) or "",
        "dst_port": normalize_int(event.get("dst_port")) or 0,
        "protocol": normalize_str(event.get("protocol"), 24) or "",
        "process_name": normalize_str(event.get("process_name"), 160) or "",
        "user_name_hash": normalize_str(event.get("user_name_hash"), 64) or "",
        "xdr_user": normalize_str(event.get("user"), 160) or "",
        "xdr_host": normalize_str(event.get("host"), 160) or "",
        "source_ip": normalize_str(event.get("source_ip") or event.get("src_ip"), 64) or "",
        "destination_ip": normalize_str(event.get("destination_ip") or event.get("dst_ip"), 64) or "",
        "domain": normalize_str(event.get("domain") or event.get("query"), 255) or "",
        "file_hash": normalize_str(event.get("file_hash"), 128) or "",
        "email_sender": normalize_str(event.get("email_sender"), 255) or "",
        "email_recipient": normalize_str(event.get("email_recipient"), 255) or "",
        "cloud_account": normalize_str(event.get("cloud_account"), 160) or "",
        "xdr_action": normalize_str(event.get("action"), 160) or "",
        "xdr_result": normalize_str(event.get("result"), 80) or "",
        "risk_score": normalize_float(event.get("risk_score")) or 0.0,
        "event_source": normalize_str(event.get("event_source") or event.get("source_adapter"), 120) or "",
        "payload": json.dumps(event, separators=(",", ":"), ensure_ascii=False),
    }


def insert_batch_clickhouse(client: ClickHouseClient, rows: List[Dict[str, Any]]) -> int:
    if not rows:
        return 0
    result = client.insert_json_each_row("telemetry_events", rows)
    if not result.ok:
        raise RuntimeError(f"clickhouse insert failed: status={result.status} error={result.error or result.body}")
    return len(rows)


def load_offset(path: Path, from_start: bool) -> int:
    if from_start or not path.exists():
        return 0
    try:
        return max(0, int(path.read_text(encoding="utf-8").strip()))
    except (TypeError, ValueError):
        return 0


def save_offset(path: Path, offset: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(str(offset), encoding="utf-8")


def iter_lines(file_path: Path, start_offset: int) -> Iterable[Tuple[int, str]]:
    with file_path.open("rb") as f:
        f.seek(start_offset)
        while True:
            line = f.readline()
            if not line:
                break
            pos = f.tell()
            text = line.decode("utf-8", errors="replace").strip()
            if text:
                yield pos, text


def insert_batch(driver: str, conn: Any, rows: List[Tuple[Any, ...]]) -> int:
    if not rows:
        return 0
    with conn.cursor() as cur:
        if driver == "psycopg3":
            cur.executemany(INSERT_SQL, rows)
        else:
            from psycopg2.extras import execute_batch  # type: ignore

            execute_batch(cur, INSERT_SQL, rows, page_size=500)
    conn.commit()
    return len(rows)


def run_ingest(args: argparse.Namespace, project_root: Path) -> int:
    file_path = (project_root / args.file).resolve()
    offset_path = (project_root / args.offset_file).resolve()

    if not file_path.exists():
        print(f"ERROR: file not found: {file_path}")
        return 1

    offset = load_offset(offset_path, args.from_start)
    if offset > file_path.stat().st_size:
        offset = 0

    if args.target == "clickhouse":
        client = ClickHouseClient(args.clickhouse_url, args.clickhouse_db, args.clickhouse_user, args.clickhouse_password)
        row_mapper = map_row_dict
        flush = lambda rows: insert_batch_clickhouse(client, rows)  # noqa: E731
        close = lambda: None  # noqa: E731
        dedup_note = "ClickHouse dedup is eventual (ReplacingMergeTree background merge), not synchronous like Postgres's ON CONFLICT DO NOTHING."
    else:
        dsn = args.dsn.strip() or build_dsn_from_env(project_root)
        if not dsn:
            print("ERROR: DSN not provided. Set --dsn or SECURITY_INGEST_DSN.")
            return 1
        driver, conn = connect_db(dsn)
        conn.autocommit = False
        row_mapper = map_row
        flush = lambda rows: insert_batch(driver, conn, rows)  # noqa: E731
        close = conn.close
        dedup_note = "Actual inserted rows are deduped by telemetry event_id ON CONFLICT DO NOTHING."

    processed = invalid = invalid_schema = inserted = 0
    batch: List[Any] = []
    last_pos = offset
    try:
        for last_pos, raw_line in iter_lines(file_path, offset):
            processed += 1
            try:
                event = json.loads(raw_line)
            except json.JSONDecodeError:
                invalid += 1
                continue
            if not isinstance(event, dict):
                invalid += 1
                continue
            ok, _errors = validate_event(event)
            if not ok:
                invalid += 1
                invalid_schema += 1
                continue
            batch.append(row_mapper(event))
            if len(batch) >= max(1, args.batch_size):
                inserted += flush(batch)
                batch = []
        if batch:
            inserted += flush(batch)
    finally:
        close()

    save_offset(offset_path, last_pos)
    print(f"Target: {args.target}")
    print(f"Processed: {processed}")
    print(f"Inserted(attempted): {inserted}")
    print(f"Invalid: {invalid}")
    print(f"InvalidSchema: {invalid_schema}")
    print(f"Offset: {last_pos}")
    print(f"Note: {dedup_note}")
    return 0


def main(argv: Optional[List[str]] = None) -> int:
    args = parse_args(argv)
    project_root = Path(__file__).resolve().parents[1]
    return run_ingest(args, project_root)


if __name__ == "__main__":
    raise SystemExit(main())
