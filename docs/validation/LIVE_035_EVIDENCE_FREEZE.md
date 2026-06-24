# PILOT-LIVE-035 Evidence Freeze

Generated: 2026-06-24 09:19:23 UTC
Overall: `PASS`
Run ID: `pilot035-20260624-091923-10b036`
Live mode: `True`
JSON report: `reports/xdr_pilot_live_035_2026-06-24-091923.json`

## Pilot Evidence Stages

| Stage | Name | Status |
|---|---|---|
| P | Posture check (local profile) | `PASS` |
| R | Recovery readiness (dry-run) | `PASS` |
| L | Lineage fields (NW-1/DB-5) | `PASS` |
| M | Domain remapping (CORR-1) | `PASS` |
| I | Rule registry integrity | `PASS` |
| E | EASM posture readiness (offline) | `PASS` |
| O | OBS/SLO readiness (offline, local profile) | `PASS` |
| PM | Pilot readiness matrix validator (offline) | `PASS` |
| C | Causal live proof (end-to-end) | `PASS` |

## Current Live Causal Proof

| Field                  | Value                                                     |
| ---------------------- | --------------------------------------------------------- |
| Result                 | `LIVE_CAUSAL_PROOF=PASS`                                  |
| Run ID                 | `pilot035-20260624-091923-10b036`                         |
| Live mode              | `True`                                                    |
| Demo run ID            | `demo-20260624-a716e7`                                    |
| Events accepted        | `5/5`                                                     |
| Field match            | `FIELD_MATCH=PASS`                                        |
| Alerts matched         | `2 alert(s) via demo_run_id`                              |
| Rules fired            | `CLOUD_NEW_ACCESS_KEY`, `CLOUD_SECURITY_SETTING_MODIFIED` |
| Demo lineage           | `demo_lineage_present=true`                               |
| Causal JSON report     | `reports/demo-causal-demo-20260624-a716e7.json`           |
| Causal Markdown report | `reports/demo-causal-demo-20260624-a716e7.md`             |
| Pilot live report      | `reports/xdr_pilot_live_035_2026-06-24-091923.json`       |

The current live pilot evidence run passed end-to-end. Stage C is now `PASS`, so the previous advisory reference to the older `3329c4` causal proof is retained only as historical evidence, not as the authoritative current proof.

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

### Stage E: EASM posture readiness (offline)

**Status:** `PASS`  
**Detail:** All 5 EASM readiness checks passed.  

| Check | Passed | Detail |
|---|---|---|
| `xdr_easm_passive_scan.py exists` | + | D:\project\Detector\scripts\xdr_easm_passive_scan.py |
| `xdr_easm_posture_history.py exists` | + | D:\project\Detector\scripts\xdr_easm_posture_history.py |
| `EASM_POSTURE_HISTORY.md exists` | + | D:\project\Detector\docs\operations\EASM_POSTURE_HISTORY.md |
| `xdr_easm_posture_history importable` | + |  |
| `ADVISORY_ONLY constant` | + |  |

### Stage O: OBS/SLO readiness (offline, local profile)

**Status:** `PASS`  
**Detail:** exit_code=0  

### Stage PM: Pilot readiness matrix validator (offline)

**Status:** `PASS`  
**Detail:** exit_code=0  

### Stage C: Causal live proof (end-to-end)

**Status:** `PASS`  
**Detail:** Current live causal proof completed successfully.

| Evidence | Value |
|---|---|
| Demo run ID | `demo-20260624-a716e7` |
| Events accepted | `5` |
| Alerts matched | `2` |
| Field match | `PASS` |
| Rules fired | `CLOUD_NEW_ACCESS_KEY`, `CLOUD_SECURITY_SETTING_MODIFIED` |
| Lineage present | `true` |
| Final verdict | `LIVE_CAUSAL_PROOF=PASS` |

## Pilot Readiness Matrix Evidence Run

| Field | Value |
|---|---|
| Matrix Run ID | `prm-5409a298-56ce-4868-bba6-e6dbab70a62e` |
| Command | `php artisan pilot:evidence-freeze` |
| Gates recorded | 10/10 — all PASS |
| Score | 100% (10/10) |
| Required gates | PASS (soak_validation, replay_verification, tenant_isolation, rollback_readiness) |
| Advisory-only | true |
| Autonomous promotion | false |
| Summary JSON | `reports/pilot_live_035_matrix_summary.json` |

## Commit Lineage

| Task | Commit | Description |
|---|---|---|
| NW-1 / CORR-1 / DB-5 | `4d1d1d7` | Lineage + domain remap + tenant_id write |
| PROD-024 | `a7c5fa5` | Production runtime posture checker |
| INGESTION-025 | `7fcdd41` | Ingestion-gateway backpressure hardening |
| SCALE-026 | `204e152` | Controlled load & soak validation |
| DR-027 | `cae4eea` | Backup / restore / recovery readiness |
| LIVE-028 | `880a8d5` | Full live regression & evidence freeze |
| EASM-030 | `9304185` | Website exposure & passive posture monitoring |
| EASM-031 | `59b6d75` | Website posture history & risk trend |
| OBS-029 | `b960c38` | Runtime observability & SLO readiness |
| PILOT-034 | `1b8d8db` | Controlled enterprise pilot readiness matrix |

## Forbidden Changes Confirmed

- No detection rules changed (registry.v1.json, 133 rules, 12 staged_active)
- No ACTIVE_ALLOWLIST entries added
- No shadow/active domain boundary changes
- No append-only table mutations
- No live containment, enforcement, or autonomous response
- EASM output advisory-only, no active scanning
- OBS/SLO thresholds informational only — no automated remediation
- Pilot readiness matrix advisory-only — no autonomous promotion
