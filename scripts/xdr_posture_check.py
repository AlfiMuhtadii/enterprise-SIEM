#!/usr/bin/env python3
"""XDR production runtime posture checker.

Validates the runtime environment configuration against the expected security
posture for local/demo, staging, and production-pilot profiles.

Profiles:
  --profile=local      (default) Issues WARN for unsafe posture, never FAIL.
  --profile=staging    FAIL for placeholder secrets / missing tokens; WARN for gaps.
  --profile=production FAIL for any critical security misconfiguration.

Exit codes:
  0  PASS   no FAIL-level issues found (WARNs and INFOs are non-blocking)
  1  FAIL   one or more FAIL-level issues detected
  2  ERROR  env file unreadable or script configuration error

Checks:
  C-01  APP_DEBUG=false
  C-02  APP_FORCE_HTTPS=true
  C-03  SESSION_SECURE_COOKIE=true
  C-04  XDR_TENANT_STRICT_MODE=true
  C-05  XDR_ENFORCE_INTERNAL_AUTH=true
  C-06  XDR_INGEST_SECRET not placeholder / not empty
  C-07  XDR_INTERNAL_AUTH_SECRET not placeholder / not empty
  C-08  Per-service internal tokens not placeholder / not empty
  C-09  XDR_SHADOW_CONSUMER_ENABLED=false (production advisory-only posture)
  C-10  DLQ consumer flags off (advisory-only by default)
  D-01  IMPLEMENTED — IG-1: async metrics poller, admissionAllowed reads cached atomic
  D-02  IMPLEMENTED — IG-2: per-tenant token-bucket map, isolated per X-Tenant-ID
  D-03  IMPLEMENTED — IG-3: bounded retry + circuit breaker, context.WithTimeout per attempt
  D-04  DEFERRED — INFRA-3: no container CPU/memory limits
  A-01  ACCEPTED RISK — DB-3: seeder users locked out in strict mode
  A-02  ACCEPTED RISK — DB-4: NULL tenant_id in demo alerts/incidents
  A-03  ACCEPTED RISK — INFRA-4: Grafana provisioning mounts writable
  A-04  ACCEPTED RISK — RAG-1: empty knowledge base on fresh deploy
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

PASS = "PASS"
WARN = "WARN"
FAIL = "FAIL"
INFO = "INFO"

PROFILES = ("local", "staging", "production")

# Placeholder values that indicate a secret was never changed from the default.
_PLACEHOLDERS = {
    "",
    "change-me",
    "CHANGE_ME",
    "dev-secret-change-me",
    "REPLACE_WITH_GENERATED_KEY",
    "REPLACE_WITH_STRONG_PASSWORD",
    "REPLACE_WITH_STRONG_WEBHOOK_SECRET",
    "REPLACE_WITH_STRONG_AGENT_ENROLLMENT_TOKEN",
    "REPLACE_WITH_STRONG_INGEST_SECRET",
    "REPLACE_WITH_STRONG_INTERNAL_AUTH_SECRET",
    "REPLACE_WITH_STRONG_NORMALIZER_TOKEN",
    "REPLACE_WITH_STRONG_ALERT_WRITER_TOKEN",
    "REPLACE_WITH_STRONG_INCIDENT_BUILDER_TOKEN",
    "REPLACE_WITH_STRONG_CORRELATION_TOKEN",
    "base64:REPLACE_WITH_GENERATED_KEY",
    "base64:CHANGE_ME",
}


def _is_placeholder(value: str) -> bool:
    return value.strip() in _PLACEHOLDERS or value.strip().startswith("REPLACE_WITH_")


def _parse_env_file(env_file: Path) -> dict[str, str]:
    """Parse a .env file into a dict. Ignores comments and blank lines."""
    env: dict[str, str] = {}
    for line in env_file.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        key, _, val = line.partition("=")
        key = key.strip()
        val = val.strip().strip('"').strip("'")
        env[key] = val
    return env


def _severity(profile: str, local_sev: str, staging_sev: str, prod_sev: str) -> str:
    return {"local": local_sev, "staging": staging_sev, "production": prod_sev}[profile]


def _check(
    check_id: str,
    name: str,
    passed: bool,
    severity: str,
    detail: str,
    remediation: str = "",
) -> dict[str, Any]:
    status = PASS if passed else severity
    return {
        "check_id": check_id,
        "name": name,
        "status": status,
        "detail": detail,
        "remediation": remediation if not passed else "",
    }


# ---------------------------------------------------------------------------
# Individual checks
# ---------------------------------------------------------------------------

def check_app_debug(env: dict, profile: str) -> dict:
    val = env.get("APP_DEBUG", "true").lower()
    passed = val in ("false", "0", "no")
    sev = _severity(profile, WARN, WARN, FAIL)
    return _check(
        "C-01", "APP_DEBUG disabled",
        passed, sev,
        f"APP_DEBUG={env.get('APP_DEBUG', '(not set)')}",
        "Set APP_DEBUG=false in production to prevent stack trace leakage.",
    )


def check_app_force_https(env: dict, profile: str) -> dict:
    val = env.get("APP_FORCE_HTTPS", "false").lower()
    passed = val in ("true", "1", "yes")
    sev = _severity(profile, WARN, WARN, FAIL)
    return _check(
        "C-02", "APP_FORCE_HTTPS enabled",
        passed, sev,
        f"APP_FORCE_HTTPS={env.get('APP_FORCE_HTTPS', '(not set)')}",
        "Set APP_FORCE_HTTPS=true to enforce HTTPS in all non-local environments.",
    )


def check_session_secure_cookie(env: dict, profile: str) -> dict:
    val = env.get("SESSION_SECURE_COOKIE", "false").lower()
    passed = val in ("true", "1", "yes")
    sev = _severity(profile, WARN, WARN, FAIL)
    return _check(
        "C-03", "SESSION_SECURE_COOKIE enabled",
        passed, sev,
        f"SESSION_SECURE_COOKIE={env.get('SESSION_SECURE_COOKIE', '(not set)')}",
        "Set SESSION_SECURE_COOKIE=true to prevent session hijacking over HTTP.",
    )


def check_tenant_strict_mode(env: dict, profile: str) -> dict:
    val = env.get("XDR_TENANT_STRICT_MODE", "false").lower()
    passed = val in ("true", "1", "yes")
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check(
        "C-04", "XDR_TENANT_STRICT_MODE enabled",
        passed, sev,
        f"XDR_TENANT_STRICT_MODE={env.get('XDR_TENANT_STRICT_MODE', '(not set)')}",
        "Set XDR_TENANT_STRICT_MODE=true before multi-tenant production go-live. "
        "See docs/security/TENANT_STRICT_MODE.md.",
    )


def check_internal_auth(env: dict, profile: str) -> dict:
    val = env.get("XDR_ENFORCE_INTERNAL_AUTH", "false").lower()
    passed = val in ("true", "1", "yes")
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check(
        "C-05", "XDR_ENFORCE_INTERNAL_AUTH enabled",
        passed, sev,
        f"XDR_ENFORCE_INTERNAL_AUTH={env.get('XDR_ENFORCE_INTERNAL_AUTH', '(not set)')}",
        "Set XDR_ENFORCE_INTERNAL_AUTH=true for any non-local deployment. "
        "Each service fails fast at startup if its token is missing.",
    )


def check_ingest_secret(env: dict, profile: str) -> dict:
    val = env.get("XDR_INGEST_SECRET", "")
    passed = bool(val) and not _is_placeholder(val)
    sev = _severity(profile, WARN, FAIL, FAIL)
    masked = "(set)" if (val and not _is_placeholder(val)) else f"'{val}'"
    return _check(
        "C-06", "XDR_INGEST_SECRET configured",
        passed, sev,
        f"XDR_INGEST_SECRET={masked}",
        "Generate with: openssl rand -hex 32",
    )


def check_internal_auth_secret(env: dict, profile: str) -> dict:
    val = env.get("XDR_INTERNAL_AUTH_SECRET", "")
    passed = bool(val) and not _is_placeholder(val)
    sev = _severity(profile, WARN, FAIL, FAIL)
    masked = "(set)" if (val and not _is_placeholder(val)) else f"'{val}'"
    return _check(
        "C-07", "XDR_INTERNAL_AUTH_SECRET configured",
        passed, sev,
        f"XDR_INTERNAL_AUTH_SECRET={masked}",
        "Generate with: openssl rand -hex 32",
    )


def check_service_tokens(env: dict, profile: str) -> dict:
    tokens = {
        "XDR_NORMALIZER_INTERNAL_TOKEN": env.get("XDR_NORMALIZER_INTERNAL_TOKEN", ""),
        "XDR_ALERT_WRITER_INTERNAL_TOKEN": env.get("XDR_ALERT_WRITER_INTERNAL_TOKEN", ""),
        "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": env.get("XDR_INCIDENT_BUILDER_INTERNAL_TOKEN", ""),
        "XDR_CORRELATION_INTERNAL_TOKEN": env.get("XDR_CORRELATION_INTERNAL_TOKEN", ""),
    }
    bad = [k for k, v in tokens.items() if not v or _is_placeholder(v)]
    passed = len(bad) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = "All per-service tokens configured" if passed else f"Unconfigured/placeholder: {', '.join(bad)}"
    return _check(
        "C-08", "Per-service internal tokens configured",
        passed, sev, detail,
        "Generate each independently: openssl rand -hex 32",
    )


def check_shadow_consumer(env: dict, profile: str) -> dict:
    val = env.get("XDR_SHADOW_CONSUMER_ENABLED", "false").lower()
    passed = val in ("false", "0", "no")
    sev = _severity(profile, INFO, WARN, FAIL)
    return _check(
        "C-09", "XDR_SHADOW_CONSUMER_ENABLED=false (advisory posture)",
        passed, sev,
        f"XDR_SHADOW_CONSUMER_ENABLED={env.get('XDR_SHADOW_CONSUMER_ENABLED', '(not set)')}",
        "Shadow consumer writes to advisory_findings only. Must remain false unless "
        "shadow domain soak is actively monitored.",
    )


def check_dlq_consumers(env: dict, profile: str) -> dict:
    flags = {
        "XDR_DLQ_CONSUMER_ENABLED": env.get("XDR_DLQ_CONSUMER_ENABLED", "false"),
        "XDR_CORRELATION_DLQ_CONSUMER_ENABLED": env.get("XDR_CORRELATION_DLQ_CONSUMER_ENABLED", "false"),
        "XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED": env.get("XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED", "false"),
    }
    enabled = [k for k, v in flags.items() if v.lower() in ("true", "1", "yes")]
    passed = len(enabled) == 0
    sev = _severity(profile, INFO, INFO, WARN)
    detail = "All DLQ consumers off" if passed else f"Enabled DLQ consumers: {', '.join(enabled)}"
    return _check(
        "C-10", "DLQ consumers default-off",
        passed, sev, detail,
        "DLQ consumers are advisory-only. Enable individually only when actively monitoring.",
    )


# ---------------------------------------------------------------------------
# Deferred risk visibility (always surfaced, non-blocking)
# ---------------------------------------------------------------------------

def deferred_ig1() -> dict:
    return {
        "check_id": "D-01",
        "name": "IMPLEMENTED: IG-1 async normalizer metrics polling (BACKLOG-INGESTION-025)",
        "status": INFO,
        "detail": (
            "admissionAllowed() reads a cached atomic (normalizerQueueDepth). "
            "startMetricsPoller() runs as a background goroutine polling at "
            "XDR_NORMALIZER_METRICS_POLL_INTERVAL_SECONDS (default 5s). "
            "No synchronous HTTP call inside the request handler."
        ),
        "remediation": "No action required. Implemented in commit 3027e08.",
    }


def deferred_ig2() -> dict:
    return {
        "check_id": "D-02",
        "name": "IMPLEMENTED: IG-2 per-tenant rate limiter (BACKLOG-INGESTION-025)",
        "status": INFO,
        "detail": (
            "ingestion-gateway now uses a per-tenant token-bucket map (sync.Map). "
            "Each tenant is identified by the X-Tenant-ID header; buckets are isolated "
            "so one high-volume tenant cannot starve others. "
            "XDR_INGEST_PER_TENANT_RPS configures per-tenant bucket size."
        ),
        "remediation": "No action required. Implemented in commit 3027e08.",
    }


def deferred_ig3() -> dict:
    return {
        "check_id": "D-03",
        "name": "IMPLEMENTED: IG-3 bounded retry + circuit breaker (BACKLOG-INGESTION-025)",
        "status": INFO,
        "detail": (
            "publish() now uses context.WithTimeout per attempt "
            "(XDR_PUBLISH_TIMEOUT_SECONDS, default 5s), exponential backoff "
            "(100ms/200ms/400ms, capped at 1s), and a circuit breaker "
            "(XDR_PUBLISH_CB_FAILURES=5, XDR_PUBLISH_CB_OPEN_SECONDS=30). "
            "Circuit open returns immediately without network I/O."
        ),
        "remediation": "No action required. Implemented in commit 3027e08.",
    }


def deferred_infra3() -> dict:
    return {
        "check_id": "D-04",
        "name": "DEFERRED: INFRA-3 no container CPU/memory resource limits",
        "status": WARN,
        "detail": (
            "docker-compose.yml sets no mem_limit or cpus for Redpanda, ClickHouse, "
            "OpenSearch, or Grafana. Under sustained load these can starve the host."
        ),
        "remediation": (
            "Add resource limits in production docker-compose override or Kubernetes manifests "
            "before any multi-tenant production pilot. See REVIEW_REJECTED.md §2."
        ),
    }


# ---------------------------------------------------------------------------
# Accepted risk visibility (always INFO)
# ---------------------------------------------------------------------------

def accepted_db3() -> dict:
    return {
        "check_id": "A-01",
        "name": "ACCEPTED RISK: DB-3 seeder users have no tenant memberships",
        "status": INFO,
        "detail": (
            "UserSeeder/DemoSocSeeder users have no user_tenant_memberships rows. "
            "These users are locked out when XDR_TENANT_STRICT_MODE=true."
        ),
        "remediation": (
            "Add tenant membership rows to seeders if enabling strict mode in production. "
            "See REVIEW_REJECTED.md §3."
        ),
    }


def accepted_db4() -> dict:
    return {
        "check_id": "A-02",
        "name": "ACCEPTED RISK: DB-4 demo alerts/incidents have NULL tenant_id",
        "status": INFO,
        "detail": (
            "DemoSocSeeder creates security_alerts and security_incidents with tenant_id=NULL. "
            "These are hidden from scoped queries in strict mode."
        ),
        "remediation": (
            "Scope demo data to a demo tenant before enabling strict mode in production. "
            "See REVIEW_REJECTED.md §3."
        ),
    }


def accepted_infra4() -> dict:
    return {
        "check_id": "A-03",
        "name": "ACCEPTED RISK: INFRA-4 Grafana provisioning mounts are writable",
        "status": INFO,
        "detail": (
            "Grafana provisioning volume mounts are not :ro. Intentionally writable "
            "for local dev dashboard authoring. Port is already localhost-only (INFRA-1)."
        ),
        "remediation": (
            "Use :ro on Grafana provisioning mounts in production deployment. "
            "See REVIEW_REJECTED.md §3."
        ),
    }


def accepted_rag1() -> dict:
    return {
        "check_id": "A-04",
        "name": "ACCEPTED RISK: RAG-1 empty knowledge base on fresh deploy",
        "status": INFO,
        "detail": (
            "Seeders do not populate soc_knowledge_base. RAG pipeline returns zero "
            "retrieval results on fresh deploy; AiGuardrails falls back to parametric outputs."
        ),
        "remediation": (
            "Add RAG seeding runbook to production operator onboarding checklist. "
            "See REVIEW_REJECTED.md §3."
        ),
    }


# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------

def run_checks(env: dict, profile: str) -> list[dict]:
    results: list[dict] = []
    results.append(check_app_debug(env, profile))
    results.append(check_app_force_https(env, profile))
    results.append(check_session_secure_cookie(env, profile))
    results.append(check_tenant_strict_mode(env, profile))
    results.append(check_internal_auth(env, profile))
    results.append(check_ingest_secret(env, profile))
    results.append(check_internal_auth_secret(env, profile))
    results.append(check_service_tokens(env, profile))
    results.append(check_shadow_consumer(env, profile))
    results.append(check_dlq_consumers(env, profile))
    # Deferred risks always surfaced
    results.append(deferred_ig1())
    results.append(deferred_ig2())
    results.append(deferred_ig3())
    results.append(deferred_infra3())
    # Accepted risks always surfaced
    results.append(accepted_db3())
    results.append(accepted_db4())
    results.append(accepted_infra4())
    results.append(accepted_rag1())
    return results


def summarize(checks: list[dict], profile: str) -> dict[str, Any]:
    counts = {PASS: 0, WARN: 0, FAIL: 0, INFO: 0}
    for c in checks:
        counts[c["status"]] = counts.get(c["status"], 0) + 1

    overall = FAIL if counts[FAIL] > 0 else (WARN if counts[WARN] > 0 else PASS)
    return {
        "profile": profile,
        "overall": overall,
        "pass_count": counts[PASS],
        "warn_count": counts[WARN],
        "fail_count": counts[FAIL],
        "info_count": counts[INFO],
        "checks": checks,
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }


def _print_summary(report: dict) -> None:
    profile = report["profile"]
    overall = report["overall"]
    print(f"\n=== XDR Posture Check — profile={profile} ===")
    print(f"Overall: {overall}  "
          f"(PASS={report['pass_count']} WARN={report['warn_count']} "
          f"FAIL={report['fail_count']} INFO={report['info_count']})\n")
    for c in report["checks"]:
        if c["status"] in (FAIL, WARN):
            print(f"  [{c['status']:4s}] {c['check_id']} {c['name']}")
            print(f"         {c['detail']}")
            if c.get("remediation"):
                print(f"         Remediation: {c['remediation']}")
    if report["fail_count"] == 0 and report["warn_count"] == 0:
        print("  All security checks PASS.")
    print()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="XDR production runtime posture checker")
    parser.add_argument(
        "--profile",
        choices=PROFILES,
        default="local",
        help="Runtime profile: local | staging | production (default: local)",
    )
    parser.add_argument(
        "--env-file",
        default=".env",
        help="Path to the .env file to validate (default: .env)",
    )
    parser.add_argument(
        "--output",
        default="",
        help="Path to write JSON report (default: stdout only)",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Suppress console output (useful when --output is set)",
    )
    args = parser.parse_args(argv)

    env_path = Path(args.env_file)
    if not env_path.exists():
        print(f"ERROR: env file not found: {env_path}", file=sys.stderr)
        return 2

    try:
        env = _parse_env_file(env_path)
    except Exception as exc:
        print(f"ERROR: cannot parse env file: {exc}", file=sys.stderr)
        return 2

    checks = run_checks(env, args.profile)
    report = summarize(checks, args.profile)

    if not args.quiet:
        _print_summary(report)

    if args.output:
        out_path = Path(args.output)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(report, indent=2), encoding="utf-8")

    return 0 if report["overall"] != FAIL else 1


if __name__ == "__main__":
    sys.exit(main())
