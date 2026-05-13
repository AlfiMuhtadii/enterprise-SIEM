#!/usr/bin/env python3
"""
Update realtime detector threshold config with audit trail.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any, Dict

from security_audit import insert_audit


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Update detector thresholds and audit the change")
    parser.add_argument("--file", default="storage/app/detector_thresholds.json")
    parser.add_argument("--actor", default="operator")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--brute-force-30s", type=int)
    parser.add_argument("--stuffing-30s", type=int)
    parser.add_argument("--stuffing-unique-emails-30s", type=int)
    parser.add_argument("--scan-30s-404", type=int)
    parser.add_argument("--scan-30s-unique-paths", type=int)
    return parser.parse_args()


def parse_env_file(path: Path) -> Dict[str, str]:
    out: Dict[str, str] = {}
    if not path.exists():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        s = line.strip()
        if not s or s.startswith("#") or "=" not in s:
            continue
        k, v = s.split("=", 1)
        out[k.strip()] = v.strip().strip('"').strip("'")
    return out


def build_dsn_from_env(project_root: Path) -> str:
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


def connect_db(dsn: str):
    try:
        import psycopg  # type: ignore
        return psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore
        return psycopg2.connect(dsn)


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    config_path = (root / args.file).resolve()
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1

    default_cfg = {
        "version": 1,
        "brute_force_30s": 12,
        "stuffing_30s": 15,
        "stuffing_unique_emails_30s": 8,
        "scan_30s_404": 20,
        "scan_30s_unique_paths": 20,
    }
    if config_path.exists():
        before = json.loads(config_path.read_text(encoding="utf-8"))
        if not isinstance(before, dict):
            before = default_cfg
    else:
        before = default_cfg

    after = dict(before)
    mapping = {
        "brute_force_30s": args.brute_force_30s,
        "stuffing_30s": args.stuffing_30s,
        "stuffing_unique_emails_30s": args.stuffing_unique_emails_30s,
        "scan_30s_404": args.scan_30s_404,
        "scan_30s_unique_paths": args.scan_30s_unique_paths,
    }
    for k, v in mapping.items():
        if v is not None:
            after[k] = int(v)
    after["version"] = int(after.get("version", 1)) + 1

    config_path.parent.mkdir(parents=True, exist_ok=True)
    config_path.write_text(json.dumps(after, indent=2), encoding="utf-8")

    conn = connect_db(dsn)
    conn.autocommit = False
    try:
        insert_audit(
            conn=conn,
            action="THRESHOLD_UPDATED",
            target_type="detector_threshold",
            target_id="realtime_rules",
            actor=args.actor,
            before_state=before,
            after_state=after,
            meta={"config_path": str(config_path)},
        )
        conn.commit()
    finally:
        conn.close()

    print(f"Updated: {config_path}")
    print(f"Version: {after['version']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
