#!/usr/bin/env python3
"""
ATTR-002: Alert Attribution Context Validator

Offline validation — verifies that the attribution framework is structurally
correct without requiring a live database or external API.

Checks:
  ATT-01  geo_asn_fixtures.json exists and is valid JSON
  ATT-02  Fixture covers all RFC 1918 private ranges
  ATT-03  Fixture covers loopback
  ATT-04  Fixture covers RFC 5737 documentation ranges
  ATT-05  Fixture has at least 4 demo ASN entries
  ATT-06  AlertMitreService class file exists
  ATT-07  AlertAttributionService class file exists
  ATT-08  alert_attribution_context migration file exists
  ATT-09  AlertAttributionController class file exists
  ATT-10  attribution route registered in web.php
"""

import json
import sys
import os
import argparse
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent

CHECKS = {}


def check(name: str, desc: str):
    def decorator(fn):
        CHECKS[name] = (desc, fn)
        return fn
    return decorator


def load_fixture() -> dict:
    path = ROOT / "resources" / "data" / "geo_asn_fixtures.json"
    if not path.exists():
        return {}
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


@check("ATT-01", "geo_asn_fixtures.json exists and is valid JSON")
def att_01():
    path = ROOT / "resources" / "data" / "geo_asn_fixtures.json"
    if not path.exists():
        return False, f"missing: {path}"
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
        if "ranges" not in data:
            return False, "missing 'ranges' key"
        return True, f"{len(data['ranges'])} ranges loaded"
    except json.JSONDecodeError as exc:
        return False, f"JSON decode error: {exc}"


@check("ATT-02", "Fixture covers RFC 1918 private ranges")
def att_02():
    fixture = load_fixture()
    ranges = fixture.get("ranges", [])
    cidrs = {r["cidr"] for r in ranges}
    required = {"10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"}
    missing = required - cidrs
    if missing:
        return False, f"missing CIDRs: {missing}"
    return True, "RFC1918 ranges present"


@check("ATT-03", "Fixture covers loopback")
def att_03():
    fixture = load_fixture()
    ranges = fixture.get("ranges", [])
    cidrs = {r["cidr"] for r in ranges}
    if "127.0.0.0/8" not in cidrs:
        return False, "127.0.0.0/8 missing"
    return True, "loopback range present"


@check("ATT-04", "Fixture covers RFC 5737 documentation ranges")
def att_04():
    fixture = load_fixture()
    ranges = fixture.get("ranges", [])
    cidrs = {r["cidr"] for r in ranges}
    required = {"192.0.2.0/24", "198.51.100.0/24", "203.0.113.0/24"}
    missing = required - cidrs
    if missing:
        return False, f"missing documentation CIDRs: {missing}"
    return True, "RFC5737 documentation ranges present"


@check("ATT-05", "Fixture has at least 4 demo ASN entries")
def att_05():
    fixture = load_fixture()
    demo_asns = fixture.get("demo_asns", [])
    if len(demo_asns) < 4:
        return False, f"only {len(demo_asns)} demo ASNs (need >=4)"
    return True, f"{len(demo_asns)} demo ASN entries"


@check("ATT-06", "AlertMitreService class file exists")
def att_06():
    path = ROOT / "app" / "Services" / "AlertMitreService.php"
    if not path.exists():
        return False, f"missing: {path}"
    return True, str(path.relative_to(ROOT))


@check("ATT-07", "AlertAttributionService class file exists")
def att_07():
    path = ROOT / "app" / "Services" / "AlertAttributionService.php"
    if not path.exists():
        return False, f"missing: {path}"
    return True, str(path.relative_to(ROOT))


@check("ATT-08", "alert_attribution_context migration file exists")
def att_08():
    mig_dir = ROOT / "database" / "migrations"
    matches = list(mig_dir.glob("*alert_attribution_context*"))
    if not matches:
        return False, "no migration file found for alert_attribution_context"
    return True, matches[0].name


@check("ATT-09", "AlertAttributionController class file exists")
def att_09():
    path = ROOT / "app" / "Http" / "Controllers" / "AlertAttributionController.php"
    if not path.exists():
        return False, f"missing: {path}"
    return True, str(path.relative_to(ROOT))


@check("ATT-10", "attribution route registered in web.php")
def att_10():
    routes_file = ROOT / "routes" / "web.php"
    if not routes_file.exists():
        return False, "routes/web.php missing"
    content = routes_file.read_text(encoding="utf-8")
    if "security.attribution" not in content:
        return False, "'security.attribution' route name not found in web.php"
    return True, "route 'security.attribution' found"


def run_all(output_file: str | None) -> dict:
    results = {}
    for check_id, (desc, fn) in CHECKS.items():
        try:
            ok, detail = fn()
        except Exception as exc:
            ok, detail = False, f"EXCEPTION: {exc}"
        results[check_id] = {
            "description": desc,
            "status": "PASS" if ok else "FAIL",
            "detail": detail,
        }
    total = len(results)
    passed = sum(1 for r in results.values() if r["status"] == "PASS")
    failed = total - passed
    overall = "PASS" if failed == 0 else "FAIL"

    report = {
        "validator": "xdr_attribution_validate",
        "overall": overall,
        "checks": results,
        "summary": {"total": total, "passed": passed, "failed": failed},
    }

    if output_file:
        out_path = Path(output_file)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        with open(out_path, "w", encoding="utf-8") as fh:
            json.dump(report, fh, indent=2)

    return report


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="ATTR-002 Attribution Context Validator")
    parser.add_argument("--output", help="Write JSON report to this path")
    parser.add_argument("--quiet", action="store_true", help="Suppress console output")
    return parser.parse_args()


def main() -> int:
    args = _parse_args()
    _p = (lambda *a, **kw: None) if args.quiet else print

    report = run_all(args.output)

    _p(f"\nATTRIBUTION VALIDATOR — {report['overall']}")
    _p(f"Checks: {report['summary']['passed']}/{report['summary']['total']} passed\n")
    for cid, r in report["checks"].items():
        icon = "+" if r["status"] == "PASS" else "x"
        _p(f"  [{icon}] {cid}: {r['description']}")
        if r["status"] == "FAIL":
            _p(f"          -> {r['detail']}")

    return 0 if report["overall"] == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())
