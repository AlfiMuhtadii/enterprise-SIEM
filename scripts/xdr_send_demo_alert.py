#!/usr/bin/env python3
"""Send a deterministic XDR alert event into Redpanda for demo validation."""

from __future__ import annotations

import argparse
import json
import time
import urllib.request


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Send demo alert to xdr.alerts")
    parser.add_argument("--rest-url", default="http://127.0.0.1:8082")
    parser.add_argument("--topic", default="xdr.alerts")
    parser.add_argument("--user", default="demo.identity@example.com")
    parser.add_argument("--ip", default="203.0.113.88")
    parser.add_argument("--alert-type", default="IDENTITY_RISKY_IP_LOGIN")
    parser.add_argument("--severity", default="high")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    ts = int(time.time())
    event = {
        "schema_version": "1.0",
        "event_id": f"demo-alert-{ts}",
        "alert_type": args.alert_type,
        "severity": args.severity,
        "actor_key": args.user,
        "ip": args.ip,
        "score": 0.93,
        "evidence": {
            "evidence_ids": [f"demo-evidence-{ts}-1", f"demo-evidence-{ts}-2"],
            "xdr_domains": ["identity", "cloud"],
            "mitre_attack": ["T1078"],
            "involved_users": [args.user],
            "involved_external_ips": [args.ip],
        },
        "raw_event": {
            "source": "xdr_send_demo_alert",
            "sample": True,
            "created_at_epoch": ts,
        },
    }
    payload = {"records": [{"value": event}]}
    req = urllib.request.Request(
        f"{args.rest_url.rstrip('/')}/topics/{args.topic}",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/vnd.kafka.json.v2+json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=10) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        print(f"status={resp.status}")
        print(body)
    print(f"sent_topic={args.topic}")
    print(f"actor={args.user}")
    print(f"ip={args.ip}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
