#!/usr/bin/env python3
"""
ENTERPRISE-038: Live Soak / Load Validation.

Controlled load test for the XDR pipeline. Sends batches of safe synthetic
demo telemetry to the ingestion gateway and collects stability metrics.

Default mode is dry-run (plan + pre-flight, no ingestion).
Use --execute for the actual soak run.

Safety invariants:
  - Safe synthetic cloud/identity demo events only — not real threat data
  - All events carry demo lineage fields: demo_run_id, scenario_id, tenant_id
  - Duration capped at MAX_DURATION_MINUTES (60)
  - Events per batch capped at MAX_EVENTS_PER_BATCH (50)
  - Total events capped at MAX_TOTAL_EVENTS (1000)
  - Batch interval >= MIN_BATCH_INTERVAL_MS (200ms)
  - No active response, containment, or remediation
  - No ACTIVE_ALLOWLIST changes; no shadow→active promotion

Metrics collected:
  total_attempted, accepted, rate_limited, rejected,
  publish_failures, timeouts,
  p95_latency_ms, p99_latency_ms, mean_latency_ms,
  circuit_breaker_opens (inferred from consecutive 503s),
  watermarks_before / watermarks_after (Redpanda topic high watermarks),
  alerts_delta, incidents_delta (advisory — requires DB)

Pass criteria (production profile):
  accepted_rate  >= 0.90  (FAIL < 0.80, WARN 0.80–0.90)
  p95_latency_ms <  300   (FAIL >= 500, WARN 300–499)
  p99_latency_ms <  600   (FAIL >= 1000, WARN 600–999)
  publish_failures == 0   (FAIL > 2, WARN 1–2)
  circuit_breaker_opens == 0 (FAIL > 1, WARN == 1)
  rate_limited_rate <= 0.05  (FAIL > 0.10, WARN 0.05–0.10)

Exit codes: 0=PASS  1=FAIL (≥1 FAIL-level bound exceeded)  2=ERROR
"""
from __future__ import annotations

import argparse
import hashlib
import hmac as _hmac
import json
import os
import sys
import time
import urllib.error
import urllib.request
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

_PROJECT_ROOT = Path(__file__).resolve().parent.parent

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

PASS = "PASS"
FAIL = "FAIL"
WARN = "WARN"
INFO = "INFO"

MAX_DURATION_MINUTES = 60
MAX_EVENTS_PER_BATCH = 50
MAX_TOTAL_EVENTS = 1000
MIN_BATCH_INTERVAL_MS = 200

_DEFAULT_DURATION_MINUTES = 2
_DEFAULT_EVENTS_PER_BATCH = 5
_DEFAULT_BATCH_INTERVAL_MS = 2000
_DEFAULT_INGEST_URL = "http://localhost:8091/v1/ingest"
_DEFAULT_ADMIN_URL = "http://localhost:9644"
_DEFAULT_SECRET = "dev-secret-change-me"
_DEFAULT_SCENARIO_ID = "soak-scenario-038"
_DEFAULT_TENANT_ID = "soak-tenant-038"

_SOAK_TOPICS = [
    "telemetry.raw",
    "telemetry.normalized",
    "xdr.alerts",
]
_DLQ_TOPICS = [
    "telemetry.normalization_failed",
    "xdr.correlation_failed",
    "xdr.alert_write_failed",
]

# Synthetic event types — cloud/identity domain (active correlation scope)
_EVENT_TYPES = [
    ("cloud.iam.api_key_created",       "cloud",    "aws",       "CreateAccessKey"),
    ("cloud.security.setting_modified", "cloud",    "aws",       "ModifySecuritySetting"),
    ("saas.login.success",              "saas",     "office365", "UserLoginSuccess"),
    ("identity.login.success",          "identity", "okta",      "user.session.start"),
    ("cloud.storage.bucket_accessed",   "cloud",    "aws",       "GetObject"),
]


