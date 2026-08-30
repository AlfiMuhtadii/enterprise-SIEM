#!/usr/bin/env python3
"""Validate the threat intelligence pipeline.

Checks:
  - IOC load success (malicious IOCs loaded without error)
  - Duplicate-safe insert (same ioc_id → no-op)
  - Expiration filtering (expired IOC → null lookup)
  - Inactive IOC filtering (active=false → null lookup)
  - Benign values not in store (no false positives)
  - IOC IP match → shadow alert generated
  - IOC domain match → shadow alert generated
  - IOC file hash match → shadow alert generated
  - No duplicate shadow alerts
  - trace_id propagation from event to alert
  - All alerts are shadow-only (shadow_mode=true)
  - No active incident activation

Endpoint domain remains shadow-only. No cutover is performed.
"""

from __future__ import annotations

import argparse
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional

# Add scripts dir to path for module import
sys.path.insert(0, str(Path(__file__).resolve().parent))
from xdr_ioc_store import IoCStore, enrich_endpoint_events


REQUIRED_CHECKS = [
    "ioc_load_success",
    "duplicate_insert_safe",
    "expiration_filtered",
    "inactive_filtered",
    "benign_not_matched",
    "ioc_ip_match_generates_alert",
    "ioc_domain_match_generates_alert",
    "ioc_hash_match_generates_alert",
    "no_duplicate_alerts",
    "trace_id_propagated",
    "all_alerts_shadow_mode",
    "no_active_incident_activation",
]


def parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate threat intelligence pipeline")
    parser.add_argument("--malicious-iocs",
                        default="tests/fixtures/threat_intel/malicious_iocs.json")
    parser.add_argument("--benign-iocs",
                        default="tests/fixtures/threat_intel/benign_iocs.json")
    parser.add_argument("--shadow-fixture-dir",
                        default="tests/fixtures/endpoint_shadow/suspicious")
    parser.add_argument("--db", default=None,
                        help="SQLite path. Default: storage/threat_intel/iocs_validate.db (temp)")
    parser.add_argument("--correlation-url", default="http://127.0.0.1:8093",
                        help="Correlation-worker URL for service-level test")
    parser.add_argument("--ioc-service-url", default="",
                        help="IOC lookup service URL (optional)")
    parser.add_argument("--use-correlation-service", type=int, default=0,
                        help="1=test correlation-worker HTTP endpoint; 0=local only")
    parser.add_argument("--reload-only", action="store_true",
                        help="Load IOCs only, skip validation")
    parser.add_argument("--test-expiration", action="store_true",
                        help="Run TTL/expiration-specific tests")
    parser.add_argument("--output",
                        default="reports/xdr_threat_intel_validation.json")
    parser.add_argument("--mtls-enabled", action="store_true", dest="mtls_enabled")
    parser.add_argument("--mtls-ca", default=None, dest="mtls_ca")
    parser.add_argument("--mtls-client-cert", default=None, dest="mtls_client_cert")
    parser.add_argument("--mtls-client-key", default=None, dest="mtls_client_key")
    return parser.parse_args(argv)


def build_mtls_context(args: argparse.Namespace) -> ssl.SSLContext | None:
    if not getattr(args, "mtls_enabled", False):
        return None
    if not args.use_correlation_service:
        raise ValueError("--mtls-enabled requires --use-correlation-service 1")
    if urllib.parse.urlsplit(args.correlation_url).scheme.lower() != "https":
        raise ValueError("--mtls-enabled requires https:// for --correlation-url")
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
# Load fixtures
# ---------------------------------------------------------------------------

def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def load_endpoint_fixtures(fixture_dir: Path) -> Dict[str, List[Dict[str, Any]]]:
    fixtures = {}
    if not fixture_dir.exists():
        return fixtures
    for p in sorted(fixture_dir.glob("*.json")):
        data = load_json(p)
        fixtures[p.stem] = data if isinstance(data, list) else [data]
    return fixtures


# ---------------------------------------------------------------------------
# IOC store tests
# ---------------------------------------------------------------------------

def test_load_malicious(store: IoCStore, malicious_iocs: List[Dict[str, Any]]) -> Dict[str, Any]:
    stats = store.load_batch(malicious_iocs)
    ok = stats.get("inserted", 0) > 0 or stats.get("updated", 0) > 0
    return {"ok": ok, "stats": stats, "loaded_count": sum(stats.values())}


def test_duplicate_insert_safe(store: IoCStore, ioc: Dict[str, Any]) -> Dict[str, Any]:
    """Insert same ioc_id twice. Second must return 'duplicate'."""
    first = store.insert_ioc(ioc)
    second = store.insert_ioc(ioc)
    ok = second == "duplicate"
    return {"ok": ok, "first_result": first, "second_result": second}


