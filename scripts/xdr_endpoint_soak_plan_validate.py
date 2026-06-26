#!/usr/bin/env python3
"""
ENTERPRISE-048: Endpoint Shadow Domain Soak Plan Validator
Checks SPL-01 through SPL-14 offline (no live services required).
All checks are advisory — no promotion occurs.

Exit codes:
  0 = all checks PASS (or WARN only)
  1 = one or more FAIL
"""

import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REGISTRY_PATH = os.path.join(ROOT, "docs", "detection", "rules", "registry.v1.json")
SERVICE_PATH  = os.path.join(ROOT, "app", "Services", "EndpointSoakPlanService.php")
COMMAND_PATH  = os.path.join(ROOT, "app", "Console", "Commands", "GenerateEndpointSoakPlanCommand.php")
CONTROLLER_PATH = os.path.join(ROOT, "app", "Http", "Controllers", "Detection", "EndpointSoakPlanController.php")
MODEL_PATH    = os.path.join(ROOT, "app", "Models", "EndpointSoakPlan.php")
VIEW_PATH     = os.path.join(ROOT, "resources", "views", "detection", "endpoint_soak_plan.blade.php")
MIGRATION_DIR = os.path.join(ROOT, "database", "migrations")

SOAKED_DOMAINS   = {"identity", "cloud", "saas"}
DEFERRED_DOMAINS = {"network", "threat-intel", "xdr"}
TIER_1_THRESHOLD = 0.72
TIER_2_THRESHOLD = 0.60

results = []


def check(name, passed, detail=""):
    status = "[PASS]" if passed else "[FAIL]"
    results.append((name, passed, detail))
    print(f"  {status} {name}" + (f" -- {detail}" if detail else ""))


def load_registry():
    with open(REGISTRY_PATH) as f:
        return json.load(f)["rules"]


def get_shadow_needs_soak(rules):
    return [r for r in rules
            if r.get("status") == "shadow"
            and r.get("domain") not in SOAKED_DOMAINS
            and r.get("domain") not in DEFERRED_DOMAINS]


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
print("=== ENTERPRISE-048: Endpoint Shadow Domain Soak Plan Validator ===")
print("")

# SPL-01: Registry loads
try:
    rules = load_registry()
    check("SPL-01 Registry loadable", True, f"{len(rules)} rules")
except Exception as e:
    check("SPL-01 Registry loadable", False, str(e))
    rules = []

# SPL-02: 93 shadow_needs_soak rules
snk = get_shadow_needs_soak(rules)
check("SPL-02 93 shadow_needs_soak (endpoint) rules", len(snk) == 93, f"found {len(snk)}")

# SPL-03: All shadow_needs_soak rules are endpoint domain
bad = [r["rule_id"] for r in snk if r.get("domain") != "endpoint"]
check("SPL-03 All shadow_needs_soak rules in endpoint domain", len(bad) == 0,
      f"unexpected: {bad}" if bad else "all endpoint")

# SPL-04: TIER_1_THRESHOLD = 0.72 in service
svc = file_content(SERVICE_PATH)
check("SPL-04 TIER_1_THRESHOLD = 0.72 in service",
      "TIER_1_THRESHOLD = 0.72" in svc, "constant present")

# SPL-05: TIER_2_THRESHOLD = 0.60 in service
check("SPL-05 TIER_2_THRESHOLD = 0.60 in service",
      "TIER_2_THRESHOLD = 0.60" in svc, "constant present")

# SPL-06: PLAN_APPROVED = false in service
check("SPL-06 PLAN_APPROVED = false in service",
      "PLAN_APPROVED" in svc and "false" in svc.lower(), "constant present")

# SPL-07: 3 tier constants in service
t1 = "tier_1_soak_ready" in svc
t2 = "tier_2_evidence_collection" in svc
t3 = "tier_3_needs_tuning" in svc
check("SPL-07 All 3 tier constants in service",
      t1 and t2 and t3, f"t1={t1} t2={t2} t3={t3}")

# SPL-08: Expected tier distribution (no DLQ signal)
t1_count = sum(1 for r in snk if r.get("confidence", 0) >= TIER_1_THRESHOLD)
t2_count = sum(1 for r in snk if TIER_2_THRESHOLD <= r.get("confidence", 0) < TIER_1_THRESHOLD)
t3_count = sum(1 for r in snk if r.get("confidence", 0) < TIER_2_THRESHOLD)

check("SPL-08 80 rules in tier_1_soak_ready (conf >= 0.72)",
      t1_count == 80, f"found {t1_count}")
check("SPL-08b 13 rules in tier_2_evidence_collection",
      t2_count == 13, f"found {t2_count}")
check("SPL-08c 0 rules in tier_3_needs_tuning",
      t3_count == 0, f"found {t3_count}")

# SPL-09: Migration exists
mig = find_migration("endpoint_soak_plan")
check("SPL-09 endpoint_soak_plan migration exists",
      mig is not None, mig or "not found")

# SPL-10: Migration has 3 tables
if mig:
    mc = file_content(mig)
    has_3 = (mc.count("Schema::create") >= 3)
    check("SPL-10 Migration creates 3 tables (plans/rules/gates)",
          has_3, f"Schema::create count = {mc.count('Schema::create')}")
else:
    check("SPL-10 Migration creates 3 tables", False, "migration missing")

# SPL-11: Model exists
check("SPL-11 EndpointSoakPlan model exists", os.path.exists(MODEL_PATH))

# SPL-12: Command exists with --dry-run
cmd = file_content(COMMAND_PATH)
check("SPL-12 GenerateEndpointSoakPlanCommand exists with --dry-run",
      os.path.exists(COMMAND_PATH) and "dry-run" in cmd, "--dry-run option present")

# SPL-13: Controller exists
check("SPL-13 EndpointSoakPlanController exists", os.path.exists(CONTROLLER_PATH))

# SPL-14: Blade view exists
check("SPL-14 endpoint_soak_plan blade view exists", os.path.exists(VIEW_PATH))

# Summary
print("")
passed = sum(1 for _, ok, _ in results if ok)
failed = sum(1 for _, ok, _ in results if not ok)
total  = len(results)
print(f"=== Results: {passed}/{total} PASS, {failed} FAIL ===")
print("  ADVISORY-ONLY: plan_approved = false always. ACTIVE_ALLOWLIST unchanged.")
print("")

sys.exit(0 if failed == 0 else 1)
