# LIVE-028 Evidence Freeze

**Generated:** 2026-06-24 03:21:38 UTC  
**Overall:** `PASS`  
**Run ID:** `live028-20260624-032138-dd6e16`

## Regression Stages

| Stage | Name | Status |
|---|---|---|
| P | Posture check (local profile) | `PASS` |
| R | Recovery readiness (dry-run) | `PASS` |
| L | Lineage fields (NW-1/DB-5) | `PASS` |
| M | Domain remapping (CORR-1) | `PASS` |
| I | Rule registry integrity | `PASS` |

## Baselines

- Rule count: `133`
- Staged active: `12`
- Active domains: `cloud, identity, saas`
- CORR-1 remap: `identity_provider->identity`, `saas_audit->saas`

## Stage Details

### Stage P: Posture check (local profile)

**Status:** `PASS`  
**Detail:** exit_code=0  

### Stage R: Recovery readiness (dry-run)

**Status:** `PASS`  
**Detail:** exit_code=0  

### Stage L: Lineage fields (NW-1/DB-5)

**Status:** `PASS`  
**Detail:** All 17 lineage checks passed.  

| Check | Passed | Detail |
|---|---|---|
| `ev[0].demo_run_id` | + | got='demo-live028-test' |
| `ev[0].scenario_id` | + | got='scen-live028' |
| `ev[0].tenant_id` | + | got='tenant-test-001' |
| `ev[0].source_event_id present` | + | got='orig-001' |
| `ev[1].demo_run_id` | + | got='demo-live028-test' |
| `ev[1].scenario_id` | + | got='scen-live028' |
| `ev[1].tenant_id` | + | got='tenant-test-001' |
| `ev[1].source_event_id present` | + | got='src-0002' |
| `ev[2].demo_run_id` | + | got='demo-live028-test' |
| `ev[2].scenario_id` | + | got='scen-live028' |
| `ev[2].tenant_id` | + | got='tenant-test-001' |
| `ev[2].source_event_id present` | + | got='alt-003' |
| `ev[0].source_event_id=orig-001` | + | got='orig-001' |
| `ev[2].source_event_id=alt-003` | + | got='alt-003' |
| `ev[0].trace_id format` | + | got='demo-live028-test-trace-0001' |
| `ev[0].event_id == trace_id` | + | event_id='demo-live028-test-trace-0001' trace_id='demo-live028-test-trace-0001' |
| `trace_ids unique` | + | trace_ids=['demo-live028-test-trace-0001', 'demo-live028-test-trace-0002', 'demo-live028-test-trace-0003'] |

### Stage M: Domain remapping (CORR-1)

**Status:** `PASS`  
**Detail:** All 18 remapping checks passed.  

| Check | Passed | Detail |
|---|---|---|
| `remap('identity_provider')=='identity'` | + | got='identity' |
| `active_filter('identity_provider')==True` | + | passes=True expected=True |
| `remap('saas_audit')=='saas'` | + | got='saas' |
| `active_filter('saas_audit')==True` | + | passes=True expected=True |
| `remap('identity')=='identity'` | + | got='identity' |
| `active_filter('identity')==True` | + | passes=True expected=True |
| `remap('cloud')=='cloud'` | + | got='cloud' |
| `active_filter('cloud')==True` | + | passes=True expected=True |
| `remap('saas')=='saas'` | + | got='saas' |
| `active_filter('saas')==True` | + | passes=True expected=True |
| `remap('endpoint')=='endpoint'` | + | got='endpoint' |
| `active_filter('endpoint')==False` | + | passes=False expected=False |
| `remap('dns')=='dns'` | + | got='dns' |
| `active_filter('dns')==False` | + | passes=False expected=False |
| `remap('firewall')=='firewall'` | + | got='firewall' |
| `active_filter('firewall')==False` | + | passes=False expected=False |
| `identity_provider not silently dropped` | + | CORR-1 regression check |
| `saas_audit not silently dropped` | + | CORR-1 regression check |

### Stage I: Rule registry integrity

**Status:** `PASS`  
**Detail:** exit_code=0  

## Commit Lineage

| Task | Commit | What |
|---|---|---|
| NW-1 / CORR-1 / DB-5 | `4d1d1d7` | Lineage fields + domain remap + tenant_id write path |
| PROD-024 | `a7c5fa5` | Production runtime posture checker |
| INGESTION-025 | `3027e08`, `e88c103`, `7fcdd41` | Ingestion-gateway backpressure hardening |
| SCALE-026 | `204e152` | Controlled load & soak validation |
| DR-027 | `cae4eea` | Backup / restore / recovery readiness |

## Forbidden Changes Confirmed

- No detection rules changed (registry.v1.json untouched)
- No ACTIVE_ALLOWLIST entries added
- No shadow/active boundary changes
- No append-only table mutations
- No live containment or autonomous response
