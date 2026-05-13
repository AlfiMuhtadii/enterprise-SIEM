#!/usr/bin/env python3
"""
Sync Postgres analytics tables into ClickHouse.

Tables:
  - security_events
  - security_alerts
"""

from __future__ import annotations

import argparse
import json
import os
from datetime import timezone
import urllib.parse
import urllib.request
import urllib.error
from pathlib import Path
from typing import Any, Dict, List, Tuple


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync Postgres tables to ClickHouse.")
    parser.add_argument("--pg-dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--ch-url", default=os.getenv("CLICKHOUSE_HTTP_URL", "http://127.0.0.1:8123"))
    parser.add_argument("--ch-user", default=os.getenv("CLICKHOUSE_USER", "detector"))
    parser.add_argument("--ch-password", default=os.getenv("CLICKHOUSE_PASSWORD", "detector"))
    parser.add_argument("--ch-db", default=os.getenv("CLICKHOUSE_DB", "detector_analytics"))
    parser.add_argument("--state-file", default="storage/app/clickhouse_sync_state.json")
    parser.add_argument("--batch-size", type=int, default=1000)
    parser.add_argument("--full-refresh", action="store_true")
    return parser.parse_args()


def parse_env_file(path: Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    if not path.exists():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        s = line.strip()
        if not s or s.startswith("#") or "=" not in s:
            continue
        k, v = s.split("=", 1)
        values[k.strip()] = v.strip().strip('"').strip("'")
    return values


def build_pg_dsn(project_root: Path) -> str:
    env = parse_env_file(project_root / ".env")
    if env.get("DB_CONNECTION") != "pgsql":
        return ""
    return (
        f"host={env.get('DB_HOST','127.0.0.1')} "
        f"port={env.get('DB_PORT','5432')} "
        f"dbname={env.get('DB_DATABASE','detector')} "
        f"user={env.get('DB_USERNAME','postgres')} "
        f"password={env.get('DB_PASSWORD','postgres')}"
    )


def ch_request(url: str, query: str, user: str, password: str, body: bytes | None = None) -> str:
    q = urllib.parse.urlencode({"query": query})
    full_url = f"{url.rstrip('/')}/?{q}"
    req = urllib.request.Request(full_url, method="POST", data=body if body is not None else b"")
    req.add_header("X-ClickHouse-User", user)
    req.add_header("X-ClickHouse-Key", password)
    if body is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return resp.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"ClickHouse HTTP {exc.code}: {detail}") from exc


def connect_pg(dsn: str):
    try:
        import psycopg  # type: ignore

        return "psycopg3", psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore

        return "psycopg2", psycopg2.connect(dsn)


def ensure_clickhouse_schema(ch_url: str, ch_user: str, ch_password: str, ch_db: str) -> None:
    ch_request(ch_url, f"CREATE DATABASE IF NOT EXISTS {ch_db}", ch_user, ch_password)
    ch_request(
        ch_url,
        f"""
        CREATE TABLE IF NOT EXISTS {ch_db}.security_events (
            id UInt64,
            ts DateTime,
            ingested_at DateTime,
            event_type String,
            event_id String,
            event_hash String,
            request_id String,
            ip String,
            user_agent_hash String,
            user_id Nullable(UInt64),
            email_hash String,
            method String,
            path String,
            status Nullable(UInt16),
            latency_ms Nullable(UInt32),
            query_hash String,
            has_sql_keywords UInt8,
            has_script_payload UInt8,
            payload String
        ) ENGINE = MergeTree
        ORDER BY (ts, id)
        """,
        ch_user,
        ch_password,
    )
    ch_request(
        ch_url,
        f"""
        CREATE TABLE IF NOT EXISTS {ch_db}.security_alerts (
            id UInt64,
            alert_id String,
            detected_at DateTime,
            alert_type String,
            detector_name String,
            detector_version String,
            severity String,
            ip String,
            request_id String,
            actor_key String,
            event_id_ref Nullable(UInt64),
            window_start Nullable(DateTime),
            window_end Nullable(DateTime),
            score Nullable(Float64),
            threshold_profile_hash String,
            model_label String,
            evidence String,
            raw_event String
        ) ENGINE = MergeTree
        ORDER BY (detected_at, id)
        """,
        ch_user,
        ch_password,
    )
    # Backward-compatible schema evolution for existing tables.
    for stmt in [
        f"ALTER TABLE {ch_db}.security_alerts ADD COLUMN IF NOT EXISTS detector_name String DEFAULT 'realtime'",
        f"ALTER TABLE {ch_db}.security_alerts ADD COLUMN IF NOT EXISTS detector_version String DEFAULT 'v1'",
        f"ALTER TABLE {ch_db}.security_alerts ADD COLUMN IF NOT EXISTS actor_key String DEFAULT ''",
        f"ALTER TABLE {ch_db}.security_alerts ADD COLUMN IF NOT EXISTS window_start Nullable(DateTime)",
        f"ALTER TABLE {ch_db}.security_alerts ADD COLUMN IF NOT EXISTS window_end Nullable(DateTime)",
        f"ALTER TABLE {ch_db}.security_alerts ADD COLUMN IF NOT EXISTS threshold_profile_hash String DEFAULT ''",
    ]:
        ch_request(ch_url, stmt, ch_user, ch_password)


