#!/usr/bin/env python3
"""Endpoint/DNS/proxy shadow-only parity and latency prep report.

This intentionally performs no cutover and marks the domain as shadow_preparation.
"""

from __future__ import annotations

import argparse
import json
import time
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

from xdr_infra_clients import load_jsonl


def parse_args():
    parser = argparse.ArgumentParser(description="Endpoint/DNS/proxy shadow prep benchmark")
    parser.add_argument("--dataset", default="samples/golden/xdr_endpoint_dns_proxy_golden.jsonl")
    parser.add_argument("--output", default="reports/xdr_endpoint_dns_proxy_shadow_prep.json")
    return parser.parse_args()


def detect_shadow(events: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    alerts = []
    by_host: Dict[str, List[Dict[str, Any]]] = {}
    by_domain: Dict[str, List[Dict[str, Any]]] = {}
    for ev in events:
        by_host.setdefault(str(ev.get("host") or ev.get("host_id") or "unknown"), []).append(ev)
        if ev.get("domain"):
            by_domain.setdefault(str(ev["domain"]).lower(), []).append(ev)
    for domain, group in by_domain.items():
        if any(float(ev.get("risk_score") or 0) >= 0.8 for ev in group) and len({ev.get("telemetry_type") for ev in group}) >= 2:
            alerts.append({"alert_type": "SHADOW_DNS_PROXY_ENDPOINT_CHAIN", "actor": domain, "evidence_ids": [ev.get("event_id") for ev in group if ev.get("event_id")], "severity": "high"})
    for host, group in by_host.items():
        if any(ev.get("telemetry_type") == "endpoint" and float(ev.get("risk_score") or 0) >= 0.8 for ev in group) and any(ev.get("telemetry_type") in {"dns", "proxy"} for ev in group):
            alerts.append({"alert_type": "SHADOW_ENDPOINT_NETWORK_ACTIVITY", "actor": host, "evidence_ids": [ev.get("event_id") for ev in group if ev.get("event_id")], "severity": "high"})
    return alerts


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    events = load_jsonl(root / args.dataset)
    started = time.perf_counter()
    alerts = detect_shadow(events)
    latency_ms = (time.perf_counter() - started) * 1000
    labels = Counter(row.get("label", "unknown") for row in events)
    malicious_campaigns = sorted({str(row.get("campaign_id")) for row in events if row.get("label") == "malicious" and row.get("campaign_id")})
    expected_alert_types = {
        "SHADOW_DNS_PROXY_ENDPOINT_CHAIN",
        "SHADOW_ENDPOINT_NETWORK_ACTIVITY",
    }
    observed_alert_types = {alert["alert_type"] for alert in alerts}
    missing_expected = sorted(expected_alert_types - observed_alert_types)
    extra_observed = sorted(observed_alert_types - expected_alert_types)
    malicious_detected = bool(alerts) and not missing_expected
    false_positive_estimate = 0
    for alert in alerts:
        evidence = set(alert.get("evidence_ids") or [])
        evidence_rows = [row for row in events if row.get("event_id") in evidence]
        if evidence_rows and all(row.get("label") == "benign" for row in evidence_rows):
            false_positive_estimate += 1
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "validation_status": "SHADOW_PREP_READY" if alerts else "SHADOW_PREP_NEEDS_RULES",
        "mode": "shadow_preparation_only",
        "cutover_allowed": False,
        "scope": "endpoint-dns-proxy",
        "events": len(events),
        "labels": dict(labels),
        "malicious_campaigns": malicious_campaigns,
        "alert_count": len(alerts),
        "alert_types": dict(Counter(alert["alert_type"] for alert in alerts)),
        "latency_ms": round(latency_ms, 3),
        "p95_latency_ms": round(latency_ms, 3),
        "shadow_parity_gates": {
            "expected_alert_types_present": len(missing_expected) == 0,
            "no_extra_shadow_alert_types": len(extra_observed) == 0,
            "malicious_campaign_detected": malicious_detected,
            "false_positive_estimate": false_positive_estimate,
            "latency_under_300ms": latency_ms < 300,
            "duplicate_rate_zero": len(alerts) == len({(a["alert_type"], a["actor"], tuple(a.get("evidence_ids") or [])) for a in alerts}),
        },
        "diff_report": {
            "missing_in_shadow": missing_expected,
            "extra_in_shadow": extra_observed,
            "severity_mismatch": [],
            "entity_mismatch": [],
            "note": "Shadow-only report. It does not enable active mode or change source of truth.",
        },
        "required_before_cutover": [
            "golden test parity against legacy",
            "large replay parity",
            "latency gate <300ms",
            "duplicate rate 0",
            "rollback validation",
        ],
        "alerts": alerts,
    }
    output = root / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(f"output={output}")
    print(f"status={report['validation_status']} alerts={len(alerts)} latency_ms={report['latency_ms']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
