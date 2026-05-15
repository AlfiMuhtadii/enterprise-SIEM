# Endpoint Shadow Correlation Plan

**Status:** shadow-only  
**Scope:** endpoint (process_start, process_exit, network_connection, dns_query, file_write, login_event)  
**Active cutover:** NOT approved — endpoint domain requires additional gate evidence before promotion

---

## Current State

| Domain | Engine | State |
|---|---|---|
| identity | go | staged_active |
| cloud | go | staged_active |
| saas | go | staged_active |
| endpoint | go | shadow-only |
| dns | go | shadow-only |
| proxy | go | shadow-only |
| firewall | go | shadow-only |

The endpoint domain currently:
- Has raw telemetry schemas defined (`docs/contracts/telemetry/endpoint/`)
- Is normalized by normalizer-worker (`normalizeEndpoint()` code path)
- Publishes normalized events to `telemetry.normalized`
- Is NOT consumed in active mode by the correlation-worker (scope filter: `identity-cloud`)
- Has no active correlation rules for endpoint event types
- Has no parity baseline, no replay dataset, and no soak evidence

---

## Why Endpoint Is Shadow-Only

1. **No parity baseline.** No baseline of expected alert output for endpoint events exists. Without a parity dataset, there is no way to validate that the Go correlation output matches the legacy output.

2. **No endpoint correlation rules.** The correlation-worker (`correlateIdentityCloud()`) does not process endpoint telemetry. The general `correlate()` function handles endpoint events partially (proxy/firewall logic exists) but there is no validated endpoint-specific rule set.

3. **No replay dataset at scale.** The current replay dataset (`storage/logs/xdr_realistic_large.jsonl`) is scoped to identity/cloud/SaaS events. An endpoint replay dataset does not exist.

4. **No endpoint-specific soak.** A 6-hour soak covering endpoint domain events has not been run.

5. **No domain-specific gate evidence.** All promotion gates remain open for the endpoint domain.

---

## Required Gates Before Active Cutover

All of the following must be completed and evidenced before endpoint correlation can be promoted to staged_active.

### Gate 1: Endpoint Normalization Validation (current phase)

- [x] Raw telemetry schemas defined
- [x] Normalizer updated to normalize endpoint events
- [x] Endpoint fixture replay validates normalization
- [x] No duplicate normalized events
- [x] All required normalized fields present

Script: `python scripts/xdr_endpoint_normalization_validate.py`

---

### Gate 2: Endpoint Replay Dataset

Requirements:
- Minimum 100,000 endpoint events covering all 6 event types
- Realistic distribution: ~40% process, ~25% network, ~15% dns, ~10% file, ~10% login
- Stored at `storage/logs/xdr_endpoint_large.jsonl`
- Validated against endpoint raw schemas

No replay dataset exists yet. Do not proceed to Gate 3 without a validated dataset.

---

### Gate 3: Endpoint Correlation Rules

Requirements:
- Correlation rules for endpoint event types implemented in correlation-worker
- Rules reviewed and approved for false-positive rate
- Coverage: process chain detection, network anomaly, DNS IOC match, file drop detection, login brute force
- Tested against the endpoint replay dataset with known-good expected outputs

No endpoint correlation rules exist in the active code path yet. Implementing rules is a separate future task.

---

### Gate 4: Endpoint Parity Validation

Requirements:
- Legacy correlation output for endpoint events captured as baseline
- Go correlation output compared against legacy baseline
- Alert type match ≥ 0.95 on the endpoint replay dataset
- Evidence match ≥ 0.98
- Alert count delta ≤ 1–2%
- Duplicate rate = 0

No parity baseline exists yet.

---

### Gate 5: Endpoint Shadow Soak (mini)

Requirements:
- 5-minute mini soak using endpoint replay dataset
- All standard soak gates pass: fallback_count=0, failure_count=0, p95 < 300ms, goroutine_growth=0, stable memory

Script (when ready): `python scripts/xdr_correlation_soak.py --dataset storage/logs/xdr_endpoint_large.jsonl --duration-minutes 5`

---

### Gate 6: Endpoint 6h Soak

Requirements:
- 6-hour soak using endpoint replay dataset
- All soak gates pass (same criteria as identity-cloud 6h soak)
- Results archived

Script (when ready): `powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1`

---

### Gate 7: Rollback Validation

Requirements:
- Confirmed that switching `XDR_CORRELATION_SCOPE` from `identity-cloud-endpoint` back to `identity-cloud` does not affect identity-cloud alert output
- Fallback to legacy confirmed operational for endpoint domain
- Circuit breaker behavior validated for endpoint events

---

## Rollback Requirements

Regardless of promotion status, rollback capability must be preserved at all times:

- `XDR_CORRELATION_ENGINE=shadow` must always be a valid fallback
- Removing endpoint domain from scope must not affect identity-cloud correlation
- The circuit breaker (3 consecutive failures → legacy fallback) must cover endpoint events

---

## What Will NOT Change at Promotion

- `XDR_CORRELATION_ENGINE=go` for identity-cloud (already staged_active — unaffected)
- Laravel SOC control-plane responsibilities
- Existing event contracts for xdr.alerts, alerts.created, incidents.updated
- Replay guarantees for existing event types
- The 6h soak validation artifacts for identity-cloud

---

## Forbidden Actions Before All Gates Pass

- Do NOT set `XDR_CORRELATION_SCOPE=identity-cloud-endpoint` in production config
- Do NOT remove endpoint events from the DLQ path in the normalizer
- Do NOT implement live endpoint containment or response actions
- Do NOT add kernel-mode instrumentation
- Do NOT claim the platform is a full EDR
