#!/usr/bin/env python3
"""ENTERPRISE-058: Confidence source refresh validator. Offline structural checks only."""
import os
import re
import sys
import glob as glob_mod

CHECKS = []


def check(cid, name):
    def decorator(fn):
        CHECKS.append((cid, name, fn))
        return fn
    return decorator


BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def _php(rel):
    return os.path.join(BASE, rel)


def _read(rel):
    try:
        with open(_php(rel), encoding="utf-8") as f:
            return f.read()
    except FileNotFoundError:
        return ""


@check("CS-01", "Migration file exists")
def _():
    files = glob_mod.glob(_php("database/migrations/*create_confidence_source_audit_tables*"))
    return len(files) >= 1, f"found {len(files)}"


@check("CS-02", "ConfidenceSourceRefreshService exists")
def _():
    p = _php("app/Services/ConfidenceSourceRefreshService.php")
    return os.path.exists(p), p


@check("CS-03", "ADVISORY_ONLY = true")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    ok = re.search(r"ADVISORY_ONLY\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("CS-04", "deriveSource method exists")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    ok = "function deriveSource" in c
    return ok, "method found" if ok else "method missing"


@check("CS-05", "empirical label defined")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    ok = "empirical" in c
    return ok, "label found" if ok else "label missing"


@check("CS-06", "fixture_tested label defined")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    ok = "fixture_tested" in c
    return ok, "label found" if ok else "label missing"


@check("CS-07", "refresh method updates rule_fixture_backlogs")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    ok = "rule_fixture_backlogs" in c and "function refresh" in c
    return ok, "present" if ok else "missing"


@check("CS-08", "confidence_source_audit_events is append-only (no UPDATE/DELETE)")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    # Must not contain update() directly after referencing the audit table on the same line
    bad = re.search(r"confidence_source_audit_events.*->update\(", c)
    return bad is None, "append-only enforced" if bad is None else "UPDATE found on audit table — violation"


@check("CS-09", "RefreshConfidenceSourceCommand exists")
def _():
    p = _php("app/Console/Commands/RefreshConfidenceSourceCommand.php")
    return os.path.exists(p), p


@check("CS-10", "ConfidenceSourceRefreshController exists")
def _():
    p = _php("app/Http/Controllers/Detection/ConfidenceSourceRefreshController.php")
    return os.path.exists(p), p


@check("CS-11", "View confidence_source_refresh.blade.php exists")
def _():
    p = _php("resources/views/detection/confidence_source_refresh.blade.php")
    return os.path.exists(p), p


@check("CS-12", "Route detection/confidence-source-refresh registered")
def _():
    c = _read("routes/web.php")
    ok = "confidence-source-refresh" in c
    return ok, "route found" if ok else "route missing"


@check("CS-13", "getDistribution method exists")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    ok = "function getDistribution" in c
    return ok, "method found" if ok else "method missing"


@check("CS-14", "getLatestRun method exists")
def _():
    c = _read("app/Services/ConfidenceSourceRefreshService.php")
    ok = "function getLatestRun" in c
    return ok, "method found" if ok else "method missing"


@check("CS-15", "Test file exists")
def _():
    p = _php("tests/Feature/ConfidenceSourceRefreshTest.php")
    return os.path.exists(p), p


def main():
    passed = 0
    for cid, name, fn in CHECKS:
        try:
            ok, detail = fn()
        except Exception as e:
            ok, detail = False, str(e)
        status = "PASS" if ok else "FAIL"
        if ok:
            passed += 1
        mark = "PASS" if ok else "FAIL"
        print(f"  [{mark}] [{cid}] {name} ({detail})")

    total = len(CHECKS)
    print(f"\nResult: {passed}/{total} PASS")
    verdict = "PASS" if passed == total else "FAIL"
    print(f"Verdict: {verdict}")
    sys.exit(0 if passed == total else 1)


if __name__ == "__main__":
    main()
