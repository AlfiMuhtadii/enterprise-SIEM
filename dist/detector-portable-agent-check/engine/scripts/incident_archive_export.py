#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args():
    parser = argparse.ArgumentParser(description="Export incident archive JSONL")
    parser.add_argument("--output", default="storage/archive/incidents.jsonl")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    return parser.parse_args()


def parse_json(value: Any):
    if isinstance(value, dict) or isinstance(value, list):
        return value
    if isinstance(value, str):
        try:
            return json.loads(value)
        except json.JSONDecodeError:
            return value
    return value


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1
    _driver, conn = connect_db(dsn)
    out = (root / args.output).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with conn.cursor() as cur, out.open("w", encoding="utf-8") as f:
        cur.execute("SELECT * FROM security_incidents ORDER BY last_seen_at DESC")
        cols = [d[0] for d in cur.description]
        for row in cur.fetchall():
            item = dict(zip(cols, row))
            for key, value in list(item.items()):
                item[key] = parse_json(value)
                if hasattr(item[key], "isoformat"):
                    item[key] = item[key].isoformat()
            f.write(json.dumps(item, default=str, ensure_ascii=False) + "\n")
            count += 1
    conn.close()
    print(f"exported={count}")
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
