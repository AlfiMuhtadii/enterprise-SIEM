# XDR Platform Architecture Summary

Last updated: 2026-05-17

---

## Platform Overview

Distributed AI-assisted XDR-like detection and investigation platform. Polyglot microservices (Go high-throughput pipeline, Python AI/event orchestration, Laravel SOC control plane) connected via Redpanda event streaming with a staged strangler migration from a monolithic detector.

**Not:** a full EDR, kernel telemetry platform, hyperscale commercial SIEM, or offensive security tool.

---

## Service Map

```
┌─────────────────────────────────────────────────────────────────────┐
│                      Laravel SOC Control Plane                       │
│                                                                       │
│  Dashboard   RBAC    Incidents   Investigations   Response Planning  │
│  Scenario Runner    Entity Graph   Risk Scoring   Export Center      │
│  Trace Investigation   Detection Governance   Security Hardening     │
│  Resilience Validation Dashboard                                      │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ read/write PostgreSQL
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                         Event Pipeline                               │
│                                                                       │
│  telemetry source                                                     │
│      ↓ POST /v1/ingest (HMAC-SHA256)                                 │
│  [ingestion-gateway Go]                                               │
│      ↓ telemetry.raw (Redpanda)                                      │
│  [normalizer-worker Go]                                               │
│      ↓ telemetry.normalized (Redpanda)                               │
│  [correlation-worker Go]                                              │
│      ↓ xdr.alerts (active: identity/cloud/SaaS)                      │
│      ↓ xdr.alerts.shadow.endpoint (shadow: endpoint — NOT persisted) │
│  [alert-writer-service Python]                                        │
│      ↓ security_alerts (PostgreSQL) + alerts.created (Redpanda)     │
│  [incident-builder-service Python]                                    │
│      ↓ security_incidents (PostgreSQL) + incidents.updated (Redpanda)│
│                                                                       │
│  [ai-rag-service Python]  — analyst assist, Qdrant vectors           │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Migration Posture (as of 2026-05-17)

| Domain | Correlation | Topic | Persisted |
|---|---|---|---|
| identity | staged_active | xdr.alerts | Yes → security_alerts |
| cloud | staged_active | xdr.alerts | Yes → security_alerts |
| SaaS | staged_active | xdr.alerts | Yes → security_alerts |
| endpoint | shadow-only | xdr.alerts.shadow.endpoint | **NO** |
| DNS | shadow-only | xdr.alerts.shadow.endpoint | **NO** |
| proxy | shadow-only | xdr.alerts.shadow.endpoint | **NO** |
| firewall | shadow-only | xdr.alerts.shadow.endpoint | **NO** |

Rollback preserved: `XDR_CORRELATION_FALLBACK_TO_LEGACY=true` (circuit breaker: 3 failures → fallback).

---

## Detection Rule Registry

37 rules total (`docs/detection/rules/registry.v1.json`):
- 12 staged_active — identity/cloud/SaaS correlation (deployed, generating alerts)
- 22 shadow — endpoint behavioral (deployed, shadow topic only)
- 3 shadow — threat-intel/IOC (deployed, shadow topic only)

Hard gate: endpoint + threat-intel rules permanently blocked from staged_active until domain-specific 6h soak PASS.

---

## Laravel SOC Control Plane — Module Map

```
/soc                          SOC dashboard (incidents, alerts, maturity overview)
/soc/agents                   Endpoint agent management
/soc/rules                    Detection rule management
/soc/hunts                    Threat hunting

/scenario                     XDR Scenario Runner (detection validation, 5 scenarios)
/traces                       Trace Investigation (cross-service correlation, TraceRedactor)
/detection                    Detection Rule Governance (lifecycle, MITRE, promotion gates)
/endpoint                     Endpoint Telemetry UI (shadow inventory, timeline, Grafana)

/entity                       Entity Graph (users/hosts/IPs/domains/processes/hashes)
/entity-risk                  Entity Risk Scoring (deterministic, advisory shadow indicators)

