#!/usr/bin/env python3
"""
ENTERPRISE-055: Stability Evidence Freeze v3 Validator
Checks SFV3-01 through SFV3-15 offline.

Exit codes:
  0 = all checks PASS
  1 = one or more FAIL
"""

import os
import re
import sys

ROOT        = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SVC_PATH    = os.path.join(ROOT, "app", "Services", "StabilityEvidenceFreezeV3Service.php")
MODEL_PATH  = os.path.join(ROOT, "app", "Models", "StabilityFreezeV3Run.php")
CMD_PATH    = os.path.join(ROOT, "app", "Console", "Commands", "StabilityFreezeV3Command.php")
CTRL_PATH   = os.path.join(ROOT, "app", "Http", "Controllers", "Detection", "StabilityFreezeV3Controller.php")
VIEW_PATH   = os.path.join(ROOT, "resources", "views", "detection", "stability_freeze_v3.blade.php")
TEST_PATH   = os.path.join(ROOT, "tests", "Feature", "StabilityEvidenceFreezeV3Test.php")
EVID_PATH   = os.path.join(ROOT, "docs", "validation", "STABILITY_FREEZE_V3_EVIDENCE.md")
MIG_GLOB    = os.path.join(ROOT, "database", "migrations")
ROUTE_PATH  = os.path.join(ROOT, "routes", "web.php")

results = []


def check(name, passed, detail=""):
    status = "[PASS]" if passed else "[FAIL]"
    results.append((name, passed, detail))
    print(f"  {status} {name}" + (f" -- {detail}" if detail else ""))


def fc(path):
    if not os.path.exists(path):
        return ""
    with open(path, encoding="utf-8") as f:
        return f.read()


def migration_exists(prefix):
    for f in os.listdir(MIG_GLOB):
        if f.endswith(".php") and prefix in f:
            return True
    return False


print("")
print("=== ENTERPRISE-055: Stability Evidence Freeze v3 Validator ===")
print("")

svc  = fc(SVC_PATH)
cmd  = fc(CMD_PATH)
test = fc(TEST_PATH)

# SFV3-01: migration file present
check("SFV3-01 v3 migration file present",
      migration_exists("stability_freeze_v3"), "migration with v3 tables")

# SFV3-02: service class exists
check("SFV3-02 StabilityEvidenceFreezeV3Service.php exists", os.path.exists(SVC_PATH))

# SFV3-03: model class exists
check("SFV3-03 StabilityFreezeV3Run.php exists", os.path.exists(MODEL_PATH))

# SFV3-04: command exists
check("SFV3-04 StabilityFreezeV3Command.php exists", os.path.exists(CMD_PATH))

# SFV3-05: controller exists
check("SFV3-05 StabilityFreezeV3Controller.php exists", os.path.exists(CTRL_PATH))

# SFV3-06: view template exists
check("SFV3-06 stability_freeze_v3.blade.php exists", os.path.exists(VIEW_PATH))

# SFV3-07: test file exists
check("SFV3-07 StabilityEvidenceFreezeV3Test.php exists", os.path.exists(TEST_PATH))

# SFV3-08: FREEZE_VERSION = 'v3'
check("SFV3-08 FREEZE_VERSION = 'v3'",
      "FREEZE_VERSION  = 'v3'" in svc or "FREEZE_VERSION = 'v3'" in svc, "constant present")

# SFV3-09: PHASE_RANGE = 'E045-E054'
check("SFV3-09 PHASE_RANGE = 'E045-E054'",
      "PHASE_RANGE     = 'E045-E054'" in svc or "PHASE_RANGE = 'E045-E054'" in svc, "constant present")

# SFV3-10: FREEZE_APPROVED = false
check("SFV3-10 FREEZE_APPROVED = false",
      bool(re.search(r"FREEZE_APPROVED\s*=\s*false", svc)), "safety constant present")

# SFV3-11: ADVISORY_ONLY = true
check("SFV3-11 ADVISORY_ONLY = true",
      bool(re.search(r"ADVISORY_ONLY\s*=\s*true", svc)), "safety constant present")

# SFV3-12: 22 gates (EV3-01 through EV3-22) defined in service
gate_ids = re.findall(r"'EV3-(\d+)'", svc)
unique_gates = set(int(g) for g in gate_ids)
check("SFV3-12 all 22 gates EV3-01 through EV3-22 defined",
      unique_gates == set(range(1, 23)),
      f"found gate numbers: {sorted(unique_gates)}")

# SFV3-13: allowed claims present in service
check("SFV3-13 allowed claims list present in service",
      "claim_type' => 'allowed'" in svc or "'allowed'" in svc and "claim_text" in svc,
      "allowed claims block found")

# SFV3-14: forbidden claims present in service
check("SFV3-14 forbidden claims list present in service",
      "claim_type' => 'forbidden'" in svc or "'forbidden'" in svc and "claim_text" in svc,
      "forbidden claims block found")

# SFV3-15: evidence doc exists
check("SFV3-15 STABILITY_FREEZE_V3_EVIDENCE.md exists", os.path.exists(EVID_PATH))

# Summary
print("")
passed = sum(1 for _, ok, _ in results if ok)
failed = sum(1 for _, ok, _ in results if not ok)
total  = len(results)
print(f"=== Results: {passed}/{total} PASS, {failed} FAIL ===")
print("  ADVISORY-ONLY. freeze_approved=false. Evidence consolidates E045-E054.")
print("")

sys.exit(0 if failed == 0 else 1)
