#!/usr/bin/env python3
"""Check source events against bounded raw, normalized, and DNS advisory Kafka ranges."""

from __future__ import annotations

import argparse
from collections import Counter
from contextlib import closing
from dataclasses import asdict, dataclass
from datetime import datetime, timedelta, timezone
import hashlib
import http.client
import json
from pathlib import Path
import re
import sys
import time
from urllib.parse import urlencode


MAX_RECORDS = 10_000
MAX_CAPTURE_BYTES = 8 * 1024 * 1024
MAX_PAGE_BYTES = 2 * 1024 * 1024
MAX_ARRIVAL_DELAY = timedelta(minutes=5)
TOKEN = re.compile(r"[a-z0-9][a-z0-9._-]{0,63}")
DNS_CLIENT_ALIAS = re.compile(r"dns-client-[a-f0-9]{32}")
DNS_ADVISORY_TOPIC = "xdr.alerts.shadow.network"
DNS_ADVISORY_RULES = {
    "dns_txt_query_pattern": ("dns_query_type", "TXT", 2, "txt_count"),
    "repeated_nxdomain": ("dns_response_code", "NXDOMAIN", 5, "nxdomain_count"),
}
DOMAINS = {
    "endpoint": {"endpoint", "sysmon", "powershell", "security_event"},
    "network": {"dns", "proxy", "firewall"},
}


class InvalidEvidence(ValueError):
    pass


@dataclass(frozen=True)
class OffsetRange:
    partition: int
    start: int
    end_exclusive: int


@dataclass(frozen=True)
class Scope:
    source_id: str
    tenant_id: str
    telemetry_type: str
    domain: str
    start: datetime
    end: datetime

    def includes(self, when: datetime) -> bool:
        return self.start <= when < self.end

    def matches(self, event: dict) -> bool:
        return event.get("tenant_id") == self.tenant_id and event.get("telemetry_type") == self.telemetry_type


@dataclass(frozen=True)
class CaptureRecord:
    payload_sha256: str
    trace_id: str
    observed_timestamp: str
    dns_client_alias: str | None = None
    dns_query_type: str | None = None
    dns_response_code: str | None = None


def unique_keys(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidEvidence("Duplicate field in JSON")
        result[key] = value
    return result


def reject_constant(_):
    raise InvalidEvidence("Non-finite JSON value")


def strict_json(raw):
    try:
        return json.loads(raw, object_pairs_hook=unique_keys, parse_constant=reject_constant)
    except (ValueError, RecursionError) as exc:
        raise InvalidEvidence("Malformed or ambiguous JSON") from exc


def read_bounded(path: Path, maximum: int) -> bytes:
    with path.open("rb") as stream:
        data = stream.read(maximum + 1)
    if not data or len(data) > maximum:
        raise InvalidEvidence("Evidence file is empty or exceeds its size limit")
    return data


def valid_id(value) -> bool:
    return (isinstance(value, str) and 0 < len(value.encode("utf-8")) <= 255
            and value == value.strip() and "\x00" not in value)


def utc_timestamp(value) -> datetime:
    if not isinstance(value, str) or "T" not in value:
        raise InvalidEvidence("Expected timezone-aware ISO-8601 timestamp")
    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if result.tzinfo is None:
            raise ValueError
        return result.astimezone(timezone.utc)
    except (ValueError, OverflowError) as exc:
        raise InvalidEvidence("Invalid or timezone-naive evidence timestamp") from exc


def parse_range(value: str) -> OffsetRange:
    if not re.fullmatch(r"[0-9]{1,10}:[0-9]{1,19}:[0-9]{1,19}", value):
        raise InvalidEvidence("Range must be PARTITION:START:END_EXCLUSIVE")
    partition, start, end = map(int, value.split(":"))
    if partition > 2**31 - 1 or end > 2**63 - 1 or not 1 <= end - start <= MAX_RECORDS:
        raise InvalidEvidence("Offset range is invalid or exceeds the record limit")
    return OffsetRange(partition, start, end)


def read_ranges(values: list[str]) -> list[OffsetRange]:
    if len(values) > 8:
        raise InvalidEvidence("Use at most eight partitions per topic")
    ranges = [parse_range(value) for value in values]
    if (len({span.partition for span in ranges}) != len(ranges)
            or sum(span.end_exclusive - span.start for span in ranges) > MAX_RECORDS):
        raise InvalidEvidence("Use distinct partitions within the bounded record limit")
    return ranges


def validate_topic(topic: str) -> None:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,248}", topic):
        raise InvalidEvidence("Invalid broker topic name")


