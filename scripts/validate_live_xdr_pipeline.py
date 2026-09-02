#!/usr/bin/env python3
"""
Read-only live XDR pipeline readiness validator.

Performs 16 checks across all pipeline services without ingesting data,
publishing to Redpanda, writing to PostgreSQL, or starting any service.

Check status semantics:
  PASS    — check passed
  FAIL    — check failed; required=True blocks LIVE_PIPELINE_READY, required=False is advisory
  WARN    — advisory security or observability note; never blocks readiness
  UNKNOWN — service unreachable or response unparseable; required=True blocks readiness

Checks 12–13 measure processing delta (max_offset − processed_since_restart).
This is NOT true Kafka consumer group committed-offset lag — see function docstring.

Usage:
    python scripts/validate_live_xdr_pipeline.py
    python scripts/validate_live_xdr_pipeline.py --env .env
    python scripts/validate_live_xdr_pipeline.py --timeout 5

Exit codes:
    0 — all required checks PASS
    1 — one or more required checks FAIL
    2 — no required FAIL but one or more required checks UNKNOWN
"""

from __future__ import annotations

import argparse
import json
import os
import ssl
import sys
import urllib.parse
import urllib.request
import urllib.error
from pathlib import Path
from typing import Any


TIMEOUT_S = 3

PASS = "PASS"
FAIL = "FAIL"
WARN = "WARN"
UNKNOWN = "UNKNOWN"


# ---------------------------------------------------------------------------
# .env reader — read key=value lines, ignore comments and blanks
# ---------------------------------------------------------------------------

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
            key = key.strip()
            val = val.strip().strip('"').strip("'")
            result[key] = val
    return result


# ---------------------------------------------------------------------------
# HTTP check — GET only, no side effects
# ---------------------------------------------------------------------------

def build_ingestion_mtls_context(
    args: argparse.Namespace,
    ingest_url: str,
) -> ssl.SSLContext | None:
    """Build an mTLS context scoped only to ingestion-gateway checks."""
    if not (
        getattr(args, "mtls_enabled", False)
        or getattr(args, "all_services_mtls_enabled", False)
    ):
        return None
    mode_flag = (
        "--all-services-mtls-enabled"
        if getattr(args, "all_services_mtls_enabled", False)
        else "--mtls-enabled"
    )
    if urllib.parse.urlsplit(ingest_url).scheme.lower() != "https":
        raise ValueError(f"{mode_flag} requires an https:// ingestion URL")

    material = {
        "--mtls-ca": getattr(args, "mtls_ca", None),
        "--mtls-client-cert": getattr(args, "mtls_client_cert", None),
        "--mtls-client-key": getattr(args, "mtls_client_key", None),
    }
    missing = [option for option, value in material.items() if not value]
    if missing:
        raise ValueError(
            f"{mode_flag} requires complete TLS material; missing "
            + ", ".join(missing)
        )

    context = ssl.create_default_context(cafile=material["--mtls-ca"])
    context.load_cert_chain(
        certfile=material["--mtls-client-cert"],
        keyfile=material["--mtls-client-key"],
    )
    return context


def validate_all_services_mtls(
    args: argparse.Namespace,
    service_urls: dict[str, str],
) -> None:
    """Validate service URLs before the shared internal identity is used."""
    if not getattr(args, "all_services_mtls_enabled", False):
        return
    insecure = [
        name
        for name, url in service_urls.items()
        if urllib.parse.urlsplit(url).scheme.lower() != "https"
    ]
    if insecure:
        raise ValueError(
            "--all-services-mtls-enabled requires https:// for "
            + ", ".join(insecure)
        )


def ingestion_base_url(url: str) -> str:
    """Accept either a gateway base URL or its canonical ingest endpoint."""
    parsed = urllib.parse.urlsplit(url)
    path = parsed.path.rstrip("/")
    if path.endswith("/v1/ingest"):
        path = path[:-len("/v1/ingest")]
    return urllib.parse.urlunsplit((
        parsed.scheme,
        parsed.netloc,
        path,
        parsed.query,
        parsed.fragment,
    )).rstrip("/")


