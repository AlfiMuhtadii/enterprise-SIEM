#!/usr/bin/env python3
"""
Evaluate false positives against known-normal telemetry JSONL using the same
declarative rules and baseline suppression as production detection.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Dict, List

from telemetry_rule_engine import evaluate_rules, load_rules, validate_rules
from telemetry_correlation_detector import load_baseline, parse_dt


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Evaluate false positives for known-normal telemetry")
    parser.add_argument("--normal-file", default="storage/logs/telemetry_normal.jsonl")
    parser.add_argument("--rules", default="storage/app/telemetry_rules.json")
    parser.add_argument("--baseline", default="storage/app/telemetry_baseline.json")
    parser.add_argument("--output", default="reports/false_positive_evaluation.json")
    return parser.parse_args()


def load_jsonl(path: Path) -> List[Dict[str, Any]]:
    events: List[Dict[str, Any]] = []
    if not path.exists():
        return events
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            if not line.strip():
                continue
            ev = json.loads(line)
            if isinstance(ev, dict):
                ev["ts"] = parse_dt(ev.get("ts"))
                ev["payload"] = ev
                events.append(ev)
    return events


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    events = load_jsonl((root / args.normal_file).resolve())
    rules_payload = load_rules((root / args.rules).resolve())
    errors = validate_rules(rules_payload)
    if errors:
        for err in errors:
            print(f"RULE_ERROR: {err}")
        return 2
    baseline = load_baseline((root / args.baseline).resolve())
    rows = evaluate_rules(events, rules_payload["rules"], baseline, step_sec=60, app_key="false-positive-eval")
    report = {
        "normal_file": args.normal_file,
        "normal_events": len(events),
        "rules": len(rules_payload["rules"]),
        "false_positive_alerts": len(rows),
        "false_positive_rate_per_event": len(rows) / len(events) if events else 0.0,
        "status": "PASS" if len(rows) == 0 else "FAIL",
        "triggered_alert_types": sorted({row[2] for row in rows}),
    }
    out = (root / args.output).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    print(f"output={out}")
    return 0 if len(rows) == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
