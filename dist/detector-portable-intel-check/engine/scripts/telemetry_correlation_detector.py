#!/usr/bin/env python3
"""
Correlate endpoint/network/DNS telemetry into defensive security alerts.

This detector complements the web-event detector. It does not replace the
current rules/ML pipeline; it adds host, network, and DNS context for advanced
behaviors such as persistence, lateral movement, internal recon, sandbox
evasion, and DNS beaconing.
"""

from __future__ import annotations

import argparse
import hashlib
import ipaddress
import json
import os
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path
from statistics import mean, pstdev
from typing import Any, Dict, Iterable, List, Optional, Tuple

from realtime_detector_consumer import (
    build_dsn_from_env,
    connect_db,
    hmac_hex,
    insert_alerts,
    mitre_for_alert,
)


DETECTOR_NAME = "telemetry-correlation"
DETECTOR_VERSION = "v1-endpoint-network-dns"

PERSISTENCE_EVENTS = {
    "scheduled_task_created",
    "service_created",
    "startup_item_created",
    "registry_run_key_modified",
    "cron_modified",
}
SANDBOX_EVASION_EVENTS = {
    "sandbox_probe",
    "debugger_check",
    "vm_artifact_check",
    "environment_probe",
}
ADMIN_PORTS = {22, 135, 139, 445, 3389, 5985, 5986}


DEFAULT_CONFIG = {
    "lateral_window_seconds": 300,
    "lateral_min_internal_hosts": 3,
    "internal_recon_window_seconds": 300,
    "internal_recon_min_dst_ips": 10,
    "internal_recon_min_dst_ports": 8,
    "dns_beacon_window_seconds": 600,
    "dns_beacon_min_hits": 6,
    "dns_beacon_max_interval_cv": 0.35,
}

DEFAULT_BASELINE = {
    "trusted_admin_sources": [],
    "approved_internal_admin_pairs": [],
    "known_dns_queries": [],
    "approved_persistence_events": [],
    "approved_process_names": [],
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Detect advanced patterns from telemetry_events")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--minutes", type=int, default=60)
    parser.add_argument("--config", default="storage/app/telemetry_correlation.json")
    parser.add_argument("--baseline", default="storage/app/telemetry_baseline.json")
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
    parser.add_argument("--dry-run", action="store_true")
    return parser.parse_args()


def load_config(path: Path) -> Dict[str, Any]:
    cfg = dict(DEFAULT_CONFIG)
    if path.exists():
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                cfg.update(data)
        except Exception:
            pass
    return cfg


def load_baseline(path: Path) -> Dict[str, Any]:
    baseline = dict(DEFAULT_BASELINE)
    if path.exists():
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                baseline.update(data)
        except Exception:
            pass
    return baseline


def parse_dt(value: Any) -> datetime:
    if isinstance(value, datetime):
        if value.tzinfo is None:
            return value.replace(tzinfo=timezone.utc)
        return value
    text = str(value or "").replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(text)
        return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)
    except ValueError:
        return datetime.now(timezone.utc)


def is_internal_ip(value: Optional[str]) -> bool:
    if not value:
        return False
    try:
        return ipaddress.ip_address(value).is_private
    except ValueError:
        return False


def floor_window(ts: datetime, seconds: int) -> Tuple[datetime, datetime]:
    epoch = int(ts.timestamp())
    start = datetime.fromtimestamp(epoch - (epoch % max(1, seconds)), tz=ts.tzinfo or timezone.utc)
    return start, start + timedelta(seconds=seconds)


def evidence_json(data: Dict[str, Any]) -> str:
    return json.dumps(data, separators=(",", ":"), ensure_ascii=False, default=str)


