# Codex Context

Short source of truth for future coding sessions. Read this before making changes.

Last updated: 2026-05-18

---

## Current Architecture

Laravel-based SOC/XDR control plane with polyglot microservices (Go + Python) connected via Redpanda. Strangler migration in progress — identity/cloud/SaaS domains staged_active, endpoint shadow-only.

### Services

| Service | Language | Role |
|---|---|---|
| Laravel SOC | PHP/Blade | Control plane: RBAC, dashboard, incidents, investigations, response, export, resilience, hardening |
| ingestion-gateway | Go | Signed telemetry ingestion (`POST /v1/ingest`, HMAC-SHA256), rate limiting, backpressure |
| normalizer-worker | Go | Normalizes `telemetry.raw` → `telemetry.normalized`, endpoint-v1 normalization |
| correlation-worker | Go | Correlates `telemetry.normalized`, identity/cloud/SaaS (active), endpoint (shadow) |
| alert-writer-service | Python/FastAPI | Persists `xdr.alerts` → PostgreSQL `security_alerts`, publishes `alerts.created` |
| incident-builder-service | Python/FastAPI | Builds incidents from `alerts.created`, publishes `incidents.updated` |
| ai-rag-service | Python/FastAPI | Analyst-assist with heuristic fallback, Qdrant vector store |
| endpoint-agent | Python stdlib | Linux telemetry collector (/proc-based), publishes to ingestion-gateway |

### Event Flow

```text
telemetry source
  → ingestion-gateway  (POST /v1/ingest, HMAC-SHA256 X-XDR-Signature)
  → telemetry.raw
  → normalizer-worker
  → telemetry.normalized
  → correlation-worker
  → xdr.alerts              (identity/cloud/SaaS — active, persisted)
  → xdr.alerts.shadow.endpoint  (endpoint shadow — NOT consumed by alert-writer)
  → alert-writer-service
  → alerts.created
  → incident-builder-service
  → incidents.updated
  → Laravel SOC control-plane
```

### Stream / Storage

- **Redpanda** (Kafka-compatible) — event streaming backbone
- **PostgreSQL** — primary SOC state: alerts, incidents, workflow, entity graph, audit
- **ClickHouse** — async analytics sync (not on alert write path)
- **OpenSearch** — alert indexing (graceful DLQ fallback when unavailable)
- **Qdrant** — vector store for AI/RAG

### Docker Profiles

```powershell
docker compose up -d                         # infra: postgres, redpanda, clickhouse, opensearch, qdrant, grafana
docker compose --profile strangler up -d     # Go + Python services
docker compose --profile app up -d           # Laravel + queue worker + scheduler
```

---

## Current Migration State

