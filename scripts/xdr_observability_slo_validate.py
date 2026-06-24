#!/usr/bin/env python3
"""XDR Runtime Observability & SLO Readiness Validation (BACKLOG-OBS-029).

Two-phase validation:

Phase 1 — Observability Readiness (always runs, offline):
  Checks that monitoring infrastructure is in place (scripts, dashboards, docs).

  OBS-01  Runtime observability SLO dashboard provisioned
  OBS-02  Posture check script available
  OBS-03  Recovery validate script available
  OBS-04  Ingestion hardening validate script available
  OBS-05  SLO documentation present
  OBS-06  Observability dashboards documented
  OBS-07  EASM posture monitoring available (advisory-only)
  OBS-08  DLQ monitoring configured

Phase 2 — SLO Evaluation (only when --input metrics file provided):
  Evaluates collected metrics against per-profile SLO thresholds.

  SLO-01  Ingestion p95 latency
  SLO-02  Ingestion publish errors
  SLO-03  Ingestion rejection rate (%)
  SLO-04  Ingestion circuit breaker opens
  SLO-05  Normalizer malformed rate (%)
  SLO-06  Normalizer publish errors
  SLO-07  Normalizer DLQ writes
  SLO-08  Correlation p95 latency
  SLO-09  Correlation publish errors
  SLO-10  Alert-writer p95 latency
  SLO-11  Alert-writer DLQ writes
  SLO-12  Alert-writer DLQ errors
  SLO-13  Incident-builder DLQ writes
  SLO-14  Incident-builder errors
  SLO-15  DLQ error rate (%)
  SLO-16  DLQ pending records
  SLO-17  EASM min posture score (advisory)

Profiles: local | staging | production
Exit codes: 0=PASS, 1=FAIL, 2=ERROR

Metrics input format (JSON, supplied via --input):
  {
    "ingestion_gateway":  { "accepted", "rejected", "rate_limited",
                            "publish_errors", "p95_latency_ms",
                            "p99_latency_ms", "circuit_breaker_opens" },
    "normalizer_worker":  { "processed", "forwarded", "malformed",
                            "publish_errors", "dlq_writes",
                            "consumer_errors", "reconnect_count",
                            "goroutines", "heap_alloc_mb" },
    "correlation_worker": { "processed", "alerts", "published",
                            "publish_errors", "p95_latency_ms",
                            "consumer_errors", "reconnect_count",
                            "goroutines", "heap_alloc_mb" },
    "alert_writer":       { "processed", "written", "dlq_writes",
                            "dlq_errors", "p95_latency_ms", "errors" },
    "incident_builder":   { "processed", "created", "dlq_writes",
                            "dlq_errors", "errors" },
    "dlq":                { "total_records", "pending_records",
                            "replayable_records", "failed_records",
                            "dlq_error_rate_pct" },
    "easm":               { "total_assets", "open_findings_high",
                            "open_findings_medium", "min_posture_score",
                            "avg_posture_score", "is_advisory" }
  }
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

PASS = "PASS"
WARN = "WARN"
FAIL = "FAIL"
INFO = "INFO"

PROFILES = ("local", "staging", "production")

_REPO_ROOT = Path(__file__).resolve().parent.parent

# Required file paths (relative to repo root)
_PATHS = {
    "OBS-01": Path("config/grafana/runtime-observability-slo.json"),
    "OBS-02": Path("scripts/xdr_posture_check.py"),
    "OBS-03": Path("scripts/xdr_recovery_validate.py"),
    "OBS-04": Path("scripts/xdr_ingestion_hardening_validate.py"),
    "OBS-05": Path("docs/operations/RUNTIME_OBSERVABILITY_SLO.md"),
    "OBS-06": Path("docs/operations/observability-dashboards.md"),
    "OBS-07": Path("scripts/xdr_easm_posture_history.py"),
    "OBS-08": Path("docs/operations/BACKUP_RESTORE_RECOVERY.md"),
}

# ---------------------------------------------------------------------------
# SLO threshold table
# Thresholds: if value > fail → FAIL; elif value > warn → WARN; else PASS.
# None = threshold not applicable for this profile.
# For warn=0: any value > 0 → WARN (first occurrence triggers WARN).
# ---------------------------------------------------------------------------

SLO_THRESHOLDS: dict[str, dict[str, dict[str, Any]]] = {
    "local": {
        "SLO-01": {"name": "Ingestion p95 latency (ms)",         "warn": 200,   "fail": None},
        "SLO-02": {"name": "Ingestion publish errors",            "warn": 0,     "fail": 20},
        "SLO-03": {"name": "Ingestion rejection rate (%)",        "warn": 5.0,   "fail": 20.0},
        "SLO-04": {"name": "Ingestion circuit breaker opens",     "warn": 0,     "fail": 5},
        "SLO-05": {"name": "Normalizer malformed rate (%)",       "warn": 2.0,   "fail": 10.0},
        "SLO-06": {"name": "Normalizer publish errors",           "warn": 0,     "fail": 20},
        "SLO-07": {"name": "Normalizer DLQ writes",               "warn": 0,     "fail": 50},
        "SLO-08": {"name": "Correlation p95 latency (ms)",        "warn": 300,   "fail": None},
        "SLO-09": {"name": "Correlation publish errors",          "warn": 0,     "fail": 20},
        "SLO-10": {"name": "Alert-writer p95 latency (ms)",       "warn": 500,   "fail": None},
        "SLO-11": {"name": "Alert-writer DLQ writes",             "warn": 0,     "fail": 20},
        "SLO-12": {"name": "Alert-writer DLQ errors",             "warn": 0,     "fail": 5},
        "SLO-13": {"name": "Incident-builder DLQ writes",         "warn": 0,     "fail": 20},
        "SLO-14": {"name": "Incident-builder errors",             "warn": 0,     "fail": 10},
        "SLO-15": {"name": "DLQ error rate (%)",                  "warn": 1.0,   "fail": None},
        "SLO-16": {"name": "DLQ pending records",                 "warn": 100,   "fail": None},
        "SLO-17": {"name": "EASM min posture score (advisory)",   "warn": 40,    "fail": None},
    },
    "staging": {
        "SLO-01": {"name": "Ingestion p95 latency (ms)",         "warn": 100,   "fail": 300},
        "SLO-02": {"name": "Ingestion publish errors",            "warn": 0,     "fail": 10},
        "SLO-03": {"name": "Ingestion rejection rate (%)",        "warn": 3.0,   "fail": 10.0},
        "SLO-04": {"name": "Ingestion circuit breaker opens",     "warn": 0,     "fail": 2},
        "SLO-05": {"name": "Normalizer malformed rate (%)",       "warn": 1.0,   "fail": 5.0},
        "SLO-06": {"name": "Normalizer publish errors",           "warn": 0,     "fail": 10},
        "SLO-07": {"name": "Normalizer DLQ writes",               "warn": 0,     "fail": 25},
        "SLO-08": {"name": "Correlation p95 latency (ms)",        "warn": 200,   "fail": 500},
        "SLO-09": {"name": "Correlation publish errors",          "warn": 0,     "fail": 10},
        "SLO-10": {"name": "Alert-writer p95 latency (ms)",       "warn": 300,   "fail": 800},
        "SLO-11": {"name": "Alert-writer DLQ writes",             "warn": 0,     "fail": 10},
        "SLO-12": {"name": "Alert-writer DLQ errors",             "warn": 0,     "fail": 2},
        "SLO-13": {"name": "Incident-builder DLQ writes",         "warn": 0,     "fail": 10},
        "SLO-14": {"name": "Incident-builder errors",             "warn": 0,     "fail": 5},
        "SLO-15": {"name": "DLQ error rate (%)",                  "warn": 0.5,   "fail": 2.0},
        "SLO-16": {"name": "DLQ pending records",                 "warn": 50,    "fail": 500},
        "SLO-17": {"name": "EASM min posture score (advisory)",   "warn": 60,    "fail": 40},
    },
    "production": {
        "SLO-01": {"name": "Ingestion p95 latency (ms)",         "warn": 50,    "fail": 100},
        "SLO-02": {"name": "Ingestion publish errors",            "warn": 0,     "fail": 1},
        "SLO-03": {"name": "Ingestion rejection rate (%)",        "warn": 1.0,   "fail": 5.0},
        "SLO-04": {"name": "Ingestion circuit breaker opens",     "warn": 0,     "fail": 0},
        "SLO-05": {"name": "Normalizer malformed rate (%)",       "warn": 0.5,   "fail": 2.0},
        "SLO-06": {"name": "Normalizer publish errors",           "warn": 0,     "fail": 5},
        "SLO-07": {"name": "Normalizer DLQ writes",               "warn": 0,     "fail": 10},
        "SLO-08": {"name": "Correlation p95 latency (ms)",        "warn": 100,   "fail": 300},
        "SLO-09": {"name": "Correlation publish errors",          "warn": 0,     "fail": 5},
        "SLO-10": {"name": "Alert-writer p95 latency (ms)",       "warn": 200,   "fail": 500},
        "SLO-11": {"name": "Alert-writer DLQ writes",             "warn": 0,     "fail": 5},
        "SLO-12": {"name": "Alert-writer DLQ errors",             "warn": 0,     "fail": 0},
        "SLO-13": {"name": "Incident-builder DLQ writes",         "warn": 0,     "fail": 5},
        "SLO-14": {"name": "Incident-builder errors",             "warn": 0,     "fail": 0},
        "SLO-15": {"name": "DLQ error rate (%)",                  "warn": 0.0,   "fail": 1.0},
        "SLO-16": {"name": "DLQ pending records",                 "warn": 10,    "fail": 100},
        "SLO-17": {"name": "EASM min posture score (advisory)",   "warn": 80,    "fail": 60},
    },
}

# ---------------------------------------------------------------------------
# Core helpers
# ---------------------------------------------------------------------------


def evaluate_threshold(
    value: float,
    warn_threshold: float | None,
    fail_threshold: float | None,
) -> str:
    """Return PASS/WARN/FAIL based on value vs thresholds.

    For EASM posture score (higher is better) the caller should invert:
      pass (value >= warn_threshold) by checking (100 - score) against thresholds.
    For all other metrics lower is better (errors, latency, etc.).
    """
    if fail_threshold is not None and value > fail_threshold:
        return FAIL
    if warn_threshold is not None and value > warn_threshold:
        return WARN
    return PASS


def evaluate_threshold_lower_is_better(
    value: float,
    warn_threshold: float | None,
    fail_threshold: float | None,
) -> str:
    """Alias for evaluate_threshold; documents intent for lower-is-better metrics."""
    return evaluate_threshold(value, warn_threshold, fail_threshold)


def evaluate_threshold_higher_is_better(
    value: float,
    warn_threshold: float | None,
    fail_threshold: float | None,
) -> str:
    """Higher values are better (e.g. posture score).

    warn_threshold / fail_threshold are the MINIMUM acceptable values.
    FAIL if value < fail_threshold; WARN if value < warn_threshold.
    """
    if fail_threshold is not None and value < fail_threshold:
        return FAIL
    if warn_threshold is not None and value < warn_threshold:
        return WARN
    return PASS


def compute_rate(numerator: float, denominator: float) -> float:
    """Return numerator/denominator*100 as a percentage, or 0.0 if denominator is 0."""
    if denominator <= 0:
        return 0.0
    return round(numerator / denominator * 100.0, 2)


def _make_check(
    check_id: str,
    name: str,
    status: str,
    detail: str,
    remediation: str = "",
) -> dict[str, Any]:
    return {
        "check_id": check_id,
        "name": name,
        "status": status,
        "detail": detail,
        "remediation": remediation if status not in (PASS, INFO) else "",
    }


# ---------------------------------------------------------------------------
# Phase 1 — Observability readiness (offline)
# ---------------------------------------------------------------------------


def check_obs_readiness(repo_root: Path | None = None) -> list[dict[str, Any]]:
    """Check that observability infrastructure files are present."""
    root = repo_root or _REPO_ROOT
    results: list[dict[str, Any]] = []

    meta = {
        "OBS-01": ("Runtime observability SLO Grafana dashboard provisioned",
                   "Add config/grafana/runtime-observability-slo.json"),
        "OBS-02": ("Posture check script available",
                   "Ensure scripts/xdr_posture_check.py is present."),
        "OBS-03": ("Recovery validate script available",
                   "Ensure scripts/xdr_recovery_validate.py is present."),
        "OBS-04": ("Ingestion hardening validate script available",
                   "Ensure scripts/xdr_ingestion_hardening_validate.py is present."),
        "OBS-05": ("SLO documentation present",
                   "Create docs/operations/RUNTIME_OBSERVABILITY_SLO.md"),
        "OBS-06": ("Observability dashboards documented",
                   "Create docs/operations/observability-dashboards.md"),
        "OBS-07": ("EASM posture history script available (advisory)",
                   "Ensure scripts/xdr_easm_posture_history.py is present."),
        "OBS-08": ("DLQ and backup/recovery monitoring documented",
                   "Create docs/operations/BACKUP_RESTORE_RECOVERY.md"),
    }

    for check_id, rel_path in _PATHS.items():
        name, remediation = meta[check_id]
        full_path = root / rel_path
        present = full_path.exists()
        status = PASS if present else WARN
        detail = f"Present: {rel_path}" if present else f"Missing: {rel_path}"
        results.append(_make_check(check_id, name, status, detail, remediation))

    return results


# ---------------------------------------------------------------------------
# Phase 2 — SLO evaluation (from injected/loaded metrics)
# ---------------------------------------------------------------------------


def _slo(check_id: str, value: float, metrics: dict, profile: str,
         detail_prefix: str = "", higher_is_better: bool = False) -> dict[str, Any]:
    """Evaluate a single SLO check against the threshold table."""
    thr = SLO_THRESHOLDS[profile][check_id]
    name = thr["name"]
    if higher_is_better:
        status = evaluate_threshold_higher_is_better(value, thr["warn"], thr["fail"])
    else:
        status = evaluate_threshold_lower_is_better(value, thr["warn"], thr["fail"])
    detail = f"{detail_prefix}value={value} warn>{thr['warn']} fail>{thr['fail']}"
    remediation = ""
    if status == FAIL:
        remediation = f"Value {value} exceeds FAIL threshold {thr['fail']} for profile={profile}."
    elif status == WARN:
        remediation = f"Value {value} exceeds WARN threshold {thr['warn']} for profile={profile}."
    return _make_check(check_id, name, status, detail, remediation)


def check_ingestion_metrics(ig: dict, profile: str) -> list[dict[str, Any]]:
    accepted = ig.get("accepted", 0)
    rejected = ig.get("rejected", 0)
    total = accepted + rejected
    rejection_rate = compute_rate(rejected, total)

    return [
        _slo("SLO-01", ig.get("p95_latency_ms", 0), ig, profile, "ingestion_gateway "),
        _slo("SLO-02", ig.get("publish_errors", 0), ig, profile, "ingestion_gateway "),
        _slo("SLO-03", rejection_rate, ig, profile,
             f"ingestion_gateway rejection_rate={rejection_rate}% "),
        _slo("SLO-04", ig.get("circuit_breaker_opens", 0), ig, profile, "ingestion_gateway "),
    ]


def check_normalizer_metrics(nw: dict, profile: str) -> list[dict[str, Any]]:
    processed = nw.get("processed", 0)
    malformed = nw.get("malformed", 0)
    malformed_rate = compute_rate(malformed, processed)

    return [
        _slo("SLO-05", malformed_rate, nw, profile,
             f"normalizer malformed_rate={malformed_rate}% "),
        _slo("SLO-06", nw.get("publish_errors", 0), nw, profile, "normalizer "),
        _slo("SLO-07", nw.get("dlq_writes", 0), nw, profile, "normalizer "),
    ]


def check_correlation_metrics(cw: dict, profile: str) -> list[dict[str, Any]]:
    return [
        _slo("SLO-08", cw.get("p95_latency_ms", 0), cw, profile, "correlation "),
        _slo("SLO-09", cw.get("publish_errors", 0), cw, profile, "correlation "),
    ]


def check_alert_writer_metrics(aw: dict, profile: str) -> list[dict[str, Any]]:
    return [
        _slo("SLO-10", aw.get("p95_latency_ms", 0), aw, profile, "alert_writer "),
        _slo("SLO-11", aw.get("dlq_writes", 0), aw, profile, "alert_writer "),
        _slo("SLO-12", aw.get("dlq_errors", 0), aw, profile, "alert_writer "),
    ]


def check_incident_builder_metrics(ib: dict, profile: str) -> list[dict[str, Any]]:
    return [
        _slo("SLO-13", ib.get("dlq_writes", 0), ib, profile, "incident_builder "),
        _slo("SLO-14", ib.get("errors", 0), ib, profile, "incident_builder "),
    ]


def check_dlq_metrics(dlq: dict, profile: str) -> list[dict[str, Any]]:
    return [
        _slo("SLO-15", dlq.get("dlq_error_rate_pct", 0.0), dlq, profile, "dlq "),
        _slo("SLO-16", dlq.get("pending_records", 0), dlq, profile, "dlq "),
    ]


def check_easm_metrics(easm: dict, profile: str) -> list[dict[str, Any]]:
    """EASM posture score SLO — advisory-only, never creates incidents."""
    advisory_note = "(advisory-only — posture score, no autonomous response)"
    score = easm.get("min_posture_score", 100)
    thr = SLO_THRESHOLDS[profile]["SLO-17"]
    status = evaluate_threshold_higher_is_better(score, thr["warn"], thr["fail"])
    detail = (f"easm min_posture_score={score} warn<{thr['warn']} fail<{thr['fail']} "
              f"{advisory_note}")
    remediation = ""
    if status in (WARN, FAIL):
        remediation = (f"EASM posture score {score} below {'FAIL' if status == FAIL else 'WARN'} "
                       f"threshold {thr['fail'] if status == FAIL else thr['warn']}. "
                       "Review open findings. Advisory-only — no automated remediation.")
    return [_make_check("SLO-17", thr["name"], status, detail, remediation)]


def _missing_service(check_ids: list[str], service_name: str, profile: str) -> list[dict]:
    """Return WARN checks for a service whose metrics are not present in the input."""
    results = []
    for check_id in check_ids:
        thr = SLO_THRESHOLDS[profile][check_id]
        results.append(_make_check(
            check_id, thr["name"], WARN,
            f"{service_name} metrics not available in input",
            f"Collect {service_name} metrics and supply via --input to evaluate this SLO.",
        ))
    return results


def run_slo_checks(metrics: dict, profile: str) -> list[dict[str, Any]]:
    """Evaluate all SLO checks against the provided metrics snapshot."""
    results: list[dict[str, Any]] = []

    ig = metrics.get("ingestion_gateway")
    if ig:
        results.extend(check_ingestion_metrics(ig, profile))
    else:
        results.extend(_missing_service(
            ["SLO-01", "SLO-02", "SLO-03", "SLO-04"], "ingestion_gateway", profile))

    nw = metrics.get("normalizer_worker")
    if nw:
        results.extend(check_normalizer_metrics(nw, profile))
    else:
        results.extend(_missing_service(
            ["SLO-05", "SLO-06", "SLO-07"], "normalizer_worker", profile))

    cw = metrics.get("correlation_worker")
    if cw:
        results.extend(check_correlation_metrics(cw, profile))
    else:
        results.extend(_missing_service(
            ["SLO-08", "SLO-09"], "correlation_worker", profile))

    aw = metrics.get("alert_writer")
    if aw:
        results.extend(check_alert_writer_metrics(aw, profile))
    else:
        results.extend(_missing_service(
            ["SLO-10", "SLO-11", "SLO-12"], "alert_writer", profile))

    ib = metrics.get("incident_builder")
    if ib:
        results.extend(check_incident_builder_metrics(ib, profile))
    else:
        results.extend(_missing_service(
            ["SLO-13", "SLO-14"], "incident_builder", profile))

    dlq = metrics.get("dlq")
    if dlq:
        results.extend(check_dlq_metrics(dlq, profile))
    else:
        results.extend(_missing_service(
            ["SLO-15", "SLO-16"], "dlq", profile))

    easm = metrics.get("easm")
    if easm:
        results.extend(check_easm_metrics(easm, profile))
    else:
        results.extend(_missing_service(["SLO-17"], "easm", profile))

    return results


# ---------------------------------------------------------------------------
# Summary & report
# ---------------------------------------------------------------------------


def summarize(
    obs_checks: list[dict],
    slo_checks: list[dict],
    profile: str,
    has_metrics: bool,
) -> dict[str, Any]:
    all_checks = obs_checks + slo_checks
    counts = {PASS: 0, WARN: 0, FAIL: 0, INFO: 0}
    for c in all_checks:
        counts[c["status"]] = counts.get(c["status"], 0) + 1

    overall = FAIL if counts[FAIL] > 0 else (WARN if counts[WARN] > 0 else PASS)

    return {
        "profile": profile,
        "overall": overall,
        "has_metrics_input": has_metrics,
        "pass_count": counts[PASS],
        "warn_count": counts[WARN],
        "fail_count": counts[FAIL],
        "info_count": counts[INFO],
        "obs_checks": obs_checks,
        "slo_checks": slo_checks,
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }


def _print_report(report: dict) -> None:
    profile = report["profile"]
    overall = report["overall"]
    print(f"\n=== XDR Observability & SLO Readiness — profile={profile} ===")
    print(f"Overall: {overall}  "
          f"(PASS={report['pass_count']} WARN={report['warn_count']} "
          f"FAIL={report['fail_count']} INFO={report['info_count']})")
    if not report["has_metrics_input"]:
        print("  [NOTE] No --input metrics file provided. SLO checks show WARN for missing data.")
    print()

    all_checks = report["obs_checks"] + report["slo_checks"]
    for c in all_checks:
        if c["status"] in (FAIL, WARN):
            print(f"  [{c['status']:4s}] {c['check_id']} {c['name']}")
            print(f"         {c['detail']}")
            if c.get("remediation"):
                print(f"         Remediation: {c['remediation']}")
    if overall == PASS:
        print("  All observability and SLO checks PASS.")
    print()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def _parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="XDR runtime observability & SLO readiness validation (OBS-029)"
    )
    p.add_argument("--profile", choices=PROFILES, default="local",
                   help="Runtime profile: local | staging | production (default: local)")
    p.add_argument("--input", default="",
                   help="Path to metrics snapshot JSON (collected separately)")
    p.add_argument("--output", default="",
                   help="Path to write JSON report")
    p.add_argument("--repo-root", default="",
                   help="Override repository root path (used in tests)")
    p.add_argument("--quiet", action="store_true",
                   help="Suppress console output")
    return p.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv)
    profile = args.profile

    repo_root = Path(args.repo_root) if args.repo_root else None

    # Phase 1: observability readiness
    obs_checks = check_obs_readiness(repo_root)

    # Phase 2: SLO evaluation (only if --input provided)
    metrics: dict = {}
    has_metrics = False

    if args.input:
        input_path = Path(args.input)
        if not input_path.exists():
            print(f"ERROR: --input file not found: {input_path}", file=sys.stderr)
            return 2
        try:
            metrics = json.loads(input_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            print(f"ERROR: cannot parse --input file: {exc}", file=sys.stderr)
            return 2
        has_metrics = True

    slo_checks = run_slo_checks(metrics, profile)

    report = summarize(obs_checks, slo_checks, profile, has_metrics)

    if not args.quiet:
        _print_report(report)

    if args.output:
        out = Path(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")

    return 0 if report["overall"] != FAIL else 1


if __name__ == "__main__":
    sys.exit(main())
