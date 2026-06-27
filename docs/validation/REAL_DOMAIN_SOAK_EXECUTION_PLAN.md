# Real Domain Soak Execution Plan — ENTERPRISE-060

**Status:** ADVISORY_ONLY — real_execution_gated = true  
**Date:** 2026-06-27  
**Principle:** Start smallest and safest, scale after each phase PASS.

---

## Phased Soak Sequence

### Phase 1 — Staged-Active Empirical Rules: Live Proof Repeat
**Scope:** 12 staged_active rules (identity/cloud/saas) with confidence_source=empirical  
**Risk:** Lowest — these rules are already active in production  
**Purpose:** Re-confirm all 12 rules fire correctly on live traffic after E056 fixture validation  
**Soak command:** `.\scripts\run_xdr_correlation_soak_6h.ps1`

Pre-soak gates:
- SPG-P1-01: empirical rules in rule_fixture_backlogs >= 12 (run `rule:run-fixtures` + `rule:refresh-confidence`)
- SPG-P1-02: tier_1 fixture files on disk >= 12 (**PASS** — 12 files present from E056)
- SPG-P1-03: run_xdr_correlation_soak_6h.ps1 exists
- SPG-P1-04: XDR_CORRELATION_ENGINE=go

---

### Phase 2 — Top Shadow-Ready Rules: Real Soak Evidence
**Scope:** Top-12 shadow rules by confidence from endpoint/network/threat-intel (after domain simulation E057)  
**Risk:** Medium — shadow rules, not yet active; PROMOTION_RECOMMENDED=false enforced  
**Purpose:** Generate first real soak evidence for highest-confidence shadow rules  
**Soak command:** `.\scripts\run_xdr_correlation_soak_6h.ps1 --domain=endpoint-shadow`  
**Promotion:** Remains BLOCKED until 6h soak PASS

Pre-soak gates:
- SPG-P2-01: domain soak simulations run for all 3 domains (E057)
- SPG-P2-02: endpoint structural_match_rate >= 0.80 confirmed
- SPG-P2-03: DomainSoakHarnessService deployed (BACKLOG-018)
- SPG-P2-04: DomainSoakSimulationService::PROMOTION_RECOMMENDED = false (**safety constant**)

---

### Phase 3 — Tier-1 Fixture-Backed Rules: Replay Validation
**Scope:** 12 tier_1_immediate rules with has_replay_fixture=true, confidence_source=empirical (E056 batch)  
**Risk:** Low — fixture events replayed against live pipeline, not novel traffic  
**Purpose:** Confirms fixture→alert correlation chain end-to-end  
**Soak command:** `php artisan rule:run-fixtures --tier=tier_1_immediate`

Pre-soak gates:
- SPG-P3-01: detection fixture batch has been run (run `rule:run-fixtures`)
- SPG-P3-02: valid fixture results in DB >= 12
- SPG-P3-03: confidence_source=empirical >= 12 (run `rule:refresh-confidence`)
- SPG-P3-04: DetectionReplayFixtureService deployed (E056) (**PASS**)

---

### Phase 4 — Endpoint Tier-1 Next Batch
**Scope:** endpoint shadow rules (tier_2_next_batch — count from registry)  
**Risk:** Requires Phase 1-3 complete first  
**Purpose:** After Phase 1-3 PASS, extend fixture coverage to endpoint tier_2 rules and run domain soak  
**Soak command:** `.\scripts\run_xdr_correlation_soak_6h.ps1 --domain=endpoint-tier2`  
**Pre-condition:** Build tier_2 fixtures first via `rule:run-fixtures --tier=tier_2_next_batch`

Pre-soak gates:
- SPG-P4-01: Phase 1 proxy ready (fixture files >= 12 on disk)
- SPG-P4-02: Phase 2 proxy ready (domain sims >= 3)
- SPG-P4-03: Phase 3 proxy ready (fixture service + batch run)
- SPG-P4-04: endpoint shadow rules in registry >= 1

---

## Safety Rules (Non-Negotiable)

1. **PROMOTION_RECOMMENDED = false** — always; no domain soak simulation counts as real soak
2. **REAL_EXECUTION_GATED = true** — each phase requires explicit operator trigger
3. **6h real soak PASS required** before any promotion decision
4. **FREEZE_APPROVED = false** — no freeze approves promotion automatically
5. Phase 4 must not start before Phase 1-3 are individually confirmed PASS

---

## Validation Commands

```powershell
php artisan soak:plan-review --dry-run
php artisan soak:plan-review --phase=1 --dry-run
php artisan soak:plan-review --phase=2 --dry-run
python scripts/xdr_soak_execution_plan_validate.py
```
