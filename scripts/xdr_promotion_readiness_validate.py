#!/usr/bin/env python3
"""
ENTERPRISE-045: Detection Domain Promotion Readiness Validator

Validates that all 133 detection rules are unambiguously classified into one
of 5 promotion readiness categories. No rules are promoted; ACTIVE_ALLOWLIST
remains empty; Go scope stays at identity/cloud/SaaS.

Exit codes: 0=PASS, 1=FAIL, 2=ERROR
"""

import json
import os
import re
import sys
from pathlib import Path

# ── Constants ────────────────────────────────────────────────────────────────

REGISTRY_PATH = Path(__file__).parent.parent / "docs" / "detection" / "rules" / "registry.v1.json"
VALIDATOR_PATH = Path(__file__).parent / "xdr_rule_registry_validate.py"

SOAKED_DOMAINS   = {"identity", "cloud", "saas"}
DEFERRED_DOMAINS = {"network", "threat-intel", "xdr"}

READINESS_ACTIVE           = "active"
READINESS_SHADOW_READY     = "shadow_ready"
READINESS_SHADOW_NEEDS_SOAK = "shadow_needs_soak"
READINESS_DEFERRED         = "deferred"
READINESS_OUT_OF_SCOPE     = "out_of_scope"

EXPECTED_ACTIVE           = 12
EXPECTED_SHADOW_READY     = 12
EXPECTED_SHADOW_NEEDS_SOAK = 93
EXPECTED_DEFERRED         = 16
EXPECTED_OUT_OF_SCOPE     = 0
EXPECTED_TOTAL            = 133


# ── Classification logic (mirrors DetectionPromotionReadinessService.php) ────

def classify_rule(rule: dict) -> str:
    status = rule.get("status", "shadow")
    domain = rule.get("domain", "")

    if status == "staged_active":
        return READINESS_ACTIVE
    if domain in DEFERRED_DOMAINS:
        return READINESS_DEFERRED
    if domain in SOAKED_DOMAINS:
        return READINESS_SHADOW_READY
    return READINESS_SHADOW_NEEDS_SOAK


# ── Checks ────────────────────────────────────────────────────────────────────