def read_scope(args) -> tuple[Scope, str]:
    registry_bytes = read_bounded(args.registry, 1024 * 1024)
    registry = strict_json(registry_bytes)
    if not isinstance(registry, list) or not 1 <= len(registry) <= 256:
        raise InvalidEvidence("Invalid source registry")
    by_id = {}
    pins = set()
    for row in registry:
        if not isinstance(row, dict) or set(row) != {"source_id", "tenant_id", "client_cert_sha256", "telemetry_types"}:
            raise InvalidEvidence("Invalid source registry entry")
        source, pin, types = row["source_id"], row["client_cert_sha256"], row["telemetry_types"]
        if (not isinstance(source, str) or not TOKEN.fullmatch(source) or source in by_id
                or not valid_id(row["tenant_id"]) or not isinstance(pin, str)
                or not re.fullmatch(r"[a-fA-F0-9]{64}", pin) or pin.lower() in pins
                or not isinstance(types, list) or not types
                or any(not isinstance(kind, str) or not TOKEN.fullmatch(kind) for kind in types)
                or len(set(types)) != len(types)):
            raise InvalidEvidence("Invalid or duplicate source registry identity")
        by_id[source] = row
        pins.add(pin.lower())
    row = by_id.get(args.source_id)
    if row is None or args.telemetry_type not in row["telemetry_types"] or args.telemetry_type not in DOMAINS[args.domain]:
        raise InvalidEvidence("Requested source, domain and telemetry type do not agree with the registry")

    start, end = utc_timestamp(args.window_start), utc_timestamp(args.window_end)
    if not timedelta(0) < end - start <= timedelta(hours=24):
        raise InvalidEvidence("Observation window must be positive and at most 24 hours")
    scope = Scope(args.source_id, row["tenant_id"], args.telemetry_type, args.domain, start, end)
    return scope, hashlib.sha256(registry_bytes).hexdigest()


def rehearsal(event: dict) -> bool:
    return (event.get("synthetic") is True or event.get("is_simulated") is True
            or event.get("shadow_evidence_origin") == "rehearsal"
            or any(event.get(field) for field in ("demo_run_id", "scenario_id"))
            or "xdr-scenario-runner" in (event.get("event_source"), event.get("source_adapter"), event.get("vendor")))


def normalized_json(value):
    if isinstance(value, dict):
        return {key: normalized_json(item) for key, item in value.items()}
    if isinstance(value, list):
        return [normalized_json(item) for item in value]
    if type(value) is float and value.is_integer():
        return int(value)
    return value


def payload_digest(event: dict) -> str:
    # These two fields are regenerated by the gateway. Everything else must match.
    body = {key: value for key, value in event.items() if key not in {"traceparent", "capture_admission"}}
    try:
        wire = json.dumps(normalized_json(body), ensure_ascii=True, sort_keys=True, separators=(",", ":"), allow_nan=False)
    except (ValueError, RecursionError) as exc:
        raise InvalidEvidence("Cannot represent capture contents as finite JSON") from exc
    return hashlib.sha256(wire.encode("ascii")).hexdigest()


