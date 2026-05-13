#!/usr/bin/env python3
"""
Lightweight defensive endpoint telemetry agent.

Collects local process and network snapshots and writes normalized telemetry
JSONL compatible with telemetry_events ingestion. It is intentionally simple:
no kernel hooks, no exploit logic, no remote control.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import platform
import socket
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def stable_id(kind: str, payload: Dict[str, Any]) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(f"{kind}|{raw}".encode("utf-8")).hexdigest()[:40]


def run_cmd(cmd: List[str]) -> str:
    try:
        return subprocess.run(cmd, text=True, capture_output=True, timeout=8).stdout
    except Exception:
        return ""


def proc_events(host_id: str) -> Iterable[Dict[str, Any]]:
    ts = now_iso()
    if platform.system().lower().startswith("win"):
        output = run_cmd(["tasklist", "/fo", "csv", "/nh"])
        for line in output.splitlines()[:300]:
            parts = [p.strip('"') for p in line.split('","')]
            if len(parts) < 2:
                continue
            payload = {"image": parts[0], "pid": parts[1], "source": "tasklist"}
            ev = {
                "schema_version": 1,
                "ts": ts,
                "telemetry_type": "endpoint",
                "event_type": "process_observed",
                "host_id": host_id,
                "process_name": parts[0],
                "raw": payload,
            }
            ev["event_id"] = stable_id("process", ev)
            yield ev
        return

    output = run_cmd(["ps", "-eo", "pid,ppid,comm,args"])
    for line in output.splitlines()[1:301]:
        parts = line.split(None, 3)
        if len(parts) < 3:
            continue
        payload = {"pid": parts[0], "ppid": parts[1], "comm": parts[2], "args": parts[3] if len(parts) > 3 else ""}
        ev = {
            "schema_version": 1,
            "ts": ts,
            "telemetry_type": "endpoint",
            "event_type": "process_observed",
            "host_id": host_id,
            "process_name": parts[2],
            "raw": payload,
        }
        ev["event_id"] = stable_id("process", ev)
        yield ev


def network_events(host_id: str) -> Iterable[Dict[str, Any]]:
    ts = now_iso()
    output = run_cmd(["netstat", "-ano"]) if platform.system().lower().startswith("win") else run_cmd(["netstat", "-tunp"])
    for line in output.splitlines():
        text = line.strip()
        if not text.lower().startswith(("tcp", "udp")):
            continue
        parts = text.split()
        if len(parts) < 4:
            continue
        proto = parts[0].lower()
        local = parts[1]
        remote = parts[2] if platform.system().lower().startswith("win") else parts[4] if len(parts) > 4 else ""
        if remote in {"*", "*:*", "0.0.0.0:*", "[::]:*"}:
            continue
        dst_ip, dst_port = parse_endpoint(remote)
        src_ip, _src_port = parse_endpoint(local)
        if not dst_ip or not dst_port:
            continue
        ev = {
            "schema_version": 1,
            "ts": ts,
            "telemetry_type": "network",
            "event_type": "connection_observed",
            "host_id": host_id,
            "src_ip": src_ip or "127.0.0.1",
            "dst_ip": dst_ip,
            "dst_port": dst_port,
            "protocol": proto,
            "raw": {"line": text, "source": "netstat"},
        }
        ev["event_id"] = stable_id("network", ev)
        yield ev


def parse_endpoint(value: str) -> tuple[str, int | None]:
    value = value.strip().strip("[]")
    if ":" not in value:
        return value, None
    host, port_text = value.rsplit(":", 1)
    try:
        return host.strip("[]"), int(port_text)
    except ValueError:
        return host.strip("[]"), None


def write_events(events: Iterable[Dict[str, Any]], output: Path) -> int:
    output.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with output.open("a", encoding="utf-8") as handle:
        for event in events:
            handle.write(json.dumps(event, separators=(",", ":"), ensure_ascii=False) + "\n")
            count += 1
    return count


def main() -> int:
    parser = argparse.ArgumentParser(description="Collect lightweight endpoint telemetry")
    parser.add_argument("--output", default="storage/logs/endpoint_agent.jsonl")
    parser.add_argument("--host-id", default=socket.gethostname())
    parser.add_argument("--interval", type=int, default=0, help="Seconds between snapshots; 0 means one-shot")
    parser.add_argument("--iterations", type=int, default=1)
    parser.add_argument("--no-process", action="store_true")
    parser.add_argument("--no-network", action="store_true")
    args = parser.parse_args()

    out = Path(args.output)
    iterations = max(1, args.iterations)
    total = 0
    for idx in range(iterations):
        batch: List[Dict[str, Any]] = []
        if not args.no_process:
            batch.extend(proc_events(args.host_id))
        if not args.no_network:
            batch.extend(network_events(args.host_id))
        total += write_events(batch, out)
        if args.interval > 0 and idx < iterations - 1:
            time.sleep(args.interval)
    print(f"host_id={args.host_id}")
    print(f"events_written={total}")
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

