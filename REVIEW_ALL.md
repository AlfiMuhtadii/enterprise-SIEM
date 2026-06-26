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