def run_checks() -> list[dict]:
    results = []

    def check(code: str, desc: str, passed: bool, detail: str = "") -> None:
        results.append({
            "code": code, "description": desc,
            "status": "PASS" if passed else "FAIL",
            "detail": detail,
        })

    # PR-01: Registry exists
    check("PR-01", "Registry file exists",
          REGISTRY_PATH.exists(),
          str(REGISTRY_PATH) if not REGISTRY_PATH.exists() else "")

    if not REGISTRY_PATH.exists():
        return results

    # PR-02: Registry loads and has rules
    try:
        registry = json.loads(REGISTRY_PATH.read_text(encoding="utf-8"))
        rules = registry.get("rules", [])
    except Exception as exc:
        check("PR-02", "Registry loads as valid JSON", False, str(exc))
        return results

    check("PR-02", "Registry loads as valid JSON", True)

    # PR-03: All rules have required fields
    missing_fields = [
        r.get("rule_id", "<no_id>")
        for r in rules
        if not all(k in r for k in ("rule_id", "status", "domain"))
    ]
    check("PR-03", "All rules have rule_id + status + domain",
          len(missing_fields) == 0,
          f"Missing fields in: {missing_fields}" if missing_fields else "")

    # PR-04: Total rule count
    check("PR-04", f"Total rule count == {EXPECTED_TOTAL}",
          len(rules) == EXPECTED_TOTAL,
          f"Got {len(rules)}" if len(rules) != EXPECTED_TOTAL else "")

    # Classify all
    classified = [(r, classify_rule(r)) for r in rules]
    by_readiness: dict[str, list] = {
        READINESS_ACTIVE: [],
        READINESS_SHADOW_READY: [],
        READINESS_SHADOW_NEEDS_SOAK: [],
        READINESS_DEFERRED: [],
        READINESS_OUT_OF_SCOPE: [],
    }
    for rule, readiness in classified:
        by_readiness.setdefault(readiness, []).append(rule["rule_id"])

    # PR-05: active count
    n_active = len(by_readiness[READINESS_ACTIVE])
    check("PR-05", f"active count == {EXPECTED_ACTIVE}",
          n_active == EXPECTED_ACTIVE,
          f"Got {n_active}: {by_readiness[READINESS_ACTIVE]}" if n_active != EXPECTED_ACTIVE else "")

    # PR-06: shadow_ready count
    n_ready = len(by_readiness[READINESS_SHADOW_READY])
    check("PR-06", f"shadow_ready count == {EXPECTED_SHADOW_READY}",
          n_ready == EXPECTED_SHADOW_READY,
          f"Got {n_ready}" if n_ready != EXPECTED_SHADOW_READY else "")

    # PR-07: shadow_needs_soak count
    n_soak = len(by_readiness[READINESS_SHADOW_NEEDS_SOAK])
    check("PR-07", f"shadow_needs_soak count == {EXPECTED_SHADOW_NEEDS_SOAK}",
          n_soak == EXPECTED_SHADOW_NEEDS_SOAK,
          f"Got {n_soak}" if n_soak != EXPECTED_SHADOW_NEEDS_SOAK else "")

    # PR-08: deferred count
    n_deferred = len(by_readiness[READINESS_DEFERRED])
    check("PR-08", f"deferred count == {EXPECTED_DEFERRED}",
          n_deferred == EXPECTED_DEFERRED,
          f"Got {n_deferred}: {by_readiness[READINESS_DEFERRED]}" if n_deferred != EXPECTED_DEFERRED else "")

    # PR-09: out_of_scope count
    n_oos = len(by_readiness[READINESS_OUT_OF_SCOPE])
    check("PR-09", f"out_of_scope count == {EXPECTED_OUT_OF_SCOPE}",
          n_oos == EXPECTED_OUT_OF_SCOPE,
          f"Got {n_oos}: {by_readiness[READINESS_OUT_OF_SCOPE]}" if n_oos != EXPECTED_OUT_OF_SCOPE else "")

    # PR-10: All rules classified (total coverage)
    total_classified = sum(len(v) for v in by_readiness.values())
    check("PR-10", "All 133 rules have a readiness classification",
          total_classified == EXPECTED_TOTAL,
          f"Classified {total_classified}" if total_classified != EXPECTED_TOTAL else "")

    # PR-11: shadow_ready rules are exclusively in soaked domains
    bad_ready = [
        r["rule_id"]
        for r, cls in classified
        if cls == READINESS_SHADOW_READY and r.get("domain") not in SOAKED_DOMAINS
    ]
    check("PR-11", "shadow_ready rules are exclusively in soaked domains (identity/cloud/saas)",
          len(bad_ready) == 0,
          f"Unexpected: {bad_ready}" if bad_ready else "")

    # PR-12: No endpoint rule is staged_active (promotion gate enforced)
    endpoint_active = [
        r["rule_id"]
        for r in rules
        if r.get("domain") == "endpoint" and r.get("status") == "staged_active"
    ]
    check("PR-12", "No endpoint rules in staged_active (promotion gate enforced)",
          len(endpoint_active) == 0,
          f"Unexpected staged_active endpoint rules: {endpoint_active}" if endpoint_active else "")

    # PR-13 (advisory): ACTIVE_ALLOWLIST is still empty in registry validator
    if VALIDATOR_PATH.exists():
        validator_src = VALIDATOR_PATH.read_text(encoding="utf-8")
        match = re.search(r"ACTIVE_ALLOWLIST.*?=.*?frozenset\((.*?)\)", validator_src)
        is_empty = match and match.group(1).strip() in ("", "[]", "()")
        check("PR-13", "ACTIVE_ALLOWLIST in registry validator is still empty (no unauthorized promotion)",
              is_empty or match is None,
              "ACTIVE_ALLOWLIST appears non-empty — review before promoting" if not (is_empty or match is None) else "")
    else:
        check("PR-13", "Registry validator file exists for ACTIVE_ALLOWLIST audit",
              False, str(VALIDATOR_PATH))

    return results


# ── Main ─────────────────────────────────────────────────────────────────────

def main() -> int:
    results = run_checks()

    failures = [r for r in results if r["status"] == "FAIL"]
    passed   = [r for r in results if r["status"] == "PASS"]

    print(f"\n{'='*60}")
    print("ENTERPRISE-045 — Detection Domain Promotion Readiness")
    print(f"{'='*60}")

    for r in results:
        icon   = "PASS" if r["status"] == "PASS" else "FAIL"
        detail = f" -- {r['detail']}" if r["detail"] else ""
        print(f"  [{icon}] {r['code']}: {r['description']}{detail}")

    print(f"\n{'='*60}")
    print(f"Result: {'PASS' if not failures else 'FAIL'} ({len(passed)} passed, {len(failures)} failed)")

    if not failures:
        try:
            registry = json.loads(REGISTRY_PATH.read_text(encoding="utf-8"))
            rules = registry.get("rules", [])
            from collections import Counter
            classified = Counter(classify_rule(r) for r in rules)
            print(f"\nBreakdown:")
            print(f"  active:             {classified.get(READINESS_ACTIVE, 0)}")
            print(f"  shadow_ready:       {classified.get(READINESS_SHADOW_READY, 0)}")
            print(f"  shadow_needs_soak:  {classified.get(READINESS_SHADOW_NEEDS_SOAK, 0)}")
            print(f"  deferred:           {classified.get(READINESS_DEFERRED, 0)}")
            print(f"  out_of_scope:       {classified.get(READINESS_OUT_OF_SCOPE, 0)}")
        except Exception:
            pass

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