# ---------------------------------------------------------------------------
# .env loader
# ---------------------------------------------------------------------------

def _load_dotenv(root: Path) -> dict[str, str]:
    env_path = root / ".env"
    result: dict[str, str] = {}
    if not env_path.exists():
        return result
    with env_path.open(encoding="utf-8-sig") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, _, v = line.partition("=")
            result[k.strip()] = v.strip().strip('"').strip("'")
    return result


def _resolve_secret(env: dict[str, str]) -> str:
    return (
        os.environ.get("XDR_INGEST_SECRET")
        or env.get("XDR_INGEST_SECRET")
        or _DEFAULT_SECRET
    )


# ---------------------------------------------------------------------------
# HMAC signing — matches ingestion-gateway verifySignature()
# ---------------------------------------------------------------------------

def _sign(secret: str, body: bytes) -> str:
    mac = _hmac.new(secret.encode(), body, hashlib.sha256)
    return "sha256=" + mac.hexdigest()


# ---------------------------------------------------------------------------
# HTTP helpers (injectable for testing)
# ---------------------------------------------------------------------------

def _http_get(url: str, timeout: int) -> tuple[int | None, str]:
    try:
        with urllib.request.urlopen(url, timeout=timeout) as resp:
            return resp.status, resp.read(131072).decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        return exc.code, str(exc)
    except OSError as exc:
        return None, str(exc)


def _http_post(url: str, headers: dict[str, str], body: bytes,
               timeout: int) -> tuple[int, str]:
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
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


def _make_persistent_post_fn(url: str, timeout: int):
    """Return a (conn, post_fn) pair using a reused HTTP/1.1 connection.

    Eliminates per-batch TCP handshake overhead. The caller must close conn
    when done.  post_fn honours the same (url, headers, body, timeout)
    signature as _http_post so it is drop-in compatible.
    """
    import http.client
    from urllib.parse import urlparse

    parsed = urlparse(url)
    host = parsed.hostname or "localhost"
    port = parsed.port or 80
    path = parsed.path or "/"

    conn = http.client.HTTPConnection(host, port, timeout=timeout)

    def _fn(url_: str, hdrs: dict[str, str], data: bytes, _t: int) -> tuple[int, str]:
        for attempt in range(2):
            try:
                conn.request("POST", path, body=data, headers=hdrs)
                resp = conn.getresponse()
                status = resp.status
                body_text = resp.read(512).decode("utf-8", errors="replace")
                return status, body_text
            except http.client.HTTPException as exc:
                if attempt == 0:
                    try:
                        conn.close()
                        conn.connect()
                    except Exception:
                        pass
                    continue
                return 0, str(exc)
            except OSError as exc:
                if attempt == 0:
                    try:
                        conn.close()
                        conn.connect()
                    except Exception:
                        pass
                    continue
                return 0, str(exc)
        return 0, "persistent connection failed after retry"

    return conn, _fn


# ---------------------------------------------------------------------------
# Prometheus gauge parser (reused from validate_live_xdr_pipeline.py pattern)
# ---------------------------------------------------------------------------

def _parse_prometheus_gauge(
    text: str,
    metric_name: str,
    required_labels: dict[str, str],
) -> float | None:
    prefix = metric_name + "{"
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("#") or not line.startswith(prefix):
            continue
        try:
            brace_end = line.index("}")
            label_str = line[len(prefix):brace_end]
            labels: dict[str, str] = {}
            for part in label_str.split(","):
                if "=" not in part:
                    continue
                k, _, v = part.partition("=")
                labels[k.strip()] = v.strip().strip('"')
            if not all(labels.get(k) == v for k, v in required_labels.items()):
                continue
            val_str = line[brace_end + 1:].strip().split()[0]
            return float(val_str)
        except (ValueError, IndexError):
            continue
    return None


# ---------------------------------------------------------------------------
# Synthetic event generation
# ---------------------------------------------------------------------------

