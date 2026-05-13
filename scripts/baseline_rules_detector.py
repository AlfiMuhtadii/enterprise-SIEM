#!/usr/bin/env python3
"""
Phase 4 baseline detector (rule-based) for academic comparison.

Input:
  - CSV exported by security:export-dataset / export_labeled_dataset.py

Output:
  - baseline_rules_report.json containing confusion matrix and summary metrics
"""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter, defaultdict, deque
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from typing import Deque, Dict, List, Optional, Tuple


CLASSES = ["normal", "bruteforce", "scan", "injection"]


@dataclass
class Thresholds:
    brute_force_window_min: int = 5
    brute_force_failed_min: int = 15
    stuffing_window_min: int = 10
    stuffing_failed_min: int = 20
    stuffing_unique_emails_min: int = 10
    scan_window_min: int = 2
    scan_404_min: int = 20
    scan_unique_paths_min: int = 20


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run baseline rules detector and output confusion matrix report.")
    parser.add_argument(
        "--input",
        default="storage/app/security_dataset.csv",
        help="Input labeled CSV path",
    )
    parser.add_argument(
        "--output",
        default="storage/app/baseline_rules_report.json",
        help="Output report JSON path",
    )
    return parser.parse_args()


def parse_ts(value: str) -> datetime:
    # Supports ISO-8601 timestamp formats produced by Laravel/Postgres exports.
    normalized = value.replace("Z", "+00:00")
    return datetime.fromisoformat(normalized)


def as_int(value: Optional[str]) -> Optional[int]:
    if value is None:
        return None
    value = value.strip()
    if value == "":
        return None
    try:
        return int(value)
    except ValueError:
        return None


def as_bool(value: Optional[str]) -> bool:
    if value is None:
        return False
    value = value.strip().lower()
    return value in {"1", "true", "t", "yes", "y"}


def safe_label(value: Optional[str]) -> str:
    if not value:
        return "normal"
    value = value.strip().lower()
    return value if value in CLASSES else "normal"


def trim_queue(q: Deque[Tuple[datetime, Dict[str, str]]], now_ts: datetime, window: timedelta) -> None:
    cutoff = now_ts - window
    while q and q[0][0] < cutoff:
        q.popleft()


def predict_label_for_event(
    row: Dict[str, str],
    ts: datetime,
    ip_state: Dict[str, Dict[str, Deque[Tuple[datetime, Dict[str, str]]]]],
    thresholds: Thresholds,
) -> Tuple[str, List[str], Dict[str, int]]:
    ip = (row.get("ip") or "").strip()
    event_type = (row.get("event_type") or "").strip()
    path = (row.get("path") or "").strip()
    status = as_int(row.get("status"))
    email_hash = (row.get("email_hash") or "").strip()
    has_sql = as_bool(row.get("has_sql_keywords"))
    has_script = as_bool(row.get("has_script_payload"))

    if ip not in ip_state:
        ip_state[ip] = {
            "failed_logins": deque(),
            "http_404": deque(),
        }

    failed_q = ip_state[ip]["failed_logins"]
    scan_q = ip_state[ip]["http_404"]

    if event_type == "auth_login_failed":
        failed_q.append((ts, row))

    if event_type == "http_request":
        scan_q.append((ts, row))

    trim_queue(failed_q, ts, timedelta(minutes=thresholds.brute_force_window_min))
    brute_failed = sum(1 for _, r in failed_q if (r.get("event_type") or "") == "auth_login_failed")

    trim_queue(failed_q, ts, timedelta(minutes=thresholds.stuffing_window_min))
    stuffing_failed = sum(1 for _, r in failed_q if (r.get("event_type") or "") == "auth_login_failed")
    stuffing_email_set = {
        (r.get("email_hash") or "").strip()
        for _, r in failed_q
        if (r.get("event_type") or "") == "auth_login_failed" and (r.get("email_hash") or "").strip()
    }
    stuffing_unique_emails = len(stuffing_email_set)

    trim_queue(scan_q, ts, timedelta(minutes=thresholds.scan_window_min))
    scan_404_count = sum(
        1
        for _, r in scan_q
        if as_int(r.get("status")) == 404 and (r.get("event_type") or "") == "http_request"
    )
    scan_unique_paths = len(
        {
            (r.get("path") or "").strip()
            for _, r in scan_q
            if (r.get("event_type") or "") == "http_request"
        }
    )

    rules_triggered: List[str] = []

    if has_sql or has_script:
        rules_triggered.append("INJECTION_INDICATOR")

    if status == 403 and path == "/admin":
        rules_triggered.append("PRIVILEGE_PROBING")
    if event_type == "authorization_denied":
        rules_triggered.append("PRIVILEGE_PROBING")

    if brute_failed >= thresholds.brute_force_failed_min:
        rules_triggered.append("BRUTE_FORCE_IP")

    if (
        stuffing_failed >= thresholds.stuffing_failed_min
        and stuffing_unique_emails >= thresholds.stuffing_unique_emails_min
    ):
        rules_triggered.append("CREDENTIAL_STUFFING")

    if scan_404_count >= thresholds.scan_404_min or scan_unique_paths >= thresholds.scan_unique_paths_min:
        rules_triggered.append("SCAN_BURST")

    # Priority for mapping rules -> canonical class label.
    if "INJECTION_INDICATOR" in rules_triggered:
        pred = "injection"
    elif "BRUTE_FORCE_IP" in rules_triggered or "CREDENTIAL_STUFFING" in rules_triggered:
        pred = "bruteforce"
    elif "SCAN_BURST" in rules_triggered or "PRIVILEGE_PROBING" in rules_triggered:
        pred = "scan"
    else:
        pred = "normal"

    debug = {
        "brute_failed_5m": brute_failed,
        "stuffing_failed_10m": stuffing_failed,
        "stuffing_unique_emails_10m": stuffing_unique_emails,
        "scan_404_2m": scan_404_count,
        "scan_unique_paths_2m": scan_unique_paths,
        "email_hash_present": 1 if email_hash else 0,
    }
    return pred, rules_triggered, debug


