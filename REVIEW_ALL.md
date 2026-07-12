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
- **Task:** DB-3 (ACCEPTED RISK — strict mode off by default; demo-scoped seeders intentionally global; must fix if strict mode enabled in production pilot, see REVIEW_REJECTED.md §3)

### Finding DB-4 — Unscoped demo alerts/incidents (`tenant_id = NULL`) in DemoSocSeeder
- **Severity:** Operational Bug (strict mode only)
- **Files:** `database/seeders/DemoSocSeeder.php`
- **Issue:** Demo `security_alerts` and `security_incidents` created with `tenant_id = NULL`. Hidden from scoped queries in strict mode; causes `tenant:null-audit` to report `HAS_NULL`.
- **Fix:** Seed with a known demo tenant_id.
- **Task:** DB-4 (ACCEPTED RISK — demo data intentionally global-scope for full-dashboard showcase; HAS_NULL audit output is expected and documented; must scope if production pilot enables strict mode, see REVIEW_REJECTED.md §3)

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
- **Task:** ENV-3 (REJECTED — renaming breaks routes with zero security/functional benefit; do not implement, see REVIEW_REJECTED.md §1)

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
- **Severity:** Low–Medium (scale-dependent)
- **Task:** INFRA-3 (DEFERRED — enterprise reliability finding; not in scope for local dev compose; must address before production pilot, see REVIEW_REJECTED.md §2)

### Finding INFRA-4 — Grafana provisioning mounts not read-only
- **Severity:** Low
- **Task:** INFRA-4 (ACCEPTED RISK — valid in production; intentionally writable in local dev for dashboard authoring; port already localhost-only via INFRA-1, see REVIEW_REJECTED.md §3)

---

## Review Batch 6 — AI-RAG-AUDIT (2026-06-23)

### Finding RAG-1 — Empty knowledge base on fresh deployment
- **Severity:** Medium (Groundedness)
- **Files:** `database/seeders/DemoSocSeeder.php`
- **Issue:** Seeders don't populate `soc_knowledge_base`. RAG pipeline returns zero results on fresh deploy; falls back to parametric outputs.
- **Note:** `AiGuardrails` already handles this with post-processing warnings. Design posture for academic demo.
- **Task:** RAG-1 (ACCEPTED RISK — AiGuardrails handles empty retrieval; seeding belongs to runtime ingest, not migrations; fallback sufficient for academic demo, see REVIEW_REJECTED.md §3)

---

## Review Batch 7 — INGESTION-GATEWAY-AUDIT (2026-06-23)

### Finding IG-1 — Synchronous normalizer metrics polling in request path
- **Severity:** Medium (Conditional Risk — high RPS only)
- **Files:** `services/ingestion-gateway/main.go`
- **Task:** IG-1 (IMPLEMENTED — BACKLOG-INGESTION-025: admissionAllowed() reads cached atomic; startMetricsPoller() background goroutine; see REVIEW_REJECTED.md §2)

### Finding IG-2 — Global rate limiter token starvation
- **Severity:** Medium (Conditional Risk — multi-tenant abuse only)
- **Files:** `services/ingestion-gateway/main.go`
- **Task:** IG-2 (IMPLEMENTED — BACKLOG-INGESTION-025: per-tenant token bucket map via X-Tenant-ID header; startTenantBucketRefiller() background goroutine; see REVIEW_REJECTED.md §2)

### Finding IG-3 — 15-second publish retry timeout causing socket exhaustion
- **Severity:** Medium (Conditional Risk — high traffic + Redpanda outage)
- **Files:** `services/ingestion-gateway/main.go`
- **Task:** IG-3 (IMPLEMENTED — BACKLOG-INGESTION-025: context.WithTimeout per attempt, exponential backoff, circuitBreaker struct with configurable failure threshold + open duration; see REVIEW_REJECTED.md §2)

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

---

## Review Batch 8 — PIPELINE-AUDIT (2026-06-24)

### Finding NW-1 — Missing tenant_id and demo lineage in normalizer worker helpers
- **Severity:** High (Isolation & Lineage Gap)
- **File:** `services/normalizer-worker/main.go`
- **Issue:** Helper functions for endpoint, network, identity, saas, ticket, and notification telemetry discard `"tenant_id"`, `"demo_run_id"`, `"source_event_id"`, and `"scenario_id"`.
- **Fix:** Propagate these keys from the raw event map to the normalized structure in all type-specific normalizer helper functions.
- **Task:** NW-1

### Finding CORR-1 — Telemetry type string mismatch for identity and saas events
- **Severity:** High (Detection Gap)
- **Files:** `services/correlation-worker/main.go`, `services/normalizer-worker/main.go`
- **Issue:** Normalizer worker outputs `"identity_provider"` and `"saas_audit"`, while correlation worker expects `"identity"` and `"saas"`. All normalized identity/SaaS events are silently ignored during correlation.
- **Fix:** Support both strings in correlation worker telemetry type checks, or align normalizer to output `"identity"` and `"saas"`.
- **Task:** CORR-1

---

## Review Batch 9 — DATABASE-PIPELINE-ALIGN (2026-06-24)

### Finding DB-5 — Missing tenant_id in security_alerts and security_incidents PostgreSQL write paths
- **Severity:** High (Isolation Gap)
- **Files:** `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`
- **Issue:** The migration adds `tenant_id` column to both tables, but python services insert `NULL` since they don't map `tenant_id` in their PostgreSQL write queries. In strict tenancy mode, these records are excluded from scoped tenant queries.
- **Fix:** Update `AlertPayload` and PostgreSQL write statements in alert-writer and incident-builder to resolve and insert `tenant_id` dynamically.
- **Task:** DB-5

---

## Review Batch 10 - DOC-DRIFT-AUDIT (2026-06-26)

### Finding DOC-1 - Threat hunting domain count drift across docs
- **Severity:** Low (Documentation Drift)
- **Files:** `AGENTS.md`, `CLAUDE.md`, `docs/INTERVIEW_SHOWCASE_GUIDE.md`, `docs/portfolio/CAPABILITY_MATRIX.md`, `docs/RELEASE_NOTES.md`, `docs/architecture/plantuml/threat_hunting_flow.puml`
- **Issue:** Runtime/test baseline and `README.md` use 164 supported threat-hunting domains, while reviewer/evaluator documents still mention older values (`161` in agent docs and `158` in portfolio/showcase/release docs).
- **Fix:** Align reviewer/evaluator-facing documents to 164, or avoid hard-coded domain counts where possible.
- **Task:** Not backlog; documentation-only cleanup.

### Finding DOC-2 - Threat hunting permission map drift
- **Severity:** Low (Documentation Drift / RBAC Context Risk)
- **Files:** `AGENTS.md`, `docs/architecture/FEATURE_REGISTRY.md`, `routes/web.php`, `config/soc.php`
- **Issue:** Actual threat-hunt read routes use `soc:investigation.view` and query/replay routes use `soc:investigation.create`, but docs list `/threat-hunts` as `soc:hunt.view` or `soc:dashboard.view`. `config/soc.php` does not define `hunt.view`.
- **Fix:** Update module maps to reflect current route middleware, unless a future product decision creates a dedicated hunt permission split.
- **Task:** Not backlog; documentation-only cleanup.

---

## Review Batch 11 - RBAC-TENANT-AUDIT (2026-06-26)

### Finding RBAC-1 - EASM and Pilot Readiness Matrix route permissions are missing from RBAC config
- **Severity:** High (Access Regression)
- **Files:** `routes/web.php`, `config/soc.php`, `app/Http/Middleware/EnsureSocPermission.php`, `app/Support/Rbac.php`
- **Issue:** Routes use `soc:easm.view`, `soc:easm.scan`, and `soc:pilot.readiness.view`, but no role in `config/soc.php` has `easm.view`, `easm.scan`, or `pilot.readiness.view`. Since `Rbac::can()` requires exact permission membership, these routes deny all users including admins.
- **Fix:** Add the missing permissions to intended roles or retarget routes to existing permissions; add route-level feature tests for admin access.
- **Task:** Proposed backlog item.

### Finding EASM-1 - EASM controller trusts raw tenant input instead of TenantContextAuthority
- **Severity:** High (Tenant Isolation Failure)
- **Files:** `app/Http/Controllers/EasmController.php`, `app/Services/EasmPassiveScanService.php`, `app/Services/TenantContextAuthority.php`
- **Issue:** `EasmController::resolveTenantId()` accepts `X-Tenant-Id`, input `tenant_id`, or `default` without membership validation. The service ownership guard checks against this untrusted tenant value, so a reachable EASM route can be spoofed into another tenant context.
- **Fix:** Use `TenantContextAuthority::validateAndResolve()` for EASM read/store/scan paths and add cross-tenant spoof rejection tests.
- **Task:** Proposed backlog item.

### Finding PILOT-1 - Pilot Readiness Matrix routes are not tenant scoped
- **Severity:** High (Tenant Isolation Failure)
- **Files:** `app/Http/Controllers/PilotReadinessMatrixController.php`, `app/Models/PilotReadinessMatrixRun.php`, `app/Services/EnterprisePilotReadinessMatrixService.php`
- **Issue:** `index()`, `show()`, and `report()` query `PilotReadinessMatrixRun` globally without tenant filtering or access assertion, even though matrix runs carry `tenant_id`.
- **Fix:** Resolve tenant context with `TenantContextAuthority`, filter list routes, assert detail/report access, and add two-tenant route tests.
- **Task:** Proposed backlog item.

---

## Review Batch 12 - AI-RAG-AUDIT-2 (2026-06-26)

### Finding AI-1 - AI/RAG FastAPI service endpoints are unauthenticated
- **Severity:** High (Service Trust Boundary Failure)
- **Files:** `services/ai-rag-service/main.py`, `docker-compose.yml`, `docker-compose.prod.yml`
- **Issue:** `/v1/analyze`, `/v1/retrieve`, and `/v1/embed` have no token/HMAC auth, while base compose publishes `8094:8094` and production compose does not reset that mapping for `ai-rag-service`.
- **Fix:** Add internal service auth to AI/RAG endpoints and restrict/remove host exposure where not required.
- **Task:** Proposed backlog item.

### Finding AI-2 - AI generation and suggestion review are not tenant-scoped
- **Severity:** High (Tenant Isolation Failure)
- **Files:** `app/Http/Controllers/SocAiController.php`, `app/Support/AiAnalystManager.php`, `routes/web.php`
- **Issue:** AI generation checks only incident existence by `incident_id`; AI suggestion review updates by `suggestion_id` globally. Neither path validates tenant context or target incident access.
- **Fix:** Resolve tenant context, assert target incident access, and store/enforce tenant context for AI suggestions.
- **Task:** Proposed backlog item.

### Finding AI-3 - AI/RAG service receives unredacted alert evidence
- **Severity:** High (Conditional Data Exposure Risk)
- **Files:** `app/Support/AiRagServiceProvider.php`, `app/Support/AiAnalystManager.php`, `app/Support/TraceRedactor.php`
- **Issue:** When standalone AI/RAG service mode is enabled, Laravel sends full alert-derived evidence to `/v1/analyze` without deep redaction or an allowlisted AI evidence projection.
- **Fix:** Redact and minimize outbound evidence before AI/RAG service calls; add tests with synthetic secrets/emails.
- **Task:** Proposed backlog item.

### Finding RAG-2 - Retrieved citations are not supplied to remote LLM prompts
- **Severity:** Medium (Grounding / Correctness Gap)
- **Files:** `app/Support/AiAnalystManager.php`, `app/Support/SocKnowledgeRetriever.php`, `app/Support/RemoteLlmProvider.php`
- **Issue:** Local citations are retrieved and stored, but `renderPrompt()` sends only compact context with citation count, not citation excerpts/content. Remote output can appear citation-backed without being grounded in retrieved knowledge.
- **Fix:** Include bounded citation excerpts or explicit citation IDs in remote prompts and track whether citations were included.
- **Task:** Proposed backlog item.

