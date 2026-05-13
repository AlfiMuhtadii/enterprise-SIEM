#!/usr/bin/env python3
"""
Continuously sync Postgres analytics tables into ClickHouse.

Used during live demo so Grafana reflects fresh alerts without waiting for an end-of-run sync.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
import time
from pathlib import Path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Looping ClickHouse sync daemon")
    parser.add_argument("--interval-sec", type=float, default=2.0)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    sync_cmd = [sys.executable, "scripts/sync_postgres_to_clickhouse.py"]
    print(f"ClickHouse sync daemon started (interval={args.interval_sec}s)", flush=True)
    try:
        while True:
            proc = subprocess.run(sync_cmd, cwd=str(root), text=True, capture_output=True)
            if proc.returncode != 0:
                print("sync_error", flush=True)
                if proc.stdout.strip():
                    print(proc.stdout.strip(), flush=True)
                if proc.stderr.strip():
                    print(proc.stderr.strip(), flush=True)
            time.sleep(max(args.interval_sec, 0.5))
    except KeyboardInterrupt:
        print("ClickHouse sync daemon stopping...", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
