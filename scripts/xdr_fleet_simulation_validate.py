#!/usr/bin/env python3
"""Endpoint fleet simulation validator.

Validates that the fleet hardening logic (spool stats, tamper visibility, policy
drift detection) behaves correctly under 7 deterministic failure scenarios.

Pure-Python, no DB, no network, no live agents.  Imports agent.py helper
functions directly and exercises them against synthetic in-memory fixtures.

Scenarios
---------
1. healthy_fleet_baseline    — all agents healthy, no indicators raised
2. stale_agent_detection     — agent silent for >60 min raises heartbeat_gap
3. policy_drift_visibility   — config hash mismatch raises config_mismatch
4. spool_capped_agent        — spool at 10 MiB cap, spool_capped=True
5. telemetry_lag_agent       — large spool queue, dropped_events > 0
6. tamper_advisory_only      — all tamper indicators advisory=True, autonomous_action=False
7. mixed_degraded_fleet      — healthy + stale + drifted + capped in one run

Safety invariants checked across all scenarios:
- No isolateHost / quarantineHost / executeShell / killProcess APIs exist
- All tamper/advisory indicators carry advisory=True, autonomous_action=False
- Spool cap constant is 10 MiB (10 * 1024 * 1024)

Exit codes: 0=PASS, 1=FAIL
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import time
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Any, Dict, List, Optional
from types import SimpleNamespace
import unittest
import unittest.mock


# ---------------------------------------------------------------------------
# Bootstrap: add agent.py to path
# ---------------------------------------------------------------------------

SCRIPT_DIR = Path(__file__).resolve().parent
AGENT_DIR  = SCRIPT_DIR.parent / "services" / "endpoint-agent"
sys.path.insert(0, str(AGENT_DIR))

try:
    import agent as _agent
except ImportError as e:
    print(f"FATAL: cannot import agent.py from {AGENT_DIR}: {e}", file=sys.stderr)
    sys.exit(2)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def ago_iso(seconds: int) -> str:
    return (datetime.now(timezone.utc) - timedelta(seconds=seconds)).isoformat()


def make_streaming_state(
    queue_depth:   int = 0,
    dropped_count: int = 0,
    spool_bytes:   int = 0,
) -> "_agent.StreamingState":
    """Build a minimal StreamingState-like object using a SimpleNamespace."""
    state = SimpleNamespace()
    state.queue        = list(range(queue_depth))  # len() == queue_depth
    state.dropped_count = dropped_count
    state.spool_path   = _mock_spool_path(spool_bytes)
    return state  # type: ignore[return-value]


def _mock_spool_path(spool_bytes: int) -> Path:
    """Return a mock Path whose .exists() and .stat() return fake values."""
    p = unittest.mock.MagicMock(spec=Path)
    p.exists.return_value = spool_bytes > 0
    stat = SimpleNamespace(st_size=spool_bytes, st_mtime=time.time() - 30)
    p.stat.return_value = stat
    return p


def make_quality(retry_count: int = 0, events_per_sec: float = 0.0) -> "_agent.QualityMetrics":
    q = unittest.mock.MagicMock(spec=_agent.QualityMetrics)
    q.retry_count = retry_count
    q.events_per_sec.return_value = events_per_sec
    return q  # type: ignore[return-value]


def get_spool_stats(
    queue_depth:   int = 0,
    dropped_count: int = 0,
    spool_bytes:   int = 0,
    retry_count:   int = 0,
) -> Dict[str, Any]:
    state   = make_streaming_state(queue_depth, dropped_count, spool_bytes)
    quality = make_quality(retry_count=retry_count)
    # Patch shutil.disk_usage so the disk_pressure branch doesn't run real FS check
    with unittest.mock.patch("shutil.disk_usage", return_value=SimpleNamespace(free=500 * 1024 * 1024)):
        return _agent.get_spool_stats(state, quality)


def check_tamper(
    cfg: dict,
    last_hb_ts:   Optional[str] = None,
    last_cfg_hash: Optional[str] = None,
    enabled_collectors: Optional[List[str]] = None,
) -> List[Dict[str, Any]]:
    return _agent.check_tamper_visibility(
        cfg,
        last_heartbeat_timestamp=last_hb_ts,
        last_config_hash=last_cfg_hash,
        enabled_collectors=enabled_collectors,
    )


def cfg_hash(cfg: dict) -> str:
    return hashlib.sha256(json.dumps(cfg, sort_keys=True).encode()).hexdigest()


# ---------------------------------------------------------------------------
# Scenario implementations
# ---------------------------------------------------------------------------

Result = Dict[str, Any]


def scenario_healthy_fleet_baseline() -> Result:
    """Healthy agent: no spool issues, no heartbeat gap, no tamper indicators."""
    cfg = {"heartbeat_interval_seconds": 60, "telemetry": {"processes": True}}
    last_hb = ago_iso(30)  # 30 seconds ago — well within 10x interval
    known_hash = cfg_hash(cfg)

    stats = get_spool_stats(queue_depth=5, dropped_count=0, spool_bytes=0)
    indicators = check_tamper(cfg, last_hb_ts=last_hb, last_cfg_hash=known_hash)

    errors = []
    if stats["spool_capped"]:
        errors.append("healthy agent should not have spool_capped=True")
    if stats["dropped_events"] != 0:
        errors.append(f"healthy agent should have dropped_events=0, got {stats['dropped_events']}")
    if indicators:
        errors.append(f"healthy agent raised unexpected indicators: {[i['type'] for i in indicators]}")

    return {
        "scenario":  "healthy_fleet_baseline",
        "stats":     stats,
        "indicators": indicators,
        "errors":    errors,
        "passed":    len(errors) == 0,
    }


def scenario_stale_agent_detection() -> Result:
    """Agent silent for 2 hours — should raise heartbeat_gap advisory."""
    cfg = {"heartbeat_interval_seconds": 60}
    last_hb = ago_iso(7200)  # 2 hours ago; threshold = 600 s

    indicators = check_tamper(cfg, last_hb_ts=last_hb)

    errors = []
    hb_gap_found = any(i["type"] == "heartbeat_gap" for i in indicators)
    if not hb_gap_found:
        errors.append("stale agent should raise heartbeat_gap indicator")
    for ind in indicators:
        if not ind.get("advisory"):
            errors.append(f"indicator {ind['type']} missing advisory=True")
        if ind.get("autonomous_action") is not False:
            errors.append(f"indicator {ind['type']} has autonomous_action != False")

    return {
        "scenario":  "stale_agent_detection",
        "indicators": indicators,
        "errors":    errors,
        "passed":    len(errors) == 0,
    }


def scenario_policy_drift_visibility() -> Result:
    """Agent reports a different config hash — should raise config_mismatch advisory."""
    original_cfg  = {"heartbeat_interval_seconds": 60, "telemetry": {"processes": True}}
    tampered_cfg  = {"heartbeat_interval_seconds": 60, "telemetry": {"processes": False, "extra_key": "injected"}}
    known_hash    = cfg_hash(original_cfg)

    indicators = check_tamper(tampered_cfg, last_cfg_hash=known_hash)

    errors = []
    mismatch_found = any(i["type"] == "config_mismatch" for i in indicators)
    if not mismatch_found:
        errors.append("drifted config should raise config_mismatch indicator")
    for ind in indicators:
        if not ind.get("advisory"):
            errors.append(f"indicator {ind['type']} missing advisory=True")
        if ind.get("autonomous_action") is not False:
            errors.append(f"indicator {ind['type']} has autonomous_action != False")

    # Sanity: same config → no mismatch
    no_drift = check_tamper(original_cfg, last_cfg_hash=known_hash)
    if any(i["type"] == "config_mismatch" for i in no_drift):
        errors.append("unchanged config incorrectly raised config_mismatch")

    return {
        "scenario":   "policy_drift_visibility",
        "indicators": indicators,
        "errors":     errors,
        "passed":     len(errors) == 0,
    }


def scenario_spool_capped_agent() -> Result:
    """Spool at exactly 10 MiB cap — spool_capped must be True."""
    spool_cap = _agent.STREAM_SPOOL_MAX_BYTES  # 10 * 1024 * 1024

    stats_at_cap    = get_spool_stats(spool_bytes=spool_cap)
    stats_below_cap = get_spool_stats(spool_bytes=spool_cap - 1)
    stats_above_cap = get_spool_stats(spool_bytes=spool_cap + 1024)

    errors = []
    if not stats_at_cap["spool_capped"]:
        errors.append(f"spool at {spool_cap} bytes should be capped")
    if stats_below_cap["spool_capped"]:
        errors.append(f"spool at {spool_cap - 1} bytes should NOT be capped")
    if not stats_above_cap["spool_capped"]:
        errors.append(f"spool at {spool_cap + 1024} bytes should be capped")
    if stats_at_cap["spool_disk_bytes"] != spool_cap:
        errors.append(f"spool_disk_bytes mismatch: got {stats_at_cap['spool_disk_bytes']}, want {spool_cap}")
    if spool_cap != 10 * 1024 * 1024:
        errors.append(f"STREAM_SPOOL_MAX_BYTES must be exactly 10 MiB, got {spool_cap}")

    return {
        "scenario":      "spool_capped_agent",
        "spool_cap":     spool_cap,
        "stats_at_cap":  stats_at_cap,
        "errors":        errors,
        "passed":        len(errors) == 0,
    }


def scenario_telemetry_lag_agent() -> Result:
    """Agent with large queue and dropped events — lag indicators reported correctly."""
    stats = get_spool_stats(queue_depth=1500, dropped_count=42, retry_count=3)

    errors = []
    if stats["queued_events"] != 1500:
        errors.append(f"queued_events: expected 1500, got {stats['queued_events']}")
    if stats["dropped_events"] != 42:
        errors.append(f"dropped_events: expected 42, got {stats['dropped_events']}")
    if stats["retry_count"] != 3:
        errors.append(f"retry_count: expected 3, got {stats['retry_count']}")

    return {
        "scenario": "telemetry_lag_agent",
        "stats":    stats,
        "errors":   errors,
        "passed":   len(errors) == 0,
    }


def scenario_tamper_advisory_only() -> Result:
    """Every tamper indicator type must carry advisory=True and autonomous_action=False."""
    cfg = {
        "heartbeat_interval_seconds": 60,
        "telemetry": {"processes": False},  # will trigger disabled_collector
    }
    original_cfg  = {"heartbeat_interval_seconds": 60, "telemetry": {"processes": True}}
    known_hash    = cfg_hash(original_cfg)
    stale_hb      = ago_iso(7200)

    indicators = check_tamper(
        cfg,
        last_hb_ts=stale_hb,
        last_cfg_hash=known_hash,
        enabled_collectors=["processes"],
    )

    errors = []
    if len(indicators) < 3:
        errors.append(f"expected at least 3 indicator types, got {len(indicators)}: {[i['type'] for i in indicators]}")
    for ind in indicators:
        if not ind.get("advisory"):
            errors.append(f"indicator '{ind['type']}' missing advisory=True")
        if ind.get("autonomous_action") is not False:
            errors.append(f"indicator '{ind['type']}' has autonomous_action != False")

    indicator_types = {i["type"] for i in indicators}
    for expected in ("heartbeat_gap", "config_mismatch", "disabled_collector"):
        if expected not in indicator_types:
            errors.append(f"expected indicator type '{expected}' not raised")

    return {
        "scenario":       "tamper_advisory_only",
        "indicator_count": len(indicators),
        "indicator_types": list(indicator_types),
        "errors":         errors,
        "passed":         len(errors) == 0,
    }


def scenario_mixed_degraded_fleet() -> Result:
    """Simulate a fleet with healthy, stale, drifted, and capped-spool agents."""
    cfg_base      = {"heartbeat_interval_seconds": 60, "telemetry": {"processes": True}}
    cfg_drifted   = {"heartbeat_interval_seconds": 60, "telemetry": {"processes": False}}
    base_hash     = cfg_hash(cfg_base)

    # Healthy agent
    h_stats      = get_spool_stats(queue_depth=5, dropped_count=0)
    h_indicators = check_tamper(cfg_base, last_hb_ts=ago_iso(10), last_cfg_hash=base_hash)

    # Stale agent (no heartbeat for 2h)
    s_indicators = check_tamper(cfg_base, last_hb_ts=ago_iso(7200))

    # Policy-drifted agent
    d_indicators = check_tamper(cfg_drifted, last_cfg_hash=base_hash)

    # Spool-capped agent
    cap_stats = get_spool_stats(
        spool_bytes=_agent.STREAM_SPOOL_MAX_BYTES,
        dropped_count=15,
    )

    errors = []

    # Healthy: no indicators, no spool issues
    if h_indicators:
        errors.append(f"healthy agent raised indicators: {[i['type'] for i in h_indicators]}")
    if h_stats["spool_capped"]:
        errors.append("healthy agent spool_capped should be False")

    # Stale: must raise heartbeat_gap
    if not any(i["type"] == "heartbeat_gap" for i in s_indicators):
        errors.append("stale agent must raise heartbeat_gap")

    # Drifted: must raise config_mismatch
    if not any(i["type"] == "config_mismatch" for i in d_indicators):
        errors.append("drifted agent must raise config_mismatch")

    # Capped: spool_capped=True, dropped_events=15
    if not cap_stats["spool_capped"]:
        errors.append("capped agent spool_capped should be True")
    if cap_stats["dropped_events"] != 15:
        errors.append(f"capped agent dropped_events: expected 15, got {cap_stats['dropped_events']}")

    # Advisory posture across all degraded indicators
    for ind in s_indicators + d_indicators:
        if not ind.get("advisory"):
            errors.append(f"indicator '{ind['type']}' missing advisory=True in mixed fleet")
        if ind.get("autonomous_action") is not False:
            errors.append(f"indicator '{ind['type']}' has autonomous_action != False in mixed fleet")

    return {
        "scenario":           "mixed_degraded_fleet",
        "healthy_indicators": len(h_indicators),
        "stale_indicators":   len(s_indicators),
        "drifted_indicators": len(d_indicators),
        "cap_stats":          cap_stats,
        "errors":             errors,
        "passed":             len(errors) == 0,
    }


# ---------------------------------------------------------------------------
# Safety invariants
# ---------------------------------------------------------------------------

def check_safety_invariants() -> Result:
    """Verify that no autonomous enforcement APIs exist in agent module."""
    forbidden = [
        "isolateHost",
        "quarantineHost",
        "executeShell",
        "killProcess",
        "autoRemediate",
        "blockNetwork",
        "enforcePolicy",
    ]
    found = [name for name in forbidden if hasattr(_agent, name)]

    # Also scan module source for the forbidden names as a belt-and-suspenders check
    source_path = AGENT_DIR / "agent.py"
    source_hits = []
    if source_path.exists():
        source = source_path.read_text(encoding="utf-8")
        for name in forbidden:
            # Only flag actual function/method definitions, not strings in comments/docs
            if f"def {name}" in source:
                source_hits.append(name)

    errors = []
    if found:
        errors.append(f"forbidden APIs found as module attributes: {found}")
    if source_hits:
        errors.append(f"forbidden APIs defined in agent.py source: {source_hits}")

    return {
        "scenario":    "safety_invariants",
        "checked_apis": forbidden,
        "found":       found,
        "source_hits": source_hits,
        "errors":      errors,
        "passed":      len(errors) == 0,
    }


# ---------------------------------------------------------------------------
# Scenario registry
# ---------------------------------------------------------------------------

SCENARIOS = [
    ("healthy_fleet_baseline",   scenario_healthy_fleet_baseline),
    ("stale_agent_detection",    scenario_stale_agent_detection),
    ("policy_drift_visibility",  scenario_policy_drift_visibility),
    ("spool_capped_agent",       scenario_spool_capped_agent),
    ("telemetry_lag_agent",      scenario_telemetry_lag_agent),
    ("tamper_advisory_only",     scenario_tamper_advisory_only),
    ("mixed_degraded_fleet",     scenario_mixed_degraded_fleet),
    ("safety_invariants",        check_safety_invariants),
]


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="XDR fleet simulation validator")
    p.add_argument(
        "--output",
        default="reports/xdr_fleet_simulation_validation.json",
        help="Output path for the JSON report",
    )
    p.add_argument(
        "--scenario",
        default="all",
        choices=["all"] + [s[0] for s in SCENARIOS],
        help="Run a specific scenario or all (default: all)",
    )
    return p.parse_args()


def ensure_dir(path: str) -> None:
    Path(path).parent.mkdir(parents=True, exist_ok=True)


def write_report(path: str, report: Dict[str, Any]) -> None:
    ensure_dir(path)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)
    print(f"Report written: {path}")


def main() -> int:
    args = parse_args()
    started_at = now_iso()

    to_run = SCENARIOS if args.scenario == "all" else [
        (name, fn) for name, fn in SCENARIOS if name == args.scenario
    ]

    results = []
    for name, fn in to_run:
        print(f"  Validating: {name} ...", end=" ", flush=True)
        try:
            result = fn()
        except Exception as exc:
            result = {
                "scenario": name,
                "errors":   [f"Exception: {exc}"],
                "passed":   False,
            }
        status = "PASS" if result.get("passed") else "FAIL"
        if not result.get("passed"):
            for err in result.get("errors", []):
                print(f"\n    ERROR: {err}", end="")
        print(f" {status}")
        results.append(result)

    passed = sum(1 for r in results if r.get("passed"))
    failed = len(results) - passed

    report = {
        "tool":          "xdr_fleet_simulation_validate",
        "version":       "1.0.0",
        "started_at":    started_at,
        "completed_at":  now_iso(),
        "scenarios_run": len(results),
        "passed":        passed,
        "failed":        failed,
        "status":        "PASS" if failed == 0 else "FAIL",
        "results":       results,
    }

    write_report(args.output, report)

    print(f"\nFleet simulation validation: {passed}/{len(results)} passed")
    if failed:
        print(f"  FAIL: {failed} scenario(s) failed — see {args.output}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
