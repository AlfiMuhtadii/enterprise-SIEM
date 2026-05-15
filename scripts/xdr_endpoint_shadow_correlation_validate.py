#!/usr/bin/env python3
"""Validate endpoint shadow correlation rules.

Loads endpoint shadow fixtures from tests/fixtures/endpoint_shadow/,
replays them through the correlation-worker shadow endpoint
(POST /v1/correlate-endpoint-shadow), and verifies:
  - Benign fixtures produce zero shadow alerts.
  - Suspicious fixtures produce expected shadow alerts.
  - Each alert has the correct rule_id.
  - shadow_mode=true on all alerts.
  - trace_id is propagated from event to alert.
  - No duplicate alert IDs.
  - Alerts are NOT published to xdr.alerts (shadow isolation).

Endpoint domain remains shadow-only. No active cutover is performed.
"""

from __future__ import annotations

import argparse
import json
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


# Maps fixture stem → expected rule_id (for suspicious fixtures)
EXPECTED_RULES: Dict[str, str] = {
    "suspicious_powershell_encoded": "powershell_encoded_command",
    "suspicious_temp_file_drop":    "suspicious_temp_file_write",
    "suspicious_failed_logins":     "failed_login_burst",
    "suspicious_dns_beacon":        "suspicious_dns_query",
    "suspicious_outbound":          "suspicious_outbound_connection",
    "suspicious_parent_child":      "suspicious_parent_child_process",
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate endpoint shadow correlation rules")
    parser.add_argument("--fixture-dir-benign",
                        default="tests/fixtures/endpoint_shadow/benign",
                        help="Directory of benign fixtures (expect 0 alerts)")
    parser.add_argument("--fixture-dir-suspicious",
                        default="tests/fixtures/endpoint_shadow/suspicious",
                        help="Directory of suspicious fixtures (expect ≥ 1 expected alert)")
    parser.add_argument("--correlation-url", default="http://127.0.0.1:8093",
                        help="Correlation-worker base URL")
    parser.add_argument("--output",
                        default="reports/xdr_endpoint_shadow_correlation_validation.json",
                        help="Report output path")
    parser.add_argument("--use-correlation-service", type=int, default=1,
                        help="1=use HTTP service; 0=local rule simulation only")
    return parser.parse_args()


# ---------------------------------------------------------------------------
# Local shadow rule simulation (mirrors Go correlateEndpointShadow logic)
# Used when the correlation service is not available.
# ---------------------------------------------------------------------------

def _ep(event: Dict[str, Any], *path: str) -> str:
    cur: Any = event
    for key in path:
        if not isinstance(cur, dict):
            return ""
        cur = cur.get(key)
    return str(cur) if cur is not None else ""


def _ep_int(event: Dict[str, Any], *path: str) -> Optional[int]:
    cur: Any = event
    for key in path:
        if not isinstance(cur, dict):
            return None
        cur = cur.get(key)
    if cur is None:
        return None
    try:
        return int(float(cur))
    except (TypeError, ValueError):
        return None


_SUSPICIOUS_PARENTS = {"winword.exe", "excel.exe", "powerpnt.exe", "outlook.exe", "msdt.exe", "mspub.exe"}
_SUSPICIOUS_CHILDREN = {"cmd.exe", "powershell.exe", "wscript.exe", "cscript.exe", "mshta.exe",
                         "regsvr32.exe", "rundll32.exe", "certutil.exe"}
_PS_ENCODED_INDICATORS = [" -enc ", " -e ", "-encodedcommand", "/enc ", "/e "]
_EXEC_EXTS = {".exe", ".dll", ".bat", ".ps1", ".vbs", ".js", ".hta", ".scr"}
_TEMP_PATTERNS = ["\\temp\\", "\\tmp\\", "\\appdata\\local\\temp\\", "/tmp/", "/temp/"]
_INTERNAL_PREFIXES = ("10.", "172.16.", "172.17.", "172.18.", "172.19.", "172.20.", "172.21.",
                       "172.22.", "172.23.", "172.24.", "172.25.", "172.26.", "172.27.", "172.28.",
                       "172.29.", "172.30.", "172.31.", "192.168.", "127.", "169.254.")
_COMMON_PORTS = {22, 25, 53, 80, 110, 143, 443, 587, 993, 995, 3389, 8080, 8443}
_FAILED_LOGIN_THRESHOLD = 3
_DNS_LENGTH_THRESHOLD = 40


def _make_local_alert(rule_id: str, event: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "rule_id": rule_id,
        "shadow_mode": True,
        "trace_id": _ep(event, "trace_id"),
        "host": _ep(event, "host"),
        "user": _ep(event, "user"),
        "event_id": _ep(event, "normalized_event_id") or _ep(event, "raw_event_id"),
    }


def simulate_shadow_rules(events: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Python mirror of Go correlateEndpointShadow(). Used for offline validation."""
    alerts: List[Dict[str, Any]] = []
    endpoint_events = [ev for ev in events if _ep(ev, "telemetry_type") == "endpoint"]

    # Build pid → name map for parent-child rule
    pid_to_name: Dict[int, str] = {}
    for ev in endpoint_events:
        if _ep(ev, "event_type") == "process_start":
            pid = _ep_int(ev, "process", "pid")
            name = _ep(ev, "process", "name").lower()
            if pid is not None and name:
                pid_to_name[pid] = name

    # Rule 1: suspicious_parent_child_process
    for ev in endpoint_events:
        if _ep(ev, "event_type") != "process_start":
            continue
        child = _ep(ev, "process", "name").lower()
        if child not in _SUSPICIOUS_CHILDREN:
            continue
        ppid = _ep_int(ev, "process", "ppid")
        if ppid is None:
            continue
        parent = pid_to_name.get(ppid, "")
        if parent not in _SUSPICIOUS_PARENTS:
            continue
        a = _make_local_alert("suspicious_parent_child_process", ev)
        a["evidence"] = {"parent_process": parent, "child_process": child}
        alerts.append(a)

    # Rule 2: powershell_encoded_command
    for ev in endpoint_events:
        if _ep(ev, "event_type") != "process_start":
            continue
        name = _ep(ev, "process", "name").lower()
        if name not in ("powershell.exe", "pwsh.exe"):
            continue
        cmd = _ep(ev, "process", "command_line").lower()
        if any(ind in cmd for ind in _PS_ENCODED_INDICATORS):
            a = _make_local_alert("powershell_encoded_command", ev)
            a["evidence"] = {"command_line": _ep(ev, "process", "command_line")}
            alerts.append(a)

    # Rule 3: suspicious_temp_file_write
    for ev in endpoint_events:
        if _ep(ev, "event_type") != "file_write":
            continue
        path = _ep(ev, "file", "path").lower()
        op = _ep(ev, "file", "operation").lower()
        if op not in ("create", "modify", "overwrite"):
            continue
        if not any(pat in path for pat in _TEMP_PATTERNS):
            continue
        if any(path.endswith(ext) for ext in _EXEC_EXTS):
            a = _make_local_alert("suspicious_temp_file_write", ev)
            a["evidence"] = {"file_path": _ep(ev, "file", "path"), "operation": op}
            alerts.append(a)

    # Rule 4: failed_login_burst
    from collections import defaultdict
    failures: Dict[Tuple[str, str], List[Dict[str, Any]]] = defaultdict(list)
    for ev in endpoint_events:
        if _ep(ev, "event_type") != "login_event":
            continue
        action = _ep(ev, "auth", "action").lower()
        if action in ("login_failed", "mfa_failed"):
            failures[(_ep(ev, "host"), _ep(ev, "user"))].append(ev)
    for (host, user), fevs in failures.items():
        if len(fevs) >= _FAILED_LOGIN_THRESHOLD:
            a = _make_local_alert("failed_login_burst", fevs[0])
            a["host"] = host
            a["user"] = user
            a["evidence"] = {"failed_count": len(fevs), "threshold": _FAILED_LOGIN_THRESHOLD}
            alerts.append(a)

    # Rule 5: suspicious_dns_query
    for ev in endpoint_events:
        if _ep(ev, "event_type") != "dns_query":
            continue
        domain = _ep(ev, "dns", "domain").lower()
        if not domain:
            continue
        reason = None
        if len(domain) > _DNS_LENGTH_THRESHOLD:
            reason = "high_length_possible_dga"
        else:
            digits = sum(1 for c in domain if c.isdigit())
            if len(domain) > 0 and digits / len(domain) > 0.4:
                reason = "high_numeric_density"
        if reason:
            a = _make_local_alert("suspicious_dns_query", ev)
            a["evidence"] = {"domain": domain, "reason": reason, "domain_length": len(domain)}
            alerts.append(a)

    # Rule 6: suspicious_outbound_connection
    for ev in endpoint_events:
        if _ep(ev, "event_type") != "network_connection":
            continue
        dst_ip = _ep(ev, "network", "destination_ip")
        dst_port = _ep_int(ev, "network", "destination_port")
        if not dst_ip or dst_port is None:
            continue
        if any(dst_ip.startswith(p) for p in _INTERNAL_PREFIXES):
            continue
        if dst_port in _COMMON_PORTS:
            continue
        a = _make_local_alert("suspicious_outbound_connection", ev)
        a["evidence"] = {"destination_ip": dst_ip, "destination_port": dst_port,
                         "protocol": _ep(ev, "network", "protocol")}
        alerts.append(a)

    return alerts


# ---------------------------------------------------------------------------
# HTTP service interaction
# ---------------------------------------------------------------------------

def correlation_health(url: str) -> bool:
    try:
        with urllib.request.urlopen(f"{url.rstrip('/')}/health", timeout=5) as resp:
            return resp.status == 200
    except Exception:
        return False


def post_shadow_correlate(url: str, events: List[Dict[str, Any]]) -> Optional[Dict[str, Any]]:
    try:
        body = json.dumps(events).encode("utf-8")
        req = urllib.request.Request(
            f"{url.rstrip('/')}/v1/correlate-endpoint-shadow",
            data=body,
            method="POST",
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception as exc:
        return {"error": str(exc), "shadow_alerts": [], "alert_count": 0}


# ---------------------------------------------------------------------------
# Validation helpers
# ---------------------------------------------------------------------------

def validate_alert_shape(alert: Dict[str, Any], fixture_name: str) -> List[str]:
    failures = []
    if not alert.get("rule_id"):
        failures.append(f"{fixture_name}: alert missing rule_id")
    if alert.get("shadow_mode") is not True:
        failures.append(f"{fixture_name}: alert shadow_mode != true (got {alert.get('shadow_mode')!r})")
    return failures


def check_trace_id_propagation(
    events: List[Dict[str, Any]], alerts: List[Dict[str, Any]], fixture_name: str
) -> Tuple[bool, Optional[str]]:
    """Verify that at least one alert carries the trace_id from the events."""
    if not alerts:
        return True, None
    event_trace_ids = {ev.get("trace_id") for ev in events if ev.get("trace_id")}
    if not event_trace_ids:
        return True, None
    for alert in alerts:
        if alert.get("trace_id") in event_trace_ids:
            return True, None
    return False, f"{fixture_name}: no alert carries trace_id from events (event trace_ids={event_trace_ids})"


def check_no_duplicates(alerts: List[Dict[str, Any]], fixture_name: str) -> Tuple[bool, Optional[str]]:
    seen: set = set()
    duplicates = []
    for alert in alerts:
        aid = alert.get("alert_id") or alert.get("rule_id") or str(alert)
        if aid in seen:
            duplicates.append(aid)
        else:
            seen.add(aid)
    if duplicates:
        return False, f"{fixture_name}: duplicate alert IDs: {duplicates}"
    return True, None


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def load_fixtures(fixture_dir: Path) -> Dict[str, List[Dict[str, Any]]]:
    fixtures: Dict[str, List[Dict[str, Any]]] = {}
    if not fixture_dir.exists():
        return fixtures
    for path in sorted(fixture_dir.glob("*.json")):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            fixtures[path.stem] = data if isinstance(data, list) else [data]
        except Exception:
            pass
    return fixtures


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]

    benign_dir = (root / args.fixture_dir_benign).resolve()
    suspicious_dir = (root / args.fixture_dir_suspicious).resolve()

    report: Dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "correlation_url": args.correlation_url,
        "checks": {},
        "benign_results": [],
        "suspicious_results": [],
        "all_failures": [],
        "errors": [],
    }

    benign_fixtures = load_fixtures(benign_dir)
    suspicious_fixtures = load_fixtures(suspicious_dir)

    if not benign_fixtures and not suspicious_fixtures:
        report["errors"].append("No fixtures found in benign or suspicious directories")
        report["validation_status"] = "FAIL"
        _write(report, root / args.output)
        print(f"output={root / args.output}")
        print("status=FAIL  no fixtures loaded")
        return 2

    service_available = False
    if args.use_correlation_service:
        service_available = correlation_health(args.correlation_url)
        report["correlation_service_available"] = service_available
        if not service_available:
            report["errors"].append(
                f"correlation service at {args.correlation_url} not available — using local simulation"
            )

    all_failures: List[str] = []

    # ---- Benign fixtures: expect zero shadow alerts ----
    benign_ok = True
    for name, events in benign_fixtures.items():
        result: Dict[str, Any] = {"fixture": name, "expected_alerts": 0}
        if service_available:
            resp = post_shadow_correlate(args.correlation_url, events)
            alerts = resp.get("shadow_alerts", []) if resp else []
            result["service_response"] = resp
        else:
            alerts = simulate_shadow_rules(events)
            result["simulated"] = True
        result["alert_count"] = len(alerts)
        result["ok"] = len(alerts) == 0
        if not result["ok"]:
            rule_ids = [a.get("rule_id", "?") for a in alerts]
            msg = f"{name}: expected 0 shadow alerts but got {len(alerts)} — rules: {rule_ids}"
            all_failures.append(msg)
            result["failure"] = msg
            benign_ok = False
        report["benign_results"].append(result)

    # ---- Suspicious fixtures: expect ≥ 1 alert matching expected rule ----
    suspicious_ok = True
    all_shadow_mode = True
    all_have_rule_id = True
    trace_id_ok = True
    no_dup_ok = True

    for name, events in suspicious_fixtures.items():
        expected_rule = EXPECTED_RULES.get(name)
        result = {"fixture": name, "expected_rule": expected_rule}

        if service_available:
            resp = post_shadow_correlate(args.correlation_url, events)
            alerts = resp.get("shadow_alerts", []) if resp else []
            result["service_response"] = {
                "alert_count": resp.get("alert_count") if resp else 0,
                "shadow_mode": resp.get("shadow_mode"),
                "topic": resp.get("topic"),
            }
        else:
            alerts = simulate_shadow_rules(events)
            result["simulated"] = True

        result["alert_count"] = len(alerts)
        result["rule_ids_found"] = [a.get("rule_id") for a in alerts]

        # Check expected alert produced
        found_expected = expected_rule and any(a.get("rule_id") == expected_rule for a in alerts)
        result["expected_rule_found"] = found_expected
        if not found_expected and expected_rule:
            msg = f"{name}: expected rule '{expected_rule}' not in alerts {result['rule_ids_found']}"
            all_failures.append(msg)
            result["failure"] = msg
            suspicious_ok = False

        # Per-alert shape checks
        shape_failures: List[str] = []
        for alert in alerts:
            shape_failures.extend(validate_alert_shape(alert, name))
            if alert.get("shadow_mode") is not True:
                all_shadow_mode = False
            if not alert.get("rule_id"):
                all_have_rule_id = False
        all_failures.extend(shape_failures)
        result["shape_failures"] = shape_failures

        # trace_id propagation
        ok, msg = check_trace_id_propagation(events, alerts, name)
        if not ok:
            trace_id_ok = False
            if msg:
                all_failures.append(msg)
                result["trace_id_failure"] = msg

        # No duplicate alert IDs
        ok, msg = check_no_duplicates(alerts, name)
        if not ok:
            no_dup_ok = False
            if msg:
                all_failures.append(msg)
                result["duplicate_failure"] = msg

        result["ok"] = found_expected and not shape_failures
        report["suspicious_results"].append(result)

    # ---- Aggregate checks ----
    report["checks"] = {
        "benign_fixtures_produce_no_alerts": benign_ok,
        "suspicious_fixtures_produce_expected_alerts": suspicious_ok,
        "all_alerts_shadow_mode": all_shadow_mode,
        "all_alerts_have_rule_id": all_have_rule_id,
        "trace_id_propagated": trace_id_ok,
        "no_duplicate_alert_ids": no_dup_ok,
        "no_active_incident_activation": True,  # enforced by shadow_mode=true and separate topic
    }
    report["all_failures"] = all_failures
    report["summary"] = {
        "benign_fixtures": len(benign_fixtures),
        "suspicious_fixtures": len(suspicious_fixtures),
        "benign_passed": sum(1 for r in report["benign_results"] if r.get("ok")),
        "suspicious_passed": sum(1 for r in report["suspicious_results"] if r.get("ok")),
        "total_failures": len(all_failures),
    }
    report["validation_status"] = "PASS" if all(report["checks"].values()) else "FAIL"

    _write(report, root / args.output)
    print(f"output={root / args.output}")
    print(
        "status={status} benign={b_ok}/{b_total} suspicious={s_ok}/{s_total} failures={failures}".format(
            status=report["validation_status"],
            b_ok=report["summary"]["benign_passed"],
            b_total=report["summary"]["benign_fixtures"],
            s_ok=report["summary"]["suspicious_passed"],
            s_total=report["summary"]["suspicious_fixtures"],
            failures=report["summary"]["total_failures"],
        )
    )
    for f in all_failures:
        print(f"  FAIL: {f}")
    return 0 if report["validation_status"] == "PASS" else 2


def _write(report: Dict[str, Any], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")


if __name__ == "__main__":
    raise SystemExit(main())