/investigations               Investigation Workflow (8-state machine, assignment, audit trail)
/response-plans               Response Planning (advisory-only, no execution, 6-state machine)
/exports                      Export Center (JSON/Markdown/HTML, TraceRedactor, audit log)

/security/hardening           Security Hardening (secret validation, service auth status)
/resilience                   Resilience Validation (14 scenarios, fault injection, metrics)

/api/internal/*               Internal Service API (X-Internal-Service-Token protected)
```

---

## Append-Only Audit Tables

These tables are NEVER updated or deleted by any platform service:

| Table | Records |
|---|---|
| `investigation_events` | State transitions, assignment changes, notes, artifacts |
| `response_plan_approvals` | Approval/rejection history with actor attribution |
| `entity_observations` | Entity observation events from projection |
| `export_audit_logs` | Every export with actor, format, size |
| `security_hardening_events` | Auth failures, signature failures, secret warnings |

---

## Event Integrity & Internal Auth

```
Service-to-service tokens:  InternalAuthService::signToken(serviceId)
                            → base64(serviceId|timestamp|HMAC-SHA256)
                            5-minute validity window, time-bounded

Event envelope signatures:  InternalAuthService::signEvent(event)
                            → sha256=HMAC(event_id|event_type|occurred_at|trace_id)
                            Deterministic. Replay-safe (different trace_id → different sig).
                            Failure: logged to security_hardening_events, NEVER destructive.

Secret validation:          php artisan security:validate-secrets
                            Checks: APP_KEY, XDR_INGEST_SECRET, XDR_INTERNAL_AUTH_SECRET,
                            SOC_WEBHOOK_SECRET. Warns on dev defaults.
```

---

## Operational Resilience

14 validated failure scenarios:

**Simulation** (validates code capability without live services):
broker_restart, consumer_reconnect, opensearch_unavailable, clickhouse_unavailable,
alert_writer_restart, incident_builder_restart, delayed_consumer, backpressure_accumulation,
partial_service_outage

**Active** (executes real checks against the running platform):
dlq_replay_recovery, endpoint_ingestion_interrupt, signature_verification_failure,
invalid_auth_token, replay_under_degraded_state

Recovery invariants enforced by tests:
- Invalid signature → never throws, never corrupts pipeline, logs hardening event
- Invalid auth token → 401, never writes to xdr_operational_events
- Replay of same event_id → exactly 1 record (insertOrIgnore idempotency)
- Endpoint alert types → never appear in security_alerts (shadow isolation)

---

## Grafana Dashboards

| Dashboard | UID | Coverage |
|---|---|---|
| Trace Flow | trace-flow-v1 | trace_id propagation end-to-end |
| Endpoint | endpoint-xdr-v1 | shadow telemetry volume, agent health |
| Entity Risk | xdr-entity-risk-v1 | risk scores, top entities, snapshots |
| Investigation Workflow | xdr-investigation-v1 | state distribution, SLA trends |
| Response Planning | xdr-response-v1 | plan states, advisor activity |
| Export & Reporting | xdr-export-reporting-v1 | export volume, format distribution |
| Security Hardening | xdr-security-hardening-v1 | auth failures, signature failures, secret warnings |
| Resilience & Recovery | xdr-resilience-v1 | run history, recovery metrics, DLQ volume |

---

## Test Suite

| Suite | Count | Runner |
|---|---|---|
| Laravel feature + unit | 764 tests | `php artisan test` |
| Endpoint agent Python | 95 tests | `python -m unittest discover -s tests/endpoint_agent` |
| Rule registry validator | 21 checks | `python scripts/xdr_rule_registry_validate.py` |
| Resilience validation | 8 scenarios | `python scripts/xdr_resilience_validate.py` |
| Fault injection | 5 injections | `python scripts/xdr_fault_injection.py` |

6h soak PASS (2026-05-14): p95=80.65ms, 562M events, 77,981 eps, zero fallbacks/failures.
