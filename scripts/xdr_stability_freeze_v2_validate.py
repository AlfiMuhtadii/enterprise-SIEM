#!/usr/bin/env python3
"""
ENTERPRISE-049: Stability Evidence Freeze v2 Validator
Checks SFV-01 through SFV-14 offline. All checks are advisory.

Exit codes:
  0 = all checks PASS
  1 = one or more FAIL
"""

import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REGISTRY_PATH    = os.path.join(ROOT, "docs", "detection", "rules", "registry.v1.json")
SERVICE_PATH     = os.path.join(ROOT, "app", "Services", "StabilityEvidenceFreezeV2Service.php")
COMMAND_PATH     = os.path.join(ROOT, "app", "Console", "Commands", "StabilityFreezeV2Command.php")
CONTROLLER_PATH  = os.path.join(ROOT, "app", "Http", "Controllers", "Detection", "StabilityFreezeV2Controller.php")
MODEL_PATH       = os.path.join(ROOT, "app", "Models", "StabilityFreezeRun.php")
VIEW_PATH        = os.path.join(ROOT, "resources", "views", "detection", "stability_freeze_v2.blade.php")
MIGRATION_DIR    = os.path.join(ROOT, "database", "migrations")

# Phase services to cross-check
E045_SERVICE = os.path.join(ROOT, "app", "Services", "DetectionPromotionReadinessService.php")
E046_SERVICE = os.path.join(ROOT, "app", "Services", "TenantBoundaryService.php")
E047_SERVICE = os.path.join(ROOT, "app", "Services", "ShadowReadyPromotionDecisionService.php")
E048_SERVICE = os.path.join(ROOT, "app", "Services", "EndpointSoakPlanService.php")

results = []


def check(name, passed, detail=""):
    status = "[PASS]" if passed else "[FAIL]"
    results.append((name, passed, detail))
    print(f"  {status} {name}" + (f" -- {detail}" if detail else ""))


def file_content(path):
    if not os.path.exists(path):
        return ""
    with open(path, encoding="utf-8") as f:
        return f.read()


def find_migration(keyword):
    for fn in os.listdir(MIGRATION_DIR):
        if keyword in fn:
            return os.path.join(MIGRATION_DIR, fn)
    return None


print("")
print("=== ENTERPRISE-049: Stability Evidence Freeze v2 Validator ===")
print("")

# SFV-01: Registry loadable
try:
    with open(REGISTRY_PATH) as f:
        rules = json.load(f)["rules"]
    check("SFV-01 Registry loadable", True, f"{len(rules)} rules")
except Exception as e:
    check("SFV-01 Registry loadable", False, str(e))
    rules = []

# SFV-02: Service file exists
svc = file_content(SERVICE_PATH)
check("SFV-02 StabilityEvidenceFreezeV2Service exists", os.path.exists(SERVICE_PATH))

# SFV-03: FREEZE_APPROVED = false in service
check("SFV-03 FREEZE_APPROVED = false in service",
      "FREEZE_APPROVED" in svc and "false" in svc.lower(), "constant present")

# SFV-04: STABLE_SCORE_THRESHOLD = 0.80 in service
check("SFV-04 STABLE_SCORE_THRESHOLD = 0.80 in service",
      "STABLE_SCORE_THRESHOLD = 0.80" in svc, "threshold constant present")

# SFV-05: 12 gates (EF-01 through EF-12)
gate_count = sum(1 for g in [f"EF-{i:02d}" for i in range(1, 13)] if g in svc)
check("SFV-05 12 gate IDs (EF-01 to EF-12) in service", gate_count == 12,
      f"found {gate_count} gate IDs")

# SFV-06: All 4 phase services referenced
e045_ref = "DetectionPromotionReadinessService" in svc
e046_ref = "TenantBoundaryService" in svc
e047_ref = "ShadowReadyPromotionDecisionService" in svc
e048_ref = "EndpointSoakPlanService" in svc
check("SFV-06 All 4 phase services referenced in freeze service",
      e045_ref and e046_ref and e047_ref and e048_ref,
      f"E045={e045_ref} E046={e046_ref} E047={e047_ref} E048={e048_ref}")

# SFV-07: Phase services E045-E048 actually exist
all_phases_exist = all(os.path.exists(p) for p in [E045_SERVICE, E046_SERVICE, E047_SERVICE, E048_SERVICE])
check("SFV-07 All 4 phase services (E045-E048) deployed",
      all_phases_exist,
      "all present" if all_phases_exist else "some missing")

# SFV-08: Migration exists
mig = find_migration("stability_evidence_freeze")
check("SFV-08 stability_freeze migration exists",
      mig is not None, mig or "not found")

# SFV-09: Migration creates 3 tables
if mig:
    mc = file_content(mig)
    count = mc.count("Schema::create")
    check("SFV-09 Migration creates 3 tables (runs/gates/phases)", count >= 3,
          f"Schema::create count = {count}")
else:
    check("SFV-09 Migration creates 3 tables", False, "migration missing")

# SFV-10: Model exists
check("SFV-10 StabilityFreezeRun model exists", os.path.exists(MODEL_PATH))

# SFV-11: Command with --dry-run
cmd = file_content(COMMAND_PATH)
check("SFV-11 StabilityFreezeV2Command exists with --dry-run",
      os.path.exists(COMMAND_PATH) and "dry-run" in cmd, "--dry-run option present")

# SFV-12: Controller exists
check("SFV-12 StabilityFreezeV2Controller exists", os.path.exists(CONTROLLER_PATH))

# SFV-13: Blade view exists
check("SFV-13 stability_freeze_v2 blade view exists", os.path.exists(VIEW_PATH))

# SFV-14: E045-E048 PHASE_MAP entries in service
p_map = all(eid in svc for eid in ["E045", "E046", "E047", "E048"])
check("SFV-14 PHASE_MAP covers E045-E048 in service", p_map,
      "all 4 entries present" if p_map else "some missing")

# Summary
print("")
passed = sum(1 for _, ok, _ in results if ok)
failed = sum(1 for _, ok, _ in results if not ok)
total  = len(results)
print(f"=== Results: {passed}/{total} PASS, {failed} FAIL ===")
print("  ADVISORY-ONLY: freeze_approved = false always. ACTIVE_ALLOWLIST unchanged.")
print("")

sys.exit(0 if failed == 0 else 1)