def event_ref(ev: Dict[str, Any]) -> Dict[str, Any]:
    payload = ev.get("payload") if isinstance(ev.get("payload"), dict) else {}
    return {
        "db_id": ev.get("id"),
        "event_id": ev.get("event_id"),
        "ts": parse_dt(ev.get("ts")).isoformat(),
        "telemetry_type": ev.get("telemetry_type"),
        "event_type": ev.get("event_type"),
        "host_id": ev.get("host_id"),
        "src_ip": ev.get("src_ip"),
        "dst_ip": ev.get("dst_ip"),
        "dst_port": ev.get("dst_port"),
        "process_name": ev.get("process_name"),
        "user_name_hash": ev.get("user_name_hash"),
        "query": payload.get("query"),
    }


def evidence_chain(events: Iterable[Dict[str, Any]], max_events: int = 12) -> List[Dict[str, Any]]:
    ordered = sorted(events, key=lambda ev: parse_dt(ev.get("ts")))
    return [event_ref(ev) for ev in ordered[:max_events]]


def score_from_signals(signals: List[str], base: float = 0.45) -> Tuple[float, str]:
    unique_count = len(set(signals))
    score = min(1.0, base + (unique_count * 0.15))
    if unique_count >= 4:
        return score, "critical"
    if unique_count >= 2:
        return score, "high"
    return score, "medium"


def list_value(data: Dict[str, Any], key: str) -> List[Any]:
    value = data.get(key, [])
    return value if isinstance(value, list) else []


def is_approved_persistence(ev: Dict[str, Any], baseline: Dict[str, Any]) -> bool:
    event_type = str(ev.get("event_type") or "")
    process_name = str(ev.get("process_name") or "").lower()
    host_id = str(ev.get("host_id") or "")
    for item in list_value(baseline, "approved_persistence_events"):
        if not isinstance(item, dict):
            continue
        if item.get("event_type") and str(item["event_type"]) != event_type:
            continue
        if item.get("process_name") and str(item["process_name"]).lower() != process_name:
            continue
        if item.get("host_id") and str(item["host_id"]) != host_id:
            continue
        return True
    return process_name in {str(x).lower() for x in list_value(baseline, "approved_process_names")}


def is_known_dns_query(query: str, baseline: Dict[str, Any]) -> bool:
    known = {str(x).lower().strip() for x in list_value(baseline, "known_dns_queries")}
    return query.lower().strip() in known


def is_trusted_admin_source(src_ip: str, baseline: Dict[str, Any]) -> bool:
    return src_ip in {str(x) for x in list_value(baseline, "trusted_admin_sources")}


def is_approved_admin_pair(src_ip: str, dst_ip: str, port: int, baseline: Dict[str, Any]) -> bool:
    for item in list_value(baseline, "approved_internal_admin_pairs"):
        if not isinstance(item, dict):
            continue
        if str(item.get("src_ip", "")) != src_ip:
            continue
        if item.get("dst_ip") and str(item["dst_ip"]) != dst_ip:
            continue
        ports = item.get("ports", [])
        if isinstance(ports, list) and port in {int(p) for p in ports if str(p).isdigit()}:
            return True
    return False


def alert_row(
    app_key: str,
    alert_type: str,
    severity: str,
    actor: str,
    ts: datetime,
    window_start: datetime,
    window_end: datetime,
    score: float,
    evidence: Dict[str, Any],
    raw_event: Dict[str, Any],
    event_id_ref: Optional[int] = None,
    ip: Optional[str] = None,
) -> Tuple[Any, ...]:
    actor_key = actor[:128]
    profile_hash = hashlib.sha256(json.dumps(DEFAULT_CONFIG, sort_keys=True).encode("utf-8")).hexdigest()
    unique_text = f"{DETECTOR_VERSION}|{alert_type}|{actor_key}|{window_start.isoformat()}|{window_end.isoformat()}"
    alert_id = hmac_hex(app_key, unique_text)
    evidence = dict(evidence)
    evidence["mitre_attack"] = mitre_for_alert(alert_type)
    evidence["detection_layer"] = "telemetry"
    evidence.setdefault("confidence", {"score": float(score), "severity": severity})
    return (
        alert_id,
        ts.isoformat(),
        alert_type,
        DETECTOR_NAME,
        DETECTOR_VERSION,
        severity,
        ip,
        None,
        actor_key,
        event_id_ref,
        window_start.isoformat(),
        window_end.isoformat(),
        float(score),
        profile_hash,
        None,
        evidence_json(evidence),
        evidence_json(raw_event),
    )


