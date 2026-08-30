#!/usr/bin/env python3
"""
Tag and optionally ingest demo events into the XDR pipeline.

Modes:
  tag-only  (default)
    Tag events with demo_run_id + trace_id, write manifest. No network calls.
    Use this to prepare events before the pipeline is running.

  pipeline
    Tag events, verify ingestion-gateway is healthy, then POST to
    POST /v1/ingest with HMAC-SHA256 signature.
    Requires --profile strangler stack running and event loops enabled:
      docker compose --profile strangler up -d
      XDR_CORRELATION_EVENT_LOOP_ENABLED=true  (correlation-worker)
      XDR_EVENT_LOOP_ENABLED=true              (alert-writer-service)

WARNING: This script does NOT call scripts/ingest_security_events.py.
That script writes to the security_events table (HTTP request log) and
does NOT trigger the Go correlation engine or produce security_alerts rows.

Correct live demo path:
  python scripts/validate_live_xdr_pipeline.py
  python scripts/demo_feed.py --input <file> --mode pipeline \\
      --ingest-url http://localhost:8091/v1/ingest

Usage:
  # Tag only (no network calls):
  python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl

  # Pipeline dry-run (tag + validate, no POST):
  python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl \\
      --mode pipeline --dry-run --ingest-url http://localhost:8091/v1/ingest

  # Pipeline live (tag + validate + POST):
  python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl \\
      --mode pipeline --ingest-url http://localhost:8091/v1/ingest
"""

from __future__ import annotations

import argparse
import hashlib
import hmac as _hmac_mod
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

_MANIFEST_DIR = Path("storage/logs")
_DEFAULT_INGEST_URL = "http://localhost:8091/v1/ingest"
_DEFAULT_SECRET = "dev-secret-change-me"


# ---------------------------------------------------------------------------
# Utilities
# ---------------------------------------------------------------------------

def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def make_demo_run_id() -> str:
    date = datetime.now(timezone.utc).strftime("%Y%m%d")
    suffix = str(uuid.uuid4())[:6]
    return f"demo-{date}-{suffix}"


def load_dotenv(env_path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    if not env_path.exists():
        return result
    with env_path.open(encoding="utf-8-sig") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" not in line:
                continue
            key, _, val = line.partition("=")
            result[key.strip()] = val.strip().strip('"').strip("'")
    return result


def resolve_secret(explicit_secret: str | None, env_vars: dict[str, str]) -> str:
    """Resolve HMAC secret: explicit arg > XDR_INGEST_SECRET env > .env > dev default."""
    if explicit_secret:
        return explicit_secret
    return (
        os.environ.get("XDR_INGEST_SECRET")
        or env_vars.get("XDR_INGEST_SECRET")
        or _DEFAULT_SECRET
    )


# ---------------------------------------------------------------------------
# HMAC signing — matches ingestion-gateway verifySignature()
# ---------------------------------------------------------------------------

def sign_body(secret: str, body: bytes) -> str:
    """Return 'sha256=<hex>' HMAC-SHA256 of body with secret."""
    if not secret:
        return ""
    mac = _hmac_mod.new(secret.encode(), body, hashlib.sha256)
    return "sha256=" + mac.hexdigest()


# ---------------------------------------------------------------------------
# Event loading and tagging
# ---------------------------------------------------------------------------

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
                print(f"WARN: line {lineno} not valid JSON ({exc}) -- skipped", file=sys.stderr)
                continue
            if not isinstance(ev, dict):
                print(f"WARN: line {lineno} not a JSON object -- skipped", file=sys.stderr)
                continue
            events.append(ev)
    return events


def tag_events(
    events: list[dict],
    demo_run_id: str,
    scenario_id: str | None = None,
    tenant_id: str | None = None,
) -> list[dict]:
    """
    Inject demo_run_id, trace_id (if absent), and source_event_id into each event.
    trace_id format: <demo_run_id>-trace-<seq> so the ID is visible in filter queries.
    """
    tagged: list[dict] = []
    for i, ev in enumerate(events, 1):
        ev = dict(ev)
        seq = str(i).zfill(4)
        ev["demo_run_id"] = demo_run_id
        ev["source_event_id"] = ev.get("event_id") or ev.get("id") or f"src-{seq}"
        if not ev.get("trace_id"):
            ev["trace_id"] = f"{demo_run_id}-trace-{seq}"
        # Replace event_id with trace_id so each run produces a unique alert fingerprint.
        # Static event_ids in the scenario file would cause fingerprint collisions across runs,
        # making alert-writer deduplicate every run after the first (FIELD_MATCH always FAIL).
        ev["event_id"] = ev["trace_id"]
        if scenario_id:
            ev["scenario_id"] = scenario_id
        if tenant_id:
            ev.setdefault("tenant_id", tenant_id)
        tagged.append(ev)
    return tagged


# ---------------------------------------------------------------------------
# HTTP helpers — injectable for testing
# ---------------------------------------------------------------------------

def _build_mtls_context(
    args: argparse.Namespace,
    ingest_url: str,
) -> ssl.SSLContext | None:
    """Build a hostname-verifying client context when mTLS is enabled."""
    if not getattr(args, "mtls_enabled", False):
        return None

    if urllib.parse.urlsplit(ingest_url).scheme.lower() != "https":
        raise ValueError("--mtls-enabled requires an https:// ingestion URL")

    material = {
        "--mtls-ca": getattr(args, "mtls_ca", None),
        "--mtls-client-cert": getattr(args, "mtls_client_cert", None),
        "--mtls-client-key": getattr(args, "mtls_client_key", None),
    }
    missing = [option for option, value in material.items() if not value]
    if missing:
        raise ValueError(
            "--mtls-enabled requires complete TLS material; missing "
            + ", ".join(missing)
        )

    context = ssl.create_default_context(cafile=material["--mtls-ca"])
    context.load_cert_chain(
        certfile=material["--mtls-client-cert"],
        keyfile=material["--mtls-client-key"],
    )
    return context


def _http_get(
    url: str,
    timeout: int,
    ssl_context: ssl.SSLContext | None = None,
) -> tuple[int | None, str]:
    try:
        with urllib.request.urlopen(url, timeout=timeout, context=ssl_context) as resp:
            return resp.status, resp.read(256).decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        return exc.code, str(exc)
    except OSError as exc:
        return None, str(exc)


def _http_post(
    url: str,
    headers: dict[str, str],
    body: bytes,
    timeout: int,
    ssl_context: ssl.SSLContext | None = None,
) -> tuple[int, str]:
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ssl_context) as resp:
            return resp.status, resp.read(512).decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        body_text = ""
        try:
            body_text = exc.read(512).decode("utf-8", errors="replace")
        except Exception:
            pass
        return exc.code, body_text
    except OSError as exc:
        return 0, str(exc)