def make_soak_run_id() -> str:
    ts = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    suffix = str(uuid.uuid4())[:6]
    return f"soak-{ts}-{suffix}"


def make_event(
    seq: int,
    soak_run_id: str,
    scenario_id: str,
    tenant_id: str,
) -> dict[str, Any]:
    """Return one synthetic soak event with full demo lineage fields."""
    etype, domain, source, action = _EVENT_TYPES[seq % len(_EVENT_TYPES)]
    event_id = f"{soak_run_id}-evt-{seq:06d}"
    return {
        "event_id": event_id,
        "trace_id": event_id,
        "source_event_id": f"src-soak-{seq:06d}",
        "demo_run_id": soak_run_id,
        "scenario_id": scenario_id,
        "tenant_id": tenant_id,
        "event_type": etype,
        "domain": domain,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "source": source,
        "actor": f"soak-actor-{seq % 10:02d}@soak.example.invalid",
        "resource": f"arn:aws:iam::000000000000:user/soak-{seq:06d}",
        "action": action,
        "region": "us-east-1",
        "metadata": {
            "soak_seq": seq,
            "soak_batch": seq // 5,
            "_soak": True,
        },
    }


def make_batch(
    batch_num: int,
    events_per_batch: int,
    soak_run_id: str,
    scenario_id: str,
    tenant_id: str,
) -> list[dict]:
    base = batch_num * events_per_batch
    return [
        make_event(base + i, soak_run_id, scenario_id, tenant_id)
        for i in range(events_per_batch)
    ]


# ---------------------------------------------------------------------------
# Percentile helpers
# ---------------------------------------------------------------------------

def _pct(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, int(len(sv) * p / 100) - 1)
    return sv[min(idx, len(sv) - 1)]


def _mean(values: list[float]) -> float:
    return sum(values) / len(values) if values else 0.0


# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------

def _pf(step_id: str, name: str, status: str,
        detail: str, remediation: str = "") -> dict[str, Any]:
    return {"step_id": step_id, "name": name, "status": status,
            "detail": detail, "remediation": remediation}


def check_duration_bounds(duration_minutes: int) -> dict:
    passed = 1 <= duration_minutes <= MAX_DURATION_MINUTES
    return _pf(
        "PRE-01", f"Duration within bounds (1–{MAX_DURATION_MINUTES}m)",
        PASS if passed else FAIL,
        f"duration_minutes={duration_minutes}",
        f"Set --duration-minutes between 1 and {MAX_DURATION_MINUTES}.",
    )


def check_batch_bounds(events_per_batch: int, batch_interval_ms: int) -> dict:
    epb_ok = 1 <= events_per_batch <= MAX_EVENTS_PER_BATCH
    ms_ok = batch_interval_ms >= MIN_BATCH_INTERVAL_MS
    passed = epb_ok and ms_ok
    detail = (
        f"events_per_batch={events_per_batch}  batch_interval_ms={batch_interval_ms}"
    )
    rem = ""
    if not epb_ok:
        rem = f"Set --events-per-batch between 1 and {MAX_EVENTS_PER_BATCH}."
    elif not ms_ok:
        rem = f"Set --batch-interval-ms >= {MIN_BATCH_INTERVAL_MS}."
    return _pf("PRE-02", "Batch parameters within bounds", PASS if passed else FAIL,
               detail, rem)


def check_total_events_cap(total: int) -> dict:
    passed = total <= MAX_TOTAL_EVENTS
    return _pf(
        "PRE-03", f"Total events <= {MAX_TOTAL_EVENTS} (safety cap)",
        PASS if passed else FAIL,
        f"total_events={total}",
        "Reduce --duration-minutes or --events-per-batch to stay within the cap.",
    )


