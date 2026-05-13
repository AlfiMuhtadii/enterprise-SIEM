#!/usr/bin/env python3
"""Generate deterministic identity/cloud golden correlation test cases."""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, Iterable


def stable_id(row: Dict[str, Any]) -> str:
    return hashlib.sha256(json.dumps(row, sort_keys=True, default=str).encode()).hexdigest()[:40]


def event(ts: datetime, telemetry_type: str, event_type: str, user: str, idx: int, **extra: Any) -> Dict[str, Any]:
    row = {
        "schema_version": 1,
        "ts": ts.isoformat().replace("+00:00", "Z"),
        "telemetry_type": telemetry_type,
        "event_type": event_type,
        "user": user,
        "host": extra.pop("host", f"{telemetry_type}-host"),
        "host_id": extra.pop("host_id", f"{telemetry_type}-host"),
        "source_ip": extra.pop("source_ip", f"198.51.100.{idx}"),
        "destination_ip": extra.pop("destination_ip", f"10.10.0.{idx}"),
        "domain": extra.pop("domain", "cloud.provider.example"),
        "cloud_account": extra.pop("cloud_account", "acct-golden"),
        "action": extra.pop("action", "observed"),
        "result": extra.pop("result", "success"),
        "risk_score": extra.pop("risk_score", 0.1),
        "event_source": extra.pop("event_source", "golden"),
        "label": extra.pop("label", "benign"),
        **extra,
    }
    row["event_id"] = stable_id(row)
    return row


def rows() -> Iterable[Dict[str, Any]]:
    base = datetime(2026, 5, 13, 1, 0, tzinfo=timezone.utc)
    # impossible travel + risky login
    yield event(base, "identity", "login_success", "travel@example.com", 1, source_ip="198.51.100.1", risk_score=0.8, label="malicious")
    yield event(base + timedelta(minutes=2), "identity", "login_success", "travel@example.com", 2, source_ip="203.0.113.2", risk_score=0.2, label="malicious")
    # privilege escalation
    yield event(base + timedelta(minutes=3), "identity", "privilege_escalation", "priv@example.com", 3, action="admin_role_change", risk_score=0.85, label="malicious")
    # MFA burst / cross service
    for i in range(5):
        yield event(base + timedelta(minutes=4, seconds=i), "identity", "login_failed", "mfa@example.com", 10 + i, result="failure", event_source=f"idp-{i % 2}", risk_score=0.6, label="malicious")
    # cloud access anomaly
    for i in range(3):
        yield event(base + timedelta(minutes=6, seconds=i), "cloud", "cloud_api_call", "cloud@example.com", 20 + i, risk_score=0.8, label="malicious")
    for i in range(5):
        yield event(base + timedelta(minutes=7, seconds=i), "cloud", "object_read", "object@example.com", 30 + i, action="GetObject", risk_score=0.4, label="malicious")
    yield event(base + timedelta(minutes=8), "cloud", "new_access_key_created", "key@example.com", 40, action="CreateAccessKey", risk_score=0.9, label="malicious")
    # benign controls
    for i in range(10):
        yield event(base + timedelta(minutes=20, seconds=i), "identity", "login_success", f"normal{i}@example.com", 60 + i, source_ip=f"10.0.0.{i+1}", risk_score=0.05)


def main() -> int:
    out = Path("samples/golden/xdr_identity_cloud_golden.jsonl")
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as f:
        for row in rows():
            f.write(json.dumps(row, separators=(",", ":"), ensure_ascii=False) + "\n")
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
