#!/usr/bin/env python3
"""
Minimal drift monitor using PSI on top features from model drift_profile.

Data source: security_dataset.csv (recent window vs baseline profile from ml_models).
If threshold exceeded, inserts DRIFT_DETECTED alert to security_alerts.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from train_ai_detector import build_features, load_csv


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="PSI drift monitor")
    parser.add_argument("--dataset", default="storage/app/security_dataset.csv")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--env", default="local")
    parser.add_argument("--lookback-hours", type=int, default=24)
    parser.add_argument("--psi-threshold", type=float, default=0.25)
    parser.add_argument("--output", default="storage/app/ml_drift_report.json")
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
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

        return "psycopg3", psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore

        return "psycopg2", psycopg2.connect(dsn)


def hmac_hex(secret: str, text: str) -> str:
    if secret.startswith("base64:"):
        try:
            import base64

            k = base64.b64decode(secret[7:])
            return hmac.new(k, text.encode("utf-8"), hashlib.sha256).hexdigest()
        except Exception:
            pass
    return hmac.new(secret.encode("utf-8"), text.encode("utf-8"), hashlib.sha256).hexdigest()


def histogram_pct(values: List[float], edges: List[float]) -> List[float]:
    if not edges or len(edges) < 2:
        return [1.0]
    counts = [0] * (len(edges) - 1)
    if not values:
        n = len(counts)
        return [1.0 / n] * n
    for v in values:
        placed = False
        for i in range(len(edges) - 1):
            left, right = float(edges[i]), float(edges[i + 1])
            if i == len(edges) - 2:
                if left <= v <= right:
                    counts[i] += 1
                    placed = True
                    break
            if left <= v < right:
                counts[i] += 1
                placed = True
                break
        if not placed:
            if v < float(edges[0]):
                counts[0] += 1
            else:
                counts[-1] += 1
    total = sum(counts) or 1
    return [c / total for c in counts]


def psi(expected: List[float], actual: List[float], eps: float = 1e-6) -> float:
    total = 0.0
    for e, a in zip(expected, actual):
        ee = max(e, eps)
        aa = max(a, eps)
        total += (aa - ee) * __import__("math").log(aa / ee)
    return float(total)


def parse_ts(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dataset_path = (root / args.dataset).resolve()
    output_path = (root / args.output).resolve()

    if not dataset_path.exists():
        print(f"ERROR: dataset not found: {dataset_path}")
        return 1

    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1

    driver, conn = connect_db(dsn)
    conn.autocommit = False
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT d.id, d.model_id, m.model_key, m.artifact_sha256, m.drift_profile
                FROM ml_model_deployments d
                JOIN ml_models m ON m.id = d.model_id
                WHERE d.environment = %s AND d.is_active = true
                ORDER BY d.deployed_at DESC
                LIMIT 1
                """,
                (args.env,),
            )
            row = cur.fetchone()
            if row is None:
                print(f"ERROR: no active deployment in env={args.env}")
                return 1
            deployment_id = int(row[0])
            model_id = int(row[1])
            model_key = str(row[2])
            artifact_sha = str(row[3])
            drift_profile = row[4]
            if isinstance(drift_profile, str):
                drift_profile = json.loads(drift_profile)
            if not isinstance(drift_profile, dict):
                print("ERROR: drift_profile missing in ml_models")
                return 1

        rows = build_features(load_csv(dataset_path))
        if not rows:
            print("ERROR: dataset has no rows")
            return 1
        max_ts = max(parse_ts(str(r["ts"])) for r in rows)
        cutoff = max_ts - timedelta(hours=max(1, args.lookback_hours))
        recent = [r for r in rows if parse_ts(str(r["ts"])) >= cutoff]
        if not recent:
            print("ERROR: no recent rows in lookback window")
            return 1

        feature_results: Dict[str, Any] = {}
        drifted_features: List[str] = []
        features_cfg = drift_profile.get("features", {})
        for feat, cfg in features_cfg.items():
            if feat not in recent[0]:
                continue
            edges = [float(x) for x in cfg.get("edges", [])]
            expected_pct = [float(x) for x in cfg.get("baseline_pct", [])]
            vals = [float(r.get(feat, 0) or 0) for r in recent]
            current_pct = histogram_pct(vals, edges)
            n = min(len(expected_pct), len(current_pct))
            psi_val = psi(expected_pct[:n], current_pct[:n])
            feature_results[feat] = {
                "psi": round(psi_val, 6),
                "threshold": args.psi_threshold,
                "is_drift": bool(psi_val >= args.psi_threshold),
            }
            if psi_val >= args.psi_threshold:
                drifted_features.append(feat)

        drift_detected = len(drifted_features) > 0
        report = {
            "generated_at": datetime.now().astimezone().isoformat(),
            "environment": args.env,
            "deployment_id": deployment_id,
            "model_id": model_id,
            "model_key": model_key,
            "artifact_sha256": artifact_sha,
            "lookback_hours": args.lookback_hours,
            "recent_rows": len(recent),
            "psi_threshold": args.psi_threshold,
            "drift_detected": drift_detected,
            "drifted_features": drifted_features,
            "features": feature_results,
        }

        if drift_detected:
            now_ts = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
            alert_key = f"{args.env}|{model_id}|{now_ts}|{','.join(sorted(drifted_features))}"
            alert_id = hmac_hex(args.app_key, alert_key)
            evidence = {
                "model_id": model_id,
                "model_key": model_key,
                "psi_threshold": args.psi_threshold,
                "drifted_features": drifted_features,
                "feature_metrics": feature_results,
            }
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO security_alerts (
                        alert_id, detected_at, alert_type, severity, ip, request_id, event_id_ref,
                        score, model_label, evidence, raw_event, created_at, updated_at
                    ) VALUES (
                        %s, now(), 'DRIFT_DETECTED', 'high', NULL, NULL, NULL,
                        %s, 'drift', %s::jsonb, %s::jsonb, now(), now()
                    )
                    ON CONFLICT (alert_id) DO NOTHING
                    """,
                    (
                        alert_id,
                        0.99,
                        json.dumps(evidence, separators=(",", ":")),
                        json.dumps({"source": "mlops_drift_monitor.py"}, separators=(",", ":")),
                    ),
                )
        conn.commit()
    finally:
        conn.close()

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(f"Output: {output_path}")
    print(f"DriftDetected: {drift_detected}")
    if drift_detected:
        print(f"DriftFeatures: {', '.join(drifted_features)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
