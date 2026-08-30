#!/usr/bin/env python3
"""Validate endpoint telemetry normalization.

Loads raw endpoint telemetry fixtures from tests/fixtures/endpoint/,
applies normalization logic that mirrors Go's normalizeEndpoint(),
validates normalized output shape and required fields,
checks idempotency, deduplication, and optionally sends fixtures
through the normalizer HTTP service.

Does NOT activate or change endpoint correlation scope.
Endpoint domain remains shadow-only.
"""

from __future__ import annotations

import argparse
import json
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


REQUIRED_NORMALIZED_FIELDS = [
    "schema_version",
    "normalization_version",
    "normalized_event_id",
    "raw_event_id",
    "ts",
    "telemetry_type",
    "event_type",
    "host",
]

NESTED_SECTIONS = ["process", "network", "dns", "file", "auth"]

VALID_EVENT_TYPES = {
    "process_start",
    "process_exit",
    "network_connection",
    "dns_query",
    "file_write",
    "login_event",
}


def parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate endpoint telemetry normalization")
    parser.add_argument("--fixture-dir", default="tests/fixtures/endpoint",
                        help="Directory containing raw endpoint telemetry fixture JSON files")
    parser.add_argument("--normalizer-url", default="http://127.0.0.1:8092",
                        help="Normalizer worker base URL")
    parser.add_argument("--output", default="reports/xdr_endpoint_normalization_validation.json",
                        help="Report output path")
    parser.add_argument("--use-normalizer-service", type=int, default=1,
                        help="1=try HTTP normalizer service; 0=local validation only")
    parser.add_argument("--mtls-enabled", action="store_true", dest="mtls_enabled")
    parser.add_argument("--mtls-ca", default=None, dest="mtls_ca")
    parser.add_argument("--mtls-client-cert", default=None, dest="mtls_client_cert")
    parser.add_argument("--mtls-client-key", default=None, dest="mtls_client_key")
    return parser.parse_args(argv)


def build_mtls_context(args: argparse.Namespace) -> ssl.SSLContext | None:
    if not getattr(args, "mtls_enabled", False):
        return None
    if not args.use_normalizer_service:
        raise ValueError("--mtls-enabled requires --use-normalizer-service 1")
    if urllib.parse.urlsplit(args.normalizer_url).scheme.lower() != "https":
        raise ValueError("--mtls-enabled requires https:// for --normalizer-url")

    material = {
        "--mtls-ca": getattr(args, "mtls_ca", None),
        "--mtls-client-cert": getattr(args, "mtls_client_cert", None),
        "--mtls-client-key": getattr(args, "mtls_client_key", None),
    }
    missing = [option for option, value in material.items() if not value]
    if missing:
        raise ValueError(
            "--mtls-enabled requires complete TLS material; missing "
            + ", ".join(missing)
        )
    context = ssl.create_default_context(cafile=material["--mtls-ca"])
    context.load_cert_chain(
        certfile=material["--mtls-client-cert"],
        keyfile=material["--mtls-client-key"],
    )
    return context


# ---------------------------------------------------------------------------
# Normalization — mirrors Go normalizeEndpoint() in normalizer-worker/main.go
# ---------------------------------------------------------------------------

def _first(raw: Dict[str, Any], *keys: str) -> Optional[str]:
    for key in keys:
        v = raw.get(key)
        if v is not None and str(v).strip() != "":
            return str(v)
    return None


def _float(raw: Dict[str, Any], *keys: str) -> Optional[float]:
    for key in keys:
        v = raw.get(key)
        if v is not None:
            try:
                return float(v)
            except (TypeError, ValueError):
                pass
    return None


