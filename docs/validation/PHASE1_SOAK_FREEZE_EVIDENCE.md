# Phase 1 Soak Evidence Freeze — ENTERPRISE-064

**Status:** Implemented 2026-06-28  
**NO_PROMOTION = true** — no freeze run authorizes rule promotion.  
**FREEZE_APPROVED = false** — always. This is a documentation/evidence record only.  
Real 6h soak PASS via `run_xdr_correlation_soak_6h.ps1` is required before any promotion gate opens.

---

## Purpose

Creates an immutable, append-only snapshot of the full P1G-01..P1G-08 evidence chain
at the state when `php artisan soak:phase1-run --warm-up` first achieved Decision: **PASS** (2026-06-27).

Complements prior stability freezes (v2/v3/v4) with a soak-execution-specific evidence record.

---

## What Was Fixed Before This Freeze

| Fix | Commit | Effect |
|-----|--------|--------|
| E062: P1G-07/P1G-08 sourced from soak report | 07d7ea3 | P1G-07/P1G-08 now show real values (p95=80.65ms, fallback=0) instead of always-WARN |
| E063: `updateOrInsert` in `DetectionReplayFixtureService::persist()` | 5b47cab | `rule_fixture_backlogs` rows created on first run (was silent no-op on empty table) |
| E063: `has_validation_evidence=true` in upsert | 5b47cab | `ConfidenceSourceRefreshService::deriveSource()` correctly labels rules as `empirical` |
| E063: `--warm-up` flag on `soak:phase1-run` | 5b47cab | Enables one-command seeding before gate check |

---

## Freeze Gates (EV064-01 through EV064-12)

| Gate | Name | Type | Pass Condition |
|------|------|------|----------------|
| EV064-01 | Phase1SoakExecutionService deployed | structural | class loadable |
| EV064-02 | Phase1SoakExecutionService::NO_PROMOTION = true | structural | safety constant present |
| EV064-03 | Phase1SoakEvidenceFreezeService::ADVISORY_ONLY = true | structural | safety constant present |
| EV064-04 | phase1_soak_runs table exists | structural | Schema::hasTable() |
| EV064-05 | phase1_soak_gate_results table exists | structural | Schema::hasTable() |
| EV064-06 | 12 tier-1 fixture files present on disk | structural | `tests/fixtures/detection/tier1_batch1/*.json` count ≥ 12 |
| EV064-07 | rule_fixture_backlogs has has_validation_evidence=true rows | live-DB (advisory) | count ≥ 12 |
| EV064-08 | rule_fixture_backlogs has 12 empirical rules | live-DB (advisory) | confidence_source=empirical count ≥ 12 |
| EV064-09 | confidence_source_audit_events has empirical entries | live-DB (advisory) | count ≥ 1 |
| EV064-10 | Latest phase1_soak_run Decision = PASS | live-DB (advisory) | decision=PASS in phase1_soak_runs |
| EV064-11 | All 8 P1G gates passed in latest soak run | live-DB (advisory) | p1g_gates_passed ≥ 8 |
| EV064-12 | 6h soak report file present (P1G-07/P1G-08 source) | file (advisory) | `reports/xdr_correlation_soak_6h.json` exists |

**Structural gates (EV064-01..EV064-06):** always evaluated; FAIL = blocking.  
**Advisory gates (EV064-07..EV064-12):** WARN when live data absent; never FAIL on empty table.

---

## Evidence Snapshot Types

| Type | Source |
|------|--------|
| `soak_run_decision` | `phase1_soak_runs.decision` (latest non-dry-run) |
| `empirical_rules_count` | `rule_fixture_backlogs.confidence_source=empirical` count |
| `fixture_files_on_disk` | `tests/fixtures/detection/tier1_batch1/*.json` count |
| `p1g_gates_passed_in_latest_run` | `phase1_soak_gate_results.status=pass` count |
| `soak_report_present` | `reports/xdr_correlation_soak_6h.json` file existence |
| `no_promotion_confirmed` | always `true` (hardcoded) |

---

## Execution

```powershell
# Dry-run: evaluate gates without persisting
php artisan soak:phase1-freeze --dry-run

# Live freeze: persist immutable evidence record
php artisan soak:phase1-freeze

# Full warm-up before freeze (recommended):
php artisan soak:phase1-run --warm-up --duration=30
php artisan soak:phase1-freeze

# View evidence in SOC UI
# GET /detection/phase1-soak-freeze
```

---

## Tables (all append-only)

| Table | Purpose |
|-------|---------|
| `phase1_soak_freeze_runs` | Freeze run metadata: verdict, pass_score, gates counts |
| `phase1_soak_freeze_gates` | Per-gate snapshot: gate_id, status, evidence, is_advisory |
| `phase1_soak_freeze_evidence` | Evidence values: type, value, source_table |

**NEVER UPDATE or DELETE rows in these tables — evidence must be immutable.**

---

## Validator

```powershell
python scripts/xdr_phase1_freeze_validate.py
```

Expected: `12/12 PASS  Verdict: PASS`

---

## Safety Invariants (Non-Negotiable)

1. `NO_PROMOTION = true` — this freeze does NOT authorize rule promotion
2. `FREEZE_APPROVED = false` — documentation record only
3. `ADVISORY_ONLY = true` — all outputs are advisory
4. Freeze tables are append-only — rows are never updated or deleted
5. 6h real soak PASS via `run_xdr_correlation_soak_6h.ps1` is still required before any promotion gate opens
