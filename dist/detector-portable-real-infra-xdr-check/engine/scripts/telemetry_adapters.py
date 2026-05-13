#!/usr/bin/env python3
"""
Normalize real telemetry sources into the existing telemetry_events JSONL schema.

Supported adapters:
- sysmon-json / sysmon-xml
- zeek-conn / zeek-dns / zeek-http
- suricata-eve
- windows-security-json / windows-security-xml
- linux-auth / linux-auditd
- m365-audit / m365-signin
- google-workspace-audit
- aws-cloudtrail
- azure-signin / azure-audit
- firewall-proxy-jsonl
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional


def iso_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def to_iso(value: Any) -> str:
    text = str(value or "").strip()
    if not text:
        return iso_now()
    for fmt in ("%Y-%m-%dT%H:%M:%S.%fZ", "%Y-%m-%dT%H:%M:%SZ", "%Y-%m-%d %H:%M:%S"):
        try:
            return datetime.strptime(text[:26] if "%f" in fmt else text[:19], fmt).replace(tzinfo=timezone.utc).isoformat().replace("+00:00", "Z")
        except ValueError:
            pass
    try:
        dt = datetime.fromtimestamp(float(text), tz=timezone.utc)
        return dt.isoformat().replace("+00:00", "Z")
    except Exception:
        return text.replace("+00:00", "Z") if "T" in text else iso_now()


def stable_event_id(source: str, payload: Dict[str, Any]) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(f"{source}|{raw}".encode("utf-8")).hexdigest()[:40]


def clean(value: Any) -> Optional[str]:
    if value is None:
        return None
    text = str(value).strip()
    return text or None


def normalize(
    source: str,
    ts: Any,
    telemetry_type: str,
    event_type: str,
    host_id: Any,
    payload: Dict[str, Any],
    **fields: Any,
) -> Dict[str, Any]:
    ev: Dict[str, Any] = {
        "schema_version": 1,
        "ts": to_iso(ts),
        "telemetry_type": telemetry_type,
        "event_type": event_type,
        "host_id": clean(host_id) or "unknown-host",
    }
    for key in [
        "src_ip", "dst_ip", "protocol", "process_name", "user_name_hash", "query",
        "user", "host", "source_ip", "destination_ip", "domain", "file_hash",
        "email_sender", "email_recipient", "cloud_account", "action", "result",
        "event_source",
    ]:
        if clean(fields.get(key)) is not None:
            ev[key] = clean(fields[key])
    if fields.get("risk_score") not in (None, ""):
        try:
            ev["risk_score"] = max(0.0, min(1.0, float(fields["risk_score"])))
        except (TypeError, ValueError):
            pass
    if fields.get("dst_port") not in (None, ""):
        try:
            ev["dst_port"] = int(fields["dst_port"])
        except (TypeError, ValueError):
            pass
    ev["source_adapter"] = source
    ev.setdefault("event_source", source)
    ev["raw"] = payload
    ev["event_id"] = stable_event_id(source, ev)
    return ev


def event_type_from_sysmon(event_id: str, data: Dict[str, Any]) -> str:
    mapping = {
        "1": "process_created",
        "3": "connection_attempt",
        "11": "file_created",
        "12": "registry_object_created",
        "13": "registry_run_key_modified",
        "22": "dns_query",
    }
    image = str(data.get("Image") or data.get("ProcessName") or "").lower()
    target = str(data.get("TargetObject") or "").lower()
    if event_id == "1" and ("schtasks" in image or "sc.exe" in image):
        return "scheduled_task_created" if "schtasks" in image else "service_created"
    if event_id in {"12", "13"} and "\\run" in target:
        return "registry_run_key_modified"
    return mapping.get(str(event_id), f"sysmon_event_{event_id}")


def iter_json_lines(path: Path) -> Iterable[Dict[str, Any]]:
    with path.open("r", encoding="utf-8", errors="replace") as f:
        for line in f:
            text = line.strip()
            if not text:
                continue
            data = json.loads(text)
            if isinstance(data, dict):
                yield data


def parse_sysmon_json(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        data = row.get("EventData") if isinstance(row.get("EventData"), dict) else row
        event_id = str(row.get("EventID") or row.get("EventId") or data.get("EventID") or "")
        event_type = event_type_from_sysmon(event_id, data)
        telemetry_type = "dns" if event_type == "dns_query" else "network" if event_type == "connection_attempt" else "endpoint"
        yield normalize(
            "sysmon-json",
            row.get("UtcTime") or row.get("TimeCreated") or data.get("UtcTime"),
            telemetry_type,
            event_type,
            row.get("Computer") or data.get("Computer") or data.get("Hostname"),
            row,
            src_ip=data.get("SourceIp"),
            dst_ip=data.get("DestinationIp"),
            dst_port=data.get("DestinationPort"),
            protocol=data.get("Protocol"),
            process_name=Path(str(data.get("Image") or data.get("ProcessName") or "")).name,
            query=data.get("QueryName"),
        )


def parse_sysmon_xml(path: Path) -> Iterable[Dict[str, Any]]:
    root = ET.parse(path).getroot()
    ns = {"e": "http://schemas.microsoft.com/win/2004/08/events/event"}
    for ev in root.findall(".//e:Event", ns) or root.findall(".//Event"):
        sys = ev.find("e:System", ns) or ev.find("System")
        event_id = ""
        computer = ""
        ts = ""
        if sys is not None:
            event_id = (sys.findtext("e:EventID", default="", namespaces=ns) or sys.findtext("EventID", default="")).strip()
            computer = sys.findtext("e:Computer", default="", namespaces=ns) or sys.findtext("Computer", default="")
            time_node = sys.find("e:TimeCreated", ns) or sys.find("TimeCreated")
            ts = time_node.attrib.get("SystemTime", "") if time_node is not None else ""
        data: Dict[str, Any] = {}
        for node in ev.findall(".//e:Data", ns) or ev.findall(".//Data"):
            data[node.attrib.get("Name", "")] = node.text or ""
        event_type = event_type_from_sysmon(event_id, data)
        telemetry_type = "dns" if event_type == "dns_query" else "network" if event_type == "connection_attempt" else "endpoint"
        yield normalize(
            "sysmon-xml",
            ts or data.get("UtcTime"),
            telemetry_type,
            event_type,
            computer or data.get("Computer"),
            data,
            src_ip=data.get("SourceIp"),
            dst_ip=data.get("DestinationIp"),
            dst_port=data.get("DestinationPort"),
            protocol=data.get("Protocol"),
            process_name=Path(str(data.get("Image") or "")).name,
            query=data.get("QueryName"),
        )


def zeek_rows(path: Path) -> Iterable[Dict[str, str]]:
    fields: List[str] = []
    with path.open("r", encoding="utf-8", errors="replace") as f:
        for line in f:
            if line.startswith("#fields"):
                fields = line.strip().split("\t")[1:]
                continue
            if line.startswith("#") or not line.strip():
                continue
            values = line.rstrip("\n").split("\t")
            if fields:
                yield dict(zip(fields, values))
            else:
                yield next(csv.DictReader([line], delimiter="\t"))


def parse_zeek(path: Path, kind: str) -> Iterable[Dict[str, Any]]:
    for row in zeek_rows(path):
        host = row.get("uid") or row.get("id.orig_h") or "zeek"
        if kind == "dns":
            yield normalize("zeek-dns", row.get("ts"), "dns", "dns_query", host, row, src_ip=row.get("id.orig_h"), query=row.get("query"))
        elif kind == "http":
            yield normalize("zeek-http", row.get("ts"), "network", "http_request", host, row, src_ip=row.get("id.orig_h"), dst_ip=row.get("id.resp_h"), dst_port=row.get("id.resp_p"), protocol="tcp")
        else:
            yield normalize("zeek-conn", row.get("ts"), "network", "connection_attempt", host, row, src_ip=row.get("id.orig_h"), dst_ip=row.get("id.resp_h"), dst_port=row.get("id.resp_p"), protocol=row.get("proto"))


def parse_suricata_eve(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        kind = row.get("event_type")
        if kind == "dns":
            dns = row.get("dns") if isinstance(row.get("dns"), dict) else {}
            yield normalize("suricata-eve", row.get("timestamp"), "dns", "dns_query", row.get("host") or row.get("src_ip"), row, src_ip=row.get("src_ip"), dst_ip=row.get("dest_ip"), query=dns.get("rrname"))
        elif kind in {"alert", "http", "flow", "tls"}:
            yield normalize("suricata-eve", row.get("timestamp"), "network", f"suricata_{kind}", row.get("host") or row.get("src_ip"), row, src_ip=row.get("src_ip"), dst_ip=row.get("dest_ip"), dst_port=row.get("dest_port"), protocol=row.get("proto"))


def parse_windows_security_json(path: Path) -> Iterable[Dict[str, Any]]:
    mapping = {"4624": "login_success", "4625": "login_failed", "4688": "process_created", "4698": "scheduled_task_created", "7045": "service_created"}
    for row in iter_json_lines(path):
        data = row.get("EventData") if isinstance(row.get("EventData"), dict) else row
        event_id = str(row.get("EventID") or row.get("EventId") or "")
        yield normalize(
            "windows-security-json",
            row.get("TimeCreated") or data.get("TimeCreated"),
            "endpoint",
            mapping.get(event_id, f"windows_security_{event_id}"),
            row.get("Computer") or data.get("Computer"),
            row,
            src_ip=data.get("IpAddress") or data.get("SourceNetworkAddress"),
            process_name=Path(str(data.get("NewProcessName") or data.get("ProcessName") or "")).name,
        )


def parse_windows_security_xml(path: Path) -> Iterable[Dict[str, Any]]:
    for ev in parse_sysmon_xml(path):
        ev["source_adapter"] = "windows-security-xml"
        yield ev


def parse_linux_auth(path: Path) -> Iterable[Dict[str, Any]]:
    ssh_re = re.compile(r"(?P<status>Failed|Accepted).* for (invalid user )?(?P<user>\S+) from (?P<src>\S+)")
    with path.open("r", encoding="utf-8", errors="replace") as f:
        for line in f:
            m = ssh_re.search(line)
            if not m:
                continue
            event_type = "login_failed" if m.group("status") == "Failed" else "login_success"
            user_hash = hashlib.sha256(m.group("user").encode("utf-8")).hexdigest()
            yield normalize("linux-auth", iso_now(), "endpoint", event_type, "linux-host", {"line": line.strip()}, src_ip=m.group("src"), process_name="sshd", user_name_hash=user_hash)


def parse_linux_auditd(path: Path) -> Iterable[Dict[str, Any]]:
    with path.open("r", encoding="utf-8", errors="replace") as f:
        for line in f:
            event_type = "auditd_event"
            if "SYSCALL" in line and "execve" in line:
                event_type = "process_created"
            if "USER_AUTH" in line and "res=failed" in line:
                event_type = "login_failed"
            yield normalize("linux-auditd", iso_now(), "endpoint", event_type, "linux-host", {"line": line.strip()}, process_name="auditd")


def parse_m365_audit(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        operation = clean(row.get("Operation") or row.get("operation") or row.get("Activity") or row.get("activity")) or "m365_activity"
        workload = str(row.get("Workload") or row.get("workload") or "m365").lower()
        event_type = "email_delivered" if workload in {"exchange", "defender for office 365"} and "deliver" in operation.lower() else f"m365_{operation}".lower().replace(" ", "_")
        yield normalize(
            "m365-audit",
            row.get("CreationTime") or row.get("TimeGenerated") or row.get("createdDateTime"),
            "email" if workload in {"exchange", "defender for office 365"} else "saas",
            event_type,
            row.get("DeviceName") or row.get("ClientIP") or "m365",
            row,
            user=row.get("UserId") or row.get("UserKey"),
            source_ip=row.get("ClientIP") or row.get("IPAddress"),
            email_sender=row.get("Sender") or row.get("SenderFromAddress"),
            email_recipient=row.get("Recipient") or row.get("Recipients"),
            domain=row.get("Domain") or row.get("SenderFromDomain"),
            action=operation,
            result=row.get("ResultStatus") or row.get("Status"),
            event_source="microsoft365",
            risk_score=row.get("RiskScore") or row.get("riskScore"),
        )


def parse_m365_signin(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        risk = row.get("riskLevelAggregated") or row.get("riskLevelDuringSignIn") or "none"
        score = {"none": 0.0, "low": 0.25, "medium": 0.55, "high": 0.85}.get(str(risk).lower(), row.get("riskScore"))
        result = row.get("status", {}).get("errorCode") if isinstance(row.get("status"), dict) else row.get("result")
        event_type = "login_success" if str(result or "0") in {"0", "success", "Success"} else "login_failed"
        yield normalize(
            "m365-signin",
            row.get("createdDateTime") or row.get("TimeGenerated"),
            "identity",
            event_type,
            row.get("deviceDetail", {}).get("displayName") if isinstance(row.get("deviceDetail"), dict) else "azure-ad",
            row,
            user=row.get("userPrincipalName") or row.get("UserPrincipalName"),
            source_ip=row.get("ipAddress") or row.get("IPAddress"),
            cloud_account=row.get("tenantId") or row.get("resourceTenantId"),
            action="signin",
            result="success" if event_type == "login_success" else "failure",
            event_source="microsoft365-signin",
            risk_score=score,
        )


def parse_google_workspace_audit(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        event = row.get("events", [{}])[0] if isinstance(row.get("events"), list) and row.get("events") else {}
        name = clean(event.get("name") or row.get("eventName")) or "google_workspace_event"
        app = str(row.get("applicationName") or "google_workspace").lower()
        yield normalize(
            "google-workspace-audit",
            row.get("id", {}).get("time") if isinstance(row.get("id"), dict) else row.get("time"),
            "identity" if app in {"login", "token"} else "saas",
            f"google_{name}".lower().replace(" ", "_"),
            row.get("actor", {}).get("email") if isinstance(row.get("actor"), dict) else "google-workspace",
            row,
            user=row.get("actor", {}).get("email") if isinstance(row.get("actor"), dict) else row.get("user"),
            source_ip=row.get("ipAddress"),
            action=name,
            result=row.get("result") or event.get("type"),
            event_source="google-workspace",
            risk_score=row.get("riskScore"),
        )


def parse_aws_cloudtrail(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        records = row.get("Records") if isinstance(row.get("Records"), list) else [row]
        for rec in records:
            event_name = clean(rec.get("eventName")) or "aws_api_call"
            user_identity = rec.get("userIdentity") if isinstance(rec.get("userIdentity"), dict) else {}
            account = clean(rec.get("recipientAccountId") or user_identity.get("accountId"))
            event_type = "new_access_key_created" if event_name == "CreateAccessKey" else "security_setting_modified" if event_name in {"PutBucketPolicy", "AuthorizeSecurityGroupIngress", "UpdateAssumeRolePolicy"} else f"aws_{event_name}".lower()
            yield normalize(
                "aws-cloudtrail",
                rec.get("eventTime"),
                "cloud",
                event_type,
                account or "aws",
                rec,
                user=user_identity.get("arn") or user_identity.get("userName"),
                source_ip=rec.get("sourceIPAddress"),
                cloud_account=account,
                action=event_name,
                result="failure" if rec.get("errorCode") else "success",
                event_source="aws-cloudtrail",
                risk_score=0.8 if event_type in {"new_access_key_created", "security_setting_modified"} else 0.2,
            )


def parse_azure_audit(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        op = clean(row.get("operationName") or row.get("OperationName") or row.get("activityDisplayName")) or "azure_activity"
        event_type = "privilege_escalation" if "role" in op.lower() or "administrator" in op.lower() else f"azure_{op}".lower().replace(" ", "_")
        yield normalize(
            "azure-audit",
            row.get("time") or row.get("TimeGenerated") or row.get("activityDateTime"),
            "cloud",
            event_type,
            row.get("tenantId") or "azure",
            row,
            user=row.get("caller") or row.get("initiatedBy", {}).get("user", {}).get("userPrincipalName") if isinstance(row.get("initiatedBy"), dict) else row.get("caller"),
            source_ip=row.get("callerIpAddress") or row.get("ipAddress"),
            cloud_account=row.get("tenantId") or row.get("subscriptionId"),
            action=op,
            result=row.get("result") or row.get("resultType"),
            event_source="azure-audit",
            risk_score=0.75 if event_type == "privilege_escalation" else 0.25,
        )


def parse_azure_signin(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        risk = row.get("riskLevelAggregated") or row.get("riskLevelDuringSignIn") or "none"
        score = {"none": 0.0, "low": 0.25, "medium": 0.55, "high": 0.85}.get(str(risk).lower(), row.get("riskScore"))
        status = row.get("status") if isinstance(row.get("status"), dict) else {}
        event_type = "login_success" if str(status.get("errorCode", row.get("result", "0"))) in {"0", "success", "Success"} else "login_failed"
        yield normalize(
            "azure-signin",
            row.get("createdDateTime") or row.get("TimeGenerated"),
            "identity",
            event_type,
            row.get("appDisplayName") or "azure-ad",
            row,
            user=row.get("userPrincipalName"),
            source_ip=row.get("ipAddress"),
            cloud_account=row.get("tenantId"),
            action="signin",
            result="success" if event_type == "login_success" else "failure",
            event_source="azure-signin",
            risk_score=score,
        )


def parse_firewall_proxy_jsonl(path: Path) -> Iterable[Dict[str, Any]]:
    for row in iter_json_lines(path):
        action = clean(row.get("action") or row.get("event.action") or row.get("verdict")) or "network_request"
        url = clean(row.get("url") or row.get("request_url") or row.get("uri"))
        domain = clean(row.get("domain") or row.get("host") or row.get("server_name"))
        yield normalize(
            "firewall-proxy-jsonl",
            row.get("timestamp") or row.get("@timestamp") or row.get("time"),
            "proxy" if url or domain else "firewall",
            "proxy_request" if url or domain else "firewall_connection",
            row.get("device") or row.get("src_ip") or "firewall-proxy",
            row,
            src_ip=row.get("src_ip") or row.get("source_ip"),
            dst_ip=row.get("dst_ip") or row.get("destination_ip"),
            dst_port=row.get("dst_port") or row.get("destination_port"),
            protocol=row.get("protocol"),
            user=row.get("user") or row.get("username"),
            source_ip=row.get("src_ip") or row.get("source_ip"),
            destination_ip=row.get("dst_ip") or row.get("destination_ip"),
            domain=domain,
            action=action,
            result=row.get("result") or row.get("status"),
            event_source="firewall-proxy",
            risk_score=row.get("risk_score") or row.get("risk"),
        )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Normalize real telemetry into detector JSONL schema")
    parser.add_argument("--adapter", required=True, choices=["sysmon-json", "sysmon-xml", "zeek-conn", "zeek-dns", "zeek-http", "suricata-eve", "windows-security-json", "windows-security-xml", "linux-auth", "linux-auditd", "m365-audit", "m365-signin", "google-workspace-audit", "aws-cloudtrail", "azure-signin", "azure-audit", "firewall-proxy-jsonl"])
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", default="storage/logs/telemetry_normalized.jsonl")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    in_path = Path(args.input)
    out_path = Path(args.output)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    parsers = {
        "sysmon-json": parse_sysmon_json,
        "sysmon-xml": parse_sysmon_xml,
        "zeek-conn": lambda p: parse_zeek(p, "conn"),
        "zeek-dns": lambda p: parse_zeek(p, "dns"),
        "zeek-http": lambda p: parse_zeek(p, "http"),
        "suricata-eve": parse_suricata_eve,
        "windows-security-json": parse_windows_security_json,
        "windows-security-xml": parse_windows_security_xml,
        "linux-auth": parse_linux_auth,
        "linux-auditd": parse_linux_auditd,
        "m365-audit": parse_m365_audit,
        "m365-signin": parse_m365_signin,
        "google-workspace-audit": parse_google_workspace_audit,
        "aws-cloudtrail": parse_aws_cloudtrail,
        "azure-signin": parse_azure_signin,
        "azure-audit": parse_azure_audit,
        "firewall-proxy-jsonl": parse_firewall_proxy_jsonl,
    }
    count = 0
    with out_path.open("w", encoding="utf-8") as out:
        for event in parsers[args.adapter](in_path):
            out.write(json.dumps(event, separators=(",", ":"), ensure_ascii=False) + "\n")
            count += 1
    print(f"adapter={args.adapter}")
    print(f"normalized={count}")
    print(f"output={out_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
