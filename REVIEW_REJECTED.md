# Review Classification — Rejected / Deferred / Accepted Risk

Findings from Gemini/Antigravity audits that were not immediately implemented.
Each finding is classified into one of three buckets:

| Classification | Meaning |
|---|---|
| **Rejected** | False positive, not applicable, or implementation would be harmful (regression risk with zero benefit). Do NOT implement. |
| **Deferred** | Valid finding, but not in scope for the current phase. Should be revisited before production deployment. |
| **Accepted Risk** | Valid finding intentionally tolerated for local/demo posture. Documented so the risk is explicit, not invisible. |

**Rule:** Enterprise-relevant reliability or production-hardening findings must never be classified as Rejected merely because academic/demo RPS is currently low. Those belong in Deferred.

---

## Section 1 — Rejected

Findings that are false positives, not applicable to the architecture, or where implementation would introduce regression risk with zero functional or security benefit.

---

### ENV-3: Inconsistent controller naming conventions

- **Category**: Code Smell / Maintainability
- **Severity**: Low
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-env-3--inconsistent-controller-naming-conventions)
- **Finding**: Mixed pluralization across resource controllers (`AdvisoryFindingsController` vs `DlqController`, `ThreatHuntController`).
- **Rejection reason**: Not a functional or security defect. Renaming would require rewriting route definitions, model references, and test assertions across dozens of files — introducing regression risk with zero security or behavioral benefit. Code style inconsistency does not meet the bar for implementation.
- **Status**: **REJECTED**

---

### STATE-REDIS-05: Refactor Go correlation worker state store to use Redis connection pooling

- **Category**: Performance / False Premise
- **Severity**: N/A
- **Source**: REVIEW_BACKLOG.md (Gemini proposal)
- **Finding**: Proposes pooling Redis connections in the correlation worker's state store.
- **Rejection reason**: The premise is false — `services/correlation-worker` does **not** use Redis at all. It has no Redis client/import; state is held in-process (`map`/`sync` + atomics), which is intentional for the shadow/advisory single-instance design. There are no Redis connections to pool. (The only "redis" token in the source is the string `"redis-server"` inside a process-name allowlist, unrelated to a datastore client.) Introducing Redis as a distributed state backend would be a separate, much larger architecture proposal — not a connection-pooling refactor — and is not warranted at the current advisory scope. Verified 2026-06-30.
- **Status**: **REJECTED**

---

### EDR-EXEC-02: Transition from Recommend-Only to Automated Active EDR Containment

- **Category**: Response Capability / Architecture Boundary Violation
- **Severity**: Critical (if implemented)
- **Source**: REVIEW_BACKLOG.md (Gemini proposal 2026-06-29)
- **Finding**: Proposes converting `EndpointResponseCommandService` and the Python endpoint agents from recommend-only to automated active containment (process kill, isolation, autonomous execution).
- **Rejection reason**: Directly violates multiple **Forbidden Changes** in CLAUDE.md: *"add ... process killing, persistence install, or active response to endpoint agent"*, *"add execution logic to `response_plan_actions` (`action_types` are `recommend_*` only — NO `execute_*`)"*, and the Phase 1 `ALLOWED_TYPES` allowlist (`noop`, `collect_diagnostics`, `refresh_config`, `upload_health_snapshot`). Also breaches the operational posture *"no active containment, no autonomous response, autonomous response not approved"* and the non-goals (*"live containment"*, *"endpoint enforcement"*). Implementing this would destroy academic defensibility (analyst-in-the-loop is a thesis requirement). This is an architectural boundary, not a deferred feature. See also Section 3 / GAP-007.
- **Status**: **REJECTED**

---

### AI-CONF-BANDS: Confidence-based automated containment rules + critical asset exclusion list