### Finding KB-1 - SOC knowledge base is global and not tenant-scoped
- **Severity:** Medium (Conditional Tenant Data Exposure Risk)
- **Files:** `app/Http/Controllers/SocKnowledgeBaseController.php`, `app/Support/SocKnowledgeRetriever.php`, `database/migrations/2026_05_12_000008_create_ai_knowledge_maturity_tables.php`
- **Issue:** `soc_knowledge_base` has no `tenant_id`; search and retrieval scan globally even for incident-linked notes.
- **Fix:** Define global vs tenant-scoped knowledge policy and enforce it in create/search/retrieve paths.
- **Task:** Proposed backlog item.

---

## Review Batch 13 - RESPONSE-SOAR-AUDIT-1 (2026-06-26)

### Finding RESP-1 - Legacy agent command paths bypass the new endpoint response approval framework
- **Severity:** High (Response Control Boundary Bypass)
- **Files:** `app/Http/Controllers/SocAgentController.php`, `app/Http/Controllers/SocResponseController.php`, `app/Http/Controllers/AgentIngestionController.php`, `app/Models/EndpointResponseCommand.php`, `app/Services/EndpointResponseCommandService.php`, `services/endpoint-agent/agent.py`
- **Issue:** Legacy controllers still write directly to `agent_commands` with dash-style command types (`collect-now`, `rotate-agent-secret`, etc.), bypassing `EndpointResponseCommandService`, the new safe allowlist, approval event model, and `CMD-YYYY-NNNNN` command convention. The current endpoint agent only supports the new underscore command types.
- **Fix:** Deprecate direct `agent_commands` writes, route endpoint command creation through `EndpointResponseCommandService`, map only equivalent legacy commands, and reject unsupported legacy types.
- **Task:** Proposed backlog item.

### Finding AGENT-API-1 - Endpoint command poll/ack/result API does not enforce agent signatures
- **Severity:** High (Agent API Authentication Failure)
- **Files:** `routes/web.php`, `app/Http/Controllers/Api/EndpointAgentApiController.php`, `app/Services/EndpointResponseCommandService.php`, `tests/Feature/EndpointResponseCommandTest.php`, `services/endpoint-agent/agent.py`
- **Issue:** `/api/agents/{agentId}/commands` returns dispatched commands without signature validation. Ack/result endpoints log invalid signatures but still mutate command state to acknowledged/completed/failed.
- **Fix:** Require valid per-agent authentication before command disclosure or state mutation; return 401/403 on invalid signatures and add denial tests.
- **Task:** Proposed backlog item.

### Finding RESP-2 - Active response execute permission semantics need clarification
- **Severity:** Low (Design Clarity / Authorization Semantics)
- **Files:** `routes/web.php`, `app/Services/ActiveResponseExecutionService.php`, `app/Http/Controllers/Response/ActiveResponseController.php`, `tests/Feature/ActiveResponseExecutionTest.php`
- **Issue:** Active response remains operator-recorded and does not autonomously mutate infrastructure, but final execute/rollback mutation routes are under `soc:response.create`, not a dedicated execute permission.
- **Fix:** Document that `response.create` includes manual execution confirmation, or introduce a dedicated execute permission and add route tests.
- **Task:** Not backlog until product authorization semantics are decided.

---

## Review Batch 14 - INTERNAL-AUTH-EDGE-AUDIT-1 (2026-06-26)

### Finding INT-AUTH-1 - Production compose overlay does not reset pipeline service ports 8092-8096
- **Severity:** High (Deployment Boundary / Exposure Drift)
- **Files:** `docker-compose.yml`, `docker-compose.prod.yml`, `docs/guides/LIMITATIONS_AND_CLAIMS.md`, `scripts/xdr_production_profile_validate.py`
- **Issue:** Base compose publishes normalizer, correlation, AI/RAG, alert-writer, and incident-builder ports to the host. Production overlay claims pipeline services have no external exposure, but does not reset those inherited port mappings.
- **Fix:** Add `ports: !reset []` or localhost-only bindings for pipeline services in production overlay, and extend production validation to fail if 8092-8096 are publicly exposed.
- **Task:** Proposed backlog item.

### Finding INT-AUTH-2 - Alert writer and incident builder expose DLQ contents without internal auth
- **Severity:** Medium (Internal Data Disclosure Risk)
- **Files:** `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`, `docker-compose.yml`, `docker-compose.prod.yml`
- **Issue:** `/dlq` endpoints return recent in-memory failure payloads without `X-Internal-Service-Token`. These payloads can include alert/incident evidence, trace IDs, actor identifiers, tenant context, and operational errors.
- **Fix:** Protect `/dlq` with the same internal auth as write/process/build routes or remove it from production; redact payloads before returning them.
- **Task:** Proposed backlog item.

### Finding INT-AUTH-3 - Internal auth scheme is split between Laravel HMAC tokens and static microservice tokens
- **Severity:** Low (Design Drift / Operational Clarity)
- **Files:** `app/Services/InternalAuthService.php`, `app/Http/Controllers/Security/SecurityHardeningController.php`, `services/*/main.*`, `.env.example`
- **Issue:** Laravel `/api/internal/*` uses time-bounded HMAC tokens, while pipeline microservices compare static per-service tokens. This is documented in code but easy to misread as one shared internal auth mechanism.
- **Fix:** Document the two schemes explicitly, or standardize microservices on the same time-bounded HMAC token format.
- **Task:** Not backlog unless auth scheme standardization is desired.

---

## Review Batch 15 - TEST-SUITE-RELEVANCE-AUDIT-1 (2026-06-26)

### Scope Analyzed
- **Areas:** PHP unit/feature tests, shared PHP test traits, Python endpoint-agent tests, Python XDR bootstrap/validation tests, documentation/evidence freeze tests.
- **Representative files:** `tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`, `tests/Traits/AssertsAdvisoryOnlyConstraints.php`, `tests/Feature/AdvancedDetectionCoverageTest.php`, `tests/Feature/DetectionEngineeringLifecycleTest.php`, `tests/Feature/EndpointLowLevelTelemetryTest.php`, `tests/Feature/DocumentationFreezeTest.php`, `tests/xdr_topic_bootstrap/test_internal_auth_coverage.py`, `tests/xdr_topic_bootstrap/test_xdr_pilot_readiness_matrix.py`, `tests/xdr_topic_bootstrap/test_xdr_pilot_live_validate.py`.

### Finding TEST-1 - Default scaffold tests add no SOC coverage
- **Severity:** Low (Unnecessary Test)
- **Files:** `tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`
- **Issue:** One test only asserts `true`; the other only checks `/` returns HTTP 200. These are framework scaffold tests and do not validate SOC behavior or security controls.
- **Fix:** Remove the unit scaffold test. Replace or remove the root-route smoke depending on whether `/` is a supported product surface.
- **Task:** Proposed backlog cleanup item.

### Finding TEST-2 - Python bytecode artifact is tracked under tests
- **Severity:** Low (Irrelevant Artifact)
- **Files:** `tests/endpoint_agent/__pycache__/test_agent.cpython-314.pyc`, `.gitignore`
- **Issue:** A `.pyc` file is tracked in the test tree even though `.gitignore` already ignores `__pycache__/`.
- **Fix:** Remove the tracked bytecode artifact from git; keep the existing ignore rule.
- **Task:** Proposed backlog cleanup item.

### Finding TEST-3 - Advisory-only invariant tests are duplicated across many feature tests
- **Severity:** Medium (Duplicate Coverage / Maintainability)
- **Files:** `tests/Traits/AssertsAdvisoryOnlyConstraints.php`, `tests/Feature/AdvancedDetectionCoverageTest.php`, `tests/Feature/DetectionEngineeringLifecycleTest.php`, `tests/Feature/EndpointLowLevelTelemetryTest.php`
- **Issue:** Common forbidden-method and advisory-only checks are repeated broadly. `method_exists(` appears 228 times in `tests/Feature`, and advisory-only constant tests appear in at least 28 feature tests.
- **Fix:** Keep the safety invariant coverage but consolidate common checks into a shared architecture guard, trait, or data provider. Preserve domain-specific forbidden method checks.
- **Task:** Proposed backlog cleanup item.

### Finding TEST-4 - Documentation/evidence-freeze tests are useful but not normal unit tests
- **Severity:** Low (Test Taxonomy)
- **Files:** `tests/Feature/DocumentationFreezeTest.php`, `tests/xdr_topic_bootstrap/test_xdr_pilot_live_validate.py`
- **Issue:** These tests assert doc file existence, release counts (`3077`, `164`, `133`), task IDs, commit lineage, and freeze metadata. They are useful release/evidence gates but brittle as everyday unit/feature tests.
- **Fix:** Classify or move them into a documentation/evidence validation suite; prefer manifest-based checks for hardcoded release metadata.
- **Task:** Not backlog until suite taxonomy cleanup is planned.

### Finding TEST-5 - Internal auth microservice tests duplicate the same matrix
- **Severity:** Low (Duplicate Test Structure)
- **Files:** `tests/xdr_topic_bootstrap/test_internal_auth_coverage.py`
- **Issue:** Alert-writer and incident-builder auth tests repeat nearly identical static token, startup secret, and enforcement mode checks.
- **Fix:** Parameterize the shared auth test matrix while preserving service-specific behavior checks.
- **Task:** Optional cleanup under the broader test consolidation task.

---

## Review Batch 16 — PIPELINE-TESTS-AND-TENANCY (2026-06-28)

### Finding TC-1 — Multi-Tenant Controller Authorization Bypass on Core SOC Modules
- **Severity:** Critical (Direct Isolation Failure)
- **Files:** `app/Http/Controllers/SocIncidentController.php`, `app/Http/Controllers/SecurityAlertController.php`, `app/Http/Controllers/SocDashboardController.php`, `app/Http/Controllers/SocApiController.php`
- **Issue:** Core SOC controllers retrieve/update incidents, alerts, dashboard statistics, and agent lists using raw `DB::table(...)` queries directly without resolving tenant context or checking permissions via `TenantContextAuthority` or `TenantBoundaryService` scopes. In strict mode, any tenant user can read or modify another tenant's data.
- **Fix:** Inject `TenantContextAuthority` and `TenantBoundaryService` into these controllers, apply query scoping filters on indexes, and assert tenant access on show/update/action operations.
- **Task:** Proposed backlog item.

### Finding PTS-1 — Incomplete fastapi/pydantic Mocking Causes Python Test Failures
- **Severity:** Medium (Test Infrastructure Failure)
- **Files:** `tests/alert_writer/test_alert_writer.py`, `tests/incident_builder/test_incident_builder.py`
- **Issue:** The dynamically stubbed `fastapi` module mock does not define core classes/functions (`Depends`, `Header`, `HTTPException`, `status`) imported by the main python script, causing `ImportError` on test discovery. This went undetected because global validation only runs `tests/endpoint_agent`.
- **Fix:** Expand the fastapi/pydantic dynamic stub mock inside the python test suites to define all required imports.
- **Task:** Proposed backlog item.

### Finding DB-5-DEFECT — Mismatch in tenant_id Serialization Field Location
- **Severity:** High (Direct Isolation Failure)
- **Files:** `services/alert-writer-service/main.py`
- **Issue:** The Go `correlation-worker` does not output `tenant_id` at the top level of its `Alert` JSON (it only places it inside the `evidence` map). The python `alert-writer-service` unmarshals the JSON directly into `AlertPayload(**row)`. Because Pydantic looks for `tenant_id` at the top level, it instantiates it as `None` (written as `NULL` in the DB).
- **Fix:** Update `normalize_records` in `services/alert-writer-service/main.py` to copy `evidence["tenant_id"]` to `row["tenant_id"]` prior to Pydantic instantiation.
- **Task:** Proposed backlog item.

### Finding IG-DOS — Ingestion Gateway sync.Map Denial of Service via fake Tenant-IDs
- **Severity:** High (Denial of Service)
- **Files:** `services/ingestion-gateway/main.go`
- **Issue:** The per-tenant rate limiter lazy-initializes a new `tenantBucket` for every unique `X-Tenant-ID` header value inside a global `sync.Map` (`tenantLimiters`), refilled in a background goroutine loop. An attacker flooding the gateway with arbitrary fake tenant IDs will cause unbounded memory leaks and CPU exhaustion.
- **Fix:** Implement an eviction/cleanup loop (e.g. using an LRU cache) to evict tenant buckets that remain inactive for a specified timeout.
- **Task:** Proposed backlog item.

