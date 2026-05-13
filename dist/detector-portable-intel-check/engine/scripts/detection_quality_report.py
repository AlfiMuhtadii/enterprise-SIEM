#!/usr/bin/env python3
"""
Generate a detection quality rollup from benchmark, validation, and history data.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any, Dict

from realtime_detector_consumer import build_dsn_from_env, connect_db


def load(path: str) -> Dict[str, Any]:
    p = Path(path)
    if not p.exists():
        return {}
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except json.JSONDecodeError:
        return {}


def fetch_history(dsn: str, limit: int) -> list[dict[str, Any]]:
    root = Path(__file__).resolve().parents[1]
    _driver, conn = connect_db(dsn or build_dsn_from_env(root))
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT measured_at,metric_type,source,precision,recall,false_positive_rate,
                   false_negative_rate,avg_detection_latency_sec,alert_volume,incident_volume,
                   rule_tests_passed,rule_tests_failed
            FROM detection_quality_history
            ORDER BY measured_at DESC
            LIMIT %s
            """,
            (limit,),
        )
        rows = [
            {
                "measured_at": str(r[0]),
                "metric_type": r[1],
                "source": r[2],
                "precision": r[3],
                "recall": r[4],
                "false_positive_rate": r[5],
                "false_negative_rate": r[6],
                "avg_detection_latency_sec": r[7],
                "alert_volume": r[8],
                "incident_volume": r[9],
                "rule_tests_passed": r[10],
                "rule_tests_failed": r[11],
            }
            for r in cur.fetchall()
        ]
    conn.close()
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate detection quality report")
    parser.add_argument("--benchmark", default="reports/detection_benchmark.json")
    parser.add_argument("--dataset-validation", default="reports/real_dataset_validation_demo.json")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--history-limit", type=int, default=20)
    parser.add_argument("--output", default="reports/detection_quality_rollup.json")
    args = parser.parse_args()
    benchmark = load(args.benchmark)
    dataset = load(args.dataset_validation)
    history = fetch_history(args.dsn, args.history_limit) if args.dsn or build_dsn_from_env(Path(__file__).resolve().parents[1]) else []
    current = benchmark.get("summary", {})
    dataset_summary = dataset.get("summary", {})
    report = {
        "current_benchmark": current,
        "real_dataset_trial": dataset_summary,
        "history": history,
        "quality_gates": {
            "precision_ok": current.get("precision", 0) >= 0.8 if current else None,
            "recall_ok": current.get("recall", 0) >= 0.8 if current else None,
            "false_positive_rate_ok": current.get("false_positive_rate", 1) <= 0.1 if current else None,
            "dataset_replay_stable": dataset.get("replay_stability", {}).get("stable_stdout") if dataset else None,
        },
    }
    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report["quality_gates"], indent=2))
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

