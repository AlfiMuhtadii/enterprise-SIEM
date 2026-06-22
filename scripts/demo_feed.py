#!/usr/bin/env python3
"""
Tag a JSONL event file with a unique demo_run_id and sequential trace_ids,
then write the tagged events + a manifest to storage/logs/.

WARNING: This script prepares events for demo lineage purposes only.
It does NOT send events to the ingestion-gateway or any Redpanda topic.
It does NOT produce security_alerts rows on its own.

To prove end-to-end pipeline execution, the tagged events must be sent through
the real ingestion-gateway/Redpanda path (requires --profile strangler stack):

    POST /v1/ingest (ingestion-gateway, HMAC-SHA256 signature required)
    -> telemetry.raw (Redpanda)
    -> normalizer-worker
    -> telemetry.normalized (Redpanda)
    -> correlation-worker  (XDR_CORRELATION_EVENT_LOOP_ENABLED=true)
    -> xdr.alerts (Redpanda)
    -> alert-writer-service  (XDR_EVENT_LOOP_ENABLED=true)
    -> security_alerts (PostgreSQL)

NOTE: scripts/ingest_security_events.py writes to the security_events table
(HTTP request log), NOT to security_alerts. It does NOT trigger the Go
correlation engine and should NOT be used to demonstrate XDR alert creation.

Usage:
    # Auto-generate demo_run_id
    python scripts/demo_feed.py --input storage/logs/attack_scenario.jsonl

    # Reuse an existing demo_run_id (to continue a partial run)
    python scripts/demo_feed.py --input storage/logs/attack_scenario.jsonl \\
        --demo-run-id demo-20260622-abc123

Output:
    storage/logs/<demo_run_id>_tagged.jsonl   -- tagged events (send via ingestion-gateway)
    storage/logs/<demo_run_id>-manifest.json  -- time window for security:alerts-report --demo-run
"""

from __future__ import annotations

import argparse
import json
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path


_MANIFEST_DIR = Path("storage/logs")


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def make_demo_run_id() -> str:
    date = datetime.now(timezone.utc).strftime("%Y%m%d")
    suffix = str(uuid.uuid4())[:6]
    return f"demo-{date}-{suffix}"


def load_events(path: Path) -> list[dict]:
    events: list[dict] = []
    with path.open(encoding="utf-8-sig") as f:
        for lineno, line in enumerate(f, 1):
            line = line.strip()
            if not line:
                continue
            try:
                ev = json.loads(line)
            except json.JSONDecodeError as exc:
                print(f"WARN: line {lineno} is not valid JSON ({exc}) — skipped", file=sys.stderr)
                continue
            if not isinstance(ev, dict):
                print(f"WARN: line {lineno} is not a JSON object — skipped", file=sys.stderr)
                continue
            events.append(ev)
    return events


def tag_events(events: list[dict], demo_run_id: str) -> list[dict]:
    tagged: list[dict] = []
    for i, ev in enumerate(events, 1):
        ev = dict(ev)
        seq = str(i).zfill(4)
        ev["demo_run_id"] = demo_run_id
        # Only set trace_id if not already present; prefix with demo_run_id so
        # the demo_run_id is visible in the trace view URL filter.
        if not ev.get("trace_id"):
            ev["trace_id"] = f"{demo_run_id}-trace-{seq}"
        tagged.append(ev)
    return tagged


def write_manifest(demo_run_id: str, input_path: Path, event_count: int, started_at: str) -> Path:
    _MANIFEST_DIR.mkdir(parents=True, exist_ok=True)
    manifest = {
        "demo_run_id": demo_run_id,
        "started_at": started_at,
        "ended_at": None,
        "input_file": str(input_path),
        "event_count": event_count,
        "status": "running",
    }
    manifest_path = _MANIFEST_DIR / f"{demo_run_id}-manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    return manifest_path


def finalize_manifest(manifest_path: Path) -> None:
    data = json.loads(manifest_path.read_text(encoding="utf-8"))
    data["ended_at"] = now_iso()
    data["status"] = "ready"
    manifest_path.write_text(json.dumps(data, indent=2), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Tag demo events with demo_run_id + sequential trace_ids")
    parser.add_argument("--input", required=True, help="Input JSONL file of telemetry events")
    parser.add_argument("--demo-run-id", default=None,
                        help="Use an existing demo_run_id (default: auto-generate)")
    parser.add_argument("--output-dir", default=str(_MANIFEST_DIR),
                        help=f"Directory for output files (default: {_MANIFEST_DIR})")
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.exists():
        print(f"ERROR: Input file not found: {input_path}", file=sys.stderr)
        return 1

    demo_run_id = args.demo_run_id or make_demo_run_id()
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    sep = "=" * 64
    print(f"\n{sep}")
    print(f"  demo_run_id  : {demo_run_id}")
    print(f"  input        : {input_path}")
    print(sep)

    print("\n[1/3] Loading events...")
    events = load_events(input_path)
    if not events:
        print("ERROR: No events found in input file.", file=sys.stderr)
        return 1
    print(f"  Loaded {len(events)} events")

    print("\n[2/3] Tagging events with demo_run_id + trace_id...")
    started_at = now_iso()
    tagged = tag_events(events, demo_run_id)
    tagged_path = output_dir / f"{demo_run_id}_tagged.jsonl"
    with tagged_path.open("w", encoding="utf-8") as f:
        for ev in tagged:
            f.write(json.dumps(ev) + "\n")
    print(f"  Tagged {len(tagged)} events -> {tagged_path}")
    print(f"  trace_id format: {demo_run_id}-trace-0001 ... {demo_run_id}-trace-{str(len(tagged)).zfill(4)}")

    print("\n[3/3] Writing manifest...")
    manifest_path = write_manifest(demo_run_id, input_path, len(tagged), started_at)
    print(f"  Manifest -> {manifest_path}")

    finalize_manifest(manifest_path)

    print(f"\n{sep}")
    print(f"  DONE: demo_run_id: {demo_run_id}")
    print(sep)
    print(f"""
IMPORTANT — this script tags events for demo lineage tracking.
It does NOT by itself prove the full XDR pipeline. To produce real
security_alerts, the tagged events must go through ingestion-gateway
and be processed by the Go correlation + alert-writer services.

DO NOT use scripts/ingest_security_events.py for XDR alert creation.
That script writes to the security_events table (HTTP request log),
not to security_alerts. It does not trigger the Go correlation engine.

--- To verify end-to-end (requires --profile strangler stack) ---

  0. Check pipeline is up:
       docker compose --profile strangler ps
       (ingestion-gateway, normalizer-worker, correlation-worker, alert-writer must be running)
       Set in .env: XDR_CORRELATION_EVENT_LOOP_ENABLED=true
       Set in .env: XDR_EVENT_LOOP_ENABLED=true

  1. Send events through ingestion-gateway (requires HMAC-SHA256 signature):
       Use the telemetry adapter + ingestion script for your source type.
       Tagged file for reference: {tagged_path}

  2. Wait 30-60s for pipeline processing:
       php artisan security:pipeline-health

  3. Show alerts (time-window filter from manifest):
       php artisan security:alerts-report --minutes=5 --demo-run={demo_run_id}

  4. Show exact rule that fired:
       python scripts/show_rule.py --rule-id <rule_id from report above>

  5. See further details in docs/guides/LIMITATIONS_AND_CLAIMS.md
{sep}
""")
    return 0


if __name__ == "__main__":
    sys.exit(main())
