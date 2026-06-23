# Review Findings — Master List

Central record of all code review, safety audit, and test suite findings.
Tracking status: lihat [REVIEW_BACKLOG.md](REVIEW_BACKLOG.md) dan [REVIEW_COMPLETED.md](REVIEW_COMPLETED.md).

---

## Review Batch 1 — BACKLOG-TENANCY-023 Hardening (2026-06-23)

### Finding 23.1 — `--table` option tidak divalidasi terhadap isolated tables
- **Severity:** Medium
- **File:** `app/Console/Commands/TenantNullAuditCommand.php`
- **Issue:** `resolveTables()` menerima nama tabel apapun dari `--table` arg. Operator bisa audit tabel non-isolated (e.g. `users`) tanpa peringatan.
- **Fix:** Validasi `--table` terhadap `TenantBoundaryService::ISOLATED_TABLES`; exit `FAILURE` jika tidak terdaftar.
- **Task:** 23.1

### Finding 23.2 — Tidak ada test untuk penolakan tabel non-isolated
- **Severity:** Low
- **File:** `tests/Feature/TenantNullCreationGuardTest.php`
- **Issue:** `test_null_audit_command_missing_table_reports_not_fails` hanya menguji tabel yang tidak ada di DB, bukan tabel yang ada tapi tidak tenant-isolated.
- **Fix:** Tambah `test_null_audit_command_unisolated_table_option_fails()` — assert exit code 1 saat `--table=users`.
- **Task:** 23.2

---

## Review Batch 3 — DATABASE-AUDIT (2026-06-23)

### Finding DB-1 — Missing `tenant_id` on `advisory_finding_events` and `dlq_normalization_events`
- **Severity:** High (Isolation Gap)
- **Files:** `database/migrations/2026_06_23_0100001_create_advisory_findings_tables.php`, `database/migrations/2026_06_24_0100001_create_dlq_review_tables.php`
- **Issue:** Both tables are declared in `TenantBoundaryService::ISOLATED_TABLES` but have no `tenant_id` column. `TenantNullAuditCommand` gracefully shows `NO_TENANT_COLUMN` instead of crashing, but `tableHasIsolation()` falsely returns `true`.
- **Fix:** Add nullable `tenant_id` column + index via new migration; update insert paths in services.
- **Task:** DB-1

### Finding DB-2 — Missing indexes on `tenant_id` in `advisory_findings` and 9 `shadow_soak_*` tables
- **Severity:** Medium (Performance)
- **Files:** `database/migrations/2026_06_23_0100001_create_advisory_findings_tables.php`, shadow soak migrations
- **Issue:** `advisory_findings` has `tenant_id` column but no index. All 9 `shadow_soak_*` tables have `tenant_id` but no index. Scoped queries cause table scans.
- **Fix:** Additive migration adding `->index('tenant_id')` to all affected tables.
- **Task:** DB-2

### Finding DB-3 — Seeder users locked out in strict mode
- **Severity:** Operational Bug (strict mode only)
- **Files:** `database/seeders/UserSeeder.php`, `database/seeders/DemoSocSeeder.php`
- **Issue:** Demo users created by seeders have no entries in `user_tenant_memberships`. In strict mode, these users cannot access any scoped endpoint.
- **Fix:** Add default tenant + membership rows to seeders.
- **Task:** DB-3 (REJECTED — see REVIEW_REJECTED.md)

### Finding DB-4 — Unscoped demo alerts/incidents (`tenant_id = NULL`) in DemoSocSeeder
- **Severity:** Operational Bug (strict mode only)
- **Files:** `database/seeders/DemoSocSeeder.php`
- **Issue:** Demo `security_alerts` and `security_incidents` created with `tenant_id = NULL`. Hidden from scoped queries in strict mode; causes `tenant:null-audit` to report `HAS_NULL`.
- **Fix:** Seed with a known demo tenant_id.
- **Task:** DB-4 (REJECTED — see REVIEW_REJECTED.md)

---

## Review Batch 4 — ENV-STRUCTURE-AUDIT (2026-06-23)

### Finding ENV-1 — Missing env keys in active `.env` / template drift
- **Severity:** High (Config Drift)
- **Files:** `.env.example`, `.env.local.example`, `.env.production.example`, `.env.staging.example`
- **Issue:** Active `.env` missing 20 keys including `XDR_TENANT_STRICT_MODE` and `XDR_ENFORCE_INTERNAL_AUTH`. Template files have severe drift between each other.
- **Fix:** Partially done (Best Practice Refactor added `GITHUB_TOKEN`/`GITHUB_REPO` to `.env.example`). Full sync of template files needed.
- **Note:** Partially addressed. Full template sync tracked separately.
- **Task:** ENV-1 (PARTIALLY DONE via Best Practice Refactor — further sync tracked in ENV-TEMPLATE-SYNC)

### Finding ENV-2 — Repository bloat / missing `.gitignore` entries
- **Severity:** Low (Cleanliness)
- **Files:** `.gitignore`
- **Issue:** `/reports` folder not ignored; large soak JSON files (42MB) and one-off demo files pollute git status.
- **Fix:** Add `reports/`, `storage/resilience/`, `dist/` patterns to `.gitignore`.
- **Note:** `.gitignore` already modified in working tree (Best Practice Refactor). Will be committed with INFRA tasks.
- **Task:** ENV-2 (ADDRESSED in Best Practice Refactor commit)

### Finding ENV-3 — Inconsistent controller naming conventions
- **Severity:** Low (Code Smell)
- **Files:** `app/Http/Controllers/`
- **Issue:** Mixed pluralization: `AdvisoryFindingsController` vs `DlqController`, `ThreatHuntController`.
- **Fix:** Not a bug; refactoring would break routes with no safety benefit.
- **Task:** ENV-3 (REJECTED — see REVIEW_REJECTED.md)

