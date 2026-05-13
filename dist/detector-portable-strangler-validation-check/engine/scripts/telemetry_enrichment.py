#!/usr/bin/env python3
"""
Enrich normalized telemetry JSONL with ETW/Sysmon context, entity hints, and
basic defensive risk labels before ingest/correlation.
"""

from __future__ import annotations

import argparse
import ipaddress
import json
from pathlib import Path
from typing import Any, Dict, Iterable


SYSMON_EVENT_MAP = {
    "process_created": {"sysmon_event_id": 1, "category": "process"},
    "connection_attempt": {"sysmon_event_id": 3, "category": "network"},
    "file_created": {"sysmon_event_id": 11, "category": "file"},
    "registry_run_key_modified": {"sysmon_event_id": 13, "category": "registry"},
    "dns_query": {"sysmon_event_id": 22, "category": "dns"},
}

SUSPICIOUS_PROCESSES = {"powershell.exe", "cmd.exe", "wscript.exe", "cscript.exe", "rundll32.exe", "regsvr32.exe", "curl", "wget"}
ADMIN_PORTS = {22, 135, 139, 445, 3389, 5985, 5986}


def is_private_ip(value: Any) -> bool | None:
    if not value:
        return None
    try:
        return ipaddress.ip_address(str(value)).is_private
    except ValueError:
        return None


def iter_jsonl(path: Path) -> Iterable[Dict[str, Any]]:
    with path.open("r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if not line.strip():
                continue
            data = json.loads(line)
            if isinstance(data, dict):
                yield data


def enrich(event: Dict[str, Any]) -> Dict[str, Any]:
    event = dict(event)
    payload = event.get("raw") if isinstance(event.get("raw"), dict) else {}
    enrichment = dict(event.get("enrichment") if isinstance(event.get("enrichment"), dict) else {})
    event_type = str(event.get("event_type") or "")
    process = str(event.get("process_name") or "").lower()
    dst_port = event.get("dst_port")

    if event_type in SYSMON_EVENT_MAP:
        enrichment["sysmon"] = SYSMON_EVENT_MAP[event_type]
    if "ProviderName" in payload or "Channel" in payload:
        enrichment["etw"] = {
            "provider": payload.get("ProviderName"),
            "channel": payload.get("Channel"),
            "event_id": payload.get("EventID") or payload.get("EventId"),
        }
    enrichment["entity"] = {
        "host": event.get("host_id"),
        "src_ip": event.get("src_ip"),
        "dst_ip": event.get("dst_ip"),
        "process": event.get("process_name"),
        "user_hash": event.get("user_name_hash"),
    }
    enrichment["network_scope"] = {
        "src_private": is_private_ip(event.get("src_ip")),
        "dst_private": is_private_ip(event.get("dst_ip")),
    }
    signals = []
    if process in SUSPICIOUS_PROCESSES:
        signals.append("high_attention_process")
    if isinstance(dst_port, int) and dst_port in ADMIN_PORTS:
        signals.append("admin_protocol")
    if event_type in {"scheduled_task_created", "service_created", "registry_run_key_modified"}:
        signals.append("persistence_surface")
    if str(event.get("query") or "").count(".") >= 3:
        signals.append("deep_dns_name")
    enrichment["risk_signals"] = sorted(set(signals))
    enrichment["risk_score_hint"] = min(1.0, 0.2 + (0.2 * len(signals))) if signals else 0.0
    event["enrichment"] = enrichment
    return event


def main() -> int:
    parser = argparse.ArgumentParser(description="Enrich normalized telemetry JSONL")
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", default="storage/logs/telemetry_enriched.jsonl")
    args = parser.parse_args()
    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with out.open("w", encoding="utf-8") as handle:
        for event in iter_jsonl(Path(args.input)):
            handle.write(json.dumps(enrich(event), separators=(",", ":"), ensure_ascii=False) + "\n")
            count += 1
    print(f"enriched={count}")
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