def http_get(
    url: str,
    timeout: int,
    max_bytes: int = 512,
    ssl_context: ssl.SSLContext | None = None,
) -> tuple[int | None, str]:
    """Return (status_code, body) or (None, error_message).

    max_bytes controls how much of the response body is read.  Use a large
    value (e.g. 65536) for Prometheus /public_metrics which can be >40 KB.
    """
    try:
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=timeout, context=ssl_context) as resp:
            body = resp.read(max_bytes).decode("utf-8", errors="replace")
            return resp.status, body
    except urllib.error.HTTPError as exc:
        return exc.code, str(exc)
    except OSError as exc:
        return None, str(exc)


# ---------------------------------------------------------------------------
# Individual checks
# ---------------------------------------------------------------------------

CheckResult = dict[str, Any]


def check_service_health(
    component: str,
    base_url: str,
    timeout: int,
    required: bool,
    _http_get_fn=None,
) -> CheckResult:
    get = _http_get_fn or http_get
    url = base_url.rstrip("/") + "/health"
    code, body = get(url, timeout)
    if code is None:
        status = FAIL
        evidence = f"Connection refused / timeout: {body}"
    elif code == 200:
        status = PASS
        evidence = f"HTTP 200 — {body[:120].strip()}"
    else:
        status = FAIL
        evidence = f"HTTP {code} — {body[:120].strip()}"
    return {
        "component": component,
        "check": "/health endpoint",
        "status": status,
        "evidence": evidence,
        "required": required,
    }


def check_redpanda_rest(base_url: str, timeout: int) -> CheckResult:
    url = base_url.rstrip("/") + "/topics"
    code, body = http_get(url, timeout)
    if code is None:
        status = FAIL
        evidence = f"Connection refused / timeout: {body}"
    elif code == 200:
        status = PASS
        evidence = f"HTTP 200 — {body[:120].strip()}"
    else:
        status = FAIL
        evidence = f"HTTP {code} — {body[:120].strip()}"
    return {
        "component": "Redpanda REST API",
        "check": "GET /topics reachable",
        "status": status,
        "evidence": evidence,
        "required": True,
    }


def check_required_topics(base_url: str, required_topics: list[str], timeout: int) -> CheckResult:
    url = base_url.rstrip("/") + "/topics"
    code, body = http_get(url, timeout)
    if code is None:
        return {
            "component": "Redpanda topics",
            "check": f"{', '.join(required_topics)}",
            "status": UNKNOWN,
            "evidence": f"Cannot reach Redpanda REST: {body}",
            "required": True,
        }
    if code != 200:
        return {
            "component": "Redpanda topics",
            "check": f"{', '.join(required_topics)}",
            "status": UNKNOWN,
            "evidence": f"Redpanda REST returned HTTP {code}",
            "required": True,
        }
    try:
        topic_list: list[str] = json.loads(body)
    except (json.JSONDecodeError, ValueError):
        # Some Redpanda versions return a list of objects; try extracting names
        try:
            data = json.loads(body + body[len(body):])  # re-read if truncated
            topic_list = data if isinstance(data, list) else []
        except Exception:
            return {
                "component": "Redpanda topics",
                "check": f"{', '.join(required_topics)}",
                "status": UNKNOWN,
                "evidence": f"Could not parse topic list from response: {body[:120]}",
                "required": True,
            }

    # topic_list may be list[str] or list[dict]
    present: set[str] = set()
    for item in topic_list:
        if isinstance(item, str):
            present.add(item)
        elif isinstance(item, dict):
            if "name" in item:
                present.add(item["name"])
            elif "topic_name" in item:
                present.add(item["topic_name"])

    missing = [t for t in required_topics if t not in present]
    if not missing:
        status = PASS
        evidence = f"All required topics present ({len(present)} total)"
    else:
        status = FAIL
        evidence = f"Missing topics: {', '.join(missing)}"

    return {
        "component": "Redpanda topics",
        "check": f"{', '.join(required_topics)}",
        "status": status,
        "evidence": evidence,
        "required": True,
    }


def check_env_flag(
    component: str,
    key: str,
    expected_true_values: set[str],
    env_vars: dict[str, str],
    env_path: Path,
    required: bool,
) -> CheckResult:
    val = env_vars.get(key, os.environ.get(key, ""))
    if not val:
        status = FAIL
        evidence = f"{key} is not set (not found in {env_path} or environment)"
    elif val.lower() in expected_true_values:
        status = PASS
        evidence = f"{key}={val}"
    else:
        status = FAIL
        evidence = f"{key}={val} (expected one of: {', '.join(sorted(expected_true_values))})"
    return {
        "component": component,
        "check": f"{key} enabled",
        "status": status,
        "evidence": evidence,
        "required": required,
    }