def read_capture(path: Path, scope: Scope) -> tuple[dict[str, CaptureRecord], str]:
    data = read_bounded(path, MAX_CAPTURE_BYTES)
    expected = {}
    for line in data.splitlines():
        if not line.strip():
            continue
        event = strict_json(line)
        if not isinstance(event, dict) or not scope.matches(event) or not valid_id(event.get("event_id")):
            raise InvalidEvidence("Independent capture record is outside the registered source scope")
        if not valid_id(event.get("trace_id")) or rehearsal(event) or "capture_admission" in event:
            raise InvalidEvidence("Capture must be pre-ingestion, traced, and not marked rehearsal")
        event_id = event["event_id"]
        observed_at = utc_timestamp(event.get("ts"))
        if event_id in expected or len(expected) >= MAX_RECORDS or not scope.includes(observed_at):
            raise InvalidEvidence("Duplicate, out-of-window, or excessive capture records")
        host_id = event.get("host_id")
        client_alias = (host_id if scope.telemetry_type == "dns" and isinstance(host_id, str)
                        and DNS_CLIENT_ALIAS.fullmatch(host_id) else None)
        query_type, response_code = event.get("query_type"), event.get("response_code")
        expected[event_id] = CaptureRecord(
            payload_digest(event), event["trace_id"], event["ts"], client_alias,
            query_type.upper() if isinstance(query_type, str) else None,
            response_code.upper() if isinstance(response_code, str) else None,
        )
    if not expected:
        raise InvalidEvidence("No eligible records in independent capture")
    return expected, hashlib.sha256(data).hexdigest()


def broker_records(topic: str, ranges: list[OffsetRange], *, port: int = 8082):
    """Use direct partition GETs, never the consumer group API."""
    client = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
    deadline = time.monotonic() + 60
    try:
        for span in ranges:
            offset = span.start
            while offset < span.end_exclusive:
                if time.monotonic() >= deadline:
                    raise InvalidEvidence("Broker read exceeded the evidence deadline")
                query = urlencode({"offset": offset, "timeout": 1000, "max_bytes": 262144})
                client.request("GET", f"/topics/{topic}/partitions/{span.partition}/records?{query}",
                               headers={"Accept": "application/vnd.kafka.json.v2+json"})
                with client.getresponse() as response:
                    if response.status != 200:
                        raise InvalidEvidence(f"Partition read failed with HTTP {response.status}")
                    raw = response.read(MAX_PAGE_BYTES + 1)
                if len(raw) > MAX_PAGE_BYTES:
                    raise InvalidEvidence("Broker page exceeds the permitted size")
                rows = strict_json(raw)
                if not isinstance(rows, list) or not rows:
                    raise InvalidEvidence("Broker did not return the requested offset range")
                for row in rows:
                    if offset == span.end_exclusive:
                        break
                    if (not isinstance(row, dict) or row.get("topic") != topic
                            or type(row.get("partition")) is not int or row["partition"] != span.partition
                            or type(row.get("offset")) is not int or row["offset"] != offset
                            or not isinstance(row.get("value"), dict)):
                        raise InvalidEvidence("Broker returned a gap or invalid record in the requested range")
                    offset += 1
                    yield row["value"]
    finally:
        client.close()


def admitted_by(event: dict, scope: Scope) -> bool:
    stamp = event.get("capture_admission")
    return isinstance(stamp, dict) and stamp.get("method") == "verified_mtls" and stamp.get("source_id") == scope.source_id


def reconcile(expected: dict[str, CaptureRecord], records, scope: Scope) -> dict:
    seen = Counter()
    outcomes = {}
    unrelated_scope = set()
    unlisted = 0
    for event in records:
        event_id = event.get("event_id")
        if not valid_id(event_id):
            if scope.matches(event) and admitted_by(event, scope):
                raise InvalidEvidence("Admitted broker record is missing a valid event ID")
            continue
        if not scope.matches(event):
            if event_id in expected:
                unrelated_scope.add(event_id)
            continue
        if event_id not in expected:
            if admitted_by(event, scope) and not rehearsal(event) and scope.includes(utc_timestamp(event.get("ts"))):
                unlisted += 1
            continue
        seen[event_id] += 1
        if rehearsal(event):
            outcomes[event_id] = "rehearsal"
        elif not admitted_by(event, scope):
            outcomes[event_id] = "missing_admission"
        elif payload_digest(event) != expected[event_id].payload_sha256:
            outcomes[event_id] = "payload_mismatch"
        else:
            received = utc_timestamp(event["capture_admission"].get("received_at"))
            observed = utc_timestamp(event["ts"])
            timely = scope.includes(received) and timedelta(0) <= received - observed <= MAX_ARRIVAL_DELAY
            outcomes[event_id] = "matched" if timely else "arrival_window_mismatch"
    counts = Counter()
    for event_id in expected:
        if seen[event_id] > 1:
            counts["duplicate"] += 1
        elif event_id in outcomes:
            counts[outcomes[event_id]] += 1
        else:
            counts["scope_mismatch" if event_id in unrelated_scope else "missing"] += 1
    counts["unlisted"] = unlisted
    return dict(counts)


