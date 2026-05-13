#!/usr/bin/env python3
"""Generate large mixed benign/malicious XDR telemetry JSONL for replay validation."""

from __future__ import annotations

import argparse
import hashlib
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict


DOMAINS = ["email", "identity", "endpoint", "dns", "cloud", "saas", "proxy"]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate large XDR telemetry dataset")
    parser.add_argument("--normal", type=int, default=10000)
    parser.add_argument("--malicious", type=int, default=500)
    parser.add_argument("--output", default="storage/logs/xdr_large_mixed.jsonl")
    return parser.parse_args()


def event_id(row: Dict[str, Any]) -> str:
    return hashlib.sha256(json.dumps(row, sort_keys=True).encode()).hexdigest()[:40]


def row(idx: int, malicious: bool) -> Dict[str, Any]:
    domain = DOMAINS[idx % len(DOMAINS)]
    ts = datetime.now(timezone.utc) - timedelta(seconds=idx)
    user = f"user{idx % 250}@example.com"
    event_type = {
        "email": "phishing_email" if malicious and idx % 7 == 0 else "email_delivered",
        "identity": "login_success" if not malicious else "login_failed" if idx % 3 else "privilege_escalation",
        "endpoint": "process_created",
        "dns": "dns_query",
        "cloud": "new_access_key_created" if malicious and idx % 5 == 0 else "cloud_api_call",
        "saas": "admin_role_change" if malicious and idx % 4 == 0 else "saas_activity",
        "proxy": "proxy_request",
    }[domain]
    data = {
        "schema_version": 1,
        "ts": ts.isoformat().replace("+00:00", "Z"),
        "telemetry_type": "firewall" if domain == "proxy" and idx % 2 else domain,
        "event_type": event_type,
        "host_id": f"host-{idx % 500}",
        "user": user,
        "host": f"host-{idx % 500}",
        "source_ip": f"10.{idx % 10}.{idx % 250}.{(idx % 240) + 1}",
        "destination_ip": f"198.51.100.{(idx % 240) + 1}",
        "domain": f"service{idx % 100}.example.com" if not malicious else f"suspicious{idx % 30}.example.net",
        "cloud_account": f"acct-{idx % 20}" if domain == "cloud" else None,
        "risk_score": 0.82 if malicious else 0.05 + ((idx % 20) / 100),
        "event_source": f"simulated-{domain}",
        "label": "malicious" if malicious else "benign",
    }
    data["event_id"] = event_id(data)
    return data


def main() -> int:
    args = parse_args()
    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as f:
        for idx in range(max(0, args.normal)):
            f.write(json.dumps(row(idx, False), separators=(",", ":")) + "\n")
        for idx in range(max(0, args.malicious)):
            f.write(json.dumps(row(idx + args.normal, True), separators=(",", ":")) + "\n")
    print(f"output={out}")
    print(f"events={args.normal + args.malicious}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