def fetch_events(conn: Any, minutes: int) -> List[Dict[str, Any]]:
    sql = """
    SELECT id, ts, event_id, telemetry_type, event_type, host_id, src_ip, dst_ip,
           dst_port, protocol, process_name, user_name_hash, payload
    FROM telemetry_events
    WHERE ts >= now() - (%s::text)::interval
    ORDER BY ts ASC
    """
    interval_text = f"{max(1, minutes)} minutes"
    with conn.cursor() as cur:
        cur.execute(sql, (interval_text,))
        rows = cur.fetchall()
    out: List[Dict[str, Any]] = []
    for row in rows:
        payload = row[12] if isinstance(row[12], dict) else {}
        if isinstance(row[12], str):
            try:
                parsed_payload = json.loads(row[12])
                payload = parsed_payload if isinstance(parsed_payload, dict) else {}
            except json.JSONDecodeError:
                payload = {}
        out.append(
            {
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
        )
    return out


def detect_atomic(events: Iterable[Dict[str, Any]], app_key: str, baseline: Dict[str, Any]) -> List[Tuple[Any, ...]]:
    rows: List[Tuple[Any, ...]] = []
    for ev in events:
        event_type = str(ev.get("event_type") or "")
        ts = parse_dt(ev.get("ts"))
        if event_type in PERSISTENCE_EVENTS:
            if is_approved_persistence(ev, baseline):
                continue
            w_start, w_end = floor_window(ts, 300)
            signals = ["persistence_event"]
            if ev.get("process_name"):
                signals.append(f"process:{ev.get('process_name')}")
            score, severity = score_from_signals(signals)
            rows.append(
                alert_row(
                    app_key,
                    "PERSISTENCE_INDICATOR",
                    severity,
                    str(ev.get("host_id") or ev.get("src_ip") or "unknown"),
                    ts,
                    w_start,
                    w_end,
                    score,
                    {
                        "event_type": event_type,
                        "process_name": ev.get("process_name"),
                        "evidence_chain": evidence_chain([ev]),
                        "confidence": {"score": score, "severity": severity, "signals": signals},
                    },
                    ev.get("payload") or ev,
                    ev.get("id"),
                    ev.get("src_ip"),
                )
            )
        if event_type in SANDBOX_EVASION_EVENTS:
            w_start, w_end = floor_window(ts, 300)
            signals = ["sandbox_evasion_event"]
            if ev.get("process_name"):
                signals.append(f"process:{ev.get('process_name')}")
            score, severity = score_from_signals(signals, base=0.35)
            rows.append(
                alert_row(
                    app_key,
                    "SANDBOX_EVASION_INDICATOR",
                    severity,
                    str(ev.get("host_id") or ev.get("src_ip") or "unknown"),
                    ts,
                    w_start,
                    w_end,
                    score,
                    {
                        "event_type": event_type,
                        "process_name": ev.get("process_name"),
                        "evidence_chain": evidence_chain([ev]),
                        "confidence": {"score": score, "severity": severity, "signals": signals},
                    },
                    ev.get("payload") or ev,
                    ev.get("id"),
                    ev.get("src_ip"),
                )
            )
    return rows


def detect_network(events: List[Dict[str, Any]], app_key: str, cfg: Dict[str, Any], baseline: Dict[str, Any]) -> List[Tuple[Any, ...]]:
    rows: List[Tuple[Any, ...]] = []
    by_src: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    for ev in events:
        if ev.get("telemetry_type") == "network" and ev.get("src_ip"):
            by_src[str(ev["src_ip"])].append(ev)

    lateral_window = int(cfg["lateral_window_seconds"])
    recon_window = int(cfg["internal_recon_window_seconds"])
    for src_ip, group in by_src.items():
        for window_sec, alert_type in [(lateral_window, "LATERAL_MOVEMENT_SUSPECTED"), (recon_window, "INTERNAL_RECON_SUSPECTED")]:
            buckets: Dict[datetime, List[Dict[str, Any]]] = defaultdict(list)
            for ev in group:
                start, _end = floor_window(parse_dt(ev["ts"]), window_sec)
                buckets[start].append(ev)
            for start, bucket in buckets.items():
                end = start + timedelta(seconds=window_sec)
                internal_dst = {str(ev.get("dst_ip")) for ev in bucket if is_internal_ip(ev.get("dst_ip"))}
                ports = {int(ev.get("dst_port")) for ev in bucket if ev.get("dst_port") is not None}
                admin_internal = {
                    str(ev.get("dst_ip"))
                    for ev in bucket
                    if is_internal_ip(ev.get("dst_ip"))
                    and int(ev.get("dst_port") or 0) in ADMIN_PORTS
                    and not is_approved_admin_pair(src_ip, str(ev.get("dst_ip")), int(ev.get("dst_port") or 0), baseline)
                }
                if alert_type == "LATERAL_MOVEMENT_SUSPECTED":
                    if is_trusted_admin_source(src_ip, baseline):
                        continue
                    threshold = int(cfg["lateral_min_internal_hosts"])
                    if len(admin_internal) >= threshold:
                        signals = ["multi_internal_admin_hosts", "remote_admin_ports"]
                        if len(ports.intersection(ADMIN_PORTS)) >= 3:
                            signals.append("multiple_admin_protocols")
                        if any(str(ev.get("process_name") or "").lower() not in {"", "mstsc.exe", "ssh.exe"} for ev in bucket):
                            signals.append("unusual_process_for_admin_access")
                        score, severity = score_from_signals(signals, base=0.45)
                        rows.append(
                            alert_row(
                                app_key,
                                alert_type,
                                severity,
                                src_ip,
                                max(parse_dt(ev["ts"]) for ev in bucket),
                                start,
                                end,
                                score,
                                {
                                    "src_ip": src_ip,
                                    "unique_internal_admin_hosts": len(admin_internal),
                                    "admin_ports": sorted(ports.intersection(ADMIN_PORTS)),
                                    "evidence_chain": evidence_chain(bucket),
                                    "confidence": {"score": score, "severity": severity, "signals": signals},
                                },
                                {"sample_events": [ev.get("payload") or ev for ev in bucket[:5]]},
                                None,
                                src_ip,
                            )
                        )
                else:
                    min_ips = int(cfg["internal_recon_min_dst_ips"])
                    min_ports = int(cfg["internal_recon_min_dst_ports"])
                    if len(internal_dst) >= min_ips or len(ports) >= min_ports:
                        signals = ["many_internal_destinations" if len(internal_dst) >= min_ips else "many_destination_ports"]
                        if len(internal_dst) >= min_ips and len(ports) >= min_ports:
                            signals.append("host_and_port_sweep")
                        score, severity = score_from_signals(signals, base=0.35)
                        rows.append(
                            alert_row(
                                app_key,
                                alert_type,
                                severity,
                                src_ip,
                                max(parse_dt(ev["ts"]) for ev in bucket),
                                start,
                                end,
                                score,
                                {
                                    "src_ip": src_ip,
                                    "unique_internal_dst_ips": len(internal_dst),
                                    "unique_dst_ports": len(ports),
                                    "evidence_chain": evidence_chain(bucket),
                                    "confidence": {"score": score, "severity": severity, "signals": signals},
                                },
                                {"sample_events": [ev.get("payload") or ev for ev in bucket[:5]]},
                                None,
                                src_ip,
                            )
                        )
    return rows


def detect_dns_beacon(events: List[Dict[str, Any]], app_key: str, cfg: Dict[str, Any], baseline: Dict[str, Any]) -> List[Tuple[Any, ...]]:
    rows: List[Tuple[Any, ...]] = []
    by_actor_query: Dict[Tuple[str, str], List[Dict[str, Any]]] = defaultdict(list)
    for ev in events:
        if ev.get("telemetry_type") != "dns":
            continue
        payload = ev.get("payload") if isinstance(ev.get("payload"), dict) else {}
        query = str(payload.get("query") or "").lower().strip()
        if is_known_dns_query(query, baseline):
            continue
        actor = str(ev.get("host_id") or ev.get("src_ip") or "unknown")
        if query:
            by_actor_query[(actor, query)].append(ev)

    min_hits = int(cfg["dns_beacon_min_hits"])
    max_cv = float(cfg["dns_beacon_max_interval_cv"])
    window_sec = int(cfg["dns_beacon_window_seconds"])
    for (actor, query), group in by_actor_query.items():
        group = sorted(group, key=lambda ev: parse_dt(ev["ts"]))
        buckets: Dict[datetime, List[Dict[str, Any]]] = defaultdict(list)
        for ev in group:
            start, _end = floor_window(parse_dt(ev["ts"]), window_sec)
            buckets[start].append(ev)
        for start, bucket in buckets.items():
            bucket = sorted(bucket, key=lambda ev: parse_dt(ev["ts"]))
            if len(bucket) < min_hits:
                continue
            intervals = [
                (parse_dt(bucket[i]["ts"]) - parse_dt(bucket[i - 1]["ts"])).total_seconds()
                for i in range(1, len(bucket))
            ]
            if not intervals:
                continue
            avg = mean(intervals)
            cv = (pstdev(intervals) / avg) if avg > 0 and len(intervals) > 1 else 0.0
            span = (parse_dt(bucket[-1]["ts"]) - parse_dt(bucket[0]["ts"])).total_seconds()
            if cv > max_cv or span > window_sec:
                continue
            end = start + timedelta(seconds=window_sec)
            signals = ["regular_dns_cadence", "repeated_domain"]
            if len(bucket) >= min_hits * 2:
                signals.append("high_beacon_count")
            score, severity = score_from_signals(signals, base=0.45)
            rows.append(
                alert_row(
                    app_key,
                    "C2_DNS_BEACON_PATTERN",
                    severity,
                    actor,
                    parse_dt(bucket[-1]["ts"]),
                    start,
                    end,
                    score,
                    {
                        "host_id": actor,
                        "query": query,
                        "hits": len(bucket),
                        "avg_interval_sec": avg,
                        "interval_cv": cv,
                        "evidence_chain": evidence_chain(bucket),
                        "confidence": {"score": score, "severity": severity, "signals": signals},
                    },
                    {"sample_events": [ev.get("payload") or ev for ev in bucket[:5]]},
                    None,
                    bucket[-1].get("src_ip"),
                )
            )
    return rows


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    cfg = load_config((root / args.config).resolve())
    baseline = load_baseline((root / args.baseline).resolve())
    driver, conn = connect_db(dsn)
    conn.autocommit = False
    try:
        events = fetch_events(conn, args.minutes)
        rows: List[Tuple[Any, ...]] = []
        rows.extend(detect_atomic(events, args.app_key, baseline))
        rows.extend(detect_network(events, args.app_key, cfg, baseline))
        rows.extend(detect_dns_beacon(events, args.app_key, cfg, baseline))
        if not args.dry_run:
            insert_alerts(conn, driver, rows)
    finally:
        conn.close()
    print(f"telemetry_events={len(events)}")
    print(f"alerts_attempted={len(rows)}")
    if args.dry_run:
        print("dry_run=1")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