---

## Review Batch 17 — CRYPTO-TENANCY-AND-RATE-LIMITS (2026-06-29)

### Finding CMD-SHARED-HMAC — Shared Cryptographic Secret for Privileged EDR Command Poll/Ack/Result
- **Severity:** Critical (Authentication / Cryptographic Weakness)
- **Files:** `app/Services/EndpointResponseCommandService.php`
- **Issue:** Command poll, ack, and result validation uses the static global `agent_enrollment_token` instead of the unique decrypted agent-specific `agent_secret` generated during enrollment. Since enrollment tokens are static and shared on all endpoints, any compromised host can forge response status changes or results for any other endpoint.
- **Fix:** Update `verifyAgentSignature` in `EndpointResponseCommandService` to query `endpoint_agents`, decrypt the unique `agent_secret`, and verify the signature against it.
- **Task:** Proposed backlog item.

### Finding AGENT-TENANCY-GAP — Complete Absence of Tenancy Isolation for Endpoint Fleet
- **Severity:** High (Direct Tenant Isolation Failure)
- **Files:** `database/migrations/*endpoint_agents*.php`, `app/Http/Controllers/AgentIngestionController.php`, `app/Http/Controllers/Endpoint/EndpointController.php`
- **Issue:** The `endpoint_agents` table does not contain a `tenant_id` column. Enrollment is global and does not associate agents with tenants. The endpoint fleet UI (`EndpointController`) indexes all agents and counts alerts globally, exposing hosts and alerts across tenants.
- **Fix:** Add a nullable `tenant_id` to `endpoint_agents`, require tenant context on enrollment, and inject `TenantContextAuthority` and query scoping in `EndpointController`.
- **Task:** Proposed backlog item.

### Finding TENANT-UNSCOPED-TABLES — Undocumented Isolation Gaps on Core Analysis Tables
- **Severity:** High (Tenancy Configuration Gap)
- **Files:** `app/Services/TenantBoundaryService.php`, DB migrations
- **Issue:** Almost 80% of analyst metadata tables (`investigations`, `response_plans`, `threat_hunts`, `entity_graph`, `soar_orchestrations`, AI/RAG summaries/events, and `notification_delivery_logs`) lack `tenant_id` columns and are omitted from `TenantBoundaryService` configuration. This allows cross-tenant visibility/hijack of active plans, investigations, and logs.
- **Fix:** Add `tenant_id` columns to all operational analyst tables, map them in `TenantBoundaryService::ISOLATED_TABLES`, and enforce scoping in controllers.
- **Task:** Proposed backlog item.

### Finding ENV-CACHE-DRIFT — Silent Internal Auth Fallback via Direct env() Call in config:cache Mode
- **Severity:** Medium (Configuration Drift)
- **Files:** `app/Services/InternalAuthService.php`
- **Issue:** Resolving the service token validation secret relies on a direct `env('XDR_INTERNAL_AUTH_SECRET')` call. When configuration is cached in production (`php artisan config:cache`), direct `env()` calls return `null`, causing the service to silently fall back to `APP_KEY`, which causes token verification to fail across Go/Python services.
- **Fix:** Map the env secret to a configuration key in `config/soc.php` and read it using `config()`.
- **Task:** Proposed backlog item.

### Finding RATE-LIMIT-BYPASS — Rate Limiting Bypass via Unauthenticated X-Tenant-ID HTTP Header
- **Severity:** Medium (Rate Limiting Weakness)
- **Files:** `services/ingestion-gateway/main.go`
- **Issue:** The ingestion gateway rate limits incoming telemetry using the `X-Tenant-ID` HTTP header, which is not verified against the actual `tenant_id` within the signed JSON payload body. A tenant can bypass throttling by sending randomized `X-Tenant-ID` headers.
- **Fix:** Validate that the request header `X-Tenant-ID` matches the `tenant_id` field inside the verified JSON body.
- **Task:** Proposed backlog item.

### Finding NOTIFY-TENANCY-GAP — Global / Unisolated SOC Notification Delivery
- **Severity:** High (Direct Tenant Isolation Failure)
- **Files:** `app/Console/Commands/SocSlaEscalationCommand.php`, `app/Services/SocNotifier.php`, DB migrations
- **Issue:** SLA breaches and incident summaries are delivered to global Slack, Discord, and webhook endpoints defined in system settings, with no tenant lookup, and all dispatch results are logged to a shared `notification_delivery_logs` table. In multi-tenant environments, this leaks sensitive incident metadata across client boundaries.
- **Fix:** Map webhooks to tenant-specific database entries and scope log dispatches correctly.
- **Task:** Proposed backlog item.

### Finding AI-KB-SEMANTIC — Missing Qdrant Vector Semantic Search Pipeline for grounding RAG
- **Severity:** High (Grounding / AI Architecture Gap)
- **Files:** `app/Support/SocKnowledgeRetriever.php`, Qdrant connection config
- **Issue:** The AI/RAG system performs simple keyword/tag matching on PostgreSQL text fields instead of utilizing semantic vector search against the Qdrant vector database. The `soc_knowledge_embeddings` table holds local embeddings placeholders, but the retrieval path does not generate actual sentence-transformer vector embeddings or execute cosine-similarity searches.
- **Fix:** Integrate an embedding generator (e.g. via HuggingFace or a remote LLM embedding API) and configure `SocKnowledgeRetriever` to store and query vectors from Qdrant.
- **Task:** Proposed backlog item.

### Finding AI-KB-FEED-INGEST — Missing Bulk Threat-Intel feed Ingestion command
- **Severity:** Medium (Content Scarcity / Data Gap)
- **Files:** `app/Services/AiKnowledgeSeedService.php`, `app/Console/Commands/AiSeedKnowledgeCommand.php`
- **Issue:** The AI Knowledge Base is populated with only 10 mock/hardcoded seed fixtures. It lacks structured parsers to dynamically ingest real-world threat intel data such as MITRE ATT&CK mitigation files, Sigma rule descriptions, or active RSS security feeds.
- **Fix:** Create a CLI command (`php artisan ai:ingest-threat-intel`) that parses MITRE and Sigma schema JSON feeds directly into `soc_knowledge_base`.
- **Task:** Proposed backlog item.

### Finding AI-KB-FEEDBACK-LOOP — Missing Closed-Loop Analyst Feedback Ingestion
- **Severity:** Low (Feedback loop Gap)
- **Files:** `app/Http/Controllers/SocAiController.php`, `app/Support/AiAnalystManager.php`
- **Issue:** The platform logs analyst approvals and ratings of LLM suggestions (`ai_analyst_suggestions.reviewed_by/status`), but it lacks a service to automatically package accepted incident analyses and summaries back into the knowledge base to improve future grounding.
- **Fix:** Create an observer or service that converts approved suggestion summaries into new `soc_knowledge_base` entries marked with relevant tags.
- **Task:** Proposed backlog item.

### Finding AI-CONF-BANDS — Missing confidence-based automated containment rules
- **Severity:** High (AI Governance / Risk Mitigation)
- **Files:** `app/Support/AiAnalystManager.php`, `app/Services/EndpointResponseCommandService.php`
- **Issue:** The active response workflow is recommendation-only. To transition to automated host containment safely without causing automated self-denial of service, the system lacks a confidence thresholding gate and a critical asset exclusion list.
- **Fix:** Implement automated containment rules for high-confidence AI findings (FPR=0%, Confidence>=95%) and enforce a blacklist blocking automatic isolation on critical servers (e.g. database and AD servers).
- **Task:** Proposed backlog item.

### Finding TENANT-ENFORCE-RLS — Advisory RLS and Tenant Strict Mode Enforcement Gap
- **Severity:** High (Isolation Hardening Gap)
- **Files:** `database/migrations/*`, `app/Services/TenantBoundaryService.php`
- **Issue:** PostgreSQL Row Level Security (RLS) policies are scaffolded but not actively enforced, and tenant strict mode defaults to false.
- **Fix:** Enable `FORCE ROW LEVEL SECURITY` on all isolated tables and set `XDR_STRICT_TENANCY=true` by default, after verifying all null data is backfilled.
- **Task:** Proposed backlog item.

### Finding PERF-AGENT-UPDATE — N+1 UPDATE queries in agent command retrieval loop
- **Severity:** Medium (Database Performance)
- **Files:** `app/Http/Controllers/AgentIngestionController.php`
- **Issue:** When an agent polls for commands, the controller updates each sent command status individually in a loop (`DB::table('agent_commands')->where('id', $command->id)->update(...)`), executing N separate queries.
- **Fix:** Perform a single bulk query: `DB::table('agent_commands')->whereIn('id', $ids)->update(...)`.
- **Task:** Proposed backlog item.

### Finding PERF-IOC-LOOP — High-complexity nested loops with synchronous inserts in threat intelligence correlation
- **Severity:** High (Performance Bottleneck / Thread Freezing)
- **Files:** `app/Http/Controllers/SocThreatIntelController.php`
- **Issue:** The IOC match correlation iterates through 2000 alerts and all enabled IOCs in a nested loop. In case of matching, it performs synchronous `DB::table('ioc_hits')->insertOrIgnore(...)` calls inside the loop. This blocks execution threads when there are many alerts and threat indicators.
- **Fix:** Accumulate matching records into an array and perform a single bulk insert outside the loop.
- **Task:** Proposed backlog item.

### Finding PERF-ALERT-TUNE — Multiple SQL writes inside alert suppression loops
- **Severity:** High (Performance Bottleneck)
- **Files:** `app/Http/Controllers/SocTuningController.php`
- **Issue:** When applying active suppression rules, for every rule matching up to 500 alerts, it runs an update query (marking alert as suppressed) and a history insert query for each individual alert. This executes up to 1000 database operations sequentially per rule.
- **Fix:** Refactor the loop to use bulk updates (`whereIn`) and bulk inserts for history logs.
- **Task:** Proposed backlog item.

### Finding GIT-RM-PYC — Tracked Compiled Python bytecode files in Git index
- **Severity:** Low (Repository Hygiene / Best Practices)
- **Files:** Git index / `.pyc` files
- **Issue:** More than 100 compiled Python bytecode files (`*.pyc` under `__pycache__` and `dist/`) are tracked in the Git repository index, causing database file size bloat and constant merge conflicts.
- **Fix:** Run `git rm --cached` on all `*.pyc` files to untrack them while keeping the `.gitignore` rules.
- **Task:** Proposed backlog item.

### Finding PERF-SUBPROCESS-POLL — Subprocess Polling Spikes in ClickHouse Sync Daemon
- **Severity:** Medium (CPU Spikes / Server Load)
- **Files:** `scripts/clickhouse_sync_daemon.py`, `scripts/sync_postgres_to_clickhouse.py`
- **Issue:** The ClickHouse sync daemon runs a subprocess every 2 seconds to execute `sync_postgres_to_clickhouse.py`. This spawns a new Python interpreter, parses `.env` environment configuration files, registers table schemas, and opens/closes PostgreSQL and ClickHouse connections on every loop.
- **Fix:** Refactor the sync daemon to keep connections open and run in-process, querying and posting HTTP data sequentially in a persistent loop.
- **Task:** Proposed backlog item.

### Finding PERF-AGENT-HEALTH-N1 — N+1 SQL Queries inside Agent Health Check loop
- **Severity:** Medium (Database Load)
- **Files:** `app/Console/Commands/AgentHealthCheckCommand.php`
- **Issue:** The agent health check command iterates through all endpoint agents and queries `agent_policies` individually. Additionally, for each agent delivery failure, it queries `endpoint_agents` individually, creating an N+1 query pattern.
- **Fix:** Refactor the database query to use a left join on policies and eager load/map failure records.
- **Task:** Proposed backlog item.

### Finding PERF-GO-LIMITER — Loop-based Channel Refill in Ingestion Gateway Rate Limiter
- **Severity:** High (CPU Spikes / Concurrency Overhead)
- **Files:** `services/ingestion-gateway/main.go`
- **Issue:** The ingestion gateway rate limiters (both global and per-tenant) use channel-based token buckets refilled by background goroutines running loops (`for len(tokens) < cap(tokens) { tokens <- struct{}{} }`). During traffic spikes, this results in high CPU overhead from thousands of lock/channel operations per second.
- **Fix:** Refactor the rate limiters to use a mathematical token bucket calculation based on elapsed time (`time.Since(lastRefill)`), avoiding loop-based channels and background refill goroutines.
- **Task:** Proposed backlog item.

