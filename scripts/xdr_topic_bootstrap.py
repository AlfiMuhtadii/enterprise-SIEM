#!/usr/bin/env python3
"""
Automatic Redpanda topic bootstrap for the XDR demo pipeline.

Reads the topic list from the Pandaproxy REST API and creates any required
topics that are missing via ``docker compose exec redpanda rpk topic create``.

Idempotent: safe to run multiple times.  rpk reports TOPIC_ALREADY_EXISTS if a
topic already exists; this script treats that as PASS, not an error.

Usage:
    python scripts/xdr_topic_bootstrap.py
    python scripts/xdr_topic_bootstrap.py --pandaproxy http://127.0.0.1:8082 --timeout 5
    python scripts/xdr_topic_bootstrap.py --dry-run

Exit codes:
    0 — all required topics now exist (or would be created in dry-run)
    1 — one or more topics could not be created
    2 — Pandaproxy unreachable (cannot verify topic list)
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import urllib.request
import urllib.error

REQUIRED_TOPICS: list[str] = [
    "telemetry.raw",
    "telemetry.normalized",
    "xdr.alerts",
    "alerts.created",
    "telemetry.normalization_failed",
    "xdr.correlation_failed",
    "xdr.alert_write_failed",
]

PASS    = "PASS"
FAIL    = "FAIL"
CREATED = "CREATED"
SKIP    = "SKIP"


# ---------------------------------------------------------------------------
# Topic list — Pandaproxy /topics
# ---------------------------------------------------------------------------

def fetch_topic_list(pandaproxy_url: str, timeout: int) -> list[str] | None:
    """Return list of topic names from Pandaproxy, or None on unreachable/error."""
    url = pandaproxy_url.rstrip("/") + "/topics"
    try:
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            if resp.status != 200:
                return None
            body = resp.read(65536).decode("utf-8", errors="replace")
    except (OSError, urllib.error.HTTPError, urllib.error.URLError):
        return None
    try:
        data = json.loads(body)
    except (json.JSONDecodeError, ValueError):
        return None
    if not isinstance(data, list):
        return None
    topics: list[str] = []
    for item in data:
        if isinstance(item, str):
            topics.append(item)
        elif isinstance(item, dict):
            name = item.get("name") or item.get("topic_name") or ""
            if name:
                topics.append(str(name))
    return topics


# ---------------------------------------------------------------------------
# Topic creation — rpk via docker compose exec
# ---------------------------------------------------------------------------

def create_topic(topic: str, dry_run: bool) -> tuple[str, str]:
    """Create *topic* via rpk inside the Redpanda container.

    Returns ``(status, detail)`` where status is one of CREATED / PASS / FAIL / SKIP.
    """
    cmd = [
        "docker", "compose", "exec", "-T", "redpanda",
        "rpk", "topic", "create", topic, "--brokers=redpanda:9092",
    ]
    if dry_run:
        return SKIP, "dry-run: " + " ".join(cmd)

    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
    except FileNotFoundError:
        return FAIL, "docker not found in PATH — cannot run rpk"
    except subprocess.TimeoutExpired:
        return FAIL, "rpk topic create timed out after 20s"
    except OSError as exc:
        return FAIL, str(exc)[:200]

    combined = (result.stdout + result.stderr).strip()
    if result.returncode == 0:
        return CREATED, combined[:200] or "created"
    if "TOPIC_ALREADY_EXISTS" in combined or "already exists" in combined.lower():
        return PASS, "already exists (TOPIC_ALREADY_EXISTS)"
    return FAIL, combined[:200] or f"rpk exited {result.returncode}"


# ---------------------------------------------------------------------------
# Table rendering
# ---------------------------------------------------------------------------

def _render_table(rows: list[tuple[str, str, str]]) -> str:
    w_t = max(len("Topic"),  max(len(r[0]) for r in rows))
    w_s = max(len("Status"), max(len(r[1]) for r in rows))
    w_d = min(max(len("Detail"), max(len(r[2]) for r in rows), 1), 60)

    sep = f"+{'-' * (w_t + 2)}+{'-' * (w_s + 2)}+{'-' * (w_d + 2)}+"
    hdr = f"| {'Topic'.ljust(w_t)} | {'Status'.ljust(w_s)} | {'Detail'.ljust(w_d)} |"

    lines = [sep, hdr, sep]
    for topic, status, detail in rows:
        lines.append(
            f"| {topic.ljust(w_t)} | {status.ljust(w_s)} | {detail[:w_d].ljust(w_d)} |"
        )
    lines.append(sep)
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Bootstrap required Redpanda topics")
    parser.add_argument(
        "--pandaproxy", default="http://127.0.0.1:8082",
        help="Pandaproxy base URL (default: http://127.0.0.1:8082)",
    )
    parser.add_argument(
        "--timeout", type=int, default=5,
        help="HTTP timeout in seconds (default: 5)",
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Show what would be created without creating anything",
    )
    args = parser.parse_args(argv)

    print()
    print("XDR Topic Bootstrap")
    if args.dry_run:
        print("  mode     : dry-run")
    print(f"  proxy    : {args.pandaproxy}")
    print()

    existing = fetch_topic_list(args.pandaproxy, args.timeout)
    if existing is None:
        print(f"ERROR: cannot reach Pandaproxy at {args.pandaproxy}/topics")
        print("       Ensure the Redpanda stack is running: docker compose up -d")
        print()
        return 2

    existing_set = set(existing)
    rows: list[tuple[str, str, str]] = []
    fail_count = 0

    for topic in REQUIRED_TOPICS:
        if topic in existing_set:
            rows.append((topic, PASS, "already present"))
        else:
            status, detail = create_topic(topic, args.dry_run)
            rows.append((topic, status, detail))
            if status == FAIL:
                fail_count += 1

    print(_render_table(rows))
    print()

    created = sum(1 for _, s, _ in rows if s == CREATED)
    present = sum(1 for _, s, _ in rows if s == PASS)
    skipped = sum(1 for _, s, _ in rows if s == SKIP)

    if fail_count == 0:
        if created:
            print(f"BOOTSTRAP_RESULT=OK  ({present} already present, {created} created)")
        elif skipped:
            print(f"BOOTSTRAP_RESULT=DRY_RUN  ({present} present, {skipped} would create)")
        else:
            print(f"BOOTSTRAP_RESULT=OK  (all {present} topics already present)")
        print()
        return 0

    print(f"BOOTSTRAP_RESULT=FAIL  ({fail_count} creation(s) failed — see table above)")
    print("  Ensure Docker is running and the redpanda container is healthy:")
    print("    docker compose up -d")
    print("    docker compose exec redpanda rpk cluster health --brokers=redpanda:9092")
    print()
    return 1


if __name__ == "__main__":
    sys.exit(main())
