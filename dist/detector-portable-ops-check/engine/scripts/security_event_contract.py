#!/usr/bin/env python3
"""
Security event schema contract validator (v1).
"""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

SCHEMA_VERSION = 1
HEX64_RE = re.compile(r"^[0-9a-f]{64}$")
UUID_RE = re.compile(r"^[0-9a-fA-F-]{36}$")


def _is_iso_ts(value: str) -> bool:
    text = value.replace("Z", "+00:00")
    try:
        datetime.fromisoformat(text)
        return True
    except ValueError:
        return False


def _is_uuid(value: str) -> bool:
    if not UUID_RE.match(value):
        return False
    parts = value.split("-")
    return len(parts) == 5 and [len(p) for p in parts] == [8, 4, 4, 4, 12]


def _is_hex64(value: str) -> bool:
    return bool(HEX64_RE.match(value))


def validate_event(event: Dict[str, Any]) -> Tuple[bool, List[str]]:
    errors: List[str] = []

    if not isinstance(event, dict):
        return False, ["payload is not an object"]

    schema_version = event.get("schema_version")
    if not isinstance(schema_version, int):
        errors.append("schema_version must be integer")
    elif schema_version != SCHEMA_VERSION:
        errors.append(f"schema_version must be {SCHEMA_VERSION}")

    ts = event.get("ts")
    if not isinstance(ts, str) or not ts.strip():
        errors.append("ts is required string")
    elif not _is_iso_ts(ts):
        errors.append("ts must be ISO-8601 timestamp")

    event_name = event.get("event") or event.get("event_type")
    if not isinstance(event_name, str) or not event_name.strip():
        errors.append("event (or event_type) is required string")

    request_id = event.get("request_id")
    if not isinstance(request_id, str) or not _is_uuid(request_id):
        errors.append("request_id must be UUID string")

    ip = event.get("ip")
    if not isinstance(ip, str) or not ip.strip():
        errors.append("ip is required string")

    ua_hash = event.get("user_agent_hash")
    if not isinstance(ua_hash, str) or not _is_hex64(ua_hash):
        errors.append("user_agent_hash must be 64-char lowercase hex string")

    method = event.get("method")
    if not isinstance(method, str) or not method.strip():
        errors.append("method is required string")

    path = event.get("path")
    if not isinstance(path, str) or not path.strip():
        errors.append("path is required string")
    elif not path.startswith("/"):
        errors.append("path must start with '/'")

    status = event.get("status")
    if not isinstance(status, int):
        errors.append("status must be integer")
    elif status < 100 or status > 599:
        errors.append("status must be HTTP status code 100..599")

    latency = event.get("latency_ms")
    if latency is not None and not isinstance(latency, int):
        errors.append("latency_ms must be integer|null")

    user_id = event.get("user_id")
    if user_id is not None and not isinstance(user_id, int):
        errors.append("user_id must be integer|null")

    email_hash = event.get("email_hash")
    if email_hash is not None:
        if not isinstance(email_hash, str) or not _is_hex64(email_hash):
            errors.append("email_hash must be 64-char lowercase hex string|null")

    query_hash = event.get("query_hash")
    if query_hash is not None:
        if not isinstance(query_hash, str) or not _is_hex64(query_hash):
            errors.append("query_hash must be 64-char lowercase hex string|null")

    has_sql = event.get("has_sql_keywords")
    if has_sql is not None and not isinstance(has_sql, bool):
        errors.append("has_sql_keywords must be bool|null")

    has_script = event.get("has_script_payload")
    if has_script is not None and not isinstance(has_script, bool):
        errors.append("has_script_payload must be bool|null")

    return len(errors) == 0, errors


def validate_file(file_path: Path, max_errors: int) -> int:
    total = 0
    valid = 0
    invalid = 0

    with file_path.open("r", encoding="utf-8") as f:
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
                continue

            invalid += 1
            if invalid <= max_errors:
                print(f"[line {idx}] invalid schema: {', '.join(errors)}")

    print(f"Total: {total}")
    print(f"Valid: {valid}")
    print(f"Invalid: {invalid}")
    return 0 if invalid == 0 else 2


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate security JSONL schema contract")
    parser.add_argument("--file", default="storage/logs/security.jsonl")
    parser.add_argument("--max-errors", type=int, default=20)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    file_path = Path(args.file)
    if not file_path.exists():
        print(f"ERROR: file not found: {file_path}")
        return 1
    return validate_file(file_path, max(1, args.max_errors))


if __name__ == "__main__":
    raise SystemExit(main())
