#!/usr/bin/env python3
"""Convert a bounded, timestamped CoreDNS export to pre-ingestion DNS JSONL."""

from __future__ import annotations

import argparse
from datetime import timedelta
import hashlib
import hmac
import http.client
from ipaddress import IPv6Address, ip_address
import json
import os
from pathlib import Path
import re
import ssl
import sys
import time

from xdr_reconcile_capture import InvalidEvidence, TOKEN, read_bounded, utc_timestamp


MARKER = "[INFO] detector-dns-v1|"
CLIENT_MARKER = "[INFO] detector-dns-v2|"
MARKERS = (MARKER, CLIENT_MARKER)
LABEL = re.compile(r"[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?")
STAMP = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?Z")
CODE = re.compile(r"[A-Z0-9]{1,16}")
MAX_INPUT_BYTES = 1024 * 1024
MAX_EVENTS = 1000


def dns_name(value: str) -> str:
    name = value.removesuffix(".").lower()
    if not 1 <= len(name) <= 253 or not all(LABEL.fullmatch(label) for label in name.split(".")):
        raise InvalidEvidence("Invalid or unsupported DNS name")
    return name


def client_alias(client: str, source_id: str, tenant_id: str, key: bytes) -> str:
    try:
        if "%" in client or client.startswith("[") != client.endswith("]"):
            raise ValueError
        address = ip_address(client.removeprefix("[").removesuffix("]"))
    except ValueError as exc:
        raise InvalidEvidence("Invalid DNS client address") from exc
    if isinstance(address, IPv6Address) and address.ipv4_mapped is not None:
        address = address.ipv4_mapped
    scope = f"detector-dns-client-v1|{tenant_id}|{source_id}|{address.compressed}"
    return "dns-client-" + hmac.new(key, scope.encode("ascii"), hashlib.sha256).hexdigest()[:32]


def read_alias_key(path: Path) -> bytes:
    encoded = read_bounded(path, 128).strip()
    if not re.fullmatch(rb"[A-Fa-f0-9]{64}", encoded):
        raise InvalidEvidence("Client alias key must contain 32 random bytes encoded as hex")
    return bytes.fromhex(encoded.decode("ascii"))


def capture(data: bytes, *, source_id: str, tenant_id: str, zone: str, start: str, end: str,
            alias_key: bytes | None = None) -> list[dict]:
    window_start, window_end = utc_timestamp(start), utc_timestamp(end)
    if not timedelta(0) < window_end - window_start <= timedelta(hours=1):
        raise InvalidEvidence("The export window must be positive and at most one hour")
    zone = dns_name(zone)
    if not TOKEN.fullmatch(source_id) or not TOKEN.fullmatch(tenant_id):
        raise InvalidEvidence("Invalid source or tenant ID")
    events = {}
    for line in data.decode("utf-8").splitlines():
        stamp, _, message = line.partition(" ")
        if not message.startswith(MARKERS):
            if line.startswith(MARKERS):
                raise InvalidEvidence("Capture log is missing its Docker timestamp")
            continue
        if not STAMP.fullmatch(stamp):
            raise InvalidEvidence("Missing or invalid UTC Docker timestamp")
        if not window_start <= utc_timestamp(stamp) < window_end:
            continue
        client_mode = message.startswith(CLIENT_MARKER)
        if client_mode != (alias_key is not None):
            raise InvalidEvidence("The export layout and client alias key do not agree")
        fields = message.removeprefix(CLIENT_MARKER if client_mode else MARKER).split("|")
        if len(fields) != (7 if client_mode else 6):
            raise InvalidEvidence("Malformed CoreDNS query row")
        port, query_id, dns_class, query_type, name, response = fields[-6:]
        if (not re.fullmatch(r"[0-9]{1,5}", port) or not 1 <= int(port) <= 65535
                or not re.fullmatch(r"[0-9]{1,5}", query_id) or int(query_id) > 65535
                or dns_class != "IN" or not CODE.fullmatch(query_type) or not CODE.fullmatch(response)):
            raise InvalidEvidence("Invalid or unsupported CoreDNS query fields")
        name = dns_name(name)
        if name != zone and not name.endswith("." + zone):
            continue
        material = "|".join((source_id, tenant_id, stamp, port, query_id, query_type, name, response))
        host_id = client_alias(fields[0], source_id, tenant_id, alias_key) if alias_key is not None else None
        if host_id is not None:
            material += "|" + host_id
        digest = hashlib.sha256(material.encode("ascii")).hexdigest()
        event_id = "dns-" + digest
        events[event_id] = {
            "event_id": event_id, "trace_id": digest[:32], "tenant_id": tenant_id, "ts": stamp,
            "telemetry_type": "dns", "event_type": "dns_query", "queried_domain": name,
            "query_type": query_type, "response_code": response, "source_service": "coredns",
        }
        if host_id is not None:
            events[event_id]["host_id"] = host_id
        if len(events) > MAX_EVENTS:
            raise InvalidEvidence("Too many eligible CoreDNS records in this export")
    if not events:
        raise InvalidEvidence("No CoreDNS query records match the approved zone and window")
    return list(events.values())


