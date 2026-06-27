#!/usr/bin/env python3
"""ENTERPRISE-061: Phase 1 Soak Execution validator. Offline structural checks only."""
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


@check("PSV-01", "Migration file exists")
def _():
    files = glob_mod.glob(_path("database/migrations/*create_phase1_soak_evidence_tables*"))
    return len(files) >= 1, f"found {len(files)}"


@check("PSV-02", "Phase1SoakRun model exists")
def _():
    p = _path("app/Models/Phase1SoakRun.php")
    return os.path.exists(p), p


@check("PSV-03", "Phase1SoakExecutionService exists")
def _():
    p = _path("app/Services/Phase1SoakExecutionService.php")
    return os.path.exists(p), p


@check("PSV-04", "ADVISORY_ONLY = true")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = re.search(r"ADVISORY_ONLY\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PSV-05", "NO_PROMOTION = true")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = re.search(r"NO_PROMOTION\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PSV-06", "SCOPE = staged_active_empirical")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = re.search(r"SCOPE\s*=\s*'staged_active_empirical'", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PSV-07", "DURATION_MIN=30 and DURATION_MAX=60")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    has_min = re.search(r"DURATION_MIN\s*=\s*30", c) is not None
    has_max = re.search(r"DURATION_MAX\s*=\s*60", c) is not None
    ok = has_min and has_max
    return ok, f"DURATION_MIN={has_min} DURATION_MAX={has_max}"


@check("PSV-08", "GATES_TOTAL = 8")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = re.search(r"GATES_TOTAL\s*=\s*8", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PSV-09", "All 8 gate IDs P1G-01..P1G-08 defined")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ids = re.findall(r"P1G-0[1-8]", c)
    unique = set(ids)
    ok = len(unique) >= 8
    return ok, f"found {len(unique)} unique gate IDs"


@check("PSV-10", "computeDecision returns PASS/WARN/FAIL")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    has_pass = "'PASS'" in c
    has_warn = "'WARN'" in c
    has_fail = "'FAIL'" in c
    ok = has_pass and has_warn and has_fail
    return ok, f"PASS={has_pass} WARN={has_warn} FAIL={has_fail}"


@check("PSV-11", "Fail takes precedence over warn in computeDecision")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    # 'FAIL' return must appear before 'WARN' return in computeDecision
    fail_pos = c.find("return 'FAIL'")
    warn_pos = c.find("return 'WARN'")
    ok = fail_pos != -1 and warn_pos != -1 and fail_pos < warn_pos
    return ok, f"FAIL at pos {fail_pos}, WARN at pos {warn_pos}"


@check("PSV-12", "SoakPhase1RunCommand exists")
def _():
    p = _path("app/Console/Commands/SoakPhase1RunCommand.php")
    return os.path.exists(p), p


@check("PSV-13", "SoakPhase1Controller exists")
def _():
    p = _path("app/Http/Controllers/Detection/SoakPhase1Controller.php")
    return os.path.exists(p), p


@check("PSV-14", "View phase1_soak.blade.php exists")
def _():
    p = _path("resources/views/detection/phase1_soak.blade.php")
    return os.path.exists(p), p


@check("PSV-15", "Route detection/phase1-soak registered")
def _():
    c = _read("routes/web.php")
    ok = "phase1-soak" in c
    return ok, "route found" if ok else "route missing"


@check("PSV-16", "SOAK_REPORT_PATH public constant defined")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = re.search(r"public const SOAK_REPORT_PATH", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("PSV-17", "setSoakReportOverride method exists (test override support)")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = "setSoakReportOverride" in c
    return ok, "method found" if ok else "method missing"


@check("PSV-18", "P1G-04 checks confidence_source_audit_events as fallback source")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = "confidence_source_audit_events" in c and "new_confidence_source" in c
    return ok, "fallback source present" if ok else "fallback source missing"


@check("PSV-19", "P1G-03 uses base_path (not storage_path) for fixture discovery")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    has_base   = re.search(r"base_path\(self::FIXTURE_DIR\)", c) is not None
    has_wrong  = re.search(r"storage_path\(self::FIXTURE_DIR\)", c) is not None
    ok = has_base and not has_wrong
    return ok, f"base_path={has_base} storage_path(wrong)={has_wrong}"


@check("PSV-20", "FIXTURE_DIR points to tier1_batch1")
def _():
    c = _read("app/Services/Phase1SoakExecutionService.php")
    ok = re.search(r"FIXTURE_DIR\s*=\s*'tests/fixtures/detection/tier1_batch1'", c) is not None
    return ok, "correct path found" if ok else "path missing or wrong"


@check("PSV-21", "DetectionReplayFixtureService::persist uses updateOrInsert (not just update)")
def _():
    c = _read("app/Services/DetectionReplayFixtureService.php")
    has_upsert = "updateOrInsert" in c
    has_plain_update_only = (
        re.search(r"->where\('rule_id'.*\)->update\(", c) is not None
        and "updateOrInsert" not in c
    )
    ok = has_upsert and not has_plain_update_only
    return ok, f"updateOrInsert={has_upsert} plain-update-only={has_plain_update_only}"


@check("PSV-22", "has_validation_evidence set to true in fixture upsert")
def _():
    c = _read("app/Services/DetectionReplayFixtureService.php")
    ok = re.search(r"'has_validation_evidence'\s*=>\s*true", c) is not None
    return ok, "evidence flag found in upsert" if ok else "evidence flag missing"


@check("PSV-23", "SoakPhase1RunCommand has --warm-up option")
def _():
    c = _read("app/Console/Commands/SoakPhase1RunCommand.php")
    ok = "warm-up" in c and "rule:run-fixtures" in c and "rule:refresh-confidence" in c
    return ok, "warm-up wiring found" if ok else "warm-up wiring missing"


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
