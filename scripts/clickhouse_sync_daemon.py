#!/usr/bin/env python3
"""
Continuously sync Postgres analytics tables into ClickHouse.

Used during live demo so Grafana reflects fresh alerts without waiting for an end-of-run sync.

PERF-SUBPROCESS-POLL: the sync runs in-process (importing sync_postgres_to_clickhouse
and calling its main([])) instead of spawning a fresh Python interpreter every cycle,
avoiding repeated process startup + module re-import overhead.
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

# Make sibling scripts importable regardless of the current working directory.
sys.path.insert(0, str(Path(__file__).resolve().parent))

import sync_postgres_to_clickhouse as ch_sync  # noqa: E402


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Looping ClickHouse sync daemon")
    parser.add_argument("--interval-sec", type=float, default=2.0)
    return parser.parse_args()


def run_once() -> int:
    """Run a single in-process sync cycle. Never raises — errors are logged so the
    daemon loop keeps running (mirrors the previous subprocess returncode handling)."""
    try:
        return int(ch_sync.main([]))
    except Exception as exc:  # pragma: no cover - defensive, exercised via patched main
        print("sync_error", flush=True)
        print(str(exc), flush=True)
        return 1


def main() -> int:
    args = parse_args()
    print(f"ClickHouse sync daemon started (interval={args.interval_sec}s)", flush=True)
    try:
        while True:
            run_once()
            time.sleep(max(args.interval_sec, 0.5))
    except KeyboardInterrupt:
        print("ClickHouse sync daemon stopping...", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