def check_gateway_reachable(
    ingest_url: str,
    timeout: int,
    execute: bool,
    _get_fn=None,
) -> dict:
    if _get_fn is None:
        _get_fn = _http_get
    base = ingest_url.split("/v1/ingest")[0] if "/v1/ingest" in ingest_url else ingest_url
    health_url = base.rstrip("/") + "/health"
    code, body = _get_fn(health_url, timeout)
    reachable = code == 200
    sev = FAIL if execute else WARN
    return _pf(
        "PRE-04", "Ingestion gateway reachable",
        PASS if reachable else sev,
        f"GET {health_url} → {code}",
        "Start ingestion gateway: docker compose --profile=strangler up -d ingestion-gateway",
    )


# ---------------------------------------------------------------------------
# Plan generation
# ---------------------------------------------------------------------------

def build_plan(
    duration_minutes: int,
    events_per_batch: int,
    batch_interval_ms: int,
    ingest_url: str,
    soak_run_id: str,
    scenario_id: str,
    tenant_id: str,
) -> dict[str, Any]:
    batches_per_minute = 60_000 / max(batch_interval_ms, 1)
    total_batches = int(duration_minutes * batches_per_minute)
    total_events = min(total_batches * events_per_batch, MAX_TOTAL_EVENTS)
    # Recalculate actual batches after cap
    actual_batches = (total_events + events_per_batch - 1) // events_per_batch
    throughput_eps = events_per_batch / max(batch_interval_ms / 1000, 0.001)
    return {
        "soak_run_id": soak_run_id,
        "scenario_id": scenario_id,
        "tenant_id": tenant_id,
        "ingest_url": ingest_url,
        "duration_minutes": duration_minutes,
        "events_per_batch": events_per_batch,
        "batch_interval_ms": batch_interval_ms,
        "total_batches": actual_batches,
        "total_events_planned": total_events,
        "throughput_eps": round(throughput_eps, 2),
        "capped": total_batches * events_per_batch > MAX_TOTAL_EVENTS,
        "topics_monitored": _SOAK_TOPICS + _DLQ_TOPICS,
    }


# ---------------------------------------------------------------------------
# Watermark collection
# ---------------------------------------------------------------------------

def collect_watermarks(
    admin_url: str,
    topics: list[str],
    timeout: int,
    _get_fn=None,
) -> dict[str, int | None]:
    if _get_fn is None:
        _get_fn = _http_get
    url = admin_url.rstrip("/") + "/public_metrics"
    code, body = _get_fn(url, timeout)
    if code != 200:
        return {t: None for t in topics}
    result: dict[str, int | None] = {}
    for topic in topics:
        offset = _parse_prometheus_gauge(
            body, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": topic},
        )
        result[topic] = int(offset) if offset is not None else None
    return result


# ---------------------------------------------------------------------------
# Soak execution loop
# ---------------------------------------------------------------------------

