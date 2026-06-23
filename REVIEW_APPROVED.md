# Review Approved — Tasks Approved for Implementation

This file tracks all tasks proposed by Gemini/Antigravity that have been validated and approved by Claude for implementation.

Each approved task has a corresponding GitHub Issue created via `scripts/sync_backlog.py`.

---

## Approved Tasks

| Task ID | Description | Primary File(s) | Priority | GH Issue |
|---|---|---|---|---|
| **T1** | Fix threat-hunting domain count mismatch (158 → 161) in docs, service, and tests | `DemoPlatformPackagingService.php`, `DocumentationFreezeTest.php` | Medium | [#4](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/4) |
| **T2** | Rename stale domain-count test methods (95/100/…) to `test_threat_hunting_supported_domains_count` | 8 test files | Low | [#5](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/5) |
| **T3** | Extract duplicate advisory-only constraint assertions into reusable `AssertsAdvisoryOnlyConstraints` Trait | `tests/Traits/AssertsAdvisoryOnlyConstraints.php`, 12 test classes | Medium | [#6](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/6) |
| **BUG** | `SecretsValidationService`: use `getenv()` instead of `env()` for `putenv()`-based test overrides | `app/Services/SecretsValidationService.php` | High | [#7](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/7) |
| **23.1** | `TenantNullAuditCommand`: validate `--table` argument against `ISOLATED_TABLES`; reject non-isolated tables with exit 1 | `app/Console/Commands/TenantNullAuditCommand.php` | High | [#8](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/8) |
| **23.2** | Add tests for `--table` rejection of non-isolated and non-existent tables in `TenantNullAuditCommand` | `tests/Feature/TenantNullCreationGuardTest.php` | Medium | [#9](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/9) |
| **INFRA-1** | Restrict docker-compose datastore port bindings from `0.0.0.0` to `127.0.0.1` | `docker-compose.yml` | High | [#10](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/10) |
| **INFRA-2** | Move hardcoded ClickHouse/Grafana/OpenSearch credentials to `${VAR:-default}` env interpolation | `docker-compose.yml`, `.env.example` | High | [#11](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/11) |
| **DB-2** | Add `tenant_id` index to `advisory_findings` and 9 `shadow_soak_*` tables | new migration `2026_06_24_0700001` | Medium | [#12](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/12) |
| **DB-1** | Add `tenant_id` nullable column + index to `advisory_finding_events` and `dlq_normalization_events`; propagate in `appendEvent()` | new migration `2026_06_24_0800001`, models, services | High | [#13](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/13) |

---

## Notes

- Tasks T1–BUG are from the **TEST-SUITE-AUDIT** and **SECRETS-BUG** categories (REVIEW_ALL.md §1–§2).
- Tasks 23.1–23.2 are from the **TENANCY-023** batch (BACKLOG-TENANCY-023 sub-findings).
- Tasks INFRA-1/INFRA-2 are from the **INFRA-AUDIT** category (REVIEW_ALL.md §5).
- Tasks DB-1/DB-2 are from the **DATABASE-AUDIT** category (REVIEW_ALL.md §3).
- All 10 tasks are implemented and closed. See `REVIEW_COMPLETED.md` for commit references.
