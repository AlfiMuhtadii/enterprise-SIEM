#!/usr/bin/env python3
"""
Train a dependency-free behavioral anomaly profile from normal application events.
"""

from __future__ import annotations

import argparse
import json
import math
from collections import Counter
from datetime import datetime
from pathlib import Path
from statistics import mean, median
from typing import Dict, List

from train_ai_detector import build_features, load_csv


FEATURES = [
    "status",
    "latency_ms",
    "path_len",
    "path_depth",
    "failed_1m",
    "failed_5m",
    "failed_10m",
    "unique_email_10m",
    "req_1m",
    "req_5m",
    "notfound_2m",
    "unique_paths_2m",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Train behavioral anomaly profile from normal events.")
    parser.add_argument("--input", default="storage/app/security_dataset.csv")
    parser.add_argument("--output", default="storage/app/anomaly_profile.json")
    parser.add_argument("--quantile", type=float, default=0.99)
    parser.add_argument("--min-threshold", type=float, default=3.5)
    parser.add_argument("--max-threshold", type=float, default=30.0)
    return parser.parse_args()


def robust_stats(values: List[float]) -> Dict[str, float]:
    if not values:
        return {"median": 0.0, "mad": 1.0, "mean": 0.0, "std": 1.0}
    med = float(median(values))
    deviations = [abs(v - med) for v in values]
    mad = float(median(deviations)) or 1.0
    mu = float(mean(values))
    var = sum((v - mu) ** 2 for v in values) / max(len(values), 1)
    std = math.sqrt(var) or 1.0
    return {"median": med, "mad": mad, "mean": mu, "std": std}


def anomaly_score(row: Dict[str, object], stats: Dict[str, Dict[str, float]]) -> float:
    vals = []
    for feature in FEATURES:
        st = stats[feature]
        value = float(row.get(feature, 0) or 0)
        # 1.4826 converts MAD to an estimate comparable to std for normal data.
        scale = max(st["mad"] * 1.4826, 1.0)
        vals.append(abs(value - st["median"]) / scale)
    if not vals:
        return 0.0
    vals.sort(reverse=True)
    return float(sum(vals[:3]) / min(3, len(vals)))


def quantile(values: List[float], q: float) -> float:
    if not values:
        return 0.0
    q = min(max(q, 0.0), 1.0)
    ordered = sorted(values)
    idx = int(round((len(ordered) - 1) * q))
    return float(ordered[idx])


def main() -> int:
    args = parse_args()
    input_path = Path(args.input).resolve()
    output_path = Path(args.output).resolve()
    if not input_path.exists():
        print(f"ERROR: dataset not found: {input_path}")
        return 1

    rows = build_features(load_csv(input_path))
    normal_rows = [r for r in rows if str(r.get("label", "normal")) == "normal"]
    low_risk_rows = [
        r
        for r in rows
        if int(r.get("has_sql_keywords", 0) or 0) == 0
        and int(r.get("has_script_payload", 0) or 0) == 0
        and int(r.get("failed_1m", 0) or 0) <= 2
        and int(r.get("notfound_2m", 0) or 0) <= 2
        and int(r.get("unique_paths_2m", 0) or 0) <= 5
        and int(r.get("status", 0) or 0) < 500
    ]
    if len(normal_rows) >= 20:
        baseline_rows = normal_rows
        baseline_source = "normal_only"
    elif len(low_risk_rows) >= 20:
        baseline_rows = low_risk_rows
        baseline_source = "low_risk_fallback"
    else:
        baseline_rows = rows
        baseline_source = "all_rows_fallback"

    stats = {
        feature: robust_stats([float(r.get(feature, 0) or 0) for r in baseline_rows])
        for feature in FEATURES
    }
    scores = [anomaly_score(r, stats) for r in baseline_rows]
    threshold = max(float(args.min_threshold), quantile(scores, args.quantile))
    threshold = min(threshold, float(args.max_threshold))

    profile = {
        "profile_type": "robust_behavioral_baseline",
        "created_at": datetime.now().astimezone().isoformat(),
        "input": str(input_path),
        "features": FEATURES,
        "baseline": {
            "source": baseline_source,
            "rows": len(baseline_rows),
            "label_distribution": dict(Counter(str(r.get("label", "normal")) for r in baseline_rows)),
        },
        "scoring": {
            "method": "mean_top3_robust_zscore",
            "threshold": round(threshold, 6),
            "quantile": args.quantile,
            "min_threshold": args.min_threshold,
            "max_threshold": args.max_threshold,
        },
        "feature_stats": stats,
        "training_score_summary": {
            "min": round(min(scores), 6) if scores else 0.0,
            "median": round(float(median(scores)), 6) if scores else 0.0,
            "p99": round(quantile(scores, 0.99), 6) if scores else 0.0,
            "max": round(max(scores), 6) if scores else 0.0,
        },
    }

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(profile, indent=2), encoding="utf-8")
    print(f"Anomaly profile saved: {output_path}")
    print(f"BaselineRows: {len(baseline_rows)}")
    print(f"Threshold: {profile['scoring']['threshold']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
