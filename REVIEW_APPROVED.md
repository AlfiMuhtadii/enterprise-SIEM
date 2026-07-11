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

| **NW-1** | Propagate `tenant_id` and demo lineage metadata in all normalizer type-specific helpers | `services/normalizer-worker/main.go` | High | [#14](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/14) |
| **CORR-1** | Align telemetry type checks for `identity_provider`/`saas_audit` in correlation worker | `services/correlation-worker/main.go` | High | [#15](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/15) |
| **DB-5** | Populate `tenant_id` in `security_alerts` and `security_incidents` write paths | `alert-writer-service/main.py`, `incident-builder-service/main.py` | High | [#16](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/16) |
| **PROD-024** | Production Runtime Profile & Safety Gates posture checker | `scripts/xdr_posture_check.py`, `docs/operations/PRODUCTION_RUNTIME_PROFILE.md` | High | [#17](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/17) |
| **INGESTION-025** | Ingestion-gateway backpressure & multi-tenant fairness hardening (IG-1/IG-2/IG-3) | `services/ingestion-gateway/main.go` | Medium | [#18](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/18) |
| **SCALE-026** | Controlled load and soak validation script | `scripts/xdr_scale_soak_validate.py` | Medium | [#19](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/19) |
| **ATTR-001** | MITRE ATT&CK TTP tagging on security_alerts (AlertMitreService, 3 nullable columns, SOC views) | `security_alerts` migration, `AlertMitreService.php`, SOC views | Medium | [#20](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/20) |
| **ATTR-002** | Alert attribution context table — advisory OSINT enrichment (append-only, offline-first) | `alert_attribution_context` migration, `AlertAttributionService.php`, views, `xdr_attribution_validate.py` | Medium | [#21](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/21) |
| **ATTR-003** | GeoIP/ASN offline enrichment lookup service using bundled fixture | `GeoAsnLookupService.php`, `geo_asn_fixtures.json` | Low | [#22](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/22) |
| **RBAC-1** | Add easm.view/easm.scan/pilot.readiness.view to admin + analyst roles in config/soc.php | `config/soc.php` | High | [#23](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/23) |
| **EASM-1** | Enforce TenantContextAuthority in EasmController (replace raw header trust) | `EasmController.php` | High | [#24](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/24) |
| **PILOT-1** | Scope PilotReadinessMatrixController index/show/report by validated tenant context | `PilotReadinessMatrixController.php` | High | [#25](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/25) |

| **ENTERPRISE-068** | Container Resource Governance — `deploy.resources.limits` on 6 services in dev + prod compose, `xdr_container_resource_validate.py` (12 checks) | `docker-compose.yml`, `docker-compose.prod.yml`, `scripts/xdr_container_resource_validate.py` | Medium | — |
| **ENTERPRISE-069** | PostgreSQL RLS Policy Scaffolding (advisory) — `scaffold_rls_policies` migration (DO block, pgsql-only, no enforcement), `TenantRlsStatusCommand` (read-only), `xdr_rls_scaffold_validate.py` | `database/migrations/`, `TenantRlsStatusCommand.php`, `scripts/xdr_rls_scaffold_validate.py`, `RlsPolicyScaffoldingTest.php` | Medium | — |
| **ENTERPRISE-070** | Tenant Null Backfill Pre-Flight Validation — `TenantBackfillPreflightCommand` (6 CHK read-only), GATE-08 in `TenantStrictModeReadinessService`, `xdr_tenant_backfill_preflight.py` | `TenantBackfillPreflightCommand.php`, `TenantStrictModeReadinessService.php`, `scripts/xdr_tenant_backfill_preflight.py` | High | — |
| **ENTERPRISE-071** | RAG Knowledge Base Operational Integration — `RagOperationalCheckCommand` (`ai:knowledge-check`), `xdr_rag_operational_validate.py` (12 checks), PHP integration tests | `RagOperationalCheckCommand.php`, `scripts/xdr_rag_operational_validate.py`, `RagOperationalIntegrationTest.php` | Medium | — |
| **ENTERPRISE-072** | Shadow Domain Soak Pre-Flight Checklist — `DomainSoakHarnessService::getPreflightStatus()`, `ShadowSoakPreflightCommand` (`domain:soak-preflight`), `xdr_shadow_soak_preflight.py` | `DomainSoakHarnessService.php`, `ShadowSoakPreflightCommand.php`, `scripts/xdr_shadow_soak_preflight.py`, `ShadowSoakPreflightTest.php` | Medium | — |
| **ENTERPRISE-073** | Redpanda Multi-Node HA Template — `docker-compose.ha.yml` (3-broker cluster), `xdr_redpanda_ha_validate.py` (12 checks), `--replication-factor` flag in `xdr_topic_bootstrap.py` | `docker-compose.ha.yml`, `scripts/xdr_redpanda_ha_validate.py`, `scripts/xdr_topic_bootstrap.py` | Low | — |

## Backlog Hardening Tasks (2026-06-29)

| Task ID | Description | Primary File(s) | Priority | GH Issue |
|---|---|---|---|---|
| **ENV-CACHE-DRIFT** | Map XDR_INTERNAL_AUTH_SECRET to `config/xdr.php`; `InternalAuthService` uses `config()` not `env()` | `config/xdr.php`, `InternalAuthService.php`, `InternalAuthConfigMappingTest.php` | Medium | — |
| **CMD-SHARED-HMAC** | Per-agent `hmac_secret` column on `endpoint_agents`; `EndpointResponseCommandService::verifyAgentSignature()` uses per-agent secret with shared-token fallback | migration `2026_06_29_070001`, `EndpointResponseCommandService.php`, `PerAgentHmacSecretTest.php` | Critical | — |
| **AGENT-TENANCY-GAP** | `tenant_id` on `endpoint_agents`; `TenantBoundaryService` ISOLATED/MUTABLE; remove from UNISOLATED | migration `2026_06_29_070001`, `TenantBoundaryService.php`, `EndpointAgentTenantScopingTest.php` | High | — |
| **TENANT-UNSCOPED-TABLES** | `tenant_id` on `investigations`, `response_plans`, `threat_hunts`, `entities`; updated ISOLATED/MUTABLE/APPEND_ONLY_ISOLATED | migration `2026_06_29_080001`, `TenantBoundaryService.php`, `TenantUnscopedTablesTest.php` | High | — |
| **RATE-LIMIT-BYPASS** | Parse payload before rate limiting; validate X-Tenant-ID header vs payload `tenant_id`; `extractPayloadTenantID()` helper; 8 new Go tests | `services/ingestion-gateway/main.go`, `main_test.go` | Medium | — |

---

**Batch 16 (2026-06-28)**

| Task ID | Description | Primary File(s) | Priority | GH Issue |
|---|---|---|---|---|
| **TC-1** | Add TenantContextAuthority advisory scoping to SecurityAlertController, SocIncidentController, SocDashboardController, SocApiController | `app/Http/Controllers/Soc*.php`, `SecurityAlertController.php` | High | — |
| **PTS-1** | Extend fastapi test stub with Depends/Header/HTTPException for alert-writer and incident-builder unit tests | `tests/alert_writer/test_alert_writer.py`, `tests/incident_builder/test_incident_builder.py` | Medium | — |
| **DB-5-DEFECT** | Add top-level `TenantID` field to correlation-worker `Alert` struct so alert-writer reads it via Pydantic top-level field | `services/correlation-worker/main.go`, `main_test.go` | High | — |
| **IG-DOS** | Add `lastSeen` atomic.Int64 + TTL eviction goroutine to ingestion-gateway `tenantBucket` | `services/ingestion-gateway/main.go` | High | — |
| **RESP-1** | Route SocAgentController/SocResponseController commands through EndpointResponseCommandService with LEGACY_TYPE_MAP | `SocAgentController.php`, `SocResponseController.php`, `EndpointResponseCommandService.php` | High | — |
| **AGENT-API-1** | Advisory-only signature check in EndpointAgentApiController.pollCommands() — logs SecurityHardeningEvent, does not block | `EndpointAgentApiController.php`, `EndpointResponseCommandService.php` | Medium | — |
| **INT-AUTH-1** | Add `ports: !reset []` to pipeline service entries in docker-compose.prod.yml | `docker-compose.prod.yml` | High | — |
| **INT-AUTH-2** | Add X-Internal-Service-Token validation on GET /dlq in alert-writer and incident-builder | `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py` | High | — |
| **TEST-1** | Replace trivial ExampleTest assertions with meaningful PHP version, login, and dashboard redirect checks | `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | Low | — |
| **TEST-2** | Migrate 13 Feature test files to `AssertsAdvisoryOnlyConstraints` trait; remove 5 duplicated inline methods per file | 13 `tests/Feature/*.php` | Low | — |
| **AI-KB-FEED-INGEST** | Bundled offline MITRE ATT&CK technique-coverage KB import — 103 fixtures derived from the 133-rule registry, imported via existing `AiKnowledgeSeedService` (no live feed, no new service) | `database/seeders/data/rag_knowledge_fixtures.json`, `tests/Feature/AiKnowledgeSeedTest.php` | Low | [#26](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/26) |
| **AI-1** | ai-rag-service's `/v1/analyze`/`/v1/retrieve`/`/v1/embed` had zero authentication; added the same `X-Internal-Service-Token` pattern every other internal service already uses | `services/ai-rag-service/main.py`, `app/Support/AiRagServiceProvider.php`, `config/soc.php` | High | [#47](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/47) |
| **AI-2** | `SocAiController::generate()`/`review()` checked incident existence only (not ownership) and looked up suggestions globally by ID; added `tenant_id` to `ai_analyst_suggestions` + tenant ownership checks matching the `ENT-TENANCY-*` convention | `app/Http/Controllers/SocAiController.php`, `app/Support/AiAnalystManager.php` | High | [#48](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/48) |
| **AI-3** | Alert `evidence`/`raw_event` reached every AI provider (including the standalone ai-rag-service over a real network call, and remote LLM APIs) completely unredacted; centrally redacted in `incidentContext()` via the existing `TraceRedactor` primitive | `app/Support/AiAnalystManager.php` | High | [#49](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/49) |
| **IDENTITY-SSO-MFA (SAML)** | Completes the remaining SAML scope: SP-initiated SAML 2.0 SSO federation login via `onelogin/php-saml`, off by default (`SOC_SAML_SSO_ENABLED`); assertions verified against a pinned `config('saml.idp.x509cert')`, never an embedded cert; never auto-provisions accounts | `app/Services/SamlSsoService.php`, `app/Http/Controllers/Auth/SamlSsoController.php`, `config/saml.php`, `routes/auth.php` | High | [#50](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/50) |

## Notes

- Tasks T1–BUG are from the **TEST-SUITE-AUDIT** and **SECRETS-BUG** categories (REVIEW_ALL.md §1–§2).
- Tasks 23.1–23.2 are from the **TENANCY-023** batch (BACKLOG-TENANCY-023 sub-findings).
- Tasks INFRA-1/INFRA-2 are from the **INFRA-AUDIT** category (REVIEW_ALL.md §5).
- Tasks DB-1/DB-2 are from the **DATABASE-AUDIT** category (REVIEW_ALL.md §3).
- All batch 15 tasks are implemented and closed. See `REVIEW_COMPLETED.md` for commit references.
- Batch 16 (10 tasks) implemented 2026-06-28; commit `bf5ca6e`; 4259 PHP tests green.
- ENTERPRISE-065/066/067 implemented 2026-06-28; commit `6688302`; 4347 PHP + 47 Python tests green.
