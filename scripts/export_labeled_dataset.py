#!/usr/bin/env python3
"""
Export labeled dataset from security_events + attack_runs.

Formats:
  - csv (default)
  - parquet (requires pandas + pyarrow)
"""

from __future__ import annotations

import argparse
import csv
import os
from pathlib import Path
from typing import Dict, Optional


QUERY = """
select
    se.ts,
    se.request_id,
    se.event_type,
    se.ip,
    se.user_id,
    se.method,
    se.path,
    se.status,
    se.latency_ms,
    se.query_hash,
    se.has_sql_keywords,
    se.has_script_payload,
    coalesce(ar.attack_type, 'normal') as label,
    ar.id as attack_run_id
from security_events se
left join lateral (
    select id, attack_type
    from attack_runs
    where se.ts >= started_at
      and se.ts <= coalesce(ended_at, now())
    order by started_at desc
    limit 1
) ar on true
where (%(from)s is null or se.ts >= cast(%(from)s as timestamptz))
  and (%(to)s is null or se.ts <= cast(%(to)s as timestamptz))
order by se.ts asc
"""


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


def connect_db(dsn: str):
    try:
        import psycopg  # type: ignore

        return "psycopg3", psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore

        return "psycopg2", psycopg2.connect(dsn)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Export labeled security dataset")
    parser.add_argument("--format", choices=["csv", "parquet"], default="csv")
    parser.add_argument("--output", default="")
    parser.add_argument("--from", dest="from_ts", default=None)
    parser.add_argument("--to", dest="to_ts", default=None)
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    return parser.parse_args()


def export_csv(cur, output: Path) -> int:
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        cols = [desc[0] for desc in cur.description]
        writer.writerow(cols)
        count = 0
        for row in cur:
            writer.writerow(row)
            count += 1
    return count


def export_parquet(cur, output: Path) -> int:
    try:
        import pandas as pd  # type: ignore
    except Exception as exc:
        raise RuntimeError("Parquet export needs pandas + pyarrow installed.") from exc

    output.parent.mkdir(parents=True, exist_ok=True)
    cols = [desc[0] for desc in cur.description]
    rows = cur.fetchall()
    frame = pd.DataFrame(rows, columns=cols)
    frame.to_parquet(output, index=False)
    return len(frame.index)


def main() -> int:
    args = parse_args()
    project_root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(project_root)

    if not dsn:
        print("ERROR: DSN not provided. Use --dsn or SECURITY_INGEST_DSN.")
        return 1

    if args.output:
        output = Path(args.output).resolve()
    else:
        default_name = "security_dataset.parquet" if args.format == "parquet" else "security_dataset.csv"
        output = (project_root / "storage" / "app" / default_name).resolve()

    driver, conn = connect_db(dsn)
    try:
        with conn.cursor() as cur:
            params = {"from": args.from_ts, "to": args.to_ts}
            cur.execute(QUERY, params)
            if args.format == "csv":
                count = export_csv(cur, output)
            else:
                count = export_parquet(cur, output)
    finally:
        conn.close()

    print(f"Driver: {driver}")
    print(f"Exported: {count}")
    print(f"Output: {output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