def reconcile_normalized(expected: dict[str, CaptureRecord], records, scope: Scope, *, verify_dns_values=False) -> dict:
    seen = Counter()
    outcomes = {}
    unrelated_scope = set()
    for event in records:
        raw_id = event.get("raw_event_id")
        normalized_id = event.get("normalized_event_id")
        # Check either ID so a row with only one intact ID is a mismatch, not missing.
        ids = {value for value in (raw_id, normalized_id) if isinstance(value, str) and value in expected}
        if not scope.matches(event):
            unrelated_scope.update(ids)
            continue
        for event_id in ids:
            seen[event_id] += 1
            source = expected[event_id]
            if rehearsal(event):
                outcomes[event_id] = "rehearsal"
            elif raw_id != event_id or normalized_id != event_id or event.get("trace_id") != source.trace_id:
                outcomes[event_id] = "lineage_mismatch"
            elif event.get("ts") != source.observed_timestamp:
                outcomes[event_id] = "timestamp_mismatch"
            elif source.dns_client_alias is not None and event.get("host_id") != source.dns_client_alias:
                outcomes[event_id] = "client_alias_mismatch"
            elif verify_dns_values and (event.get("query_type") != source.dns_query_type
                                        or event.get("response_code") != source.dns_response_code):
                outcomes[event_id] = "dns_value_mismatch"
            else:
                outcomes[event_id] = "matched"
    counts = Counter()
    for event_id in expected:
        if seen[event_id] > 1:
            counts["duplicate"] += 1
        elif event_id in outcomes:
            counts[outcomes[event_id]] += 1
        else:
            counts["scope_mismatch" if event_id in unrelated_scope else "missing"] += 1
    return dict(counts)


def dns_candidate_windows(expected: dict[str, CaptureRecord], scope: Scope, rule: str) -> dict:
    field, value, minimum, _ = DNS_ADVISORY_RULES[rule]
    windows = {}
    for event_id, event in expected.items():
        if not all(isinstance(field, str) and re.fullmatch(r"[A-Z0-9]{1,16}", field)
                   for field in (event.dns_query_type, event.dns_response_code)):
            raise InvalidEvidence("DNS advisory reconciliation requires explicit query types and response codes")
        if getattr(event, field) != value:
            continue
        if event.dns_client_alias is None:
            raise InvalidEvidence("DNS advisory contributors require scoped client aliases")
        start = utc_timestamp(event.observed_timestamp).replace(second=0, microsecond=0)
        if start < scope.start or start + timedelta(minutes=1) > scope.end:
            continue
        windows.setdefault((event.dns_client_alias, start), set()).add(event_id)
    return {key: ids for key, ids in windows.items() if len(ids) >= minimum}


def identifier_set(value) -> set[str] | None:
    if (not isinstance(value, list) or not 1 <= len(value) <= 256 or
            any(not valid_id(item) for item in value) or len(set(value)) != len(value)):
        return None
    return set(value)


