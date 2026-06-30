# Review Completed — Tasks Done

This file tracks all completed and verified implementations for the tenancy, security, and test architecture.

---

## 1. Summary of Completed Tasks

| Task ID | Description | Reference / Commit | GH Issue | Date Completed |
|---|---|---|---|---|
| **23.0.A** | Tenant Null Creation Guard (Strict Mode Boundary) | `a0e0841` (pre-workflow) | — | 2026-06-23 |
| **23.1 / 23.0.B** | Tenant Null Audit Command: `--table` validates against `ISOLATED_TABLES` | `194e9e6` | [#8](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/8) | 2026-06-23 |
| **23.2 / 23.0.C** | Test coverage: `--table` rejection of non-isolated and non-existent tables | `194e9e6` | [#9](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/9) | 2026-06-23 |
| **ENV.1** | Align environment variables in `.env` | (pre-workflow) | — | 2026-06-23 |
| **ENV.3** | Harden `.gitignore` for reports and logs | (pre-workflow) | — | 2026-06-23 |
| **T1** | Fix threat-hunting domain count mismatch (158 → 161) | `194e9e6` | [#4](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/4) | 2026-06-23 |
| **T2** | Rename 8 stale domain-count test methods to `supported_domains_count` | `194e9e6` | [#5](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/5) | 2026-06-23 |
| **T3** | Extract advisory-only constraint assertions into `AssertsAdvisoryOnlyConstraints` Trait (12 classes) | `194e9e6` | [#6](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/6) | 2026-06-23 |
| **BUG** | `SecretsValidationService`: use `getenv()` for `putenv()` compatibility | `194e9e6` | [#7](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/7) | 2026-06-23 |
| **INFRA-1** | Restrict docker-compose datastore ports from `0.0.0.0` → `127.0.0.1` | `2780f13` | [#10](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/10) | 2026-06-23 |
| **INFRA-2** | Move ClickHouse/Grafana/OpenSearch secrets to `${VAR:-default}` | `2780f13` | [#11](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/11) | 2026-06-23 |
| **DB-2** | Add `tenant_id` index to `advisory_findings` + 9 `shadow_soak_*` tables | `2780f13` | [#12](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/12) | 2026-06-23 |
| **DB-1** | Add `tenant_id` nullable column + index to `advisory_finding_events` and `dlq_normalization_events` | `2780f13` | [#13](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/13) | 2026-06-23 |
| **NW-1** | Propagate `tenant_id`, `demo_run_id`, `source_event_id`, `scenario_id` in all 11 type-specific normalizer helpers | `4d1d1d7` | [#14](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/14) | 2026-06-24 |
| **CORR-1** | Normalize `identity_provider`→`identity` and `saas_audit`→`saas` in correlate() and correlateIdentityCloud() | `4d1d1d7` | [#15](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/15) | 2026-06-24 |
| **DB-5** | Propagate `tenant_id` in `security_alerts` + `security_incidents` write paths; 13 new Python tests | `4d1d1d7` | [#16](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/16) | 2026-06-24 |
| **PROD-024** | Production runtime posture checker — 3 profiles (local/staging/production), 18 checks, 58 tests | `a7c5fa5` | — | 2026-06-24 |
| **INGESTION-025** | Ingestion-gateway backpressure hardening — async metrics poller (IG-1), per-tenant rate limiter (IG-2), bounded retry + circuit breaker (IG-3); 14 Go tests; D-01/D-02/D-03 resolved to INFO | `3027e08`, `e88c103`, `7fcdd41` | — | 2026-06-24 |
| **SCALE-026** | Ingestion hardening controlled load & soak validation — 6 scenarios (S-01–S-06), 10 metrics captured, 5 bounds checks; 65 Python tests; report PASS (11/11) | `204e152` | — | 2026-06-24 |
| **DR-027** | Backup/restore/recovery readiness — BACKUP_RESTORE_RECOVERY.md runbook + xdr_recovery_validate.py (8 checks + 4 advisory RPO/RTO); 50 Python tests; local PASS 8/0/0 | `cae4eea` | — | 2026-06-24 |
| **LIVE-028** | Full live regression & evidence freeze — xdr_live_regression_validate.py (5 stages: posture/recovery/lineage/remapping/registry); LIVE_028_EVIDENCE_FREEZE.md; 71 Python tests; all PASS | `880a8d5` | — | 2026-06-24 |
| **EASM-030** | Website Exposure & Passive Posture Monitoring — 3 migrations, EasmPassiveScanService, EasmScanCommand, EasmController, xdr_easm_passive_scan.py; 58 PHP + 62 Python tests; advisory-only, ownership-guarded, no active scanning | `9304185` | — | 2026-06-24 |
| **EASM-031** | Website Posture History & Risk Trend — 3 new tables (easm_posture_snapshots/finding_changes append-only; easm_asset_risk_scores mutable), EasmPostureHistoryService (score/tier/trend/diff/snapshot/upsert), updated EasmScanCommand+EasmController, 3 routes, 2 blade views, xdr_easm_posture_history.py; 60 PHP + 45 Python tests; advisory-only | `59b6d75` | — | 2026-06-24 |
| **OBS-029** | Runtime Observability & SLO Readiness — xdr_observability_slo_validate.py (OBS-01–08 readiness + SLO-01–17 metric evaluation, 3 profiles, PASS/WARN/FAIL); config/grafana/runtime-observability-slo.json (17-panel dashboard); RUNTIME_OBSERVABILITY_SLO.md; advisory EASM SLO (SLO-17); 71 Python tests; 684 Python total green | `b960c38` | — | 2026-06-24 |
| **PILOT-034** | Controlled Enterprise Pilot Readiness Matrix — 3 tables (1 mutable + 2 append-only), EnterprisePilotReadinessMatrixService (ADVISORY_ONLY, SELF_APPROVE_BLOCKED, PASS_THRESHOLD=0.80, MAX_GATES=20, 4 required gates), PilotReadinessMatrixController, 3 routes, 2 blade views, xdr_pilot_readiness_matrix.py (PASS/FAIL/ERROR, self-approve block), Grafana dashboard; +3 hunt domains → 164; 53 PHP + 45 Python tests; 3561 PHP + 729 Python green | `1b8d8db` | — | 2026-06-24 |
| **PILOT-LIVE-035** | Final Live Pilot Evidence Run — xdr_pilot_live_validate.py (8 offline stages: P/R/L/M/I/E/O/PM, all PASS; Stage C advisory WARN when services not running, prior LIVE_CAUSAL_PROOF=PASS at 3329c4); PilotEvidenceFreezeCommand (10 gates, 100% score, advisory-only, self-approve-blocked); LIVE_035_EVIDENCE_FREEZE.md; 31 PHP + 58 Python tests; 3592 PHP + 787 Python green | `6ce7914` | — | 2026-06-24 |
| **ENTERPRISE-039** | RBAC Audit Coverage — 2 self-approval guards (EndpointResponseCommandService + SocResponseController), 26-test RbacAuditCoverageTest, 3 existing tests fixed; 3618 PHP green | `7e27346` | [#\*](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues) | 2026-06-25 |
| **ATTR-001** | MITRE ATT&CK TTP tagging on security_alerts — migration (3 nullable columns), AlertMitreService (16 alert types: 12 staged_active + 4 cross-domain), TagAlertMitreTtpCommand (dry-run safe), SecurityAlertController + alerts.blade.php updated; 18 tests; 3636 PHP green | `57fe592` | [#20](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/20) | 2026-06-26 |
| **ATTR-002** | Alert attribution context — alert_attribution_context migration (append-only), AlertAttributionService (offline-first, idempotent), EnrichAlertAttributionCommand, controller + route + view, xdr_attribution_validate.py (10/10 PASS); 28 tests; 3664 PHP green | `4f8b008` | [#21](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/21) | 2026-06-26 |
| **ATTR-003** | GeoIP/ASN offline lookup — GeoAsnLookupService (CIDR matching, IPv4+IPv6), geo_asn_fixtures.json (RFC1918/5737 + 4 demo ASNs); bundled with ATTR-002; 3664 PHP green | `4f8b008` | [#22](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/22) | 2026-06-26 |
| **RBAC-1** | Add missing EASM + Pilot Readiness Matrix permissions to all roles in config/soc.php; fixes locked-out routes | `8e7ba02` | [#23](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/23) | 2026-06-26 |
| **EASM-1** | Enforce TenantContextAuthority in EasmController; cross-tenant spoof blocked via TCA::validateAndResolve | `8e7ba02` | [#24](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/24) | 2026-06-26 |
| **PILOT-1** | Scope PilotReadinessMatrixController by validated tenant context; cross-tenant run_id returns 404 | `8e7ba02` | [#25](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/25) | 2026-06-26 |
| **ENTERPRISE-045** | Detection Domain Promotion Readiness — DetectionPromotionReadinessService (5 categories, derived from registry), DetectionPromotionReadinessController, promotion_readiness blade view, xdr_promotion_readiness_validate.py (13/13 PASS); 24 tests; 3707 PHP green | `8524f3c` | — | 2026-06-26 |
| **ENTERPRISE-046** | Tenant Strict Mode & Null Backfill Closure — MUTABLE_TABLES + APPEND_ONLY_ISOLATED_TABLES constants in TenantBoundaryService, TenantNullBackfillCommand (dry-run/idempotent/batched), xdr_tenant_backfill_validate.py (13/13 PASS); 18 tests; 3725 PHP green | `eaafe83` | — | 2026-06-26 |
| **ENTERPRISE-047** | Shadow Ready Promotion Decision — ShadowReadyPromotionDecisionService (0.78/0.65 thresholds, DLQ-aware, advisory), EvaluateShadowPromotionCommand (--dry-run), shadow_promotion_decisions table (append-only), xdr_shadow_promotion_decision_validate.py (16/16 PASS); 35 tests; 3760 PHP green | `7704784` | — | 2026-06-26 |
| **ENTERPRISE-048** | Endpoint Shadow Domain Soak Plan — EndpointSoakPlanService (tier_1_soak_ready conf>=0.72: 80 rules, tier_2_evidence_collection: 13 rules, 5 gates), 3 append-only tables, GenerateEndpointSoakPlanCommand (--dry-run), xdr_endpoint_soak_plan_validate.py (16/16 PASS); 32 tests; 3792 PHP green | `d4a3ef1` | — | 2026-06-26 |
| **ENTERPRISE-049** | Stability Evidence Freeze v2 — StabilityEvidenceFreezeV2Service (12 gates EF-01–EF-12, 4 phase summaries E045–E048, STABLE_SCORE_THRESHOLD=0.80), 3 append-only tables, StabilityFreezeV2Command (--dry-run), xdr_stability_freeze_v2_validate.py (14/14 PASS); 25 tests; 3817 PHP green | `e7f73d3` | — | 2026-06-26 |
| **TC-1** | TenantContextAuthority advisory scoping — SecurityAlertController, SocIncidentController (fixed extra-brace syntax error), SocDashboardController, SocApiController; legacy/demo pass-through (requireTenantContext=false) | `bf5ca6e` | — | 2026-06-28 |
| **PTS-1** | Fastapi stub: added Depends/Header/HTTPException stubs in alert-writer and incident-builder Python test suites | `bf5ca6e` | — | 2026-06-28 |
| **DB-5-DEFECT** | Correlation-worker Alert struct: add top-level TenantID field + makeAlert() propagation; 2 new Go tests | `bf5ca6e` | — | 2026-06-28 |
| **IG-DOS** | Ingestion-gateway: lastSeen atomic.Int64 on tenantBucket + TTL-based eviction goroutine (XDR_TENANT_LIMITER_IDLE_MINUTES=30) | `bf5ca6e` | — | 2026-06-28 |
| **RESP-1** | SocAgentController + SocResponseController route through EndpointResponseCommandService via LEGACY_TYPE_MAP; unmappable types rejected | `bf5ca6e` | — | 2026-06-28 |
| **AGENT-API-1** | Advisory-only signature check in pollCommands(): logs SecurityHardeningEvent on invalid sig, does not block (deferred until endpoint 6h soak PASS) | `bf5ca6e` | — | 2026-06-28 |
| **INT-AUTH-1** | docker-compose.prod.yml: ports: !reset [] on all internal pipeline services (normalizer/correlation/alert-writer/incident-builder/ai-rag) | `bf5ca6e` | — | 2026-06-28 |
| **INT-AUTH-2** | alert-writer-service + incident-builder-service: X-Internal-Service-Token validation on GET /dlq; field truncation to 120 chars | `bf5ca6e` | — | 2026-06-28 |
| **TEST-1** | ExampleTest: PHP version minimum (8.1), login page 200, dashboard redirect; Unit ExampleTest: PHP_VERSION_ID >= 80100 | `bf5ca6e` | — | 2026-06-28 |
| **TEST-2** | 13 Feature test files migrated to AssertsAdvisoryOnlyConstraints trait; 5 duplicated inline advisory methods removed per file | `bf5ca6e` | — | 2026-06-28 |
| **ENTERPRISE-065** | Tenant Backfill + Strict Mode Readiness — TenantStrictModeReadinessService (7 gates, PASS_THRESHOLD=0.80, SELF_APPROVE_BLOCKED), 3 append-only tables, StrictModeReadinessCommand, 2 views, RBAC tenant.strict-mode.view/assess | `6688302` | — | 2026-06-28 |
| **ENTERPRISE-066** | Redpanda Topic Bootstrap + Runtime Recovery Hardening — RedpandaRecoveryHardeningService (9 topics, 6 consumer groups, advisory-only), 3 append-only tables, 2 views, RBAC redpanda.health.view/check | `6688302` | — | 2026-06-28 |
| **ENTERPRISE-067** | RAG Knowledge Base Seeding Runbook — AiKnowledgeSeedService (idempotent, 10 fixtures, 4 categories), rag_seed_fixtures (mutable) + 2 append-only tables, AiSeedKnowledgeCommand (--dry-run), view, RBAC ai.knowledge.view/seed | `6688302` | — | 2026-06-28 |
| **ENTERPRISE-068** | Container Resource Governance — `deploy.resources.limits` (memory + cpus) on 6 services in docker-compose.yml (dev) + docker-compose.prod.yml (prod); `xdr_container_resource_validate.py` 14 tests PASS | 0d6cfce | — | 2026-06-29 |
| **ENTERPRISE-069** | PostgreSQL RLS Policy Scaffolding (advisory-only) — `scaffold_rls_policies` migration (DO $$, idempotent, pgsql-only, no enforcement); `TenantRlsStatusCommand` (read-only, advisory); `xdr_rls_scaffold_validate.py` 14 tests PASS; `RlsPolicyScaffoldingTest.php` 19 tests PASS | 0d6cfce | — | 2026-06-29 |
| **ENTERPRISE-070** | Tenant Null Backfill Pre-Flight Validation — `TenantBackfillPreflightCommand` (6 CHK, advisory-only); GATE-08 `gateRlsPoliciesScaffolded()` in `TenantStrictModeReadinessService`; `xdr_tenant_backfill_preflight.py` 14 tests PASS; gate count 7→8 in test | 0d6cfce | — | 2026-06-29 |
| **ENTERPRISE-071** | RAG Knowledge Base Operational Integration — `RagOperationalCheckCommand` (`ai:knowledge-check`, read-only); `xdr_rag_operational_validate.py` 14 tests PASS; `RagOperationalIntegrationTest.php` 12 tests PASS | 0d6cfce | — | 2026-06-29 |
| **ENTERPRISE-072** | Shadow Domain Soak Pre-Flight — `DomainSoakHarnessService::getPreflightStatus()` (5 CHK, advisory); `ShadowSoakPreflightCommand` (`domain:soak-preflight`); `xdr_shadow_soak_preflight.py` 14 tests PASS; `ShadowSoakPreflightTest.php` 17 tests PASS | 0d6cfce | — | 2026-06-29 |
| **ENTERPRISE-073** | Redpanda Multi-Node HA Template — `docker-compose.ha.yml` (3-broker, seed discovery, RPC addr, healthcheck, resource limits); `xdr_redpanda_ha_validate.py` 14 tests PASS; `--replication-factor` + `--replicas` flag in `xdr_topic_bootstrap.py` | 0d6cfce | — | 2026-06-29 |
| **ENV-CACHE-DRIFT** | Map `XDR_INTERNAL_AUTH_SECRET` to `config/xdr.php` (`internal_auth_secret`); `InternalAuthService::secret()` uses `config()` not `env()` (config:cache bypass prevented); `InternalAuthConfigMappingTest.php` 7 tests | 4ee9675 | — | 2026-06-29 |
| **CMD-SHARED-HMAC** | Per-agent `hmac_secret` column on `endpoint_agents`; `EndpointResponseCommandService::verifyAgentSignature($sig,$raw,$agentId)` uses per-agent secret with shared-token fallback; `PerAgentHmacSecretTest.php` 11 tests | 4ee9675 | — | 2026-06-29 |
| **AGENT-TENANCY-GAP** | `tenant_id` on `endpoint_agents`; `TenantBoundaryService` ISOLATED+MUTABLE, removed from UNISOLATED; `EndpointAgentTenantScopingTest.php` 11 tests | 4ee9675 | — | 2026-06-29 |
| **TENANT-UNSCOPED-TABLES** | `tenant_id` on `investigations`, `response_plans`, `entities` (MUTABLE) + `threat_hunts` (APPEND_ONLY_ISOLATED); `TenantUnscopedTablesTest.php` 16 tests | 4ee9675 | — | 2026-06-29 |
| **RATE-LIMIT-BYPASS** | Parse payload before rate limiting; `extractPayloadTenantID()`; reject `tenant_id_header_mismatch` (400) when `X-Tenant-ID` ≠ payload `tenant_id`; rate-limit keyed on payload tenant; 8 Go tests | 4ee9675 | — | 2026-06-29 |
| **ENTERPRISE-074** | Consolidated Security Hardening Evidence Freeze — 9 append-only tables, `SecurityHardeningEvidenceFreezeService` (10 controls, ADVISORY_ONLY, SELF_APPROVE_BLOCKED, MIN_PASS_SCORE=0.85), command + controller + 5 views, +5 hunt domains → 177, 55 PHP tests, `xdr_security_hardening_evidence_freeze.py` 12/12 PASS | a44bfd8 | — | 2026-06-29 |
| **NOTIFY-TENANCY-GAP** | Tenant-aware SOC notification routing — `tenant_notification_settings` (mutable, isolated) + `tenant_id` on `notification_delivery_logs`; `TenantNotificationResolver` (null→global, configured→tenant w/ per-channel fallback, disabled→suppress); `SocNotifier::send($tenantId)`; `soc:sla-escalate` + `soc:notify-critical` per-tenant routing; 16 tests | 5db597c | — | 2026-06-29 |
| **PERF-IOC-LOOP** | IOC enrichment nested-loop → batched writes — `SocThreatIntelController::matchIocs()` accumulates hits + per-alert evidence, chunked `insertOrIgnore` (500) + one UPDATE per matched alert (was 1 insert + 1 UPDATE per match); fixes latent multi-match evidence loss; preserves no-dedup + hit count; 5 tests (`PerfIocLoopTest`) | (this batch) | — | 2026-06-30 |
| **PERF-ALERT-TUNE** | Suppression apply N+1 → bulk — `SocTuningController::applyActiveSuppressions()` resolves matching alert ids once per rule, single bulk UPDATE + batched history insert (was 1 UPDATE + 1 INSERT per alert); retains 500-cap + unsuppressed-only semantics; 4 tests (`PerfAlertTuneTest`) | 1e4bc3c | — | 2026-06-30 |
| **PERF-AGENT-UPDATE** | Agent command retrieval N+1 → bulk — `AgentIngestionController::config()` marks all retrieved queued commands sent in one `whereIn` UPDATE (status/sent_at/attempts+1) instead of per-command; 2 tests (`PerfAgentN1Test`) | (this batch) | — | 2026-06-30 |
| **PERF-AGENT-HEALTH-N1** | Agent health check N+1 → eager-load — `AgentHealthCheckCommand` eager-loads `agent_policies` (keyed map) + failure-breaching `endpoint_agents` (keyed map), and batches stale-agent offline status into one bulk UPDATE; staleness/alert semantics preserved; 3 tests (`PerfAgentN1Test`) | 4a0f1d9 | — | 2026-06-30 |
| **TZ-AGENT-STALE** | Timestamptz round-trip skew fix (discovered during PERF-AGENT-HEALTH-N1) — PG server session was +07 while app.timezone=UTC, so naive query-builder timestamps read back ~7h off in PHP, making every agent appear stale/offline across dashboard, `SocAgentController`, `SocApiController`, `EndpointController`, `OpsHealthController` + the health-check command. Fix: pin pgsql connection `timezone`→UTC (systemic class fix) + in-DB staleness comparison in `AgentHealthCheckCommand` (defense-in-depth). 3 lock tests (`TimezoneRoundTripTest`); full suite 4526 green | 90ea4a1 | — | 2026-06-30 |
| **GIT-RM-PYC** | Untracked 147 compiled `*.pyc`/`__pycache__` files + 3 Go `*.exe` service binaries from the git index (`git rm --cached`); added `*.exe` rules to `.gitignore` (`*.py[cod]`/`__pycache__/` already present). Removes perpetual `git status` noise; working files retained | 317f1a0 | — | 2026-06-30 |
| **PERF-TRANSACTION-GAP** | SLA escalation atomicity — `SocSlaEscalationCommand` wraps incident UPDATE + activity INSERT + audit log in one `DB::transaction`; notifications (external I/O) stay after commit; 2 tests (`PerfTransactionGapTest`) | 9800281 | — | 2026-06-30 |
| **AI-CONTEXT-EMPTY** | LLM context enrichment — `AiAnalystManager::compactContext()` now includes bounded alert details (type/severity/detector/score/ip/evidence, top 8), IOC hit values (top 8), and retrieved knowledge text (title+excerpt+score, top 6) instead of only counts; existing count keys retained; 3 tests (`AiContextEnrichmentTest`) | 34d7b0f | — | 2026-06-30 |
| **PERF-PYTHON-HTTP** | HTTP session pooling — alert-writer + incident-builder now route all outbound HTTP (Pandaproxy/OpenSearch put/post/get/delete) through a module-level `SESSION = requests.Session()` (keep-alive connection reuse) instead of per-call transient connections; tests repointed to patch `SESSION`; +2 lock tests; 23 service + 1340 Python tests pass | d2db826 | — | 2026-06-30 |
| **PERF-SUBPROCESS-POLL** | ClickHouse sync daemon in-process — `clickhouse_sync_daemon.py` imports `sync_postgres_to_clickhouse` and calls `main([])` per cycle (extracted testable `run_once()`, errors logged not raised) instead of spawning a Python interpreter subprocess each loop; `sync` `main`/`parse_args` now accept explicit `argv`; 5 tests (`tests/scripts/test_clickhouse_sync_daemon.py`) | (this batch) | — | 2026-06-30 |

---

## 2. Implementation & Verification Details

### Task 23.0.A: Tenant Null Creation Guard (Strict Mode Boundary)
* **Goal**: Prevent admins from accidentally creating records with `tenant_id = NULL` on store routes when strict mode is active.
* **Verification Details**:
  * Added `requireExplicitScope` parameters to `TenantContextAuthority::validateAndResolve()`.
  * Added `GLOBAL_SCOPE` ('_global') sentinel to allow explicit admin-only global scope creation.
  * Verified non-admins are spoofing-blocked from using `_global` sentinel.
  * Updated [ShadowSoakController.php](file:///D:/project/Detector/app/Http/Controllers/ShadowSoak/ShadowSoakController.php) to pass `requireExplicitScope: true`.
  * Verified unit and feature integration tests are green.

### Task 23.0.B / 23.1: Tenant Null Audit Command & Table Validation
* **Goal**: Provide a read-only CLI tool (`php artisan tenant:null-audit`) to count null records across all tenant-isolated tables, validating `--table` arguments strictly against isolated tables.
* **Verification Details**:
  * Implemented [TenantNullAuditCommand.php](file:///D:/project/Detector/app/Console/Commands/TenantNullAuditCommand.php).
  * Ensured command strictly performs read-only count queries (fully compliant with append-only database rule).
  * Added options for `--table` filter and `--output` JSON reports path.
  * Command returns exit code `0` when clean and `1` when null records are found.
  * Rejects tables not defined in `TenantBoundaryService::ISOLATED_TABLES` (such as `users` or non-existent tables) and exits with code `1`.

### Task 23.0.C / 23.2: Test Coverage & Regression Testing
* **Goal**: Create and run regression test coverage for strict store validations and audit outputs, including rejection of non-isolated tables.
* **Verification Details**:
  * Developed [TenantNullCreationGuardTest.php](file:///D:/project/Detector/tests/Feature/TenantNullCreationGuardTest.php) (24/24 tests PASS).
  * Updated [TenantIndexCreationSafetyTest.php](file:///D:/project/Detector/tests/Feature/TenantIndexCreationSafetyTest.php) (24/24 tests PASS).
  * Verified rejection of `--table=users` (unisolated) and `--table=nonexistent_table_xyz` in the command.

### Task ENV.1: Align Environment Variables in `.env`
* **Goal**: Synchronize all missing keys from `.env.example` to prevent configuration drift.
* **Verification Details**:
  * Appended the 20 missing keys to the end of [.env](file:///D:/project/Detector/.env).

### Task ENV.3: Hardening `.gitignore` for Reports and Logs
* **Goal**: Exclude dynamically generated test output report files and database dump logs to avoid bloating the Git history.
* **Verification Details**:
  * Appended exclusions for reports (`/reports/*.json`, `/reports/*.md`, etc.) to [.gitignore](file:///D:/project/Detector/.gitignore).

### Task T1 / #1: Fix Threat-Hunting Domain Count Mismatch (158 → 161)
* **Goal**: Update documentation and baseline settings from 158 to 161 domains (due to shadow domain soak harness domains).
* **Verification Details**:
  * Updated [README.md](file:///D:/project/Detector/README.md) to reference 161 query domains instead of 158.
  * Updated [DemoPlatformPackagingService.php](file:///D:/project/Detector/app/Services/DemoPlatformPackagingService.php) baseline count and descriptors to 161.
  * Updated [DocumentationFreezeTest.php](file:///D:/project/Detector/tests/Feature/DocumentationFreezeTest.php) to assert 161 domains.
  * Verified that `AGENTS.md` and `claude.md` already refer to 161 domains.

### Task T2: Rename Domain Count Test Methods
* **Goal**: Rename test cases checking threat hunting domain counts that had historical values (95, 100, 105, 110, 115, 120, 125, 130) to generic or matching names.
* **Verification Details**:
  * Renamed outdated test methods to `test_threat_hunting_supported_domains_count`, `test_threat_hunting_domain_count_is_161`, or `test_threat_hunting_service_has_161_domains` in:
    * `AdvancedHuntingInvestigationTest.php`
    * `CapacityGovernanceTest.php`
    * `ComplianceGovernanceTest.php`
    * `DistributedReliabilityTest.php`
    * `EndpointFleetHardeningTest.php`
    * `SoarOrchestrationTest.php`
    * `DemoPlatformPackagingTest.php`
    * `CodeLevelXdrMaturityTest.php`

### Task T3: Extract Advisory-Only Constraint Assertions into Trait
* **Goal**: Remove code duplication by extracting shared containment and auto-remediation checks.
* **Verification Details**:
  * Created `AssertsAdvisoryOnlyConstraints` trait under `tests/Traits/AssertsAdvisoryOnlyConstraints.php`.
  * Replaced 60 duplicate test methods across 12 separate feature test classes with a single clean trait inclusion.

### BUG: SecretsValidationService and phpdotenv Compatibility
* **Goal**: Enable direct modification of environment variables during test execution.
* **Verification Details**:
  * Updated `SecretsValidationService` to read directly from `getenv()` instead of `env()` helper because phpdotenv's ImmutableRepository caches variables at bootstrap, ignoring subsequent `putenv()` overrides in test runs.
