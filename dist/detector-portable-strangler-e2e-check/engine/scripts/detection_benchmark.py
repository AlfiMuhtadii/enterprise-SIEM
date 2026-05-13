#!/usr/bin/env python3
"""
Formal detection benchmarking from labeled telemetry windows and alerts.

Labels file format:
{
  "windows": [
    {"id":"w1","start":"...","end":"...","expected_alert_types":["..."],"expected_mitre":["T1021"],"is_attack":true}
  ]
}
"""

from __future__ import annotations

import argparse
import json
import os
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Set

from realtime_detector_consumer import build_dsn_from_env, connect_db
from telemetry_correlation_detector import parse_dt


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Compute detection benchmark metrics")
    parser.add_argument("--labels", default="tools/attack-lab/coverage/telemetry-benchmark-labels.json")
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--output", default="reports/detection_benchmark.json")
    return parser.parse_args()


def load_labels(path: Path) -> Dict[str, Any]:
    if not path.exists():
        now = datetime.now(timezone.utc).isoformat()
        return {"generated_default": True, "windows": [{"id": "recent", "start": "1970-01-01T00:00:00+00:00", "end": now, "expected_alert_types": [], "expected_mitre": [], "is_attack": False}]}
    return json.loads(path.read_text(encoding="utf-8"))


def parse_evidence(value: Any) -> Dict[str, Any]:
    if isinstance(value, dict):
        return value
    if isinstance(value, str):
        try:
            data = json.loads(value)
            return data if isinstance(data, dict) else {}
        except json.JSONDecodeError:
            return {}
    return {}


def fetch_alerts(conn: Any, minutes: int) -> List[Dict[str, Any]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT alert_type, detected_at, severity, score, evidence
            FROM security_alerts
            WHERE detected_at >= now() - (%s::text)::interval
            ORDER BY detected_at ASC
            """,
            (f"{max(1, minutes)} minutes",),
        )
        return [
            {"alert_type": r[0], "detected_at": parse_dt(r[1]), "severity": r[2], "score": r[3], "evidence": parse_evidence(r[4])}
            for r in cur.fetchall()
        ]


def mitre(alert: Dict[str, Any]) -> Set[str]:
    out: Set[str] = set()
    for item in alert.get("evidence", {}).get("mitre_attack", []):
        if isinstance(item, dict) and item.get("technique"):
            out.add(str(item["technique"]))
    return out


def benchmark(labels: Dict[str, Any], alerts: List[Dict[str, Any]]) -> Dict[str, Any]:
    tp = fp = tn = fn = 0
    latencies: List[float] = []
    mitre_counts: Counter[str] = Counter()
    window_rows = []
    for window in labels.get("windows", []):
        start = parse_dt(window.get("start"))
        end = parse_dt(window.get("end"))
        expected_alerts = set(window.get("expected_alert_types", []))
        expected_mitre = set(window.get("expected_mitre", []))
        observed = [a for a in alerts if start <= a["detected_at"] <= end]
        observed_types = {a["alert_type"] for a in observed}
        observed_mitre = set().union(*(mitre(a) for a in observed)) if observed else set()
        is_attack = bool(window.get("is_attack", bool(expected_alerts or expected_mitre)))
        detected = bool(observed)
        matched = bool((expected_alerts and observed_types.intersection(expected_alerts)) or (expected_mitre and observed_mitre.intersection(expected_mitre)) or (is_attack and detected and not expected_alerts and not expected_mitre))
        if is_attack and matched:
            tp += 1
            if start.year >= 2000:
                latencies.append(max(0.0, (min(a["detected_at"] for a in observed) - start).total_seconds()))
        elif is_attack and not matched:
            fn += 1
        elif not is_attack and detected:
            fp += 1
        else:
            tn += 1
        for tech in observed_mitre:
            mitre_counts[tech] += 1
        window_rows.append({"id": window.get("id"), "is_attack": is_attack, "status": "TP" if is_attack and matched else "FN" if is_attack else "FP" if detected else "TN", "observed_alert_types": sorted(observed_types), "observed_mitre": sorted(observed_mitre)})
    precision = tp / (tp + fp) if tp + fp else 0.0
    recall = tp / (tp + fn) if tp + fn else 0.0
    fpr = fp / (fp + tn) if fp + tn else 0.0
    fnr = fn / (fn + tp) if fn + tp else 0.0
    return {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "summary": {
            "tp": tp,
            "fp": fp,
            "tn": tn,
            "fn": fn,
            "precision": precision,
            "recall": recall,
            "false_positive_rate": fpr,
            "false_negative_rate": fnr,
            "avg_detection_latency_sec": sum(latencies) / len(latencies) if latencies else None,
        },
        "alert_distribution": dict(Counter(a["alert_type"] for a in alerts)),
        "coverage_by_mitre": dict(mitre_counts),
        "windows": window_rows,
    }


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    labels = load_labels((root / args.labels).resolve())
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    _driver, conn = connect_db(dsn)
    try:
        alerts = fetch_alerts(conn, args.minutes)
    finally:
        conn.close()
    report = benchmark(labels, alerts)
    out = (root / args.output).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report["summary"], indent=2))
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