def run_soak_loop(
    plan: dict[str, Any],
    secret: str,
    timeout: int,
    _post_fn=None,
    _sleep_fn=None,
) -> dict[str, Any]:
    """Execute the soak batches and return raw metrics."""
    if _post_fn is None:
        _post_fn = _http_post
    if _sleep_fn is None:
        _sleep_fn = time.sleep

    soak_run_id = plan["soak_run_id"]
    scenario_id = plan["scenario_id"]
    tenant_id = plan["tenant_id"]
    ingest_url = plan["ingest_url"]
    epb = plan["events_per_batch"]
    interval_s = plan["batch_interval_ms"] / 1000.0
    total_batches = plan["total_batches"]

    accepted = 0
    rate_limited = 0
    rejected = 0
    publish_failures = 0
    timeouts = 0
    latencies_ms: list[float] = []
    consecutive_503 = 0
    circuit_breaker_opens = 0
    batch_results: list[dict] = []

    for bn in range(total_batches):
        batch = make_batch(bn, epb, soak_run_id, scenario_id, tenant_id)
        body_bytes = json.dumps(batch).encode("utf-8")
        headers = {
            "Content-Type": "application/json",
            "X-XDR-Signature": _sign(secret, body_bytes),
        }

        t0 = time.perf_counter()
        status, body = _post_fn(ingest_url, headers, body_bytes, timeout)
        elapsed_ms = (time.perf_counter() - t0) * 1000

        try:
            parsed: dict = json.loads(body)
        except Exception:
            parsed = {}

        latencies_ms.append(elapsed_ms)
        batch_entry: dict = {
            "batch": bn,
            "events": len(batch),
            "status": status,
            "latency_ms": round(elapsed_ms, 1),
        }

        if status == 202:
            n = parsed.get("accepted", len(batch))
            accepted += n
            consecutive_503 = 0
        elif status == 429:
            rate_limited += len(batch)
            consecutive_503 = 0
        elif status == 503:
            rejected += len(batch)
            consecutive_503 += 1
            if consecutive_503 >= 3:
                circuit_breaker_opens += 1
                consecutive_503 = 0
        elif status == 0:
            publish_failures += 1
            timeouts += 1
            consecutive_503 = 0
        else:
            rejected += len(batch)
            consecutive_503 = 0

        batch_results.append(batch_entry)

        if bn < total_batches - 1 and interval_s > 0:
            _sleep_fn(interval_s)

    total_attempted = accepted + rate_limited + rejected + (publish_failures * epb)
    return {
        "total_attempted": total_attempted,
        "accepted": accepted,
        "rate_limited": rate_limited,
        "rejected": rejected,
        "publish_failures": publish_failures,
        "timeouts": timeouts,
        "latencies_ms": latencies_ms,
        "circuit_breaker_opens": circuit_breaker_opens,
        "batch_results": batch_results,
        "total_batches_run": total_batches,
    }


# ---------------------------------------------------------------------------
# Bounds evaluation
# ---------------------------------------------------------------------------

def evaluate_bounds(
    raw: dict[str, Any],
    profile: str,
) -> list[dict[str, Any]]:
    """Evaluate soak metrics against pass/warn/fail thresholds."""
    bounds: list[dict] = []
    attempted = max(raw.get("total_attempted", 0), 1)
    accepted = raw.get("accepted", 0)
    rate_limited = raw.get("rate_limited", 0)
    pub_fail = raw.get("publish_failures", 0)
    cb_opens = raw.get("circuit_breaker_opens", 0)
    lats = raw.get("latencies_ms", [])

    accepted_rate = accepted / attempted
    rate_lim_rate = rate_limited / attempted
    p95 = _pct(lats, 95)
    p99 = _pct(lats, 99)
    mean_lat = _mean(lats)

    def _bnd(bid, name, value, unit, warn_lo, warn_hi, fail_lo, fail_hi, higher_is_better=True):
        if higher_is_better:
            status = FAIL if value < fail_lo else WARN if value < warn_lo else PASS
        else:
            status = FAIL if value > fail_hi else WARN if value > warn_hi else PASS
        return {
            "bound_id": bid, "name": name,
            "value": round(value, 4), "unit": unit,
            "status": status,
            "thresholds": {"warn": warn_lo if higher_is_better else warn_hi,
                           "fail": fail_lo if higher_is_better else fail_hi},
        }

    # accepted_rate: higher is better
    bounds.append(_bnd(
        "B-01", "Accepted rate", accepted_rate, "ratio",
        warn_lo=0.90, warn_hi=0, fail_lo=0.80, fail_hi=0,
        higher_is_better=True,
    ))
    # rate_limited_rate: lower is better
    bounds.append(_bnd(
        "B-02", "Rate-limited rate", rate_lim_rate, "ratio",
        warn_lo=0, warn_hi=0.05, fail_lo=0, fail_hi=0.10,
        higher_is_better=False,
    ))
    # p95 latency: lower is better
    bounds.append(_bnd(
        "B-03", "p95 ingest latency", p95, "ms",
        warn_lo=0, warn_hi=300, fail_lo=0, fail_hi=500,
        higher_is_better=False,
    ))
    # p99 latency: lower is better
    bounds.append(_bnd(
        "B-04", "p99 ingest latency", p99, "ms",
        warn_lo=0, warn_hi=600, fail_lo=0, fail_hi=1000,
        higher_is_better=False,
    ))
    # publish failures: lower is better
    bounds.append(_bnd(
        "B-05", "Publish failures", pub_fail, "count",
        warn_lo=0, warn_hi=0, fail_lo=0, fail_hi=2,
        higher_is_better=False,
    ))
    # circuit breaker opens: lower is better
    bounds.append(_bnd(
        "B-06", "Circuit breaker opens", cb_opens, "count",
        warn_lo=0, warn_hi=0, fail_lo=0, fail_hi=1,
        higher_is_better=False,
    ))

    return bounds


