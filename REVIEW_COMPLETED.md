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
