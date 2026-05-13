#!/usr/bin/env python3
"""
Idempotent JSONL -> Postgres ingester for security events.

Dedup key:
    event_id = HMAC_SHA256(ts|request_id|event_type|path|ip, key)
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple

from security_event_contract import validate_event


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingest security JSONL into Postgres")
    parser.add_argument(
        "--file",
        default="storage/logs/security.jsonl",
        help="Path to JSONL file",
    )
    parser.add_argument(
        "--offset-file",
        default="storage/app/security_ingest_py.offset",
        help="Path to byte offset checkpoint file",
    )
    parser.add_argument(
        "--dsn",
        default=os.getenv("SECURITY_INGEST_DSN", ""),
        help="Postgres DSN (or set SECURITY_INGEST_DSN)",
    )
    parser.add_argument(
        "--batch-size",
        type=int,
        default=500,
        help="Insert batch size",
    )
    parser.add_argument(
        "--from-start",
        action="store_true",
        help="Ignore saved offset and ingest from byte 0",
    )
    parser.add_argument(
        "--hmac-key",
        default=os.getenv("SECURITY_EVENT_ID_KEY", "demo-ingest-key"),
        help="HMAC key for deterministic event_id",
    )
    return parser.parse_args()


def parse_env_file(path: Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    if not path.exists():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def build_dsn_from_env(project_root: Path) -> str:
    env = parse_env_file(project_root / ".env")
    if env.get("DB_CONNECTION") != "pgsql":
        return ""
    host = env.get("DB_HOST", "127.0.0.1")
    port = env.get("DB_PORT", "5432")
    dbname = env.get("DB_DATABASE", "postgres")
    user = env.get("DB_USERNAME", "postgres")
    password = env.get("DB_PASSWORD", "")
    return f"host={host} port={port} dbname={dbname} user={user} password={password}"


def normalize_str(value: Any, limit: int) -> Optional[str]:
    if not isinstance(value, str):
        return None
    cleaned = value.strip()
    if not cleaned:
        return None
    return cleaned[:limit]


def normalize_uuid(value: Any) -> Optional[str]:
    val = normalize_str(value, 36)
    if not val:
        return None
    allowed = set("0123456789abcdefABCDEF-")
    if len(val) != 36 or any(ch not in allowed for ch in val):
        return None
    return val.lower()


def normalize_int(value: Any) -> Optional[int]:
    if value is None or value == "":
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def compute_event_id(event: Dict[str, Any], hmac_key: str) -> str:
    ts = str(event.get("ts", ""))
    request_id = normalize_uuid(event.get("request_id")) or ""
    event_type = str(event.get("event") or event.get("event_type") or "unknown")
    path = normalize_str(event.get("path"), 1024) or ""
    ip = normalize_str(event.get("ip"), 45) or ""
    canonical = f"{ts}|{request_id}|{event_type}|{path}|{ip}"
    return hmac.new(hmac_key.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).hexdigest()


def map_row(event: Dict[str, Any], raw_line: str, hmac_key: str) -> Tuple[Any, ...]:
    payload = json.dumps(event, separators=(",", ":"), ensure_ascii=False)
    event_type = str(event.get("event") or event.get("event_type") or "unknown")[:64]
    return (
        str(event.get("ts", "")),
        event_type,
        compute_event_id(event, hmac_key),
        hashlib.sha256(raw_line.encode("utf-8")).hexdigest(),
        normalize_uuid(event.get("request_id")),
        normalize_str(event.get("ip"), 45),
        normalize_str(event.get("user_agent_hash"), 64),
        normalize_int(event.get("user_id")),
        normalize_str(event.get("email_hash"), 64),
        normalize_str(event.get("method"), 16),
        normalize_str(event.get("path"), 1024),
        normalize_int(event.get("status")),
        normalize_int(event.get("latency_ms")),
        normalize_str(event.get("query_hash"), 64),
        bool(event.get("has_sql_keywords", False)),
        bool(event.get("has_script_payload", False)),
        payload,
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


INSERT_SQL = """
INSERT INTO security_events (
    ts, event_type, event_id, event_hash, request_id, ip, user_agent_hash, user_id,
    email_hash, method, path, status, latency_ms, query_hash,
    has_sql_keywords, has_script_payload, payload
) VALUES (
    %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb
)
ON CONFLICT DO NOTHING
"""


def connect_db(dsn: str):
    try:
        import psycopg  # type: ignore

        return "psycopg3", psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore

        return "psycopg2", psycopg2.connect(dsn)


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


def iter_events(file_path: Path, start_offset: int) -> Iterable[Tuple[int, str]]:
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
    file_size = file_path.stat().st_size
    if offset > file_size:
        offset = 0

    driver, conn = connect_db(dsn)
    conn.autocommit = False

    processed = 0
    invalid = 0
    invalid_schema = 0
    inserted = 0
    batch: List[Tuple[Any, ...]] = []
    last_pos = offset

    try:
        for last_pos, raw_line in iter_events(file_path, offset):
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

            batch.append(map_row(event, raw_line, args.hmac_key))

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
    print("Note: actual inserted rows are deduped by event_id ON CONFLICT DO NOTHING.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