def check_env_value(
    component: str,
    key: str,
    expected_vals: set[str],
    env_vars: dict[str, str],
    env_path: Path,
    required: bool,
) -> CheckResult:
    val = env_vars.get(key, os.environ.get(key, ""))
    if not val:
        status = FAIL
        evidence = f"{key} is not set (not found in {env_path} or environment)"
    elif val in expected_vals:
        status = PASS
        evidence = f"{key}={val}"
    else:
        status = FAIL
        evidence = f"{key}={val} (expected one of: {', '.join(sorted(expected_vals))})"
    return {
        "component": component,
        "check": f"{key} correct value",
        "status": status,
        "evidence": evidence,
        "required": required,
    }


# ---------------------------------------------------------------------------
# Advisory checks — processing movement and topic watermarks
# ---------------------------------------------------------------------------

def check_worker_processing_movement(
    component: str,
    metrics_url: str,
    input_topic: str,
    redpanda_admin_url: str,
    timeout: int,
    _worker_http_get_fn=None,
) -> CheckResult:
    """Advisory check: processing movement for a Go pipeline worker.

    IMPORTANT — this is NOT true Kafka consumer group committed-offset lag.

    Two sources are combined:
    - Worker /metrics  → ``processed`` count since last restart (in-memory, resets to 0
      on every container restart regardless of the Pandaproxy committed offset)
    - Redpanda Admin API public_metrics → topic ``max_offset`` (high watermark)

    ``delta = max_offset − processed`` is a coarse movement indicator, not a lag metric.
    After a restart, delta = max_offset (all historical records) even when the worker
    has committed its offset and is healthy. A large delta alone is not a problem.

    True Kafka consumer group lag = committed_offset − high_watermark, read from the
    broker. That requires a consumer group query (side effect); this validator is
    read-only and cannot perform one.

    What the check IS useful for:
    - ``recreate_count >= 10``: consumer cycling without advancing (survives restarts)
    - ``poll_error_count >= 10``: stuck in a Pandaproxy error loop
    - ``poison_skipped`` / ``dlq_written``: DLQ isolation activity visibility
    - ``delta > 500``: possible slow consumer (confirm with rpk consumer-group describe)

    FAIL threshold: delta > 500 OR recreate_count >= 10 OR poll_error_count >= 10.
    """
    check_name = f"processing movement: {input_topic} (not committed-offset lag)"

    # --- 1. Fetch worker metrics ---
    worker_get = _worker_http_get_fn or http_get
    wurl = metrics_url.rstrip("/") + "/metrics"
    code, body = worker_get(wurl, timeout)
    if code is None:
        return {
            "component": component,
            "check": check_name,
            "status": UNKNOWN,
            "evidence": f"Cannot reach {wurl}: {body[:80]}",
            "required": False,
        }
    if code != 200:
        return {
            "component": component,
            "check": check_name,
            "status": UNKNOWN,
            "evidence": f"HTTP {code} from {wurl}",
            "required": False,
        }
    try:
        data = json.loads(body)
    except (json.JSONDecodeError, ValueError):
        return {
            "component": component,
            "check": check_name,
            "status": UNKNOWN,
            "evidence": "Could not parse worker metrics JSON",
            "required": False,
        }
    processed   = int(data.get("processed", 0))
    recreate    = int(data.get("consumer_recreate_count", 0))
    poll_errs   = int(data.get("poll_error_count", 0))
    dlq_written = int(data.get("dlq_written", 0))
    poison_skip = int(data.get("poison_skipped", 0))

    # --- 2. Fetch topic max_offset from Redpanda public_metrics ---
    purl = redpanda_admin_url.rstrip("/") + "/public_metrics"
    _, prom_body = http_get(purl, timeout, max_bytes=131072)
    max_offset_f = _parse_prometheus_gauge(
        prom_body, "redpanda_kafka_max_offset",
        {"redpanda_namespace": "kafka", "redpanda_topic": input_topic},
    )

    # --- 3. Compute delta and status ---
    if max_offset_f is not None:
        max_offset = int(max_offset_f)
        lag = max_offset - processed
        evidence = (
            f"delta~{lag} (max_offset={max_offset}, processed_since_restart={processed}), "
            f"recreate_count={recreate}, poll_errors={poll_errs}, "
            f"poison_skipped={poison_skip}, dlq_written={dlq_written}"
        )
    else:
        lag = None
        evidence = (
            f"max_offset unavailable, processed_since_restart={processed}, "
            f"recreate_count={recreate}, poll_errors={poll_errs}, "
            f"poison_skipped={poison_skip}, dlq_written={dlq_written}"
        )

    if poll_errs >= 10:
        status = FAIL
        evidence += " — poll_errors>=10: consumer stuck (verify with rpk consumer-group describe)"
    elif recreate >= 10:
        status = FAIL
        evidence += " — recreate_count>=10: consumer cycling without advancing"
    elif lag is not None and lag > 500:
        status = FAIL
        evidence += f" — delta>{500}: worker behind (restart resets counter; verify committed offset via rpk)"
    else:
        status = PASS
    return {
        "component": component,
        "check": check_name,
        "status": status,
        "evidence": evidence,
        "required": False,
    }


