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
