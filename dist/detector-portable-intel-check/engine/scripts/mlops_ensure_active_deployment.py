#!/usr/bin/env python3
"""
Ensure an active model deployment exists for an environment.
"""

from __future__ import annotations

import argparse
import hashlib
import subprocess
import sys
from pathlib import Path
from typing import Any

from mlops_retrain_policy import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ensure active MLOps deployment")
    parser.add_argument("--env", default="local")
    parser.add_argument("--model", default="storage/app/ai_detector_model.pkl")
    parser.add_argument("--report", default="storage/app/ai_detector_report.json")
    parser.add_argument("--dataset", default="storage/app/security_dataset.csv")
    parser.add_argument("--deployed-by", default="demo-runbook")
    return parser.parse_args()


def file_sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        while True:
            chunk = f.read(8192)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def active_deployment_hash(conn: Any, env: str) -> str:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT COALESCE(d.expected_artifact_sha256, m.artifact_sha256, '')
            FROM ml_model_deployments d
            JOIN ml_models m ON m.id = d.model_id
            WHERE d.environment = %s AND d.is_active = true
            ORDER BY d.deployed_at DESC
            LIMIT 1
            """,
            (env,),
        )
        row = cur.fetchone()
        return str(row[0]) if row and row[0] else ""


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1

    conn = connect_db(dsn)
    try:
        model_path = (root / args.model).resolve()
        current_hash = file_sha256(model_path) if model_path.exists() else ""
        active_hash = active_deployment_hash(conn, args.env)
        if active_hash and current_hash and active_hash.lower() == current_hash.lower():
            print(f"ActiveDeployment: present env={args.env} hash={active_hash}")
            return 0
        if active_hash and current_hash:
            print("ActiveDeployment: stale hash, registering current model")
            print(f"ActiveHash:  {active_hash}")
            print(f"CurrentHash: {current_hash}")
    finally:
        conn.close()

    cmd = [
        sys.executable,
        "scripts/mlops_register_model.py",
        "--model",
        args.model,
        "--report",
        args.report,
        "--dataset",
        args.dataset,
        "--deploy",
        "--env",
        args.env,
        "--deployed-by",
        args.deployed_by,
    ]
    proc = subprocess.run(cmd, cwd=str(root), text=True)
    return int(proc.returncode)


if __name__ == "__main__":
    raise SystemExit(main())
