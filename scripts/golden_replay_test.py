#!/usr/bin/env python3
"""
Golden-run replay for end-to-end detector validation.

- Validates schema contract.
- Replays 4 datasets: normal, bruteforce, scan, injection.
- Checks rule determinism against the expected baseline.
- Checks ML labels and score signatures only when a model is available.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import random
import uuid
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from realtime_detector_consumer import (
    RealtimeState,
    RuleThresholds,
    evaluate_rules,
    maybe_load_model,
    predict_model,
    vectorize_for_model,
)
from security_event_contract import validate_event


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Golden replay test for rules + ML stability")
    parser.add_argument("--input-dir", default="scripts/golden_runs")
    parser.add_argument("--expected", default="scripts/golden_runs/expected_replay.json")
    parser.add_argument("--model", default="storage/app/ai_detector_model.pkl")
    parser.add_argument("--output", default="storage/app/golden_replay_report.json")
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument(
        "--rules-only",
        action="store_true",
        help="Skip optional local ML artifacts and validate only deterministic rule output.",
    )
    parser.add_argument("--generate-samples", action="store_true")
    parser.add_argument("--write-baseline", action="store_true")
    return parser.parse_args()


def h64(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def make_event(ts: datetime, idx: int, event: str, ip: str, path: str, status: int, **kwargs: Any) -> Dict[str, Any]:
    req_id = str(uuid.uuid5(uuid.NAMESPACE_URL, f"golden-{idx}-{event}-{path}"))
    payload: Dict[str, Any] = {
        "schema_version": 1,
        "ts": ts.isoformat(),
        "event": event,
        "request_id": req_id,
        "ip": ip,
        "user_agent_hash": h64("golden-agent"),
        "user_id": None,
        "email_hash": None,
        "method": "GET" if event == "http_request" else "POST",
        "path": path,
        "status": status,
        "latency_ms": 20,
        "query_hash": None,
        "has_sql_keywords": False,
        "has_script_payload": False,
    }
    payload.update(kwargs)
    return payload


def write_jsonl(path: Path, rows: List[Dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as f:
        for row in rows:
            f.write(json.dumps(row, separators=(",", ":")) + "\n")


def generate_samples(base_dir: Path) -> None:
    base_ts = datetime(2026, 3, 4, 0, 0, 0, tzinfo=timezone.utc)

    normal: List[Dict[str, Any]] = []
    for i in range(6):
        normal.append(
            make_event(
                ts=base_ts + timedelta(seconds=i * 10),
                idx=i,
                event="http_request",
                ip="127.0.0.1",
                path="/dashboard",
                status=200,
            )
        )

    bruteforce: List[Dict[str, Any]] = []
    for i in range(15):
        bruteforce.append(
            make_event(
                ts=base_ts + timedelta(minutes=1, seconds=i),
                idx=100 + i,
                event="auth_login_failed",
                ip="203.0.113.10",
                path="/login",
                status=422,
                email_hash=h64("victim@example.com"),
            )
        )

    scan: List[Dict[str, Any]] = []
    for i in range(25):
        scan.append(
            make_event(
                ts=base_ts + timedelta(minutes=2, seconds=i),
                idx=200 + i,
                event="http_request",
                ip="198.51.100.77",
                path=f"/scan/path-{i:02d}",
                status=404,
            )
        )

    injection: List[Dict[str, Any]] = []
    injection.append(
        make_event(
            ts=base_ts + timedelta(minutes=3, seconds=1),
            idx=300,
            event="http_request",
            ip="192.0.2.55",
            path="/search",
            status=200,
            query_hash=h64("' OR 1=1--"),
            has_sql_keywords=True,
        )
    )
    injection.append(
        make_event(
            ts=base_ts + timedelta(minutes=3, seconds=5),
            idx=301,
            event="http_request",
            ip="192.0.2.55",
            path="/search",
            status=200,
            query_hash=h64("<script>alert(1)</script>"),
            has_script_payload=True,
        )
    )
    injection.append(
        make_event(
            ts=base_ts + timedelta(minutes=3, seconds=9),
            idx=302,
            event="http_request",
            ip="192.0.2.55",
            path="/search",
            status=200,
            query_hash=h64("UNION SELECT password"),
            has_sql_keywords=True,
        )
    )
    injection.append(
        make_event(
            ts=base_ts + timedelta(minutes=3, seconds=12),
            idx=303,
            event="http_request",
            ip="192.0.2.55",
            path="/search",
            status=200,
            query_hash=h64("<img src=x onerror=alert(1)>"),
            has_script_payload=True,
        )
    )

    write_jsonl(base_dir / "normal.jsonl", normal)
    write_jsonl(base_dir / "bruteforce.jsonl", bruteforce)
    write_jsonl(base_dir / "scan.jsonl", scan)
    write_jsonl(base_dir / "injection.jsonl", injection)


def load_events(path: Path) -> List[Dict[str, Any]]:
    rows: List[Dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            text = line.strip()
            if not text:
                continue
            payload = json.loads(text)
            if isinstance(payload, dict):
                rows.append(payload)
    return rows


def replay_one(name: str, rows: List[Dict[str, Any]], model: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    state = RealtimeState()
    thr = RuleThresholds()
    rule_counts: Counter[str] = Counter()
    ml_counts: Counter[str] = Counter()
    invalid = 0
    ml_sig_rows: List[str] = []

    for row in rows:
        ok, _errors = validate_event(row)
        if not ok:
            invalid += 1
            continue

        snapshot = state.update(row)
        for alert_type, _severity, _score in evaluate_rules(row, snapshot, thr):
            rule_counts[alert_type] += 1

        if model is not None:
            x = vectorize_for_model(row, snapshot, model["vectorizer"])
            pred_label, score = predict_model(model, x)
            ml_counts[pred_label] += 1
            ml_sig_rows.append(f"{pred_label}:{score:.6f}")

    ml_signature = hashlib.sha256("|".join(ml_sig_rows).encode("utf-8")).hexdigest() if ml_sig_rows else None
    return {
        "name": name,
        "events": len(rows),
        "invalid": invalid,
        "rules": dict(sorted(rule_counts.items())),
        "ml_counts": dict(sorted(ml_counts.items())),
        "ml_signature": ml_signature,
    }


def compare_expected(
    actual: Dict[str, Any], expected: Dict[str, Any], *, compare_ml: bool = True
) -> Dict[str, Any]:
    rule_mismatches: List[str] = []
    ml_mismatches: List[str] = []
    for key in sorted(set(actual.keys()) | set(expected.keys())):
        if key not in expected:
            rule_mismatches.append(f"unexpected dataset in actual: {key}")
            continue
        if key not in actual:
            rule_mismatches.append(f"missing dataset in actual: {key}")
            continue

        for field in ("events", "invalid", "rules"):
            actual_value = actual[key].get(field)
            expected_value = expected[key].get(field)
            if actual_value != expected_value:
                rule_mismatches.append(
                    f"{key}: {field} mismatch actual={actual_value} expected={expected_value}"
                )

        if not compare_ml:
            continue

        actual_counts = actual[key].get("ml_counts", {})
        expected_counts = expected[key].get("ml_counts", {})
        if actual_counts != expected_counts:
            ml_mismatches.append(
                f"{key}: ml_counts mismatch actual={actual_counts} expected={expected_counts}"
            )

        e_sig = expected[key].get("ml_signature")
        a_sig = actual[key].get("ml_signature")
        if e_sig and a_sig != e_sig:
            ml_mismatches.append(f"{key}: ml_signature mismatch actual={a_sig} expected={e_sig}")

    mismatches = [*rule_mismatches, *ml_mismatches]
    return {
        "ok": len(mismatches) == 0,
        "rules_ok": len(rule_mismatches) == 0,
        "ml_checked": compare_ml,
        "ml_ok": len(ml_mismatches) == 0 if compare_ml else None,
        "mismatches": mismatches,
    }


def main() -> int:
    args = parse_args()
    random.seed(args.seed)

    root = Path(__file__).resolve().parents[1]
    input_dir = (root / args.input_dir).resolve()
    expected_path = (root / args.expected).resolve()
    output_path = (root / args.output).resolve()
    model_path = (root / args.model).resolve()

    if args.generate_samples:
        generate_samples(input_dir)

    required = ["normal.jsonl", "bruteforce.jsonl", "scan.jsonl", "injection.jsonl"]
    for name in required:
        if not (input_dir / name).exists():
            print(f"ERROR: missing dataset file: {input_dir / name}")
            return 1

    model = None if args.rules_only else maybe_load_model(model_path)
    if args.rules_only:
        print("INFO: explicit rules-only mode; ML replay is not part of this gate.")
    elif model is None:
        print("WARNING: model not found, replay runs in rules-only mode.")

    actual: Dict[str, Any] = {}
    for file_name in required:
        dataset = file_name.replace(".jsonl", "")
        rows = load_events(input_dir / file_name)
        actual[dataset] = replay_one(dataset, rows, model)

    report: Dict[str, Any] = {
        "seed": args.seed,
        "model_loaded": model is not None,
        "validation_mode": "rules+ml" if model is not None else "rules-only",
        "datasets": actual,
    }

    if args.write_baseline:
        expected_path.parent.mkdir(parents=True, exist_ok=True)
        expected_path.write_text(json.dumps(actual, indent=2), encoding="utf-8")
        report["baseline_written"] = str(expected_path)
        status_ok = True
    else:
        if not expected_path.exists():
            print(f"ERROR: expected baseline not found: {expected_path}")
            return 1
        expected = json.loads(expected_path.read_text(encoding="utf-8"))
        cmp = compare_expected(actual, expected, compare_ml=model is not None)
        report["comparison"] = cmp
        status_ok = bool(cmp["ok"])

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(f"Output: {output_path}")
    if report.get("comparison"):
        print(f"DeterministicReplay: {report['comparison']['ok']}")
    return 0 if status_ok else 2


if __name__ == "__main__":
    raise SystemExit(main())
