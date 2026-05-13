# CLAUDE.md

# Claude Code Behavior Rules

## Think First — Always
Before making ANY change:
1. State what you understand about the current state
2. State what problem you are solving
3. State what files you plan to touch
4. State what you will NOT touch and why
5. Wait for confirmation before proceeding

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

Permanent correlation cutover is NOT approved yet.

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

* staged/shadow capable
* NOT permanent default

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

Primary blocker:

* 6h soak validation NOT PASS

Observed failures:

* worker_closed_connection
* host_aborted_connection
* cutover_status_command_failed
* php artisan xdr:correlation-cutover-status timeout

Warm-up soak:

* PASS

Current investigation focus:

* connection lifecycle
* retry handling
* health responsiveness
* graceful reconnect
* keepalive stability
* Docker responsiveness
* status command latency
* circuit breaker behavior

Do NOT:

* redesign architecture
* rewrite correlation logic
* introduce unnecessary feature scope

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

Observed:

* no goroutine leak during warm-up
* no memory leak during warm-up

Recent metrics:

```text
normalizer processed=9 forwarded=9 consumer_errors=0
correlation processed=9 alerts=9 published=9 consumer_errors=0
```

---

# Current Operational Rules

Current correlation mode:

```env
XDR_CORRELATION_ENGINE=shadow
```

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

* permanently set XDR_CORRELATION_ENGINE=go before soak PASS
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
