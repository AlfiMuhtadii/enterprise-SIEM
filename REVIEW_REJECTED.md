# Review Classification — Rejected / Deferred / Accepted Risk

Findings from Gemini/Antigravity audits that were not immediately implemented.
Each finding is classified into one of three buckets:

| Classification | Meaning |
|---|---|
| **Rejected** | False positive, not applicable, or implementation would be harmful (regression risk with zero benefit). Do NOT implement. |
| **Deferred** | Valid finding, but not in scope for the current phase. Should be revisited before production deployment. |
| **Accepted Risk** | Valid finding intentionally tolerated for local/demo posture. Documented so the risk is explicit, not invisible. |

**Rule:** Enterprise-relevant reliability or production-hardening findings must never be classified as Rejected merely because current traffic is low. Those belong in Deferred.

---

## Reconciliation audit (2026-07-06) — decision-quality review

A pass over every Rejected/Deferred/Accepted-Risk decision (see REVIEW_ALL.md Batch 22). Outcome:

- **Reject decisions are sound and evidence-based.** ENV-3 (code-style, real regression risk),
  STATE-REDIS-05 (verified: no Redis in correlation-worker), PERF-DB-CONN-LEAK (verified: psycopg3
  `with conn:` closes — no leak), and **TEST-PER-TEST-SEED** (re-verified 2026-07-06: **zero** test
  files seed inside `setUp()` — the reject is correct; the original Batch 21 finding was imprecise)
  all hold.
- **EDR-EXEC-02 / AI-CONF-BANDS / GAP-005 / GAP-007 stay valid regardless of target** — these are
  CLAUDE.md **Forbidden Changes / non-goals (safety boundaries)**, not academic preferences. Only the
  *rationale wording* ("academic defensibility / thesis requirement") is stale post-enterprise-reframe
  and should read "product safety boundary / stated non-goal." The **decisions do not change.**
- **Rationale wording to refresh (decision unchanged, justification now weaker at enterprise bar):**
  DB-3, DB-4 (null-tenant demo data), RAG-1 (empty KB), INFRA-4 (Grafana writable) — "tolerated for
  demo" is a softer basis now; each needs a firmer production gate, not removal.
- **GAP-006** (p95 494ms) — reason still valid (Docker Desktop/WSL2 artifact) but now requires the
  native-Linux <300ms SLA measurement as a hard pre-pilot gate, not an open-ended acceptance.
- **Filing fixes:** INFRA-3 was stale (resolved by ENTERPRISE-068) → now marked RESOLVED below.
  IG-1/IG-2/IG-3 are marked **IMPLEMENTED** but live in this file's Section 2 (Deferred) — they are
  done (also in REVIEW_COMPLETED.md as INGESTION-025); they belong in COMPLETED, kept here only as
  cross-reference.
- **Completed-fix fidelity:** spot-checked 4 completed fixes against live code (ASSET-TENANT-OVERWRITE,
  CONSUMER-GROUP-EPHEMERAL, NORM-ASYNC-COMMIT-LOSS, AW-DEDUPE-BEFORE-COMMIT) — **all four match their
  claimed fix exactly.** No overstated completions found.

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

### AGENT-MIGRATE-GO: Migrate Endpoint Agent from Python to Go

