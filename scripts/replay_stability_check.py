#!/usr/bin/env python3
"""
Replay stability check:
- stores previous snapshot
- on next run compares delta alerts_per_1k_events band
- fails if duplicate alert_id found in delta
"""

from __future__ import annotations

import argparse
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Replay stability checker")
    p.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    p.add_argument("--state-file", default="storage/app/replay_stability_state.json")
    p.add_argument("--max-band-pct", type=float, default=25.0, help="max allowed deviation percent")
    p.add_argument(
        "--min-delta-alerts-for-band",
        type=int,
        default=10,
        help="skip band check when previous/current delta alerts are below this value",
    )
    p.add_argument("--reset-baseline", action="store_true")
    return p.parse_args()


def parse_env(path: Path) -> Dict[str, str]:
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


def dsn_from_env(root: Path) -> str:
    env = parse_env(root / ".env")
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


def query_counts(conn) -> Dict[str, int]:
    out: Dict[str, int] = {}
    with conn.cursor() as cur:
        cur.execute("select count(*) from security_events")
        out["events"] = int(cur.fetchone()[0])
        cur.execute("select count(*) from security_alerts")
        out["alerts"] = int(cur.fetchone()[0])
        cur.execute("select count(distinct alert_id) from security_alerts")
        out["uniq_alerts"] = int(cur.fetchone()[0])
        cur.execute("select count(*) from security_responses")
        out["responses"] = int(cur.fetchone()[0])
    return out


def load_state(path: Path) -> Dict[str, object]:
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_state(path: Path, state: Dict[str, object]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(state, indent=2), encoding="utf-8")


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1

    conn = connect_db(dsn)
    conn.autocommit = True
    try:
        now_counts = query_counts(conn)
    finally:
        conn.close()

    state_path = (root / args.state_file).resolve()
    prev = {} if args.reset_baseline else load_state(state_path)

    snapshot = {
        "captured_at": datetime.now(timezone.utc).isoformat(),
        **now_counts,
    }

    if not prev:
        snapshot["last_delta_alerts_per_1k"] = None
        snapshot["last_delta_alerts"] = 0
        snapshot["last_delta_events"] = 0
        save_state(state_path, snapshot)
        print("BaselineSaved")
        print(snapshot)
        print(f"StateFile: {state_path}")
        return 0

    delta_events = max(0, int(snapshot["events"]) - int(prev.get("events", 0)))
    delta_alerts = max(0, int(snapshot["alerts"]) - int(prev.get("alerts", 0)))
    delta_uniq_alerts = max(0, int(snapshot["uniq_alerts"]) - int(prev.get("uniq_alerts", 0)))
    duplicate_delta = max(0, delta_alerts - delta_uniq_alerts)

    prev_delta_ratio = prev.get("last_delta_alerts_per_1k")
    prev_delta_alerts = int(prev.get("last_delta_alerts", 0) or 0)
    delta_ratio = (delta_alerts * 1000.0 / delta_events) if delta_events > 0 else 0.0
    if isinstance(prev_delta_ratio, (int, float)):
        ref = float(prev_delta_ratio)
    else:
        ref = delta_ratio
    band_pct = abs(delta_ratio - ref) * 100.0 / ref if ref > 0 else 0.0

    print(f"DeltaEvents={delta_events}")
    print(f"DeltaAlerts={delta_alerts}")
    print(f"DeltaUniqueAlerts={delta_uniq_alerts}")
    print(f"DeltaDuplicateAlerts={duplicate_delta}")
    print(f"PrevDeltaAlertsPer1k={float(prev_delta_ratio) if isinstance(prev_delta_ratio,(int,float)) else -1:.4f}")
    print(f"DeltaAlertsPer1k={delta_ratio:.4f}")
    print(f"BandPct={band_pct:.4f}")

    ok = True
    if duplicate_delta > 0:
        print("FAIL: duplicate alert_id detected in delta")
        ok = False
    band_check_applicable = (
        isinstance(prev_delta_ratio, (int, float))
        and prev_delta_alerts >= args.min_delta_alerts_for_band
        and delta_alerts >= args.min_delta_alerts_for_band
    )
    if band_check_applicable:
        if band_pct > args.max_band_pct:
            print(f"FAIL: alerts_per_1k deviation > {args.max_band_pct}%")
            ok = False
    else:
        print(
            "BandCheckSkipped: insufficient alert volume for stable ratio "
            f"(prev_delta_alerts={prev_delta_alerts}, delta_alerts={delta_alerts}, "
            f"min_required={args.min_delta_alerts_for_band})"
        )

    snapshot["last_delta_alerts_per_1k"] = delta_ratio
    snapshot["last_delta_alerts"] = delta_alerts
    snapshot["last_delta_events"] = delta_events
    save_state(state_path, snapshot)

    print("STABILITY PASS" if ok else "STABILITY FAIL")
    return 0 if ok else 2


if __name__ == "__main__":
    raise SystemExit(main())