- **Category**: Response Capability / Architecture Boundary Violation
- **Severity**: Critical (if implemented)
- **Source**: REVIEW_BACKLOG.md (Gemini proposal 2026-06-29)
- **Finding**: Proposes wiring AI confidence bands in `AiAnalystManager` to drive automated containment via `EndpointResponseCommandService`, with a critical-asset exclusion list.
- **Rejection reason**: The core ask — AI-confidence-driven **automated containment** — violates the same forbidden boundary as EDR-EXEC-02 (no autonomous response, no `execute_*` actions, advisory-only posture). AI output must remain analyst-assist only; it must never trigger response execution. The "critical asset exclusion list" sub-idea is benign on its own but only has meaning in the context of automated containment, so the task as proposed is rejected. If an asset-criticality tag is desired purely as **advisory** alert-enrichment metadata (no response coupling), that would be a separate, new advisory-only proposal — not this task.
- **Status**: **REJECTED**

---

## Section 2 — Deferred

Valid enterprise-relevant findings that are not causing harm at current scale but must be addressed before high-traffic or multi-tenant production deployment.

---

### GAP-001: Shadow rule domain promotion (6h soaks required)

- **Category**: Detection Coverage / Operational Gate
- **Severity**: High
- **Source**: Gap analysis 2026-06-26
- **Finding**: 121 of 133 detection rules are shadow-only. Endpoint behavioral (40), network (9), UEBA (9), threat-intel (3), advanced detection (60) have never produced active alerts. Each domain requires a domain-specific 6h soak PASS before promotion is permitted per CLAUDE.md.
- **Why deferred**: Not a code gap — code for `DomainSoakHarnessService` and shadow soak command already exists. This is an operational execution gate. Promotion is forbidden until soak evidence is collected per domain.
- **Production gate**: Must run domain-specific 6h soak per domain before any shadow-to-active promotion. `ACTIVE_ALLOWLIST` must not be modified without soak PASS.
- **Status**: **DEFERRED**

---

### GAP-002: PostgreSQL Row-Level Security (RLS) implementation

- **Category**: Multi-Tenant Isolation / Security
- **Severity**: High
- **Source**: Gap analysis 2026-06-26
- **Finding**: Tenant isolation is application-layer only. PostgreSQL RLS is not enabled. Documented in `docs/security/RLS_DECISION_RECORD.md`. A privileged DB connection (e.g., via compromised service account) could read any tenant's data without app-layer filtering.
- **Why deferred**: Requires GAP-003 (null tenant_id backfill) to complete first. RLS policies on tables with null `tenant_id` would silently hide pre-migration records.
- **Production gate**: Must be addressed before any multi-tenant production pilot. Prerequisite: GAP-003 fully complete and verified.
- **Status**: **DEFERRED**

---

### GAP-003: Null tenant_id backfill for pre-BACKLOG-019 records

- **Category**: Multi-Tenant Isolation / Data Integrity
- **Severity**: High
- **Source**: Gap analysis 2026-06-26
- **Finding**: Records created before BACKLOG-019 (tenant context enforcement) have `tenant_id = NULL`. `XDR_TENANT_STRICT_MODE` remains `false` because strict mode rejects null-tenant records. Command `php artisan tenant:backfill-nulls` is scaffolded but not run against real data.
- **Why deferred**: Backfill requires a data audit (`TenantNullAuditCommand`) to identify the scope of null records before any bulk update. Running blindly on production data risks assigning wrong tenant context.
- **Production gate**: Must complete audit → backfill → verify before enabling `XDR_TENANT_STRICT_MODE=true` in staging/production.
- **Status**: **DEFERRED**

---

### GAP-004: Redpanda single-node — no HA / partition replication

- **Category**: Infrastructure Reliability
- **Severity**: Medium
- **Source**: Gap analysis 2026-06-26
- **Finding**: Redpanda runs as a single Docker container. No replication factor, no partition leadership failover. A single container crash halts the entire event pipeline (all topics go offline).
- **Why deferred**: Single-node is acceptable for academic demo. Multi-node Redpanda cluster requires infrastructure reconfiguration beyond Docker Compose scope. HA belongs in Kubernetes/production deployment manifest.
- **Production gate**: Must be addressed before any production pilot with real endpoint traffic. Minimum: 3-node Redpanda with replication factor ≥ 2.
- **Status**: **DEFERRED**