def mtls_context(ca: Path, cert: Path, key: Path) -> ssl.SSLContext:
    try:
        context = ssl.create_default_context(cafile=str(ca))
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(certfile=str(cert), keyfile=str(key), password=b"")
        return context
    except OSError as exc:
        raise InvalidEvidence("Could not load the local mTLS CA or client identity") from exc


def publish_local(events: list[dict], port: int, secret: str, *, context: ssl.SSLContext | None = None) -> None:
    body = json.dumps(events, ensure_ascii=True, separators=(",", ":")).encode("ascii")
    stamp = str(int(time.time()))
    signature = hmac.new(secret.encode("utf-8"), stamp.encode("ascii") + b"." + body, hashlib.sha256)
    if context is None:
        client = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    else:
        client = http.client.HTTPSConnection("127.0.0.1", port, timeout=10, context=context)
    try:
        client.request("POST", "/v1/ingest", body, {
            "Content-Type": "application/json",
            "X-XDR-Timestamp": stamp,
            "X-XDR-Signature": "sha256=" + signature.hexdigest(),
        })
        with client.getresponse() as response:
            if response.status != 202:
                raise InvalidEvidence(f"Local gateway returned HTTP {response.status}")
            receipt = json.loads(response.read(8192))
            if not isinstance(receipt, dict) or type(receipt.get("accepted")) is not int or receipt["accepted"] != len(events):
                raise InvalidEvidence("Local gateway did not acknowledge the full capture")
    finally:
        client.close()


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("log", type=Path, help="CoreDNS stdout from docker logs --timestamps")
    parser.add_argument("--source-id", required=True, help="Operator-assigned resolver identifier")
    parser.add_argument("--tenant-id", required=True)
    parser.add_argument("--zone", required=True, help="Exact DNS zone approved for import")
    parser.add_argument("--window-start", required=True)
    parser.add_argument("--window-end", required=True)
    parser.add_argument("--output", type=Path, help="Write a new local JSONL file; otherwise report counts only")
    parser.add_argument("--client-alias-key-file", type=Path, help="Private key file for detector-dns-v2 logs")
    parser.add_argument("--publish-local", action="store_true", help="Send saved records once to a loopback demo gateway")
    parser.add_argument("--gateway-port", type=int, default=8091)
    parser.add_argument("--mtls-ca", type=Path, help="Trusted CA for the loopback gateway")
    parser.add_argument("--mtls-cert", type=Path, help="Client certificate chain in PEM format")
    parser.add_argument("--mtls-key", type=Path, help="Client private key in PEM format")
    args = parser.parse_args(argv)
    try:
        secret = os.environ.get("XDR_INGEST_SECRET") if args.publish_local else None
        if args.publish_local and (not args.output or not secret or not 1 <= args.gateway_port <= 65535):
            raise InvalidEvidence("Local publication requires --output, a valid gateway port, and XDR_INGEST_SECRET")
        tls_files = (args.mtls_ca, args.mtls_cert, args.mtls_key)
        if any(tls_files) and (not args.publish_local or not all(tls_files)):
            raise InvalidEvidence("mTLS requires --publish-local and all three mTLS files")
        context = mtls_context(*tls_files) if all(tls_files) else None
        alias_key = read_alias_key(args.client_alias_key_file) if args.client_alias_key_file else None
        data = read_bounded(args.log, MAX_INPUT_BYTES)
        events = capture(data, source_id=args.source_id, tenant_id=args.tenant_id, zone=args.zone,
                         start=args.window_start, end=args.window_end, alias_key=alias_key)
        if args.output:
            with args.output.open("x", encoding="utf-8", newline="\n") as stream:
                for event in events:
                    stream.write(json.dumps(event, ensure_ascii=True, separators=(",", ":")) + "\n")
        status = "CAPTURED" if args.output else "INSPECTED"
        if args.publish_local:
            publish_local(events, args.gateway_port, secret, context=context)
            status = "ACCEPTED_BY_GATEWAY"
        print(json.dumps({"status": status, "record_count": len(events),
                          "source_sha256": hashlib.sha256(data).hexdigest(),
                          "client_grouping": "scoped_alias" if alias_key is not None else "none",
                          "transport": ("https_mtls" if context else "http_loopback") if args.publish_local else None,
                          "capture_admission_verified": False, "representative_telemetry_verified": False,
                          "promotion_evidence_verified": False}))
        return 0
    except InvalidEvidence as exc:
        print(str(exc), file=sys.stderr)
    except (OSError, UnicodeError, http.client.HTTPException, json.JSONDecodeError):
        print("Could not read the export, create the capture, or confirm gateway receipt", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
