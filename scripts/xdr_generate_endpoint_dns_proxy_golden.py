#!/usr/bin/env python3
"""Generate deterministic endpoint/DNS/proxy shadow-only golden telemetry."""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path


def parse_args():
    parser = argparse.ArgumentParser(description="Generate endpoint/DNS/proxy golden dataset")
    parser.add_argument("--output", default="samples/golden/xdr_endpoint_dns_proxy_golden.jsonl")
    return parser.parse_args()


def ev(ts, telemetry_type, event_type, host, user, domain=None, src="10.20.1.10", risk=0.2, action="observed", label="benign", campaign=None):
    material = f"{ts}|{telemetry_type}|{event_type}|{host}|{user}|{domain}|{campaign}"
    return {
        "schema_version": 1,
        "ts": ts,
        "telemetry_type": telemetry_type,
        "event_type": event_type,
        "host_id": host,
        "host": host,
        "user": user,
        "source_ip": src,
        "destination_ip": "198.51.100.20",
        "domain": domain,
        "action": action,
        "result": "success",
        "risk_score": risk,
        "event_source": f"golden-{telemetry_type}",
        "label": label,
        "campaign_id": campaign,
        "event_id": "golden-" + str(abs(hash(material)))[:16],
    }


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    base = datetime(2026, 5, 12, 12, 0, tzinfo=timezone.utc)
    rows = []
    rows.append(ev((base).isoformat(), "dns", "dns_query", "host-edp-1", "alice@example.com", "known-good.example", risk=0.1))
    rows.append(ev((base + timedelta(seconds=5)).isoformat(), "endpoint", "process_created", "host-edp-1", "alice@example.com", risk=0.2, action="powershell.exe -NoProfile", label="benign"))
    rows.append(ev((base + timedelta(seconds=10)).isoformat(), "proxy", "proxy_request", "host-edp-1", "alice@example.com", "updates.example", risk=0.1))

    for offset in range(5):
        rows.append(ev((base + timedelta(minutes=10, seconds=offset)).isoformat(), "dns", "ioc_domain_query", "host-edp-2", "bob@example.com", "beacon.bad.example", risk=0.85, label="malicious", campaign="edp-campaign-1"))
    rows.append(ev((base + timedelta(minutes=10, seconds=8)).isoformat(), "endpoint", "process_created", "host-edp-2", "bob@example.com", "beacon.bad.example", risk=0.9, action="rundll32 suspicious.dll", label="malicious", campaign="edp-campaign-1"))
    rows.append(ev((base + timedelta(minutes=10, seconds=12)).isoformat(), "proxy", "proxy_request", "host-edp-2", "bob@example.com", "beacon.bad.example", risk=0.8, label="malicious", campaign="edp-campaign-1"))

    output = root / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text("\n".join(json.dumps(row, separators=(",", ":")) for row in rows) + "\n", encoding="utf-8")
    print(f"output={output}")
    print(f"events={len(rows)} malicious={sum(1 for r in rows if r['label'] == 'malicious')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