---

### TENANT-ENFORCE-RLS: Enable hard Row-Level Security enforcement + strict tenant mode defaults

- **Category**: Multi-Tenant Isolation / Security
- **Severity**: High
- **Source**: REVIEW_BACKLOG.md (Gemini proposal 2026-06-29)
- **Finding**: Proposes enabling PostgreSQL RLS enforcement (`ENABLE ROW LEVEL SECURITY`) and flipping `XDR_TENANT_STRICT_MODE` to default `true`.
- **Why deferred**: This is a valid enterprise hardening target, **not** a false positive — so it is Deferred, not Rejected. It is explicitly gated by an existing decision record (`docs/security/RLS_DECISION_RECORD.md`, ENTERPRISE-040) and by GAP-002/GAP-003: RLS enforcement on tables that still hold `tenant_id = NULL` pre-migration records would silently hide that data, and strict-mode-by-default would lock out seeder/demo users (DB-3, DB-4). The advisory RLS scaffold (ENTERPRISE-069) and backfill pre-flight (ENTERPRISE-070) are already in place as the prerequisite groundwork.
- **Production gate**: Complete GAP-003 (null `tenant_id` audit → backfill → verify) → then GAP-002 (enable RLS policies) → then flip `XDR_TENANT_STRICT_MODE=true` in staging, validate seeder/demo membership, before production pilot.
- **Status**: **DEFERRED**

---

### PERF-DB-CONN-LEAK: Database connection pooling for Python workers

- **Category**: Enterprise Performance / Reliability
- **Severity**: Low–Medium (scale-dependent)
- **Source**: REVIEW_BACKLOG.md (Gemini proposal)
- **Finding**: Proposes connection pooling for alert-writer / incident-builder to fix a "connection leak".
- **Why deferred (not rejected)**: Two evidence-based reasons, but it remains a valid enterprise-scale concern so it is Deferred, not Rejected:
  1. **No actual leak.** Both services use **psycopg3** (`import psycopg`). All four write paths open a connection and use it under `with conn:` (`write_postgres`, `store_operational_event`, and the two query paths). In psycopg3 the connection context-manager **closes** the connection on block exit, so connections are released per batch — there is no leak. The premise ("leak") does not hold for psycopg3.
  2. **Safe pooling needs a dependency that is not installed.** `psycopg_pool` is not present in the environment, and a hand-rolled module-level shared connection would be **thread-unsafe** (the FastAPI HTTP handlers run concurrently with the consumer event loop; psycopg3 connections are not safe for concurrent use). Connect-per-batch is acceptable at the current academic/demo throughput.
- **Production gate**: Before a high-throughput production pilot, add `psycopg_pool` to the service requirements and route DB access through a `ConnectionPool` (bounded min/max), then load-test under concurrency. Verified 2026-06-30.
- **Status**: **DEFERRED**

---

### BATCH-DEFER-2026-07-01: Hot-path Go, core-pipeline rearchitecture, and AI live-model tasks

The following backlog items are **valid enterprise concerns** but are Deferred (not
Rejected) because they either (a) touch the hot ingestion/correlation path that per
CLAUDE.md requires Go tests + a live-pipeline verifier and carry real regression risk
to the demo that anchors the thesis, (b) are major architectural rewrites out of scope
for the current single-node academic/demo posture, or (c) conflict with the intentional
offline-first AI design. Verified/triaged 2026-07-01.

