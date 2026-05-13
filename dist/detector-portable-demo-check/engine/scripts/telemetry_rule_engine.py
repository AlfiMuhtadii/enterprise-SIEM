#!/usr/bin/env python3
"""
Declarative telemetry rule engine with sliding windows, temporal sequencing,
decay scoring, and evidence-chain output.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path
from statistics import mean, pstdev
from typing import Any, Dict, Iterable, List, Optional, Tuple

from realtime_detector_consumer import build_dsn_from_env, connect_db, hmac_hex, insert_alerts
from telemetry_correlation_detector import (
    evidence_chain,
    is_approved_admin_pair,
    is_approved_persistence,
    is_known_dns_query,
    is_trusted_admin_source,
    load_baseline,
    parse_dt,
)


DETECTOR_NAME = "telemetry-rule-engine"
DETECTOR_VERSION = "v1-declarative"
REQUIRED_RULE_FIELDS = {"rule_id", "name", "alert_type", "severity", "time_window_seconds"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run declarative telemetry correlation rules")
    parser.add_argument("--rules", default="storage/app/telemetry_rules.json")
    parser.add_argument("--baseline", default="storage/app/telemetry_baseline.json")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--step-seconds", type=int, default=60)
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
    parser.add_argument("--dry-run", action="store_true")
    return parser.parse_args()


def load_rules(path: Path) -> Dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def validate_rules(payload: Dict[str, Any]) -> List[str]:
    errors: List[str] = []
    if payload.get("version") != 1:
        errors.append("version must be 1")
    rules = payload.get("rules")
    if not isinstance(rules, list):
        return errors + ["rules must be an array"]
    seen = set()
    for idx, rule in enumerate(rules):
        if not isinstance(rule, dict):
            errors.append(f"rules[{idx}] must be object")
            continue
        missing = REQUIRED_RULE_FIELDS - set(rule)
        if missing:
            errors.append(f"{rule.get('rule_id', idx)} missing fields: {sorted(missing)}")
        rid = rule.get("rule_id")
        if rid in seen:
            errors.append(f"duplicate rule_id: {rid}")
        seen.add(rid)
        if "required_event_types" not in rule and "sequence" not in rule:
            errors.append(f"{rid} must define required_event_types or sequence")
        if not isinstance(rule.get("time_window_seconds"), int):
            errors.append(f"{rid} time_window_seconds must be integer")
        if "enabled" in rule and not isinstance(rule["enabled"], bool):
            errors.append(f"{rid} enabled must be boolean")
        if "rule_version" in rule and not isinstance(rule["rule_version"], str):
            errors.append(f"{rid} rule_version must be string")
        if "conditions" in rule and not isinstance(rule["conditions"], list):
            errors.append(f"{rid} conditions must be array")
    return errors


def fetch_events(conn: Any, minutes: int) -> List[Dict[str, Any]]:
    sql = """
    SELECT id, ts, event_id, telemetry_type, event_type, host_id, src_ip, dst_ip,
           dst_port, protocol, process_name, user_name_hash, payload
    FROM telemetry_events
    WHERE ts >= now() - (%s::text)::interval
    ORDER BY ts ASC
    """
    with conn.cursor() as cur:
        cur.execute(sql, (f"{max(1, minutes)} minutes",))
        rows = cur.fetchall()
    events: List[Dict[str, Any]] = []
    for row in rows:
        payload = row[12] if isinstance(row[12], dict) else {}
        if isinstance(row[12], str):
            try:
                data = json.loads(row[12])
                payload = data if isinstance(data, dict) else {}
            except json.JSONDecodeError:
                payload = {}
        ev = {
            "id": row[0],
            "ts": parse_dt(row[1]),
            "event_id": row[2],
            "telemetry_type": row[3],
            "event_type": row[4],
            "host_id": row[5],
            "src_ip": row[6],
            "dst_ip": row[7],
            "dst_port": row[8],
            "protocol": row[9],
            "process_name": row[10],
            "user_name_hash": row[11],
            "payload": payload,
        }
        if payload.get("query") and not ev.get("query"):
            ev["query"] = payload.get("query")
        events.append(ev)
    return events


def value_for(event: Dict[str, Any], field: str) -> Any:
    if field in event:
        return event.get(field)
    payload = event.get("payload") if isinstance(event.get("payload"), dict) else {}
    return payload.get(field)


def condition_match(event: Dict[str, Any], condition: Dict[str, Any]) -> bool:
    actual = value_for(event, str(condition.get("field", "")))
    op = condition.get("op")
    expected = condition.get("value")
    if op == "eq":
        return actual == expected
    if op == "ne":
        return actual != expected
    if op == "in":
        return actual in (expected if isinstance(expected, list) else [])
    if op == "contains":
        return str(expected) in str(actual or "")
    return False


def event_matches(rule: Dict[str, Any], event: Dict[str, Any]) -> bool:
    req = set(rule.get("required_event_types", []))
    if req and event.get("event_type") not in req:
        return False
    return all(condition_match(event, c) for c in rule.get("conditions", []))


def group_key(rule: Dict[str, Any], event: Dict[str, Any]) -> str:
    fields = rule.get("group_by", ["host_id"])
    values = [str(value_for(event, str(f)) or "unknown") for f in fields]
    return "|".join(values)[:128]


def sliding_buckets(events: List[Dict[str, Any]], window_sec: int, step_sec: int) -> Iterable[Tuple[datetime, datetime, List[Dict[str, Any]]]]:
    if not events:
        return
    start = min(parse_dt(ev["ts"]) for ev in events)
    end = max(parse_dt(ev["ts"]) for ev in events)
    cursor = start
    while cursor <= end:
        w_end = cursor + timedelta(seconds=window_sec)
        bucket = [ev for ev in events if cursor <= parse_dt(ev["ts"]) <= w_end]
        if bucket:
            yield cursor, w_end, bucket
        cursor += timedelta(seconds=step_sec)


def thresholds_pass(rule: Dict[str, Any], events: List[Dict[str, Any]]) -> bool:
    thresholds = rule.get("thresholds", {})
    if not isinstance(thresholds, dict):
        return True
    if "min_count" in thresholds and len(events) < int(thresholds["min_count"]):
        return False
    if "unique_dst_ip" in thresholds:
        if len({ev.get("dst_ip") for ev in events if ev.get("dst_ip")}) < int(thresholds["unique_dst_ip"]):
            return False
    if "unique_dst_port" in thresholds:
        if len({ev.get("dst_port") for ev in events if ev.get("dst_port") is not None}) < int(thresholds["unique_dst_port"]):
            return False
    if "max_interval_cv" in thresholds:
        ordered = sorted(events, key=lambda ev: parse_dt(ev["ts"]))
        intervals = [(parse_dt(ordered[i]["ts"]) - parse_dt(ordered[i - 1]["ts"])).total_seconds() for i in range(1, len(ordered))]
        if not intervals:
            return False
        avg = mean(intervals)
        cv = pstdev(intervals) / avg if avg > 0 and len(intervals) > 1 else 0.0
        if cv > float(thresholds["max_interval_cv"]):
            return False
    return True


def score_rule(rule: Dict[str, Any], events: List[Dict[str, Any]], window_end: datetime) -> Tuple[float, str, Dict[str, Any]]:
    scoring = rule.get("scoring", {}) if isinstance(rule.get("scoring"), dict) else {}
    base = float(scoring.get("base", 0.4))
    now_score = base
    if "per_event" in scoring:
        now_score += len(events) * float(scoring["per_event"])
    if "per_unique_dst_ip" in scoring:
        now_score += len({ev.get("dst_ip") for ev in events if ev.get("dst_ip")}) * float(scoring["per_unique_dst_ip"])
    if "per_unique_dst_port" in scoring:
        now_score += len({ev.get("dst_port") for ev in events if ev.get("dst_port") is not None}) * float(scoring["per_unique_dst_port"])
    if "per_phase" in scoring:
        now_score += len({ev.get("_phase") for ev in events if ev.get("_phase") is not None}) * float(scoring["per_phase"])
    latest = max(parse_dt(ev["ts"]) for ev in events)
    age_sec = max(0.0, (window_end - latest).total_seconds())
    decay = max(0.4, 1.0 - (age_sec / max(1.0, float(rule.get("time_window_seconds", 300))) * 0.5))
    score = min(1.0, now_score * decay)
    critical_at = int(scoring.get("critical_at", 9999))
    severity = rule.get("severity_override") or rule.get("severity", "medium")
    if len(events) >= critical_at:
        severity = "critical"
    elif score >= 0.75 and severity == "medium":
        severity = "high"
    return score, severity, {"decay": decay, "latest_event_age_sec": age_sec}


def sequence_matches(rule: Dict[str, Any], events: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    sequence = rule.get("sequence", [])
    if not isinstance(sequence, list) or not sequence:
        return []
    max_gap = int(rule.get("max_gap_seconds", rule.get("time_window_seconds", 300)))
    ordered = sorted(events, key=lambda ev: parse_dt(ev["ts"]))
    chain: List[Dict[str, Any]] = []
    cursor_ts: Optional[datetime] = None
    for phase_idx, phase in enumerate(sequence):
        allowed = set(phase.get("any_event_type", [])) if isinstance(phase, dict) else set()
        match = None
        for ev in ordered:
            if ev in chain or ev.get("event_type") not in allowed:
                continue
            ev_ts = parse_dt(ev["ts"])
            if cursor_ts is not None and (ev_ts - cursor_ts).total_seconds() > max_gap:
                continue
            if cursor_ts is None or ev_ts >= cursor_ts:
                match = dict(ev)
                match["_phase"] = phase_idx
                break
        if match is None:
            return []
        chain.append(match)
        cursor_ts = parse_dt(match["ts"])
    return chain


def suppress_by_baseline(rule: Dict[str, Any], events: List[Dict[str, Any]], baseline: Dict[str, Any]) -> bool:
    if rule.get("alert_type") == "C2_DNS_BEACON_PATTERN":
        queries = {str(value_for(ev, "query") or "").lower() for ev in events}
        return any(is_known_dns_query(q, baseline) for q in queries)
    if rule.get("alert_type") == "PERSISTENCE_INDICATOR":
        return all(is_approved_persistence(ev, baseline) for ev in events)
    if rule.get("alert_type") == "LATERAL_MOVEMENT_SUSPECTED":
        src_ips = {str(ev.get("src_ip") or "") for ev in events if ev.get("src_ip")}
        if any(is_trusted_admin_source(src, baseline) for src in src_ips):
            return True
        return all(
            is_approved_admin_pair(str(ev.get("src_ip") or ""), str(ev.get("dst_ip") or ""), int(ev.get("dst_port") or 0), baseline)
            for ev in events
            if ev.get("src_ip") and ev.get("dst_ip") and ev.get("dst_port") is not None
        )
    return False


def build_alert(app_key: str, rule: Dict[str, Any], actor: str, w_start: datetime, w_end: datetime, events: List[Dict[str, Any]]) -> Tuple[Any, ...]:
    score, severity, scoring_meta = score_rule(rule, events, w_end)
    alert_type = str(rule["alert_type"])
    profile_hash = hashlib.sha256(json.dumps(rule, sort_keys=True).encode("utf-8")).hexdigest()
    unique = f"{DETECTOR_VERSION}|{rule['rule_id']}|{actor}|{w_start.isoformat()}|{w_end.isoformat()}|{profile_hash}"
    alert_id = hmac_hex(app_key, unique)
    evidence = {
        "rule_id": rule["rule_id"],
        "rule_version": rule.get("rule_version", "1.0.0"),
        "rule_name": rule.get("name"),
        "description": rule.get("description"),
        "rule_metadata": rule.get("metadata", {}),
        "mitre_attack": rule.get("mitre", []),
        "detection_layer": "telemetry-rule-engine",
        "evidence_chain": evidence_chain(events),
        "confidence": {"score": score, "severity": severity, "scoring": scoring_meta},
        "window": {"start": w_start.isoformat(), "end": w_end.isoformat()},
        "alert_fingerprint": hashlib.sha256(f"{rule['rule_id']}|{actor}|{alert_type}".encode("utf-8")).hexdigest(),
        "dedup_group": f"{rule['rule_id']}|{actor}|{alert_type}",
        "suppression_window_seconds": int(rule.get("suppression_window_seconds", 300)),
    }
    ip = next((ev.get("src_ip") for ev in events if ev.get("src_ip")), None)
    return (
        alert_id,
        max(parse_dt(ev["ts"]) for ev in events).isoformat(),
        alert_type,
        DETECTOR_NAME,
        DETECTOR_VERSION,
        severity,
        ip,
        None,
        actor,
        None,
        w_start.isoformat(),
        w_end.isoformat(),
        score,
        profile_hash,
        None,
        json.dumps(evidence, separators=(",", ":"), default=str),
        json.dumps({"sample_events": evidence_chain(events, 5)}, separators=(",", ":"), default=str),
    )


def evaluate_rules(events: List[Dict[str, Any]], rules: List[Dict[str, Any]], baseline: Dict[str, Any], step_sec: int, app_key: str) -> List[Tuple[Any, ...]]:
    rows: List[Tuple[Any, ...]] = []
    for rule in rules:
        if rule.get("enabled") is False:
            continue
        window_sec = int(rule["time_window_seconds"])
        if "sequence" in rule:
            candidates = [ev for ev in events if any(ev.get("event_type") in set(p.get("any_event_type", [])) for p in rule["sequence"])]
        else:
            candidates = [ev for ev in events if event_matches(rule, ev)]
        grouped: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
        for ev in candidates:
            grouped[group_key(rule, ev)].append(ev)
        for actor, group in grouped.items():
            for w_start, w_end, bucket in sliding_buckets(group, window_sec, step_sec):
                matched = sequence_matches(rule, bucket) if "sequence" in rule else bucket
                if not matched or not thresholds_pass(rule, matched):
                    continue
                if suppress_by_baseline(rule, matched, baseline):
                    continue
                rows.append(build_alert(app_key, rule, actor, w_start, w_end, matched))
    return rows


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    rules_payload = load_rules((root / args.rules).resolve())
    errors = validate_rules(rules_payload)
    if errors:
        for err in errors:
            print(f"RULE_ERROR: {err}")
        return 2
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    baseline = load_baseline((root / args.baseline).resolve())
    driver, conn = connect_db(dsn)
    conn.autocommit = False
    try:
        events = fetch_events(conn, args.minutes)
        rows = evaluate_rules(events, rules_payload["rules"], baseline, max(1, args.step_seconds), args.app_key)
        if not args.dry_run:
            insert_alerts(conn, driver, rows)
    finally:
        conn.close()
    print(f"events={len(events)}")
    print(f"rules={len(rules_payload['rules'])}")
    print(f"alerts_attempted={len(rows)}")
    if args.dry_run:
        print("dry_run=1")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
