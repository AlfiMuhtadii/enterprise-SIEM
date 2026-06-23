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

---

## 8. Backlog & Triage Assessment

### Backlog Candidate List (Confirmed / High-Confidence Conditional Risks)
* **[INGESTION-GATEWAY] Refactor normalizer queue depth checks to use asynchronous polling** (IG-1)
* **[INGESTION-GATEWAY] Implement client-scoped or tenant-scoped rate limiting to prevent global starvation** (IG-2)
* **[INGESTION-GATEWAY] Reduce publish HTTP client timeout to prevent socket exhaustion during outages** (IG-3)

### Not Backlog (Low Priority / Cleanup / Posture)
* *None from this audit batch.* All three findings are classified as medium-severity high-confidence Conditional Risks that should be tracked for implementation.