- **PERF-GO-LIMITER** — the channel+ticker token bucket (IG-2) is correct and was just hardened (IG-2/IG-DOS); an atomic time-delta rewrite risks regressing it for negligible benefit at demo RPS.
- **PERF-GO-OVERCONCURRENT** — per-batch goroutine/channel allocation only causes GC churn at high sustained RPS (not the academic/demo profile).
- **PERF-GO-HOT-HTTP** — real (correlation worker does synchronous IOC HTTP lookups in the hot loop); a thread-safe cache is worthwhile but needs Go tests + live verifier.
- **PERF-REST-POLL** / **ARCH-KAFKA-NATIVE** — moving the Go workers from Pandaproxy HTTP REST to the native binary Kafka protocol is a **major rearchitecture** of the core event pipeline (produce/consume, offset recovery, poison-message DLQ — all recently hardened). Pandaproxy REST was chosen deliberately for demo simplicity.
- **PERF-REST-REBALANCE** — static consumer instance IDs to avoid REST rebalance storms; valid, but changes the recently-hardened consumer-offset-recovery path — needs live-pipeline validation.
- **ARCH-DB-SPLIT** — routing raw/normalized telemetry to a ClickHouse OLAP cluster and reserving PG for OLTP is an infrastructure redesign beyond Docker-Compose demo scope.
- **ARCH-MTLS-SEC** — mutual TLS for service-to-service traffic belongs in a production/K8s deployment manifest, not the local demo compose (traffic is already loopback-bound).
- **ARCH-DISCOVERY** — dynamic DNS/service discovery / internal LBs are a multi-node production concern; single-node compose uses static hostnames by design.
- **RATE-LIMIT-DOS** — valid (an authenticated client can spam distinct tenant_ids to allocate buckets; HMAC gating + TTL eviction already bound it). The right fix is a bounded cap on distinct tenant buckets, but it touches the just-hardened IG-2 limiter → do carefully with Go tests, not in a batch.
- **AI-KB-SEMANTIC** — the Qdrant path + embeddings + cosine ranking already exist (`SocKnowledgeRetriever::retrieveQdrant`, `soc_knowledge_embeddings`); only a live transformer embedding model is missing, which requires external ML infra and conflicts with the intentional offline-first design (see KNOWN_LIMITATIONS §7).
- **AI-KB-FEED-INGEST** — live MITRE/RSS ingestion adds an external network dependency + scheduler against the offline-first posture; should be re-scoped as a bundled offline dataset import instead.

**Production gate (all):** revisit when moving to a multi-node / production deployment
with load testing; the Go hot-path items require a live-pipeline verifier run per CLAUDE.md.

---

### INFRA-3: No memory/CPU limits on intensive containers

- **Category**: Enterprise Reliability / Production Hardening
- **Severity**: Low–Medium (scale-dependent)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-infra-3--no-memorycpu-limits-on-intensive-containers)
- **Finding**: `docker-compose.yml` sets no `mem_limit` or `cpus` for Redpanda, ClickHouse, OpenSearch, or Grafana. Under sustained load these can starve the host.
- **Why deferred**: Hard limits in the local dev compose can crash containers on lower-spec developer machines. This is a production deployment concern — limits belong in the Kubernetes/ECS deployment manifest or a `docker-compose.prod.yml` override, not in the shared dev compose.
- **Production gate**: Must be addressed before any multi-tenant production pilot. Relevant to `docs/operations/OPERATIONAL_POSTURE.md`.
- **Status**: **DEFERRED**

---

### IG-1: Synchronous normalizer metrics polling in request path

- **Category**: Enterprise Performance / Reliability
- **Severity**: Medium (high RPS only)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-ig-1--synchronous-normalizer-metrics-polling-in-request-path)
- **Status**: **IMPLEMENTED** — `admissionAllowed()` now reads a cached `normalizerQueueDepth` atomic. A background goroutine (`startMetricsPoller`) polls at configurable interval (`XDR_NORMALIZER_METRICS_POLL_INTERVAL_SECONDS`, default 5s). BACKLOG-INGESTION-025 / commits 3027e08 + e88c103.