def _parse_prometheus_gauge(
    text: str,
    metric_name: str,
    required_labels: dict[str, str],
) -> float | None:
    """Return the numeric value of a Prometheus gauge matching all required_labels.

    Parses the Prometheus text exposition format line-by-line.  Returns None if
    no matching series is found or the value cannot be parsed as float.
    """
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


def check_topic_watermarks(
    redpanda_admin_url: str,
    topics: list[str],
    timeout: int,
) -> CheckResult:
    """Advisory check: read topic high watermarks from Redpanda public_metrics.

    Reports the current max_offset (high watermark) for each required topic.
    A max_offset > 0 confirms events have flowed through the topic.
    A max_offset == 0 on a fresh install is normal; after a demo run all
    offsets should be > 0.
    """
    url = redpanda_admin_url.rstrip("/") + "/public_metrics"
    code, body = http_get(url, timeout, max_bytes=131072)
    if code is None:
        return {
            "component": "Redpanda",
            "check": "topic watermarks (max_offset per topic)",
            "status": UNKNOWN,
            "evidence": f"Cannot reach {url}: {body[:80]}",
            "required": False,
        }
    if code != 200:
        return {
            "component": "Redpanda",
            "check": "topic watermarks (max_offset per topic)",
            "status": UNKNOWN,
            "evidence": f"Redpanda public_metrics HTTP {code}",
            "required": False,
        }

    parts: list[str] = []
    all_positive = True
    for topic in topics:
        offset = _parse_prometheus_gauge(
            body, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": topic},
        )
        if offset is None:
            parts.append(f"{topic}=?")
            all_positive = False
        else:
            parts.append(f"{topic}={int(offset)}")
            if offset == 0:
                all_positive = False

    evidence = ", ".join(parts)
    status = PASS if all_positive else PASS  # informational only — always PASS
    if not all_positive:
        evidence += " (0=topic empty or not yet used)"
    return {
        "component": "Redpanda",
        "check": "topic watermarks (max_offset per topic)",
        "status": status,
        "evidence": evidence,
        "required": False,
    }


# ---------------------------------------------------------------------------
# Security posture checks (advisory — required=False)
# ---------------------------------------------------------------------------

def check_pandaproxy_exposure(pandaproxy_url: str, timeout: int) -> CheckResult:
    """Advisory security check: Pandaproxy must be internal-only in production.

    Pandaproxy provides unauthenticated Kafka REST access (read + write topics).
    In the local demo it is intentionally exposed on 127.0.0.1:8082.
    In any non-local deployment this port MUST be firewalled or removed.

    WARN if reachable without auth from this host (explicitly unsafe for non-local).
    PASS if not reachable (expected in a hardened or production-equivalent setup).
    """
    url = pandaproxy_url.rstrip("/") + "/topics"
    code, _ = http_get(url, timeout)
    if code is None:
        return {
            "component": "Pandaproxy",
            "check": "pandaproxy exposure (internal-only boundary)",
            "status": PASS,
            "evidence": f"Pandaproxy not reachable at {pandaproxy_url} (expected in hardened setup)",
            "required": False,
        }
    return {
        "component": "Pandaproxy",
        "check": "pandaproxy exposure (internal-only boundary)",
        "status": WARN,
        "evidence": (
            f"Pandaproxy reachable at {pandaproxy_url} without auth (HTTP {code})"
            " — local demo only; firewall port 8082 in non-local deployments"
        ),
        "required": False,
    }


