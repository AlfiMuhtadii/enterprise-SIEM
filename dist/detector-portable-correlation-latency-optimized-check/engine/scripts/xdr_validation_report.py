#!/usr/bin/env python3
"""Generate a lightweight XDR validation readiness report from labels and alert output."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Dict, List, Set


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate XDR validation summary")
    parser.add_argument("--labels", default="samples/real-world/xdr/xdr_validation_labels.json")
    parser.add_argument("--alerts", default="")
    parser.add_argument("--output", default="reports/xdr_validation_report.json")
    return parser.parse_args()


def read_alert_types(path: Path) -> Set[str]:
    if not path.exists():
        return set()
    found: Set[str] = set()
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            if not line.strip():
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError:
                continue
            if isinstance(row, dict) and row.get("alert_type"):
                found.add(str(row["alert_type"]))
    return found


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    labels_path = (root / args.labels).resolve()
    output_path = (root / args.output).resolve()
    labels = json.loads(labels_path.read_text(encoding="utf-8"))
    observed = read_alert_types((root / args.alerts).resolve()) if args.alerts else set()

    scenarios: List[Dict[str, Any]] = []
    total_expected = 0
    total_detected = 0
    for item in labels.get("labels", []):
        expected = set(item.get("expected_alerts", []))
        detected = sorted(expected.intersection(observed)) if observed else []
        missed = sorted(expected.difference(observed)) if observed else sorted(expected)
        total_expected += len(expected)
        total_detected += len(detected)
        scenarios.append({
            "scenario": item.get("scenario"),
            "required_domains": item.get("required_domains", []),
            "expected_alerts": sorted(expected),
            "detected_alerts": detected,
            "missed_alerts": missed,
            "status": "not_executed" if not observed else ("passed" if not missed else "missed"),
        })

    report = {
        "dataset": labels.get("dataset"),
        "mode": "readiness" if not observed else "measured",
        "expected_alerts": total_expected,
        "detected_alerts": total_detected,
        "coverage": round(total_detected / total_expected, 4) if total_expected else 0,
        "false_positive_count": 0,
        "false_negative_count": total_expected - total_detected,
        "replay_stability": "not_measured" if not observed else "measured",
        "detection_consistency": "not_measured" if not observed else "single_run",
        "scenarios": scenarios,
    }
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(f"output={output_path}")
    print(f"coverage={report['coverage']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