### Finding PERF-PYTHON-HTTP — Recreating HTTP connections for sequential OpenSearch/Redpanda writes in Python services
- **Severity:** Medium (Network latency / Port Exhaustion)
- **Files:** `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`
- **Issue:** The alert writer service executes `requests.put()` inside loops to write alerts individually to OpenSearch. The incident builder service executes `requests.post()` to publish batches to Redpanda. Because they do not use `requests.Session()`, they recreate a raw TCP/TLS socket for every single request, causing high latency and port exhaustion.
- **Fix:** Refactor HTTP publishers to use `requests.Session()` to enable HTTP Keep-Alive connection pooling, and use OpenSearch bulk APIs where applicable.
- **Task:** Proposed backlog item.

### Finding PERF-TRANSACTION-GAP — Missing database transactions in sequential Laravel write operations
- **Severity:** Medium (Data Integrity Risk)
- **Files:** `app/Http/Controllers/SocIncidentController.php`, `app/Console/Commands/SocSlaEscalationCommand.php`, `app/Http/Controllers/SocResponseController.php`
- **Issue:** Multiple sequential write operations (such as updating an incident status and inserting incident notes or logging activity) are executed directly without transaction encapsulation. If a write fails midway, the database is left in a partially-updated state.
- **Fix:** Wrap multi-table write sequences in a `DB::transaction(...)` callback to guarantee atomicity.
- **Task:** Proposed backlog item.

### Finding AI-CONTEXT-EMPTY — Empty AI LLM Context Grounding due to overcompaction
- **Severity:** High (AI Quality / Grounding Fail)
- **Files:** `app/Support/AiAnalystManager.php`
- **Issue:** The `compactContext` method helper strips away the actual payload fields of alerts, telemetry logs, and retrieved RAG markdown documentation. It passes only count metrics (e.g. `alert_count`, `retrieval_citation_count`) to the LLM. This forces the LLM to write summaries and recommendations completely blind, causing heavy hallucinations.
- **Fix:** Inject relevant details (alert types, timestamps, descriptions, matched IOC values, and retrieved markdown text snippet citations) in the prompt template payload.
- **Task:** Proposed backlog item.

### Finding RATE-LIMIT-DOS — Ingestion Gateway Dynamic Rate Limiter Memory Flooding
- **Severity:** High (Availability / DoS Vulnerability)
- **Files:** `services/ingestion-gateway/main.go`
- **Issue:** The gateway dynamically instantiates a new `tenantBucket` structure and channel for any incoming request header `X-Tenant-ID` that it hasn't seen before. An attacker can flood the ingestion gateway with randomized headers to consume all system memory, causing an Out-Of-Memory (OOM) crash.
- **Fix:** Validate incoming `X-Tenant-ID` strings against an in-memory cache of verified database tenants before allocating rate limiters, or set a max capacity limit on the limiter map.
- **Task:** Proposed backlog item.

### Finding PERF-DB-CONN-LEAK — Recreating PostgreSQL connections for every batch in Python workers
- **Severity:** High (Database Load / Latency Overhead)
- **Files:** `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`
- **Issue:** The Python-based workers establish a new database connection via `psycopg.connect()` on-demand for every batch of writes, rather than utilizing a connection pool. This introduces high latency and risks exhausting PostgreSQL's `max_connections` limit under load.
- **Fix:** Refactor the workers to use a persistent connection or a psycopg connection pool.
- **Task:** Proposed backlog item.

### Finding PERF-REST-POLL — High Overhead HTTP REST Proxy Polling in Go Workers
- **Severity:** Medium (Performance Overhead)
- **Files:** `services/normalizer-worker/main.go`, `services/correlation-worker/main.go`
- **Issue:** Go workers interact with Redpanda via Pandaproxy's HTTP REST endpoints. Polling Kafka via REST Proxy introduces huge HTTP serialization overhead and risks duplicate processing if the REST proxy restarts or group sessions time out.
- **Fix:** Refactor Go workers to use the native binary Kafka protocol via `confluent-kafka-go` or `kafka-go`.
- **Task:** Proposed backlog item.

### Finding PERF-GO-OVERCONCURRENT — Loop-level Goroutine/Channel Allocation in Go Workers
- **Severity:** Medium (GC Churn / CPU Overhead)
- **Files:** `services/normalizer-worker/main.go`, `services/correlation-worker/main.go`
- **Issue:** For every incoming batch, Go workers dynamically allocate channels (`jobs` and `results`) and spawn `NumCPU` goroutines to process records. For small telemetry batches, the overhead of channel synchronization, locks, allocations, and context switching exceeds the processing time.
- **Fix:** Refactor to use a persistent worker pool created once during boot, or process small batches sequentially.
- **Task:** Proposed backlog item.

### Finding PERF-GO-HOT-HTTP — Synchronous HTTP API Lookup inside Ingestion Loops
- **Severity:** High (Latency Spill / Pipeline Blocking)
- **Files:** `services/correlation-worker/main.go`
- **Issue:** The correlation worker executes synchronous, single-threaded HTTP GET requests (`lookupIOC`) inside hot loops for every IP, domain, and hash found in each event. A batch of thousands of events can result in thousands of blocking network calls, completely stalling the ingestion pipeline.
- **Fix:** Implement a thread-safe in-memory cache for IOC lookup results, query lookups in bulk, or load rule definitions locally at startup.
- **Task:** Proposed backlog item.

### Finding PERF-REST-REBALANCE — Frequent Consumer Group Rebalance Storms in Go Workers
- **Severity:** High (Ingestion Stalls / Message Duplication)
- **Files:** `services/normalizer-worker/main.go`, `services/correlation-worker/main.go`
- **Issue:** Go workers register consumer instances with Pandaproxy using a unique ID appended with `time.Now().UnixNano()`. Upon network drops or worker restarts, the worker creates a new instance, but the old instance remains registered in the group until its session timeout expires. This causes continuous group rebalance loops, blocking telemetry consumption.
- **Fix:** Use a static consumer instance ID (e.g., based on hostname or container UUID) so that the REST Proxy replaces the old session immediately upon reconnection.
- **Task:** Proposed backlog item.

### Finding PERF-IOC-STR-LOWER — Redundant String lowercasing in nested IOC matching loop
- **Severity:** Medium (CPU Overhead / Inefficient Loops)
- **Files:** `app/Http/Controllers/SocThreatIntelController.php`
- **Issue:** The `matchIocs` method compares a list of recent alerts against all active threat intelligence IOCs using a nested loop. Inside the inner loop (which runs millions of times), the code calls `strtolower($ioc->ioc_value)` on every iteration, leading to massive redundant CPU cycles and string allocations.
- **Fix:** Pre-lowercase all IOC values once outside the nested loop prior to matching.
- **Task:** Proposed backlog item.

### Finding ARCH-KAFKA-NATIVE — Redpanda HTTP REST Proxy (Pandaproxy) Intermediate Overhead
- **Severity:** High (Architectural Overhead / High Latency)
- **Files:** Ingestion gateway, normalizer-worker, correlation-worker
- **Issue:** Telemetry streaming pipelines communicate with Redpanda using HTTP REST proxy requests. This introduces serialization/deserialization layers, lacks compression benefits, and increases CPU footprint under load.
- **Fix:** Use native binary TCP Kafka protocol (`franz-go` or `sarama`) directly to broker port 9092.
- **Task:** Proposed backlog item.

### Finding ARCH-DB-SPLIT — Monolithic Relational Database on the High-Throughput Write Path
- **Severity:** High (OLTP Database Lock / Write Bottleneck)
- **Files:** PostgreSQL / ClickHouse
- **Issue:** The alert writer service writes all alert metrics directly to PostgreSQL, which is an OLTP database optimized for low-latency transactions. High-volume streaming telemetries degrade dashboard transactions.
- **Fix:** Route telemetry and alert logs directly to ClickHouse (OLAP database) and reserve PostgreSQL for relational cases.
- **Task:** Proposed backlog item.

### Finding ARCH-MTLS-SEC — Weak Shared Static Token Internal Authentication
- **Severity:** High (Security Risk / Spoofing Vector)
- **Files:** Services authentication configs
- **Issue:** Container-to-container calls are authenticated using static tokens (`X-Internal-Service-Token`), making the setup vulnerable to compromised containers.
- **Fix:** Enforce Mutual TLS (mTLS) for all service-to-service communication.
- **Task:** Proposed backlog item.















---

## Review Batch 18 — CLAUDE-QC-PIPELINE-AUDIT (2026-07-05)

Code-level QA/QC + architecture audit by Claude (agent:claude). Scope: `services/ingestion-gateway/main.go`,
`services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`,
`services/ai-rag-service/main.py`, service Dockerfiles/go.mod, `docker-compose.prod.yml`,
Laravel raw-SQL spot check (no injection found — all parameterized). Duplicates against
REVIEW_ALL/REJECTED/COMPLETED were excluded before recording.

### Finding PIPE-CONSUMER-AUTH-500 — Consumer event loop breaks when internal service token is configured
- **Severity:** Critical (Pipeline Outage in production-enforced posture)
- **Files:** `services/alert-writer-service/main.py` (`process_alerts()` → `write()`), `services/incident-builder-service/main.py` (`process_alerts()` → `build()`), `docker-compose.prod.yml`
- **Issue:** The Kafka consumer path calls the FastAPI **route functions directly** (`write(WriteRequest(...))` / `build(BuildRequest(...))`) without passing `x_internal_service_token`. Outside FastAPI's DI, the parameter default is the raw `Header(default=None)` object — `verify_internal_token()` then either raises `AttributeError` (`.encode` on Header) or fails `compare_digest` → `HTTPException 401`. Either way, **every consumed batch throws**, the loop counts consecutive errors, and the consumer enters an endless delete/recreate cycle: no alert is ever written. Trigger condition: `XDR_ALERT_WRITER_INTERNAL_TOKEN` / `XDR_INCIDENT_BUILDER_INTERNAL_TOKEN` set (even in permissive mode), which is exactly what `docker-compose.prod.yml` requires (`XDR_ENFORCE_INTERNAL_AUTH: "true"` + event loop enabled). Existing event-loop tests pass because they run without tokens and against the fastapi stub.
- **Fix:** Extract core functions (`_write_alerts_core()` / `_build_incidents_core()`) containing the business logic; HTTP handlers wrap them with `verify_internal_token`; `process_alerts()` calls the core directly (in-process calls are already inside the trust boundary). Add a regression test: event loop with token env set must still write.
- **Task:** Proposed backlog item.