# ---------------------------------------------------------------------------
# Report assembly
# ---------------------------------------------------------------------------

def build_report(
    plan: dict,
    preflight: list[dict],
    raw: dict[str, Any],
    bounds: list[dict],
    watermarks_before: dict,
    watermarks_after: dict,
    args: argparse.Namespace,
    started_at: str,
    ended_at: str,
) -> dict[str, Any]:
    pre_fails = sum(1 for s in preflight if s["status"] == FAIL)
    bound_fails = sum(1 for b in bounds if b["status"] == FAIL)
    bound_warns = sum(1 for b in bounds if b["status"] == WARN)
    lats = raw.get("latencies_ms", [])

    overall = FAIL if (pre_fails > 0 or bound_fails > 0) else PASS

    return {
        "task": "ENTERPRISE-038",
        "started_at": started_at,
        "ended_at": ended_at,
        "mode": "execute" if getattr(args, "execute", False) else "dry-run",
        "profile": getattr(args, "profile", "local"),
        "soak_run_id": plan.get("soak_run_id"),
        "overall": overall,
        "status_line": (
            f"overall={overall}  "
            f"accepted={raw.get('accepted', 0)}/"
            f"{raw.get('total_attempted', 0)}  "
            f"p95={round(_pct(lats, 95), 1)}ms  "
            f"bound_fails={bound_fails}  bound_warns={bound_warns}"
        ),
        "plan": plan,
        "preflight": preflight,
        "metrics": {
            "total_attempted": raw.get("total_attempted", 0),
            "accepted": raw.get("accepted", 0),
            "rate_limited": raw.get("rate_limited", 0),
            "rejected": raw.get("rejected", 0),
            "publish_failures": raw.get("publish_failures", 0),
            "timeouts": raw.get("timeouts", 0),
            "circuit_breaker_opens": raw.get("circuit_breaker_opens", 0),
            "p95_latency_ms": round(_pct(lats, 95), 2),
            "p99_latency_ms": round(_pct(lats, 99), 2),
            "mean_latency_ms": round(_mean(lats), 2),
            "total_batches_run": raw.get("total_batches_run", 0),
        },
        "watermarks_before": watermarks_before,
        "watermarks_after": watermarks_after,
        "bounds": bounds,
    }


# ---------------------------------------------------------------------------
# Orchestrator
# ---------------------------------------------------------------------------

