# Global Security & Code Review Findings List

This file lists all the analysis results, security posture evaluations, and code quality findings identified by the Advisor/Reviewer. For actionable tasks, implementation guides, and progress tracking, please refer to:
* **[TASKS_BACKLOG.md](file:///D:/project/Detector/TASKS_BACKLOG.md)**: Pending hardening and cleanup tasks.
* **[TASKS_COMPLETED.md](file:///D:/project/Detector/TASKS_COMPLETED.md)**: Completed or in-progress implementations.

---

## 1. Tenancy & Isolation (BACKLOG-TENANCY-023)

### Findings:
* **Sentinel Scope Protection (Secure)**:
  * The sentinel value (`_global`) is evaluated prior to the admin bypass check. This ensures that non-admins attempting to use the sentinel will be thrown into a `TenantSpoofAttemptException` rather than bypassing validation.
* **CLI Compliance & Append-Only Safety (Secure)**:
  * The `tenant:null-audit` command relies purely on read-only query operations (`count()`). It does not modify any records, which respects the append-only table invariant.
* **CLI Input Validation Gap (Low Risk / Hardening Opportunity)**:
  * The `--table` option in the CLI command accepts any database table name as input. It lacks validation to ensure the table is part of the registered `TenantBoundaryService::ISOLATED_TABLES`, creating a potential target boundary gap during operations.

---

## 2. Unit & Feature Test Suite Analysis (TEST-SUITE-AUDIT)

### Findings:
* **Documented Domain Count Mismatch (Low Risk / Doc Bug)**:
  * The documentation in `AGENTS.md` and `CLAUDE.md` states there are **158** supported threat-hunting domains.
  * The actual implementation in `ThreatHuntingService::SUPPORTED_DOMAINS` and the test suite has **161** domains.
  * The mismatch is due to 3 domains added for the shadow domain soak harness (`shadow_soak_runs`, `shadow_soak_gate_checks`, `shadow_soak_evidence_snapshots` in Phase 1/018) that were not updated in documentation.
* **Deceptive Test Method Naming (Code Smell)**:
  * Several test classes check the count of supported threat-hunting domains using names containing historical counts (e.g. 95, 100, 105, 110, 115, 120, 125, 130), but all of them assert the actual current count of `161`.
* **Containment Checks Duplication (DRY Violation)**:
  * 12+ separate feature test classes duplicate the exact same 5 test methods to verify that their corresponding service classes do not contain forbidden containment or remediation logic (methods like `isolateHost`, `quarantineHost`, `executeShell`, `killProcess`, and `autoRemediate`). This represents 60 duplicate test methods in the test suite.

---

## 3. Database, Migrations, & Seeders (DATABASE-AUDIT)

### Findings:
* **Missing `tenant_id` Columns in Registered Isolated Tables (High Risk / Isolation Gap)**:
  * The event tables `advisory_finding_events` and `dlq_normalization_events` are declared in `TenantBoundaryService::ISOLATED_TABLES` but completely lack a `tenant_id` column in their database migrations. As a result, direct database-level tenant isolation is impossible for these logs.
* **Missing Indexes on `tenant_id` Columns (Performance Smell)**:
  * The `advisory_findings` table and all 9 `shadow_soak_*` tables (`shadow_soak_runs`, `shadow_soak_evidence_snapshots`, `shadow_soak_gate_checks`, `shadow_soak_domain_assessments`, `shadow_soak_finding_summaries`, `shadow_soak_confidence_bands`, `shadow_soak_suppression_stats`, `shadow_soak_coverage_stats`, and `shadow_soak_audit_events`) have a `tenant_id` column but **lack indexes** on it. Scoped queries in a multi-tenant production environment will trigger expensive table scans.
* **Lockout of Seeded Users in Strict Mode (Operational Bug)**:
  * The seeders (`UserSeeder.php` and `DemoSocSeeder.php`) create demo users (`soc-admin`, `soc-analyst`, etc.) but do not seed any tenant memberships in the `user_tenant_memberships` table. In strict tenancy mode, these users are locked out from accessing any scoped endpoints.
* **Unscoped Demo Alerts & Incidents**:
  * `DemoSocSeeder` inserts database records with `tenant_id = NULL` for demo alerts and incidents. Under strict multi-tenant environments, these records are hidden from scoped queries and will cause the `tenant:null-audit` command to fail with dirty status.

---

## 4. Environment & Repository Structure (ENV-STRUCTURE-AUDIT)

### Findings:
* **Critical Missing Environment Keys in `.env` (High Risk / Config Drift)**:
  * The active `.env` file is missing **20 key variables** defined in `.env.example`.
  * Crucially, this includes `XDR_TENANT_STRICT_MODE` (our tenancy isolation switch) and `XDR_ENFORCE_INTERNAL_AUTH` (our service trust boundary switch), which can result in fallbacks to insecure or incorrect local defaults.
* **Configuration Template Drift (Maintenance Smell)**:
  * The template env files have severe drift: `.env.example` has 119 keys, `.env.local.example` has 53 keys, `.env.production.example` has 81 keys, and `.env.staging.example` has 33 keys.
  * 19 AI-related variables (e.g. `SOC_AI_ENABLED`, `SOC_OLLAMA_BASE_URL`) exist in `.env.local.example` and `.env.production.example` but are entirely missing from `.env.example` and `.env.staging.example`.
* **Repository Bloat & Missing gitignores (Cleanliness Smell)**:
  * The `/reports` folder is not ignored in `.gitignore`, causing large dynamically generated output files (like the 42MB `xdr_correlation_soak_6h.json` file and dozens of one-off `demo-causal-demo-*.json`/`.md` files) to pollute `git status` or bloat the repository.
* **Inconsistent Controller Naming Conventions**:
  * Resource controllers use mixed pluralization: `AdvisoryFindingsController` is plural, whereas `DlqController` and `ThreatHuntController` are singular. Model classes, however, are perfectly singular and resolve cleanly to migrations.

---

## 5. Infrastructure & Container Deployment (INFRA-AUDIT)

### Findings:
* **Insecure Datastore Port Exposure (High Risk / Boundary Breach)**:
  * All database and search clusters (`postgres` on 5432, `clickhouse` on 8123/9000, `opensearch` on 9200/9600, and `qdrant` on 6333/6334) bind to `0.0.0.0` (all interfaces) in `docker-compose.yml`. This exposes the ports to the host's public interfaces. These must be restricted to localhost (`127.0.0.1`) or unexposed to ensure the network boundary is tight.
* **Plaintext Hardcoded Secrets (Security Smell)**:
  * ClickHouse (`CLICKHOUSE_PASSWORD: detector`), Grafana (`GF_SECURITY_ADMIN_PASSWORD: admin`), and OpenSearch (`OPENSEARCH_INITIAL_ADMIN_PASSWORD: DetectorAdmin123!`) use plaintext, hardcoded credentials directly inside `docker-compose.yml`. These credentials should be set dynamically via `.env` variables to prevent pushing secrets to source control.
* **Lack of Resource/Memory Limits on Intensive Containers (OOM Vulnerability)**:
  * Containers like `clickhouse`, `qdrant`, and pipeline worker services (e.g. `correlation-worker`, `normalizer-worker`) have no memory or CPU boundaries configured. Under intensive soak/chaos validation runs, a memory leak or cpu spike could freeze the staging/production host machine.
* **Grafana Provisioning Mount Permissions**:
  * Grafana mounts provisioning directories directly from the host. In staging/production environments, this should be mounted as read-only (`:ro`) to prevent the containerized application from modifying configuration files.

---

## 6. AI & RAG Subsystem (AI-RAG-AUDIT)

### Findings:
* **Hybrid RAG Grounding Architecture (Design Posture)**:
  * The AI subsystem employs Retrieval-Augmented Generation (RAG) to grounding LLM outputs. It uses a vector store (`local-keyword` keyword embedding, or `Qdrant` vector search via the external `ai-rag-service`) to retrieve analyst-authored checklists and playbooks (`soc_knowledge_base`).
* **Critical Knowledge Base/Dataset Sufficiency Gap (Groundedness Smell)**:
  * The database seeders (`DemoSocSeeder.php` and `UserSeeder.php`) **do not seed any RAG knowledge entries** in `soc_knowledge_base`.
  * On a fresh deployment, the knowledge base is completely empty, rendering vector search useless. The RAG pipeline will always retrieve zero results and fall back to local heuristics or ungrounded parametric model outputs.
* **High Hallucination ("Halu") Risk initially**:
  * Because the database has no seeded knowledge playbooks, the external LLM must generate suggestions based purely on pre-trained parametric weights. This leads to high hallucination rates for contextual incident analysis.
  * To mitigate this, `AiGuardrails` implements a post-processing check: if an output has no citations, it triggers a guardrail warning event (`'No retrieval citations attached; treat as analyst-assistive, not authoritative.'`) and logs a warning on the dashboard.
* **Accuracy Measurement Loop**:
  * The nightly evaluation script (`soc:ai-evaluate`) calculates RAG accuracy based on analyst feedback (acceptance rate), citation coverage (RAG grounding), guardrail violations (hallucination rate), and metadata keyword match (`summary_accuracy_estimate`).

---

## 7. Ingestion Gateway Service (INGESTION-GATEWAY-AUDIT)

### Finding IG-1: Synchronous Metrics Polling for Admission Control
* **Category**: B. Conditional Risk
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **file**: `services/ingestion-gateway/main.go`
  * **function/class**: `Gateway.admissionAllowed()` (lines 152-171)
  * **behavior observed**: The gateway sends a synchronous HTTP GET request to `normalizerMetricsURL` inside the client request path on every single ingest operation.
* **Why it matters**: Synchronous network I/O to poll external metrics blocks the main client thread, increases ingestion latency, and introduces a cascading failure risk if the normalizer service slows down or goes offline.
* **Conditions required for this to be a real risk**: High telemetry volume (high RPS traffic) or a slow/unresponsive normalizer metrics endpoint.
* **Existing safeguards**: A rate limiter restricts overall incoming traffic to `XDR_INGEST_RPS` (default 50), and admission control is bypassed if `XDR_NORMALIZER_METRICS_URL` is empty.
* **Recommended action**: Refactor metrics gathering to query the normalizer asynchronously in a background goroutine at a set interval (e.g., 1 second) and check a cached atomic value.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[INGESTION-GATEWAY] Refactor normalizer queue depth checks to use asynchronous polling`
* **Tests required if implemented**: Unit/integration tests verifying that `admissionAllowed` returns the cached status without network calls, and load tests checking gateway latency during a mock metrics endpoint outage.

---

### Finding IG-2: Global Rate Limiting Starvation
* **Category**: B. Conditional Risk
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **file**: `services/ingestion-gateway/main.go`
  * **function/class**: `rateLimit()` middleware (lines 225-249)
  * **behavior observed**: A single global token bucket channel is shared across all incoming client connections.
* **Why it matters**: If one rogue agent or tenant floods the ingestion path, it can exhaust all available tokens, starving and blocking traffic from all other healthy endpoint agents (Denial of Service).
* **Conditions required for this to be a real risk**: Multi-tenant or multi-agent environments where a single client transmits high volumes of telemetry or executes an abuse payload.
* **Existing safeguards**: Endpoint agents are programmed with randomized exponential backoffs and heartbeat intervals.
* **Recommended action**: Refactor the rate limiter middleware to track token buckets per client IP address, client token, or tenant ID.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[INGESTION-GATEWAY] Implement client-scoped or tenant-scoped rate limiting to prevent global starvation`
* **Tests required if implemented**: Functional tests simulating multiple mock clients and asserting that rate-limiting Client A does not block or limit Client B.

---

### Finding IG-3: Overly Long Outage Retry Timeout
* **Category**: B. Conditional Risk
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **file**: `services/ingestion-gateway/main.go`
  * **function/class**: `Gateway.publish()` (lines 191-209)
  * **behavior observed**: Sequential retries occur with a 15-second HTTP client timeout.
* **Why it matters**: If the Redpanda REST proxy is completely down, publishing requests will block for up to 45 seconds. Under high traffic volumes, this blocks available server threads and exhausts connection socket pools, freezing the gateway.
* **Conditions required for this to be a real risk**: An active Redpanda REST proxy outage under high telemetry traffic.
* **Existing safeguards**: Retries use incremental sleep backoffs of 100ms, 200ms, and 300ms.
* **Recommended action**: Reduce the REST client timeout to 1-2 seconds specifically for publishing Kafka/Redpanda records.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[INGESTION-GATEWAY] Reduce publish HTTP client timeout to prevent socket exhaustion during outages`
* **Tests required if implemented**: A test case simulating a slow/hung Redpanda endpoint and asserting that the gateway times out within 1-2 seconds.
* **Triage Note**: Since the ingestion-gateway findings only trigger under high scale and this is an academic single-tenant scope, Claude has validated and rejected IG-1, IG-2, and IG-3 (see REVIEW_REJECTED.md).

---

## 8. Go Normalizer & Correlation Pipeline (PIPELINE-AUDIT)

### Finding NW-1: Missing tenant_id and Demo Lineage Metadata in Normalizer Workers
* **Category**: A. Direct Isolation / Lineage Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **file**: `services/normalizer-worker/main.go`
  * **functions**: `normalizeEndpoint`, `normalizeDns`, `normalizeProxy`, `normalizeFirewall`, `normalizeSysmon`, `normalizePowerShell`, `normalizeWindowsSecurityEvent`, `normalizeIdentityProvider`, `normalizeSaasAudit`, `normalizeTicketSync`, `normalizeNotificationEvent`
  * **behavior observed**: Every single helper function maps raw telemetry to a typed normalized structure but completely ignores/omits `"tenant_id"`, `"demo_run_id"`, `"source_event_id"`, and `"scenario_id"`. Only the fallback `normalize` function maps them.
* **Why it matters**: Normalized events written to the `telemetry.normalized` topic lose all tenant context and demo lineage metadata. This breaks database tenant scoping during correlation and makes tracking/evaluating simulated demo scenarios impossible.
* **Recommended action**: Update all typed normalizer helpers in `services/normalizer-worker/main.go` to include:
  ```go
  "tenant_id":       first(raw, "tenant_id"),
  "demo_run_id":     first(raw, "demo_run_id"),
  "source_event_id": first(raw, "source_event_id"),
  "scenario_id":     first(raw, "scenario_id"),
  ```
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[NORMALIZER-WORKER] Propagate tenant_id and demo lineage metadata in all type-specific normalizers`

---

### Finding CORR-1: Telemetry Type Mismatch for Identity and SaaS Events in Correlation Worker
* **Category**: D. Code Smell / Detection Gap
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **file**: `services/correlation-worker/main.go`, `services/normalizer-worker/main.go`
  * **behavior observed**:
    - The `normalizer-worker` normalizes identity provider events to `"telemetry_type": "identity_provider"` and SaaS events to `"telemetry_type": "saas_audit"`.
    - However, the `correlation-worker` checks for `ev.TelemetryType == "identity"` and `ev.TelemetryType == "saas"` in its correlation rules and filters (e.g. lines 611, 724, 763, 2049, 2137).
* **Why it matters**: The telemetry type string mismatch causes all normalized identity provider and SaaS audit events to be silently ignored during correlation. No rules targeting these domains will ever fire.
* **Recommended action**: Update telemetry type checks in `services/correlation-worker/main.go` to support both forms (e.g. check for `"identity"` or `"identity_provider"`, and `"saas"` or `"saas_audit"`), or align the normalizer worker to output `"identity"` and `"saas"`.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[CORRELATION-WORKER] Align telemetry type checks to support normalized identity_provider and saas_audit strings`

---

## 9. DB Write Path & Alert Tenancy Scoping (DATABASE-PIPELINE-ALIGN)

### Finding DB-5: Missing tenant_id Propagation in Write Paths for Alerts and Incidents
* **Category**: A. Direct Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**: `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`
  * **behavior observed**:
    - `write_postgres()` in `alert-writer-service/main.py` does not include `tenant_id` in its `INSERT INTO security_alerts` query. The `AlertPayload` model also lacks a `tenant_id` field.
    - `write_incidents()` in `incident-builder-service/main.py` does not include `tenant_id` in its `INSERT INTO security_incidents` query.
* **Why it matters**: Migration `2026_06_24_0500001_add_tenant_id_to_alerts_incidents.php` adds the `tenant_id` column to both tables, but because the pipeline microservices never write to it, all alerts and incidents are created with `tenant_id = NULL`. In strict tenancy mode, these records are excluded from tenant-scoped queries, making them invisible to users.
* **Recommended action**:
  - Update `AlertPayload` model to include `tenant_id: Optional[str] = None`.
  - Update `write_postgres()` in `alert-writer-service/main.py` to extract `tenant_id` (from raw_event, evidence, or envelope) and insert it.
  - Update `write_incidents()` in `incident-builder-service/main.py` to resolve `tenant_id` from the contributing alerts and insert it into `security_incidents`.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[DATABASE-PIPELINE] Populate tenant_id in security_alerts and security_incidents PostgreSQL write paths`

---

## 10. Backlog & Triage Assessment

### Backlog Candidate List (Confirmed / High-Confidence Risks)
* **[NORMALIZER-WORKER] Propagate tenant_id and demo lineage metadata in all type-specific normalizers** (NW-1)
* **[CORRELATION-WORKER] Align telemetry type checks to support normalized identity_provider and saas_audit strings** (CORR-1)
* **[DATABASE-PIPELINE] Populate tenant_id in security_alerts and security_incidents PostgreSQL write paths** (DB-5)

### Not Backlog (Low Priority / Cleanup / Posture)
* **[INGESTION-GATEWAY] Ingestion gateway metrics, rate-limiting, and retry timeout** (IG-1, IG-2, IG-3) - Rejected by Claude as low risk under single-tenant academic scope.
* **[DATABASE] Seeder user tenancy & unscoped seeder data** (DB-3, DB-4) - Rejected.
* **[ENV] Controller naming consistency** (ENV-3) - Rejected.
* **[INFRA] Docker resource limits & Grafana write mounts** (INFRA-3, INFRA-4) - Rejected.
* **[AI-RAG] Empty knowledge base seeder** (RAG-1) - Rejected.


---

## 11. Documentation Drift Audit (DOC-DRIFT-AUDIT, 2026-06-26)

### Finding DOC-1: Threat Hunting Domain Count Drift Across Reviewer and Portfolio Documents
* **Category**: D. Documentation Drift / Reviewer Context Risk
* **Severity**: Low
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Services/ThreatHuntingService.php`
    - `tests/Feature/*`
    - `README.md`
    - `AGENTS.md`
    - `CLAUDE.md`
    - `docs/INTERVIEW_SHOWCASE_GUIDE.md`
    - `docs/portfolio/CAPABILITY_MATRIX.md`
    - `docs/RELEASE_NOTES.md`
    - `docs/architecture/plantuml/threat_hunting_flow.puml`
  * **behavior observed**:
    - Runtime/test baseline expects `164` supported threat-hunting domains.
    - `README.md` and `DemoPlatformPackagingService::BASELINE_DOMAIN_COUNT` also report `164`.
    - Reviewer/operator documents still report older values: `AGENTS.md` and `CLAUDE.md` say `161`, while interview/portfolio/release docs still say `158`.
* **Why it matters**: This does not affect runtime behavior, but it gives reviewer agents and human evaluators contradictory baseline numbers. That increases false-positive audit noise and can cause demo/evaluator scripts to describe stale capability counts.
* **Existing safeguards**: Multiple feature tests assert `164`, and `DocumentationFreezeTest` checks that `README.md` contains `164`.
* **Recommended action**: Align all reviewer/evaluator-facing documents to `164` supported threat-hunting domains, or replace hard-coded counts with a reference to `ThreatHuntingService::SUPPORTED_DOMAINS` where practical.
* **Should this become a backlog item?**: No
* **Reason**: Documentation-only cleanup. It is safe to fix directly in a docs pass and does not require an implementation backlog issue.

---

### Finding DOC-2: Threat Hunting Permission Map Drift
* **Category**: D. Documentation Drift / RBAC Context Risk
* **Severity**: Low
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `routes/web.php`
    - `config/soc.php`
    - `AGENTS.md`
    - `docs/architecture/FEATURE_REGISTRY.md`
  * **behavior observed**:
    - The actual web/API threat-hunt read routes are protected by `soc:investigation.view`.
    - The execute/replay/query routes are protected by `soc:investigation.create`.
    - `AGENTS.md` lists `/threat-hunts` under `soc:hunt.view`.
    - `docs/architecture/FEATURE_REGISTRY.md` lists `/threat-hunts` under `soc:dashboard.view`.
    - `config/soc.php` does not define a `hunt.view` permission entry in the role permission arrays.
* **Why it matters**: Runtime authorization appears internally consistent, but the reviewer-facing module map is stale. Agents or humans using the docs may propose unnecessary RBAC changes or misdiagnose access behavior for threat-hunting screens.
* **Existing safeguards**: The routes use concrete middleware, and role permissions include `investigation.view` / `investigation.create` for the intended analyst workflows.
* **Recommended action**: Update reviewer and architecture documentation to reflect the current route gates: read = `soc:investigation.view`; execute/replay/API query = `soc:investigation.create`. If a dedicated `soc:hunt.view` permission is desired later, that should be treated as a separate RBAC design change, not a documentation correction.
* **Should this become a backlog item?**: No
* **Reason**: Documentation-only correction unless the product intentionally wants a dedicated hunt permission split.

---

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)
* None from this audit batch.

#### Not Backlog (Documentation-Only Cleanup)
* **DOC-1** - Align threat-hunting domain counts across reviewer/evaluator documents.
* **DOC-2** - Align threat-hunting permission maps with actual route middleware.

---

## 12. RBAC and Tenant Boundary Audit (RBAC-TENANT-AUDIT, 2026-06-26)

### Scope Analyzed
* `routes/web.php` route middleware map, with focus on `soc:*` permissions and recently added EASM / Pilot Readiness Matrix routes.
* `config/soc.php` role permission arrays.
* `app/Http/Middleware/EnsureSocPermission.php` and `app/Support/Rbac.php` permission resolution behavior.
* `app/Services/TenantContextAuthority.php` and `app/Services/TenantBoundaryService.php` tenant boundary pattern.
* `app/Http/Controllers/EasmController.php` tenant resolution and EASM ownership checks.
* `app/Http/Controllers/PilotReadinessMatrixController.php` pilot readiness matrix list/detail/report queries.
* Related feature tests for EASM, pilot readiness matrix, RBAC audit, and tenant context authority.

### Finding RBAC-1: EASM and Pilot Readiness Matrix Routes Use Permissions Missing from RBAC Config
* **Category**: A. Direct Access Regression / RBAC Misconfiguration
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `routes/web.php`
    - `config/soc.php`
    - `app/Http/Middleware/EnsureSocPermission.php`
    - `app/Support/Rbac.php`
  * **behavior observed**:
    - `routes/web.php` protects EASM routes with `soc:easm.view` and `soc:easm.scan`.
    - `routes/web.php` protects Pilot Readiness Matrix routes with `soc:pilot.readiness.view`.
    - `config/soc.php` role permission arrays do not define `easm.view`, `easm.scan`, or `pilot.readiness.view` for any role.
    - `EnsureSocPermission` calls `Rbac::can()`, and `Rbac::can()` only returns true when the exact permission string exists in the configured role permissions.
* **Why it matters**: These routes are effectively locked out for every authenticated user, including admins. The EASM UI/API and Pilot Readiness Matrix UI become unreachable through the web layer despite the services, controllers, views, migrations, and tests existing.
* **Existing safeguards**: The failure mode is deny-by-default, so this is not an authorization bypass. It is still a functional availability regression for security operations screens.
* **Recommended action**:
  - Add `easm.view`, `easm.scan`, and `pilot.readiness.view` to the intended roles in `config/soc.php`, or retarget the routes to already-existing permissions if that is the product decision.
  - Add route-level feature tests that assert an admin can access `/soc/easm`, can submit EASM passive scan actions as intended, and can access `/soc/pilot/readiness-matrix`.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[RBAC] Add missing EASM and Pilot Readiness Matrix permissions to role config`

---

### Finding EASM-1: EASM Controller Trusts Raw Tenant Input Instead of TenantContextAuthority
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Http/Controllers/EasmController.php`
    - `app/Services/EasmPassiveScanService.php`
    - `app/Services/TenantContextAuthority.php`
  * **behavior observed**:
    - `EasmController::resolveTenantId()` returns `X-Tenant-Id`, request input `tenant_id`, or the literal fallback `default`.
    - It does not call `TenantContextAuthority::validateAndResolve()` and does not validate the requested tenant against `user_tenant_memberships`.
    - `EasmPassiveScanService::validateOwnership()` correctly checks asset ownership against the tenant ID it receives, but that tenant ID is supplied directly from untrusted request input.
* **Why it matters**: Once RBAC-1 is fixed and the routes become reachable, a user can select another tenant by sending `X-Tenant-Id` or `tenant_id` and then list/register/read EASM assets under that tenant context. The service ownership guard is not sufficient because the tenant selector was never authorized.
* **Existing safeguards**:
  - EASM validates asset ownership against the supplied tenant ID.
  - EASM is advisory-only and passive, so the blast radius is data exposure and unauthorized advisory scan dispatch, not containment or destructive action.
* **Recommended action**:
  - Inject and use `TenantContextAuthority` in `EasmController`.
  - Use `requireTenantContext: true` on read/object routes.
  - Use `requireTenantContext: true` and `requireExplicitScope: true` on store/scan routes where tenant-scoped records or actions are created.
  - Remove request-body `tenant_id` fallback for user-facing routes unless it is validated through the authority layer.
  - Add feature tests for cross-tenant spoof rejection and missing-tenant behavior under strict mode.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[EASM] Enforce TenantContextAuthority in EASM controller tenant resolution`

---

### Finding PILOT-1: Pilot Readiness Matrix Controller Lists and Reports Runs Without Tenant Scoping
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Http/Controllers/PilotReadinessMatrixController.php`
    - `app/Models/PilotReadinessMatrixRun.php`
    - `app/Services/EnterprisePilotReadinessMatrixService.php`
  * **behavior observed**:
    - `index()` calls `PilotReadinessMatrixRun::orderByDesc(...)->paginate(20)` without filtering by tenant.
    - `show()` and `report()` call `where('matrix_run_id', $runId)->firstOrFail()` without validating that the run belongs to the request tenant.
    - `PilotReadinessMatrixRun` has a required `tenant_id` field, and the generated report includes that tenant ID, so cross-tenant leakage is possible if routes are reachable.
* **Why it matters**: After RBAC-1 is fixed, any user with the matrix view permission can enumerate and fetch readiness matrix runs across tenants. These records include pilot readiness status, gate outcomes, scope, tenant ID, and operational evidence references.
* **Existing safeguards**: Current RBAC-1 lockout prevents route access, but that is an accidental deny-all state, not a valid tenant isolation control.
* **Recommended action**:
  - Resolve tenant context through `TenantContextAuthority`.
  - Filter `index()` by tenant context and assert object access on `show()` / `report()`.
  - Add feature tests with two tenants proving list/detail/report isolation and cross-tenant denial.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[PILOT] Scope Pilot Readiness Matrix routes by validated tenant context`

---

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)
* **[RBAC] Add missing EASM and Pilot Readiness Matrix permissions to role config** (RBAC-1)
* **[EASM] Enforce TenantContextAuthority in EASM controller tenant resolution** (EASM-1)
* **[PILOT] Scope Pilot Readiness Matrix routes by validated tenant context** (PILOT-1)

#### Not Backlog
* None from this audit batch.

---

## 19. Log Connector Reliability and Trust-Boundary Audit (LOG-CONNECTOR-AUDIT-1, 2026-07-11)

### Scope Analyzed

* `services/log-connector-syslog/main.go` and its CEF/LEEF/registry tests.
* `services/log-connector-cloudtrail/main.go` and CloudTrail parser/state tests.
* `services/log-connector-guardduty/main.go` and GuardDuty parser/state tests.
* `services/log-connector-gcp-audit/main.go` and GCP Audit parser/state tests.
* Connector Dockerfiles, `docker-compose.yml`, connector README files, and the ingestion gateway tenant-resolution path.

### Finding CONN-DELIVERY-LOSS: Connector Batches Are Discarded Before Delivery Is Confirmed

* **Category**: Reliability / Data Integrity
* **Severity**: Critical
* **Confidence**: High
* **Evidence**:
  * All four connectors assign `c.buffer = nil` before calling `forward()`. A network error, timeout, HTTP 429, or HTTP 5xx is only logged; the failed batch is never requeued or persisted.
  * The three file connectors additionally set `processedFiles[path] = true` and call `saveState()` immediately after parsing, before any ingest request succeeds.
  * On the next scan or after restart, the persisted path is skipped even though its events may never have reached `telemetry.raw`.
  * Existing tests verify that `forward()` returns an error and increments a counter, but do not verify retry, requeue, checkpoint ordering, or recovery after a failed scan.
* **Why it matters**: A brief ingestion-gateway or network outage can silently create permanent telemetry gaps. For file connectors, the source file remains present but is suppressed by the premature checkpoint, making recovery impossible without manually editing state.
* **Recommended action**: Introduce bounded durable spooling or at minimum requeue failed batches with retry/backoff; checkpoint a file only after every batch derived from that file receives an accepted response; make shutdown wait for an acknowledged flush; add outage/restart tests that prove at-least-once delivery and deterministic deduplication.

### Finding CONN-UNTENANTED-INGEST: Connector Deployment Omits Tenant Attribution and the Gateway Accepts It

* **Category**: Tenant Isolation / Configuration Safety
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * Each connector only adds `tenant_id` when its connector-specific tenant environment variable is non-empty.
  * `docker-compose.yml` configures none of `XDR_SYSLOG_TENANT_ID`, `XDR_CLOUDTRAIL_TENANT_ID`, `XDR_GUARDDUTY_TENANT_ID`, or `XDR_GCP_AUDIT_TENANT_ID`.
  * The connectors do not send `X-Tenant-ID` as an alternative.
  * `ingestion-gateway.tenantAllowed("")` returns `true`, so unattributed batches are accepted and bypass per-tenant rate limiting.
* **Why it matters**: In the supplied deployment, connector telemetry enters the shared pipeline without an ownership boundary. This prevents reliable tenant-scoped search/correlation and can mix security evidence across customers.
* **Recommended action**: Require a non-empty tenant identity at connector startup in multi-tenant/production mode, include it in every signed event, set explicit connector tenant configuration in deployment manifests, and reject unattributed ingest at the gateway when strict tenant mode is enabled.

### Finding CONN-UNBOUNDED-FILE: Cloud Export Connectors Read Entire Files Into Memory Without a Size Limit

* **Category**: Availability / Resource Exhaustion
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * CloudTrail, GuardDuty, and GCP Audit connectors call `os.ReadFile(path)` for every candidate file.
  * Their parsers may then decompress using `io.ReadAll`, creating another unbounded in-memory representation. No compressed-size or decompressed-size ceiling is enforced.
  * Files originate from operator-synchronized external buckets and can be unexpectedly large or compression bombs.
* **Why it matters**: One oversized or highly compressible file can exhaust the connector container's memory, trigger repeated restart loops, and stop ingestion for all later files in that directory.
* **Recommended action**: Stream records from bounded readers, enforce both compressed and decompressed byte limits, quarantine oversized/poison files with an auditable reason, and add memory-bound tests using oversized and high-compression fixtures.

### Finding SYSLOG-TCP-ADMISSION: Syslog TCP Listener Has No Connection Limit or Read Deadline

* **Category**: Availability / Network Hardening
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * Every accepted TCP connection launches `go c.handleTCPConn(conn)` with no semaphore or maximum concurrent connection count.
  * `handleTCPConn()` loops on `bufio.Scanner` without setting a read/idle deadline.
  * An unauthenticated client can keep many partial connections open indefinitely. The Compose profile publishes port `5140/tcp` on the host.
* **Why it matters**: Slow or abandoned clients can consume file descriptors and goroutines until legitimate log sources cannot connect.
* **Recommended action**: Add a bounded connection semaphore, configurable idle/read deadlines, temporary-error accept backoff, connection metrics, and saturation/slow-client tests. Network allowlisting or authenticated TLS should be part of production deployment hardening.

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)

* **[CONNECTOR] Implement acknowledged, retryable, restart-safe connector delivery** (CONN-DELIVERY-LOSS)
* **[TENANCY] Require tenant attribution for all connector telemetry** (CONN-UNTENANTED-INGEST)
* **[CONNECTOR] Stream and bound cloud-export file ingestion** (CONN-UNBOUNDED-FILE)
* **[SYSLOG] Bound TCP connections and enforce idle/read deadlines** (SYSLOG-TCP-ADMISSION)

#### Not Backlog

* Public connector health/metrics endpoints expose aggregate counters only; no sensitive payload exposure was confirmed in this pass.
* The shared `XDR_INGEST_SECRET` is already covered by the broader internal mTLS/shared-secret backlog and is not duplicated here.

---

## 13. Internal Auth and Microservice Exposure Audit (INTERNAL-AUTH-EDGE-AUDIT-1, 2026-06-26)

### Scope Analyzed
* Laravel internal auth: `InternalAuthService`, `InternalServiceAuthMiddleware`, `/api/internal/status`, and `SecurityHardeningTest`.
* Microservice internal auth posture: `services/normalizer-worker/main.go`, `services/correlation-worker/main.go`, `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py`.
* Service exposure and production overlay: `docker-compose.yml`, `docker-compose.prod.yml`, `.env.example`, `docs/guides/LIMITATIONS_AND_CLAIMS.md`.
* Validation coverage: `scripts/validate_live_xdr_pipeline.py`, `scripts/xdr_production_profile_validate.py`, `tests/xdr_topic_bootstrap/test_internal_auth_coverage.py`, `tests/xdr_topic_bootstrap/test_xdr_production_profile_validate.py`.

### Finding INT-AUTH-1: Production Compose Overlay Claims Pipeline Services Have No External Exposure But Does Not Reset Ports 8092-8096
* **Category**: A. Deployment Boundary / Exposure Drift
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `docker-compose.yml`
    - `docker-compose.prod.yml`
    - `docs/guides/LIMITATIONS_AND_CLAIMS.md`
    - `scripts/xdr_production_profile_validate.py`
  * **behavior observed**:
    - Base `docker-compose.yml` publishes internal pipeline services on host ports: normalizer `8092:8092`, correlation `8093:8093`, AI/RAG `8094:8094`, alert-writer `8095:8095`, and incident-builder `8096:8096`.
    - `docker-compose.prod.yml` states "Pipeline services (8092-8096) - no external exposure", but only resets ports for Redpanda, datastores, and Grafana.
    - The production overlay sets `XDR_ENFORCE_INTERNAL_AUTH=true` for normalizer, correlation, alert-writer, and incident-builder, but it does not remove their inherited host port bindings. It also does not define/reset `ai-rag-service` in the overlay.
    - `scripts/xdr_production_profile_validate.py` checks datastore ports and Pandaproxy exposure, but it does not check pipeline service host port exposure, so the validation suite can pass while this claim is false.
* **Why it matters**: In a merged production compose (`docker-compose.yml` + `docker-compose.prod.yml`), internal services can remain reachable from the host network despite the production profile's stated boundary. Authenticated write/process endpoints are safer when enforcement is enabled, but unauthenticated `/health`, `/metrics`, and some `/dlq` endpoints remain externally reachable. This increases information disclosure and attack surface for services that process alerts, incidents, correlation data, and AI evidence.
* **Existing safeguards**:
  - Production overlay enforces token auth for mutating internal endpoints on normalizer, correlation, alert-writer, and incident-builder.
  - Datastore and Redpanda port exposure is explicitly hardened in the overlay.
* **Recommended action**:
  - Add `ports: !reset []` for `normalizer-worker`, `correlation-worker`, `ai-rag-service`, `alert-writer-service`, and `incident-builder-service` in `docker-compose.prod.yml`, or bind them to `127.0.0.1` only if host access is required.
  - Extend `xdr_production_profile_validate.py` to fail production validation when 8092-8096 are public or inherited from base compose.
  - Add tests against actual production compose parsing/merged config expectations.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[INFRA] Remove production host exposure for internal pipeline service ports 8092-8096`

---

### Finding INT-AUTH-2: Alert Writer and Incident Builder Expose DLQ Contents Without Internal Auth
* **Category**: B. Internal Data Disclosure Risk
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `services/alert-writer-service/main.py`
    - `services/incident-builder-service/main.py`
    - `docker-compose.yml`
    - `docker-compose.prod.yml`
  * **behavior observed**:
    - `alert-writer-service` exposes `GET /dlq` without checking `X-Internal-Service-Token`.
    - `incident-builder-service` exposes `GET /dlq` without checking `X-Internal-Service-Token`.
    - `alert-writer-service` appends failed alert payloads to in-memory `DLQ`, including trace IDs, error strings, and up to 20 alert payload dumps in some write failure paths.
    - `incident-builder-service` appends failed incident build inputs to its in-memory `DLQ`.
    - Base compose publishes alert-writer and incident-builder ports to the host, and the production overlay does not reset those ports.
* **Why it matters**: DLQ payloads can contain alert evidence, raw event-derived fields, actor identifiers, tenant context, trace IDs, and operational error details. If ports are reachable from an operator network or accidentally exposed host, unauthenticated `/dlq` leaks recent failure payloads even when `/v1/write`, `/v1/process`, and `/v1/build` are protected.
* **Existing safeguards**:
  - The DLQ is in-memory and bounded by process lifetime in the inspected path.
  - Production overlay disables some optional DLQ consumers, reducing some sources of DLQ growth.
* **Recommended action**:
  - Protect `/dlq` with the same internal auth enforcement used for write/process/build endpoints, or remove the endpoint from production.
  - Redact alert/incident payloads before returning DLQ items.
  - Add tests that `/dlq` requires `X-Internal-Service-Token` when `XDR_ENFORCE_INTERNAL_AUTH=true`.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[PIPELINE] Require internal auth and redaction for alert/incident DLQ debug endpoints`

---

### Finding INT-AUTH-3: Internal Auth Implementations Are Split Between Time-Bounded Laravel HMAC Tokens and Static Microservice Tokens
* **Category**: D. Design Drift / Operational Clarity
* **Severity**: Low
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Services/InternalAuthService.php`
    - `app/Http/Controllers/Security/SecurityHardeningController.php`
    - `services/normalizer-worker/main.go`
    - `services/correlation-worker/main.go`
    - `services/alert-writer-service/main.py`
    - `services/incident-builder-service/main.py`
    - `.env.example`
  * **behavior observed**:
    - Laravel `/api/internal/*` uses `InternalAuthService::signToken(serviceId)` and verifies a time-bounded HMAC token.
    - Pipeline microservices compare `X-Internal-Service-Token` directly to static per-service env vars such as `XDR_NORMALIZER_INTERNAL_TOKEN` and `XDR_ALERT_WRITER_INTERNAL_TOKEN`.
    - `SecurityHardeningController` labels Laravel internal API as "Time-bounded HMAC token" and microservice auth as "Optional internal token", but the global docs and AGENTS summary can read as if all internal service auth shares the same HMAC token mechanism.
* **Why it matters**: This is not a direct exploit by itself, but it creates operator confusion and makes token rotation/expiry expectations unclear. A static leaked microservice token remains valid until manually rotated, unlike Laravel's 5-minute HMAC tokens.
* **Existing safeguards**:
  - Static tokens are independent per service.
  - Validators require strong token values for production-like profiles.
* **Recommended action**:
  - Document the two token modes explicitly in AGENTS/ops docs.
  - Consider converging microservices on the same time-bounded HMAC token format, or clearly name the static token header as a distinct scheme.
* **Should this become a backlog item?**: No, unless the project decides to standardize the auth scheme.

---

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)
* **[INFRA] Remove production host exposure for internal pipeline service ports 8092-8096** (INT-AUTH-1)
* **[PIPELINE] Require internal auth and redaction for alert/incident DLQ debug endpoints** (INT-AUTH-2)

#### Not Backlog
* **INT-AUTH-3** is design drift and documentation clarity unless the platform wants a single internal auth mechanism across Laravel and polyglot services.

---

## 14. Response, SOAR, and Endpoint Command Boundary Audit (RESPONSE-SOAR-AUDIT-1, 2026-06-26)

### Scope Analyzed
* Legacy SOC response workflow: `routes/web.php`, `SocResponseController`, `SocResponseWorkflowTest`, `SocContainmentEnterpriseReadinessTest`.
* Legacy agent management command path: `SocAgentController`, `AgentIngestionController`, `agent_commands` migration, `EndpointAgentApiTest`, `SocAgentManagementTest`.
* Endpoint response approval framework: `EndpointResponseController`, `EndpointResponseCommandService`, `EndpointResponseCommand`, `EndpointAgentApiController`, `EndpointResponseCommandTest`.
* Endpoint agent command runtime: `services/endpoint-agent/agent.py`, `tests/endpoint_agent/test_agent.py`.
* Response planning and active response: `ResponsePlanningService`, `ResponsePlanController`, `ResponseExecution`, `ActiveResponseExecutionService`, `ActiveResponseController`, `ResponsePlanningTest`, `ActiveResponseExecutionTest`.

### Finding RESP-1: Legacy Agent Command Paths Bypass the New Endpoint Response Approval Framework
* **Category**: A. Response Control Boundary Bypass
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Http/Controllers/SocAgentController.php`
    - `app/Http/Controllers/SocResponseController.php`
    - `app/Http/Controllers/AgentIngestionController.php`
    - `app/Models/EndpointResponseCommand.php`
    - `app/Services/EndpointResponseCommandService.php`
    - `services/endpoint-agent/agent.py`
  * **behavior observed**:
    - `EndpointResponseCommand::ALLOWED_TYPES` permits only `noop`, `collect_diagnostics`, `refresh_config`, and `upload_health_snapshot`.
    - `EndpointResponseCommandService::createCommand()` enforces that allowlist and blocks destructive/unsupported command types.
    - `SocAgentController::queueCommand()` still writes directly to `agent_commands` with legacy command types: `collect-now`, `flush-local-queue`, `rotate-agent-secret`, `refresh-policy`, and `restart-agent-loop`.
    - `SocResponseController::decide()` also writes directly to `agent_commands` after approval, bypassing `EndpointResponseCommandService`, `endpoint_response_command_events`, the `CMD-YYYY-NNNNN` ID convention, and the new command state machine.
    - `AgentIngestionController::config()` delivers queued legacy `agent_commands` through the older signed `/api/agents/config` path.
    - The current Python endpoint agent command executor only supports the new underscore command types and rejects unsupported legacy dash command types.
* **Why it matters**: The platform now has two command systems with different allowlists, approval semantics, audit trails, IDs, and agent delivery APIs. Operators can still create commands outside the documented Endpoint Response Approval Framework, including command types that are not in the current safe allowlist. Depending on which agent API/runtime is used, those commands either execute through the legacy path or fail as unsupported, creating both a safety bypass and an operational reliability gap.
* **Existing safeguards**:
  - Legacy routes require authenticated SOC permissions (`soc:agents.manage` or `soc:workflow.execute`).
  - Legacy containment actions in `SocResponseController` are simulated and do not write `agent_commands`.
  - The modern endpoint agent rejects unsupported command types when received through the new command API.
* **Recommended action**:
  - Deprecate direct writes to `agent_commands` from web controllers.
  - Route all endpoint command creation through `EndpointResponseCommandService`.
  - Map legacy safe command names to the new allowlist only where behavior is equivalent, or reject them with a migration notice.
  - Add regression tests proving `SocAgentController` and `SocResponseController` cannot create commands outside `EndpointResponseCommand::ALLOWED_TYPES`.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[RESPONSE] Route legacy SOC agent commands through EndpointResponseCommandService`

---

### Finding AGENT-API-1: New Endpoint Command Poll/Ack/Result API Does Not Enforce Agent Signature Before Disclosure or Mutation
* **Category**: A. Agent API Authentication Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `routes/web.php`
    - `app/Http/Controllers/Api/EndpointAgentApiController.php`
    - `app/Services/EndpointResponseCommandService.php`
    - `tests/Feature/EndpointResponseCommandTest.php`
    - `services/endpoint-agent/agent.py`
  * **behavior observed**:
    - `routes/web.php` exposes `/api/agents/{agentId}/commands`, `/ack`, and `/result` without auth middleware because these are agent-facing routes.
    - `EndpointAgentApiController::pollCommands()` returns dispatched commands by `agentId` without validating `X-Agent-Signature`.
    - `acknowledgeCommand()` and `commandResult()` pass the request signature to `EndpointResponseCommandService`.
    - `EndpointResponseCommandService::acknowledge()` and `receiveResult()` log a `signature_failure` hardening event when validation fails, but still update command state to acknowledged/completed/failed.
    - `EndpointResponseCommandTest::test_acknowledge_with_invalid_signature_logs_hardening_event()` explicitly asserts HTTP 200 for an invalid ack signature, so this behavior is covered as current expected behavior rather than an accidental edge case.
    - The Python agent signs ack/result requests, which indicates signatures are available for enforcement.
* **Why it matters**: Anyone who can reach these routes and knows or guesses an `agent_id` can read dispatched commands for that agent. Anyone who knows a command ID can spoof acknowledgement or result submission; invalid signatures are logged but do not prevent state mutation. That can hide unexecuted commands, mark commands failed/completed incorrectly, and pollute endpoint response audit state.
* **Existing safeguards**:
  - Command IDs use the `CMD-YYYY-NNNNN` format in the new framework and are not trivially exposed to unauthenticated users.
  - The command allowlist limits command impact, so this is primarily command confidentiality, integrity, and audit correctness rather than arbitrary execution.
* **Recommended action**:
  - Require valid agent authentication for poll, ack, and result routes before returning or mutating command state.
  - Reuse the stronger per-agent secret verification pattern from `AgentIngestionController::verifiedAgent()` or move it into a shared middleware/service.
  - Keep signature failure hardening logs, but return `401/403` and do not transition command state on invalid signatures.
  - Add tests for unauthenticated poll denial and invalid ack/result denial.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[AGENT-API] Enforce agent signatures on endpoint command poll, ack, and result APIs`

---

### Finding RESP-2: Active Response and Response Planning Are Mostly Advisory/Operator-Recorded, But Route Permission Semantics Should Be Clarified
* **Category**: D. Design Clarity / Authorization Semantics
* **Severity**: Low
* **Confidence**: Medium
* **Evidence**:
  * **files**:
    - `routes/web.php`
    - `app/Services/ResponsePlanningService.php`
    - `app/Services/ActiveResponseExecutionService.php`
    - `app/Http/Controllers/Response/ActiveResponseController.php`
    - `tests/Feature/ActiveResponseExecutionTest.php`
    - `tests/Feature/ResponsePlanningTest.php`
  * **behavior observed**:
    - Response planning actions are `recommend_*` and documented as no-execution.
    - Active response requires approval, simulation, and execution-ready state before `executeAction()`.
    - `executeAction()` records operator confirmation and appends audit evidence; it does not call external identity, firewall, DNS, EDR, or shell execution APIs.
    - The route for `/active-response/{id}/execute` is protected by `soc:response.create`, while approval routes use `soc:response.approve`.
* **Why it matters**: The implementation currently aligns with "operator-recorded" execution rather than autonomous execution. The remaining concern is naming/permission clarity: a user with create permission can record final execution after approval. That may be intentional, but it should be explicit because the route name and status `executed` can look like privileged active containment.
* **Existing safeguards**:
  - Creator self-approval is blocked.
  - High-impact active response actions require dual approval.
  - Simulation is required before execution.
  - Execution is recorded as manual/operator-confirmed, with no infrastructure mutation.
* **Recommended action**:
  - Document whether `soc:response.create` is intended to include manual execution confirmation.
  - If not intended, move execute/rollback mutation routes to a dedicated permission such as `soc:response.execute`.
  - Add route-level tests for the chosen permission model.
* **Should this become a backlog item?**: No, not until product authorization semantics are decided.

---

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)
* **[RESPONSE] Route legacy SOC agent commands through EndpointResponseCommandService** (RESP-1)
* **[AGENT-API] Enforce agent signatures on endpoint command poll, ack, and result APIs** (AGENT-API-1)

#### Not Backlog
* **RESP-2** is a permission semantics clarification. The implementation remains manual/operator-recorded and does not autonomously mutate infrastructure, so it should not become a hardening task without a product decision.

---

## 15. AI and RAG Boundary Audit (AI-RAG-AUDIT-2, 2026-06-26)

### Scope Analyzed
* Laravel AI request flow: `routes/web.php`, `SocAiController`, `AiAnalystManager`, `LocalAiAnalystProvider`, `RemoteLlmProvider`, `AiRagServiceProvider`, `AiGuardrails`.
* RAG and knowledge-base flow: `SocKnowledgeRetriever`, `SocKnowledgeBaseController`, `soc_knowledge_base`, `soc_knowledge_embeddings`, `rag_retrieval_runs`.
* AI persistence schema: `ai_analyst_suggestions`, `ai_execution_history`, `ai_prompt_templates`, `ai_guardrail_events`, `ai_evaluation_runs`.
* AI/RAG microservice: `services/ai-rag-service/main.py`, service README, and Docker exposure in `docker-compose.yml` / `docker-compose.prod.yml`.
* Related tests: `SocLlmRagGuardrailTest`, `SocAiKnowledgeMaturityTest`, `SocExternalTiAdvancedRagAiEvalTest`.

### Finding AI-1: AI/RAG FastAPI Service Exposes Analysis, Retrieval, and Embedding Endpoints Without Auth
* **Category**: A. Service Trust Boundary Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `services/ai-rag-service/main.py`
    - `docker-compose.yml`
    - `docker-compose.prod.yml`
  * **behavior observed**:
    - `main.py` registers `/v1/analyze`, `/v1/retrieve`, and `/v1/embed` without token validation, HMAC verification, or caller authentication.
    - `docker-compose.yml` publishes the service as `8094:8094`.
    - `docker-compose.prod.yml` does not override/reset the `ai-rag-service` port mapping, so the base host exposure remains in effect when this service is deployed from the composed config.
* **Why it matters**: Any host with network access to port 8094 can submit arbitrary analysis, retrieval, or embedding requests. Even though the current service is heuristic, these endpoints form the AI analysis boundary and can process incident evidence. This bypasses Laravel RBAC, audit attribution, and internal service auth posture.
* **Existing safeguards**: The service is only under the `strangler` profile in base compose, and the heuristic implementation does not trigger autonomous response.
* **Recommended action**:
  - Add internal service token auth to AI/RAG endpoints, aligned with `XDR_ENFORCE_INTERNAL_AUTH` and existing internal auth conventions.
  - Restrict local compose exposure to `127.0.0.1:8094:8094` or remove host port exposure where only container-to-container calls are needed.
  - Add tests that unauthenticated `/v1/analyze`, `/v1/retrieve`, and `/v1/embed` requests are rejected when internal auth is enforced.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[AI-RAG] Require internal auth for AI/RAG service endpoints and restrict host exposure`

---

### Finding AI-2: AI Incident Generate/Review Paths Are Not Tenant-Scoped
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `routes/web.php`
    - `app/Http/Controllers/SocAiController.php`
    - `app/Support/AiAnalystManager.php`
    - `database/migrations/2026_06_24_0500001_add_tenant_id_to_alerts_incidents.php`
  * **behavior observed**:
    - `SocAiController::generate()` checks only that `security_incidents.incident_id` exists.
    - `AiAnalystManager::incidentContext()` fetches `security_incidents`, `security_alerts`, IOC hits, and knowledge refs by incident ID without `TenantContextAuthority` or `TenantBoundaryService`.
    - `SocAiController::review()` updates `ai_analyst_suggestions` by `suggestion_id` globally, without confirming the reviewer can access the target incident.
    - `security_alerts` and `security_incidents` now have `tenant_id`, but AI suggestions do not store tenant context and AI routes do not assert incident tenant access.
* **Why it matters**: In strict or future multi-tenant mode, a user with `soc:workflow.execute` can generate AI summaries for another tenant's incident ID and review another tenant's AI suggestion if the IDs are known or guessed. The generated suggestion and incident note can also write cross-tenant AI-derived content into the incident workflow.
* **Existing safeguards**: Route middleware requires authenticated users with `soc:workflow.execute`; this limits the caller population but does not enforce tenant isolation.
* **Recommended action**:
  - Resolve tenant context in `SocAiController` before generation/review.
  - Assert access to the target `security_incidents.tenant_id` before calling `AiAnalystManager`.
  - Store `tenant_id` on `ai_analyst_suggestions` or derive and enforce it consistently from the target incident.
  - Add two-tenant tests for generate and review denial.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[AI] Enforce tenant context on AI generation and suggestion review`

---

### Finding AI-3: AI/RAG Service Receives Unredacted Alert Evidence and Raw Event Fields
* **Category**: B. Conditional Data Exposure Risk
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Support/AiRagServiceProvider.php`
    - `app/Support/AiAnalystManager.php`
    - `app/Support/TraceRedactor.php`
  * **behavior observed**:
    - `AiAnalystManager::incidentContext()` loads full alert rows for the target incident.
    - `AiRagServiceProvider::generate()` maps each alert into outbound `evidence` by merging the full alert array with decoded evidence-derived fields.
    - The outbound request to `/v1/analyze` sends that evidence to the AI/RAG service without `TraceRedactor` or an AI-specific redaction allowlist.
    - `TraceRedactor` exists for presentation/API trace views but is not used before AI/RAG service calls.
* **Why it matters**: Alert rows can include `evidence`, `raw_event`, actor identifiers, IPs, emails, tokens, payloads, or other sensitive telemetry. When `SOC_AI_SERVICE_ENABLED=true`, these fields leave the Laravel process for a separate service endpoint. If the AI/RAG URL is remote or exposed, this becomes a data minimization and secret leakage risk.
* **Existing safeguards**:
  - Default `SOC_AI_SERVICE_ENABLED=false` in `.env.example`.
  - Local remote LLM prompts use compact context, so this specific exposure is strongest for the standalone AI/RAG service path.
* **Recommended action**:
  - Build an AI evidence projection that allowlists only required fields.
  - Apply deep redaction to JSON evidence/raw fields before outbound AI service calls.
  - Add tests with synthetic tokens/emails in `raw_event` and `evidence` proving the AI/RAG request payload is redacted.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[AI-RAG] Redact and minimize alert evidence before AI/RAG service calls`

---

### Finding RAG-2: Local Retrieval Citations Are Stored But Not Supplied to Remote LLM Prompts
* **Category**: D. Grounding / Correctness Gap
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Support/AiAnalystManager.php`
    - `app/Support/SocKnowledgeRetriever.php`
    - `app/Support/RemoteLlmProvider.php`
  * **behavior observed**:
    - `AiAnalystManager::generateForIncident()` retrieves local citations and stores them in `$context['retrieval_citations']`.
    - `renderPrompt()` serializes only `compactContext()`, which includes `retrieval_citation_count` but not citation titles/excerpts/content.
    - `RemoteLlmProvider` sends the rendered prompt to the configured remote provider, so the model cannot inspect the retrieved knowledge excerpts.
    - The final suggestion still stores `retrieval_citations`, which can make the output appear citation-backed even though the generated text was not grounded on the citation content.
* **Why it matters**: This weakens the central RAG claim. Citation metadata is attached after generation, but remote generation is not actually grounded in retrieved knowledge. Analysts may over-trust outputs marked with citations.
* **Existing safeguards**: `AiGuardrails` adds warnings when citations are empty, but it does not detect the case where citations exist but were not included in the prompt.
* **Recommended action**:
  - Include bounded citation excerpts in the prompt context for remote providers.
  - Track `citations_in_prompt=true/false` in `ai_execution_history.prompt_trace`.
  - Add a regression test proving the remote provider prompt includes selected citation excerpts or explicit citation IDs.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[RAG] Include retrieved citation excerpts in remote LLM prompt context`

---

### Finding KB-1: SOC Knowledge Base Is Global and Not Tenant-Scoped
* **Category**: B. Conditional Tenant Data Exposure Risk
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `app/Http/Controllers/SocKnowledgeBaseController.php`
    - `app/Support/SocKnowledgeRetriever.php`
    - `database/migrations/2026_05_12_000008_create_ai_knowledge_maturity_tables.php`
  * **behavior observed**:
    - `soc_knowledge_base` has no `tenant_id` column.
    - `SocKnowledgeBaseController::index()` lists/searches the entire knowledge base.
    - `SocKnowledgeRetriever::retrieveLocal()` scans the newest 500 entries globally.
    - Knowledge entries can be linked to `related_incident_id`, `related_rule_id`, and `related_ioc_id`.
* **Why it matters**: Analyst notes and lessons learned can contain tenant-specific investigative context. In a multi-tenant pilot, global retrieval can surface one tenant's notes as citations for another tenant's incident when terms or rule IDs overlap.
* **Existing safeguards**: Current platform posture documents single-tenant/academic-pilot assumptions, and the knowledge base is treated as analyst-authored SOC context.
* **Recommended action**:
  - Decide whether `soc_knowledge_base` is global-only reference material or tenant-scoped operational knowledge.
  - If tenant-scoped, add `tenant_id`, validate context on create/search/retrieve, and filter retrieval by current tenant plus explicitly global entries.
  - If global-only, block `related_incident_id` tenant-specific entries or mark them with a scope field.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[AI-KB] Define and enforce tenant scope for SOC knowledge base retrieval`

---

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)
* **[AI-RAG] Require internal auth for AI/RAG service endpoints and restrict host exposure** (AI-1)
* **[AI] Enforce tenant context on AI generation and suggestion review** (AI-2)
* **[AI-RAG] Redact and minimize alert evidence before AI/RAG service calls** (AI-3)
* **[RAG] Include retrieved citation excerpts in remote LLM prompt context** (RAG-2)
* **[AI-KB] Define and enforce tenant scope for SOC knowledge base retrieval** (KB-1)

#### Not Backlog
* None from this audit batch.

---

## 17. Batch 10 — Tenancy, Ingest, and Testing Gaps (2026-06-28)

### Finding TC-1: Multi-Tenant Controller Authorization Bypass on Core SOC Modules
* **Category**: A. Direct Isolation Failure
* **Severity**: Critical
* **Confidence**: High
* **Evidence**:
  * **file**: [app/Http/Controllers/SocIncidentController.php](file:///D:/project/Detector/app/Http/Controllers/SocIncidentController.php), [app/Http/Controllers/SecurityAlertController.php](file:///D:/project/Detector/app/Http/Controllers/SecurityAlertController.php), [app/Http/Controllers/SocDashboardController.php](file:///D:/project/Detector/app/Http/Controllers/SocDashboardController.php), [app/Http/Controllers/SocApiController.php](file:///D:/project/Detector/app/Http/Controllers/SocApiController.php)
  * **behavior observed**: The controllers retrieve and update incidents, alerts, dashboard statistics, and endpoint lists by calling DB queries directly via raw `DB::table(...)` statements without invoking `TenantContextAuthority` or applying `TenantBoundaryService` query scopes.
* **Why it matters**: In strict tenancy mode, any authenticated user from any tenant context can view, edit, or compromise another tenant's security incidents and alerts merely by passing the ID parameters or querying the general endpoints, completely bypassing multi-tenant scoping.
* **Conditions required for this to be a real risk**: Multi-tenant environment running with strict tenancy enforcement.
* **Existing safeguards**: None.
* **Recommended action**: Inject `TenantContextAuthority` and `TenantBoundaryService` into these controllers. Apply query scopes (e.g. `$tenantBoundary->scopeQuery()`) to list operations, and call `$tenantBoundary->assertAccess()` for detail and update operations.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[CONTROLLERS-TENANCY] Enforce tenant context boundaries and query scoping in all core SOC controllers`
* **Tests required if implemented**: Feature tests simulating multi-tenant requests and verifying that mismatched `X-Tenant-ID` headers return HTTP 403 Forbidden.

---

### Finding PTS-1: Incomplete fastapi/pydantic Mocking Causes Python Test Failures
* **Category**: D. Code Smell / Test Infrastructure Failure
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **file**: [tests/alert_writer/test_alert_writer.py](file:///D:/project/Detector/tests/alert_writer/test_alert_writer.py) and [tests/incident_builder/test_incident_builder.py](file:///D:/project/Detector/tests/incident_builder/test_incident_builder.py)
  * **behavior observed**: When discovering tests via `python -m unittest discover`, the mock modules stubbed dynamically do not define `Depends`, `Header`, `HTTPException`, or `status`, causing imports from `fastapi` inside `main.py` to fail with `ImportError`.
* **Why it matters**: The python test suites for these services cannot be executed directly by developers, leaving important pipeline and DLQ recovery components untested in local and CI environments.
* **Recommended action**: Align the dynamic mock stubs in the python test files to define all required fastapi imports (e.g. `Depends`, `Header`, `HTTPException`, `status`).
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[TESTS-PIPELINE] Resolve mock stubbing import errors in python test suites`
* **Tests required if implemented**: Test execution must return exit 0.

---

### Finding DB-5-DEFECT: Mismatch in tenant_id Serialization Field Location
* **Category**: A. Direct Isolation / Lineage Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **file**: [services/alert-writer-service/main.py](file:///D:/project/Detector/services/alert-writer-service/main.py) (normalize_records)
  * **behavior observed**: `normalize_records` unmarshals rows from `xdr.alerts` topic directly into `AlertPayload(**row)`. However, the Go `correlation-worker`'s `Alert` struct does not have a top-level `tenant_id` field; it nests `tenant_id` inside the `evidence` map.
* **Why it matters**: Pydantic instantiates `tenant_id` as `None` since it is missing at the top level of the JSON payload. All postgres writes for alerts and incidents are therefore written with `tenant_id = NULL` (bypassing strict multi-tenant RLS checks).
* **Recommended action**: Update `normalize_records` in `alert-writer-service/main.py` to copy `evidence["tenant_id"]` to `row["tenant_id"]` before instantiating `AlertPayload(**row)`.
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[ALERT-WRITER] Copy nested tenant_id from evidence to top-level AlertPayload before instantiation`
* **Tests required if implemented**: Integration tests asserting that alert database records have the correct non-null `tenant_id` mapped from the correlation worker.

---

### Finding IG-DOS: Unbounded memory allocation in per-tenant rate limiter map
* **Category**: B. Conditional Risk / Denial of Service
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **file**: [services/ingestion-gateway/main.go](file:///D:/project/Detector/services/ingestion-gateway/main.go)
  * **behavior observed**: Per-tenant rate limiting lazy-initializes a `newTenantBucket` on `g.tenantLimiters` `sync.Map` for every unique `X-Tenant-ID` header.
* **Why it matters**: An attacker flooding the ingestion gateway with arbitrary or fake `X-Tenant-ID` values can grow the map boundlessly, leaking memory and exhausting CPU on each refiller tick. There is no eviction mechanism.
* **Recommended action**: Implement an LRU cache or cleanup routine that evicts tenant buckets that have not received any requests for a certain duration (e.g. 5-10 minutes).
* **Should this become a backlog item?**: Yes
* **Suggested backlog title**: `[INGESTION-GATEWAY] Implement eviction mechanism for tenant bucket rate limiters to prevent OOM`
* **Tests required if implemented**: Load tests verifying that sending thousands of unique client headers resolves with bucket eviction without leaking memory.

---

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)
* **[CONTROLLERS-TENANCY] Enforce tenant context boundaries and query scoping in all core SOC controllers** (TC-1)
* **[TESTS-PIPELINE] Resolve mock stubbing import errors in python test suites** (PTS-1)
* **[ALERT-WRITER] Copy nested tenant_id from evidence to top-level AlertPayload before instantiation** (DB-5-DEFECT)
* **[INGESTION-GATEWAY] Implement eviction mechanism for tenant bucket rate limiters to prevent OOM** (IG-DOS)

#### Not Backlog
* None from this audit batch.

---

## 18. Batch 11 — Cryptographic, Tenancy, and Rate Limiting Edge Cases (2026-06-29)

### Finding CMD-SHARED-HMAC: Shared Cryptographic Secret for Privileged EDR Command Poll/Ack/Result
* **Category**: A. Authentication / Cryptographic Weakness
* **Severity**: Critical
* **Confidence**: High
* **Evidence**:
  * **file**: [app/Services/EndpointResponseCommandService.php](file:///D:/project/Detector/app/Services/EndpointResponseCommandService.php) (verifyAgentSignature)
  * **behavior observed**: The endpoint response approval framework validates command poll/ack/result payload signatures against the static global enrollment token `config('soc.agent_enrollment_token')` instead of using the unique decrypted agent-specific `agent_secret` generated during enrollment.
* **Why it matters**: Since the enrollment token is static, shared across all endpoints, and easily retrieved from any enrolled host, any compromised host or malicious actor can forge command poll request headers, false acknowledgements, or spoofed command completion/failure results for *any other endpoint* in the system. This breaks integrity and audit controls for privileged response actions.
* **Recommended action**: Update `verifyAgentSignature` in `EndpointResponseCommandService` to query the `endpoint_agents` table by `agentId`, decrypt `agent_secret`, and verify the signature against the unique per-agent secret, aligning it with the pattern used in `AgentIngestionController::verifiedAgent()`.

---

### Finding AGENT-TENANCY-GAP: Complete Absence of Tenancy Isolation for Endpoint Fleet
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - `endpoint_agents` table migration
    - [app/Http/Controllers/AgentIngestionController.php](file:///D:/project/Detector/app/Http/Controllers/AgentIngestionController.php)
    - [app/Http/Controllers/Endpoint/EndpointController.php](file:///D:/project/Detector/app/Http/Controllers/Endpoint/EndpointController.php)
  * **behavior observed**:
    - The `endpoint_agents` table has no `tenant_id` column.
    - Enrollment relies on a global token and does not map enrolled agents to any tenant.
    - Direct telemetry ingestion via `/api/agents/telemetry` receives and writes events globally.
    - The modern Endpoint Fleet list (`EndpointController::index()`) and host detail (`EndpointController::show()`) query agents and sum shadow alerts globally.
* **Why it matters**: A tenant operator with `soc:agents.view` can monitor all enrolled agents, hardware metadata, and endpoint shadow alerts across the entire platform. This is a massive cross-tenant information exposure leak.
* **Recommended action**:
  - Add a nullable `tenant_id` column to `endpoint_agents` table.
  - Require a tenant context header during enrollment and map the agent to the corresponding tenant ID.
  - Inject `TenantContextAuthority` and `TenantBoundaryService` in `EndpointController` and apply query scoping filters so users only see hosts/alerts belonging to their tenant.

---

### Finding TENANT-UNSCOPED-TABLES: Undocumented Isolation Gaps on Core Analysis Tables
* **Category**: B. Tenancy Configuration Gap
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - [app/Services/TenantBoundaryService.php](file:///D:/project/Detector/app/Services/TenantBoundaryService.php)
    - Database migrations for investigations, response plans, threat hunts, entity graphs, SOAR orchestrations, AI/RAG summaries, and notification logs.
  * **behavior observed**: Almost 80% of database tables containing sensitive security coordinator metadata do not contain a `tenant_id` column and are not configured under `TenantBoundaryService::ISOLATED_TABLES` or `UNISOLATED_TABLES`. This includes:
    - `investigations`, `investigation_notes`, `investigation_events`, `investigation_assignments`, `investigation_artifacts`.
    - `response_plans`, `response_plan_actions`, `response_plan_approvals`.
    - `threat_hunts`, `threat_hunt_queries`, `threat_hunt_results`, `soc_hunt_sessions`, `soc_hunt_run_sessions`.
    - `soar_orchestrations`.
    - `entity_graph`, `entity_graph_relationships`.
    - `ai_analyst_suggestions`, `ai_execution_history`, `ai_prompt_templates`, `soc_knowledge_embeddings`, `ai_guardrail_events`, `soc_knowledge_base`, `detection_maturity_runs`, `detection_quality_warnings`.
    - `notification_delivery_logs`.
* **Why it matters**: These tables contain highly sensitive investigation notes, response files, active playbooks, AI findings, and threat queries. The current posture makes a secure multi-tenant SOC deployment impossible as analysts from different tenants can view, modify, or execute playbooks globally.
* **Recommended action**: Map out all analyst-level operational tables, add `tenant_id` columns, and configure them under `TenantBoundaryService::ISOLATED_TABLES` with RLS or query scoping middleware. Update `TENANT_ISOLATION_POSTURE.md` to document these gaps.

---

### Finding ENV-CACHE-DRIFT: Silent Internal Auth Fallback via Direct env() Call in config:cache Mode
* **Category**: D. Configuration Drift
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **file**: [app/Services/InternalAuthService.php](file:///D:/project/Detector/app/Services/InternalAuthService.php) (secret)
  * **behavior observed**: `InternalAuthService::secret()` queries the internal service secret directly via `env('XDR_INTERNAL_AUTH_SECRET', '')`.
* **Why it matters**: During production deployment, running `php artisan config:cache` disables direct `env()` calls outside config files, forcing them to return `null`. In this state, `InternalAuthService` silently ignores any configured `XDR_INTERNAL_AUTH_SECRET` and falls back to decoding the `APP_KEY`. This causes cryptographic token validation failures across Go/Python microservices that still expect tokens signed with the intended secret.
* **Recommended action**: Map `XDR_INTERNAL_AUTH_SECRET` to a configuration key (e.g. `config('soc.internal_auth_secret')`) in `config/soc.php` and update `InternalAuthService` to resolve the secret through the configuration repository.

---

### Finding RATE-LIMIT-BYPASS: Rate Limiting Bypass via Unauthenticated X-Tenant-ID HTTP Header
* **Category**: C. Rate Limiting Weakness
* **Severity**: Medium
* **Confidence**: High
* **Evidence**:
  * **file**: [services/ingestion-gateway/main.go](file:///D:/project/Detector/services/ingestion-gateway/main.go) (ingest)
  * **behavior observed**: The ingestion gateway parses `X-Tenant-ID` from HTTP headers to apply per-tenant rate limiting. However, the HMAC payload signature header (`X-XDR-Signature`) only signs the JSON request body and does not include the HTTP headers.
* **Why it matters**: A tenant can bypass rate limiting by sending randomized or arbitrary `X-Tenant-ID` headers with each request, while their telemetry events are still successfully parsed and written to the database under their true `tenant_id` (extracted from the signed body).
* **Recommended action**: Update the ingestion gateway to either: (a) parse the payload `tenant_id` to enforce rate limits, or (b) verify that the HTTP header `X-Tenant-ID` matches the `tenant_id` field contained within the verified JSON body.

---

### Finding NOTIFY-TENANCY-GAP: Global / Unisolated SOC Notification Delivery
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - [app/Console/Commands/SocSlaEscalationCommand.php](file:///D:/project/Detector/app/Console/Commands/SocSlaEscalationCommand.php)
    - [app/Services/SocNotifier.php](file:///D:/project/Detector/app/Services/SocNotifier.php)
    - `notification_delivery_logs` table migration
  * **behavior observed**:
    - `SocSlaEscalationCommand` triggers alerts on SLA breaches using global configuration targets (`notifications_soc.webhook_url`, `slack_url`, `discord_url`).
    - `SocNotifier::send()` delivers webhook, Slack, and Discord posts to these global targets without tenant lookup.
    - `notification_delivery_logs` logs all events globally with no `tenant_id` column.
* **Why it matters**: In a multi-tenant setup, one tenant's SLA breaches and incident summaries will be posted to the global shared channels of the operator/other tenants, causing a massive cross-tenant leakage of confidential breach details.
* **Recommended action**: Update the notification service to store and resolve tenant-specific webhooks and webhook configs from the database, and add `tenant_id` to `notification_delivery_logs`.

---

### Finding ACT-RESP-TENANCY-GAP: Unisolated Active Response Execution Subsystem
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: Critical
* **Confidence**: High
* **Evidence**:
  * **files**:
    - [app/Services/ActiveResponseExecutionService.php](file:///D:/project/Detector/app/Services/ActiveResponseExecutionService.php)
    - [app/Http/Controllers/Response/ActiveResponseController.php](file:///D:/project/Detector/app/Http/Controllers/Response/ActiveResponseController.php)
    - [app/Http/Controllers/Api/ActiveResponseApiController.php](file:///D:/project/Detector/app/Http/Controllers/Api/ActiveResponseApiController.php)
    - Database migration `database/migrations/2026_05_19_100001_create_active_response_execution_tables.php`
  * **behavior observed**:
    - None of the response execution tables (`response_executions`, `response_execution_events`, `response_execution_rollbacks`, or `response_execution_simulations`) have a `tenant_id` column.
    - These tables are completely omitted from `TenantBoundaryService::ISOLATED_TABLES`.
    - Controller and API actions query and mutate response execution records globally without tenant context routing or X-Tenant-ID header verification.
* **Why it matters**: Response executions perform high-privilege containment actions (session revocation, host isolation, account disabling, network blocking). In multi-tenant environments, the lack of tenant boundary enforcement allows a compromised tenant analyst or actor to view, simulate, approve, or execute response plans targeting other tenants' infrastructures, leading to total environment compromise.
* **Recommended action**: Add `tenant_id` to all response execution tables, register them under `TenantBoundaryService::ISOLATED_TABLES`, and refactor controllers to enforce scoping.

---

### Finding ENT-GRAPH-TENANCY-GAP: Unisolated Entity Graph Generation & Cross-Tenant Pollution
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **file**: [app/Services/EntityGraphService.php](file:///D:/project/Detector/app/Services/EntityGraphService.php)
  * **behavior observed**:
    - `EntityGraphService::upsertEntity()` and `upsertRelationship()` do not populate the `tenant_id` column when creating or updating `Entity` and `EntityRelationship` records, leaving them as `null`.
    - The projection methods `projectFromAlerts()` and `projectFromIncidents()` query database events globally and merge them into a single unified graph, colliding identical keys (such as `administrator` or `192.168.1.1`) across tenants.
* **Why it matters**: Merging entities from different tenants when their keys collide results in a single shared graph topology. This leaks infrastructure details across tenant boundaries and allows Tenant A's analysts to traverse and view Tenant B's connected assets.
* **Recommended action**: Update `EntityGraphService` to accept and populate `tenant_id` on all entities/relationships, include `tenant_id` in unique constraints (to isolate identically keyed entities), and scope all database projection queries by tenant.

---

### Finding UEBA-TENANCY-GAP: Unisolated UEBA Analytics & Baseline Collision
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **file**: [app/Services/UEBABaselineService.php](file:///D:/project/Detector/app/Services/UEBABaselineService.php)
  * **behavior observed**:
    - None of the UEBA profile, observation, or anomaly score tables (`entity_behavior_baselines`, `baseline_observations`, `baseline_anomaly_scores`, `peer_group_profiles`) carry a `tenant_id` column.
    - These tables are omitted from `TenantBoundaryService::ISOLATED_TABLES`.
    - Observations, peer group configurations, and baselines are calculated globally without tenant segmentation.
* **Why it matters**: Activity observations and peer grouping are calculated globally, causing user/host behavior baselines to be skewed by other tenants' traffic. This leaks behavior footprints and allows cross-tenant visibility of user/host anomalies.
* **Recommended action**: Add `tenant_id` columns to all UEBA tables, register them under `TenantBoundaryService::ISOLATED_TABLES`, and segment observations, baselines, and peer groups per tenant.

---

### Finding RISK-TENANCY-LEAK: Cross-Tenant Data Leak in Entity Risk Scoring
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **file**: [app/Services/EntityRiskScoringService.php](file:///D:/project/Detector/app/Services/EntityRiskScoringService.php)
  * **behavior observed**: `EntityRiskScoringService::calculateRisk()` retrieves security alerts and incidents globally based purely on the entity key (e.g. actor_key/user email, IP, hostname), without checking their tenant attribution.
* **Why it matters**: If an entity key exists in multiple tenants (e.g. `192.168.1.1` or `admin@corp.example`), the risk scoring engine will aggregate and leak alerts/incidents belonging to other tenants in the factors detail.
* **Recommended action**: Scope all data retrieval helpers (`alertsForEntity`, `incidentsForEntity`, etc.) inside `EntityRiskScoringService` by the requesting tenant ID.

---

### Finding EXPORT-TENANCY-GAP: Unisolated Report Export Service & IDOR Vulnerability
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: Critical
* **Confidence**: High
* **Evidence**:
  * **files**:
    - [app/Services/ReportExportService.php](file:///D:/project/Detector/app/Services/ReportExportService.php)
    - [app/Http/Controllers/Export/ExportController.php](file:///D:/project/Detector/app/Http/Controllers/Export/ExportController.php)
    - [app/Http/Controllers/Api/ExportApiController.php](file:///D:/project/Detector/app/Http/Controllers/Api/ExportApiController.php)
  * **behavior observed**: The report export engine and its web/API controllers fetch investigations, response plans, entity risk profiles, and traces by ID directly using raw SQL queries without verifying whether they belong to the requesting user's tenant context.
* **Why it matters**: This leads to an Insecure Direct Object Reference (IDOR) leak. Any authenticated tenant user can guess or manipulate IDs to download another tenant's security reports, raw alert logs, risk factor details, and action plans, bypassing multi-tenant scoping.
* **Recommended action**: Inject `TenantBoundaryService` or use `TenantContextAuthority` in `ReportExportService` and all export controllers to scope resource retrieval and assert that the requested records belong to the active tenant.

---

### Finding SOAR-TENANCY-GAP: Lack of Multi-Tenant Scoping in SOAR Orchestration & Playbooks
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: Critical
* **Confidence**: High
* **Evidence**:
  * **files**:
    - [app/Http/Controllers/Soar/SoarOrchestrationController.php](file:///D:/project/Detector/app/Http/Controllers/Soar/SoarOrchestrationController.php)
    - [app/Services/SoarOrchestrationService.php](file:///D:/project/Detector/app/Services/SoarOrchestrationService.php)
    - Database migration `database/migrations/2026_05_20_500001_create_soar_orchestration_tables.php`
  * **behavior observed**: None of the SOAR tables (`soar_playbooks`, `soar_playbook_versions`, `soar_execution_plans`, `soar_execution_steps`, `soar_execution_results`, `soar_approval_requests`, `soar_rollback_plans`, `soar_execution_audits`, `soar_simulation_results`) contain a `tenant_id` column. They are omitted from `TenantBoundaryService::ISOLATED_TABLES`. Web controllers query playbooks, simulations, execution plans, rollback plans, and approvals globally.
* **Why it matters**: Allows cross-tenant control plane visibility and action execution. A user from Tenant A can view, run simulations on, and approve/reject Tenant B's active response playbooks and escalation steps.
* **Recommended action**: Add `tenant_id` columns to all SOAR tables, configure them under `TenantBoundaryService::ISOLATED_TABLES`, and refactor `SoarOrchestrationController` and `SoarOrchestrationService` to filter all queries by the tenant context.

---

### Finding HUNT-TENANCY-GAP: Unisolated Threat Hunting Queries & Cross-Tenant Pivoting
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - [app/Services/ThreatHuntingService.php](file:///D:/project/Detector/app/Services/ThreatHuntingService.php)
    - [app/Http/Controllers/Security/ThreatHuntController.php](file:///D:/project/Detector/app/Http/Controllers/Security/ThreatHuntController.php)
    - [app/Http/Controllers/Api/ThreatHuntApiController.php](file:///D:/project/Detector/app/Http/Controllers/Api/ThreatHuntApiController.php)
  * **behavior observed**: `ThreatHuntingService` executes hunts and pivot searches (e.g. host, process, trace, destination IP) globally across all tenants' events without filtering. `ThreatHuntController` displays threat hunts, queries, and endpoint agents globally.
* **Why it matters**: This leaks hosts, behavioral logs, process ancestry, and network correlations of other tenants, allowing any tenant analyst to spy on other tenants' infrastructures.
* **Recommended action**: Scope all Eloquent queries inside `ThreatHuntingService::executeQuery` and pivot helpers by the active tenant ID, add `tenant_id` columns to the threat hunt tables (already registered under `ISOLATED_TABLES`), and filter listings in the controllers.

---

### Finding TRACE-TENANCY-GAP: Cross-Tenant Data Leak in Trace Investigation Search
* **Category**: A. Direct Tenant Isolation Failure
* **Severity**: High
* **Confidence**: High
* **Evidence**:
  * **files**:
    - [app/Http/Controllers/Trace/TraceInvestigationController.php](file:///D:/project/Detector/app/Http/Controllers/Trace/TraceInvestigationController.php)
    - [app/Http/Controllers/Api/TraceApiController.php](file:///D:/project/Detector/app/Http/Controllers/Api/TraceApiController.php)
  * **behavior observed**: The Trace Investigation search methods query alerts, incidents, operational events, and scenario evidence globally using trace IDs, alert/incident IDs, or IPs without applying tenant filters.
* **Why it matters**: Allows cross-tenant timeline exploration. Any tenant user can query and retrieve deep, unredacted trace timelines, associated alerts, and event steps belonging to other tenants.
* **Recommended action**: Scope the trace search and lookup queries by the tenant context, checking that the trace contains at least one alert or incident belonging to the active tenant before displaying the timeline.

---

### Backlog & Triage Assessment

#### Backlog Candidate List (Confirmed / High-Confidence Risks)
* **[TENANCY] Scope all Report Exports and History by Tenant Context** (EXPORT-TENANCY-GAP)
* **[TENANCY] Implement Tenant Isolation for SOAR Playbooks, Execution Plans, and Approvals** (SOAR-TENANCY-GAP)
* **[TENANCY] Implement Tenant Scoping for Threat Hunting Queries and Pivoting** (HUNT-TENANCY-GAP)
* **[TENANCY] Restrict Trace Investigation and Search by Tenant Context** (TRACE-TENANCY-GAP)
* **[TENANCY] Implement tenant isolation for Active Response Execution subsystem** (ACT-RESP-TENANCY-GAP)
* **[TENANCY] Implement tenant isolation for Entity Graph generation and projection** (ENT-GRAPH-TENANCY-GAP)
* **[TENANCY] Implement tenant isolation for UEBA baseline profiles and observations** (UEBA-TENANCY-GAP)
* **[TENANCY] Scope all Entity Risk Scoring data retrieval by tenant context** (RISK-TENANCY-LEAK)
* **[AGENT-API] Enforce unique per-agent secret for response command signature verification** (CMD-SHARED-HMAC)
* **[AGENT-TENANCY] Implement tenant scoping and isolation for endpoint fleet** (AGENT-TENANCY-GAP)
* **[TENANCY] Implement tenant isolation for investigations, response plans, threat hunts, and entity graphs** (TENANT-UNSCOPED-TABLES)
* **[CONFIG] Map XDR_INTERNAL_AUTH_SECRET to Laravel config to prevent cached env bypass** (ENV-CACHE-DRIFT)
* **[INGESTION-GATEWAY] Validate X-Tenant-ID header matches verified payload tenant_id** (RATE-LIMIT-BYPASS)
* **[NOTIFICATION] Implement tenant-specific lookup and isolation for webhook, Slack, and Discord alerts** (NOTIFY-TENANCY-GAP)
* **[AI-KB] Implement Qdrant vector semantic search and sentence embedding pipeline** (AI-KB-SEMANTIC)
* **[AI-KB] Build MITRE ATT&CK and threat intelligence RSS feed dynamic ingestion pipeline** (AI-KB-FEED-INGEST)
* **[AI-KB] Create closed-loop analyst feedback ingestion service for approved suggestions** (AI-KB-FEEDBACK-LOOP)
* **[AI] Implement confidence-based automated containment rules and a critical asset exclusion list** (AI-CONF-BANDS)
* **[TENANCY] Enable hard Row-Level Security (RLS) enforcement and strict tenant mode defaults** (TENANT-ENFORCE-RLS)
* **[PERF] Convert N+1 UPDATE queries in agent command retrieval loop to bulk updates** (PERF-AGENT-UPDATE)
* **[PERF] Refactor threat intel nested loop with synchronous writes to use bulk insert** (PERF-IOC-LOOP)
* **[PERF] Refactor alert suppression rule matching N+1 queries to use bulk updates and inserts** (PERF-ALERT-TUNE)
* **[REFACTOR] Remove tracked compiled Python bytecode (*.pyc) files from Git cache** (GIT-RM-PYC)
* **[PERF] Refactor ClickHouse sync daemon to use in-process polling instead of spawning python subprocesses** (PERF-SUBPROCESS-POLL)
* **[PERF] Convert N+1 database queries in agent health check schedule loop to joins/eager loading** (PERF-AGENT-HEALTH-N1)
* **[PERF] Refactor Go ingestion rate limiters to use mathematical time-delta calculations instead of channel loops** (PERF-GO-LIMITER)
* **[PERF] Refactor python HTTP client requests in alert writer and incident builder to use persistent Session pools** (PERF-PYTHON-HTTP)
* **[PERF] Wrap sequential Laravel write operations in database transactions to preserve data integrity** (PERF-TRANSACTION-GAP)
* **[AI] Include alert details and RAG knowledge base text in compactContext to prevent LLM blindness** (AI-CONTEXT-EMPTY)
* **[INGESTION] Restrict rate limiter instantiation to verified tenants to prevent memory exhaustion DoS** (RATE-LIMIT-DOS)
* **[PERF] Refactor Python workers (alert writer / incident builder) to use database connection pooling** (PERF-DB-CONN-LEAK)
* **[PERF] Refactor Go workers (normalizer / correlation) to use native binary Kafka protocol instead of HTTP REST** (PERF-REST-POLL)
* **[PERF] Eliminate per-batch goroutine and channel allocations in Go workers to avoid GC churn** (PERF-GO-OVERCONCURRENT)
* **[PERF] Refactor hot-loop synchronous HTTP IOC lookups to use thread-safe in-memory cache** (PERF-GO-HOT-HTTP)
* **[PERF] Use static consumer instance IDs in Go workers to prevent Kafka REST rebalance storms** (PERF-REST-REBALANCE)
* **[PERF] Pre-lowercase IOC values outside the nested matching loop to avoid CPU churn** (PERF-IOC-STR-LOWER)
* **[ARCH] Replace Pandaproxy REST calls in Go workers with Native Kafka client binary protocol** (ARCH-KAFKA-NATIVE)
* **[ARCH] Route high-throughput streaming telemetries to ClickHouse OLAP and reserve PG for relational OLTP** (ARCH-DB-SPLIT)
* **[ARCH] Implement Mutual TLS (mTLS) for secure service-to-service internal container communications** (ARCH-MTLS-SEC)
* **[ARCH] Integrate dynamic DNS-based service discovery or internal load balancers** (ARCH-DISCOVERY)

#### Not Backlog
* None from this audit batch.