| Domain | Status | Since |
|---|---|---|
| identity/cloud/SaaS | **staged_active** | 6h soak PASS 2026-05-14 |
| endpoint | shadow-only | — |
| DNS/proxy/firewall | shadow-only | — |

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
```

Circuit breaker: 1–2 transient failures → no fallback. 3 consecutive → fallback to legacy.

Do NOT expand active scope beyond identity/cloud/SaaS.

---

## Laravel SOC Modules

All modules are admin/role-gated. Navigation items reflect RBAC permissions.

### Core (pre-2026-05-15)
- `/soc` — SOC dashboard (incidents, alerts, maturity)
- `/security/alerts` — raw security event log (admin only)
- `/soc/rules` — detection rule management (admin)
- `/soc/agents` — endpoint agent management

### Added 2026-05-15
- **XDR Scenario Runner** (`/scenario`) — detection validation, stub or real pipeline mode
- **Trace Investigation** (`/traces`) — cross-service trace correlation, TraceRedactor
- **Detection Rule Governance** (`/detection`) — rule lifecycle, MITRE coverage, promotion gates

### Added 2026-05-16
- **Endpoint Telemetry UI** (`/endpoint`) — shadow inventory, agent timeline, Grafana
- **Entity Graph** (`/entity`) — entity registry (users/hosts/IPs/domains/processes/hashes), relationships, timeline, adjacency graph

### Added 2026-05-17
- **Entity Risk Scoring** (`/entity-risk`) — deterministic weighted scoring, advisory shadow indicators
- **Investigation Workflow** (`/investigations`) — 8-state machine, assignment, notes, artifacts, audit trail
- **Response Planning** (`/response-plans`) — deterministic recommendations, advisory-only, no execution
- **Export Center** (`/exports`) — JSON/Markdown/HTML export with TraceRedactor + append-only audit log
- **Security Hardening** (`/security/hardening`) — secret validation, service auth status, audit integrity
- **Resilience Validation** (`/resilience`) — 14 failure scenarios, fault injection, recovery metrics
- **Endpoint Agent Hardening** (`/endpoint-agents`) — enrollment tokens, signed heartbeat, health states, config policy
- **Endpoint Response Approval Framework** (`/endpoint-agents/response-queue`) — safe command dispatch, 8-state lifecycle, audit trail

### Added 2026-05-18
- **Endpoint Behavioral Visibility** (`/endpoint-agents/{id}/activity|process-tree|persistence|process-network|long-lived`) — process ancestry, long-lived tracking, persistence inventory
- **Behavioral Detection Analytics** (`/endpoint-agents/{id}/analytics/*`) — execution chains, beacon patterns, LOLBin analytics, rare parent-child
- **Threat Hunting & Investigation Query Engine** (`/threat-hunts`) — structured queries across 8 domains, pivot explorer, history replay; advisory-only, append-only

---

## RBAC Roles and Key Permissions

| Role | Key Permissions |
|---|---|
| admin | All permissions + security.view |
| analyst | incidents, investigations, response, export, scenario.view/replay |
| viewer | Read-only: dashboard, incidents, entities, investigations, response, reports |
| scenario_operator | scenario.view/run/replay/export, entity.view, investigation.view |
| detection_engineer | scenario + registry.manage + rules.manage + evidence.view + investigation.create |

Gate forward: `AuthServiceProvider::boot()` → `Gate::before()` → `Rbac::can()` for all `soc:*` abilities.

---

## Internal Security (added 2026-05-17)

### Service Token Auth
- `InternalAuthService::signToken(serviceId)` → time-bounded HMAC token (5-min window)
- `InternalAuthService::verifyToken(token)` → false for missing/tampered/expired
- Middleware: `InternalServiceAuthMiddleware` on `/api/internal/*`
- Rejection logs `SecurityHardeningEvent::EVENT_AUTH_FAILURE`

### Event Signatures
- `InternalAuthService::signEvent(event)` → `sha256=<hex>` over `event_id|event_type|occurred_at|trace_id`
- Deterministic (same fields → same sig). Replay-safe (different trace_id → different sig).
- `verifyEvent()` NEVER throws — returns false and logs `signature_failure` hardening event

### Secret Validation
- `php artisan security:validate-secrets` — checks APP_KEY, XDR_INGEST_SECRET, XDR_INTERNAL_AUTH_SECRET
- New env vars: `XDR_INTERNAL_AUTH_SECRET`, `XDR_NORMALIZER_INTERNAL_TOKEN`, `XDR_ALERT_WRITER_INTERNAL_TOKEN`
- Go/Python services log `[SECURITY-WARN]` at startup for missing/dev-default secrets

---

## Key Database Tables

### Core pipeline (pre-2026-05-15)
- `security_alerts` — alert_id (UNIQUE), alert_fingerprint, trace_id, actor_key, alert_type, severity, evidence, raw_event
- `security_incidents` — incident_id (UNIQUE), trace_id, affected_entities, timeline, mitre_mapping
- `xdr_operational_events` — event_id (UNIQUE), ON CONFLICT DO NOTHING (replay-safe)
- `security_events` / `telemetry_events` — raw event store

### Added 2026-05-15
- `scenario_runs`, `scenario_evidence`, `detection_rules`

### Added 2026-05-16
- `entities` — type, key, risk_score, risk_level, risk_factors, last_risk_calculated_at
- `entity_relationships` — source/target FK, relationship_type, confidence, trace_id
- `entity_observations` — APPEND-ONLY per entity
- `entity_risk_snapshots` — risk calculation history per entity

### Added 2026-05-17 (all append-only where noted)
- `investigations` — INV-YYYY-NNNNN, 8-state machine
- `investigation_events` — APPEND-ONLY audit trail
- `investigation_assignments` — APPEND-ONLY with is_active flag
- `investigation_notes`, `investigation_artifacts`
- `response_plans` — RP-YYYY-NNNNN, 6-state machine, advisory-only
- `response_plan_actions` — recommend_* types ONLY, no execute_* columns
- `response_plan_approvals` — APPEND-ONLY
- `response_plan_notes`
- `export_audit_logs` — EXP-YYYY-NNNNN, APPEND-ONLY
- `security_hardening_events` — APPEND-ONLY (auth_failure|signature_failure|secret_warning|startup_validation)
- `resilience_runs` — RES-YYYY-NNNNN, 14 scenarios
- `resilience_metrics` — metric tracking per run
- `endpoint_agents` — + enrollment_token_hash, public_key_fingerprint, ip_address, hostname, platform, health_state (additive 2026-05-17)
- `endpoint_agent_configs` — config policy history per agent
- `endpoint_agent_heartbeats` — signed heartbeat log, signature_valid (APPEND-ONLY)
- `endpoint_agent_metrics` — quality metrics upserted per agent
- `endpoint_response_commands` — CMD-YYYY-NNNNN, 8-state machine
- `endpoint_response_command_events` — APPEND-ONLY audit trail per command

### Added 2026-05-18
- `endpoint_process_snapshots` — SNAP-YYYY-NNNNN IDs, process_count, shell_count, long_lived_count
- `endpoint_process_entries` — per-process data per snapshot (APPEND-ONLY, no updated_at)
- `endpoint_persistence_items` — upserted by (agent_id, item_key); systemd/cron/startup
- `endpoint_network_correlations` — process-to-network links per snapshot (APPEND-ONLY)
- `endpoint_behavioral_findings` — FIND-YYYY-NNNNN IDs, 5 finding types (APPEND-ONLY)
- `endpoint_execution_chains` — EC-YYYY-NNNNN IDs, chain_steps jsonb, chain_score
- `endpoint_beacon_patterns` — BP-YYYY-NNNNN IDs, destination_reuse_score, connection_count
- `threat_hunts` — hunt_id, title, replay_scope, status, result_count, trace_id (APPEND-ONLY)
- `threat_hunt_queries` — structured filter params per hunt (APPEND-ONLY)
- `threat_hunt_results` — result_type, result_data snapshot per hunt (APPEND-ONLY)

**PostgreSQL HAVING note**: Cannot reference SELECT aliases in HAVING. Use:
```sql
SELECT COUNT(*) AS cnt FROM (SELECT col FROM tbl GROUP BY col HAVING COUNT(*) > 1) sub
```
NOT: `->having('cnt', '>', 1)` or `->groupBy()->havingRaw()->count()` (both fail in PostgreSQL).

---

## Detection Rule Registry

`docs/detection/rules/registry.v1.json` — **37 rules** total:
- 12 staged_active (identity/cloud/SaaS)
- 22 shadow (endpoint behavioral)
- 3 shadow (threat-intel/IOC)

Hard gate: endpoint + threat-intel rules BLOCKED from staged_active. `ACTIVE_ALLOWLIST` intentionally empty.

Validator: `python scripts/xdr_rule_registry_validate.py` (21 checks, exit 0=PASS)

---

## Event Contracts

Location: `docs/contracts/events/`

- `event-envelope.v1.schema.json` — base envelope with optional `event_signature` field (added 2026-05-17)
- `xdr.alerts.v1.schema.json`, `alerts.created.v1.schema.json`, `incidents.updated.v1.schema.json`
- `ai.analysis.{requests,results,completed}.v1.schema.json`

Endpoint telemetry: `docs/contracts/telemetry/endpoint/` (8 event types + heartbeat)
Threat intel: `docs/contracts/threat-intel/ioc.v1.schema.json`

---

## TraceRedactor Rules

- Applied at **presentation layer only** — DB is NEVER mutated
- JSON payload fields deeply decoded and redacted: `payload`, `evidence`, `raw_event`, `metadata`, `config`, `results`
- Sensitive keys → `[REDACTED]`: password, token, secret, cookie, authorization, bearer, api_key, private_key, session_id
- Email addresses → `[EMAIL]` inside JSON payload fields
- Investigative identifiers preserved at top level: actor_key, ip, alert_type, trace_id

---

## Resilience Validation (added 2026-05-17)

14 scenarios: 9 simulation (validates code capability) + 5 active (executes real checks).

Active scenarios test:
- Endpoint shadow isolation (zero endpoint alert types in `security_alerts`)
- Signature failure non-destructive (`verifyEvent` returns false, never throws)
- Invalid auth no replay corruption (bad token → 401, no `xdr_operational_events` written)
- DLQ replay recovery (hardening event structure, idempotency)
- Replay under degraded state (`insertOrIgnore` idempotency)

```powershell
python scripts\xdr_resilience_validate.py           # 8 HTTP-based scenarios
python scripts\xdr_fault_injection.py               # 5 fault injections (non-destructive)
php artisan resilience:validate --scenario=broker_restart
php artisan resilience:validate                     # all 14 scenarios
```

---

## Standard Validation Commands

```powershell
# Laravel tests (PRIMARY gate — run after every change)
php artisan test
# Current: 764 tests, all green

# Endpoint agent tests
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
# Current: 95 tests, all green

# Rule registry validator
python scripts\xdr_rule_registry_validate.py
# Current: 37 rules, 21/21 checks PASS

# Event contract validation
python scripts\xdr_contract_validate.py --output reports\xdr_contract_validation.json

# Event-flow resilience
python scripts\xdr_event_flow_resilience_validate.py --replays 3 --send-malformed 1 \
  --output reports\xdr_event_flow_resilience_validation.json

# Resilience validation suite
python scripts\xdr_resilience_validate.py --output reports\resilience\resilience-validation-report.json
python scripts\xdr_fault_injection.py --output reports\resilience\fault-injection-report.json

# Secret validation
php artisan security:validate-secrets --record

# Docker
docker compose config --quiet

# Soak (6h — run before permanent cutover decisions)
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

Do NOT run parallel `php artisan test` processes against the same PostgreSQL test database.

---

## Forbidden Changes

- Promote endpoint/DNS/proxy/firewall domains to active without domain-specific soak PASS
- Expand Go active scope beyond identity/cloud/SaaS
- Remove rollback capability (XDR_CORRELATION_FALLBACK_TO_LEGACY)
- Remove Laravel as SOC control plane
- Add kernel driver, live containment, malware prevention, offensive features to endpoint agent
- Add execute_* columns or runtime execution to response_plan_actions (advisory-only only)
- Delete or update records in append-only audit tables (export_audit_logs, investigation_events, response_plan_approvals, security_hardening_events, entity_observations)
- Mutate DB inside TraceRedactor (redaction is presentation-layer only)
- Bypass InternalServiceAuthMiddleware on /api/internal/* routes
- Add entries to ACTIVE_ALLOWLIST in xdr_rule_registry_validate.py without soak evidence
- Manually create SOC alerts/incidents from Scenario Runner (real mode must go through pipeline)
- Promote endpoint/threat-intel alert paths to xdr.alerts active topic

Operational rule: gate fails → remain shadow OR rollback to legacy. Never force cutover.
