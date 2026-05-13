#!/usr/bin/env python3
"""Generate realistic multi-domain XDR replay datasets with linked campaigns."""

from __future__ import annotations

import argparse
import hashlib
import json
import random
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List


DOMAINS = ["endpoint", "email", "identity", "cloud", "saas", "firewall", "proxy", "dns"]
BENIGN_TYPES = {
    "endpoint": ["process_created", "file_changed", "service_started"],
    "email": ["email_delivered", "email_read", "attachment_scanned"],
    "identity": ["login_success", "mfa_success", "password_change"],
    "cloud": ["cloud_api_call", "object_read", "describe_instances"],
    "saas": ["document_viewed", "team_message", "calendar_update"],
    "firewall": ["connection_allowed", "connection_denied"],
    "proxy": ["proxy_request", "proxy_cache_hit"],
    "dns": ["dns_query", "dns_response"],
}
CHAIN = [
    ("email", "phishing_email"),
    ("identity", "suspicious_login"),
    ("identity", "mfa_failure_burst"),
    ("endpoint", "process_created"),
    ("dns", "dns_beacon"),
    ("cloud", "new_access_key_created"),
    ("saas", "mass_download"),
    ("firewall", "connection_allowed"),
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate realistic large-scale XDR telemetry")
    parser.add_argument("--events", type=int, default=52500)
    parser.add_argument("--malicious-ratio", type=float, default=0.05)
    parser.add_argument("--noise", type=float, default=0.35)
    parser.add_argument("--campaigns", type=int, default=25)
    parser.add_argument("--duration-minutes", type=int, default=240)
    parser.add_argument("--output", default="storage/logs/xdr_realistic_large.jsonl")
    parser.add_argument("--seed", type=int, default=1337)
    return parser.parse_args()


def stable_id(row: Dict[str, Any]) -> str:
    return hashlib.sha256(json.dumps(row, sort_keys=True, default=str).encode()).hexdigest()[:40]


def write_jsonl(path: Path, rows: Iterable[Dict[str, Any]]) -> int:
    path.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with path.open("w", encoding="utf-8") as f:
        for row in rows:
            row["event_id"] = stable_id(row)
            f.write(json.dumps(row, separators=(",", ":"), ensure_ascii=False) + "\n")
            count += 1
    return count


def benign_event(idx: int, start: datetime, duration: int, noise: float) -> Dict[str, Any]:
    domain = DOMAINS[idx % len(DOMAINS)]
    user = f"user{idx % 850}@enterprise.example"
    host = f"host-{idx % 700:04d}"
    noisy = random.random() < noise
    event_type = random.choice(BENIGN_TYPES[domain])
    return {
        "schema_version": 1,
        "ts": (start + timedelta(seconds=random.randint(0, duration * 60))).isoformat().replace("+00:00", "Z"),
        "telemetry_type": domain,
        "event_type": event_type,
        "host_id": host,
        "host": host,
        "user": user,
        "source_ip": f"10.{idx % 16}.{idx % 240}.{(idx % 230) + 10}",
        "destination_ip": f"172.16.{idx % 200}.{(idx % 240) + 1}",
        "domain": f"service{idx % 160}.enterprise.example",
        "file_hash": hashlib.sha256(f"file-{idx % 2000}".encode()).hexdigest(),
        "email_sender": f"sender{idx % 300}@partner.example" if domain == "email" else None,
        "email_recipient": user if domain == "email" else None,
        "cloud_account": f"acct-{idx % 35}" if domain == "cloud" else None,
        "action": "allow" if domain in {"firewall", "proxy"} else "observed",
        "result": "success",
        "risk_score": round(0.03 + (0.22 if noisy else 0) + random.random() * 0.08, 3),
        "event_source": f"realistic-{domain}",
        "label": "benign",
        "campaign_id": None,
        "noise_profile": "enterprise_noisy" if noisy else "normal",
    }


def campaign_events(campaign_idx: int, start: datetime, duration: int) -> List[Dict[str, Any]]:
    campaign_id = f"camp-{campaign_idx:04d}"
    user = f"target{campaign_idx % 120}@enterprise.example"
    host = f"host-{campaign_idx % 180:04d}"
    source_ip = f"203.0.113.{(campaign_idx % 220) + 10}"
    domain = f"lookalike-{campaign_idx % 40}.example.net"
    base = start + timedelta(seconds=random.randint(0, max(1, duration * 60 - 600)))
    rows: List[Dict[str, Any]] = []
    for offset, (telemetry_type, event_type) in enumerate(CHAIN):
        rows.append({
            "schema_version": 1,
            "ts": (base + timedelta(seconds=offset * random.randint(30, 240))).isoformat().replace("+00:00", "Z"),
            "telemetry_type": telemetry_type,
            "event_type": event_type,
            "host_id": host,
            "host": host,
            "user": user,
            "source_ip": source_ip if telemetry_type in {"identity", "firewall", "proxy"} else f"10.9.{campaign_idx % 80}.{offset + 20}",
            "destination_ip": f"198.51.100.{(campaign_idx + offset) % 240}",
            "domain": domain if telemetry_type in {"email", "dns", "proxy"} else f"cloud{campaign_idx % 20}.provider.example",
            "file_hash": hashlib.sha256(f"{campaign_id}-{offset}".encode()).hexdigest(),
            "email_sender": f"billing-{campaign_idx}@external.example" if telemetry_type == "email" else None,
            "email_recipient": user if telemetry_type == "email" else None,
            "cloud_account": f"acct-{campaign_idx % 35}" if telemetry_type == "cloud" else None,
            "action": "create" if event_type == "new_access_key_created" else "observed",
            "result": "failure" if "failure" in event_type else "success",
            "risk_score": round(0.72 + random.random() * 0.26, 3),
            "event_source": f"realistic-{telemetry_type}",
            "label": "malicious",
            "campaign_id": campaign_id,
            "attack_stage": offset + 1,
        })
    return rows


def main() -> int:
    args = parse_args()
    random.seed(args.seed)
    start = datetime.now(timezone.utc) - timedelta(minutes=args.duration_minutes)
    malicious_target = max(args.campaigns * len(CHAIN), int(args.events * args.malicious_ratio))
    benign_target = max(0, args.events - malicious_target)
    rows: List[Dict[str, Any]] = [benign_event(i, start, args.duration_minutes, args.noise) for i in range(benign_target)]
    campaign_idx = 0
    while len(rows) < args.events:
        rows.extend(campaign_events(campaign_idx, start, args.duration_minutes))
        campaign_idx += 1
    rows = rows[: args.events]
    rows.sort(key=lambda item: item["ts"])
    out = Path(args.output)
    count = write_jsonl(out, rows)
    print(f"output={out}")
    print(f"events={count}")
    print(f"campaigns={campaign_idx}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
