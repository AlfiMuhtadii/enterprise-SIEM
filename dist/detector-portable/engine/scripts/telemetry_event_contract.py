#!/usr/bin/env python3
"""
Telemetry event schema contract validator.

Supported telemetry_type values:
- endpoint
- network
- dns
"""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Tuple

SCHEMA_VERSION = 1
VALID_TYPES = {"endpoint", "network", "dns"}
HEX64_RE = re.compile(r"^[0-9a-f]{64}$")


def is_iso_ts(value: str) -> bool:
    try:
        datetime.fromisoformat(value.replace("Z", "+00:00"))
        return True
    except ValueError:
        return False


def validate_event(event: Dict[str, Any]) -> Tuple[bool, List[str]]:
    errors: List[str] = []
    if not isinstance(event, dict):
        return False, ["payload is not an object"]
    if event.get("schema_version") != SCHEMA_VERSION:
        errors.append(f"schema_version must be {SCHEMA_VERSION}")
    if not isinstance(event.get("ts"), str) or not is_iso_ts(str(event.get("ts"))):
        errors.append("ts must be ISO-8601 string")
    if not isinstance(event.get("event_id"), str) or not str(event.get("event_id")).strip():
        errors.append("event_id is required string")
    telemetry_type = event.get("telemetry_type")
    if telemetry_type not in VALID_TYPES:
        errors.append(f"telemetry_type must be one of {sorted(VALID_TYPES)}")
    if not isinstance(event.get("event_type"), str) or not str(event.get("event_type")).strip():
        errors.append("event_type is required string")
    if not isinstance(event.get("host_id"), str) or not str(event.get("host_id")).strip():
        errors.append("host_id is required string")

    if telemetry_type == "network":
        if not isinstance(event.get("src_ip"), str) or not str(event.get("src_ip")).strip():
            errors.append("network.src_ip is required")
        if not isinstance(event.get("dst_ip"), str) or not str(event.get("dst_ip")).strip():
            errors.append("network.dst_ip is required")
        if not isinstance(event.get("dst_port"), int):
            errors.append("network.dst_port must be integer")
    if telemetry_type == "dns":
        if not isinstance(event.get("query"), str) or not str(event.get("query")).strip():
            errors.append("dns.query is required")
    if telemetry_type == "endpoint":
        if event.get("user_name_hash") is not None and not (
            isinstance(event.get("user_name_hash"), str) and HEX64_RE.match(str(event.get("user_name_hash")))
        ):
            errors.append("endpoint.user_name_hash must be 64-char lowercase hex|null")
    return len(errors) == 0, errors


def validate_file(path: Path, max_errors: int) -> int:
    total = valid = invalid = 0
    with path.open("r", encoding="utf-8") as f:
        for idx, line in enumerate(f, start=1):
            text = line.strip()
            if not text:
                continue
            total += 1
            try:
                payload = json.loads(text)
            except json.JSONDecodeError as exc:
                invalid += 1
                if invalid <= max_errors:
                    print(f"[line {idx}] invalid json: {exc}")
                continue
            ok, errors = validate_event(payload if isinstance(payload, dict) else {})
            if ok:
                valid += 1
            else:
                invalid += 1
                if invalid <= max_errors:
                    print(f"[line {idx}] invalid schema: {', '.join(errors)}")
    print(f"Total: {total}")
    print(f"Valid: {valid}")
    print(f"Invalid: {invalid}")
    return 0 if invalid == 0 else 2


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate telemetry JSONL contract")
    parser.add_argument("--file", default="storage/logs/telemetry.jsonl")
    parser.add_argument("--max-errors", type=int, default=20)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    path = Path(args.file)
    if not path.exists():
        print(f"ERROR: file not found: {path}")
        return 1
    return validate_file(path, max(1, args.max_errors))


if __name__ == "__main__":
    raise SystemExit(main())
