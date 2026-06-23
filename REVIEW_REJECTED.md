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

## Section 2 — Deferred

Valid enterprise-relevant findings that are not causing harm at current scale but must be addressed before high-traffic or multi-tenant production deployment.

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
- **Status**: **IMPLEMENTED** — `admissionAllowed()` now reads a cached `normalizerQueueDepth` atomic. A background goroutine (`startMetricsPoller`) polls at configurable interval (`XDR_NORMALIZER_METRICS_POLL_INTERVAL_SECONDS`, default 5s). BACKLOG-INGESTION-025 / commit to follow.

---

### IG-2: Global rate limiter token starvation

- **Category**: Enterprise Reliability / Multi-Tenant Fairness
- **Severity**: Medium (multi-tenant abuse scenario)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-ig-2--global-rate-limiter-token-starvation)
- **Status**: **IMPLEMENTED** — Per-tenant token bucket map (`tenantLimiters sync.Map`). Each tenant identified via `X-Tenant-ID` header gets its own bucket (`XDR_INGEST_PER_TENANT_RPS`, default = global RPS). Background goroutine refills all buckets every second. Global hard-cap middleware still in place. BACKLOG-INGESTION-025 / commit to follow.

---

### IG-3: 15-second publish retry timeout causing socket exhaustion

- **Category**: Enterprise Reliability / Backpressure
- **Severity**: Medium (sustained Redpanda outage scenario)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-ig-3--15-second-publish-retry-timeout-causing-socket-exhaustion)
- **Status**: **IMPLEMENTED** — `publish()` now uses `context.WithTimeout` per attempt (`XDR_PUBLISH_TIMEOUT_SECONDS`, default 5s), exponential backoff (100ms/200ms/400ms, capped at 1s), and circuit breaker (`XDR_PUBLISH_CB_FAILURES`=5, `XDR_PUBLISH_CB_OPEN_SECONDS`=30). Circuit open = immediate fast-fail, no goroutine accumulation. BACKLOG-INGESTION-025 / commit to follow.

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

### RAG-1: Empty knowledge base on fresh deployment

- **Category**: Operational Gap / Groundedness
- **Severity**: Medium (academic scope)
- **Source**: [REVIEW_ALL.md](REVIEW_ALL.md#finding-rag-1--empty-knowledge-base-on-fresh-deployment)
- **Finding**: Seeders do not populate `soc_knowledge_base`. RAG pipeline returns zero retrieval results on a fresh deploy, falling back to parametric LLM outputs.
- **Accepted risk rationale**: RAG knowledge seeding is a runtime ingest responsibility, not a migration/seeder concern. `AiGuardrails` already handles empty retrieval with post-processing warnings visible on the analyst dashboard. For academic demo, the fallback output is sufficient to demonstrate the pipeline. Seeding arbitrary vectors into migrations would couple deployment to LLM embedding infrastructure.
- **Condition to re-evaluate**: Production deployment must include a RAG seeding runbook as part of the operator onboarding checklist (`docs/operations/OPERATIONAL_POSTURE.md`).
- **Status**: **ACCEPTED RISK**