def check_internal_auth_posture(
    normalizer_url: str,
    env_vars: dict,
    timeout: int,
    _http_get_fn=None,
) -> CheckResult:
    """Advisory security check: normalizer /v1/normalize internal auth posture.

    Checks whether the normalizer enforces token auth on its internal HTTP endpoint.
    Permissive mode (XDR_ENFORCE_INTERNAL_AUTH not set) allows unauthenticated
    POST to /v1/normalize — acceptable for local demo, unsafe for any other context.

    PASS if XDR_ENFORCE_INTERNAL_AUTH=true and XDR_NORMALIZER_INTERNAL_TOKEN is set.
    WARN otherwise (permissive posture — make explicit that hardening is needed).
    """
    check_name = "internal auth posture (XDR_ENFORCE_INTERNAL_AUTH)"

    TRUE_FLAGS = {"1", "true", "yes"}
    enforce_flag = env_vars.get("XDR_ENFORCE_INTERNAL_AUTH", "").strip().lower() in TRUE_FLAGS
    token_set    = bool(env_vars.get("XDR_NORMALIZER_INTERNAL_TOKEN", "").strip())
    secret_set   = bool(env_vars.get("XDR_INTERNAL_AUTH_SECRET", "").strip())

    # Read live internal_auth_mode from normalizer metrics.
    get = _http_get_fn or http_get
    murl = normalizer_url.rstrip("/") + "/metrics"
    code, body = get(murl, timeout)
    live_mode = "unknown"
    if code == 200:
        try:
            live_mode = json.loads(body).get("internal_auth_mode", "unknown")
        except (json.JSONDecodeError, ValueError):
            pass

    secret_note = "" if secret_set else "; XDR_INTERNAL_AUTH_SECRET not set (using APP_KEY fallback)"

    if enforce_flag and token_set:
        return {
            "component": "normalizer-worker",
            "check": check_name,
            "status": PASS,
            "evidence": (
                f"internal_auth_mode={live_mode}, "
                f"XDR_ENFORCE_INTERNAL_AUTH=true, token=configured{secret_note}"
            ),
            "required": False,
        }

    reasons: list[str] = []
    if not enforce_flag:
        reasons.append("XDR_ENFORCE_INTERNAL_AUTH not set")
    if not token_set:
        reasons.append("XDR_NORMALIZER_INTERNAL_TOKEN not set")
    if not secret_set:
        reasons.append("XDR_INTERNAL_AUTH_SECRET not set")

    return {
        "component": "normalizer-worker",
        "check": check_name,
        "status": WARN,
        "evidence": (
            f"internal_auth_mode={live_mode} — {', '.join(reasons)}"
            "; /v1/normalize unauthenticated (local demo only)"
        ),
        "required": False,
    }


def check_internal_auth_posture_service(
    component: str,
    service_url: str,
    token_env_var: str,
    env_vars: dict,
    timeout: int,
    _http_get_fn=None,
) -> CheckResult:
    """Advisory: internal auth posture for a given microservice.

    PASS if XDR_ENFORCE_INTERNAL_AUTH=true and the service token is configured.
    WARN otherwise — permissive posture acceptable for local demo only.
    """
    check_name = f"internal auth posture ({component})"
    TRUE_FLAGS = {"1", "true", "yes"}
    enforce_flag = env_vars.get("XDR_ENFORCE_INTERNAL_AUTH", "").strip().lower() in TRUE_FLAGS
    token_set    = bool(env_vars.get(token_env_var, "").strip())

    get = _http_get_fn or http_get
    murl = service_url.rstrip("/") + "/metrics"
    code, body = get(murl, timeout)
    live_mode = "unknown"
    if code == 200:
        try:
            live_mode = json.loads(body).get("internal_auth_mode", "unknown")
        except (json.JSONDecodeError, ValueError):
            pass

    if enforce_flag and token_set:
        return {
            "component": component,
            "check": check_name,
            "status": PASS,
            "evidence": f"internal_auth_mode={live_mode}, XDR_ENFORCE_INTERNAL_AUTH=true, token=configured",
            "required": False,
        }

    reasons: list[str] = []
    if not enforce_flag:
        reasons.append("XDR_ENFORCE_INTERNAL_AUTH not set")
    if not token_set:
        reasons.append(f"{token_env_var} not set")

    return {
        "component": component,
        "check": check_name,
        "status": WARN,
        "evidence": (
            f"internal_auth_mode={live_mode} — {', '.join(reasons)}"
            "; unauthenticated (local demo only)"
        ),
        "required": False,
    }


