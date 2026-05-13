#!/usr/bin/env python3
"""
Validate detector coverage assertions against security_alerts.
"""

from __future__ import annotations

import argparse
import json
import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, List, Tuple

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build detector assertion and coverage matrix.")
    parser.add_argument("--expectations", default="tools/attack-lab/coverage/web-basic-coverage.json")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--minutes", type=int, default=0, help="Override expectation window_minutes when >0")
    parser.add_argument("--output", default="")
    parser.add_argument("--format", choices=["text", "json"], default="text")
    return parser.parse_args()


def load_expectations(path: Path) -> Dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("expectations file must be a JSON object")
    if not isinstance(data.get("expectations"), list):
        raise ValueError("expectations file must contain expectations array")
    return data


def fetch_alert_counts(conn: Any, since: datetime) -> Dict[str, int]:
    sql = """
    SELECT alert_type, count(*)
    FROM security_alerts
    WHERE detected_at >= %s
    GROUP BY alert_type
    ORDER BY alert_type
    """
    out: Dict[str, int] = {}
    with conn.cursor() as cur:
        cur.execute(sql, (since,))
        for alert_type, count in cur.fetchall():
            out[str(alert_type)] = int(count)
    return out


def evaluate(expectations: Dict[str, Any], counts: Dict[str, int], minutes: int) -> Dict[str, Any]:
    rows: List[Dict[str, Any]] = []
    passed = 0
    failed = 0
    for item in expectations.get("expectations", []):
        if not isinstance(item, dict):
            continue
        alert_types = [str(x) for x in item.get("any_of", [])]
        min_count = int(item.get("min_count", 1) or 1)
        observed = sum(counts.get(alert_type, 0) for alert_type in alert_types)
        ok = observed >= min_count
        if ok:
            passed += 1
        else:
            failed += 1
        rows.append(
            {
                "id": str(item.get("id", "")),
                "description": str(item.get("description", "")),
                "expected_any_of": alert_types,
                "min_count": min_count,
                "observed_count": observed,
                "status": "PASS" if ok else "FAIL",
                "severity": str(item.get("severity", "")),
            }
        )
    return {
        "name": str(expectations.get("name", "coverage")),
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "window_minutes": minutes,
        "summary": {
            "total": len(rows),
            "passed": passed,
            "failed": failed,
            "pass_rate": round((passed / len(rows)) if rows else 0.0, 6),
        },
        "alert_counts": counts,
        "matrix": rows,
    }


def render_text(report: Dict[str, Any]) -> str:
    lines = [
        "=== Detector Coverage Matrix ===",
        f"Name: {report['name']}",
        f"Window: last {report['window_minutes']} minutes",
        f"Summary: {report['summary']['passed']}/{report['summary']['total']} passed ({report['summary']['pass_rate']})",
        "",
        "Alert counts:",
    ]
    for alert_type, count in sorted(report["alert_counts"].items()):
        lines.append(f"- {alert_type}: {count}")
    lines.append("")
    lines.append("Assertions:")
    for row in report["matrix"]:
        expected = ", ".join(row["expected_any_of"])
        lines.append(
            f"- [{row['status']}] {row['id']} observed={row['observed_count']} min={row['min_count']} any_of=[{expected}]"
        )
    return "\n".join(lines)


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    expectations_path = Path(args.expectations)
    if not expectations_path.is_absolute():
        expectations_path = root / expectations_path
    expectations = load_expectations(expectations_path)
    minutes = int(args.minutes) if int(args.minutes) > 0 else int(expectations.get("window_minutes", 15) or 15)

    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn, SECURITY_INGEST_DSN, or Laravel .env pgsql.")
        return 1

    _driver, conn = connect_db(dsn)
    try:
        since = datetime.now(timezone.utc) - timedelta(minutes=minutes)
        counts = fetch_alert_counts(conn, since)
    finally:
        conn.close()

    report = evaluate(expectations, counts, minutes)
    output = json.dumps(report, indent=2) if args.format == "json" else render_text(report)
    if args.output:
        out_path = Path(args.output)
        if not out_path.is_absolute():
            out_path = root / out_path
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(output, encoding="utf-8")
    print(output)
    return 2 if report["summary"]["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