def reconcile_dns_advisories(expected: dict[str, CaptureRecord], records, scope: Scope, rule: str) -> dict:
    windows = dns_candidate_windows(expected, scope, rule)
    aliases = {event.dns_client_alias for event in expected.values() if event.dns_client_alias}
    count_field = DNS_ADVISORY_RULES[rule][3]
    counts = Counter(expected_windows=len(windows))
    matched = set()
    alert_ids = set()
    alerts_read = 0
    for envelope in records:
        alerts = envelope.get("alerts")
        if not isinstance(alerts, list) or not alerts:
            raise InvalidEvidence("Invalid network advisory envelope")
        alerts_read += len(alerts)
        if alerts_read > MAX_RECORDS:
            raise InvalidEvidence("Too many advisories in the selected offset ranges")
        for alert in alerts:
            if not isinstance(alert, dict):
                raise InvalidEvidence("Invalid network advisory record")
            if alert.get("rule_id") != rule:
                continue
            host = alert.get("host")
            raw_refs = alert.get("evidence_ids")
            refs = identifier_set(raw_refs)
            uses_capture = isinstance(raw_refs, list) and any(
                isinstance(item, str) and item in expected for item in raw_refs
            )
            known_client = isinstance(host, str) and host in aliases
            if not uses_capture and not known_client:
                continue
            if (envelope.get("tenant_id") != scope.tenant_id or alert.get("tenant_id") != scope.tenant_id
                    or envelope.get("scope") != "network-shadow" or envelope.get("source") != "correlation-worker"
                    or envelope.get("shadow_mode") is not True or alert.get("shadow_mode") is not True
                    or envelope.get("shadow_evidence_origin") != "unverified"
                    or alert.get("shadow_evidence_origin") != "unverified"
                    or rehearsal(envelope) or rehearsal(alert) or not known_client):
                counts["scope_mismatch"] += 1
                continue
            evidence = alert.get("evidence")
            if not isinstance(evidence, dict) or evidence.get("aggregation_mode") != "dns-event-time-v1":
                counts["invalid_window"] += 1
                continue
            try:
                start = utc_timestamp(evidence.get("window_start"))
                end = utc_timestamp(evidence.get("window_end"))
            except InvalidEvidence:
                counts["invalid_window"] += 1
                continue
            if (start.second != 0 or start.microsecond != 0 or end - start != timedelta(minutes=1)
                    or evidence["window_start"] != start.strftime("%Y-%m-%dT%H:%M:%SZ")
                    or evidence["window_end"] != end.strftime("%Y-%m-%dT%H:%M:%SZ")):
                counts["invalid_window"] += 1
                continue
            if not uses_capture and (start < scope.start or end > scope.end):
                continue
            key = (host, start)
            contributors = windows.get(key)
            if contributors is None:
                counts["unexpected_window"] += 1
                continue
            traces = {expected[event_id].trace_id for event_id in contributors}
            trace_id = next(iter(traces)) if len(traces) == 1 else ""
            alert_id = alert.get("alert_id")
            if (refs != contributors or evidence.get("host") != host
                    or evidence.get("advisory") != "network_shadow_only" or evidence.get("no_blocking") is not True
                    or evidence.get("evidence_lineage_complete") is not True
                    or evidence.get("evidence_lineage_truncated") is True
                    or type(evidence.get("source_event_count")) is not int
                    or evidence["source_event_count"] != len(contributors)
                    or type(evidence.get(count_field)) is not int or evidence[count_field] != len(contributors)
                    or identifier_set(evidence.get("source_trace_ids")) != traces
                    or envelope.get("trace_id") != trace_id or alert.get("trace_id") != trace_id
                    or not isinstance(alert_id, str) or not re.fullmatch(r"[a-f0-9]{40}", alert_id)):
                counts["evidence_mismatch"] += 1
                continue
            if key in matched or alert_id in alert_ids:
                counts["duplicate"] += 1
                continue
            matched.add(key)
            alert_ids.add(alert_id)
    counts["matched"] = len(matched)
    counts["missing"] = len(windows) - len(matched)
    return dict(counts)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--capture", required=True, type=Path, help="JSONL retained at source before publication")
    parser.add_argument("--registry", required=True, type=Path, help="Operator-controlled gateway source registry")
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--domain", required=True, choices=sorted(DOMAINS))
    parser.add_argument("--telemetry-type", required=True)
    parser.add_argument("--window-start", required=True)
    parser.add_argument("--window-end", required=True)
    parser.add_argument("--topic", default="telemetry.raw")
    parser.add_argument("--range", dest="ranges", required=True, action="append", help="PARTITION:START:END_EXCLUSIVE")
    parser.add_argument("--normalized-topic", default="telemetry.normalized")
    parser.add_argument("--normalized-range", action="append", default=[], help="PARTITION:START:END_EXCLUSIVE")
    parser.add_argument("--dns-advisory-rule", choices=sorted(DNS_ADVISORY_RULES))
    parser.add_argument("--advisory-range", action="append", default=[], help="PARTITION:START:END_EXCLUSIVE")
    parser.add_argument("--broker-port", type=int, default=8082, help="Local broker HTTP proxy port")
    args = parser.parse_args(argv)
    report = {"status": "ERROR", "promotion_evidence_verified": False, "representative_telemetry_verified": False}
    try:
        if not 1 <= args.broker_port <= 65535:
            raise InvalidEvidence("Invalid local broker port")
        validate_topic(args.topic)
        ranges = read_ranges(args.ranges)
        normalized_ranges = read_ranges(args.normalized_range)
        advisory_ranges = read_ranges(args.advisory_range)
        if bool(advisory_ranges) != bool(args.dns_advisory_rule):
            raise InvalidEvidence("DNS advisory reconciliation requires a rule and advisory ranges")
        if advisory_ranges and (not normalized_ranges or args.domain != "network" or args.telemetry_type != "dns"
                                or DNS_ADVISORY_TOPIC in (args.topic, args.normalized_topic)):
            raise InvalidEvidence("DNS advisory verification requires raw and normalized network DNS evidence")
        if normalized_ranges:
            validate_topic(args.normalized_topic)
            if args.normalized_topic == args.topic:
                raise InvalidEvidence("Raw and normalized topics must differ")
        scope, registry_sha256 = read_scope(args)
        expected, capture_sha256 = read_capture(args.capture, scope)
        with closing(broker_records(args.topic, ranges, port=args.broker_port)) as records:
            counts = reconcile(expected, records, scope)
        matched = counts.get("matched") == len(expected) and counts["unlisted"] == 0
        report.update(
            source_id=scope.source_id, tenant_id=scope.tenant_id, telemetry_type=scope.telemetry_type,
            domain=scope.domain, window_start=scope.start.isoformat(), window_end=scope.end.isoformat(),
            capture_sha256=capture_sha256, capture_record_count=len(expected),
            registry_sha256=registry_sha256,
            broker_port=args.broker_port, broker_topic=args.topic, ranges=[asdict(span) for span in ranges], counts=counts,
            verification_scope="specified_local_broker_offsets_only",
        )
        if normalized_ranges:
            normalized = {
                "topic": args.normalized_topic,
                "ranges": [asdict(span) for span in normalized_ranges],
                "status": "SKIPPED_RAW_MISMATCH",
            }
            report["normalized_lineage"] = normalized
            if matched:
                normalized["status"] = "ERROR"
                with closing(broker_records(args.normalized_topic, normalized_ranges, port=args.broker_port)) as records:
                    normalized_counts = reconcile_normalized(expected, records, scope,
                                                             verify_dns_values=bool(advisory_ranges))
                matched = normalized_counts.get("matched") == len(expected)
                normalized.update(status="MATCHED" if matched else "MISMATCH", counts=normalized_counts)
        if advisory_ranges:
            advisory = {
                "rule": args.dns_advisory_rule, "topic": DNS_ADVISORY_TOPIC,
                "ranges": [asdict(span) for span in advisory_ranges], "status": "SKIPPED_UPSTREAM_MISMATCH",
            }
            report["advisory_lineage"] = advisory
            if matched:
                advisory["status"] = "ERROR"
                with closing(broker_records(DNS_ADVISORY_TOPIC, advisory_ranges, port=args.broker_port)) as records:
                    advisory_counts = reconcile_dns_advisories(expected, records, scope, args.dns_advisory_rule)
                observed = advisory_counts["expected_windows"]
                faults = sum(value for key, value in advisory_counts.items() if key not in {"expected_windows", "matched"})
                matched = observed > 0 and faults == 0
                status = "MATCHED" if matched else ("NO_CANDIDATE_EVIDENCE" if observed == 0 and not faults else "MISMATCH")
                advisory.update(status=status, counts=advisory_counts)
        report["status"] = "MATCHED" if matched else "MISMATCH"
    except InvalidEvidence as exc:
        report["error"] = str(exc)
    except (OSError, http.client.HTTPException, UnicodeError):
        report["error"] = "Could not read capture, registry, or local broker data"
    print(json.dumps(report, sort_keys=True))
    return 0 if report["status"] == "MATCHED" else 2


if __name__ == "__main__":
    sys.exit(main())