def load_state(path: Path) -> Dict[str, int]:
    if not path.exists():
        return {"security_events": 0, "security_alerts": 0}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return {
            "security_events": int(data.get("security_events", 0)),
            "security_alerts": int(data.get("security_alerts", 0)),
        }
    except Exception:
        return {"security_events": 0, "security_alerts": 0}


def save_state(path: Path, state: Dict[str, int]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(state, indent=2), encoding="utf-8")


def fetch_batch(cur: Any, table: str, last_id: int, batch_size: int) -> List[Dict[str, Any]]:
    cur.execute(
        f"SELECT * FROM {table} WHERE id > %s ORDER BY id ASC LIMIT %s",
        (last_id, batch_size),
    )
    cols = [d[0] for d in cur.description]
    rows = []
    for rec in cur.fetchall():
        row = {}
        for i, col in enumerate(cols):
            val = rec[i]
            if hasattr(val, "isoformat"):
                if getattr(val, "tzinfo", None) is not None:
                    row[col] = val.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
                else:
                    row[col] = val.strftime("%Y-%m-%d %H:%M:%S")
            elif isinstance(val, dict):
                row[col] = json.dumps(val, separators=(",", ":"))
            elif val is None:
                row[col] = None
            else:
                row[col] = val
        rows.append(row)
    return rows


def insert_clickhouse_json(ch_url: str, ch_user: str, ch_password: str, ch_db: str, table: str, rows: List[Dict[str, Any]]) -> None:
    if not rows:
        return
    payload = "\n".join(json.dumps(r, separators=(",", ":"), default=str) for r in rows).encode("utf-8")
    query = f"INSERT INTO {ch_db}.{table} FORMAT JSONEachRow"
    ch_request(ch_url, query, ch_user, ch_password, body=payload)


def truncate_clickhouse(ch_url: str, ch_user: str, ch_password: str, ch_db: str) -> None:
    ch_request(ch_url, f"TRUNCATE TABLE {ch_db}.security_events", ch_user, ch_password)
    ch_request(ch_url, f"TRUNCATE TABLE {ch_db}.security_alerts", ch_user, ch_password)


def sync_table(
    cur: Any,
    ch_cfg: Tuple[str, str, str, str],
    table: str,
    last_id: int,
    batch_size: int,
) -> int:
    ch_url, ch_user, ch_password, ch_db = ch_cfg
    max_id = last_id
    total = 0
    while True:
        rows = fetch_batch(cur, table, max_id, batch_size)
        if not rows:
            break
        insert_clickhouse_json(ch_url, ch_user, ch_password, ch_db, table, rows)
        max_id = max(int(r["id"]) for r in rows)
        total += len(rows)
    print(f"{table}: synced_rows={total}, last_id={max_id}")
    return max_id


def main() -> int:
    args = parse_args()
    project_root = Path(__file__).resolve().parents[1]
    pg_dsn = args.pg_dsn.strip() or build_pg_dsn(project_root)
    if not pg_dsn:
        print("ERROR: postgres DSN not found")
        return 1

    ch_cfg = (args.ch_url, args.ch_user, args.ch_password, args.ch_db)
    ensure_clickhouse_schema(*ch_cfg)

    state_path = (project_root / args.state_file).resolve()
    state = load_state(state_path)

    driver, conn = connect_pg(pg_dsn)
    print(f"postgres_driver={driver}")
    try:
        if args.full_refresh:
            truncate_clickhouse(*ch_cfg)
            state = {"security_events": 0, "security_alerts": 0}

        with conn.cursor() as cur:
            state["security_events"] = sync_table(
                cur, ch_cfg, "security_events", state["security_events"], args.batch_size
            )
            state["security_alerts"] = sync_table(
                cur, ch_cfg, "security_alerts", state["security_alerts"], args.batch_size
            )
        conn.commit()
    finally:
        conn.close()

    save_state(state_path, state)

    # quick counts
    events_count = ch_request(args.ch_url, f"SELECT count() FROM {args.ch_db}.security_events", args.ch_user, args.ch_password).strip()
    alerts_count = ch_request(args.ch_url, f"SELECT count() FROM {args.ch_db}.security_alerts", args.ch_user, args.ch_password).strip()
    print(f"clickhouse_security_events={events_count}")
    print(f"clickhouse_security_alerts={alerts_count}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
