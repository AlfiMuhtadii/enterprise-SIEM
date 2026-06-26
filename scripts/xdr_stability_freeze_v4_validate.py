#!/usr/bin/env python3
"""ENTERPRISE-059: Stability Evidence Freeze v4 validator. Offline structural checks only."""
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


@check("SFV4-01", "Migration file exists")
def _():
    files = glob_mod.glob(_php("database/migrations/*create_stability_freeze_v4_tables*"))
    return len(files) >= 1, f"found {len(files)}"


@check("SFV4-02", "StabilityFreezeV4Run model exists")
def _():
    p = _php("app/Models/StabilityFreezeV4Run.php")
    return os.path.exists(p), p


@check("SFV4-03", "StabilityEvidenceFreezeV4Service exists")
def _():
    p = _php("app/Services/StabilityEvidenceFreezeV4Service.php")
    return os.path.exists(p), p


@check("SFV4-04", "FREEZE_APPROVED = false")
def _():
    c = _read("app/Services/StabilityEvidenceFreezeV4Service.php")
    ok = re.search(r"FREEZE_APPROVED\s*=\s*false", c) is not None
    return ok, "constant found" if ok else "MISSING — safety violation"


@check("SFV4-05", "FREEZE_VERSION = 'v4'")
def _():
    c = _read("app/Services/StabilityEvidenceFreezeV4Service.php")
    ok = re.search(r"FREEZE_VERSION\s*=\s*'v4'", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("SFV4-06", "PHASE_RANGE = 'E055-E058'")
def _():
    c = _read("app/Services/StabilityEvidenceFreezeV4Service.php")
    ok = re.search(r"PHASE_RANGE\s*=\s*'E055-E058'", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("SFV4-07", "16 gates defined (EV4-01 through EV4-16)")
def _():
    c = _read("app/Services/StabilityEvidenceFreezeV4Service.php")
    ids = re.findall(r"EV4-\d+", c)
    unique = set(ids)
    ok = len(unique) >= 16
    return ok, f"found {len(unique)} unique gate IDs"


@check("SFV4-08", "4 phase summaries defined (E055-E058)")
def _():
    c = _read("app/Services/StabilityEvidenceFreezeV4Service.php")
    # PHASE_MAP defines phases as 'E055' => '...' etc.
    phases = re.findall(r"'E05[5-8]'\s*=>", c)
    ok = len(phases) >= 4
    return ok, f"found {len(phases)} phase keys (E055-E058)"


@check("SFV4-09", "StabilityFreezeV4Command exists")
def _():
    p = _php("app/Console/Commands/StabilityFreezeV4Command.php")
    return os.path.exists(p), p


@check("SFV4-10", "StabilityFreezeV4Controller exists")
def _():
    p = _php("app/Http/Controllers/Detection/StabilityFreezeV4Controller.php")
    return os.path.exists(p), p


@check("SFV4-11", "View stability_freeze_v4.blade.php exists")
def _():
    p = _php("resources/views/detection/stability_freeze_v4.blade.php")
    return os.path.exists(p), p


@check("SFV4-12", "Route detection/stability-freeze-v4 registered")
def _():
    c = _read("routes/web.php")
    ok = "stability-freeze-v4" in c
    return ok, "route found" if ok else "route missing"


@check("SFV4-13", "Allowed claims defined (>= 5 items in $allowed array)")
def _():
    c = _read("app/Services/StabilityEvidenceFreezeV4Service.php")
    allowed_section = re.search(r"\$allowed\s*=\s*\[(.+?)\];", c, re.DOTALL)
    count = 0
    if allowed_section:
        count = len(re.findall(r"'[^']+'", allowed_section.group(1)))
    ok = count >= 5
    return ok, f"found {count} items in $allowed array"


@check("SFV4-14", "Forbidden claims defined (>= 3, in $forbidden array)")
def _():
    c = _read("app/Services/StabilityEvidenceFreezeV4Service.php")
    # Check that there are at least 3 distinct string items in the $forbidden array
    # by searching for the foreach that produces forbidden claims
    ok = "claim_type' => 'forbidden'" in c or "claim_type'=>'forbidden'" in c or re.search(r"'claim_type'\s*=>\s*'forbidden'", c) is not None
    # Also count distinct items by looking for the $forbidden array body
    forbidden_section = re.search(r"\$forbidden\s*=\s*\[(.+?)\];", c, re.DOTALL)
    count = 0
    if forbidden_section:
        count = len(re.findall(r"'[^']+",  forbidden_section.group(1)))
    ok2 = count >= 3
    return ok2, f"found {count} items in $forbidden array"


@check("SFV4-15", "Evidence doc exists")
def _():
    p = _php("docs/validation/STABILITY_FREEZE_V4_EVIDENCE.md")
    return os.path.exists(p), p


@check("SFV4-16", "Test file exists")
def _():
    p = _php("tests/Feature/StabilityEvidenceFreezeV4Test.php")
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