def compute_metrics(confusion: Dict[str, Dict[str, int]]) -> Dict[str, object]:
    total = sum(confusion[t][p] for t in CLASSES for p in CLASSES)
    correct = sum(confusion[c][c] for c in CLASSES)
    accuracy = (correct / total) if total else 0.0

    per_class: Dict[str, Dict[str, float]] = {}
    for cls in CLASSES:
        tp = confusion[cls][cls]
        fp = sum(confusion[t][cls] for t in CLASSES if t != cls)
        fn = sum(confusion[cls][p] for p in CLASSES if p != cls)
        precision = tp / (tp + fp) if (tp + fp) else 0.0
        recall = tp / (tp + fn) if (tp + fn) else 0.0
        f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0
        per_class[cls] = {
            "precision": round(precision, 6),
            "recall": round(recall, 6),
            "f1": round(f1, 6),
            "support": sum(confusion[cls][p] for p in CLASSES),
        }

    return {
        "accuracy": round(accuracy, 6),
        "total_samples": total,
        "per_class": per_class,
    }


def main() -> int:
    args = parse_args()
    input_path = Path(args.input).resolve()
    output_path = Path(args.output).resolve()

    if not input_path.exists():
        print(f"ERROR: input file not found: {input_path}")
        return 1

    thresholds = Thresholds()
    ip_state: Dict[str, Dict[str, Deque[Tuple[datetime, Dict[str, str]]]]] = {}
    confusion: Dict[str, Dict[str, int]] = {t: {p: 0 for p in CLASSES} for t in CLASSES}
    rule_counter: Counter[str] = Counter()

    samples = 0
    with input_path.open("r", encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        rows = list(reader)

    # Ensure deterministic time ordering even if CSV was appended out of order.
    rows.sort(key=lambda r: parse_ts(r.get("ts", "")))

    for row in rows:
        ts_raw = row.get("ts", "")
        if not ts_raw:
            continue
        ts = parse_ts(ts_raw)

        true_label = safe_label(row.get("label"))
        pred_label, rules_triggered, _debug = predict_label_for_event(row, ts, ip_state, thresholds)

        confusion[true_label][pred_label] += 1
        for rule_name in set(rules_triggered):
            rule_counter[rule_name] += 1
        samples += 1

    metrics = compute_metrics(confusion)

    report = {
        "generated_at": datetime.now().astimezone().isoformat(),
        "input": str(input_path),
        "output": str(output_path),
        "classes": CLASSES,
        "thresholds": thresholds.__dict__,
        "samples": samples,
        "confusion_matrix": confusion,
        "rule_trigger_counts": dict(rule_counter),
        "metrics": metrics,
    }

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(report, indent=2), encoding="utf-8")

    print(f"Processed: {samples}")
    print(f"Output: {output_path}")
    print(f"Accuracy: {metrics['accuracy']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
