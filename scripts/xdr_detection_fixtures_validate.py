#!/usr/bin/env python3
"""ENTERPRISE-056: Detection replay fixture validator. Offline structural checks only."""
import json
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


@check("DF-01", "Migration file exists")
def _():
    files = glob_mod.glob(_php("database/migrations/*create_detection_fixture_tables*"))
    return len(files) >= 1, f"found {len(files)}"


@check("DF-02", "DetectionFixtureBatch model exists")
def _():
    p = _php("app/Models/DetectionFixtureBatch.php")
    return os.path.exists(p), p


@check("DF-03", "DetectionReplayFixtureService exists")
def _():
    p = _php("app/Services/DetectionReplayFixtureService.php")
    return os.path.exists(p), p


@check("DF-04", "ADVISORY_ONLY = true")
def _():
    c = _read("app/Services/DetectionReplayFixtureService.php")
    ok = re.search(r"ADVISORY_ONLY\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("DF-05", "PROMOTION_BLOCKED = true")
def _():
    c = _read("app/Services/DetectionReplayFixtureService.php")
    ok = re.search(r"PROMOTION_BLOCKED\s*=\s*true", c) is not None
    return ok, "constant found" if ok else "constant missing"


@check("DF-06", "RunDetectionFixturesCommand exists")
def _():
    p = _php("app/Console/Commands/RunDetectionFixturesCommand.php")
    return os.path.exists(p), p


@check("DF-07", "DetectionFixturesController exists")
def _():
    p = _php("app/Http/Controllers/Detection/DetectionFixturesController.php")
    return os.path.exists(p), p


@check("DF-08", "View fixture_batches.blade.php exists")
def _():
    p = _php("resources/views/detection/fixture_batches.blade.php")
    return os.path.exists(p), p


@check("DF-09", "Route detection/fixture-batches registered")
def _():
    c = _read("routes/web.php")
    ok = "fixture-batches" in c
    return ok, "route found" if ok else "route missing"


@check("DF-10", "Fixture directory exists")
def _():
    d = _php("tests/fixtures/detection/tier1_batch1")
    return os.path.isdir(d), d


@check("DF-11", "At least 12 fixture JSON files present")
def _():
    files = glob_mod.glob(_php("tests/fixtures/detection/tier1_batch1/*.json"))
    return len(files) >= 12, f"found {len(files)}"


@check("DF-12", "Fixture files are valid JSON arrays")
def _():
    files = glob_mod.glob(_php("tests/fixtures/detection/tier1_batch1/*.json"))
    invalid = []
    for f in files:
        try:
            with open(f, encoding="utf-8") as fh:
                data = json.load(fh)
            if not isinstance(data, list) or len(data) == 0:
                invalid.append(os.path.basename(f))
        except Exception as e:
            invalid.append(f"{os.path.basename(f)}: {e}")
    return len(invalid) == 0, f"invalid: {invalid}" if invalid else "all valid"


@check("DF-13", "Fixture files have required fields")
def _():
    required = {"schema_version", "normalization_version", "normalized_event_id", "ts", "telemetry_type", "event_type", "user"}
    files = glob_mod.glob(_php("tests/fixtures/detection/tier1_batch1/*.json"))
    missing = []
    for f in files:
        try:
            with open(f, encoding="utf-8") as fh:
                events = json.load(fh)
            for ev in events:
                absent = required - set(ev.keys())
                if absent:
                    missing.append(f"{os.path.basename(f)}: missing {absent}")
        except Exception:
            pass
    return len(missing) == 0, f"missing fields: {missing[:3]}" if missing else "all fields present"


@check("DF-14", "test file exists")
def _():
    p = _php("tests/Feature/DetectionReplayFixtureTest.php")
    return os.path.exists(p), p


@check("DF-15", "Service has runBatch method")
def _():
    c = _read("app/Services/DetectionReplayFixtureService.php")
    ok = "function runBatch" in c
    return ok, "method found" if ok else "method missing"


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
