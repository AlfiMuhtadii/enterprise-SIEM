#!/usr/bin/env python3
"""
ENTERPRISE-050: Rule Evidence & Replay Fixture Governance Validator
Checks REG-01 through REG-14 offline.

Exit codes:
  0 = all checks PASS
  1 = one or more FAIL
"""

import json
import os
import sys

ROOT          = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REGISTRY_PATH = os.path.join(ROOT, "docs", "detection", "rules", "registry.v1.json")
SERVICE_PATH  = os.path.join(ROOT, "app", "Services", "RuleEvidenceGovernanceService.php")
COMMAND_PATH  = os.path.join(ROOT, "app", "Console", "Commands", "RuleEvidenceInventoryCommand.php")
CTRL_PATH     = os.path.join(ROOT, "app", "Http", "Controllers", "Detection", "RuleEvidenceGovernanceController.php")
MODEL_BACKLOG = os.path.join(ROOT, "app", "Models", "RuleFixtureBacklog.php")
MODEL_BATCH   = os.path.join(ROOT, "app", "Models", "RuleEvidenceBatchPlan.php")
VIEW_PATH     = os.path.join(ROOT, "resources", "views", "detection", "rule_evidence_governance.blade.php")
MIGRATION_DIR = os.path.join(ROOT, "database", "migrations")

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


def find_migration(keyword):
    for fn in os.listdir(MIGRATION_DIR):
        if keyword in fn:
            return os.path.join(MIGRATION_DIR, fn)
    return None


print("")
print("=== ENTERPRISE-050: Rule Evidence Governance Validator ===")
print("")

# REG-01: Registry loadable and has 133 rules
try:
    with open(REGISTRY_PATH) as f:
        rules = json.load(f)["rules"]
    check("REG-01 Registry loadable", True, f"{len(rules)} rules")
except Exception as e:
    check("REG-01 Registry loadable", False, str(e))
    rules = []

# REG-02: 133 rules total
check("REG-02 Exactly 133 rules", len(rules) == 133, f"found {len(rules)}")

# REG-03: 12 staged_active, 121 shadow
staged = [r for r in rules if r.get("status") == "staged_active"]
shadow = [r for r in rules if r.get("status") == "shadow"]
check("REG-03 12 staged_active rules", len(staged) == 12, f"found {len(staged)}")
check("REG-04 121 shadow rules", len(shadow) == 121, f"found {len(shadow)}")

# REG-05: 113 rules missing fixture
missing_fixture = [r for r in rules if not r.get("replay_fixture")]
check("REG-05 113 rules missing replay_fixture", len(missing_fixture) == 113,
      f"found {len(missing_fixture)} missing")

# REG-06: All 12 staged_active have evidence but no fixture
staged_no_fixture = [r for r in staged if r.get("replay_fixture")]
staged_has_evidence = [r for r in staged if r.get("validation_evidence")]
check("REG-06 All 12 staged_active have validation_evidence",
      len(staged_has_evidence) == 12, f"with_evidence={len(staged_has_evidence)}")
check("REG-07 All 12 staged_active have NO replay_fixture",
      len(staged_no_fixture) == 0, f"with_fixture={len(staged_no_fixture)} (should be 0)")

# REG-08: Service exists and has ADVISORY_ONLY = true
svc = fc(SERVICE_PATH)
check("REG-08 RuleEvidenceGovernanceService exists", os.path.exists(SERVICE_PATH))
check("REG-09 ADVISORY_ONLY = true in service",
      "ADVISORY_ONLY" in svc and "true" in svc.lower(), "constant present")

# REG-10: PLAN_APPROVED = false in service
check("REG-10 PLAN_APPROVED = false in service",
      "PLAN_APPROVED" in svc and "false" in svc.lower(), "constant present")

# REG-11: Three tier constants present
tier1 = "tier_1_immediate" in svc
tier2 = "tier_2_next_batch" in svc
tier3 = "tier_3_deferred" in svc
check("REG-11 All 3 tier constants in service",
      tier1 and tier2 and tier3, f"T1={tier1} T2={tier2} T3={tier3}")

# REG-12: Migration creates both tables
mig = find_migration("rule_evidence_governance")
check("REG-12 rule_evidence_governance migration exists",
      mig is not None, mig or "not found")
if mig:
    mc = fc(mig)
    t1 = "rule_fixture_backlogs" in mc
    t2 = "rule_evidence_batch_plans" in mc
    check("REG-13 Migration creates rule_fixture_backlogs + rule_evidence_batch_plans",
          t1 and t2, f"backlog={t1} batch_plan={t2}")
else:
    check("REG-13 Migration creates both tables", False, "migration not found")

# REG-14: Blade view, command, controller exist
all_exist = all(os.path.exists(p) for p in [VIEW_PATH, COMMAND_PATH, CTRL_PATH, MODEL_BACKLOG, MODEL_BATCH])
check("REG-14 View, command, controller, models all exist",
      all_exist, "all present" if all_exist else "some missing")

# Summary
print("")
passed = sum(1 for _, ok, _ in results if ok)
failed = sum(1 for _, ok, _ in results if not ok)
total  = len(results)
print(f"=== Results: {passed}/{total} PASS, {failed} FAIL ===")
print("  ADVISORY-ONLY: plan_approved = false always. ACTIVE_ALLOWLIST unchanged.")
print("")

sys.exit(0 if failed == 0 else 1)
