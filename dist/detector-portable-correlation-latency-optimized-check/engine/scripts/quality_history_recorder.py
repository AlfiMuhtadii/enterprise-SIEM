#!/usr/bin/env python3
"""
Record detection quality history from benchmark, false-positive, and rule quality reports.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any, Dict

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Record detection quality metrics into Postgres")
    parser.add_argument("--benchmark", default="reports/detection_benchmark.json")
    parser.add_argument("--false-positive", default="reports/false_positive_evaluation.json")
    parser.add_argument("--rule-quality", default="reports/rule_quality_report.json")
    parser.add_argument("--source", default="local")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    return parser.parse_args()


def load(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    benchmark = load(root / args.benchmark)
    fp = load(root / args.false_positive)
    rq = load(root / args.rule_quality)
    summary = benchmark.get("summary", {})
    regression = rq.get("regression", {}).get("summary", {})
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    _driver, conn = connect_db(dsn)
    conn.autocommit = False
    with conn.cursor() as cur:
        cur.execute("SELECT count(*) FROM security_alerts WHERE detected_at >= now() - interval '24 hours'")
        alert_volume = int(cur.fetchone()[0])
        cur.execute("SELECT count(*) FROM security_incidents WHERE last_seen_at >= now() - interval '24 hours'")
        incident_volume = int(cur.fetchone()[0])
        cur.execute(
            """
            INSERT INTO detection_quality_history (
                measured_at, metric_type, source, precision, recall, false_positive_rate,
                false_negative_rate, avg_detection_latency_sec, alert_volume, incident_volume,
                rule_tests_passed, rule_tests_failed, details, created_at, updated_at
            ) VALUES (now(),'operational',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,now(),now())
            """,
            (
                args.source,
                summary.get("precision"),
                summary.get("recall"),
                fp.get("false_positive_rate_per_event", summary.get("false_positive_rate")),
                summary.get("false_negative_rate"),
                summary.get("avg_detection_latency_sec"),
                alert_volume,
                incident_volume,
                int(regression.get("passed", 0)),
                int(regression.get("failed", 0)),
                json.dumps({"benchmark": benchmark, "false_positive": fp, "rule_quality": rq}),
            ),
        )
    conn.commit()
    conn.close()
    print("quality_history_recorded=1")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