---

### IG-2: Global rate limiter token starvation

- **Category**: Enterprise Reliability / Multi-Tenant Fairness
- **Severity**: Medium (multi-tenant abuse scenario)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-ig-2--global-rate-limiter-token-starvation)
- **Status**: **IMPLEMENTED** — Per-tenant token bucket map (`tenantLimiters sync.Map`). Each tenant identified via `X-Tenant-ID` header gets its own bucket (`XDR_INGEST_PER_TENANT_RPS`, default = global RPS). Background goroutine refills all buckets every second. Global hard-cap middleware still in place. BACKLOG-INGESTION-025 / commits 3027e08 + e88c103.

---

### IG-3: 15-second publish retry timeout causing socket exhaustion

- **Category**: Enterprise Reliability / Backpressure
- **Severity**: Medium (sustained Redpanda outage scenario)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-ig-3--15-second-publish-retry-timeout-causing-socket-exhaustion)
- **Status**: **IMPLEMENTED** — `publish()` now uses `context.WithTimeout` per attempt (`XDR_PUBLISH_TIMEOUT_SECONDS`, default 5s), exponential backoff (100ms/200ms/400ms, capped at 1s), and circuit breaker (`XDR_PUBLISH_CB_FAILURES`=5, `XDR_PUBLISH_CB_OPEN_SECONDS`=30). Circuit open = immediate fast-fail, no goroutine accumulation. BACKLOG-INGESTION-025 / commits 3027e08 + e88c103.

---

## Section 3 — Accepted Risk

Valid findings where the risk is real but intentionally tolerated given the current local/demo operational posture. Documented here so the risk is explicit and visible, not silently ignored.

---

### DB-3: Seeder users locked out in strict mode

- **Category**: Operational Gap (strict mode only)
- **Severity**: Low (demo posture)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-db-3--seeder-users-locked-out-in-strict-mode)
- **Finding**: Users created by `UserSeeder.php` and `DemoSocSeeder.php` have no `user_tenant_memberships` entries. With `XDR_TENANT_STRICT_MODE=true` these users cannot access any scoped endpoint.
- **Accepted risk rationale**: `XDR_TENANT_STRICT_MODE` defaults to `false`. Seeders are purpose-built for local demo and thesis defense walkthroughs. Scoping seeder users to a specific tenant would break the global dashboard showcase layout. The risk is bounded to strict-mode-only environments — which are never enabled during demos.
- **Condition to re-evaluate**: If a production pilot enables strict mode by default, seeder users must be given explicit tenant memberships.
- **Status**: **ACCEPTED RISK**

---

### DB-4: Unscoped demo alerts/incidents (`tenant_id = NULL`) in `DemoSocSeeder`

- **Category**: Operational Gap (strict mode only)
- **Severity**: Low (demo posture)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-db-4--unscoped-demo-alertsincidents-tenant_id--null-in-demosocseeder)
- **Finding**: Demo `security_alerts` and `security_incidents` seeded with `tenant_id = NULL`. Hidden from scoped queries under strict mode; causes `tenant:null-audit` to report `HAS_NULL`.
- **Accepted risk rationale**: Demo data is intentionally global-scope to populate the full dashboard across all views without requiring a tenant selection step during presentations. The `HAS_NULL` audit report output is expected and documented.
- **Condition to re-evaluate**: Same as DB-3 — production pilot with strict mode enabled requires scoped demo data.
- **Status**: **ACCEPTED RISK**

---

### INFRA-4: Grafana provisioning mounts not read-only

- **Category**: Security Hardening (production only)
- **Severity**: Low
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-infra-4--grafana-provisioning-mounts-not-read-only)
- **Finding**: Grafana provisioning volume mounts (`./infra/grafana/provisioning`) are not mounted `:ro`. A compromised Grafana container could write to provisioning files.
- **Accepted risk rationale**: Local dev requires writable provisioning mounts to allow dashboard exports and live edits inside the container. The attack surface is localhost-only (Grafana port is already restricted to `127.0.0.1` via INFRA-1). Adding `:ro` in dev breaks the dashboard authoring workflow.
- **Condition to re-evaluate**: Production deployment config must use `:ro` on all provisioning mounts.
- **Status**: **ACCEPTED RISK**