# ---------------------------------------------------------------------------
# Table rendering
# ---------------------------------------------------------------------------

COL_WIDTHS = (28, 44, 7, 62, 20)
HEADERS = ("Component", "Check", "Status", "Evidence", "Required For Demo")


def _pad(s: str, width: int) -> str:
    if len(s) > width:
        return s[: width - 3] + "..."
    return s.ljust(width)


def render_table(results: list[CheckResult]) -> str:
    sep = "+" + "+".join("-" * (w + 2) for w in COL_WIDTHS) + "+"
    def row(cols: tuple[str, ...]) -> str:
        cells = [" " + _pad(c, w) + " " for c, w in zip(cols, COL_WIDTHS)]
        return "|" + "|".join(cells) + "|"

    lines = [sep, row(HEADERS), sep]
    for r in results:
        req_label = "YES" if r["required"] else "no"
        lines.append(row((
            r["component"],
            r["check"],
            r["status"],
            r["evidence"],
            req_label,
        )))
    lines.append(sep)
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def _parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Read-only XDR pipeline readiness validator")
    parser.add_argument("--env", default=".env", help="Path to .env file (default: .env)")
    parser.add_argument("--timeout", type=int, default=TIMEOUT_S, help=f"HTTP timeout in seconds (default: {TIMEOUT_S})")
    parser.add_argument("--ingest-url", default=None, dest="ingest_url",
                        help="Override ingestion-gateway base or /v1/ingest URL")
    parser.add_argument("--mtls-enabled", action="store_true", dest="mtls_enabled",
                        help="Require mutual TLS for the ingestion-gateway health check")
    parser.add_argument("--mtls-ca", default=None, dest="mtls_ca",
                        help="PEM CA bundle used to verify ingestion-gateway")
    parser.add_argument("--mtls-client-cert", default=None, dest="mtls_client_cert",
                        help="PEM client certificate presented to ingestion-gateway")
    parser.add_argument("--mtls-client-key", default=None, dest="mtls_client_key",
                        help="PEM private key for --mtls-client-cert")
    parser.add_argument(
        "--all-services-mtls-enabled",
        action="store_true",
        dest="all_services_mtls_enabled",
        help="Use the ingestion mTLS identity for every first-party service check",
    )
    return parser.parse_args(argv)