# ---------------------------------------------------------------------------
# Readiness check — GET /health on ingest base URL
# ---------------------------------------------------------------------------

def check_readiness(ingest_url: str, timeout: int, _get_fn=None) -> tuple[bool, str]:
    """
    Quick check: is the ingestion-gateway reachable and healthy?
    Strips /v1/ingest from the URL to derive the base, then GETs /health.
    """
    if _get_fn is None:
        _get_fn = _http_get
    base = ingest_url.split("/v1/ingest")[0] if "/v1/ingest" in ingest_url else ingest_url
    health_url = base.rstrip("/") + "/health"
    code, body = _get_fn(health_url, timeout)
    if code == 200:
        return True, f"HTTP 200 from {health_url}"
    if code is None:
        return False, f"Connection refused at {health_url}: {body}"
    return False, f"HTTP {code} from {health_url}: {body[:120]}"


# ---------------------------------------------------------------------------
# Batch send
# ---------------------------------------------------------------------------

def send_batch(
    batch: list[dict],
    ingest_url: str,
    secret: str,
    timeout: int,
    _post_fn=None,
) -> tuple[int, dict]:
    """POST one batch to ingest URL. Returns (http_status, parsed_response)."""
    if _post_fn is None:
        _post_fn = _http_post
    body_bytes = json.dumps(batch).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "X-XDR-Signature": sign_body(secret, body_bytes),
    }
    status, body = _post_fn(ingest_url, headers, body_bytes, timeout)
    try:
        parsed: dict = json.loads(body)
    except Exception:
        parsed = {"raw": body[:256]}
    return status, parsed


# ---------------------------------------------------------------------------
# Manifest writer
# ---------------------------------------------------------------------------

def write_manifest(
    *,
    demo_run_id: str,
    scenario_id: str | None,
    mode: str,
    dry_run: bool,
    input_path: Path,
    tagged_path: Path,
    ingest_url: str | None,
    started_at: str,
    ended_at: str,
    event_count: int,
    sent_count: int,
    failed_count: int,
    tagged: list[dict],
    readiness_check_result: str,
    response_summary: list[dict],
    output_dir: Path,
) -> Path:
    output_dir.mkdir(parents=True, exist_ok=True)
    manifest = {
        "demo_run_id": demo_run_id,
        "scenario_id": scenario_id,
        "mode": mode,
        "dry_run": dry_run,
        "input_path": str(input_path),
        "tagged_output_path": str(tagged_path),
        "ingest_url": ingest_url,
        "started_at": started_at,
        "ended_at": ended_at,
        "event_count": event_count,
        "sent_count": sent_count,
        "failed_count": failed_count,
        "trace_id_first": tagged[0].get("trace_id", "") if tagged else "",
        "trace_id_last": tagged[-1].get("trace_id", "") if tagged else "",
        "readiness_check_result": readiness_check_result,
        "response_summary": response_summary,
    }
    manifest_path = output_dir / f"{demo_run_id}-manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    return manifest_path