---

### GAP-005: No kernel-level telemetry (non-goal by design)

- **Category**: Telemetry Coverage / Scope Boundary
- **Severity**: Informational
- **Source**: Gap analysis 2026-06-26
- **Finding**: Endpoint agent does not collect kernel-level events (syscalls, eBPF hooks, kernel modules, memory forensics). System is not a full EDR.
- **Accepted risk rationale**: Explicitly a non-goal. CLAUDE.md lists "kernel EDR" and "malware prevention" as non-goals. Academic scope is behavioral observability and correlation, not deep kernel instrumentation. Adding kernel telemetry would require privileged container capabilities and would introduce offensive tooling risk.
- **Condition to re-evaluate**: Never — this is an architectural boundary, not a deferred feature.
- **Status**: **ACCEPTED RISK**

---

### GAP-006: p95 ingest latency ~494ms (Docker Desktop / WSL2 overhead)

- **Category**: Performance / Operational Baseline
- **Severity**: Low
- **Source**: Gap analysis 2026-06-26 / ENTERPRISE-044 EXE-08
- **Finding**: p95 ingest latency is 494ms on Docker Desktop / WSL2 Windows, in the WARN range (300–499ms). The 300ms PASS threshold is not achievable under Docker Desktop networking overhead regardless of optimizations applied (persistent connection implemented in ENTERPRISE-044).
- **Accepted risk rationale**: Overhead is Docker Desktop WSL2 networking artifact — not a code defect. Native Linux deployment or CI runner shows sub-100ms p95. Demo environment latency does not reflect production performance. EXE-08 PASS was achieved (no FAIL-level bounds exceeded).
- **Condition to re-evaluate**: Verify p95 < 300ms on a native Linux environment before any production pilot SLA commitment.
- **Status**: **ACCEPTED RISK**

---

### GAP-007: No live containment / active response (non-goal by design)

- **Category**: Response Capability / Scope Boundary
- **Severity**: Informational
- **Source**: Gap analysis 2026-06-26
- **Finding**: Response plan actions are `recommend_*` only. No `execute_*` actions. No process kill, IP block, account suspension, or autonomous containment.
- **Accepted risk rationale**: Explicitly forbidden by CLAUDE.md. Academic positioning requires analyst-in-the-loop for all response actions. Autonomous remediation is a non-goal and a forbidden change. Implementing active response would compromise academic defensibility.
- **Condition to re-evaluate**: Never for autonomous response. Controlled execution (human-approved, audit-logged) could be considered post-thesis as Phase 2 response framework.
- **Status**: **ACCEPTED RISK**

---

### RAG-1: Empty knowledge base on fresh deployment

- **Category**: Operational Gap / Groundedness
- **Severity**: Medium (academic scope)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-rag-1--empty-knowledge-base-on-fresh-deployment)
- **Finding**: Seeders do not populate `soc_knowledge_base`. RAG pipeline returns zero retrieval results on a fresh deploy, falling back to parametric LLM outputs.
- **Accepted risk rationale**: RAG knowledge seeding is a runtime ingest responsibility, not a migration/seeder concern. `AiGuardrails` already handles empty retrieval with post-processing warnings visible on the analyst dashboard. For academic demo, the fallback output is sufficient to demonstrate the pipeline. Seeding arbitrary vectors into migrations would couple deployment to LLM embedding infrastructure.
- **Condition to re-evaluate**: Production deployment must include a RAG seeding runbook as part of the operator onboarding checklist (`docs/operations/OPERATIONAL_POSTURE.md`).
- **Status**: **ACCEPTED RISK**
