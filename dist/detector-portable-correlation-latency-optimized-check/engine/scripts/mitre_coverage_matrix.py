#!/usr/bin/env python3
"""
Build a MITRE ATT&CK coverage matrix from security_alerts evidence.
"""

from __future__ import annotations

import argparse
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Set

from realtime_detector_consumer import build_dsn_from_env, connect_db


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build MITRE coverage matrix from security_alerts")
    parser.add_argument("--expectations", default="tools/attack-lab/coverage/mitre-advanced-coverage.json")
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--output", default="")
    return parser.parse_args()


def load_json(path: Path) -> Dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def fetch_alerts(conn: Any, minutes: int) -> List[Dict[str, Any]]:
    sql = """
    SELECT alert_type, severity, detected_at, evidence
    FROM security_alerts
    WHERE detected_at >= now() - (%s::text)::interval
    ORDER BY detected_at DESC
    """
    with conn.cursor() as cur:
        cur.execute(sql, (f"{max(1, minutes)} minutes",))
        rows = cur.fetchall()
    alerts: List[Dict[str, Any]] = []
    for alert_type, severity, detected_at, evidence in rows:
        parsed = evidence if isinstance(evidence, dict) else {}
        if isinstance(evidence, str):
            try:
                data = json.loads(evidence)
                parsed = data if isinstance(data, dict) else {}
            except json.JSONDecodeError:
                parsed = {}
        alerts.append({"alert_type": alert_type, "severity": severity, "detected_at": str(detected_at), "evidence": parsed})
    return alerts


def techniques_for_alert(alert: Dict[str, Any]) -> Set[str]:
    evidence = alert.get("evidence") if isinstance(alert.get("evidence"), dict) else {}
    mitre = evidence.get("mitre_attack", [])
    out: Set[str] = set()
    if isinstance(mitre, list):
        for item in mitre:
            if isinstance(item, dict) and item.get("technique"):
                out.add(str(item["technique"]))
    return out


def build_matrix(expectations: Dict[str, Any], alerts: List[Dict[str, Any]]) -> Dict[str, Any]:
    rows: List[Dict[str, Any]] = []
    passed = 0
    for item in expectations.get("techniques", []):
        technique = str(item.get("technique", ""))
        matching = [alert for alert in alerts if technique in techniques_for_alert(alert)]
        status = "PASS" if matching else "FAIL"
        if matching:
            passed += 1
        rows.append(
            {
                "tactic": item.get("tactic"),
                "technique": technique,
                "name": item.get("name"),
                "expected": bool(item.get("expected", True)),
                "status": status,
                "detected": bool(matching),
                "alert_count": len(matching),
                "evidence": [
                    {
                        "alert_type": alert["alert_type"],
                        "severity": alert["severity"],
                        "detected_at": alert["detected_at"],
                    }
                    for alert in matching[:5]
                ],
            }
        )
    total = len(rows)
    return {
        "name": expectations.get("name", "mitre-coverage"),
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "summary": {"total": total, "passed": passed, "failed": total - passed, "pass_rate": passed / total if total else 0},
        "matrix": rows,
    }


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    expectations_path = (root / args.expectations).resolve()
    expectations = load_json(expectations_path)
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    _driver, conn = connect_db(dsn)
    try:
        alerts = fetch_alerts(conn, args.minutes)
    finally:
        conn.close()
    report = build_matrix(expectations, alerts)
    text = json.dumps(report, indent=2, ensure_ascii=False)
    print(text)
    if args.output:
        out = (root / args.output).resolve()
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(text, encoding="utf-8")
    return 0 if report["summary"]["failed"] == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
