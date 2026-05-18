# SOC Control Plane — Module Reference

Last updated: 2026-05-17

This document describes all Laravel SOC modules, their key design decisions, and operational boundaries.

---

## Module Inventory

| Module | Route Prefix | Added | Permission |
|---|---|---|---|
| SOC Dashboard | `/soc` | initial | dashboard.view |
| Security Alerts | `/security/alerts` | initial | admin only |
| Agent Management | `/soc/agents` | initial | agents.manage |
| XDR Scenario Runner | `/scenario` | 2026-05-15 | scenario.view + |
| Trace Investigation | `/traces` | 2026-05-15 | trace.view |
| Detection Governance | `/detection` | 2026-05-15 | rules.govern |
| Endpoint Telemetry UI | `/endpoint` | 2026-05-15/16 | dashboard.view |
| Entity Graph | `/entity` | 2026-05-16 | entity.view |
| Entity Risk Scoring | `/entity-risk` | 2026-05-16 | entity.view |
| Investigation Workflow | `/investigations` | 2026-05-17 | investigation.* |
| Response Planning | `/response-plans` | 2026-05-17 | response.* |
| Export Center | `/exports` | 2026-05-17 | report.* |
| Security Hardening | `/security/hardening` | 2026-05-17 | admin only |
| Resilience Validation | `/resilience` | 2026-05-17 | admin only |

Internal API: `/api/internal/*` — `X-Internal-Service-Token` required (no session/CSRF).

---

## XDR Scenario Runner

**Purpose:** Controlled detection validation — verify that detection rules fire as expected.

**Config:** `config/scenarios.php`  
**Job:** `ExecuteScenarioRunJob` (database queue)  
**Models:** `ScenarioRun`, `ScenarioEvidence`

Two modes:
- `stub` — synthetic evidence only, no real pipeline required
- `real` — publishes events to ingestion-gateway, polls `security_alerts` by actor_key + alert_type

Scenarios: `sql_injection_emulation`, `failed_login_burst` (active — identity/cloud), plus three shadow-only endpoint scenarios.

**Constraint:** Real mode must go through pipeline. Never create SOC alerts/incidents directly from the runner.

---

## Trace Investigation

**Purpose:** Cross-service trace correlation — follow a trace_id from ingestion through to incidents.

**Service:** `TraceInvestigationController`, `TraceApiController`  
**Redactor:** `app/Support/TraceRedactor`

TraceRedactor rules:
- JSON payload fields decoded + redacted: `payload`, `evidence`, `raw_event`, `metadata`, `config`, `results`
- Sensitive keys → `[REDACTED]`; emails → `[EMAIL]`
- Investigative identifiers preserved at top level (actor_key, ip, alert_type, trace_id)
- Presentation-layer only — DB never mutated

---

## Detection Rule Governance

**Purpose:** Full rule lifecycle management with hard gates preventing unsafe promotion.

**Service:** `RuleRegistryService`  
**Registry:** `docs/detection/rules/registry.v1.json` (immutable source of truth)  
**DB table:** `detection_rules` (mutable governance state)

Lifecycle: `draft` → `shadow` → `staged_active` → `deprecated`

Hard gates (enforced in PHP + Python validator):
- endpoint domain → BLOCKED from staged_active
- threat-intel domain → BLOCKED from staged_active
- `ACTIVE_ALLOWLIST` intentionally empty

---

## Entity Graph

**Purpose:** Projection layer that builds an entity registry from existing alert/incident data.

**Service:** `EntityGraphService`  
**Key methods:**
- `upsertEntity()` — firstOrCreate + `DB::table()->increment()` (bypass Eloquent cast layer)
- `upsertRelationship()` — deduplicates, increments observation_count
- `appendObservation()` — ALWAYS INSERT (append-only)
- `projectFromAlerts()` — reads security_alerts, builds entities (read-only projection)
- `buildAdjacency()` — depth-bounded graph traversal (limit 30 nodes)

Entity types: user, host, ip, domain, process, file_hash, alert, incident, trace

**Constraint:** Entity tables are projection/index layer — not authoritative. Security_alerts is authoritative.

---

## Entity Risk Scoring

**Purpose:** Deterministic weighted risk scoring for investigation prioritization.

**Service:** `EntityRiskScoringService`

Factor weights (selected):
- critical_alerts: 3.0, c2_indicator: 3.5, incident_involvement: 3.0
- shadow_alert_advisory: 0.5 (endpoint/shadow alerts — advisory only)
- Score = min(sum of weighted factors, 10.0)

