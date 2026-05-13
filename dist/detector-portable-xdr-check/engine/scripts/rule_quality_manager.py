#!/usr/bin/env python3
"""
Rule quality management: schema validation, version checks, enable/disable
listing, and regression tests using rule test_cases.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

from telemetry_correlation_detector import load_baseline
from telemetry_rule_engine import evaluate_rules, load_rules, validate_rules


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Manage and test telemetry rules")
    parser.add_argument("--rules", default="storage/app/telemetry_rules.json")
    parser.add_argument("--baseline", default="storage/app/telemetry_baseline.json")
    parser.add_argument("--action", choices=["validate", "list", "test"], default="validate")
    parser.add_argument("--output", default="reports/rule_quality_report.json")
    return parser.parse_args()


def enrich_event(raw: Dict[str, Any], idx: int) -> Dict[str, Any]:
    ev = dict(raw)
    ev.setdefault("schema_version", 1)
    ev.setdefault("ts", datetime.now(timezone.utc))
    ev.setdefault("event_id", hashlib.sha256(json.dumps(raw, sort_keys=True).encode("utf-8")).hexdigest()[:40] + str(idx))
    ev.setdefault("host_id", "test-host")
    ev.setdefault("payload", dict(raw))
    return ev


def run_tests(rules: List[Dict[str, Any]], baseline: Dict[str, Any]) -> Dict[str, Any]:
    results = []
    passed = failed = 0
    for rule in rules:
        cases = rule.get("test_cases", [])
        if not isinstance(cases, list):
            continue
        for case_idx, case in enumerate(cases):
            events = [enrich_event(ev, i) for i, ev in enumerate(case.get("events", []))]
            rows = evaluate_rules(events, [rule], baseline, step_sec=60, app_key="rule-test")
            expected = bool(case.get("expect_alert", True))
            ok = bool(rows) == expected
            passed += 1 if ok else 0
            failed += 0 if ok else 1
            results.append(
                {
                    "rule_id": rule.get("rule_id"),
                    "case": case.get("name", f"case-{case_idx}"),
                    "expected_alert": expected,
                    "observed_alerts": len(rows),
                    "status": "PASS" if ok else "FAIL",
                }
            )
    return {"summary": {"passed": passed, "failed": failed, "total": passed + failed}, "tests": results}


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    payload = load_rules((root / args.rules).resolve())
    errors = validate_rules(payload)
    rules = payload.get("rules", [])
    report: Dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "rule_file": args.rules,
        "rule_count": len(rules) if isinstance(rules, list) else 0,
        "errors": errors,
    }
    if args.action == "list":
        report["rules"] = [
            {
                "rule_id": r.get("rule_id"),
                "rule_version": r.get("rule_version"),
                "enabled": r.get("enabled", True),
                "severity": r.get("severity_override") or r.get("severity"),
                "owner": (r.get("metadata") or {}).get("owner"),
                "status": (r.get("metadata") or {}).get("status"),
            }
            for r in rules
        ]
    if args.action == "test":
        baseline = load_baseline((root / args.baseline).resolve())
        report["regression"] = run_tests(rules, baseline)
    out = (root / args.output).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if not errors and report.get("regression", {}).get("summary", {}).get("failed", 0) == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
