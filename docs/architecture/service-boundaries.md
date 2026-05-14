# Service Boundaries

Defines service responsibilities, ownership boundaries, allowed dependencies, and forbidden coupling for the polyglot XDR pipeline.

---

## Service Ownership Table

| Service | Runtime | Port | Produces | Consumes | DB Access |
|---|---|---|---|---|---|
| ingestion-gateway | Go | 8091 | telemetry.raw | — | none |
| normalizer-worker | Go | 8092 | telemetry.normalized, telemetry.normalized.dlq | telemetry.raw | none |
| correlation-worker | Go | 8093 | xdr.alerts, xdr.alerts.dlq | telemetry.normalized | none |
| alert-writer-service | Python/FastAPI | 8095 | alerts.created | xdr.alerts | PostgreSQL (alerts), OpenSearch |
| incident-builder-service | Python/FastAPI | 8096 | incidents.updated, incidents.builder.dlq | alerts.created | PostgreSQL (incidents) |
| ai-rag-service | Python/FastAPI | 8094 | ai.analysis.results, ai.analysis.completed | ai.analysis.requests | Qdrant, OpenSearch (read) |
| Laravel SOC control-plane | PHP/Laravel | 8000 | ai.analysis.requests | alerts.created, incidents.updated, ai.analysis.results | PostgreSQL (primary, all SOC tables) |

---

## Service Responsibilities

### ingestion-gateway

**Owns:** Telemetry ingestion surface. Nothing else.

Responsibilities:
- Accept and validate signed telemetry batches (HMAC-SHA256)
- Rate limiting and admission control
- Backpressure: check normalizer queue depth before accepting
- Publish raw events to `telemetry.raw`
- Reject malformed or unauthorized requests at the boundary

Not responsible for:
- Normalization, deduplication, enrichment, or classification
- Routing or correlation logic
- Persisting events anywhere

### normalizer-worker

**Owns:** Schema normalization. Nothing else.

Responsibilities:
- Consume `telemetry.raw`, normalize to canonical schema
- Validate required fields: `ts`, `telemetry_type`, `event_type`
- Route malformed events to `telemetry.normalized.dlq`
- Publish normalized events to `telemetry.normalized` (batched, fan-out producers)
- Maintain producer queue with backpressure

Not responsible for:
- Enrichment, correlation, or alert generation
- Database writes of any kind

### correlation-worker

**Owns:** Identity/cloud/SaaS alert generation. Shadow-only for endpoint/DNS/proxy/firewall.

Responsibilities:
- Consume `telemetry.normalized`
- Run identity/cloud/SaaS correlation logic (scoped to `identity-cloud`)
- Generate deterministic alert IDs: SHA-256 of `alert_type + actor + sorted(evidence_ids)`
- Publish alerts to `xdr.alerts`; route publish failures to `xdr.alerts.dlq`
- Accept HTTP `POST /v1/correlate` for direct correlation (soak validation, testing)
- Expose `/health`, `/ready`, `/metrics`

Not responsible for:
- Alert persistence, OpenSearch indexing
- Incident creation or management
- Active correlation for endpoint/DNS/proxy/firewall domains (shadow-only)

**Current state:** staged_active for identity/cloud/SaaS (6h soak PASS 2026-05-14).

### alert-writer-service

**Owns:** Alert persistence and downstream event publication.

Responsibilities:
- Consume `xdr.alerts`
- Write alerts to PostgreSQL and index to OpenSearch
- Publish idempotent `alerts.created` events for downstream consumers
- Route persistence failures to `xdr.alerts.dlq`
- Accept legacy unwrapped payloads for backward compatibility

Not responsible for:
- Correlation logic or re-correlation
- Incident management, RBAC, or workflow

### incident-builder-service

**Owns:** Incident aggregation and lifecycle.

Responsibilities:
- Consume `alerts.created`
- Aggregate related alerts into incidents
- Update incident status
- Publish `incidents.updated`
- Route failures to `incidents.builder.dlq`

Not responsible for:
- Alert management or persistence
- SOC workflow, dashboard, or RBAC
- AI enrichment directly

### ai-rag-service

**Owns:** AI analysis pipeline. Standalone — not in the critical alert path.