# ---------------------------------------------------------------------------
# Mode: tag-only
# ---------------------------------------------------------------------------

def run_tag_only(args: argparse.Namespace, demo_run_id: str) -> int:
    input_path = Path(args.input)
    if not input_path.exists():
        print(f"ERROR: Input file not found: {input_path}", file=sys.stderr)
        return 1

    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    sep = "=" * 64
    print(f"\n{sep}")
    print(f"  demo_run_id  : {demo_run_id}")
    print(f"  mode         : tag-only")
    print(f"  input        : {input_path}")
    print(sep)

    print("\n[1/3] Loading events...")
    events = load_events(input_path)
    if not events:
        print("ERROR: No events found in input file.", file=sys.stderr)
        return 1
    print(f"  Loaded {len(events)} events")

    print("\n[2/3] Tagging events with demo_run_id + trace_id + source_event_id...")
    started_at = now_iso()
    tagged = tag_events(events, demo_run_id, getattr(args, "scenario_id", None),
                        getattr(args, "tenant_id", None))
    tagged_path = output_dir / f"{demo_run_id}_tagged.jsonl"
    with tagged_path.open("w", encoding="utf-8") as f:
        for ev in tagged:
            f.write(json.dumps(ev) + "\n")
    print(f"  Tagged {len(tagged)} events -> {tagged_path}")

    print("\n[3/3] Writing manifest...")
    manifest_path = write_manifest(
        demo_run_id=demo_run_id,
        scenario_id=getattr(args, "scenario_id", None),
        mode="tag-only",
        dry_run=False,
        input_path=input_path,
        tagged_path=tagged_path,
        ingest_url=None,
        started_at=started_at,
        ended_at=now_iso(),
        event_count=len(events),
        sent_count=0,
        failed_count=0,
        tagged=tagged,
        readiness_check_result="skipped",
        response_summary=[],
        output_dir=output_dir,
    )
    print(f"  Manifest -> {manifest_path}")

    print(f"\n{sep}")
    print(f"  DONE (tag-only): demo_run_id={demo_run_id}")
    print(sep)
    print(f"""
WARNING: tag-only mode. No events were sent to any service.
The tagged JSONL is ready: {tagged_path}

To send events through the live pipeline (requires --profile strangler stack):

  1. Verify pipeline is ready:
       python scripts/validate_live_xdr_pipeline.py

  2. Send tagged events:
       python scripts/demo_feed.py --input {input_path} \\
           --demo-run-id {demo_run_id} \\
           --mode pipeline \\
           --ingest-url http://localhost:8091/v1/ingest

DO NOT use scripts/ingest_security_events.py for XDR alert creation.
That script writes to security_events (HTTP request log), not security_alerts.
""")
    return 0


# ---------------------------------------------------------------------------
# Mode: pipeline
# ---------------------------------------------------------------------------

