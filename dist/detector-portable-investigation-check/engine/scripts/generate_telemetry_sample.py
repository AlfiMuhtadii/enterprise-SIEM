#!/usr/bin/env python3
"""
Generate safe synthetic telemetry for validating advanced defensive correlation.
No network actions are performed; this only writes normalized telemetry JSONL.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, List


def stable_id(parts: List[str]) -> str:
    return hashlib.sha256("|".join(parts).encode("utf-8")).hexdigest()[:40]


def event(ts: datetime, telemetry_type: str, event_type: str, host_id: str, **extra: Any) -> Dict[str, Any]:
    base: Dict[str, Any] = {
        "schema_version": 1,
        "ts": ts.isoformat().replace("+00:00", "Z"),
        "telemetry_type": telemetry_type,
        "event_type": event_type,
        "host_id": host_id,
    }
    base.update(extra)
    identity = [base["ts"], telemetry_type, event_type, host_id, str(extra)]
    base["event_id"] = stable_id(identity)
    return base


def build_sample(now: datetime) -> List[Dict[str, Any]]:
    host = "win-workstation-07"
    rows: List[Dict[str, Any]] = []
    rows.append(
        event(now - timedelta(seconds=80), "endpoint", "sandbox_probe", host, src_ip="10.10.1.25", process_name="unknown.exe")
    )
    rows.append(
        event(
            now - timedelta(seconds=70),
            "endpoint",
            "scheduled_task_created",
            host,
            src_ip="10.10.1.25",
            process_name="powershell.exe",
            task_name="UpdaterHealthCheck",
            command_hash=hashlib.sha256(b"redacted-command").hexdigest(),
        )
    )
    rows.append(
        event(
            now - timedelta(seconds=65),
            "endpoint",
            "service_created",
            host,
            src_ip="10.10.1.25",
            process_name="sc.exe",
            service_name="WinUpdateCache",
        )
    )

    for idx, port in enumerate([445, 3389, 22, 5985, 135, 139, 445, 3389, 5986, 445, 22, 135], start=1):
        rows.append(
            event(
                now - timedelta(seconds=60 - idx * 3),
                "network",
                "connection_attempt",
                host,
                src_ip="10.10.1.25",
                dst_ip=f"10.10.2.{20 + idx}",
                dst_port=port,
                protocol="tcp",
                process_name="powershell.exe",
            )
        )

    for idx in range(8):
        rows.append(
            event(
                now - timedelta(seconds=48 - idx * 6),
                "dns",
                "dns_query",
                host,
                src_ip="10.10.1.25",
                query="updates-control.example.test",
                process_name="unknown.exe",
            )
        )
    return rows


def build_normal_sample(now: datetime) -> List[Dict[str, Any]]:
    host = "admin-workstation-01"
    rows: List[Dict[str, Any]] = []
    rows.append(
        event(
            now - timedelta(seconds=80),
            "endpoint",
            "scheduled_task_created",
            host,
            src_ip="10.10.1.10",
            process_name="sccm-agent.exe",
            task_name="ApprovedMaintenance",
        )
    )
    for idx, port in enumerate([22, 3389, 5985], start=1):
        rows.append(
            event(
                now - timedelta(seconds=70 - idx * 5),
                "network",
                "connection_attempt",
                host,
                src_ip="10.10.1.10",
                dst_ip="10.10.2.20",
                dst_port=port,
                protocol="tcp",
                process_name="mstsc.exe",
            )
        )
    for idx in range(8):
        rows.append(
            event(
                now - timedelta(seconds=48 - idx * 6),
                "dns",
                "dns_query",
                host,
                src_ip="10.10.1.10",
                query="time.windows.com",
                process_name="svchost.exe",
            )
        )
    return rows


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate synthetic telemetry JSONL")
    parser.add_argument("--output", default="storage/logs/telemetry.jsonl")
    parser.add_argument("--kind", choices=["advanced", "normal"], default="advanced")
    parser.add_argument("--append", action="store_true")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    out = (root / args.output).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    now = datetime.now(timezone.utc)
    rows = build_sample(now) if args.kind == "advanced" else build_normal_sample(now)
    mode = "a" if args.append else "w"
    with out.open(mode, encoding="utf-8") as f:
        for row in rows:
            f.write(json.dumps(row, separators=(",", ":"), ensure_ascii=False) + "\n")
    print(f"wrote={len(rows)}")
    print(f"file={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
