# Review Completed — Tasks Done

This file tracks all completed and verified implementations for the tenancy, security, and test architecture.

---

## 1. Summary of Completed Tasks

| Task ID | Description | Reference / Commit | Date Completed |
|---|---|---|---|
| **23.0.A** | Tenant Null Creation Guard (Strict Mode Boundary) | Controller & Middleware Protection | 2026-06-23 |
| **23.0.B** | Tenant Null Audit Command & Table Validation | `TenantNullAuditCommand.php` / `194e9e6` | 2026-06-23 |
| **23.0.C** | Test Coverage & Regression Testing | `TenantNullCreationGuardTest.php` | 2026-06-23 |
| **ENV.1** | Align Environment Variables in `.env` | Env alignment | 2026-06-23 |
| **ENV.3** | Hardening `.gitignore` for Reports and Logs | Gitignore rules | 2026-06-23 |
| **T1 / #1** | Fix threat-hunting domain count mismatch (158 → 161) | Docs & Packaging Service / `194e9e6` | 2026-06-23 |
| **T2** | Rename 8 test methods domain count (95/100/…→ `supported_domains_count`) | Test cleanup / `194e9e6` | 2026-06-23 |
| **T3** | Extract 5 advisory-only constraint methods to Trait | Trait refactor (12 classes) / `194e9e6` | 2026-06-23 |
| **BUG** | `SecretsValidationService` using `getenv()` for `putenv()` compatibility | Environment compatibility / `194e9e6` | 2026-06-23 |
| **INFRA-1** | Restrict docker-compose datastore ports from `0.0.0.0` → `127.0.0.1` | `docker-compose.yml` | 2026-06-23 |
| **INFRA-2** | Move ClickHouse/Grafana/OpenSearch hardcoded secrets to `${VAR:-default}` | `docker-compose.yml`, `.env.example` | 2026-06-23 |
| **DB-2** | Add `tenant_id` index to `advisory_findings` + 9 `shadow_soak_*` tables | Migration `2026_06_24_0700001` | 2026-06-23 |
| **DB-1** | Add `tenant_id` nullable column + index to `advisory_finding_events` and `dlq_normalization_events` | Migration `2026_06_24_0800001` + models + services | 2026-06-23 |

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
