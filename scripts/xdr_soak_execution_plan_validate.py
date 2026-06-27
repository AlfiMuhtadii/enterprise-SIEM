#!/usr/bin/env python3
"""ENTERPRISE-060: Real Domain Soak Execution Plan validator. Offline structural checks only."""
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


@check("SEP-01", "Migration file exists")
def _():
    files = glob_mod.glob(_php("database/migrations/*create_real_domain_soak_plan_tables*"))
    return len(files) >= 1, f"found {len(files)}"


@check("SEP-02", "SoakPlanRun model exists")
def _():
    p = _php("app/Models/SoakPlanRun.php")
    return os.path.exists(p), p


@check("SEP-03", "RealDomainSoakPlanService exists")
def _():
    p = _php("app/Services/RealDomainSoakPlanService.php")
    return os.path.exists(p), p


@check("SEP-04", "ADVISORY_ONLY = true")
def _():
    c = _read("app/Services/RealDomainSoakPlanService.php")
    ok = re.search(r"ADVISORY_ONLY\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("SEP-05", "REAL_EXECUTION_GATED = true")
def _():
    c = _read("app/Services/RealDomainSoakPlanService.php")
    ok = re.search(r"REAL_EXECUTION_GATED\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("SEP-06", "PHASES_TOTAL = 4")
def _():
    c = _read("app/Services/RealDomainSoakPlanService.php")
    ok = re.search(r"PHASES_TOTAL\s*=\s*4", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("SEP-07", "All 4 phases defined in PHASE_DEFINITIONS")
def _():
    c = _read("app/Services/RealDomainSoakPlanService.php")
    phases = re.findall(r"^\s+[1-4]\s*=>\s*\[", c, re.MULTILINE)
    ok = len(phases) >= 4
    return ok, f"found {len(phases)} phase keys"


@check("SEP-08", "16 SPG gate IDs defined (SPG-P1-01 through SPG-P4-04)")
def _():
    c = _read("app/Services/RealDomainSoakPlanService.php")
    ids = re.findall(r"SPG-P[1-4]-0[1-4]", c)
    unique = set(ids)
    ok = len(unique) >= 16
    return ok, f"found {len(unique)} unique gate IDs"


@check("SEP-09", "promotion_gated = true in phases")
def _():
    c = _read("app/Services/RealDomainSoakPlanService.php")
    ok = re.search(r"promotion_gated.*true", c) is not None
    return ok, "found" if ok else "missing"


@check("SEP-10", "SPG-P2-04 checks PROMOTION_RECOMMENDED safety constant")
def _():
    c = _read("app/Services/RealDomainSoakPlanService.php")
    ok = "PROMOTION_RECOMMENDED" in c and "SPG-P2-04" in c
    return ok, "safety gate present" if ok else "safety gate missing"


@check("SEP-11", "SoakPlanReviewCommand exists")
def _():
    p = _php("app/Console/Commands/SoakPlanReviewCommand.php")
    return os.path.exists(p), p


@check("SEP-12", "SoakExecutionPlanController exists")
def _():
    p = _php("app/Http/Controllers/Detection/SoakExecutionPlanController.php")
    return os.path.exists(p), p


@check("SEP-13", "View soak_execution_plan.blade.php exists")
def _():
    p = _php("resources/views/detection/soak_execution_plan.blade.php")
    return os.path.exists(p), p


@check("SEP-14", "Route detection/soak-execution-plan registered")
def _():
    c = _read("routes/web.php")
    ok = "soak-execution-plan" in c
    return ok, "route found" if ok else "route missing"


@check("SEP-15", "Test file exists")
def _():
    p = _php("tests/Feature/RealDomainSoakPlanTest.php")
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