- **Category**: Architecture / Language Rewrite
- **Severity**: N/A (proposal, not a defect)
- **Source**: REVIEW_BACKLOG.md proposed task, 2026-07-14
- **Finding**: Endpoint agent is Python stdlib; no Python pre-installed on standard Windows endpoints (real deployment friction), higher memory (~50-100MB) than a compiled binary, and relies on spawning subprocesses (`ps`/`ss`) rather than native OS APIs.
- **Rejection reason**: The proposed fix is a full big-bang rewrite of an entire service into a different language — exactly the pattern the Architecture Direction Lock names to avoid ("big bang rewrite", "speculative redesign", "unnecessary rewrites", "architecture churn"), not the strangler/incremental approach this codebase otherwise follows everywhere (see ARCH-KAFKA-NATIVE's opt-in native-transport addition alongside the existing REST path, never a wholesale rewrite). More importantly, the specific proposed mechanism — hooking OS-native kernel tracing APIs (ETW on Windows, eBPF on Linux) — directly re-opens **GAP-005** in this same file ("No kernel-level telemetry (non-goal by design)" — Accepted Risk, condition to re-evaluate: **"Never — this is an architectural boundary, not a deferred feature"**) and crosses into the Non-Goals this project explicitly and repeatedly states it is not: "a kernel telemetry platform", "a full EDR". `services/endpoint-agent`'s Python-stdlib design (no credential collection, no packet sniffing, no kernel module, advisory-only) is a deliberate safety boundary documented in Architecture Boundaries and Forbidden Changes, not an oversight to modernize away. The real-world Windows-Python-availability friction is a legitimate packaging concern, but the proposed fix (kernel hooks + full rewrite) is the wrong solution to it — a bundled/frozen Python distribution (PyInstaller-style) would address the "no Python on the box" problem without any of the kernel-boundary or rewrite-risk issues, and is not what this backlog item proposes.
- **Status**: **REJECTED**

---

### PIPE-MIGRATE-GO: Migrate Alert Writer and Incident Builder from Python to Go

- **Category**: Architecture / Language Rewrite
- **Severity**: N/A (proposal, not a defect)
- **Source**: REVIEW_BACKLOG.md proposed task, 2026-07-14
- **Finding**: `alert-writer-service`/`incident-builder-service` are Python/FastAPI while the 3 upstream pipeline services are Go; Python's GIL, per-process memory footprint, and separate dependency/native-Kafka-client management are cited as reasons to unify the stack.
- **Rejection reason**: Python/FastAPI for these two services is the codebase's *current, deliberate* architecture — Architecture Boundaries' Service Responsibilities table specifies it explicitly, not as legacy debt. A full rewrite is a big-bang rewrite of the write path for real `security_alerts`/`security_incidents` — the single most correctness-sensitive part of the whole pipeline (CLAUDE.md: "preserve replay guarantees, event contract integrity" and the Architecture Direction Lock's "avoid big bang rewrite… architecture churn"). It would need to reproduce, in a second language, every piece of business logic these services own today: fingerprinting/dedup, incident aggregation, DLQ handling, RBAC/audit context, OpenSearch indexing, and the just-added native-Kafka + backoff/circuit-breaker logic from `PANDAPROXY-EARLIEST-RESET-BUG` (`REVIEW_COMPLETED.md`) — a large surface to re-verify for zero-behavior-change, for a benefit that is currently only theoretical (no measured GIL contention or memory pressure at this project's actual scale). Critically, this backlog item's own stated motivation — avoiding Python's separate native-Kafka-client burden — was **already substantially resolved today** by adding native Kafka transport (`kafka_native.py`, `franz-go`-equivalent via `confluent-kafka`) directly to both Python services, at a fraction of the risk of a full rewrite. If real production telemetry someday shows Python throughput is a genuine bottleneck at scale, that would be a fresh, narrower, evidence-based finding — not this broad "rewrite the service" proposal.
- **Status**: **REJECTED**

---

### TEST-PER-TEST-SEED: "16 classes seed in setUp → heavy DemoSocSeeder re-runs per test method"

- **Category**: Test Infra / False Premise
- **Severity**: N/A
- **Source**: REVIEW_BACKLOG.md (Gemini proposal)
- **Finding**: Claims 16 test classes call `DemoSocSeeder` (218 lines) in `setUp()`, so the full seeder re-runs before every single test method across those classes.
- **Rejection reason**: The premise is false and directly contradicted by the current codebase. `grep -rl "DemoSocSeeder" tests/Feature/*.php` returns exactly **one** file, `tests/Feature/DemoPackageTest.php` — not 16. That file itself has exactly **one** test method (`test_demo_seed_creates_reviewer_data`), which calls `$this->seed(DemoSocSeeder::class)` **once**, inside the test body — not in a `setUp()` override, so it doesn't even run before other tests in that class (there are none). There is no per-test-method re-seeding happening anywhere in the suite today. (All other `DemoSocSeeder` hits project-wide are stale build artifacts under `dist/*/database/seeders/` and a `.claude/worktrees/` copy — not live test code.) Implementing a "minimal per-test fixture / seed-once" refactor for this would be solving a problem that doesn't exist, with real risk of introducing test coupling or state-leakage bugs for zero benefit. Verified 2026-07-06.
- **Status**: **REJECTED**

---

## Section 2 — Deferred

Valid enterprise-relevant findings that are not causing harm at current scale but must be addressed before high-traffic or multi-tenant production deployment.

---

### DETECT-BACKTEST-TENANCY: Detection backtest results are not tenant-scoped

- **Category**: Multi-Tenant Isolation
- **Severity**: Medium
- **Source**: Same review pass that found the Honeytoken/SocAgentController/EndpointController tenant-scoping gaps (2026-07-12; see `REVIEW_COMPLETED.md`, issues #53–#55)
- **Finding**: Detection backtest results are not tenant-scoped. Unlike the other three gaps found in the same pass (genuinely bounded, single-controller fixes), the root cause here sits one layer deeper, in the underlying `telemetry_events` table, which has no `tenant_id` column at all and is written to by roughly a dozen Python scripts plus one Laravel ingestion controller.
- **Why deferred**: A correct fix requires adding `tenant_id` to `telemetry_events` and auditing every write path (mostly Python, outside this session's normal PHP/test-suite verification loop) to populate it correctly — a materially larger, cross-language change than a single-controller scoping fix, with real risk of leaving a write path silently unscoped if one is missed. Needs its own dedicated pass with full write-path coverage.
- **Production gate**: Before this can be closed: add `tenant_id` to `telemetry_events` and its write paths, add `tenant_id` to the backtest output tables and scope the service/controller by it, then verify no write path was missed via a null-audit pass (same pattern as GAP-003's `TenantNullAuditCommand`, extended to cover `telemetry_events`).
- **Status**: **DEFERRED**

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
- **Audit-step evidence (2026-07-12)**: `TenantNullAuditCommand` had **zero** test coverage before this pass — its own correctness had never been verified, which matters because everything downstream (the go/no-go for backfill) depends on trusting its output. Added 9 tests (`tests/Feature/TenantNullAuditCommandTest.php`) against real fixture rows in `detector_test`: correct null-count/percentage/status per table, correct JSON report shape, `--table` single-table scoping (including rejecting a table not in the registry), the `TABLE_MISSING`/`NO_TENANT_COLUMN` defensive branches (exercised via a real `Schema::drop`/`dropColumn` inside the test transaction, restored after), and an explicit assertion that the command never mutates a record it audits. All 9 pass. Separately ran the real audit against this environment's actual dev `detector` database (`php artisan tenant:null-audit --output=reports/tenant_null_audit_2026_07_12.json`) — result: 0 rows in every one of the 23 registered tables. This is **not** evidence that GAP-003 is resolved; it's evidence that this environment's dev DB has never been seeded or used (confirmed via `migrate:status`: 8 migrations pending, including ones from earlier in this same session — this DB is simply idle, all real work in this environment runs against the isolated `detector_test` DB per CLAUDE.md's testing policy). GAP-003's actual backfill scope can only be assessed once real accumulated staging/production data exists; there is no such data in this environment to audit. The audit *tool* is now verified correct and ready to run the moment real data exists.
- **New sub-finding surfaced by this audit pass — TENANT-ISOLATED-TABLES-STALE**: `TenantBoundaryService::ISOLATED_TABLES` (the audit/backfill registry) lists 23 tables, unchanged since the 2026-06-25 decision record. The current schema (`database/schema/pgsql-schema.sql`) shows **~156 tables** now carry a `tenant_id` column — the registry has not kept pace with the platform's growth (asset inventory, UEBA, entity graph, honeytokens, tenant hierarchy, and more, all added after the registry was last touched). This means today's audit/backfill tooling — and any future RLS policy rollout scoped from this same list — would only ever cover ~15% of tenant-bearing tables. Deliberately not fixed here: deciding which of the ~133 unregistered tables need `ISOLATED_TABLES` treatment (vs. shadow/advisory/simulated data where a null tenant is acceptable) is a scoping judgment call with real behavioral consequences (`MUTABLE_TABLES` gates actual UPDATE permission in the backfill command), not something to silently expand as a side effect of writing test coverage. Per this repo's own classification rule, an enterprise-relevant completeness gap must be Deferred, not Rejected, regardless of current data volume.
- **Production gate (registry staleness)**: Before GAP-002 (RLS activation) or a real GAP-003 backfill run, `ISOLATED_TABLES` must be re-derived from the actual current schema (not the stale June list) and each of the ~133 newly-identified tables explicitly triaged into "needs isolation" vs "shadow/advisory, null is acceptable."
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

### PERF-DB-CONN-LEAK: Database connection pooling for Python workers — RESOLVED 2026-07-11

Moved to `REVIEW_COMPLETED.md`. The production gate stated here ("add `psycopg_pool` ...
route DB access through a `ConnectionPool` ... load-test under concurrency") is now
implemented: `services/{alert-writer-service,incident-builder-service}/pg_pool.py`, a
bounded lazily-created `psycopg_pool.ConnectionPool` (thread-safe by design, solving the
exact FastAPI-handler + consumer-event-loop concurrency concern this finding raised).
Full concurrent load-test against a live cluster still requires infra this environment
doesn't have (Docker daemon) — see the completed-task entry for exact scope.

---

### BATCH-DEFER-2026-07-01: Enterprise-roadmap — hot-path Go, core-pipeline rearchitecture, AI live-model

**Target reframed to enterprise demo (2026-07-01):** these are now **in-scope enterprise
roadmap** items (no longer "out of scope for academic posture"), but remain **staged**
(Deferred) because they either (a) touch the hot ingestion/correlation path that per
CLAUDE.md requires Go tests + a live-pipeline verifier and carry real regression risk to
the running demo, (b) are major architectural rewrites (multi-node infra) that need
their own design + staged validation, or (c) require external ML infra. Each should be
picked up as a dedicated, validated effort — not a batch. Hard safety boundaries in
CLAUDE.md (no active containment / autonomous response, shadow-rule soak gates) still
apply regardless of target. Triaged 2026-07-01.

- **PERF-GO-LIMITER** — the channel+ticker token bucket (IG-2) is correct and was just hardened (IG-2/IG-DOS); an atomic time-delta rewrite risks regressing it for negligible benefit at demo RPS.
- **PERF-GO-OVERCONCURRENT** — per-batch goroutine/channel allocation only causes GC churn at high sustained RPS (not the academic/demo profile).
- **PERF-REST-POLL** / **ARCH-KAFKA-NATIVE** — moving the Go workers from Pandaproxy HTTP REST to the native binary Kafka protocol is a **major rearchitecture** of the core event pipeline (produce/consume, offset recovery, poison-message DLQ — all recently hardened). Pandaproxy REST was chosen deliberately for demo simplicity.
- **PERF-REST-REBALANCE** — static consumer instance IDs to avoid REST rebalance storms; valid, but changes the recently-hardened consumer-offset-recovery path — needs live-pipeline validation.
- **ARCH-DB-SPLIT** — routing raw/normalized telemetry to a ClickHouse OLAP cluster and reserving PG for OLTP is an infrastructure redesign beyond Docker-Compose demo scope.
- **ARCH-MTLS-SEC** — mutual TLS for service-to-service traffic belongs in a production/K8s deployment manifest, not the local demo compose (traffic is already loopback-bound).
- **ARCH-DISCOVERY** — dynamic DNS/service discovery / internal LBs are a multi-node production concern; single-node compose uses static hostnames by design.
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
- **Status**: **RESOLVED (2026-07-06 reconciliation)** — superseded by **ENTERPRISE-068** (`0d6cfce`, in REVIEW_COMPLETED.md): `deploy.resources.limits` (memory + cpus) added to 6 services in both `docker-compose.yml` (dev) and `docker-compose.prod.yml`, with `xdr_container_resource_validate.py` (14 tests). This entry was stale — the finding is done; kept here for history.

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

### ML-SERVE-ONLINE: Multiclass LR model is offline-only, not in the live detection path

- **Category**: Product Claim / Detection Capability
- **Severity**: Medium-High (thesis/product claim accuracy, not a live-system defect)
- **Source**: REVIEW_BACKLOG.md (moved here 2026-07-10 after investigation)
- **Finding**: The trained multiclass logistic-regression model (`ai_detector_model.pkl`) is loaded only by offline `scripts/` (`train_ai_detector`, `realtime_detector_*_consumer`, `replay_detector_from_db`) — no live service loads it, so the running pipeline's "hybrid rule-based + ML" claim is, in practice, rule-based only.
- **Why deferred**: Investigated 2026-07-10 — the original proposed fix (wire the model into `correlation-worker`) is the wrong integration point. The model's feature vector (`status`/`latency_ms`/`has_sql_keywords`/...) is HTTP-request features, confirmed identical to `SecurityRequestLogger`'s `security_events` capture, not `correlation-worker`'s identity/cloud/SaaS telemetry — wiring it into correlation-worker would score data it was never trained on. The real rule+ML hybrid already exists as working code (`scripts/realtime_detector_consumer.py`) but is absent from `docker-compose.yml` and writes directly to the **active** `security_alerts`/`security_responses` tables with no advisory/shadow gate — deploying it as-is risks silently activating an unvalidated new alert domain, against the spirit of the Forbidden Changes list on domain promotion.
- **Production gate**: An explicit decision between (a) deploying `realtime_detector_consumer.py` as a real compose service that writes active alerts for a new web-request/HTTP-attack domain (requires domain-specific 6h soak PASS per CLAUDE.md before any active-path promotion) or (b) redirecting its output to an advisory-only table first (matching the existing `advisory_findings` shadow-alert-consumer pattern) and soak-validating before promotion. Either path is a properly-sized dedicated task, not a quick wire-up.
- **Resolution (2026-07-11, ENT-DETECT-ML-NOT-LIVE)**: Option (b) implemented — see `REVIEW_COMPLETED.md`. New `--output-mode` flag (default `shadow`) redirects output to `advisory_findings` (domain `web_request`), never `security_alerts`/`security_responses`; wired into `docker-compose.yml` as an opt-in `ml-shadow` profile service (was previously entirely absent from compose). `--output-mode active` preserves the original direct-to-`security_alerts` behavior byte-for-byte for operators who have completed a domain-specific 6h soak PASS.
- **Status**: **RESOLVED** — see `REVIEW_COMPLETED.md` / [#41](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/41)

---

### TECH-EOL-UPGRADE: Runtime/framework are at or past end-of-life

- **Category**: Tech Currency / Supply Chain
- **Severity**: High (compliance/security-patchability signal, not an active exploit)
- **Source**: REVIEW_BACKLOG.md (moved here 2026-07-10)
- **Finding**: `composer.json` pins `php: ^8.1` (security support ended 2025-12), `laravel/framework: ^10.10` (security window closed ~2025-02), `laravel/sanctum: ^3.3` (4.x is current). Running on an unsupported PHP/framework fails baseline SOC 2 / vendor security review expectations.
- **Why deferred**: This dev environment only has PHP 8.1 available — there is no PHP 8.3 runtime to upgrade to or test against here, so the staged upgrade (PHP 8.1→8.3, Laravel 10→11, Sanctum 3→4, each gated on the full test suite green) cannot even be attempted, let alone verified, in this environment. Not a code-complexity blocker — a missing-runtime blocker.
- **Production gate**: Must be revisited in an environment with a newer PHP runtime available, before any production/pilot deployment. Each version bump gated on the full `php artisan test` suite green before merge; Laravel 11's bootstrap/config restructuring should be its own dedicated effort, not bundled with the PHP bump.
- **Status**: **DEFERRED**

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
