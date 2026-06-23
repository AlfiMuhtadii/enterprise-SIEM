# Review Completed — Tasks Done

Tasks yang sudah dikerjakan dan diverifikasi. Dipindahkan dari [REVIEW_BACKLOG.md](REVIEW_BACKLOG.md).
Detail temuan ada di [REVIEW_ALL.md](REVIEW_ALL.md).

---

## Completed Tasks

| Task ID | Deskripsi | Commit | Selesai |
|---|---|---|---|
| **23.1** | Validasi `--table` arg hanya untuk isolated tables di `TenantNullAuditCommand` | `194e9e6` | 2026-06-23 |
| **23.2** | Test penolakan `--table=users` (unisolated) di `TenantNullCreationGuardTest` | `194e9e6` | 2026-06-23 |
| **T1** | Update docs 158 → 161 domain (`AGENTS.md`, `claude.md`) | `194e9e6` | 2026-06-23 |
| **T2** | Rename 8 test methods domain count (95/100/…→ `supported_domains_count`) | `194e9e6` | 2026-06-23 |
| **T3** | Extract 5 advisory-only constraint methods ke `AssertsAdvisoryOnlyConstraints` trait (12 test classes) | `194e9e6` | 2026-06-23 |
| **BUG** | `SecretsValidationService` gunakan `getenv()` agar `putenv()` di test bekerja (phpdotenv ImmutableRepository) | `194e9e6` | 2026-06-23 |

---

## Catatan Implementasi

- Task T3: 60 duplicate test methods dihapus dari 12 file, diganti trait `tests/Traits/AssertsAdvisoryOnlyConstraints.php`.
  Setiap class implement `getAdvisoryServiceClass(): string`.
- BUG SecurityHardeningTest: `env()` di Laravel 10 membaca dari phpdotenv ImmutableRepository yang
  di-cache saat boot. `putenv()` hanya update PHP native env, tidak update Repository.
  Solusi: gunakan `getenv()` langsung untuk raw env var yang dimanipulasi via `putenv()` di test.
- PHP test total: 3390 (naik dari 3389 — Task 23.2 menambah 1 test baru).
