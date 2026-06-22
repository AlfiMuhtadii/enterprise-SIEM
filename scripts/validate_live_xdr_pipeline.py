#!/usr/bin/env python3
"""
Read-only live XDR pipeline readiness validator.

Performs 9 checks across all pipeline services without ingesting data,
publishing to Redpanda, writing to PostgreSQL, or starting any service.

Usage:
    python scripts/validate_live_xdr_pipeline.py
    python scripts/validate_live_xdr_pipeline.py --env .env
    python scripts/validate_live_xdr_pipeline.py --timeout 5

Exit codes:
    0 — all checks PASS
    1 — one or more checks FAIL
    2 — no FAIL but one or more checks UNKNOWN
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.request
import urllib.error
from pathlib import Path
from typing import Any


TIMEOUT_S = 3

PASS = "PASS"
FAIL = "FAIL"
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

def http_get(url: str, timeout: int) -> tuple[int | None, str]:
    """Return (status_code, body_preview) or (None, error_message)."""
    try:
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read(512).decode("utf-8", errors="replace")
            return resp.status, body
    except urllib.error.HTTPError as exc:
        return exc.code, str(exc)
    except OSError as exc:
        return None, str(exc)


# ---------------------------------------------------------------------------
# Individual checks
# ---------------------------------------------------------------------------

CheckResult = dict[str, Any]


def check_service_health(component: str, base_url: str, timeout: int, required: bool) -> CheckResult:
    url = base_url.rstrip("/") + "/health"
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

def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only XDR pipeline readiness validator")
    parser.add_argument("--env", default=".env", help="Path to .env file (default: .env)")
    parser.add_argument("--timeout", type=int, default=TIMEOUT_S, help=f"HTTP timeout in seconds (default: {TIMEOUT_S})")
    args = parser.parse_args()

    env_path = Path(args.env)
    env_vars = load_dotenv(env_path)
    timeout = args.timeout

    # Resolve service URLs from env (with defaults matching service code)
    ingest_url    = env_vars.get("XDR_INGEST_ADDR",          "http://127.0.0.1:8091")
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

    ingest_url    = normalise_addr(ingest_url)
    normalizer_url= normalise_addr(normalizer_url)
    correlation_url= normalise_addr(correlation_url)

    alert_topic   = env_vars.get("XDR_ALERTS_TOPIC", "xdr.alerts")
    required_topics = ["telemetry.raw", "telemetry.normalized", alert_topic]

    TRUE_FLAGS = {"1", "true", "yes"}

    OFFSET_RESET_VALS = {"earliest", "latest", "none"}

    results: list[CheckResult] = [
        # 1–5: service health
        check_service_health("ingestion-gateway",    ingest_url,      timeout, required=True),
        check_service_health("normalizer-worker",    normalizer_url,  timeout, required=True),
        check_service_health("correlation-worker",   correlation_url, timeout, required=True),
        check_service_health("alert-writer-service", alert_writer_url,timeout, required=True),
        check_service_health("incident-builder",     incident_url,    timeout, required=True),
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
    warn_count    = sum(1 for r in results if r["status"] == FAIL    and not r.get("required", True))
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
        print("  - See docs/guides/LIMITATIONS_AND_CLAIMS.md for pipeline architecture")
        print()

    if fail_count > 0:
        return 1
    if unknown_count > 0:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