Risk levels: critical (≥7.5), high (≥5.0), medium (≥2.5), low (≥0.0)

**Constraint:** Shadow alert advisory factor is informational only. Risk score does NOT automatically trigger any response.

---

## Investigation Workflow

**Purpose:** Structured investigation lifecycle for SOC analysts.

**Service:** `InvestigationOrchestratorService`  
**ID format:** `INV-YYYY-NNNNN`

State machine:
```
new → triaged → investigating → escalated → contained_manual → resolved → closed
                              → false_positive → closed
```

`contained_manual` state = analyst documented a manual external action. ZERO system execution.

All state transitions, assignments, and notes are recorded in append-only tables:
- `investigation_events` — never updated/deleted
- `investigation_assignments` — deactivates previous via is_active=false (history preserved)

---

## Response Planning

**Purpose:** Advisory-only recommendation documentation. No execution whatsoever.

**Service:** `ResponsePlanningService`  
**ID format:** `RP-YYYY-NNNNN`

State machine: `draft` → `pending_approval` → `approved` → `completed_documented`

Disclaimer enforced everywhere: *"Recommendations are advisory-only and were not automatically executed by the platform."*

Action types (all `recommend_*` prefix):
- `recommend_reset_password`, `recommend_revoke_session`, `recommend_disable_user`
- `recommend_block_ip`, `recommend_block_domain`, `recommend_monitor_only`
- `recommend_collect_forensics`, `recommend_remove_persistence`, `recommend_isolate_host` (advisory_only=true), `recommend_notify_stakeholders`

**Hard constraints:**
- No `execute_*` column exists in response_plan_actions
- No network call, no process management, no DB side effects from approval
- `completed_documented` = analyst confirmed they took action externally

---

## Export Center

**Purpose:** Auditable, redacted exports of platform artifacts.

**Service:** `ReportExportService`  
**ID format:** `EXP-YYYY-NNNNN`

Export types: `investigation`, `response_plan`, `entity_risk`, `trace`, `incident_bundle`  
Formats: `json` (pretty-printed), `markdown` (formatted), `html` (self-contained inline CSS)

Export pipeline:
1. Load source data
2. `TraceRedactor::deep()` on entire structure (no secret leakage)
3. Render to format
4. Append-only record in `export_audit_logs`

**Constraint:** Every export creates a new audit record — never updated/deleted.

---

## Security Hardening

**Purpose:** Platform-level security observability — secret validation, service auth status, audit integrity.

**Service:** `InternalAuthService`, `SecretsValidationService`  
**Artisan:** `php artisan security:validate-secrets [--record]`

Internal service auth:
- Tokens: `X-Internal-Service-Token` header, time-bounded HMAC (5-min window)
- Event signatures: `event_signature` field on event envelopes (optional, non-destructive)
- Failure always logged to `security_hardening_events`, NEVER thrown/destructive

`security_hardening_events` is append-only. Records: auth_failure, signature_failure, secret_warning, startup_validation.

---

## Resilience Validation

**Purpose:** Validate operational resilience under degraded conditions. 14 scenarios.

**Service:** `ResilienceValidationService`  
**Artisan:** `php artisan resilience:validate [--scenario=X] [--list]`  
**Scripts:** `xdr_resilience_validate.py`, `xdr_fault_injection.py`

Scenario types:
- `simulation` — validates code capability without live services (9 scenarios)
- `active` — executes real checks against the running platform (5 scenarios)

Recovery metrics tracked per run: `recovery_duration_seconds`, `consumer_lag_peak`, `replay_idempotent`, `failed_signature_count`, `auth_failure_count`, etc.

Reports written to `storage/resilience/`. Dashboard at `/resilience` (admin only).

---

## Grafana Dashboards (all in `docs/grafana/`)

All dashboards use PostgreSQL datasource (`DS_POSTGRES`). Import via Grafana UI.

| File | UID | Purpose |
|---|---|---|
| trace-flow-dashboard.json | trace-flow-v1 | Trace propagation |
| endpoint-dashboards.json | endpoint-xdr-v1 | Shadow telemetry |
| entity-risk-dashboard.json | xdr-entity-risk-v1 | Entity risk scores |
| investigation-workflow-dashboard.json | xdr-investigation-v1 | Investigation SLA |
| response-planning-dashboard.json | xdr-response-v1 | Response plan states |
| export-reporting-dashboard.json | xdr-export-reporting-v1 | Export audit |
| security-hardening-dashboard.json | xdr-security-hardening-v1 | Auth/sig failures |
| resilience-dashboard.json | xdr-resilience-v1 | Recovery metrics |