def run_validate(
    args: argparse.Namespace,
    root: Path = _PROJECT_ROOT,
    env: dict[str, str] | None = None,
    _get_fn=None,
    _post_fn=None,
    _sleep_fn=None,
) -> dict[str, Any]:
    started_at = datetime.now(timezone.utc).isoformat()

    if env is None:
        env = _load_dotenv(root)
    secret = _resolve_secret(env)

    ingest_url = getattr(args, "ingest_url", _DEFAULT_INGEST_URL)
    admin_url = getattr(args, "admin_url", _DEFAULT_ADMIN_URL)
    duration_min = getattr(args, "duration_minutes", _DEFAULT_DURATION_MINUTES)
    epb = getattr(args, "events_per_batch", _DEFAULT_EVENTS_PER_BATCH)
    interval_ms = getattr(args, "batch_interval_ms", _DEFAULT_BATCH_INTERVAL_MS)
    scenario_id = getattr(args, "scenario_id", _DEFAULT_SCENARIO_ID)
    tenant_id = getattr(args, "tenant_id", _DEFAULT_TENANT_ID)
    execute = getattr(args, "execute", False)
    timeout = getattr(args, "timeout_seconds", 10)
    profile = getattr(args, "profile", "local")

    soak_run_id = make_soak_run_id()

    # Pre-flight
    batches_per_min = 60_000 / max(interval_ms, 1)
    total_uncapped = int(duration_min * batches_per_min) * epb
    preflight = [
        check_duration_bounds(duration_min),
        check_batch_bounds(epb, interval_ms),
        check_total_events_cap(total_uncapped),
        check_gateway_reachable(ingest_url, timeout, execute, _get_fn),
    ]

    plan = build_plan(duration_min, epb, interval_ms, ingest_url,
                      soak_run_id, scenario_id, tenant_id)

    empty_raw: dict[str, Any] = {
        "total_attempted": 0, "accepted": 0, "rate_limited": 0,
        "rejected": 0, "publish_failures": 0, "timeouts": 0,
        "latencies_ms": [], "circuit_breaker_opens": 0,
        "batch_results": [], "total_batches_run": 0,
    }

    pre_fails = [s for s in preflight if s["status"] == FAIL]

    if not execute:
        ended_at = datetime.now(timezone.utc).isoformat()
        return build_report(plan, preflight, empty_raw, [],
                            {}, {}, args, started_at, ended_at)

    if pre_fails:
        preflight.append(_pf(
            "ABORT", "Soak aborted — pre-flight failures", FAIL,
            f"Failed: {[s['step_id'] for s in pre_fails]}",
            "Fix all pre-flight failures before running with --execute.",
        ))
        ended_at = datetime.now(timezone.utc).isoformat()
        return build_report(plan, preflight, empty_raw, [],
                            {}, {}, args, started_at, ended_at)

    all_topics = _SOAK_TOPICS + _DLQ_TOPICS
    watermarks_before = collect_watermarks(admin_url, all_topics, timeout, _get_fn)

    # Use a persistent connection in execute mode to eliminate per-batch TCP
    # handshake overhead (significant on Docker Desktop / WSL2 on Windows).
    # Tests always inject _post_fn so this path is never taken in tests.
    _conn = None
    if _post_fn is None:
        _conn, _post_fn = _make_persistent_post_fn(ingest_url, timeout)

    try:
        raw = run_soak_loop(plan, secret, timeout, _post_fn, _sleep_fn)
    finally:
        if _conn is not None:
            try:
                _conn.close()
            except Exception:
                pass

    watermarks_after = collect_watermarks(admin_url, all_topics, timeout, _get_fn)

    bounds = evaluate_bounds(raw, profile)
    ended_at = datetime.now(timezone.utc).isoformat()
    return build_report(plan, preflight, raw, bounds,
                        watermarks_before, watermarks_after,
                        args, started_at, ended_at)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main(args: argparse.Namespace, root: Path = _PROJECT_ROOT,
         env: dict[str, str] | None = None,
         _get_fn=None, _post_fn=None, _sleep_fn=None) -> int:
    mode = "execute" if getattr(args, "execute", False) else "dry-run"

    if not getattr(args, "quiet", False):
        print(f"\n  XDR Live Soak Validator — ENTERPRISE-038")
        print(f"  mode       : {mode}")
        print(f"  duration   : {getattr(args, 'duration_minutes', _DEFAULT_DURATION_MINUTES)}m")
        print(f"  batch      : {getattr(args, 'events_per_batch', _DEFAULT_EVENTS_PER_BATCH)} events")
        print(f"  interval   : {getattr(args, 'batch_interval_ms', _DEFAULT_BATCH_INTERVAL_MS)}ms")
        print()

    report = run_validate(args, root=root, env=env,
                          _get_fn=_get_fn, _post_fn=_post_fn, _sleep_fn=_sleep_fn)
    overall = report["overall"]

    if not getattr(args, "quiet", False):
        w = 72
        print("=" * w)
        pre = report.get("preflight", [])
        bounds = report.get("bounds", [])
        for item in pre:
            m = {"PASS": "+", "FAIL": "!", "WARN": "~", "INFO": "."}.get(item["status"], "?")
            print(f"  [{m}] {item['step_id']:<10} {item['name']:<42} {item['status']}")
        if bounds:
            print("-" * w)
            for b in bounds:
                m = {"PASS": "+", "FAIL": "!", "WARN": "~"}.get(b["status"], "?")
                print(f"  [{m}] {b['bound_id']:<10} {b['name']:<32} "
                      f"{b['value']} {b['unit']:<8} {b['status']}")
        print("-" * w)
        marker = {"PASS": "+", "FAIL": "!"}.get(overall, "~")
        print(f"  [{marker}] {'OVERALL':<51} {overall}")
        print(f"  {report['status_line']}")
        print("=" * w)
        if mode == "dry-run":
            print()
            print(f"  DRY-RUN — no events sent.")
            plan = report.get("plan", {})
            print(f"  Planned: {plan.get('total_events_planned', 0)} events  "
                  f"({plan.get('total_batches', 0)} batches × "
                  f"{plan.get('events_per_batch', 0)})  "
                  f"{plan.get('throughput_eps', 0)} eps")
        print()

    if getattr(args, "output", ""):
        out = Path(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")
        if not getattr(args, "quiet", False):
            print(f"  Report: {out}")

    return 0 if overall == PASS else 1


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args(argv=None) -> argparse.Namespace:
    p = argparse.ArgumentParser(
        prog="xdr_live_soak_validate.py",
        description="ENTERPRISE-038: Live soak / load validation for the XDR pipeline.",
    )
    p.add_argument("--execute", action="store_true", default=False,
                   help="Actually run the soak (send events). Default: dry-run only.")
    p.add_argument("--duration-minutes", type=int, default=_DEFAULT_DURATION_MINUTES,
                   dest="duration_minutes",
                   help=f"Soak duration in minutes (default: {_DEFAULT_DURATION_MINUTES}, max: {MAX_DURATION_MINUTES})")
    p.add_argument("--events-per-batch", type=int, default=_DEFAULT_EVENTS_PER_BATCH,
                   dest="events_per_batch",
                   help=f"Events per batch (default: {_DEFAULT_EVENTS_PER_BATCH}, max: {MAX_EVENTS_PER_BATCH})")
    p.add_argument("--batch-interval-ms", type=int, default=_DEFAULT_BATCH_INTERVAL_MS,
                   dest="batch_interval_ms",
                   help=f"Interval between batches in ms (default: {_DEFAULT_BATCH_INTERVAL_MS}, min: {MIN_BATCH_INTERVAL_MS})")
    p.add_argument("--ingest-url", default=_DEFAULT_INGEST_URL, dest="ingest_url",
                   help=f"Ingestion gateway URL (default: {_DEFAULT_INGEST_URL})")
    p.add_argument("--admin-url", default=_DEFAULT_ADMIN_URL, dest="admin_url",
                   help=f"Redpanda admin URL (default: {_DEFAULT_ADMIN_URL})")
    p.add_argument("--scenario-id", default=_DEFAULT_SCENARIO_ID, dest="scenario_id")
    p.add_argument("--tenant-id", default=_DEFAULT_TENANT_ID, dest="tenant_id")
    p.add_argument("--profile", default="local", choices=("local", "staging", "production"))
    p.add_argument("--timeout-seconds", type=int, default=10, dest="timeout_seconds")
    p.add_argument("--output", default="", help="Write JSON report to this path.")
    p.add_argument("--quiet", action="store_true")
    return p.parse_args(argv)


if __name__ == "__main__":
    sys.exit(main(_parse_args()))