def run_pipeline(
    args: argparse.Namespace,
    demo_run_id: str,
    _post_fn=None,
    _get_fn=None,
) -> int:
    input_path = Path(args.input)
    if not input_path.exists():
        print(f"ERROR: Input file not found: {input_path}", file=sys.stderr)
        return 1

    env_vars = load_dotenv(Path(".env"))
    secret = resolve_secret(getattr(args, "ingest_secret", None), env_vars)
    ingest_url: str = args.ingest_url
    timeout: int = args.timeout_seconds
    batch_size: int = max(1, min(args.batch_size, 1000))
    dry_run: bool = args.dry_run
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    try:
        ssl_context = _build_mtls_context(args, ingest_url)
    except (OSError, ValueError) as exc:
        print(f"ERROR: Invalid mTLS configuration: {exc}", file=sys.stderr)
        return 2

    get_fn = _get_fn
    if get_fn is None:
        get_fn = lambda url, request_timeout: _http_get(
            url, request_timeout, ssl_context
        )
    post_fn = _post_fn
    if post_fn is None:
        post_fn = lambda url, headers, body, request_timeout: _http_post(
            url, headers, body, request_timeout, ssl_context
        )

    sep = "=" * 64
    mode_label = "pipeline [DRY-RUN]" if dry_run else "pipeline"
    print(f"\n{sep}")
    print(f"  demo_run_id  : {demo_run_id}")
    print(f"  mode         : {mode_label}")
    print(f"  input        : {input_path}")
    print(f"  ingest_url   : {ingest_url}")
    print(f"  mTLS         : {'enabled' if ssl_context else 'disabled'}")
    print(f"  batch_size   : {batch_size}")
    print(sep)

    # Step 1 — Readiness check
    readiness_result = "skipped"
    if not args.skip_readiness_check:
        print("\n[1/4] Checking ingestion-gateway readiness...")
        ok, msg = check_readiness(ingest_url, timeout, get_fn)
        if ok:
            readiness_result = "pass"
            print(f"  OK: {msg}")
        else:
            readiness_result = "fail"
            print(f"  FAIL: {msg}", file=sys.stderr)
            print("  Tip: run python scripts/validate_live_xdr_pipeline.py", file=sys.stderr)
            print("  Use --skip-readiness-check to bypass (not recommended for live demo)", file=sys.stderr)
            return 1
    else:
        print("\n[1/4] Readiness check skipped (--skip-readiness-check)")

    # Step 2 — Load and tag
    print("\n[2/4] Loading and tagging events...")
    events = load_events(input_path)
    if not events:
        print("ERROR: No events found in input file.", file=sys.stderr)
        return 1
    started_at = now_iso()
    tagged = tag_events(events, demo_run_id,
                        getattr(args, "scenario_id", None),
                        getattr(args, "tenant_id", None))
    tagged_path = output_dir / f"{demo_run_id}_tagged.jsonl"
    with tagged_path.open("w", encoding="utf-8") as f:
        for ev in tagged:
            f.write(json.dumps(ev) + "\n")
    print(f"  Tagged {len(tagged)} events -> {tagged_path}")
    n_batches = (len(tagged) + batch_size - 1) // batch_size

    if dry_run:
        # Step 3 dry — report without POST
        print(f"\n[3/4] DRY-RUN: would POST {len(tagged)} events in {n_batches} "
              f"batch(es) to {ingest_url} (no network call made)")
        manifest_path = write_manifest(
            demo_run_id=demo_run_id,
            scenario_id=getattr(args, "scenario_id", None),
            mode="pipeline",
            dry_run=True,
            input_path=input_path,
            tagged_path=tagged_path,
            ingest_url=ingest_url,
            started_at=started_at,
            ended_at=now_iso(),
            event_count=len(events),
            sent_count=0,
            failed_count=0,
            tagged=tagged,
            readiness_check_result=readiness_result,
            response_summary=[],
            output_dir=output_dir,
        )
        print(f"\n[4/4] Manifest -> {manifest_path}")
        print(f"\n{sep}")
        print(f"  DONE (dry-run): {len(tagged)} events ready, no POST performed.")
        print(sep)
        return 0

    # Step 3 live — send batches
    print(f"\n[3/4] Sending {len(tagged)} events in {n_batches} batch(es)...")
    sent_count = 0
    failed_count = 0
    response_summary: list[dict] = []
    batches = [tagged[i:i + batch_size] for i in range(0, len(tagged), batch_size)]
    for batch_num, batch in enumerate(batches, 1):
        status, parsed = send_batch(batch, ingest_url, secret, timeout, post_fn)
        entry: dict = {"batch": batch_num, "size": len(batch), "status": status}
        for k in ("accepted", "latency_ms", "error", "raw"):
            if k in parsed:
                entry[k] = parsed[k]
        if status == 202:
            sent_count += len(batch)
            print(f"  batch {batch_num}/{n_batches}: HTTP 202 "
                  f"accepted={parsed.get('accepted', '?')} "
                  f"latency_ms={parsed.get('latency_ms', '?')}")
        else:
            failed_count += len(batch)
            print(f"  batch {batch_num}/{n_batches}: HTTP {status} FAIL -- {parsed}",
                  file=sys.stderr)
        response_summary.append(entry)
        if batch_num < n_batches:
            time.sleep(0.05)  # stay well under 50 RPS default rate limit

    ended_at = now_iso()

    # Step 4 — manifest
    manifest_path = write_manifest(
        demo_run_id=demo_run_id,
        scenario_id=getattr(args, "scenario_id", None),
        mode="pipeline",
        dry_run=False,
        input_path=input_path,
        tagged_path=tagged_path,
        ingest_url=ingest_url,
        started_at=started_at,
        ended_at=ended_at,
        event_count=len(events),
        sent_count=sent_count,
        failed_count=failed_count,
        tagged=tagged,
        readiness_check_result=readiness_result,
        response_summary=response_summary,
        output_dir=output_dir,
    )
    print(f"\n[4/4] Manifest -> {manifest_path}")
    print(f"\n{sep}")
    print(f"  DONE: demo_run_id={demo_run_id}")
    print(f"  sent={sent_count}  failed={failed_count}  total={len(tagged)}")
    print(sep)

    if failed_count == 0:
        print(f"""
Next steps (wait 30-60s for pipeline processing):

  1. Verify full pipeline:
       python scripts/validate_live_xdr_pipeline.py

  2. Show alerts (manifest time window):
       php artisan security:alerts-report --minutes=5 --demo-run={demo_run_id}

  3. Show the rule that fired:
       python scripts/show_rule.py --rule-id <rule_id from report>

  4. See docs/guides/LIMITATIONS_AND_CLAIMS.md for pipeline architecture.
""")
    else:
        print(f"\nWARNING: {failed_count} event(s) failed ingestion.", file=sys.stderr)
        print(f"  Check: XDR_INGEST_SECRET matches the gateway secret.", file=sys.stderr)
        print(f"  Default dev secret: {_DEFAULT_SECRET}", file=sys.stderr)
        print(f"  Check gateway logs: docker compose logs correlation-worker", file=sys.stderr)

    return 0 if failed_count == 0 else 1


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Tag and optionally ingest demo events into the XDR pipeline",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Modes:
  tag-only (default)
    Tag events with demo_run_id + trace_id. No network calls.

  pipeline
    Tag events then POST to ingestion-gateway via HMAC-SHA256.
    Requires --profile strangler stack + event loops enabled in .env.

