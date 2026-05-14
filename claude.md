# CLAUDE.md

---

# Claude Code Behavior Rules

## Step 1 — Q&A Codebase First
On first contact with any new topic or session:
- Read this entire file before doing anything
- Demonstrate understanding by answering:
  1. What is the current active blocker?
  2. What has already been validated and passed?
  3. What are the forbidden changes?
  4. What are the cutover gates?
  5. What is the current correlation engine mode and why?
- If any answer is uncertain — ask, do not assume

---

## Step 2 — Think First, Always
Before making ANY change:
1. State what you understand about the current state
2. State what problem you are solving
3. State what files you plan to touch
4. State what you will NOT touch and why
5. State what risks exist for this change
6. State which feedback loop command you will run after
7. Wait for confirmation before proceeding

---

## Step 3 — Feedback Loops, Always
After every change:
1. Run the relevant validation command immediately
2. Show full output — never summarize or paraphrase
3. Compare output against pass criteria below
4. If output deviates from expected — STOP, do not proceed
5. Wait for instruction before next step

### Feedback Loop Map

| Change Type              | Validation Command              |
|--------------------------|---------------------------------|
| Docker / infra           | docker compose config --quiet   |
| Laravel / PHP            | php artisan test                |
| Event contracts          | xdr_contract_validate.py        |
| Correlation / resilience | xdr_event_flow_resilience_validate.py |
| Full soak                | run_xdr_correlation_soak_6h.ps1 |

### Pass Criteria

```
docker compose config    → exit code 0, no errors
php artisan test         → all tests green, zero failures
contract validate        → all contracts valid
soak validation          → ALL gates below must pass:
  fallback_count = 0
  failure_count = 0
  duplicate_rate = 0
  goroutine_growth = 0
  stable memory usage
  p95_latency_ms < 300
  no sustained latency drift
  alert type match >= 0.95
  evidence match >= 0.98
  alert count delta <= 1-2%
```

### STOP Conditions
- Any test fails
- Any validation output deviates from baseline
- Any gate metric out of range
- Any forbidden file is touched

If validation fails → remain shadow OR rollback to legacy. Never force promotion.

---

## Memory Load Order
Load context in this order — only what is needed for the session:
1. Current Active Blocker ← start here
2. Forbidden Changes ← know what not to do
3. Architecture Overview ← understand the system
4. Standard Commands ← know how to validate
5. Required Cutover Gates ← know pass criteria

---

# Operational Context

Read this file before making architectural or implementation changes.

This is an ongoing distributed systems migration project.

This is NOT a greenfield rewrite.

Preserve:

* replay guarantees
* event contract integrity
* rollback capability
* staged migration discipline
* operational validation gates
* cutover safety

Avoid:

* speculative redesign
* unnecessary rewrites
* architecture churn
* fake enterprise claims

---

# Project

Distributed AI-assisted XDR-like platform with operational polyglot microservices.

Current architecture is already operational and validated end-to-end.

identity/cloud/SaaS Go correlation: staged active approved (6h soak PASS, 2026-05-14).
endpoint/DNS/proxy/firewall: shadow-only, cutover not approved.

---

# Architecture Overview

## Laravel

Laravel remains the SOC control plane.

Responsibilities:

* dashboard
* RBAC
* incident workflow
* audit
* reporting
* operational management
* configuration

Do NOT remove Laravel from control-plane responsibilities.

---

## Go Services

### ingestion-gateway

Responsibilities:

* telemetry ingestion
* signed ingestion
* rate limiting
* admission control
* backpressure handling

Publishes:

* telemetry.raw

---

### normalizer-worker

Consumes:

* telemetry.raw

Responsibilities:

* telemetry normalization
* schema normalization
* event shaping
* validation

Publishes:

* telemetry.normalized

---

### correlation-worker

Consumes:

* telemetry.normalized

Responsibilities:

* identity/cloud/SaaS correlation
* alert generation
* replay-safe correlation

Publishes:

* xdr.alerts

Current state:

* identity/cloud/SaaS: staged_active (6h soak PASS, 2026-05-14)
* endpoint/DNS/proxy/firewall: shadow-only
* rollback capability preserved

---

## Python/FastAPI Services

### alert-writer-service

Consumes:

* xdr.alerts

Responsibilities:

* alert persistence
* OpenSearch indexing
* PostgreSQL writes
* alert event publishing

Publishes:

* alerts.created

---

### incident-builder-service

Consumes:

* alerts.created

Responsibilities:

* incident aggregation
* incident updates
* workflow orchestration

Publishes:

* incidents.updated

---

### AI/RAG Service

Responsibilities:

* analyst assistance
* AI enrichment
* vector retrieval
* heuristic fallback workflows

Infrastructure:

* Qdrant
* embeddings
* semantic retrieval

---

# Infrastructure

Streaming:

* Redpanda

Storage:

* PostgreSQL
* ClickHouse
* OpenSearch
* Qdrant

Observability:

* Grafana

---

# Event Flow

```text
telemetry source
  -> ingestion-gateway
  -> telemetry.raw
  -> normalizer-worker
  -> telemetry.normalized
  -> correlation-worker
  -> xdr.alerts
  -> alert-writer-service
  -> alerts.created
  -> incident-builder-service
  -> incidents.updated
  -> Laravel SOC control-plane
```

---

# Event Contracts

Contracts exist under:

```text
docs/contracts/events/
```

Current contracts:

* xdr.alerts
* alerts.created
* incidents.updated
* ai.analysis.requests
* ai.analysis.results
* ai.analysis.completed

