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


INSERT_SQL = """
INSERT INTO telemetry_events (
    ts, event_id, telemetry_type, event_type, host_id, src_ip, dst_ip,
    dst_port, protocol, process_name, user_name_hash,
    xdr_user, xdr_host, source_ip, destination_ip, domain, file_hash,
    email_sender, email_recipient, cloud_account, xdr_action, xdr_result,
    risk_score, event_source, payload, created_at, updated_at
) VALUES (
    %s, %s, %s, %s, %s, %s, %s,
    %s, %s, %s, %s,
    %s, %s, %s, %s, %s, %s,
    %s, %s, %s, %s, %s,
    %s, %s, %s::jsonb, now(), now()
)
ON CONFLICT (event_id) DO NOTHING
"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingest normalized telemetry JSONL into Postgres")
    parser.add_argument("--file", default="storage/logs/telemetry.jsonl")
    parser.add_argument("--offset-file", default="storage/app/telemetry_ingest.offset")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--batch-size", type=int, default=500)
    parser.add_argument("--from-start", action="store_true")
    return parser.parse_args()


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


def main() -> int:
    args = parse_args()
    project_root = Path(__file__).resolve().parents[1]
    file_path = (project_root / args.file).resolve()
    offset_path = (project_root / args.offset_file).resolve()

    if not file_path.exists():
        print(f"ERROR: file not found: {file_path}")
        return 1

    dsn = args.dsn.strip() or build_dsn_from_env(project_root)
    if not dsn:
        print("ERROR: DSN not provided. Set --dsn or SECURITY_INGEST_DSN.")
        return 1

    offset = load_offset(offset_path, args.from_start)
    if offset > file_path.stat().st_size:
        offset = 0

    driver, conn = connect_db(dsn)
    conn.autocommit = False

    processed = invalid = invalid_schema = inserted = 0
    batch: List[Tuple[Any, ...]] = []
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
            batch.append(map_row(event))
            if len(batch) >= max(1, args.batch_size):
                inserted += insert_batch(driver, conn, batch)
                batch = []
        if batch:
            inserted += insert_batch(driver, conn, batch)
    finally:
        conn.close()

    save_offset(offset_path, last_pos)
    print(f"Processed: {processed}")
    print(f"Inserted(attempted): {inserted}")
    print(f"Invalid: {invalid}")
    print(f"InvalidSchema: {invalid_schema}")
    print(f"Offset: {last_pos}")
    print("Note: actual inserted rows are deduped by telemetry event_id ON CONFLICT DO NOTHING.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
