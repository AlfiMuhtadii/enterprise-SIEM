#!/usr/bin/env python3
"""
Minimal retraining policy checker.

Trigger retrain when:
- active deployment age >= weekly threshold
- or latest drift report has drift_detected=true
"""

from __future__ import annotations

import argparse
import json
import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, Optional


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Retrain policy checker")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--env", default="local")
    parser.add_argument("--weekly-days", type=int, default=7)
    parser.add_argument("--drift-report", default="storage/app/ml_drift_report.json")
    parser.add_argument("--output", default="storage/app/ml_retrain_policy.json")
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


def parse_dt(value: Any) -> Optional[datetime]:
    if value is None:
        return None
    text = str(value).replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(text)
        if dt.tzinfo is None:
            return dt.replace(tzinfo=timezone.utc)
        return dt
    except ValueError:
        return None


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1

    conn = connect_db(dsn)
    conn.autocommit = False
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT d.id, d.deployed_at, d.model_id, m.model_key
                FROM ml_model_deployments d
                JOIN ml_models m ON m.id = d.model_id
                WHERE d.environment = %s AND d.is_active = true
                ORDER BY d.deployed_at DESC
                LIMIT 1
                """,
                (args.env,),
            )
            row = cur.fetchone()
    finally:
        conn.close()

    if row is None:
        print(f"ERROR: no active deployment in env={args.env}")
        return 1

    deployment_id = int(row[0])
    deployed_at = parse_dt(row[1])
    model_id = int(row[2])
    model_key = str(row[3])

    now = datetime.now(timezone.utc)
    weekly_due = False
    age_days = -1.0
    if deployed_at is not None:
        age = now - deployed_at.astimezone(timezone.utc)
        age_days = age.total_seconds() / 86400.0
        weekly_due = age >= timedelta(days=max(1, args.weekly_days))

    drift_report_path = (root / args.drift_report).resolve()
    drift_detected = False
    drift_features = []
    if drift_report_path.exists():
        drift = json.loads(drift_report_path.read_text(encoding="utf-8"))
        drift_detected = bool(drift.get("drift_detected", False))
        drift_features = drift.get("drifted_features", []) or []

    retrain_required = bool(weekly_due or drift_detected)
    reasons = []
    if weekly_due:
        reasons.append(f"weekly_schedule_due(age_days={age_days:.2f})")
    if drift_detected:
        reasons.append(f"drift_detected(features={','.join(drift_features)})")

    out = {
        "generated_at": now.astimezone().isoformat(),
        "environment": args.env,
        "deployment_id": deployment_id,
        "model_id": model_id,
        "model_key": model_key,
        "deployment_age_days": round(age_days, 6),
        "weekly_days_threshold": args.weekly_days,
        "drift_detected": drift_detected,
        "drift_features": drift_features,
        "retrain_required": retrain_required,
        "reasons": reasons,
    }

    output_path = (root / args.output).resolve()
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(out, indent=2), encoding="utf-8")

    print(f"Output: {output_path}")
    print(f"RetrainRequired: {retrain_required}")
    if reasons:
        print("Reasons:")
        for r in reasons:
            print(f"- {r}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