Envelope format:

```json
{
  "event_id": "",
  "event_type": "",
  "schema_version": "",
  "occurred_at": "",
  "trace_id": "",
  "source_service": "",
  "payload": {},
  "metadata": {}
}
```

All new distributed service outputs should use the v1 envelope format.

---

# Event Store

Replayable operational event store:

```text
xdr_operational_events
```

Requirements:

* replay-safe
* idempotent
* deterministic
* event-sourced

---

# Current Migration State

Current posture:

* Laravel remains SOC control plane
* Go handles high-throughput event pipeline
* Python handles AI/event orchestration
* Redpanda connects distributed event streams

Current active Go scope:

* identity
* cloud
* SaaS

Current shadow-only domains:

* endpoint
* DNS
* proxy
* firewall

Do NOT expand active cutover beyond current scope.

---

# Current Active Blocker

No active blocker.

6h soak: PASS (2026-05-14). Decision: staged_active for identity/cloud/SaaS.

Resolved failures:

* worker_closed_connection — fixed: consumer reconnect loop
* host_aborted_connection — fixed: HTTP timeout hardening
* cutover_status_command_failed — fixed: resilience validator retry handling

See: docs/validation/xdr_6h_soak_pass.md
See: docs/operations/reconnect_resilience_fix.md

---

# Proven Validation

PASS:

* distributed replay validation
* parity validation
* event contract validation
* infrastructure validation
* Docker compose validation
* polyglot microservices validation
* warm-up soak validation
* 6h soak validation (2026-05-14)
* reconnect/resilience validation

6h soak gate results:

| Gate | Threshold | Actual |
|---|---|---|
| fallback_count | = 0 | 0 |
| failure_count | = 0 | 0 |
| status_failures | = 0 | 0 |
| p95_latency_ms | < 300 ms | 80.65 ms |
| worker_p95_latency_ms | < 300 ms | 61 ms |
| memory_growth_mb | stable | −6.519 MB |
| goroutine_growth | = 0 | 0 |
| latency_drift | none | not drifting |
| events_processed | — | 562,640,000 |
| avg_throughput_eps | — | 77,981.72 |

---

# Current Operational Rules

Current correlation mode:

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
```

Decision: staged_active for identity/cloud/SaaS.
Rollback preserved: XDR_CORRELATION_FALLBACK_TO_LEGACY=true

Current scope:

```env
XDR_CORRELATION_SCOPE=identity-cloud
```

Fallback:

```env
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
```

Circuit breaker:

* 1 transient failure -> no fallback
* 2 transient failures -> no fallback
* 3 consecutive failures -> fallback to legacy

---

# Required Cutover Gates

Permanent cutover is forbidden until ALL gates PASS.

Required gates:

* fallback_count = 0
* failure_count = 0
* duplicate_rate = 0
* goroutine_growth = 0
* stable memory usage
* p95_latency_ms < 300
* no sustained latency drift
* alert type match >= 0.95
* evidence match >= 0.98
* alert count delta <= 1-2%

If any gate fails:

* remain shadow
  OR
* rollback to legacy

Never force promotion.

---

# Standard Commands

## Run 6h Soak

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

---

## Analyze Soak

```powershell
php artisan xdr:soak-analyze --report=reports/xdr_correlation_soak_6h.json --json
```

```powershell
python scripts\xdr_soak_fallback_debug.py --input reports\xdr_correlation_soak_6h.json --output reports\xdr_correlation_soak_fallback_debug.json
```

---

## Contract Validation

```powershell
python scripts\xdr_contract_validate.py --output reports\xdr_contract_validation.json
```

---

## Event Resilience Validation

```powershell
python scripts\xdr_event_flow_resilience_validate.py --replays 3 --restart-services 0 --send-malformed 1 --output reports\xdr_event_flow_resilience_validation.json
```

---

## Shadow Prep Validation

```powershell
python scripts\xdr_endpoint_dns_proxy_shadow_prep.py --output reports\xdr_endpoint_dns_proxy_shadow_prep.json
```

---

## Docker Validation

```powershell
docker compose config --quiet
```

---

## Laravel Tests

```powershell
php artisan test
```

Do NOT run multiple parallel Laravel test processes against the same PostgreSQL test database.

---

# Architecture Direction Lock

Current strategy:

* strangler migration
* event-driven decomposition
* replay-first validation
* contract-first integration
* staged cutover
* rollback capability
* operational validation before promotion

Avoid:

* big bang rewrite
* unnecessary service splitting
* premature Kubernetes migration
* speculative redesign

---

# Service Ownership

Laravel:

* SOC control-plane only

Go:

* ingestion
* normalization
* correlation
* high-throughput processing

Python/FastAPI:

* AI/RAG
* alert persistence
* incident orchestration

---

# Non Goals

This project is NOT:

* a full EDR
* a kernel telemetry platform
* a malware framework
* a stealth/persistence platform
* an offensive security framework
* a hyperscale commercial SIEM replacement

Not implemented:

* kernel EDR
* live containment
* malware prevention
* endpoint enforcement
* offensive automation

---

# Forbidden Changes

Do NOT:

* promote endpoint/DNS/proxy/firewall to active before domain-specific soak PASS
* remove rollback capability
* remove legacy compatibility paths
* remove Laravel control-plane responsibilities
* bypass validation gates
* claim production hyperscale XDR
* claim full EDR
* ignore replay/idempotency guarantees
* ignore failed validation gates

Operational rule:

If validation fails:

* remain shadow
  OR
* rollback to legacy

Never force cutover after failed soak.
