# Demo Readiness

Last updated: 2026-05-17

---

## What Is Demo-Ready

Every feature listed here is backed by passing automated tests. Nothing listed here is simulated in the demo layer.

### Event Pipeline (Go/Python)
- Telemetry ingestion via `POST /v1/ingest` with HMAC-SHA256 signature verification
- Normalizer: identity/cloud/SaaS + endpoint telemetry → `telemetry.normalized`
- Correlation: 12 staged_active identity/cloud/SaaS rules generating real alerts
- Alert writer: idempotent PostgreSQL persistence with trace_id, OpenSearch indexing
- Incident builder: deterministic incident grouping and upsert

### Laravel SOC Control Plane
- SOC dashboard with maturity indicators, service separation status, recent incidents
- Incident workflow with state machine, analyst assignment, notes, MITRE mapping
- Detection rule governance: lifecycle promotion with hard gates (endpoint BLOCKED)
- XDR Scenario Runner: detection validation in stub and real pipeline mode
- Trace Investigation: cross-service correlation with TraceRedactor (no secret leakage)
- Endpoint UI: shadow telemetry inventory (no promotion, no active output)

### Investigation & Response (New)
- **Entity Graph**: search entities by type, view relationships, timeline, adjacency graph
- **Entity Risk Scoring**: deterministic 0–10 score with explainable factors breakdown
- **Investigation Workflow**: create → triage → investigate → escalate → resolve → close
- **Response Planning**: advisory-only plans, recommendations, approval workflow
- **Export Center**: one-click JSON/Markdown/HTML exports, audit log, disclaimer enforced

### Security Observability (New)
- **Security Hardening Dashboard**: live secret validation status, service auth config, audit table counts
- **Resilience Validation**: run any of 14 failure scenarios, view recovery metrics

---

## How to Start for Demo

### Minimum (Laravel only — no pipeline)
```powershell
# Ensure PostgreSQL is running, run migrations
php artisan migrate
php artisan db:seed --class=DemoSeeder

# Start Laravel
php artisan serve

# Open: http://localhost:8000
# Login: admin@example.com / password (or from seeder output)
```

### Full Stack (with live pipeline)
```powershell
# Infrastructure
docker compose up -d

# Go + Python services
docker compose --profile strangler up -d

# Laravel queue worker (required for Scenario Runner async job)
php artisan queue:work --sleep=1 --tries=1

# Optional: Laravel scheduler
php artisan schedule:work

# Open: http://localhost:8000
```

---

## Demo Golden Paths

### Path 1 — Threat Detection Flow (2 min)
1. `/soc` — show dashboard: service separation, MITRE coverage, recent incidents
2. `/soc/api/alerts` — show recent identity/cloud/SaaS alerts with trace_ids
3. Click an incident → show affected entities, evidence chain, MITRE mapping

**Talking points:**
- Correlation engine processes 77,000+ events/second
- 6h soak PASS: p95 < 300ms, zero fallbacks, stable memory
- Endpoint alerts stay shadow-only (architecture isolation)

### Path 2 — Investigation Workflow (3 min)
1. `/investigations` — show investigation queue
2. Create new investigation: select entity type, severity, link to alert
3. Show state machine: new → triaged → investigating
4. Add analyst note, assign to analyst
5. Show audit trail — append-only, every action preserved

**Talking points:**
- State machine enforced at service layer (InvalidArgumentException on invalid transitions)
- Assignment history preserved — previous assignments deactivated, not deleted
- All events append-only — no DB mutation

### Path 3 — Entity Graph & Risk Scoring (2 min)
1. `/entity` — search for a user or host entity
2. Show entity timeline (sorted observations from operational events)
3. Show adjacency graph — entity relationships
4. `/entity-risk` — risk dashboard with top-risk entities
5. Click an entity → risk breakdown with explainable factors

**Talking points:**
- Deterministic scoring — same data → same score (no randomness)
- Advisory shadow indicators for endpoint/C2 (not active alerts)
- Risk snapshots create auditable history

### Path 4 — Response Planning & Export (2 min)
1. `/response-plans` — show recommendations for a high-risk entity
2. Show recommended actions (reset_password, revoke_session, etc.)
3. Create response plan → submit → approve
4. `/exports` — export investigation as JSON, Markdown, or HTML
5. Show export history — EXP-YYYY-NNNNN IDs, append-only audit log

**Talking points:**
- Zero system execution — all actions are advisory recommendations only
- Disclaimer enforced in every export format
- TraceRedactor strips passwords/tokens from all exported data

### Path 5 — Security Hardening & Resilience (2 min)
1. `/security/hardening` — show secret validation warnings, service auth table
2. Run `php artisan security:validate-secrets` in terminal — show output
3. `/resilience` — show 14 scenario grid with statuses
4. Run `broker_restart` or `signature_verification_failure` scenario
5. Show recovery metrics and findings

**Talking points:**
- Internal service auth: time-bounded HMAC tokens, 5-minute window
- Invalid signatures logged but never destructive
- All 14 resilience scenarios pass — recovery validated

### Path 6 — Detection Governance (2 min)
1. `/detection` — show rule registry with 24 rules
2. Show lifecycle stages: draft → shadow → staged_active → deprecated
3. Show MITRE coverage matrix
4. Attempt to promote an endpoint rule → show hard gate blocking
5. Run `python scripts/xdr_rule_registry_validate.py` — show 21/21 PASS

---

## What Is Shadow-Only (Not Demo-Active in Alert Path)

- Endpoint behavioral detection (SCHEDULED_TASK_PERSISTENCE, C2_BEACON_PATTERN, etc.)
- DNS/proxy/firewall correlation
- Threat intel IOC matching
- Endpoint agent in production (demo as simulation/fixture mode only)

These are visible in the platform as shadow alerts and in the `/endpoint` UI but do NOT produce security_alerts entries.

---

## Known Constraints

| Constraint | Why |
|---|---|
| Queue worker must run for Scenario Runner | `QUEUE_CONNECTION=database` — async job dispatch |
| Endpoint alerts never in security_alerts | Architecture invariant — shadow topic only |
| Response actions are advisory-only | No execute_* columns exist by design |
| TraceRedactor is presentation-only | DB is never mutated for redaction |
| Registry ACTIVE_ALLOWLIST is empty | Hard gate — no domain promotion without soak |
| 591 tests require PostgreSQL | No SQLite — `RefreshDatabase` uses real transactions |

---

## Quick Validation Before Demo

```powershell
php artisan test                                          # must be 591 green
python scripts/xdr_rule_registry_validate.py             # must be 21/21 PASS
python scripts/xdr_resilience_validate.py                # must be 8/8 PASS
php artisan security:validate-secrets                    # review warnings
```

---

## Architecture Narrative (30 seconds)

> This is a distributed XDR-like detection and investigation platform. Telemetry flows through a Go ingestion gateway and normalizer into a Go correlation worker that runs 12 identity/cloud/SaaS detection rules. Alerts are persisted by a Python writer and grouped into incidents by a Python incident builder. The Laravel SOC control plane provides investigation workflow, entity graph, risk scoring, response planning, export, security hardening, and resilience validation. Everything connects through Redpanda. Endpoint detection is shadow-only — visible but not active — with a hard architectural gate preventing premature promotion.