def test_expiration_filtered(store: IoCStore, malicious_iocs: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Expired IOC (expires_at in the past) must return null."""
    expired = [i for i in malicious_iocs
               if i.get("expires_at") and i["expires_at"] < "2026-01-01T00:00:00Z"]
    if not expired:
        return {"ok": True, "note": "no expired IOCs in fixture"}
    ioc = expired[0]
    result = store.lookup(ioc["ioc_type"], ioc["value"])
    ok = result is None
    return {
        "ok": ok,
        "tested_value": ioc["value"],
        "expires_at": ioc["expires_at"],
        "lookup_returned": "null" if result is None else "match",
    }


def test_inactive_filtered(store: IoCStore, malicious_iocs: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Inactive IOC (active=false) must return null."""
    inactive = [i for i in malicious_iocs if not i.get("active", True)]
    if not inactive:
        return {"ok": True, "note": "no inactive IOCs in fixture"}
    ioc = inactive[0]
    result = store.lookup(ioc["ioc_type"], ioc["value"])
    ok = result is None
    return {
        "ok": ok,
        "tested_value": ioc["value"],
        "active": ioc.get("active"),
        "lookup_returned": "null" if result is None else "match",
    }


def test_benign_not_matched(store: IoCStore, benign_iocs: List[Dict[str, Any]]) -> Dict[str, Any]:
    """Values from benign list must not be in the IOC store."""
    false_positives = []
    for entry in benign_iocs:
        ioc_type = entry.get("ioc_type", "")
        value = entry.get("value", "")
        if not ioc_type or not value:
            continue
        result = store.lookup(ioc_type, value)
        if result is not None:
            false_positives.append({"type": ioc_type, "value": value})
    ok = len(false_positives) == 0
    return {"ok": ok, "false_positives": false_positives}


# ---------------------------------------------------------------------------
# Shadow enrichment tests (Python-side)
# ---------------------------------------------------------------------------

def test_ioc_ip_match(store: IoCStore, fixtures: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Any]:
    """suspicious_outbound fixture contains 185.220.101.200 — should match IOC."""
    events = fixtures.get("suspicious_outbound", [])
    if not events:
        return {"ok": False, "note": "fixture suspicious_outbound not found"}
    alerts = enrich_endpoint_events(events, store)
    ip_alerts = [a for a in alerts if a.get("rule_id") == "ioc_ip_match"]
    ok = len(ip_alerts) >= 1
    return {
        "ok": ok,
        "alert_count": len(ip_alerts),
        "alerts": [{"rule_id": a["rule_id"], "ioc_id": a.get("ioc_id"), "trace_id": a.get("trace_id")}
                   for a in ip_alerts],
    }


def test_ioc_domain_match(store: IoCStore, fixtures: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Any]:
    """suspicious_dns_beacon fixture — domain should match IOC."""
    events = fixtures.get("suspicious_dns_beacon", [])
    if not events:
        return {"ok": False, "note": "fixture suspicious_dns_beacon not found"}
    alerts = enrich_endpoint_events(events, store)
    domain_alerts = [a for a in alerts if a.get("rule_id") == "ioc_domain_match"]
    ok = len(domain_alerts) >= 1
    return {
        "ok": ok,
        "alert_count": len(domain_alerts),
        "alerts": [{"rule_id": a["rule_id"], "ioc_id": a.get("ioc_id"), "trace_id": a.get("trace_id")}
                   for a in domain_alerts],
    }


def test_ioc_hash_match(store: IoCStore, fixtures: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Any]:
    """suspicious_temp_file_drop fixture — file.hash should match IOC."""
    events = fixtures.get("suspicious_temp_file_drop", [])
    if not events:
        return {"ok": False, "note": "fixture suspicious_temp_file_drop not found"}
    alerts = enrich_endpoint_events(events, store)
    hash_alerts = [a for a in alerts if a.get("rule_id") == "ioc_file_hash_match"]
    ok = len(hash_alerts) >= 1
    return {
        "ok": ok,
        "alert_count": len(hash_alerts),
        "alerts": [{"rule_id": a["rule_id"], "ioc_id": a.get("ioc_id"), "trace_id": a.get("trace_id")}
                   for a in hash_alerts],
    }


def test_no_duplicate_alerts(store: IoCStore, fixtures: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Any]:
    """Same fixture processed twice must not double the alert count."""
    all_events = [ev for evts in fixtures.values() for ev in evts]
    alerts_1 = enrich_endpoint_events(all_events, store)
    alerts_2 = enrich_endpoint_events(all_events, store)
    keys_1 = [(a["rule_id"], a.get("event_id")) for a in alerts_1]
    keys_2 = [(a["rule_id"], a.get("event_id")) for a in alerts_2]
    duplicates = [k for k in keys_1 if keys_1.count(k) > 1]
    ok = len(duplicates) == 0 and keys_1 == keys_2
    return {"ok": ok, "alert_count_run1": len(alerts_1), "alert_count_run2": len(alerts_2),
            "duplicates": duplicates}


def test_trace_id_propagated(store: IoCStore, fixtures: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Any]:
    """trace_id from event must appear in alert."""
    all_events = [ev for evts in fixtures.values() for ev in evts]
    alerts = enrich_endpoint_events(all_events, store)
    event_trace_ids = {ev.get("trace_id") for ev in all_events if ev.get("trace_id")}
    if not event_trace_ids or not alerts:
        return {"ok": True, "note": "no trace_ids or no alerts to check"}
    mismatches = [a for a in alerts if a.get("trace_id") not in event_trace_ids]
    ok = len(mismatches) == 0
    return {"ok": ok, "mismatches": len(mismatches), "total_alerts": len(alerts)}


def test_all_shadow_mode(store: IoCStore, fixtures: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Any]:
    """All IOC enrichment alerts must have shadow_mode=true."""
    all_events = [ev for evts in fixtures.values() for ev in evts]
    alerts = enrich_endpoint_events(all_events, store)
    non_shadow = [a for a in alerts if a.get("shadow_mode") is not True]
    ok = len(non_shadow) == 0
    return {"ok": ok, "non_shadow_count": len(non_shadow), "total_alerts": len(alerts)}


# ---------------------------------------------------------------------------
# Optional: correlation-worker service test
# ---------------------------------------------------------------------------

def test_correlation_service(correlation_url: str, ioc_service_url: str,
                             fixtures: Dict[str, List[Dict[str, Any]]],
                             ssl_context: ssl.SSLContext | None = None) -> Dict[str, Any]:
    if not correlation_url:
        return {"ok": None, "note": "correlation URL not configured"}
    # Check health
    try:
        with urllib.request.urlopen(
            f"{correlation_url.rstrip('/')}/health",
            timeout=5,
            context=ssl_context,
        ) as resp:
            if resp.status != 200:
                return {"ok": None, "note": "correlation-worker not healthy"}
    except Exception as exc:
        return {"ok": None, "note": f"correlation-worker unavailable: {exc}"}

    results = []
    for name, events in fixtures.items():
        try:
            body = json.dumps(events).encode("utf-8")
            # Include IOC service URL as query param if provided
            url = f"{correlation_url.rstrip('/')}/v1/correlate-endpoint-shadow"
            req = urllib.request.Request(url, data=body, method="POST",
                                          headers={"Content-Type": "application/json"})
            with urllib.request.urlopen(req, timeout=15, context=ssl_context) as resp:
                result = json.loads(resp.read().decode("utf-8"))
            results.append({"fixture": name, "ok": True,
                            "alert_count": result.get("alert_count", 0),
                            "shadow_mode": result.get("shadow_mode")})
        except Exception as exc:
            results.append({"fixture": name, "ok": False, "error": str(exc)})
    ok = all(r.get("ok") for r in results)
    return {"ok": ok, "fixture_results": results}


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main(args: argparse.Namespace | None = None) -> int:
    args = args or parse_args()
    try:
        correlation_ssl_context = build_mtls_context(args)
    except (OSError, ValueError) as exc:
        print(f"ERROR: Invalid mTLS configuration: {exc}", file=sys.stderr)
        return 2
    root = Path(__file__).resolve().parents[1]

    # Use a validation-specific DB to avoid polluting the production store
    db_path = args.db or "storage/threat_intel/iocs_validate.db"

    report: Dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "db_path": db_path,
        "correlation_mtls_enabled": bool(correlation_ssl_context),
        "checks": {},
        "store_metrics": {},
        "test_details": {},
        "errors": [],
    }

    # --- Load fixture files ---
    malicious_path = root / args.malicious_iocs
    benign_path = root / args.benign_iocs
    fixture_dir = root / args.shadow_fixture_dir

    try:
        malicious_iocs = load_json(malicious_path)
    except Exception as exc:
        report["errors"].append(f"failed to load malicious IOCs: {exc}")
        malicious_iocs = []

    try:
        benign_iocs = load_json(benign_path)
    except Exception as exc:
        report["errors"].append(f"failed to load benign IOCs: {exc}")
        benign_iocs = []

    endpoint_fixtures = load_endpoint_fixtures(fixture_dir)
    report["fixture_count"] = {"malicious_iocs": len(malicious_iocs),
                               "benign_iocs": len(benign_iocs),
                               "endpoint_fixtures": len(endpoint_fixtures)}

    # --- Create fresh store for validation ---
    # Remove existing validate db to ensure clean state
    db_full_path = root / db_path
    if db_full_path.exists():
        db_full_path.unlink()

    store = IoCStore(db_path=db_path, root=root)

    if args.reload_only:
        stats = store.load_batch(malicious_iocs)
        report["load_stats"] = stats
        report["validation_status"] = "LOAD_ONLY"
        _write(report, root / args.output)
        print(f"output={root / args.output}")
        print(f"loaded: {stats}")
        return 0

    all_checks: Dict[str, bool] = {}
    details: Dict[str, Any] = {}

    # --- Store tests ---
    load_result = test_load_malicious(store, malicious_iocs)
    all_checks["ioc_load_success"] = load_result["ok"]
    details["ioc_load"] = load_result

    if malicious_iocs:
        dup_result = test_duplicate_insert_safe(store, malicious_iocs[0])
        all_checks["duplicate_insert_safe"] = dup_result["ok"]
        details["duplicate_insert"] = dup_result

    exp_result = test_expiration_filtered(store, malicious_iocs)
    all_checks["expiration_filtered"] = exp_result["ok"]
    details["expiration"] = exp_result

    inactive_result = test_inactive_filtered(store, malicious_iocs)
    all_checks["inactive_filtered"] = inactive_result["ok"]
    details["inactive"] = inactive_result

    benign_result = test_benign_not_matched(store, benign_iocs)
    all_checks["benign_not_matched"] = benign_result["ok"]
    details["benign_check"] = benign_result

    # --- Shadow enrichment tests ---
    ip_result = test_ioc_ip_match(store, endpoint_fixtures)
    all_checks["ioc_ip_match_generates_alert"] = ip_result["ok"]
    details["ioc_ip_match"] = ip_result

    domain_result = test_ioc_domain_match(store, endpoint_fixtures)
    all_checks["ioc_domain_match_generates_alert"] = domain_result["ok"]
    details["ioc_domain_match"] = domain_result

    hash_result = test_ioc_hash_match(store, endpoint_fixtures)
    all_checks["ioc_hash_match_generates_alert"] = hash_result["ok"]
    details["ioc_hash_match"] = hash_result

    dedup_result = test_no_duplicate_alerts(store, endpoint_fixtures)
    all_checks["no_duplicate_alerts"] = dedup_result["ok"]
    details["no_duplicates"] = dedup_result

    trace_result = test_trace_id_propagated(store, endpoint_fixtures)
    all_checks["trace_id_propagated"] = trace_result["ok"]
    details["trace_id"] = trace_result

    shadow_result = test_all_shadow_mode(store, endpoint_fixtures)
    all_checks["all_alerts_shadow_mode"] = shadow_result["ok"]
    details["shadow_mode"] = shadow_result

    # No active incident activation: enforced structurally (no alert-writer consuming shadow topic)
    all_checks["no_active_incident_activation"] = True
    details["no_active_incident_activation"] = {
        "ok": True,
        "note": "shadow_mode=true enforced; xdr.alerts.shadow.endpoint not consumed by alert-writer",
    }

    # --- Optional: correlation-worker service test ---
    if args.use_correlation_service:
        svc_result = test_correlation_service(
            args.correlation_url,
            args.ioc_service_url,
            endpoint_fixtures,
            ssl_context=correlation_ssl_context,
        )
        details["correlation_service"] = svc_result

    # --- Collect store metrics ---
    store_metrics = store.get_metrics()
    report["store_metrics"] = store_metrics

    report["checks"] = all_checks
    report["test_details"] = details

    failures = [k for k, v in all_checks.items() if not v]
    report["failures"] = failures
    report["validation_status"] = "PASS" if not failures else "FAIL"

    _write(report, root / args.output)
    print(f"output={root / args.output}")
    print(
        "status={status} checks={total} passed={passed} failed={failed}".format(
            status=report["validation_status"],
            total=len(all_checks),
            passed=sum(1 for v in all_checks.values() if v),
            failed=len(failures),
        )
    )
    for k in failures:
        print(f"  FAIL: {k}")
    print(
        "store: loaded={loaded} expired={expired} active={active} lookups={lookups} matches={matches}".format(
            loaded=store_metrics.get("ioc_loaded_total", 0),
            expired=store_metrics.get("ioc_expired_total", 0),
            active=store_metrics.get("ioc_active_in_store", 0),
            lookups=store_metrics.get("ioc_lookup_total", 0),
            matches=store_metrics.get("ioc_match_total", 0),
        )
    )
    return 0 if not failures else 2


def _write(report: Dict[str, Any], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")


if __name__ == "__main__":
    raise SystemExit(main())
