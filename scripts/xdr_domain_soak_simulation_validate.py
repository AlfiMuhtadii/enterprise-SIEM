#!/usr/bin/env python3
"""ENTERPRISE-057: Domain soak simulation validator. Offline structural checks only."""
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


@check("DS-01", "Migration file exists")
def _():
    files = glob_mod.glob(_php("database/migrations/*create_domain_soak_simulation_tables*"))
    return len(files) >= 1, f"found {len(files)}"


@check("DS-02", "DomainSoakSimulation model exists")
def _():
    p = _php("app/Models/DomainSoakSimulation.php")
    return os.path.exists(p), p


@check("DS-03", "DomainSoakSimulationService exists")
def _():
    p = _php("app/Services/DomainSoakSimulationService.php")
    return os.path.exists(p), p


@check("DS-04", "PROMOTION_RECOMMENDED = false (always)")
def _():
    c = _read("app/Services/DomainSoakSimulationService.php")
    ok = re.search(r"PROMOTION_RECOMMENDED\s*=\s*false", c) is not None
    return ok, "constant found" if ok else "MISSING — safety violation"


@check("DS-05", "REAL_SOAK_REQUIRED = true (always)")
def _():
    c = _read("app/Services/DomainSoakSimulationService.php")
    ok = re.search(r"REAL_SOAK_REQUIRED\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "MISSING — safety violation"


@check("DS-06", "ADVISORY_ONLY = true")
def _():
    c = _read("app/Services/DomainSoakSimulationService.php")
    ok = re.search(r"ADVISORY_ONLY\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("DS-07", "SUPPORTED_DOMAINS includes endpoint/network/threat-intel")
def _():
    c = _read("app/Services/DomainSoakSimulationService.php")
    ok = all(d in c for d in ["endpoint", "network", "threat-intel"])
    return ok, "all domains present" if ok else "domain missing"


@check("DS-08", "STRUCTURAL_PASS_RATE = 0.80")
def _():
    c = _read("app/Services/DomainSoakSimulationService.php")
    ok = re.search(r"STRUCTURAL_PASS_RATE\s*=\s*0\.80", c) is not None
    return ok, "threshold found" if ok else "threshold missing"


@check("DS-09", "RunDomainSoakSimulationCommand exists")
def _():
    p = _php("app/Console/Commands/RunDomainSoakSimulationCommand.php")
    return os.path.exists(p), p


@check("DS-10", "DomainSoakSimulationController exists")
def _():
    p = _php("app/Http/Controllers/Detection/DomainSoakSimulationController.php")
    return os.path.exists(p), p


@check("DS-11", "View domain_soak_simulations.blade.php exists")
def _():
    p = _php("resources/views/detection/domain_soak_simulations.blade.php")
    return os.path.exists(p), p


@check("DS-12", "Route detection/domain-soak-simulations registered")
def _():
    c = _read("routes/web.php")
    ok = "domain-soak-simulations" in c
    return ok, "route found" if ok else "route missing"


@check("DS-13", "Service has simulate method")
def _():
    c = _read("app/Services/DomainSoakSimulationService.php")
    ok = "function simulate" in c
    return ok, "method found" if ok else "method missing"


@check("DS-14", "Service has simulateAll method")
def _():
    c = _read("app/Services/DomainSoakSimulationService.php")
    ok = "function simulateAll" in c
    return ok, "method found" if ok else "method missing"


@check("DS-15", "Test file exists")
def _():
    p = _php("tests/Feature/DomainSoakSimulationTest.php")
    return os.path.exists(p), p


def main():
    results = []
    passed = 0
    for cid, name, fn in CHECKS:
        try:
            ok, detail = fn()
        except Exception as e:
            ok, detail = False, str(e)
        status = "PASS" if ok else "FAIL"
        if ok:
            passed += 1
        results.append({"check": cid, "name": name, "status": status, "detail": detail})
        mark = "PASS" if ok else "FAIL"
        print(f"  [{mark}] [{cid}] {name} ({detail})")

    total = len(CHECKS)
    print(f"\nResult: {passed}/{total} PASS")
    verdict = "PASS" if passed == total else "FAIL"
    print(f"Verdict: {verdict}")
    sys.exit(0 if passed == total else 1)


if __name__ == "__main__":
    main()