Examples:
  python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl

  python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl \\
      --mode pipeline --dry-run

  python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl \\
      --mode pipeline --ingest-url http://localhost:8091/v1/ingest
""",
    )
    parser.add_argument("--input", required=True,
                        help="Input JSONL file of telemetry events")
    parser.add_argument("--mode", choices=["tag-only", "pipeline"], default="tag-only",
                        help="Execution mode (default: tag-only)")
    parser.add_argument("--demo-run-id", default=None,
                        help="Reuse an existing demo_run_id (default: auto-generate)")
    parser.add_argument("--output-dir", default=str(_MANIFEST_DIR),
                        help=f"Output directory for tagged JSONL + manifest (default: {_MANIFEST_DIR})")
    parser.add_argument("--ingest-url", default=_DEFAULT_INGEST_URL,
                        help=f"Ingestion-gateway URL (default: {_DEFAULT_INGEST_URL})")
    parser.add_argument("--ingest-secret", default=None, dest="ingest_secret",
                        help="HMAC secret (default: XDR_INGEST_SECRET env var or dev-secret-change-me)")
    parser.add_argument("--tenant-id", default=None, dest="tenant_id",
                        help="Optional tenant_id injected into each event")
    parser.add_argument("--scenario-id", default=None, dest="scenario_id",
                        help="Optional scenario_id injected into each event")
    parser.add_argument("--dry-run", action="store_true",
                        help="Pipeline mode: validate + tag but do not POST")
    parser.add_argument("--skip-readiness-check", action="store_true",
                        dest="skip_readiness_check",
                        help="Pipeline mode: skip ingestion-gateway health check")
    parser.add_argument("--timeout-seconds", type=int, default=5, dest="timeout_seconds",
                        help="HTTP timeout in seconds (default: 5)")
    parser.add_argument("--batch-size", type=int, default=10, dest="batch_size",
                        help="Events per POST request, 1-1000 (default: 10)")
    parser.add_argument("--mtls-enabled", action="store_true", dest="mtls_enabled",
                        help="Require mutual TLS for pipeline ingestion")
    parser.add_argument("--mtls-ca", default=None, dest="mtls_ca",
                        help="PEM CA bundle used to verify ingestion-gateway")
    parser.add_argument("--mtls-client-cert", default=None, dest="mtls_client_cert",
                        help="PEM client certificate presented to ingestion-gateway")
    parser.add_argument("--mtls-client-key", default=None, dest="mtls_client_key",
                        help="PEM private key for --mtls-client-cert")
    args = parser.parse_args()

    demo_run_id = args.demo_run_id or make_demo_run_id()

    if args.mode == "tag-only":
        return run_tag_only(args, demo_run_id)
    return run_pipeline(args, demo_run_id)


if __name__ == "__main__":
    sys.exit(main())