def main(args: argparse.Namespace | None = None) -> int:
    args = args or _parse_args()

    env_path = Path(args.env)
    env_vars = load_dotenv(env_path)
    timeout = args.timeout

    # Resolve service URLs from env (with defaults matching service code)
    ingest_url    = args.ingest_url or env_vars.get("XDR_INGEST_ADDR", "http://127.0.0.1:8091")
    normalizer_url= env_vars.get("XDR_NORMALIZER_ADDR",      "http://127.0.0.1:8092")
    correlation_url= env_vars.get("XDR_CORRELATION_WORKER_URL", "http://127.0.0.1:8093")
    alert_writer_url= env_vars.get("XDR_ALERT_WRITER_URL",   "http://127.0.0.1:8095")
    incident_url  = env_vars.get("XDR_INCIDENT_BUILDER_URL", "http://127.0.0.1:8096")
    redpanda_url  = env_vars.get("XDR_REDPANDA_REST_URL",    "http://127.0.0.1:8082")

    # XDR_INGEST_ADDR in Go code defaults to ":8091" (no host prefix); normalise
    def normalise_addr(addr: str, default_host: str = "http://127.0.0.1") -> str:
        if addr.startswith(":"):
            return f"{default_host}{addr}"
        if not addr.startswith("http"):
            return f"http://{addr}"
        return addr

    ingest_url    = ingestion_base_url(normalise_addr(ingest_url))
    normalizer_url= normalise_addr(normalizer_url)
    correlation_url= normalise_addr(correlation_url)

    alert_topic   = env_vars.get("XDR_ALERTS_TOPIC", "xdr.alerts")
    corr_failed_topic  = env_vars.get("XDR_CORRELATION_FAILED_TOPIC", "xdr.correlation_failed")
    write_failed_topic = env_vars.get("XDR_ALERT_WRITE_FAILED_TOPIC", "xdr.alert_write_failed")
    required_topics = ["telemetry.raw", "telemetry.normalized", alert_topic,
                       corr_failed_topic, write_failed_topic]

    TRUE_FLAGS = {"1", "true", "yes"}

    OFFSET_RESET_VALS = {"earliest", "latest", "none"}

    try:
        ingestion_ssl_context = build_ingestion_mtls_context(args, ingest_url)
        validate_all_services_mtls(args, {
            "normalizer-worker": normalizer_url,
            "correlation-worker": correlation_url,
            "alert-writer-service": alert_writer_url,
            "incident-builder-service": incident_url,
        })
    except (OSError, ValueError) as exc:
        print(f"ERROR: Invalid service mTLS configuration: {exc}", file=sys.stderr)
        return 2

    service_ssl_context = (
        ingestion_ssl_context
        if getattr(args, "all_services_mtls_enabled", False)
        else None
    )

    def ingestion_get(url: str, request_timeout: int) -> tuple[int | None, str]:
        return http_get(url, request_timeout, ssl_context=ingestion_ssl_context)

    def service_get(
        url: str,
        request_timeout: int,
        max_bytes: int = 512,
    ) -> tuple[int | None, str]:
        return http_get(
            url,
            request_timeout,
            max_bytes=max_bytes,
            ssl_context=service_ssl_context,
        )

    results: list[CheckResult] = [
        # 1–5: service health
        check_service_health(
            "ingestion-gateway", ingest_url, timeout, required=True,
            _http_get_fn=ingestion_get,
        ),
        check_service_health("normalizer-worker", normalizer_url, timeout, required=True, _http_get_fn=service_get),
        check_service_health("correlation-worker", correlation_url, timeout, required=True, _http_get_fn=service_get),
        check_service_health("alert-writer-service", alert_writer_url, timeout, required=True, _http_get_fn=service_get),
        check_service_health("incident-builder", incident_url, timeout, required=True, _http_get_fn=service_get),
        # 6: Redpanda REST reachable
        check_redpanda_rest(redpanda_url, timeout),
        # 7: required topics exist
        check_required_topics(redpanda_url, required_topics, timeout),
        # 8: correlation event loop enabled
        check_env_flag(
            "correlation-worker",
            "XDR_CORRELATION_EVENT_LOOP_ENABLED",
            TRUE_FLAGS,
            env_vars, env_path,
            required=True,
        ),
        # 9: alert-writer event loop enabled
        check_env_flag(
            "alert-writer-service",
            "XDR_EVENT_LOOP_ENABLED",
            TRUE_FLAGS,
            env_vars, env_path,
            required=True,
        ),
        # 10: alert-writer offset reset policy (advisory — code defaults to earliest)
        check_env_value(
            "alert-writer-service",
            "XDR_ALERT_WRITER_AUTO_OFFSET_RESET",
            OFFSET_RESET_VALS,
            env_vars, env_path,
            required=False,
        ),
        # 11: incident-builder offset reset policy (advisory — code defaults to earliest)
        check_env_value(
            "incident-builder",
            "XDR_INCIDENT_BUILDER_AUTO_OFFSET_RESET",
            OFFSET_RESET_VALS,
            env_vars, env_path,
            required=False,
        ),
        # 12: processing movement — telemetry.raw → normalizer-worker (advisory; NOT committed-offset lag)
        check_worker_processing_movement(
            "normalizer-worker", normalizer_url,
            "telemetry.raw", "http://127.0.0.1:9644", timeout,
            _worker_http_get_fn=service_get,
        ),
        # 13: processing movement — telemetry.normalized → correlation-worker (advisory; NOT committed-offset lag)
        check_worker_processing_movement(
            "correlation-worker", correlation_url,
            "telemetry.normalized", "http://127.0.0.1:9644", timeout,
            _worker_http_get_fn=service_get,
        ),
        # 14: topic high watermarks — confirms events have flowed through each topic
        check_topic_watermarks(
            "http://127.0.0.1:9644",
            required_topics + ["alerts.created", "telemetry.normalization_failed"],
            timeout,
        ),
        # 15: Pandaproxy exposure — advisory security posture (internal-only boundary)
        check_pandaproxy_exposure(redpanda_url, timeout),
        # 16: internal auth posture — advisory security posture (XDR_ENFORCE_INTERNAL_AUTH)
        check_internal_auth_posture(normalizer_url, env_vars, timeout, _http_get_fn=service_get),
        # 17: alert-writer internal auth posture
        check_internal_auth_posture_service("alert-writer", alert_writer_url, "XDR_ALERT_WRITER_INTERNAL_TOKEN", env_vars, timeout, _http_get_fn=service_get),
        # 18: incident-builder internal auth posture
        check_internal_auth_posture_service("incident-builder", incident_url, "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN", env_vars, timeout, _http_get_fn=service_get),
        # 19: correlation-worker internal auth posture
        check_internal_auth_posture_service("correlation-worker", correlation_url, "XDR_CORRELATION_INTERNAL_TOKEN", env_vars, timeout, _http_get_fn=service_get),
        # 20: structured failure topic watermarks — advisory visibility (topics exist once bootstrap runs)
        check_topic_watermarks(
            "http://127.0.0.1:9644",
            [corr_failed_topic, write_failed_topic],
            timeout,
        ),
    ]

    print()
    print("XDR Live Pipeline Readiness Validator")
    print(f"  env file : {env_path.resolve()}")
    print(f"  timeout  : {timeout}s")
    print()
    print(render_table(results))
    print()

    # Required checks determine LIVE_PIPELINE_READY. Advisory (required=False) checks are
    # surfaced as warnings but do not block readiness.
    fail_count    = sum(1 for r in results if r["status"] == FAIL    and r.get("required", True))
    unknown_count = sum(1 for r in results if r["status"] == UNKNOWN and r.get("required", True))
    pass_count    = sum(1 for r in results if r["status"] == PASS)
    warn_count    = sum(1 for r in results if
                        r["status"] == WARN or
                        (r["status"] == FAIL and not r.get("required", True)))
    total_count   = len(results)

    if fail_count == 0 and unknown_count == 0:
        ready = "true"
        warn_suffix = f"  ({warn_count} advisory WARN)" if warn_count else ""
        summary_line = f"LIVE_PIPELINE_READY=true  (all {total_count} checks{warn_suffix})"
    elif fail_count > 0:
        ready = "false"
        summary_line = (
            f"LIVE_PIPELINE_READY=false  ({fail_count} FAIL, {unknown_count} UNKNOWN,"
            f" {pass_count} PASS, {warn_count} advisory WARN)"
        )
    else:
        ready = "unknown"
        summary_line = (
            f"LIVE_PIPELINE_READY=unknown  (0 FAIL, {unknown_count} UNKNOWN,"
            f" {pass_count} PASS, {warn_count} advisory WARN)"
        )

    print(summary_line)
    print()

    if fail_count > 0 or unknown_count > 0 or warn_count > 0:
        print("Remediation:")
        if fail_count > 0:
            print("  - Start the full pipeline: docker compose --profile strangler up -d")
        print("  - Ensure XDR_CORRELATION_EVENT_LOOP_ENABLED=true in .env (correlation-worker)")
        print("  - Ensure XDR_EVENT_LOOP_ENABLED=true in .env (alert-writer-service)")
        if warn_count > 0:
            print("  - Advisory: set XDR_ALERT_WRITER_AUTO_OFFSET_RESET=earliest in .env")
            print("  - Advisory: set XDR_INCIDENT_BUILDER_AUTO_OFFSET_RESET=earliest in .env")
            print("    (these default to 'earliest' in code; setting explicitly improves offset recovery)")
            print("  - Security (check 15): Pandaproxy port 8082 must be firewalled in non-local deployments")
            print("  - Security (check 16): set XDR_INTERNAL_AUTH_SECRET + XDR_NORMALIZER_INTERNAL_TOKEN")
            print("    and XDR_ENFORCE_INTERNAL_AUTH=true for any non-local deployment")
        print("  - See docs/guides/LIMITATIONS_AND_CLAIMS.md for pipeline architecture")
        print()

    if fail_count > 0:
        return 1
    if unknown_count > 0:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main(_parse_args()))
