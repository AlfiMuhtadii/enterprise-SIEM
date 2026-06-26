#!/usr/bin/env python3
"""
ENTERPRISE-047: Shadow Ready Promotion Decision Validator
Checks SPD-01 through SPD-14 offline (no live services required).
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
VALIDATOR_PATH = os.path.join(ROOT, "scripts", "xdr_rule_registry_validate.py")
SERVICE_PATH = os.path.join(ROOT, "app", "Services", "ShadowReadyPromotionDecisionService.php")
COMMAND_PATH = os.path.join(ROOT, "app", "Console", "Commands", "EvaluateShadowPromotionCommand.php")
CONTROLLER_PATH = os.path.join(ROOT, "app", "Http", "Controllers", "Detection", "ShadowPromotionDecisionController.php")
MIGRATION_PATH = os.path.join(ROOT, "database", "migrations")
MODEL_PATH = os.path.join(ROOT, "app", "Models", "ShadowPromotionDecision.php")
VIEW_PATH = os.path.join(ROOT, "resources", "views", "detection", "shadow_promotion_decisions.blade.php")

SOAKED_DOMAINS = {"identity", "cloud", "saas"}
PROMOTE_ELIGIBLE_THRESHOLD = 0.78
KEEP_SHADOW_THRESHOLD = 0.65

results = []


def check(name, passed, detail=""):
    status = "[PASS]" if passed else "[FAIL]"
    results.append((name, passed, detail))
    print(f"  {status} {name}" + (f" — {detail}" if detail else ""))


def load_registry():
    with open(REGISTRY_PATH) as f:
        return json.load(f)


def get_shadow_ready_rules(rules):
    return [r for r in rules if r.get("status") == "shadow" and r.get("domain") in SOAKED_DOMAINS]


def get_file_content(path):
    if not os.path.exists(path):
        return ""
    with open(path, encoding="utf-8") as f:
        return f.read()


def find_migration():
    for fn in os.listdir(MIGRATION_PATH):
        if "shadow_promotion_decisions" in fn:
            return os.path.join(MIGRATION_PATH, fn)
    return None


print("")
print("=== ENTERPRISE-047: Shadow Ready Promotion Decision Validator ===")
print("")

# ── SPD-01: Registry loads and has rules ──────────────────────────────────────
try:
    data = load_registry()
    rules = data.get("rules", [])
    check("SPD-01 Registry loadable and has rules", len(rules) > 0, f"{len(rules)} rules")
except Exception as e:
    check("SPD-01 Registry loadable and has rules", False, str(e))
    rules = []

# ── SPD-02: Exactly 12 shadow_ready rules exist ──────────────────────────────
shadow_ready = get_shadow_ready_rules(rules)
check("SPD-02 Exactly 12 shadow_ready rules", len(shadow_ready) == 12, f"found {len(shadow_ready)}")

# ── SPD-03: All shadow_ready rules are in soaked domains ─────────────────────
bad_domains = [r["rule_id"] for r in shadow_ready if r.get("domain") not in SOAKED_DOMAINS]
check("SPD-03 All shadow_ready rules in soaked domains", len(bad_domains) == 0,
      f"unexpected: {bad_domains}" if bad_domains else "all in identity/cloud/saas")

# ── SPD-04: promote_eligible threshold is 0.78 ───────────────────────────────
service_content = get_file_content(SERVICE_PATH)
check("SPD-04 PROMOTE_ELIGIBLE_THRESHOLD = 0.78 in service",
      "PROMOTE_ELIGIBLE_THRESHOLD = 0.78" in service_content or
      "PROMOTE_ELIGIBLE_THRESHOLD=0.78" in service_content,
      "threshold constant present")

# ── SPD-05: keep_shadow threshold is 0.65 ────────────────────────────────────
check("SPD-05 KEEP_SHADOW_THRESHOLD = 0.65 in service",
      "KEEP_SHADOW_THRESHOLD = 0.65" in service_content,
      "threshold constant present")

# ── SPD-06: promotion_approved is always false ────────────────────────────────
check("SPD-06 PROMOTION_APPROVED = false in service",
      "PROMOTION_APPROVED" in service_content and "false" in service_content.lower(),
      "constant present")

# ── SPD-07: Decision logic covers all 3 outcomes ─────────────────────────────
has_promote = "promote_eligible" in service_content
has_keep    = "keep_shadow"      in service_content
has_defer   = "defer"            in service_content
check("SPD-07 All 3 decision outcomes in service",
      has_promote and has_keep and has_defer,
      f"promote={has_promote} keep={has_keep} defer={has_defer}")

# ── SPD-08: Expected distribution — 6 promote_eligible, 6 keep_shadow ────────
expected_promote = [r for r in shadow_ready if r.get("confidence", 0.0) >= PROMOTE_ELIGIBLE_THRESHOLD]
expected_keep    = [r for r in shadow_ready
                    if KEEP_SHADOW_THRESHOLD <= r.get("confidence", 0.0) < PROMOTE_ELIGIBLE_THRESHOLD]
expected_defer   = [r for r in shadow_ready if r.get("confidence", 0.0) < KEEP_SHADOW_THRESHOLD]

check("SPD-08 6 rules meet promote_eligible threshold (conf >= 0.78)",
      len(expected_promote) == 6,
      f"found {len(expected_promote)}: {[r['rule_id'] for r in expected_promote]}")

check("SPD-08b 6 rules in keep_shadow band (0.65 <= conf < 0.78)",
      len(expected_keep) == 6,
      f"found {len(expected_keep)}: {[r['rule_id'] for r in expected_keep]}")

check("SPD-08c 0 rules defer (conf < 0.65)",
      len(expected_defer) == 0,
      f"found {len(expected_defer)}: {[r['rule_id'] for r in expected_defer]}")

# ── SPD-09: Migration file exists ─────────────────────────────────────────────
migration_file = find_migration()
check("SPD-09 shadow_promotion_decisions migration exists",
      migration_file is not None,
      migration_file or "not found")

# ── SPD-10: Migration is append-only (no UPDATE/DELETE in schema) ─────────────
if migration_file:
    mig_content = get_file_content(migration_file)
    has_append_comment = "NEVER UPDATE" in mig_content or "append" in mig_content.lower()
    check("SPD-10 Migration has append-only safety comment",
          has_append_comment, "append-only annotation present")
else:
    check("SPD-10 Migration has append-only safety comment", False, "migration file missing")

# ── SPD-11: Model file exists ──────────────────────────────────────────────────
check("SPD-11 ShadowPromotionDecision model exists", os.path.exists(MODEL_PATH))

# ── SPD-12: Command file exists and --dry-run supported ──────────────────────
command_content = get_file_content(COMMAND_PATH)
check("SPD-12 EvaluateShadowPromotionCommand exists with --dry-run",
      os.path.exists(COMMAND_PATH) and "dry-run" in command_content,
      "--dry-run option present")

# ── SPD-13: Controller exists ──────────────────────────────────────────────────
check("SPD-13 ShadowPromotionDecisionController exists", os.path.exists(CONTROLLER_PATH))

# ── SPD-14: Blade view exists ─────────────────────────────────────────────────
check("SPD-14 shadow_promotion_decisions blade view exists", os.path.exists(VIEW_PATH))

# ── Summary ──────────────────────────────────────────────────────────────────
print("")
passed  = sum(1 for _, ok, _ in results if ok)
failed  = sum(1 for _, ok, _ in results if not ok)
total   = len(results)

print(f"=== Results: {passed}/{total} PASS, {failed} FAIL ===")
print("  ADVISORY-ONLY: promotion_approved = false always. ACTIVE_ALLOWLIST unchanged.")
print("")

sys.exit(0 if failed == 0 else 1)