Responsibilities:
- Consume `ai.analysis.requests`
- Perform semantic retrieval via Qdrant
- Publish `ai.analysis.results` and `ai.analysis.completed`
- Expose HTTP endpoint for synchronous analyst queries

Not responsible for:
- Core correlation logic
- Alert or incident persistence
- RBAC or workflow

### Laravel SOC control-plane

**Owns:** All SOC operational concerns. Does NOT own the event pipeline.

Responsibilities:
- Dashboard, RBAC, incident workflow, audit log, reporting
- Configuration management for XDR correlation engine
- Cutover decision and status: `xdr:correlation-cutover-status`
- Consumes `alerts.created`, `incidents.updated`, `ai.analysis.results`
- Triggers AI enrichment via `ai.analysis.requests`

Not responsible for:
- High-throughput event processing
- Stream consumer management
- Alert/normalized event production

---

## Allowed Dependencies

```
ingestion-gateway       → Redpanda (telemetry.raw publish only)
normalizer-worker       → Redpanda (telemetry.raw consume, telemetry.normalized publish)
correlation-worker      → Redpanda (telemetry.normalized consume, xdr.alerts publish)
alert-writer-service    → Redpanda, PostgreSQL, OpenSearch
incident-builder-service→ Redpanda, PostgreSQL
ai-rag-service          → Redpanda, Qdrant, OpenSearch (read-only)
Laravel SOC             → PostgreSQL (primary), Redpanda (consume SOC topics only)
```

---

## Forbidden Cross-Service Coupling

- Services must NOT call each other over HTTP for pipeline operations — all pipeline coupling is via Redpanda topics only
- `ingestion-gateway` must NOT read from any topic
- `normalizer-worker` must NOT write to application databases
- `correlation-worker` must NOT call `alert-writer-service` or `incident-builder-service` directly
- `alert-writer-service` must NOT call `correlation-worker` for re-correlation
- Laravel must NOT directly publish to Go or Python service topics (consumers only, for SOC topics)
- No two services share a database schema — each service owns its own tables

---

## Event Ownership

| Topic | Owner (single producer) | Consumer(s) |
|---|---|---|
| telemetry.raw | ingestion-gateway | normalizer-worker |
| telemetry.normalized | normalizer-worker | correlation-worker |
| telemetry.normalized.dlq | normalizer-worker | ops (manual review) |
| xdr.alerts | correlation-worker | alert-writer-service |
| xdr.alerts.dlq | correlation-worker, alert-writer-service | ops (manual review) |
| alerts.created | alert-writer-service | incident-builder-service, Laravel SOC |
| incidents.updated | incident-builder-service | Laravel SOC |
| incidents.builder.dlq | incident-builder-service | ops (manual review) |
| ai.analysis.requests | Laravel SOC | ai-rag-service |
| ai.analysis.results | ai-rag-service | Laravel SOC |
| ai.analysis.completed | ai-rag-service | Laravel SOC |

---

## Database Ownership

| Database | Owner | Tables / Collections |
|---|---|---|
| PostgreSQL | Laravel SOC (primary) | users, roles, incidents, alerts (SOC view), audit, workflow, xdr_operational_events |
| PostgreSQL | alert-writer-service | alerts (writer schema) |
| PostgreSQL | incident-builder-service | incidents (builder schema) |
| OpenSearch | alert-writer-service | alert index |
| OpenSearch | ai-rag-service (read) | alert index (read-only for enrichment) |
| Qdrant | ai-rag-service | vector embeddings |
| ClickHouse | — | available for future analytics; not yet actively written |

---

## Operational Responsibilities

| Concern | Owner |
|---|---|
| Consumer group health | Service operator |
| DLQ monitoring and replay | Ops team |
| Correlation engine mode | Laravel SOC (via config/artisan) |
| Soak validation | Ops team before any cutover promotion |
| Rollback decision | Ops team + Laravel cutover status command |
| Event contract changes | Service owner + all consumer owners |
| Schema migration (PostgreSQL) | Laravel (SOC tables), service owner (service tables) |

---

## Health, Ready, Metrics Endpoints

All services expose:
- `/health` — process is running; does not check downstream
- `/ready` — ready to process requests; may check critical runtime state
- `/metrics` — JSON counters for operational monitoring

These endpoints must remain available under load. The soak validator and PHP cutover status command poll `/health` and `/metrics`.
