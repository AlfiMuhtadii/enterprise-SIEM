# EASM Posture History & Risk Trend

**Module:** BACKLOG-EASM-031
**Status:** Advisory-only (no active scanning, no incident creation)
**Depends on:** EASM-030 (Passive Posture Monitoring)

---

## Overview

Extends EASM-030 with scan-to-scan posture history tracking. After each passive scan
completes, a posture snapshot is recorded and finding-level change records are produced.
A risk score per asset is maintained, and a tenant-level summary view is available.

All output is advisory-only. No incidents are created. No detection rules are modified.

---

## Posture Score Formula

Starting score: 100
Deductions per open finding:
- high: -20
- medium: -10
- low: -3
- info: -1

Score is floored at 0.

## Risk Tiers

| Score Range | Tier     |
|-------------|----------|
| 80 – 100    | low      |
| 60 – 79     | medium   |
| 40 – 59     | high     |
| 0 – 39      | critical |

## Trend Direction

Computed on each scan against the stored previous score:
- **improving** — current score > previous score
- **degrading** — current score < previous score
- **stable** — equal or no previous score (first scan)

---

## Finding Change Types

| Type       | Meaning                                                     |
|------------|-------------------------------------------------------------|
| new        | Finding present in current scan, absent in previous         |
| resolved   | Finding present in previous scan, absent in current         |
| unchanged  | Present in both; severity unchanged or decreased            |
| regressed  | Present in both; severity increased (e.g. low → high)       |

**Critical constraint:** Previous findings must be fetched from the DB **before** upserting
the current scan's findings. `EasmScanCommand` captures `$previousFindingsForDiff` before
calling `upsertFinding()`.

---

## Database Tables

| Table                   | Type         | Purpose                                  |
|-------------------------|--------------|------------------------------------------|
| `easm_posture_snapshots`| append-only  | One row per completed scan per asset     |
| `easm_finding_changes`  | append-only  | Per-finding change events per scan run   |
| `easm_asset_risk_scores`| mutable      | Current risk score per asset (upserted)  |

### Append-only enforcement
`EasmPostureSnapshot` and `EasmFindingChange` models override `save()` to throw
`LogicException` when `$this->exists` is true (update attempted).

### Delete-forbidden enforcement
`EasmAssetRiskScore` model overrides `delete()` and `forceDelete()` to throw `LogicException`.

---

## Service: `EasmPostureHistoryService`

| Method                 | Purpose                                           |
|------------------------|---------------------------------------------------|
| `computePostureScore`  | Score 0–100 from finding list                     |
| `classifyRiskTier`     | low / medium / high / critical from score         |
| `determineTrendDirection` | improving / degrading / stable                 |
| `diffFindings`         | Produce per-finding change list                   |
| `createPostureSnapshot`| Persist append-only snapshot                      |
| `recordFindingChanges` | Persist append-only finding change rows           |
| `upsertAssetRiskScore` | Upsert mutable risk score row                     |
| `generateTrendReport`  | Latest N snapshots + current risk score           |
| `getTenantEasmSummary` | Tenant-level finding counts and highest-risk asset|

---

## Routes (advisory view only)

| Method | Path                                    | Action                    | Gate          |
|--------|-----------------------------------------|---------------------------|---------------|
| GET    | `/soc/easm/summary`                     | Tenant posture summary    | soc:easm.view |
| GET    | `/soc/easm/assets/{assetId}/history`    | Posture trend view        | soc:easm.view |
| GET    | `/soc/easm/assets/{assetId}/report`     | JSON trend report         | soc:easm.view |

---

## Python Tool

```bash
python scripts/xdr_easm_posture_history.py \
    --asset-id 1 --tenant-id tenant-001 \
    --output reports/easm_posture_history.json \
    [--input data/exported_snapshots.json] \
    [--limit 10]
```

The tool is purely offline. It reads pre-exported snapshot data (if `--input` provided)
or produces an empty report. No network calls are made.

---

## Safety Boundaries

- `ADVISORY_ONLY = true` constant on both service and Python tool
- Never creates `SecurityIncident` records
- Never modifies `ACTIVE_ALLOWLIST` or detection rules
- Never promotes shadow findings to active
- No active scanning or exploit payloads
- Tenant ownership guard enforced in controller and scan command
- RBAC gate: `soc:easm.view` required for all read routes
