#!/usr/bin/env python3
"""ENTERPRISE-064: Phase 1 Soak Evidence Freeze validator. Offline structural checks only."""
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


def _path(rel):
    return os.path.join(BASE, rel)


def _read(rel):
    try:
        with open(_path(rel), encoding="utf-8") as f:
            return f.read()
    except FileNotFoundError:
        return ""


@check("PFV-01", "Migration file exists")
def _():
    files = glob_mod.glob(_path("database/migrations/*create_phase1_soak_freeze_tables*"))
    return len(files) >= 1, f"found {len(files)}"


@check("PFV-02", "Phase1SoakFreezeRun model exists")
def _():
    p = _path("app/Models/Phase1SoakFreezeRun.php")
    return os.path.exists(p), p


@check("PFV-03", "Phase1SoakEvidenceFreezeService exists")
def _():
    p = _path("app/Services/Phase1SoakEvidenceFreezeService.php")
    return os.path.exists(p), p


@check("PFV-04", "ADVISORY_ONLY = true")
def _():
    c = _read("app/Services/Phase1SoakEvidenceFreezeService.php")
    ok = re.search(r"ADVISORY_ONLY\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PFV-05", "NO_PROMOTION = true")
def _():
    c = _read("app/Services/Phase1SoakEvidenceFreezeService.php")
    ok = re.search(r"NO_PROMOTION\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PFV-06", "FREEZE_APPROVED = false")
def _():
    c = _read("app/Services/Phase1SoakEvidenceFreezeService.php")
    ok = re.search(r"FREEZE_APPROVED\s*=\s*false", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PFV-07", "GATES_TOTAL = 12")
def _():
    c = _read("app/Services/Phase1SoakEvidenceFreezeService.php")
    ok = re.search(r"GATES_TOTAL\s*=\s*12", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PFV-08", "12 gate IDs EV064-01..EV064-12 defined")
def _():
    c = _read("app/Services/Phase1SoakEvidenceFreezeService.php")
    ids = re.findall(r"EV064-\d{2}", c)
    unique = set(ids)
    expected = {f"EV064-{i:02d}" for i in range(1, 13)}
    ok = expected.issubset(unique)
    return ok, f"found {len(unique & expected)}/12 expected gate IDs"


@check("PFV-09", "freeze_approved hardcoded false in persist()")
def _():
    c = _read("app/Services/Phase1SoakEvidenceFreezeService.php")
    ok = re.search(r"'freeze_approved'\s*=>\s*false", c) is not None
    return ok, "found in persist()" if ok else "missing — freeze_approved must be hardcoded false"


@check("PFV-10", "SoakPhase1FreezeCommand exists with --dry-run")
def _():
    c = _read("app/Console/Commands/SoakPhase1FreezeCommand.php")
    ok = "dry-run" in c and "soak:phase1-freeze" in c
    return ok, "command + flag found" if ok else "command or flag missing"


@check("PFV-11", "Route /detection/phase1-soak-freeze registered")
def _():
    c = _read("routes/web.php")
    ok = "phase1-soak-freeze" in c
    return ok, "route found" if ok else "route missing"


@check("PFV-12", "View phase1_soak_freeze.blade.php exists")
def _():
    p = _path("resources/views/detection/phase1_soak_freeze.blade.php")
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
        print(f"  [{status}] [{cid}] {name} ({detail})")

    total = len(CHECKS)
    print(f"\nResult: {passed}/{total} PASS")
    verdict = "PASS" if passed == total else "FAIL"
    print(f"Verdict: {verdict}")
    sys.exit(0 if passed == total else 1)


if __name__ == "__main__":
    main()