### Finding AW-DEDUPE-BEFORE-COMMIT — Dedupe cache records fingerprints before the DB write succeeds
- **Severity:** High (Correctness / Silent alert loss on retry)
- **Files:** `services/alert-writer-service/main.py` (`write()` — `_seen_add(fp)` before `write_postgres`)
- **Issue:** `write()` inserts each alert fingerprint into the `SEEN` LRU **before** attempting `write_postgres()`. If the Postgres write fails (transient outage), the batch goes to DLQ as `postgres_write_failed` (classified **replayable**), but any replay of the same alerts within the same process lifetime is dropped as "duplicate" by the poisoned `SEEN` cache — the alerts are silently never written. The documented recovery path (`dlq:replay`) is defeated by the cache.
- **Fix:** Only add fingerprints to `SEEN` after a successful write (or remove the batch's fingerprints from `SEEN` in the failure handler). DB `ON CONFLICT` remains the idempotency source of truth, so late additions are safe.
- **Task:** Proposed backlog item.

### Finding IG-HMAC-REPLAY — Ingest HMAC signature has no timestamp/nonce → replayable requests
- **Severity:** Medium (Security — integrity only, no freshness)
- **Files:** `services/ingestion-gateway/main.go` (`verifySignature`), `services/endpoint-agent/agent.py` (signer side)
- **Issue:** `X-XDR-Signature` is HMAC-SHA256 over the raw body only. A captured signed batch can be replayed indefinitely by anyone on the network path — inflating telemetry, re-triggering detections, or skewing baselines/UEBA. There is no timestamp header in the signed material, no tolerance window, and no nonce cache.
- **Fix:** Sign `timestamp + "." + body` (e.g. `X-XDR-Timestamp` header), reject requests outside a +/-5 min window; keep a compat flag (`XDR_INGEST_SIGV2_REQUIRED=false` default) so existing agents/demo feeders migrate without breakage. Pipeline idempotency already dampens duplicates downstream, so severity is Medium, not High.
- **Task:** Proposed backlog item.

### Finding IG-HMAC-FAIL-OPEN — Empty ingest secret silently disables signature verification
- **Severity:** Medium (Security posture — fail-open auth)
- **Files:** `services/ingestion-gateway/main.go` (`verifySignature` returns nil when `secret == ""`; `validateStartupSecrets` only WARNs)
- **Issue:** If `XDR_INGEST_SECRET` is unset, HMAC verification is skipped entirely and all ingest is accepted — with only a startup log WARN. Internal-token auth already has a fail-fast pattern (`XDR_ENFORCE_INTERNAL_AUTH=true` → refuse to start without token), but the ingest edge — the **external** trust boundary — does not.
- **Fix:** When `XDR_ENFORCE_INTERNAL_AUTH=true` (production posture), the gateway must refuse to start with an empty or `dev-secret-change-me` ingest secret (mirror the Python services' `[SECURITY-FATAL]` + exit 1). Keep permissive behavior for the local/demo profile.
- **Task:** Proposed backlog item.

### Finding GO-BASEIMAGE-EOL — Go 1.22 toolchain and alpine:3.20 base images are end-of-life
- **Severity:** Medium (Tech currency / unpatched CVEs in build+runtime images)
- **Files:** `services/{ingestion-gateway,normalizer-worker,correlation-worker}/Dockerfile` (`golang:1.22-alpine`, `alpine:3.20`), all three `go.mod` (`go 1.22`)
- **Issue:** Go 1.22 left support in Feb 2025 (two majors old — no security patches for stdlib `net/http`, `crypto/*`). Alpine 3.20 reached EOL 2026-04-01. All three Go services build and run on unsupported images. Complements TECH-EOL-UPGRADE, which covers only PHP/Laravel.
- **Fix:** Bump `go.mod` to a current supported Go (>=1.24), builder image to matching `golang:*-alpine`, runtime to `alpine:3.22`+ (or distroless/static). Services are stdlib-only, so the upgrade is low-risk; verify with Go builds + live validator.
- **Task:** Proposed backlog item.

### Finding IB-DLQ-NOT-DURABLE — Incident-builder failures are recorded in memory only
- **Severity:** Medium (Reliability / failure evidence loss)
- **Files:** `services/incident-builder-service/main.py` (`DLQ` deque only), vs `services/alert-writer-service/main.py` (`write_alert_failure` → `xdr.alert_write_failed`)
- **Issue:** Alert-writer publishes structured failure records to the durable `xdr.alert_write_failed` topic; incident-builder records incident write/publish failures **only** in its bounded in-memory ring (max 1000, lost on restart). A restart after a failure storm erases all evidence, and no `dlq_records` entry is created for SOC review — asymmetric with the unified DLQ review workflow.
- **Fix:** Add `write_incident_failure()` publishing to `xdr.incident_write_failed` (same structured schema), register the topic in `xdr_topic_bootstrap.py`, and extend `normalize_pipeline_dlq_records()` replayability classification.
- **Task:** Proposed backlog item.

### Finding PY-POISON-RECORD-BATCH — One malformed record fails the whole poll batch in alert-writer/incident-builder
- **Severity:** Medium (Resilience — poison-message isolation gap)
- **Files:** `services/alert-writer-service/main.py` (`normalize_records` — `AlertPayload(**row)`), `services/incident-builder-service/main.py` (same pattern)
- **Issue:** `AlertPayload(**row)` raises pydantic `ValidationError` on a single malformed record; the exception aborts the **entire** poll batch inside `event_loop()`, counts toward consumer recreation, and since recreation uses a fresh group with `auto.offset.reset=earliest`, the poison record is re-consumed forever — an infinite error/recreate loop with continuous DLQ-topic writes. The normalizer already isolates poison messages inline (40801 → DLQ → commit → continue); the two Python consumers do not.
- **Fix:** Wrap per-record parsing in try/except: skip the bad record, write a structured DLQ entry (`alert_parse_error` with source coordinates), continue the batch. Mirrors the normalizer's proven pattern.
- **Task:** Proposed backlog item.

### Finding CONSUMER-GROUP-EPHEMERAL — Fresh consumer group per start/recovery replays entire topic history
- **Severity:** Medium (Scalability / replay amplification — deliberate design, needs a bound)
- **Files:** `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py` (`_new_ids()` ms-timestamp groups, `offset_reset=earliest`, no offset commit calls)
- **Issue:** Every service start — and every error-triggered consumer recreation — creates a brand-new consumer group reading from `earliest`. The full retained topic history is reprocessed on each restart. Idempotent upserts make this *safe*, but startup cost and duplicate-metric inflation grow linearly with retention; at enterprise volume (see SCALE-026 targets) a restart storm multiplies broker read load (related to the deferred PERF-REST-REBALANCE, but this is offset strategy, not rebalance latency).
- **Fix:** Use a stable group id + explicit offset commits after successful processing; recreate the group identity **only** on `offset_out_of_range` (the original recovery scenario). Alternative cheap bound: `auto.offset.reset=latest` for non-first groups.
- **Task:** Proposed backlog item (enterprise-relevant reliability — must not be Rejected per classification rules).

### Finding AIRAG-STUB-CITATIONS — /v1/retrieve returns fabricated fixed citations without a stub label
- **Severity:** Low (Groundedness / analyst-trust hygiene; adjacent to RAG-1/RAG-2, distinct surface)
- **Files:** `services/ai-rag-service/main.py` (`retrieve()`)
- **Issue:** `/v1/retrieve` always returns the same two hardcoded results (`kb:incident-response` score 0.82, `kb:xdr-correlation` 0.74) regardless of query — with no `provider`/`stub` marker, unlike `analyze()` which labels `"provider": "heuristic"`. A consumer cannot distinguish fabricated fixtures from real retrieval.
- **Fix:** Label the response (`"provider": "stub"`, `"grounded": false`) or return an empty result set until real Qdrant retrieval is wired in this service.
- **Task:** Proposed backlog item.

### Finding PY-PRINT-LOGGING — Python services log via print() instead of the logging module
- **Severity:** Low (Best practice / observability)
- **Files:** `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`, `services/ai-rag-service/main.py`
- **Issue:** All operational logging uses `print(..., flush=True)`: no levels, no timestamps, no logger names, not filterable, and inconsistent with uvicorn's own logging. Structured triage of `[SECURITY-WARN]`/`WARN:` strings requires text scraping.
- **Fix:** Adopt stdlib `logging` with a shared JSON-line formatter (service, level, ts, msg, topic/group fields); map current prefixes to levels. No behavior change; keep messages test-stable.
- **Task:** Proposed backlog item.

### Finding GO-GRACEFUL-SHUTDOWN — SIGTERM uses server.Close(), dropping in-flight ingest requests
- **Severity:** Low (Deploy-window data loss risk; log message is misleading)
- **Files:** `services/ingestion-gateway/main.go` (`server.Close()` on SIGTERM), `services/correlation-worker/main.go` (same)
- **Issue:** The shutdown handler logs "shutting down gracefully" but calls `server.Close()`, which immediately terminates active connections — an in-flight `/v1/ingest` batch that was already accepted-but-not-yet-published is dropped without a client-visible retry signal. `server.Shutdown(ctx)` is the stdlib graceful path.
- **Fix:** Replace with `server.Shutdown(ctx)` using a 10–15 s timeout, then close; drain the publish path before exit.
- **Task:** Proposed backlog item.

### Batch note — stale backlog entry
- **PY-CONTAINER-HARDENING** (REVIEW_BACKLOG.md): the Dockerfile portion is already implemented — all three Python service Dockerfiles now run as non-root `app` user **with** HEALTHCHECK. Remaining valid scope is only dependency pinning (`requirements.txt` uses unpinned `>=`, no lockfile). Entry annotated accordingly.

---

## Review Batch 19 — CLAUDE-QC-DEEP-DIVE (2026-07-06)

Deeper code-level QA/QC by Claude (agent:claude). Scope this pass: `services/normalizer-worker/main.go`
(offset/producer durability), `services/correlation-worker/main.go` (IOC cache + consume loop), the newly
merged `SIEM-SEARCH` (`app/Services/SiemSearchService.php`) and `ASSET-INVENTORY`
(`app/Services/AssetContextService.php`, migrations), and the shared `xdr_event_contracts.py` copies.
Batch 18 status noted: 10/11 findings already implemented (commits `15e25ef`..`2a7766f`); only
`CONSUMER-GROUP-EPHEMERAL` remains open. Findings below are NEW, verified non-duplicate.

### Finding ASSET-TENANT-OVERWRITE — CSV import can reassign another tenant's asset via globally-unique external_id
- **Severity:** High (Tenant Isolation Failure — append/overwrite across tenant boundary)
- **Files:** `app/Services/AssetContextService.php` (`registerAsset()` / `importCsv()`), `database/migrations/2026_07_07_010001_create_asset_inventory_table.php`
- **Issue:** `registerAsset()` upserts with `AssetInventory::updateOrCreate(['external_id' => $externalId], ['tenant_id' => $tenantId, ...])`. The migration declares `external_id` **globally unique** (`->unique()`), not unique-per-tenant. `importCsv()` lets the caller supply the `external_id` column. So a user with `assetinventory.manage` on tenant B who imports a CSV row whose `external_id` collides with tenant A's asset will **match tenant A's existing row and overwrite it — including flipping its `tenant_id` to B** (hostname, IP, owner, criticality FK all reattributed). This is a cross-tenant write that the tenant-scoped read queries (`where('tenant_id', …)`) cannot detect afterward. Manual `store()` is safe (external_id auto-generated), the CSV/`asset:import` path is not.
- **Fix:** Make the upsert key tenant-scoped: `updateOrCreate(['tenant_id' => $tenantId, 'external_id' => $externalId], …)` and change the migration to a composite unique `unique(['tenant_id','external_id'])`. Add a test: importing tenant B's CSV with tenant A's external_id must create a new B-scoped row, never mutate A's.
- **Task:** Proposed backlog item.

### Finding NORM-ASYNC-COMMIT-LOSS — Normalizer commits input offsets before the async producer has published
- **Severity:** Medium (Reliability — at-least-once gap / silent event loss on crash)
- **Files:** `services/normalizer-worker/main.go` (`consumeOnce()` → `enqueue()` → `producerLoop()`)
- **Issue:** The consume loop polls `telemetry.raw`, normalizes, and `enqueue()`s events into in-memory producer channels (`queueCapacity` default 200000) that are flushed asynchronously (100 ms ticker / 5000-event batch). The loop never explicitly commits offsets except in the poison path, so it relies on Pandaproxy's default fetch-time auto-commit — which advances the input offset **as records are polled**, i.e. *before* `producerLoop` has actually published them to `telemetry.normalized`. A crash/OOM/SIGKILL (or a persistent publish failure that only routes to the local DLQ) between poll-commit and flush drops every event still sitting in the producer queues — up to 200k events — with no replay, because the source offset already advanced. The service otherwise markets replay-safety.
- **Fix:** Either (a) publish synchronously before the offset can advance, or (b) disable fetch-time auto-commit (`auto.commit.enable=false` on consumer create) and explicitly commit the input offset only after `producerLoop` confirms the batch was published — mirroring the deliberate commit-after-write already used in `isolatePoisonRecord()`. Bound the in-flight window either way.
- **Task:** Proposed backlog item (enterprise reliability — Deferred-class, not Rejected).

### Finding SIEM-QUERYSTRING-DOS — Free-form OpenSearch query_string allows leading wildcard / regex expensive queries
- **Severity:** Medium (Availability — analyst-triggered OpenSearch DoS)
- **Files:** `app/Services/SiemSearchService.php` (`searchOpenSearch()`)
- **Issue:** The search passes raw analyst input straight into an OpenSearch `query_string` clause with no `allow_leading_wildcard=false`, no `analyze_wildcard` guard, and no server-side query timeout in the request body. `query_string` accepts full Lucene syntax, so a query like `*abc`, `/.*expensive.*/`, or deeply fuzzy terms forces a full-index scan and can pin the OpenSearch node — an authenticated analyst (or a compromised low-priv `search.view` account) can degrade the shared cluster for all tenants. The tenant `term` filter bounds *data exposure* correctly, but not *cost*. (The Postgres fallback path is safe — it escapes `%_\\` and uses bound params.)
- **Fix:** Set `allow_leading_wildcard: false`, add a bounded `timeout` in the search body (e.g. `"timeout": "3s"`), consider `simple_query_string` (no regex/leading-wildcard grammar) instead of `query_string`, and cap query length. Keep the AND default_operator.
- **Task:** Proposed backlog item.

### Finding SIEM-ASSET-CONTEXT-UNUSED — ASSET-INVENTORY criticality is defined but never actually enriches/ranks alerts
- **Severity:** Low (Dead capability / spec-vs-code drift — the stated purpose is unimplemented)
- **Files:** `app/Services/AssetContextService.php` (`assetContextForIps()`, `criticalityForAsset()`), `app/Http/Controllers/SocIncidentController.php`
- **Issue:** The module's docblocks state criticality is "used to rank the analyst queue." `assetContextForIps()` is wired into the incident **detail** view (read-only display), but no code path uses `criticality_tier` to actually order/prioritise the analyst queue or alert list — the ranking claim is aspirational. Not a safety issue (advisory-only boundary is respected), but it is capability drift a reviewer/defender could mistake for a working feature.
- **Fix:** Either implement queue ordering by `criticality_tier` (advisory sort key only, never a response gate) or soften the docblocks to "displayed as incident context" so code and claim match.
- **Task:** Proposed backlog item (Low).

### Positive verifications this pass (no action needed)
- **Batch 18 remediation confirmed in code**, not just docs: `_write_alerts_core()`/`_build_incidents_core()` now separate business logic from the auth'd HTTP handler (PIPE-CONSUMER-AUTH-500); `_seen_add(fp)` deferred until after successful write (AW-DEDUPE-BEFORE-COMMIT); normalizer-style per-record poison isolation added; correlation-worker uses `server.Shutdown(ctx)` with a 15 s drain (GO-GRACEFUL-SHUTDOWN); ingest signer now supports timestamped HMAC (IG-HMAC-REPLAY).
- **`xdr_event_contracts.py` is byte-identical across all three Python services** (SHA-256 match) — no contract drift between alert-writer / incident-builder / ai-rag.
- **Laravel raw SQL remains injection-safe**: every `whereRaw`/`orWhereRaw` reviewed (SiemSearch, SocForensic, SocHunt, SocEndpointTimeline, SocKnowledgeBase) uses bound `?` params; `DB::raw` uses are constant expressions (`version + 1`, counters), not interpolated input.
- **IOC cache (correlation-worker) is correctly bounded** (TTL + max, expired-first eviction, never caches transient failures) — no unbounded-growth regression.

---

## Review Batch 20 — ENTERPRISE-INTERNATIONAL-BAR (2026-07-06)

**Posture change (authoritative):** the product target is stated by the owner to be an
**enterprise / international** platform — NOT an academic thesis demo. This review re-graded the
system against that bar (SOC 2 / ISO 27001 / GDPR access + data protection, real HA/DR, enforced
multi-tenancy, supply-chain/SDLC, standards-based observability). Consequence: **every finding
previously classified as Accepted-Risk / Rejected / Deferred *because of* "academic/local/demo
posture" is now invalid and must be re-opened** — the justification no longer holds. The
academic-framing docs are themselves a defect (see META-DOC-MISREPRESENT).

### Finding META-DOC-MISREPRESENT — Docs frame the product as academic/demo, contradicting the enterprise/international target
- **Severity:** High (Governance / truth-in-claims — a reviewer would read the code posture as demo-grade)
- **Files:** `CLAUDE.md` ("Academic scope is stable and defensible", "Focus is on demo stability, thesis defensibility"), `docs/thesis/*`, `docs/guides/LIMITATIONS_AND_CLAIMS.md`, `docker-compose.prod.yml` header ("Does NOT claim full HA, multi-region, or commercial readiness"), `docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md`
- **Issue:** The canonical operating docs and the production overlay explicitly scope the system to academic/demo/pilot and disclaim commercial readiness. If the real target is an international enterprise product, these are not just stale — they actively misrepresent the target and have been used as the stated reason to accept real security/reliability gaps (see re-classification list below). A due-diligence reviewer (customer security team, auditor, investor) reading "demo stability / thesis defensibility" will discount the whole platform.
- **Fix:** Decide and record the true target in a single source of truth. If enterprise/international: rewrite the posture sections, remove "academic/demo" framing, and re-run classification of every deferred/accepted-risk item against the enterprise bar. If both (academic origin, enterprise product), state it explicitly and keep a real "commercial readiness gap register" instead of disclaimers buried in a compose header.
- **Task:** Proposed backlog item (do this first — it gates the re-classification of everything below).

### Finding ENT-SEC-OPENSEARCH-OPEN — OpenSearch runs with the security plugin DISABLED, even in the production overlay
- **Severity:** Critical (Unauthenticated + unencrypted datastore holding alert evidence + PII)
- **Files:** `docker-compose.yml` (`plugins.security.disabled: "true"`, line ~133), `docker-compose.prod.yml` (opensearch block resets `ports` only — does not re-enable security)
- **Issue:** The alert index (`xdr-alerts`, containing detection evidence, actor identifiers, IPs, and tenant data) is served by an OpenSearch node with `plugins.security.disabled: "true"`. The production overlay closes the host port but leaves the plugin disabled — so anything on the Docker network (any compromised pipeline container) has full unauthenticated read/write to all tenants' security data, with no TLS in transit and no audit. For an enterprise/international product this fails SOC 2 CC6 (logical access), ISO 27001 A.8, and GDPR Art. 32 (security of processing) outright.
- **Fix:** Enable the OpenSearch security plugin in the production overlay: TLS, per-service credentials/roles (least-privilege index access), and audit logging. Pipe the alert-writer's OpenSearch client through authenticated TLS. Add a production-profile validator check that fails if `plugins.security.disabled` is truthy.
- **Task:** Proposed backlog item (enterprise blocker).

### Finding ENT-SEC-NO-TLS-INTERNAL — No TLS/mTLS anywhere on the internal data + control plane
- **Severity:** High (Confidentiality / integrity in transit; SOC2 CC6.7, ISO A.8.24)
- **Files:** all `services/*` (plaintext HTTP to Pandaproxy `http://…:8082`, OpenSearch `http://`), Postgres DSNs (no `sslmode=require`), `app/Services/InternalAuthService.php` (static bearer tokens over HTTP)
- **Issue:** Every hop — ingestion→Pandaproxy, normalizer/correlation→Pandaproxy, alert-writer→Postgres/OpenSearch, Laravel→internal APIs — is plaintext HTTP/TCP, authenticated (where at all) by static shared tokens. This is the substance behind the already-filed `ARCH-MTLS-SEC` (currently Deferred). At enterprise/international bar, in-transit encryption for security telemetry is mandatory, not deferrable. Static-token-over-plaintext is trivially sniffable by any co-resident workload.
- **Fix:** Re-classify `ARCH-MTLS-SEC` from Deferred → **required**. Terminate TLS on every internal listener; move service-to-service auth to mTLS or at minimum tokens-over-TLS; require `sslmode=verify-full` on DB connections.
- **Task:** Re-open ARCH-MTLS-SEC as an enterprise blocker (was Deferred under demo posture).

### Finding ENT-SEC-WEAK-DEFAULT-SECRETS — Weak default credentials are accepted, not rejected, in the production path
- **Severity:** High (Credential management; SOC2 CC6.1)
- **Files:** `docker-compose.yml` (`POSTGRES_PASSWORD:-postgres`, `CLICKHOUSE_PASSWORD:-detector`, `GF_SECURITY_ADMIN_PASSWORD:-admin`, `OPENSEARCH_INITIAL_ADMIN_PASSWORD:-DetectorAdmin123!`), `docker-compose.prod.yml` (does not override or enforce)
- **Issue:** Data stores fall back to well-known defaults (`postgres`/`detector`/`admin`) when env vars are unset, and the production overlay does not enforce that strong secrets were actually provided — deployment succeeds with `admin/admin` Grafana and `postgres/postgres`. There is a `security:validate-secrets` command but it is not a hard deploy gate on these container secrets.
- **Fix:** Make the production profile fail-closed if any data-store secret is unset or matches a default (extend `xdr_production_profile_validate.py`). Remove default fallbacks from the prod overlay. Wire toward `SECRETS-VAULT` (already backlogged) but the fail-closed gate is the immediate blocker.
- **Task:** Proposed backlog item (enterprise blocker).

### Finding ENT-TENANCY-NO-DB-ENFORCEMENT — Multi-tenant isolation is application-layer only; no DB-enforced RLS
- **Severity:** High (Tenant isolation; the #1 enterprise multi-tenant control)
- **Files:** `app/Services/TenantBoundaryService.php` (`RLS_ENABLED=false`), `TENANT-ENFORCE-RLS` (currently Deferred), plus every `where('tenant_id', …)` scattered across services (a single missing scope = cross-tenant leak — cf. ASSET-TENANT-OVERWRITE just found)
- **Issue:** Isolation depends entirely on developers remembering to add `where('tenant_id', …)` on every query. Batch 19's `ASSET-TENANT-OVERWRITE` is a live proof that this hand-rolled model already leaks. `TENANT-ENFORCE-RLS` was Deferred "gated by RLS_DECISION_RECORD" under demo posture. At enterprise/international multi-tenant SaaS bar, Postgres Row-Level Security (or equivalent hard enforcement) is table-stakes for SOC 2 / customer isolation guarantees.
- **Fix:** Re-open `TENANT-ENFORCE-RLS` as a blocker. Adopt Postgres RLS with a per-request tenant GUC, so the DB rejects cross-tenant access even when an app query forgets its scope. Keep the app-layer scope as defense-in-depth.
- **Task:** Re-open TENANT-ENFORCE-RLS as enterprise blocker (was Deferred under demo posture).

### Finding ENT-REL-SIMULATED-HA — HA / scale / DR "PASS" records are computed, not measured against real infrastructure
- **Severity:** High (Reliability claim integrity — a PASS can be mistaken for a real SLA guarantee)
- **Files:** `app/Services/EnterpriseScaleHaService.php`, `TelemetryScalePilotService.php`, `SoakChaosValidationService.php`, `PilotExecutionService.php` (SIM-LAYER-REALITY-GATE Track B, still open), `REVIEW_FUTURE_BACKLOG.md` (HA-DRILL-01 deferred as "too heavy for a laptop")
- **Issue:** The enterprise-facing readiness/HA/scale/DR validators emit PASS/readiness records without exercising a real multi-node cluster; Track A only *labels* them `is_simulated`. For an academic demo this is acceptable; for an enterprise/international product that will publish availability numbers, a computed PASS that reads as a validated SLA is a material misrepresentation. "Too heavy for a laptop" (HA-DRILL-01) is not a valid deferral reason once the target is enterprise — the validation must run on real staging infra.
- **Fix:** Re-open SIM-LAYER-REALITY-GATE Track B and HA-DRILL-01 as required enterprise work: stand up `docker-compose.ha.yml` (3-broker) in real staging/CI-with-resources, run failover/partition/soak, and back the readiness records with measured evidence. Until then, no availability/HA claim may be published.
- **Task:** Re-open SIM-LAYER-REALITY-GATE Track B + HA-DRILL-01 as enterprise blockers (were deferred as "resource-heavy / demo").

### Finding ENT-DETECT-ML-NOT-LIVE — Headline "hybrid rule + ML" detection is not in the live path
- **Severity:** High (Product-claim integrity for an enterprise detection product)
- **Files:** `services/correlation-worker/main.go` (rule-based only), `scripts/train_ai_detector.py`, `storage/app/ai_detector_model.pkl`, `ML-SERVE-ONLINE` (backlogged Medium-High)
- **Issue:** The multiclass-LR model exists only in offline scripts; the live correlation path is rule-based. As an academic thesis artifact this is defensible ("model trained and evaluated offline"); as an enterprise product sold on "hybrid ML + rules detection," shipping a live path with no ML inference is a claim the product does not deliver. Re-rank `ML-SERVE-ONLINE` from Medium-High to a headline-claim blocker (serve it at least as a shadow/advisory scorer, consistent with the shadow-soak boundary).
- **Task:** Re-rank ML-SERVE-ONLINE to enterprise product-claim blocker.

### Finding ENT-SDLC-NO-SUPPLYCHAIN — No dependency pinning, SBOM, image scanning, or signed builds
- **Severity:** Medium (Supply-chain assurance; SLSA / ISO 27001 A.8.28, EU CRA relevance for international sale)
- **Files:** `services/*/requirements.txt` (unpinned `>=`), `services/*/go.mod` (no vendored/verified deps beyond stdlib), `services/*/Dockerfile` (no scan/sign step), absence of `.sbom`, no `composer.lock` policy check in CI
- **Issue:** For international enterprise procurement (and increasingly EU Cyber Resilience Act), customers require an SBOM, pinned+scanned dependencies, and provenance for build artifacts. The Python services pin nothing (`fastapi>=0.110`), there is no image vulnerability scan, and no artifact signing. This is the enterprise-grade superset of the already-noted PY-CONTAINER-HARDENING dependency-pinning item.
- **Fix:** Pin + hash-lock all deps, generate an SBOM (syft/cyclonedx) per image in CI, add image scanning (trivy/grype) as a merge gate, and sign release images (cosign). Fold the reduced PY-CONTAINER-HARDENING scope into this.
- **Task:** Proposed backlog item.

### Finding ENT-COMPLIANCE-GAPS — No SSO/MFA, no distributed tracing, no data-residency/retention controls for an international product
- **Severity:** High (aggregate of enterprise access + observability + data-governance table-stakes)
- **Files:** `IDENTITY-SSO-MFA` (backlogged High), `OBS-OTEL-TRACING` (backlogged Medium), `DATA-TIERING` (backlogged), `SECRETS-VAULT` (backlogged), `app/Console/Commands/SecurityRetentionCommand.php`
- **Issue:** Individually these are already backlogged, but under an enterprise/international frame they form a single compliance blocker set: (a) privileged SOC console has no SSO/MFA (SOC 2 CC6.1, and it *approves response commands*); (b) no standards-based distributed tracing across the polyglot pipeline (operational SLA support); (c) no data-residency / configurable retention / right-to-erasure controls for GDPR/international customers — only a fixed 30/90-day prune. Re-rank `OBS-OTEL-TRACING` and add explicit GDPR data-subject controls.
- **Fix:** Treat SSO/MFA + OTel tracing + configurable retention/erasure as a required "enterprise compliance" epic, not independent low/medium items. Sequence SSO/MFA first (privileged-console access control).
- **Task:** Proposed backlog epic (re-rank OBS-OTEL-TRACING to High; add DATA-RESIDENCY-ERASURE).

### Re-classification note (authoritative under the new target)
The following were parked citing academic/local/demo posture. That justification is void; each must be
re-triaged against the enterprise bar in REVIEW_REJECTED.md:
- `ARCH-MTLS-SEC` (Deferred → blocker; see ENT-SEC-NO-TLS-INTERNAL)
- `TENANT-ENFORCE-RLS` (Deferred → blocker; see ENT-TENANCY-NO-DB-ENFORCEMENT)
- `SIM-LAYER-REALITY-GATE` Track B + `HA-DRILL-01` (deferred as resource-heavy → required; see ENT-REL-SIMULATED-HA)
- `ML-SERVE-ONLINE` (Medium-High → product-claim blocker; see ENT-DETECT-ML-NOT-LIVE)
- `INFRA-4` Grafana writable provisioning, `RAG-1` empty KB (Accepted-Risk "for academic demo") — re-review, though both are lower severity than the above.
- `ARCH-KAFKA-NATIVE`, `ARCH-DB-SPLIT` (Deferred perf) — re-rank against real enterprise throughput SLOs, not demo RPS.

---

## Review Batch 21 — TEST-INFRA-AUDIT (2026-07-06)

Investigation into why test runs are slow when agents work tasks. Facts gathered from `phpunit.xml`,
`tests/TestCase.php`, `config/database.php`, `.env`, and trait/seed usage across 147 test files
(4618 tests, 139 migrations). Conclusion: the slowness is **structural test-infra design**, not
literal duplicate tests. The CLAUDE.md test policy is partly a band-aid over a DB-isolation defect.

### Finding TEST-SHARED-DEV-DB — Tests run against the same Postgres DB as the app (`detector`), with no isolation
- **Severity:** High (Data-loss risk + root cause of forced-serial slowness)
- **Files:** `.env` (`DB_CONNECTION=pgsql`, `DB_DATABASE=detector`), `phpunit.xml` (no `DB_DATABASE`/`DB_CONNECTION` override; sqlite lines 25-26 commented out), CLAUDE.md test policy
- **Issue:** `phpunit.xml` does not override the DB, so the suite uses the `.env` connection — the **live `detector` database**. Consequences: (1) the CLAUDE.md-mandated `php artisan migrate:fresh --force` before every run **wipes the developer's working database**; (2) because there is exactly one shared mutable DB, tests **cannot** run in parallel (hence the CLAUDE.md "do NOT run parallel" rule) — 4618 tests are forced serial over TCP round-trips; (3) state leaks between runs, which is *why* the `migrate:fresh` band-aid exists ("avoid intermittent QueryException from stale schema state"). The isolation defect and the slowness are the same root cause.
- **Fix:** Point tests at a dedicated DB (`detector_test`) via `phpunit.xml` `<env name="DB_DATABASE" value="detector_test"/>` or `.env.testing`. This alone makes `migrate:fresh` safe (never touches dev data) and unblocks parallelism.
- **Task:** Proposed backlog item (do first — prerequisite for the parallel + drop-migrate-fresh wins).

### Finding TEST-NO-PARALLEL — No paratest; suite is serial only because of the single shared DB
- **Severity:** High (dominant wall-clock cost)
- **Files:** `composer.json` (no `brianium/paratest`; `php artisan test` runs single-process), CLAUDE.md ("Do NOT run parallel processes against the same PostgreSQL test database")
- **Issue:** 4618 tests run in one process against one Postgres DB. The "no parallel" rule is correct *given* the shared-DB setup, but it is self-imposed: once each worker gets its own DB (`detector_test_1..N`, Laravel's `php artisan test --parallel` creates these automatically), parallelism is safe and is the single biggest speedup available (≈ Nx on core count). The current setup locks in serial execution.
- **Fix:** After TEST-SHARED-DEV-DB, install `brianium/paratest` and use `php artisan test --parallel --recreate-databases`. Update CLAUDE.md to *require* parallel with per-worker DBs instead of forbidding it.
- **Task:** Proposed backlog item.

### Finding TEST-NO-SCHEMA-DUMP — All 139 migrations run on every fresh; manual migrate:fresh double-runs them
- **Severity:** Medium (fixed per-invocation overhead, paid twice)
- **Files:** no `database/schema/*.dump`, CLAUDE.md (`migrate:fresh --force && php artisan test`), RefreshDatabase (140 test files)
- **Issue:** Two compounding costs: (a) there is no `schema:dump`, so all **139 migration files** execute sequentially every time the schema is built; (b) RefreshDatabase already runs `migrate:fresh` once at suite start (guarded by its static `$migrated`), so the CLAUDE.md-mandated **manual `migrate:fresh --force` builds the entire 139-migration schema a second time** in a separate process before `php artisan test` builds it again. The manual step is redundant with RefreshDatabase and doubles the migration cost per full run.
- **Fix:** Run `php artisan schema:dump --prune` to collapse migrations into one SQL load (RefreshDatabase then loads the dump + only newer migrations). Once all tests are transaction-isolated (see TEST-UNTRAITED), drop the manual `migrate:fresh` prefix — RefreshDatabase's single fresh migration is sufficient.
- **Task:** Proposed backlog item.

### Finding TEST-UNTRAITED — 7 test files use no DB trait; likely source of the "stale schema" leakage the band-aid papers over
- **Severity:** Medium (state coupling → the QueryException flakiness that justifies migrate:fresh)
- **Files:** `tests/Feature/{DocumentationFreeze,EndpointAgentStatusHelper,IntegrationConfigCache,InternalAuthConfigMapping,XdrRuleRegistryValidator}Test.php`, `tests/{Feature,Unit}/ExampleTest.php`
- **Issue:** 140/147 files use `RefreshDatabase` (transaction-wrapped, clean). The 7 without a trait run outside any transaction. Most look DB-free (validator/doc/config tests), but any that touch the DB will **commit** rows that survive into later tests and the next run — exactly the "stale state" the manual `migrate:fresh` compensates for. Confirming these 7 are truly DB-free (or adding `DatabaseTransactions`) removes the justification for the destructive migrate:fresh prefix.
- **Fix:** Audit the 7; add `RefreshDatabase`/`DatabaseTransactions` to any that hit the DB, or assert they are DB-free. Then the migrate:fresh band-aid can be retired (see TEST-NO-SCHEMA-DUMP).
- **Task:** Proposed backlog item.

### Finding TEST-PER-TEST-SEED — 16 classes seed inside setUp; heavy DemoSocSeeder re-runs per test method
- **Severity:** Low-Medium (per-method seed cost in those classes)
- **Files:** 16 files calling `$this->seed(...)` / `Artisan::call` in setUp (AiContextEnrichment, SocWorkflow, SiemSearch, TraceInvestigation, Perf* …); `database/seeders/DemoSocSeeder.php` (218 lines)
- **Issue:** With RefreshDatabase, `setUp` runs before **every** test method, so any class seeding a heavy fixture (DemoSocSeeder is 218 lines of inserts) pays that cost once per method, not once per class. For a class with 15 methods that is 15× the seed.
- **Fix:** Prefer a minimal purpose-built fixture per test, or seed once per class via a `setUpBeforeClass`/lazy-guard pattern where the seeded data is read-only. Reserve the full DemoSocSeeder for the few tests that genuinely need it.
- **Task:** Proposed backlog item (Low-Medium).

### Not a defect — "duplicate tests" assessment
- There are **no literal duplicate tests**. The two `ExampleTest.php` files are (customized) Laravel defaults and are harmless. The real driver of the 4618-test count is **boilerplate proliferation**: ~50 phase modules each add 50–65 near-identically-structured tests (advisory-only assertions, append-only table checks). 29 files already share `AssertsAdvisoryOnlyConstraints` (good DRY). The unbounded growth ties directly to `META-MODULE-RATIONALIZE` (~32 self-referential readiness/certification/evidence-freeze modules) — trimming those modules would shrink the suite as a side effect. This is a capability/scope issue, not a test-hygiene bug.

### CLAUDE.md verdict
The policy is **not wrong on iteration discipline** (targeted `--filter` first is correct and should stay). It IS wrong on two points, both traceable to the shared-DB defect:
1. `migrate:fresh --force &&` on every full run — redundant with RefreshDatabase, doubles migration cost, and destroys the dev DB because tests share it. Retire it after DB isolation + untraited-test cleanup.
2. "Do NOT run parallel" — should become "run parallel with per-worker DBs" once isolation lands; it is the biggest available speedup.
Recommended sequence: TEST-SHARED-DEV-DB → TEST-NO-PARALLEL → TEST-NO-SCHEMA-DUMP → TEST-UNTRAITED → update CLAUDE.md test policy. Keep Postgres (do NOT switch to sqlite :memory:) — the codebase relies on Postgres-specific SQL (`::jsonb`, `ILIKE`, `xmax`, `GREATEST`) that sqlite would break.

---

## Review Batch 22 — REVIEW-DECISION-AUDIT (2026-07-06)

Meta-review of the review process itself: audited every REVIEW_REJECTED.md decision (sound? reason
valid?) and spot-verified REVIEW_COMPLETED.md fixes against live code (does the code match the claim?).

### Completed-fix fidelity — 4 spot-checks, all MATCH code exactly
- **ASSET-TENANT-OVERWRITE** ✓ `AssetContextService::registerAsset()` upsert key is now
  `['tenant_id'=>$tenantId,'external_id'=>$externalId]`; migration `2026_07_08_010001_fix_asset_inventory_tenant_scoped_external_id.php` present.
- **CONSUMER-GROUP-EPHEMERAL** ✓ `_stable_group()` / `_new_instance_id()` / `_fresh_group_for_offset_reset()`
  present; group is regenerated only inside `if is_offset_err`, instance id always — exactly as claimed.
- **NORM-ASYNC-COMMIT-LOSS** ✓ `queuedEvent{event, wg *sync.WaitGroup}`, per-batch `wg.Wait()` blocks the
  next poll/commit until publish attempts complete — matches claim.
- **AW-DEDUPE-BEFORE-COMMIT** ✓ local `seen_in_batch` set dedupes intra-batch; `_seen_add(fp)` runs only
  after `postgres_ok` (or dry-run) — matches claim.
Conclusion: high implementation fidelity; no overstated completions in the sample.

### Reject-decision soundness — all sampled rejects hold
- ENV-3, STATE-REDIS-05, PERF-DB-CONN-LEAK: evidence-based, valid.
- **TEST-PER-TEST-SEED reject is CORRECT** (re-verified: zero test files seed inside `setUp()`). The
  original Batch 21 finding was imprecise (flagged files containing any seed call, assumed `setUp`). The
  rejecter caught it correctly.
- EDR-EXEC-02 / AI-CONF-BANDS: correctly rejected as CLAUDE.md Forbidden Changes (safety boundary) — valid
  regardless of enterprise vs academic target; only the rationale *wording* ("academic defensibility") is stale.

### Misclassifications found & reconciled (in REVIEW_REJECTED.md)
- **INFRA-3** was stale — sat in Deferred but is resolved by ENTERPRISE-068 (container resource limits in
  both compose files). Re-marked RESOLVED.
- **IG-1/IG-2/IG-3** are marked IMPLEMENTED but filed under Deferred (Section 2); they belong in COMPLETED
  (already there as INGESTION-025). Flagged as cross-reference, not a real open item.
- **Enterprise-reframe rationale drift:** several Deferred/Accepted-Risk reasons still cite "academic
  demo/scope/posture." Decisions mostly stand (safety boundaries unchanged; prod-gated items still valid),
  but the justification wording needs refresh and DB-3/DB-4/RAG-1/INFRA-4/GAP-006 need firmer pre-pilot
  gates rather than open-ended "tolerated for demo." Recorded in the REVIEW_REJECTED.md reconciliation banner.

### Verdict
Decision quality is good: rejects are evidence-based, the Deferred bucket correctly holds enterprise items
(GAP-002/003, TENANT-ENFORCE-RLS) instead of rejecting them, and completed fixes are real. The only defects
are housekeeping (INFRA-3 stale, IG-* misfiled) and stale "academic" rationale wording post-reframe — no
wrong or unsafe decision found, and no fabricated completion.

---

## Review Batch 23 — MULTI-TENANT-ISOLATION-AUDIT (2026-07-07)

Deeper security audit focusing on multi-tenant boundaries and data isolation in active response, entity graphs, UEBA baselines, and risk scoring.

### Finding 23.1 — Active Response Execution Subsystem is completely unisolated
- **Severity:** Critical (Security Vulnerability)
- **Files:** `app/Services/ActiveResponseExecutionService.php`, `app/Http/Controllers/Response/ActiveResponseController.php`, `app/Http/Controllers/Api/ActiveResponseApiController.php`, active response migrations
- **Issue:** The active response execution tables (`response_executions`, `response_execution_events`, `response_execution_rollbacks`, and `response_execution_simulations`) lack a `tenant_id` column and are not registered in `TenantBoundaryService::ISOLATED_TABLES`. Web and API controllers query and execute response plans globally without validating the requesting tenant context, allowing any tenant's operator to trigger or view response plans (e.g. host isolation) for other tenants.
- **Fix:** Add `tenant_id` columns to all response execution tables, register them in `TenantBoundaryService::ISOLATED_TABLES`, and refactor controllers to enforce scoped queries and authorization checks.
- **Task:** ENT-TENANCY-RESPONSE-EXECUTION

### Finding 23.2 — Entity Graph has no tenant isolation and suffers from cross-tenant pollution
- **Severity:** High (Data Leakage)
- **Files:** `app/Services/EntityGraphService.php`
- **Issue:** `EntityGraphService::upsertEntity()` and `upsertRelationship()` do not populate the `tenant_id` column on the `entities` and `entity_relationships` tables (leaving them as `null`). The projection methods (`projectFromAlerts`, `projectFromIncidents`) process events globally and merge identically-keyed entities (like `administrator` or `192.168.1.1`) across all tenants into a single shared graph.
- **Fix:** Update `EntityGraphService` to accept and populate `tenant_id` on all entities/relationships, include `tenant_id` in unique constraints (to isolate identically keyed entities), and scope all database projection queries by tenant.
- **Task:** ENT-TENANCY-ENTITY-GRAPH

### Finding 23.3 — UEBA profiles and observations are computed globally without tenant isolation
- **Severity:** High (Data Leakage / Inaccuracy)
- **Files:** `app/Services/UEBABaselineService.php`, UEBA migrations
- **Issue:** None of the UEBA profile, observation, or anomaly score tables (`entity_behavior_baselines`, `baseline_observations`, `baseline_anomaly_scores`, `peer_group_profiles`) carry a `tenant_id` column, nor are they registered in `TenantBoundaryService::ISOLATED_TABLES`. Baselines and peer groups are calculated globally, causing users/hosts from different tenants to be compared/grouped together.
- **Fix:** Add `tenant_id` columns to all UEBA tables, register them under `TenantBoundaryService::ISOLATED_TABLES`, and update `UEBABaselineService` to segment observations, baselines, and peer groups per tenant.
- **Task:** ENT-TENANCY-UEBA

### Finding 23.4 — Entity Risk Scoring leaks cross-tenant alerts and incidents
- **Severity:** High (Data Leakage)
- **Files:** `app/Services/EntityRiskScoringService.php`
- **Issue:** `EntityRiskScoringService::calculateRisk()` retrieves security alerts and incidents globally based purely on the entity key (e.g. actor_key/user email, IP, hostname), without checking their tenant attribution. If keys collide (e.g. `admin`), Tenant A's entity risk calculation will fetch and leak Tenant B's alerts.
- **Fix:** Scope all data retrieval helpers (`alertsForEntity`, `incidentsForEntity`, etc.) inside `EntityRiskScoringService` by the requesting tenant ID.
- **Task:** ENT-TENANCY-RISK-SCORING

## Review Batch 24 - LOG-CONNECTOR-AUDIT-1 (2026-07-11)

### Scope Analyzed

Reviewed the Syslog/CEF/LEEF, CloudTrail, GuardDuty, and GCP Audit connector entrypoints, parsers, state files, tests, Dockerfiles, Compose wiring, and the ingestion gateway's tenant acceptance behavior.

### Finding CONN-DELIVERY-LOSS - Failed connector batches are permanently discarded

- **Severity:** Critical
- **Confidence:** High
- All connectors clear their in-memory buffer before HTTP delivery succeeds. The three file connectors also persist a file as processed before delivery, so a temporary gateway failure causes the source file to be skipped forever.
- **Backlog:** Yes - acknowledged delivery, retry/backoff, restart-safe spool/checkpointing, and failure-recovery tests.

### Finding CONN-UNTENANTED-INGEST - Compose sends connector telemetry without tenant attribution

- **Severity:** High
- **Confidence:** High
- Connector-specific tenant variables are optional and absent from `docker-compose.yml`; the gateway accepts empty tenant identity and bypasses per-tenant limiting for it.
- **Backlog:** Yes - fail closed in strict/production mode and require signed tenant attribution.

### Finding CONN-UNBOUNDED-FILE - Cloud connectors load and decompress whole files without limits

- **Severity:** High
- **Confidence:** High
- All three cloud-export connectors use `os.ReadFile`; parser decompression uses `io.ReadAll`, with no compressed or expanded size limit.
- **Backlog:** Yes - bounded streaming, oversized-file quarantine, and resource-bound tests.

### Finding SYSLOG-TCP-ADMISSION - Syslog TCP has no connection cap or idle deadline

- **Severity:** Medium
- **Confidence:** High
- Every unauthenticated connection receives an unbounded goroutine and may block forever in `Scanner` because no read deadline is set.
- **Backlog:** Yes - connection semaphore, deadlines, backoff, metrics, and slow-client tests.

### Not Backlog

- Aggregate connector health/metrics endpoints did not expose event payloads in the reviewed implementation.
- Shared-secret transport risk is already tracked by the existing mTLS/internal-auth backlog.

## Review Batch 25 — EXPORT-SOAR-HUNTING-TRACE-TENANCY-AUDIT (2026-07-12)

Deeper security audit of report exports, SOAR playbooks and approval workflows, threat hunting query engines, and trace search pathways to ensure strict multi-tenant segmentation.

### Finding 25.1 — Report Export Service & Controllers suffer from cross-tenant IDOR leaks
- **Severity:** Critical (Security Vulnerability)
- **Files:** `app/Services/ReportExportService.php`, `app/Http/Controllers/Export/ExportController.php`, `app/Http/Controllers/Api/ExportApiController.php`
- **Issue:** The export service and controllers retrieve investigations, response plans, entity risk profiles, and traces using raw SQL queries based solely on IDs, without validating requesting tenant context. Any authenticated tenant user can query and download another tenant's security reports and raw alert logs by changing the ID in the download request path.
- **Fix:** Inject `TenantBoundaryService` or use `TenantContextAuthority` to scope all DB queries and assert that requested records belong to the active tenant.
- **Task:** EXPORT-TENANCY-GAP

### Finding 25.2 — SOAR Orchestration & Playbooks completely lack multi-tenant scoping
- **Severity:** Critical (Security Vulnerability)
- **Files:** `app/Http/Controllers/Soar/SoarOrchestrationController.php`, `app/Services/SoarOrchestrationService.php`, SOAR migrations
- **Issue:** None of the SOAR playbooks, version snapshots, execution plans, rollback plans, simulations, or approvals contain a `tenant_id` column, nor are they registered in `TenantBoundaryService::ISOLATED_TABLES`. All registry lists and details are queried globally, allowing any tenant user to see, simulate, and approve/reject response playbooks of other tenants.
- **Fix:** Add `tenant_id` columns to all SOAR tables, register them in `TenantBoundaryService::ISOLATED_TABLES`, and filter all queries by tenant context.
- **Task:** SOAR-TENANCY-GAP

### Finding 25.3 — Threat Hunting Queries and Pivoting execute globally
- **Severity:** High (Data Leakage)
- **Files:** `app/Services/ThreatHuntingService.php`, `app/Http/Controllers/Security/ThreatHuntController.php`, `app/Http/Controllers/Api/ThreatHuntApiController.php`
- **Issue:** `ThreatHuntingService` executes hunts and pivot searches globally across all hosts/events without scoping queries by the tenant context. In addition, the pivot explorer and dashboard query hosts and recent hunts globally.
- **Fix:** Scope all queries in `executeQuery` and pivot helpers by the active tenant ID, add `tenant_id` columns to threat hunts (already registered under `ISOLATED_TABLES`), and segment lists in the controllers.
- **Task:** HUNT-TENANCY-GAP

### Finding 25.4 — Trace Investigation Search leaks cross-tenant timelines
- **Severity:** High (Data Leakage)
- **Files:** `app/Http/Controllers/Trace/TraceInvestigationController.php`, `app/Http/Controllers/Api/TraceApiController.php`
- **Issue:** Trace search and timeline retrieval queries look up alerts, incidents, operational events, and evidence globally by ID or IP without checking tenant boundaries, allowing a tenant user to explore trace timelines and raw logs of other tenants.
- **Fix:** Scope the search and timeline lookup queries by the tenant context, ensuring the trace contains at least one alert or incident owned by the active tenant before displaying the details.
- **Task:** TRACE-TENANCY-GAP
