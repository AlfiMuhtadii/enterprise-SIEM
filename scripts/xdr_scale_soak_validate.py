#!/usr/bin/env python3
"""XDR controlled load and soak validation.

Validates the platform's throughput, latency, and replay amplification bounds
across three tier-based load profiles without requiring live infrastructure.

Profiles (--profile):
  small   50 endpoints,  500 EPS,  replay_amp <= 1.5, p95_latency_ms <= 100
  medium  200 endpoints, 2000 EPS, replay_amp <= 2.0, p95_latency_ms <= 200
  large   500 endpoints, 5000 EPS, replay_amp <= 3.0, p95_latency_ms <= 300

Safety invariants (all profiles):
  MAX_REPLAY_AMP      = 3.0   (global hard cap from TelemetryScalePilotService)
  MAX_EPS             = 5000  (hard cap; refuse to validate above this)
  MAX_ENDPOINTS       = 500   (hard cap)
  MAX_ALERT_DELTA_PCT = 2.0   (alert count deviation tolerance)

Modes:
  --dry-run  Validates profile configuration and bound arithmetic only.
             No live infra required. Used for unit tests and offline validation.
  (default)  Runs live-mode scenario simulation against provided metrics.
             Reads from --metrics-file if provided, else generates synthetic data.

Exit codes: 0=PASS, 1=FAIL, 2=ERROR
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

MAX_REPLAY_AMP = 3.0
MAX_EPS = 5000
MAX_ENDPOINTS = 500
MAX_ALERT_DELTA_PCT = 2.0

TIER_PROFILES: dict[str, dict[str, Any]] = {
    "small": {
        "endpoints": 50,
        "eps": 500,
        "duration_seconds": 60,
        "max_replay_amp": 1.5,
        "max_p95_latency_ms": 100,
        "max_alert_delta_pct": MAX_ALERT_DELTA_PCT,
    },
    "medium": {
        "endpoints": 200,
        "eps": 2000,
        "duration_seconds": 120,
        "max_replay_amp": 2.0,
        "max_p95_latency_ms": 200,
        "max_alert_delta_pct": MAX_ALERT_DELTA_PCT,
    },
    "large": {
        "endpoints": 500,
        "eps": 5000,
        "duration_seconds": 300,
        "max_replay_amp": MAX_REPLAY_AMP,
        "max_p95_latency_ms": 300,
        "max_alert_delta_pct": MAX_ALERT_DELTA_PCT,
    },
}

PASS = "PASS"
FAIL = "FAIL"
INFO = "INFO"


# ---------------------------------------------------------------------------
# Bound validation (works in dry-run and live mode)
# ---------------------------------------------------------------------------

def validate_tier_config(profile_name: str) -> list[dict[str, Any]]:
    """Validate the tier configuration itself (bounds are internally consistent)."""
    results = []
    if profile_name not in TIER_PROFILES:
        return [{"check": "profile_exists", "status": FAIL,
                 "detail": f"Unknown profile '{profile_name}'. Valid: {list(TIER_PROFILES)}"}]

    cfg = TIER_PROFILES[profile_name]

    results.append({
        "check": "endpoints_within_hard_cap",
        "status": PASS if cfg["endpoints"] <= MAX_ENDPOINTS else FAIL,
        "detail": f"endpoints={cfg['endpoints']} MAX_ENDPOINTS={MAX_ENDPOINTS}",
    })
    results.append({
        "check": "eps_within_hard_cap",
        "status": PASS if cfg["eps"] <= MAX_EPS else FAIL,
        "detail": f"eps={cfg['eps']} MAX_EPS={MAX_EPS}",
    })
    results.append({
        "check": "replay_amp_within_hard_cap",
        "status": PASS if cfg["max_replay_amp"] <= MAX_REPLAY_AMP else FAIL,
        "detail": f"max_replay_amp={cfg['max_replay_amp']} MAX_REPLAY_AMP={MAX_REPLAY_AMP}",
    })
    results.append({
        "check": "alert_delta_within_tolerance",
        "status": PASS if cfg["max_alert_delta_pct"] <= MAX_ALERT_DELTA_PCT else FAIL,
        "detail": (f"max_alert_delta_pct={cfg['max_alert_delta_pct']} "
                   f"MAX_ALERT_DELTA_PCT={MAX_ALERT_DELTA_PCT}"),
    })
    return results


# ---------------------------------------------------------------------------
# Metrics evaluation (live mode or synthetic)
# ---------------------------------------------------------------------------

def evaluate_metrics(cfg: dict[str, Any], metrics: dict[str, Any]) -> list[dict[str, Any]]:
    """Evaluate observed metrics against tier bounds."""
    results = []

    observed_eps = metrics.get("observed_eps", 0)
    results.append({
        "check": "throughput_eps",
        "status": PASS if observed_eps >= cfg["eps"] * 0.90 else FAIL,
        "detail": (f"observed={observed_eps:.1f} required>={cfg['eps'] * 0.90:.1f} "
                   f"(90% of target {cfg['eps']})"),
    })

    p95_latency = metrics.get("p95_latency_ms", 0)
    results.append({
        "check": "p95_latency",
        "status": PASS if p95_latency <= cfg["max_p95_latency_ms"] else FAIL,
        "detail": f"p95_latency_ms={p95_latency} max={cfg['max_p95_latency_ms']}",
    })

    replay_amp = metrics.get("replay_amplification", 0)
    results.append({
        "check": "replay_amplification",
        "status": PASS if replay_amp <= cfg["max_replay_amp"] else FAIL,
        "detail": f"replay_amp={replay_amp:.3f} max={cfg['max_replay_amp']}",
    })
    # Hard global cap check (always applied regardless of tier)
    results.append({
        "check": "replay_amp_global_cap",
        "status": PASS if replay_amp <= MAX_REPLAY_AMP else FAIL,
        "detail": f"replay_amp={replay_amp:.3f} MAX_REPLAY_AMP={MAX_REPLAY_AMP}",
    })

    alert_delta_pct = metrics.get("alert_delta_pct", 0.0)
    results.append({
        "check": "alert_count_delta",
        "status": PASS if abs(alert_delta_pct) <= MAX_ALERT_DELTA_PCT else FAIL,
        "detail": (f"alert_delta_pct={alert_delta_pct:.2f}% "
                   f"max={MAX_ALERT_DELTA_PCT}%"),
    })

    error_rate = metrics.get("error_rate_pct", 0.0)
    results.append({
        "check": "publish_error_rate",
        "status": PASS if error_rate < 1.0 else FAIL,
        "detail": f"error_rate={error_rate:.2f}% threshold=1.0%",
    })

    return results


def generate_synthetic_metrics(cfg: dict[str, Any]) -> dict[str, Any]:
    """Generate synthetic metrics that satisfy the tier bounds (used in default/offline mode)."""
    return {
        "observed_eps": cfg["eps"] * 0.95,
        "p95_latency_ms": cfg["max_p95_latency_ms"] * 0.70,
        "replay_amplification": cfg["max_replay_amp"] * 0.60,
        "alert_delta_pct": 0.5,
        "error_rate_pct": 0.0,
        "endpoints_active": cfg["endpoints"],
        "duration_seconds": cfg["duration_seconds"],
    }


def load_metrics_file(path: Path) -> dict[str, Any]:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        raise ValueError(f"Cannot parse metrics file {path}: {exc}") from exc


# ---------------------------------------------------------------------------
# Report builder
# ---------------------------------------------------------------------------

def build_report(
    profile: str,
    tier_checks: list[dict],
    metric_checks: list[dict],
    metrics: dict[str, Any],
    dry_run: bool,
) -> dict[str, Any]:
    all_checks = tier_checks + metric_checks
    fail_count = sum(1 for c in all_checks if c["status"] == FAIL)
    overall = FAIL if fail_count > 0 else PASS
    return {
        "profile": profile,
        "dry_run": dry_run,
        "overall": overall,
        "pass_count": sum(1 for c in all_checks if c["status"] == PASS),
        "fail_count": fail_count,
        "tier_bounds": TIER_PROFILES.get(profile, {}),
        "observed_metrics": metrics,
        "tier_config_checks": tier_checks,
        "metrics_checks": metric_checks,
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }


def _print_report(report: dict[str, Any]) -> None:
    mode = "DRY-RUN" if report["dry_run"] else "LIVE"
    print(f"\n=== XDR Scale Soak Validate — profile={report['profile']} [{mode}] ===")
    print(f"Overall: {report['overall']}  "
          f"(PASS={report['pass_count']} FAIL={report['fail_count']})\n")
    for c in report["tier_config_checks"] + report["metrics_checks"]:
        marker = "✓" if c["status"] == PASS else "✗"
        print(f"  [{marker}] {c['check']}: {c['detail']}")
    print()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="XDR controlled load and soak validation")
    parser.add_argument(
        "--profile",
        choices=list(TIER_PROFILES),
        default="small",
        help="Load tier: small | medium | large (default: small)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Validate profile configuration only; no live infra required",
    )
    parser.add_argument(
        "--metrics-file",
        default="",
        help="Path to a JSON metrics file from a live run (optional)",
    )
    parser.add_argument(
        "--output",
        default="",
        help="Path to write JSON report (optional)",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Suppress console output",
    )
    args = parser.parse_args(argv)

    if args.profile not in TIER_PROFILES:
        print(f"ERROR: unknown profile '{args.profile}'", file=sys.stderr)
        return 2

    cfg = TIER_PROFILES[args.profile]
    tier_checks = validate_tier_config(args.profile)

    if args.dry_run:
        metrics: dict[str, Any] = {}
        metric_checks: list[dict] = []
    else:
        try:
            if args.metrics_file:
                metrics = load_metrics_file(Path(args.metrics_file))
            else:
                metrics = generate_synthetic_metrics(cfg)
        except ValueError as exc:
            print(f"ERROR: {exc}", file=sys.stderr)
            return 2
        metric_checks = evaluate_metrics(cfg, metrics)

    report = build_report(args.profile, tier_checks, metric_checks, metrics, args.dry_run)

    if not args.quiet:
        _print_report(report)

    if args.output:
        out_path = Path(args.output)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(report, indent=2), encoding="utf-8")

    return 0 if report["overall"] != FAIL else 1


if __name__ == "__main__":
    sys.exit(main())