def normalize_endpoint_local(raw: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    """Mirror normalizeEndpoint() from services/normalizer-worker/main.go.

    Returns None if required fields are missing (same as Go returning an error).
    """
    ts = _first(raw, "ts", "timestamp", "event_time")
    event_type = (_first(raw, "event_type", "action", "operation") or "").lower()
    host = _first(raw, "host", "host_id", "device_name")
    if not ts or not event_type or not host:
        return None

    event_id = _first(raw, "event_id", "id") or ""

    return {
        "schema_version": 1,
        "normalization_version": "endpoint-v1",
        "normalized_event_id": event_id,
        "raw_event_id": event_id,
        "ts": ts,
        "telemetry_type": "endpoint",
        "event_type": event_type,
        "host": host,
        "user": _first(raw, "user"),
        "risk_score": _float(raw, "risk_score", "risk", "score"),
        "event_source": _first(raw, "event_source", "source_adapter", "vendor"),
        "trace_id": _first(raw, "trace_id"),
        "process": {
            "name":         _first(raw, "process_name"),
            "pid":          raw.get("pid"),
            "ppid":         raw.get("ppid"),
            "command_line": _first(raw, "command_line"),
            "path":         _first(raw, "process_path"),
            "hash":         _first(raw, "file_hash", "sha256"),
        },
        "network": {
            "source_ip":        _first(raw, "source_ip", "src_ip", "client_ip"),
            "destination_ip":   _first(raw, "destination_ip", "dst_ip", "server_ip"),
            "destination_port": raw.get("destination_port"),
            "protocol":         (_first(raw, "protocol") or "").lower() or None,
        },
        "dns": {
            "domain":       _first(raw, "domain", "query", "url_domain"),
            "resolved_ips": raw.get("resolved_ips"),
        },
        "file": {
            "path":      _first(raw, "file_path"),
            "hash":      _first(raw, "file_hash", "sha256"),
            "operation": (_first(raw, "operation") or "").lower() or None,
        },
        "auth": {
            "action": (_first(raw, "action") or "").lower() or None,
            "result": (_first(raw, "result", "outcome") or "").lower() or None,
        },
    }


# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------

def validate_normalized_shape(normalized: Dict[str, Any], fixture_name: str) -> List[str]:
    """Return a list of validation failure strings. Empty list = all checks pass."""
    failures: List[str] = []

    for field in REQUIRED_NORMALIZED_FIELDS:
        v = normalized.get(field)
        if v is None or str(v).strip() == "":
            failures.append(f"{fixture_name}: missing or empty required field '{field}'")

    if normalized.get("telemetry_type") != "endpoint":
        failures.append(f"{fixture_name}: telemetry_type '{normalized.get('telemetry_type')}' != 'endpoint'")

    if normalized.get("normalization_version") != "endpoint-v1":
        failures.append(f"{fixture_name}: normalization_version != 'endpoint-v1'")

    if normalized.get("event_type") not in VALID_EVENT_TYPES:
        failures.append(
            f"{fixture_name}: event_type '{normalized.get('event_type')}' not in valid set {sorted(VALID_EVENT_TYPES)}"
        )

    for section in NESTED_SECTIONS:
        if section not in normalized:
            failures.append(f"{fixture_name}: missing nested section '{section}'")
        elif not isinstance(normalized[section], dict):
            failures.append(f"{fixture_name}: nested section '{section}' is not an object")

    nid = normalized.get("normalized_event_id")
    rid = normalized.get("raw_event_id")
    if nid != rid:
        failures.append(
            f"{fixture_name}: normalized_event_id '{nid}' != raw_event_id '{rid}' — idempotency key mismatch"
        )

    return failures


def check_idempotency(raw: Dict[str, Any], fixture_name: str) -> Tuple[bool, Optional[str]]:
    """Normalize the same raw event twice. Output must be byte-for-byte identical."""
    n1 = normalize_endpoint_local(raw)
    n2 = normalize_endpoint_local(raw)
    if n1 is None or n2 is None:
        return False, f"{fixture_name}: normalization returned None on idempotency check"
    s1 = json.dumps(n1, sort_keys=True, default=str)
    s2 = json.dumps(n2, sort_keys=True, default=str)
    if s1 != s2:
        return False, f"{fixture_name}: double normalization produced different output"
    return True, None


# ---------------------------------------------------------------------------
# Normalizer service check (optional)
# ---------------------------------------------------------------------------

def try_normalizer_service(
    url: str,
    raw: Dict[str, Any],
    fixture_name: str,
    ssl_context: ssl.SSLContext | None = None,
) -> Dict[str, Any]:
    """POST a single raw event to /v1/normalize and return the service response."""
    try:
        body = json.dumps([raw]).encode("utf-8")
        req = urllib.request.Request(
            f"{url.rstrip('/')}/v1/normalize",
            data=body,
            method="POST",
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=10, context=ssl_context) as resp:
            result = json.loads(resp.read().decode("utf-8"))
        service_ok = result.get("malformed", 1) == 0 and result.get("enqueued", 0) >= 1
        return {
            "fixture": fixture_name,
            "service_ok": service_ok,
            "response": result,
        }
    except urllib.error.URLError as exc:
        return {"fixture": fixture_name, "service_ok": None, "error": f"service_unavailable: {exc}"}
    except Exception as exc:
        return {"fixture": fixture_name, "service_ok": False, "error": str(exc)}


def normalizer_health(
    url: str,
    ssl_context: ssl.SSLContext | None = None,
) -> bool:
    try:
        with urllib.request.urlopen(
            f"{url.rstrip('/')}/health", timeout=5, context=ssl_context
        ) as resp:
            return resp.status == 200
    except Exception:
        return False


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main(args: argparse.Namespace | None = None) -> int:
    args = args or parse_args()
    try:
        normalizer_ssl_context = build_mtls_context(args)
    except (OSError, ValueError) as exc:
        print(f"ERROR: Invalid mTLS configuration: {exc}", file=sys.stderr)
        return 2
    root = Path(__file__).resolve().parents[1]
    fixture_dir = (root / args.fixture_dir).resolve()

    report: Dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "fixture_dir": str(fixture_dir.relative_to(root)),
        "normalizer_url": args.normalizer_url,
        "normalizer_mtls_enabled": bool(normalizer_ssl_context),
        "checks": {},
        "fixture_results": [],
        "idempotency_results": [],
        "service_results": [],
        "all_failures": [],
        "errors": [],
    }

    # ---- Load fixtures ----
    fixtures: Dict[str, Dict[str, Any]] = {}
    if not fixture_dir.exists():
        report["errors"].append(f"fixture_dir not found: {fixture_dir}")
    else:
        for path in sorted(fixture_dir.glob("*.json")):
            try:
                fixtures[path.stem] = json.loads(path.read_text(encoding="utf-8"))
            except Exception as exc:
                report["errors"].append(f"failed to load {path.name}: {exc}")

    if not fixtures:
        report["checks"]["fixtures_loaded"] = False
        report["validation_status"] = "FAIL"
        _write_report(report, root / args.output)
        print(f"output={root / args.output}")
        print("status=FAIL  no fixtures loaded")
        return 2

    all_failures: List[str] = []
    seen_normalized_ids: set = set()
    duplicate_ids: List[str] = []

    # ---- Per-fixture validation ----
    for name, raw in fixtures.items():
        normalized = normalize_endpoint_local(raw)
        result: Dict[str, Any] = {
            "fixture": name,
            "raw_event_type": raw.get("event_type"),
            "raw_event_id": raw.get("event_id"),
        }

        if normalized is None:
            result["normalization_ok"] = False
            result["error"] = "normalization returned None — missing ts, event_type, or host"
            all_failures.append(f"{name}: normalization returned None")
            report["fixture_results"].append(result)
            continue

        failures = validate_normalized_shape(normalized, name)
        result["normalization_ok"] = len(failures) == 0
        result["failures"] = failures
        result["normalized_event_id"] = normalized.get("normalized_event_id")
        result["event_type_normalized"] = normalized.get("event_type")
        result["nested_sections"] = {s: isinstance(normalized.get(s), dict) for s in NESTED_SECTIONS}
        all_failures.extend(failures)

        nid = normalized.get("normalized_event_id") or ""
        if nid in seen_normalized_ids:
            duplicate_ids.append(nid)
            all_failures.append(f"{name}: duplicate normalized_event_id '{nid}'")
        else:
            if nid:
                seen_normalized_ids.add(nid)

        report["fixture_results"].append(result)

    # ---- Idempotency checks ----
    idempotency_ok = True
    for name, raw in fixtures.items():
        ok, msg = check_idempotency(raw, name)
        report["idempotency_results"].append({
            "fixture": name,
            "ok": ok,
            "message": msg,
        })
        if not ok:
            idempotency_ok = False
            if msg:
                all_failures.append(msg)

    # ---- Normalizer service checks (optional) ----
    normalizer_available = False
    if args.use_normalizer_service:
        normalizer_available = normalizer_health(
            args.normalizer_url, ssl_context=normalizer_ssl_context
        )
        report["normalizer_service_available"] = normalizer_available
        if normalizer_available:
            for name, raw in fixtures.items():
                result = try_normalizer_service(
                    args.normalizer_url,
                    raw,
                    name,
                    ssl_context=normalizer_ssl_context,
                )
                report["service_results"].append(result)
                if result.get("service_ok") is False:
                    all_failures.append(
                        f"{name}: normalizer service rejected event — {result.get('error', result.get('response'))}"
                    )
        else:
            report["service_results"].append({
                "note": f"normalizer service at {args.normalizer_url} not available — skipped service checks"
            })

    # ---- Aggregate checks ----
    no_duplicates = len(duplicate_ids) == 0
    all_fields_ok = not any("missing or empty required field" in f for f in all_failures)
    all_types_ok = not any("not in valid set" in f for f in all_failures)
    all_nested_ok = not any("missing nested section" in f for f in all_failures)
    all_normalized_ok = all(r.get("normalization_ok", False) for r in report["fixture_results"])

    report["checks"] = {
        "fixtures_loaded": len(fixtures) > 0,
        "all_required_fields_present": all_fields_ok,
        "all_event_types_valid": all_types_ok,
        "normalization_idempotent": idempotency_ok,
        "no_duplicate_normalized_ids": no_duplicates,
        "nested_sections_present": all_nested_ok,
        "all_fixtures_normalized_ok": all_normalized_ok,
    }
    report["all_failures"] = all_failures
    report["summary"] = {
        "fixtures_loaded": len(fixtures),
        "fixtures_passed": sum(1 for r in report["fixture_results"] if r.get("normalization_ok")),
        "fixtures_failed": sum(1 for r in report["fixture_results"] if not r.get("normalization_ok")),
        "duplicate_ids": duplicate_ids,
        "total_failures": len(all_failures),
    }
    report["validation_status"] = "PASS" if all(report["checks"].values()) else "FAIL"

    _write_report(report, root / args.output)
    print(f"output={root / args.output}")
    print(
        "status={status} fixtures={loaded} passed={passed} failed={failed} failures={total}".format(
            status=report["validation_status"],
            loaded=report["summary"]["fixtures_loaded"],
            passed=report["summary"]["fixtures_passed"],
            failed=report["summary"]["fixtures_failed"],
            total=report["summary"]["total_failures"],
        )
    )
    for f in all_failures:
        print(f"  FAIL: {f}")
    return 0 if report["validation_status"] == "PASS" else 2


def _write_report(report: Dict[str, Any], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")


if __name__ == "__main__":
    raise SystemExit(main())
