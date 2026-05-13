#!/usr/bin/env python3
"""
Update detector allowlist IPs with audit trail.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Dict, List

from security_audit import insert_audit


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Update detector allowlist")
    parser.add_argument("--file", default="storage/app/detector_allowlist.json")
    parser.add_argument("--actor", default="operator")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--set-ips", default="", help="Comma-separated IP list")
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
    file_path = (root / args.file).resolve()
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1

    before = {"ips": []}
    if file_path.exists():
        try:
            d = json.loads(file_path.read_text(encoding="utf-8"))
            if isinstance(d, dict) and isinstance(d.get("ips"), list):
                before = {"ips": [str(x) for x in d["ips"] if str(x).strip()]}
        except Exception:
            pass

    ips = [x.strip() for x in args.set_ips.split(",") if x.strip()]
    after = {"ips": sorted(set(ips))}
    file_path.parent.mkdir(parents=True, exist_ok=True)
    file_path.write_text(json.dumps(after, indent=2), encoding="utf-8")

    conn = connect_db(dsn)
    conn.autocommit = False
    try:
        insert_audit(
            conn=conn,
            action="ALLOWLIST_UPDATED",
            target_type="detector_allowlist",
            target_id="ip_allowlist",
            actor=args.actor,
            before_state=before,
            after_state=after,
            meta={"config_path": str(file_path)},
        )
        conn.commit()
    finally:
        conn.close()

    print(f"Updated: {file_path}")
    print(f"IPs: {len(after['ips'])}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