---

## Review Batch 5 — INFRA-AUDIT (2026-06-23)

### Finding INFRA-1 — Datastore ports bound to `0.0.0.0` in `docker-compose.yml`
- **Severity:** High (Boundary Breach)
- **Files:** `docker-compose.yml`
- **Issue:** `postgres:5432`, `clickhouse:8123/9000`, `opensearch:9200/9600`, `qdrant:6333/6334` all bind to `0.0.0.0`, exposing datastores to the host's public interfaces.
- **Fix:** Change to `127.0.0.1:HOST:CONTAINER` form for all datastore port bindings.
- **Task:** INFRA-1

### Finding INFRA-2 — Plaintext hardcoded secrets in `docker-compose.yml`
- **Severity:** Medium (Security Smell)
- **Files:** `docker-compose.yml`
- **Issue:** ClickHouse password `detector`, Grafana admin `admin`, OpenSearch admin `DetectorAdmin123!` hardcoded inline.
- **Fix:** Move to `.env` variables with `.env.example` defaults.
- **Task:** INFRA-2

### Finding INFRA-3 — No memory/CPU limits on intensive containers
- **Severity:** Low (Operational)
- **Task:** INFRA-3 (REJECTED — low priority for academic scope, see REVIEW_REJECTED.md)

### Finding INFRA-4 — Grafana provisioning mounts not read-only
- **Severity:** Low
- **Task:** INFRA-4 (REJECTED — low priority for academic scope, see REVIEW_REJECTED.md)

---

## Review Batch 6 — AI-RAG-AUDIT (2026-06-23)

### Finding RAG-1 — Empty knowledge base on fresh deployment
- **Severity:** Medium (Groundedness)
- **Files:** `database/seeders/DemoSocSeeder.php`
- **Issue:** Seeders don't populate `soc_knowledge_base`. RAG pipeline returns zero results on fresh deploy; falls back to parametric outputs.
- **Note:** `AiGuardrails` already handles this with post-processing warnings. Design posture for academic demo.
- **Task:** RAG-1 (REJECTED — see REVIEW_REJECTED.md)

---

## Review Batch 7 — INGESTION-GATEWAY-AUDIT (2026-06-23)

### Finding IG-1 — Synchronous normalizer metrics polling in request path
- **Severity:** Medium (Conditional Risk — high RPS only)
- **Files:** `services/ingestion-gateway/main.go`
- **Task:** IG-1 (REJECTED — conditional risk; academic RPS well below threshold, see REVIEW_REJECTED.md)

### Finding IG-2 — Global rate limiter token starvation
- **Severity:** Medium (Conditional Risk — multi-tenant abuse only)
- **Files:** `services/ingestion-gateway/main.go`
- **Task:** IG-2 (REJECTED — conditional risk; single-tenant academic scope, see REVIEW_REJECTED.md)

### Finding IG-3 — 15-second publish retry timeout causing socket exhaustion
- **Severity:** Medium (Conditional Risk — high traffic + Redpanda outage)
- **Files:** `services/ingestion-gateway/main.go`
- **Task:** IG-3 (REJECTED — conditional risk; existing backpressure + rate limit adequate for academic scope, see REVIEW_REJECTED.md)

---

## Review Batch 2 — TEST-SUITE-AUDIT (2026-06-23)

### Finding T1 — Dokumentasi menyebut 158 domain, kode aktual 161
- **Severity:** Low (doc bug)
- **Files:** `AGENTS.md` line 17, `claude.md` line 173
- **Issue:** Shadow Domain Soak Harness (BACKLOG-018) menambah 3 domain → total 161. Docs belum diupdate dari 158 → 161.
- **Fix:** Update angka di `AGENTS.md` dan `claude.md`.
- **Task:** T1

### Finding T2 — Nama method test tidak sesuai dengan nilai assertCount
- **Severity:** Low (code smell / misleading)
- **Files:** 8 test classes (lihat detail di bawah)
- **Issue:** Method name encode angka historis (95, 100, 105, …) tapi body assert `assertCount(161, ...)`.
- **Affected methods:**
  - `PilotExecutionTest::test_threat_hunting_has_95_supported_domains`
  - `OperationalIntelligenceTest::test_threat_hunting_has_100_supported_domains`
  - `AnalystOptimizationTest::test_threat_hunting_has_105_supported_domains`
  - `TelemetryScalePilotTest::test_threat_hunting_has_110_supported_domains`
  - `LongRunningOperationalTest::test_threat_hunting_has_115_supported_domains`
  - `EndpointSensorAdvancedTelemetryTest::test_threat_hunting_has_120_supported_domains`
  - `EnterpriseDeploymentHardeningTest::test_threat_hunting_has_125_supported_domains`
  - `EnterpriseOperationsAutomationTest::test_threat_hunting_has_130_supported_domains`
- **Fix:** Rename semua ke `test_threat_hunting_supported_domains_count`.
- **Task:** T2

### Finding T3 — 95 duplikasi assertion "no containment method" di 19 test class
- **Severity:** Low (maintainability)
- **Files:** 19 feature test classes
- **Issue:** Setiap class mengulangi 5 method identik: `test_no_isolate_host_method`, `test_no_quarantine_host_method`, `test_no_execute_shell_method`, `test_no_kill_process_method`, `test_no_auto_remediate_method`.
- **Fix:** Extract ke trait `tests/Traits/AssertsAdvisoryOnlyConstraints.php`, gunakan di semua class tersebut.
- **Task:** T3
