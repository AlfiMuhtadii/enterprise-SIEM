#!/usr/bin/env python3
"""
Cross-domain XDR correlation detector.

This adds email, identity, cloud, SaaS, firewall/proxy, and endpoint correlation
without replacing the existing endpoint/network telemetry detector.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple

from realtime_detector_consumer import build_dsn_from_env, connect_db, hmac_hex, insert_alerts


DETECTOR_NAME = "xdr-correlation"
DETECTOR_VERSION = "v1-cross-domain"

MITRE = {
    "XDR_PHISHING_TO_ENDPOINT_EXECUTION": ["T1566", "T1078", "T1059"],
    "XDR_IMPOSSIBLE_LOGIN_PRIVILEGE_CLOUD": ["T1078", "T1098", "T1528"],
    "XDR_IOC_DNS_ENDPOINT_CHAIN": ["T1583", "T1071.004", "T1059"],
    "XDR_PROXY_ENDPOINT_ESCALATION": ["T1071", "T1059", "TA0040"],
    "IDENTITY_IMPOSSIBLE_TRAVEL": ["T1078"],
    "IDENTITY_UNUSUAL_LOGIN_SOURCE": ["T1078"],
    "IDENTITY_MFA_FAILURE_BURST": ["T1110", "T1621"],
    "IDENTITY_PRIVILEGE_ESCALATION": ["T1098"],
    "IDENTITY_RISKY_IP_LOGIN": ["T1078"],
    "IDENTITY_FAILED_LOGIN_ACROSS_SERVICES": ["T1110"],
    "CLOUD_UNUSUAL_API_ACTIVITY": ["T1528"],
    "CLOUD_SUSPICIOUS_OBJECT_ACCESS": ["T1530"],
    "CLOUD_MASS_DOWNLOAD": ["T1530"],
    "CLOUD_NEW_ACCESS_KEY": ["T1098.001"],
    "CLOUD_SECURITY_SETTING_MODIFIED": ["T1562.007"],
    "SAAS_UNUSUAL_ADMIN_ACTIVITY": ["T1098"],
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run XDR cross-domain correlation against telemetry_events")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--minutes", type=int, default=1440)
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
    parser.add_argument("--dry-run", action="store_true")
    return parser.parse_args()


def parse_dt(value: Any) -> datetime:
    if isinstance(value, datetime):
        return value if value.tzinfo else value.replace(tzinfo=timezone.utc)
    text = str(value or "").replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(text)
        return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)
    except ValueError:
        return datetime.now(timezone.utc)


def evidence_json(data: Dict[str, Any]) -> str:
    return json.dumps(data, separators=(",", ":"), ensure_ascii=False, default=str)


def fetch_events(conn: Any, minutes: int) -> List[Dict[str, Any]]:
    sql = """
    SELECT id, ts, event_id, telemetry_type, event_type, host_id, src_ip, dst_ip, dst_port,
           process_name, user_name_hash, payload, xdr_user, xdr_host, source_ip,
           destination_ip, domain, file_hash, email_sender, email_recipient,
           cloud_account, xdr_action, xdr_result, risk_score, event_source
    FROM telemetry_events
    WHERE ts >= now() - (%s::text)::interval
    ORDER BY ts ASC
    """
    with conn.cursor() as cur:
        cur.execute(sql, (f"{max(1, minutes)} minutes",))
        rows = cur.fetchall()
    events = []
    for row in rows:
        payload = row[11] if isinstance(row[11], dict) else {}
        if isinstance(row[11], str):
            try:
                payload = json.loads(row[11])
            except json.JSONDecodeError:
                payload = {}
        events.append(
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
                "process_name": row[9],
                "user_name_hash": row[10],
                "payload": payload,
                "user": row[12],
                "host": row[13] or row[5],
                "source_ip": row[14] or row[6],
                "destination_ip": row[15] or row[7],
                "domain": row[16],
                "file_hash": row[17],
                "email_sender": row[18],
                "email_recipient": row[19],
                "cloud_account": row[20],
                "action": row[21],
                "result": row[22],
                "risk_score": float(row[23]) if row[23] is not None else 0.0,
                "event_source": row[24],
            }
        )
    return events


def ref(ev: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "db_id": ev.get("id"),
        "event_id": ev.get("event_id"),
        "ts": parse_dt(ev.get("ts")).isoformat(),
        "telemetry_type": ev.get("telemetry_type"),
        "event_type": ev.get("event_type"),
        "user": ev.get("user"),
        "host": ev.get("host"),
        "source_ip": ev.get("source_ip"),
        "destination_ip": ev.get("destination_ip"),
        "domain": ev.get("domain"),
        "cloud_account": ev.get("cloud_account"),
        "email_sender": ev.get("email_sender"),
        "email_recipient": ev.get("email_recipient"),
        "event_source": ev.get("event_source"),
    }


def chain(events: Iterable[Dict[str, Any]]) -> List[Dict[str, Any]]:
    return [ref(ev) for ev in sorted(events, key=lambda item: parse_dt(item["ts"]))[:20]]


def score(signals: List[str], base: float = 0.45) -> Tuple[float, str]:
    value = min(1.0, base + (len(set(signals)) * 0.13))
    if value >= 0.85:
        return value, "critical"
    if value >= 0.65:
        return value, "high"
    if value >= 0.45:
        return value, "medium"
    return value, "low"


def alert_row(app_key: str, alert_type: str, actor: str, events: List[Dict[str, Any]], signals: List[str], base: float = 0.45) -> Tuple[Any, ...]:
    ordered = sorted(events, key=lambda ev: parse_dt(ev["ts"]))
    start = parse_dt(ordered[0]["ts"])
    end = parse_dt(ordered[-1]["ts"])
    confidence, severity = score(signals, base)
    actor_key = actor[:128] if actor else "unknown"
    alert_id = hmac_hex(app_key, f"{DETECTOR_VERSION}|{alert_type}|{actor_key}|{start.isoformat()}|{end.isoformat()}")
    evidence = {
        "detection_layer": "xdr",
        "xdr_domains": sorted({str(ev.get("telemetry_type")) for ev in events if ev.get("telemetry_type")}),
        "involved_users": sorted({str(ev.get("user")) for ev in events if ev.get("user")}),
        "involved_hosts": sorted({str(ev.get("host")) for ev in events if ev.get("host")}),
        "involved_cloud_accounts": sorted({str(ev.get("cloud_account")) for ev in events if ev.get("cloud_account")}),
        "involved_email_artifacts": sorted({str(ev.get("email_sender")) for ev in events if ev.get("email_sender")}),
        "involved_external_ips": sorted({str(ev.get("source_ip")) for ev in events if ev.get("source_ip")}),
        "mitre_attack": MITRE.get(alert_type, []),
        "xdr_kill_chain_summary": {"signals": signals, "first_seen": start.isoformat(), "last_seen": end.isoformat()},
        "evidence_chain": chain(events),
        "confidence": {"score": confidence, "severity": severity, "signals": signals},
    }
    return (
        alert_id,
        end.isoformat(),
        alert_type,
        DETECTOR_NAME,
        DETECTOR_VERSION,
        severity,
        ordered[-1].get("source_ip"),
        None,
        actor_key,
        ordered[-1].get("id"),
        start.isoformat(),
        end.isoformat(),
        confidence,
        hashlib.sha256(DETECTOR_VERSION.encode("utf-8")).hexdigest(),
        None,
        evidence_json(evidence),
        evidence_json({"sample_events": [ev.get("payload") for ev in ordered[:5]]}),
    )


def within(a: Dict[str, Any], b: Dict[str, Any], minutes: int) -> bool:
    return timedelta(0) <= parse_dt(b["ts"]) - parse_dt(a["ts"]) <= timedelta(minutes=minutes)


def detect_identity(events: List[Dict[str, Any]], app_key: str) -> List[Tuple[Any, ...]]:
    rows = []
    by_user: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    by_ip: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    for ev in events:
        if ev.get("telemetry_type") == "identity":
            if ev.get("user"):
                by_user[str(ev["user"])].append(ev)
            if ev.get("source_ip"):
                by_ip[str(ev["source_ip"])].append(ev)
    for user, group in by_user.items():
        failed = [ev for ev in group if ev.get("event_type") in {"login_failed", "mfa_failed"} or ev.get("result") == "failure"]
        services = {ev.get("event_source") for ev in failed}
        if len(failed) >= 5:
            rows.append(alert_row(app_key, "IDENTITY_MFA_FAILURE_BURST", user, failed[-10:], ["mfa_or_login_failure_burst", "identity"], 0.45))
        if len(failed) >= 4 and len(services) >= 2:
            rows.append(alert_row(app_key, "IDENTITY_FAILED_LOGIN_ACROSS_SERVICES", user, failed[-10:], ["failed_login_across_services", "multi_service"], 0.5))
        risky = [ev for ev in group if float(ev.get("risk_score") or 0) >= 0.7 and ev.get("event_type") == "login_success"]
        if risky:
            rows.append(alert_row(app_key, "IDENTITY_RISKY_IP_LOGIN", user, risky[-5:], ["risky_ip", "successful_login"], 0.5))
        sources = {ev.get("source_ip") for ev in group if ev.get("event_type") == "login_success" and ev.get("source_ip")}
        if len(sources) >= 2:
            latest = [ev for ev in group if ev.get("source_ip") in sources][-6:]
            rows.append(alert_row(app_key, "IDENTITY_IMPOSSIBLE_TRAVEL", user, latest, ["multiple_geo_or_source_ips", "short_time_window"], 0.4))
        priv = [ev for ev in group if "privilege" in str(ev.get("event_type")) or "role" in str(ev.get("action") or "").lower() or "admin" in str(ev.get("action") or "").lower()]
        if priv:
            rows.append(alert_row(app_key, "IDENTITY_PRIVILEGE_ESCALATION", user, priv[-5:], ["privilege_change", "identity"], 0.55))
    for ip, group in by_ip.items():
        users = {ev.get("user") for ev in group if ev.get("user")}
        if len(users) >= 3:
            rows.append(alert_row(app_key, "IDENTITY_UNUSUAL_LOGIN_SOURCE", ip, group[-12:], ["single_ip_many_users", "unusual_source"], 0.45))
    return rows


def detect_cloud_saas(events: List[Dict[str, Any]], app_key: str) -> List[Tuple[Any, ...]]:
    rows = []
    by_actor: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    for ev in events:
        if ev.get("telemetry_type") in {"cloud", "saas"}:
            by_actor[str(ev.get("user") or ev.get("cloud_account") or "unknown")].append(ev)
    for actor, group in by_actor.items():
        high = [ev for ev in group if float(ev.get("risk_score") or 0) >= 0.7]
        downloads = [ev for ev in group if "download" in str(ev.get("event_type") or ev.get("action") or "").lower()]
        object_access = [ev for ev in group if "object" in str(ev.get("event_type") or "").lower() or "getobject" in str(ev.get("action") or "").lower()]
        access_keys = [ev for ev in group if "access_key" in str(ev.get("event_type") or "").lower() or str(ev.get("action")) == "CreateAccessKey"]
        settings = [ev for ev in group if "security_setting" in str(ev.get("event_type") or "").lower() or "policy" in str(ev.get("action") or "").lower()]
        admin = [ev for ev in group if "admin" in str(ev.get("action") or ev.get("event_type") or "").lower()]
        if len(high) >= 3:
            rows.append(alert_row(app_key, "CLOUD_UNUSUAL_API_ACTIVITY", actor, high[-10:], ["high_risk_cloud_api_activity", "repeated"], 0.45))
        if len(object_access) >= 5:
            rows.append(alert_row(app_key, "CLOUD_SUSPICIOUS_OBJECT_ACCESS", actor, object_access[-10:], ["suspicious_object_access", "volume"], 0.45))
        if len(downloads) >= 10:
            rows.append(alert_row(app_key, "CLOUD_MASS_DOWNLOAD", actor, downloads[-20:], ["mass_download", "cloud_or_saas"], 0.5))
        if access_keys:
            rows.append(alert_row(app_key, "CLOUD_NEW_ACCESS_KEY", actor, access_keys[-5:], ["new_access_key_created"], 0.6))
        if settings:
            rows.append(alert_row(app_key, "CLOUD_SECURITY_SETTING_MODIFIED", actor, settings[-5:], ["security_setting_modified"], 0.6))
        if admin and any(ev.get("telemetry_type") == "saas" for ev in admin):
            rows.append(alert_row(app_key, "SAAS_UNUSUAL_ADMIN_ACTIVITY", actor, admin[-8:], ["saas_admin_activity"], 0.5))
    return rows


def detect_cross_domain(events: List[Dict[str, Any]], app_key: str) -> List[Tuple[Any, ...]]:
    rows = []
    by_user: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    by_domain: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    by_host: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    for ev in events:
        if ev.get("user"):
            by_user[str(ev["user"])].append(ev)
        if ev.get("domain"):
            by_domain[str(ev["domain"]).lower()].append(ev)
        if ev.get("host"):
            by_host[str(ev["host"])].append(ev)

    for user, group in by_user.items():
        emails = [ev for ev in group if ev.get("telemetry_type") == "email" and ("phish" in str(ev.get("event_type")) or float(ev.get("risk_score") or 0) >= 0.7)]
        logins = [ev for ev in group if ev.get("telemetry_type") == "identity" and ev.get("event_type") == "login_success"]
        endpoints = [ev for ev in group if ev.get("telemetry_type") == "endpoint" and ev.get("event_type") in {"process_created", "scheduled_task_created"}]
        for email in emails:
            linked = [email] + [ev for ev in logins if within(email, ev, 120)] + [ev for ev in endpoints if within(email, ev, 180)]
            if len({ev.get("telemetry_type") for ev in linked}) >= 3:
                rows.append(alert_row(app_key, "XDR_PHISHING_TO_ENDPOINT_EXECUTION", user, linked, ["phishing_email", "suspicious_login", "endpoint_execution"], 0.6))
                break
        priv = [ev for ev in group if "privilege" in str(ev.get("event_type")) or "role" in str(ev.get("action") or "").lower()]
        cloud = [ev for ev in group if ev.get("telemetry_type") == "cloud"]
        risky_login = [ev for ev in logins if float(ev.get("risk_score") or 0) >= 0.7]
        if risky_login and priv and cloud:
            rows.append(alert_row(app_key, "XDR_IMPOSSIBLE_LOGIN_PRIVILEGE_CLOUD", user, [risky_login[-1], priv[-1], cloud[-1]], ["risky_login", "privilege_change", "cloud_access"], 0.65))

    for domain, group in by_domain.items():
        ioc = [ev for ev in group if float(ev.get("risk_score") or 0) >= 0.7 or "ioc" in str(ev.get("event_type")).lower()]
        dns = [ev for ev in group if ev.get("telemetry_type") == "dns"]
        endpoint = [ev for ev in group if ev.get("telemetry_type") == "endpoint"]
        if ioc and dns and endpoint:
            rows.append(alert_row(app_key, "XDR_IOC_DNS_ENDPOINT_CHAIN", domain, [ioc[0], dns[-1], endpoint[-1]], ["ioc_hit", "dns_beacon_or_query", "endpoint_alert"], 0.6))

    for host, group in by_host.items():
        proxy = [ev for ev in group if ev.get("telemetry_type") in {"proxy", "firewall"} and float(ev.get("risk_score") or 0) >= 0.6]
        process = [ev for ev in group if ev.get("telemetry_type") == "endpoint" and ev.get("event_type") == "process_created"]
        if proxy and process:
            rows.append(alert_row(app_key, "XDR_PROXY_ENDPOINT_ESCALATION", host, [proxy[-1], process[-1]], ["proxy_anomaly", "endpoint_process", "incident_escalation_candidate"], 0.5))
    return rows


def expand_incidents(conn: Any, alert_rows: List[Tuple[Any, ...]]) -> None:
    if not alert_rows:
        return
    with conn.cursor() as cur:
        for row in alert_rows:
            alert_id, detected_at, alert_type, _detector, _version, severity, _ip, _req, actor_key, *_rest = row
            evidence = json.loads(row[15]) if isinstance(row[15], str) else {}
            incident_id = "xdr-" + hashlib.sha256(f"{alert_type}|{actor_key}|{detected_at}".encode("utf-8")).hexdigest()[:24]
            cur.execute(
                """
                INSERT INTO security_incidents (
                    incident_id, title, status, severity, confidence, first_seen_at, last_seen_at,
                    affected_entities, timeline, mitre_mapping, metadata, xdr_domains, involved_users,
                    involved_hosts, involved_cloud_accounts, involved_email_artifacts, involved_external_ips,
                    xdr_kill_chain_summary, created_at, updated_at
                ) VALUES (
                    %s, %s, 'open', %s, %s, %s, %s,
                    %s::jsonb, %s::jsonb, %s::jsonb, %s::jsonb, %s::jsonb, %s::jsonb,
                    %s::jsonb, %s::jsonb, %s::jsonb, %s::jsonb,
                    %s::jsonb, now(), now()
                )
                ON CONFLICT (incident_id) DO UPDATE SET
                    last_seen_at=excluded.last_seen_at,
                    severity=excluded.severity,
                    confidence=GREATEST(security_incidents.confidence, excluded.confidence),
                    timeline=excluded.timeline,
                    metadata=excluded.metadata,
                    updated_at=now()
                """,
                (
                    incident_id,
                    f"XDR correlation: {alert_type}",
                    severity,
                    evidence.get("confidence", {}).get("score"),
                    row[10],
                    row[11],
                    json.dumps(evidence.get("involved_users", []) + evidence.get("involved_hosts", []) + evidence.get("involved_external_ips", [])),
                    json.dumps(evidence.get("evidence_chain", [])),
                    json.dumps(evidence.get("mitre_attack", [])),
                    json.dumps({"source": "xdr_correlation", "alert_type": alert_type}),
                    json.dumps(evidence.get("xdr_domains", [])),
                    json.dumps(evidence.get("involved_users", [])),
                    json.dumps(evidence.get("involved_hosts", [])),
                    json.dumps(evidence.get("involved_cloud_accounts", [])),
                    json.dumps(evidence.get("involved_email_artifacts", [])),
                    json.dumps(evidence.get("involved_external_ips", [])),
                    json.dumps(evidence.get("xdr_kill_chain_summary", {})),
                ),
            )
            cur.execute("UPDATE security_alerts SET incident_id=%s WHERE alert_id=%s", (incident_id, alert_id))
            cur.execute(
                "INSERT INTO security_incident_alerts (incident_id, alert_id, created_at, updated_at) VALUES (%s, %s, now(), now()) ON CONFLICT DO NOTHING",
                (incident_id, alert_id),
            )
    conn.commit()


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1
    driver, conn = connect_db(dsn)
    conn.autocommit = False
    try:
        events = fetch_events(conn, args.minutes)
        rows: List[Tuple[Any, ...]] = []
        rows.extend(detect_identity(events, args.app_key))
        rows.extend(detect_cloud_saas(events, args.app_key))
        rows.extend(detect_cross_domain(events, args.app_key))
        if not args.dry_run:
            insert_alerts(conn, driver, rows)
            expand_incidents(conn, rows)
    finally:
        conn.close()
    print(f"xdr_events={len(events)}")
    print(f"xdr_alerts_attempted={len(rows)}")
    if args.dry_run:
        print("dry_run=1")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
