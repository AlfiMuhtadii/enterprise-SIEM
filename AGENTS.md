# AGENTS.md

Compact source of truth for AI coding sessions (Codex, Cursor, Copilot, etc.).
Read this before making any change. For Claude Code, prefer CLAUDE.md which has fuller operational rules.

Last updated: 2026-06-22

---

## Current State

| Metric | Value |
|---|---|
| PHP tests | **3077** (run: `php artisan migrate:fresh --force && php artisan test`) |
| Python tests | **186** (run: `python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v`) |
| Detection rules | **133** (12 staged_active, 121 shadow) |
| Hunt domains | **161** |
| Active correlation scope | `identity-cloud` (Go engine, 6h soak PASS 2026-05-14) |
| Shadow / advisory | endpoint, DNS/proxy/firewall, threat-intel |

---

## Architecture

Laravel SOC control plane + polyglot microservices (Go + Python) over Redpanda event bus. Strangler migration in progress.

### Services

| Service | Language | Role |
|---|---|---|
| Laravel SOC | PHP/Blade | Control plane: RBAC, dashboard, incidents, investigations, response, export, resilience, hardening, all subsystems |
| ingestion-gateway | Go | Signed telemetry ingestion (`POST /v1/ingest`, HMAC-SHA256 X-XDR-Signature), rate limiting, backpressure |
| normalizer-worker | Go | `telemetry.raw` → `telemetry.normalized`, multi-format normalizers |
| correlation-worker | Go | identity/cloud/SaaS (active) + endpoint shadow + cross-domain shadow |
| alert-writer-service | Python/FastAPI | `xdr.alerts` → PostgreSQL `security_alerts` + OpenSearch + `alerts.created` |
| incident-builder-service | Python/FastAPI | `alerts.created` → `security_incidents` + `incidents.updated` |
| ai-rag-service | Python/FastAPI | Analyst assist, Qdrant vector store, heuristic fallback |
| endpoint-agent | Python stdlib | Behavioral endpoint visibility (process ancestry, persistence, network, snapshots, signed heartbeat). Posts to ingestion-gateway. |

### Event Flow

```
telemetry source
  → ingestion-gateway  (POST /v1/ingest, HMAC-SHA256 X-XDR-Signature)
  → telemetry.raw  (Redpanda)
  → normalizer-worker
  → telemetry.normalized  (Redpanda)
  → correlation-worker
  → xdr.alerts              (identity/cloud/SaaS — active, persisted)
  → xdr.alerts.shadow.endpoint  (endpoint shadow — NOT consumed by alert-writer)
  → alert-writer-service
  → security_alerts (PostgreSQL) + alerts.created (Redpanda)
  → incident-builder-service
  → security_incidents (PostgreSQL) + incidents.updated (Redpanda)
  → Laravel SOC control-plane
```

### Infrastructure

| Component | Role |
|---|---|
| Redpanda | Kafka-compatible event bus |
| PostgreSQL | Primary SOC state |
| ClickHouse | Async analytics (not on alert write path) |
| OpenSearch | Alert indexing (graceful DLQ fallback) |
| Qdrant | Vector store for AI/RAG |
| Grafana | Observability dashboards |

Docker profiles: default = infra only; `--profile strangler` = Go+Python pipeline; `--profile app` = Laravel stack.

---

## Laravel SOC Modules

| Module | Route Prefix | Key Permission |
|---|---|---|
| SOC Dashboard | `/soc` | `soc:dashboard` |
| Security Alerts | `/security/alerts` | `security.view` |
| Detection Rules | `/soc/rules` | `soc:rules.manage` |
| Endpoint Agents | `/endpoint-agents` | `soc:agents.view` |
| XDR Scenario Runner | `/scenario` | `soc:scenario.view` |
| Trace Investigation | `/traces` | `soc:trace.view` |
| Detection Governance | `/detection` | `soc:detection.view` |
| Entity Graph | `/entity` | `soc:entity.view` |
| Entity Risk | `/entity-risk` | `soc:entity.view` |
| Investigation Workflow | `/investigations` | `soc:investigation.view` |
| Response Planning | `/response-plans` | `soc:response.view` |
| Export Center | `/exports` | `soc:export.view` |
| Security Hardening | `/security/hardening` | `security.view` |
| Resilience Validation | `/resilience` | `soc:resilience.view` |
| Endpoint Behavioral | `/endpoint-agents/{id}/activity` | `soc:agents.view` |
| Threat Hunting | `/threat-hunts` | `soc:hunt.view` |
| Cross-Domain Correlation | `/cross-domain` | `soc:correlation.view` |
| Active Response | `/response-execution` | `soc:response.execute` |
| Streaming Telemetry | `/endpoint-stream` | `soc:stream.view` |
| Ops Hardening | `/ops` | `soc:ops.view` |
| DNS/Proxy/Firewall Analytics | `/network` | `soc:network.view` |
| SOC Collaboration | `/soc/collaboration` | `soc:collab.view` |
| Enterprise Integrations | `/integrations` | `soc:integrations.view` |
| UEBA | `/ueba` | `soc:ueba.view` |
| Endpoint Fleet | `/endpoint-fleet` | `soc:fleet.view` |
| Low-Level Telemetry | `/lltet` | `soc:agents.view` |
| Detection Engineering | `/detection-engineering` | `soc:detection.view` |
| Investigation Graph | `/investigation-graph` | `soc:investigation.view` |
| SOAR Orchestration | `/soar` | `soc:soar.view` |
| HA Reliability | `/ha-reliability` | `soc:ops.view` |
| Compliance Governance | `/compliance` | `soc:audit.view` |
| Capacity Governance | `/capacity` | `soc:ops.view` |
| Release Governance | `/release` | `soc:audit.view` |
| Advanced Detection | `/advanced-detection` | `soc:detection.view` |
| Sensor Hardening | `/sensor-hardening` | `soc:agents.view` |
| Multi-Tenant Isolation | `/multi-tenant` | `soc:audit.view` |
| Soak/Chaos Validation | `/soak-chaos` | `soc:ops.view` |
| Pilot Readiness | `/pilot-readiness` | `soc:ops.view` |
| Pilot Execution | `/pilot-execution` | `soc:ops.view` |
| Operational Intelligence | `/operational-intelligence` | `soc:ops.view` |
| Analyst Optimization | `/analyst-optimization` | `soc:ops.view` |
| Telemetry Scale Pilot | `/telemetry-scale` | `soc:ops.view` |
| Long-Running Ops | `/long-running-ops` | `soc:ops.view` |
| Sensor Advanced Telemetry | `/sensor-advanced` | `soc:agents.view` |
| Enterprise Deployment | `/enterprise-deployment` | `soc:audit.view` |
| Enterprise Ops Automation | `/enterprise-ops` | `soc:ops.view` |
| Commercial Readiness | `/commercial-readiness` | `soc:audit.view` |
| Enterprise Scale HA | `/enterprise-scale-ha` | `soc:ops.view` |
| XDR Certification | `/xdr-certification` | `soc:audit.view` |
| Release Candidate | `/release-candidate` | `soc:audit.view` |
| XDR Maturity | `/xdr-maturity` | `soc:audit.view` |
| Demo Platform | `/demo-platform` | `soc:demo.view` |

---

## RBAC

Gate forward in `AuthServiceProvider::boot()`: `Gate::before()` → `Rbac::can()` for all `soc:*` abilities.
Required for Blade `@can('soc:*')` to work. Without it, all SOC buttons are invisible.

| Role | Key Permissions |
|---|---|
| admin | All + `security.view` |
| analyst | incidents, investigations, response, export, scenario.view/replay |
| viewer | Read-only: dashboard, incidents, entities, investigations |
| scenario_operator | scenario.view/run/replay/export, entity.view, investigation.view |
| detection_engineer | scenario + registry.manage + rules.manage + evidence.view + investigation.create |

---

## Database Conventions

### ID Prefixes (generated at service layer, not DB default)

| Prefix | Table |
|---|---|
| `INV-YYYY-NNNNN` | investigations |
| `RP-YYYY-NNNNN` | response_plans |
| `CMD-YYYY-NNNNN` | endpoint_response_commands |
| `EXP-YYYY-NNNNN` | export_audit_logs |
| `RES-YYYY-NNNNN` | resilience_runs |
| `SNAP-YYYY-NNNNN` | endpoint_process_snapshots |
| `EC-YYYY-NNNNN` | endpoint_execution_chains |
| `BP-YYYY-NNNNN` | endpoint_beacon_patterns |
| `FIND-YYYY-NNNNN` | endpoint_behavioral_findings |

### Append-Only Tables
NEVER `UPDATE` or `DELETE`. Full list in CLAUDE.md Forbidden Changes.
Examples: `export_audit_logs`, `investigation_events`, `security_hardening_events`, `endpoint_agent_heartbeats`, `threat_hunts`, `threat_hunt_queries`, `threat_hunt_results`, all `*_audit`, `*_events`, `*_snapshots`, `*_validation_runs`.

### Idempotency
`xdr_operational_events` uses `ON CONFLICT DO NOTHING` — replay-safe, deterministic.

### PostgreSQL HAVING Gotcha
Cannot reference SELECT aliases in HAVING clause. Use subquery:
```php
// WRONG
->groupBy('col')->havingRaw('cnt > 1')

// CORRECT
DB::select('SELECT COUNT(*) FROM (SELECT col FROM tbl GROUP BY col HAVING COUNT(*) > 1) sub')
```

### Models: explicit `$table`
When class name doesn't auto-resolve to the migration table name, add `protected $table = 'table_name'`.
Examples: `ProductionRiskRegister`, `DeploymentAcceptanceAudit`, `FinalXdrCertificationAudit`.
Always verify pluralization matches migration table name before adding a model.

---

## Internal Security

### Service Token Auth
- `InternalAuthService::signToken(serviceId)` → time-bounded HMAC token (5-min window)
- Middleware: `InternalServiceAuthMiddleware` on `/api/internal/*`
- Never bypass this middleware

### Event Signatures
- `InternalAuthService::signEvent(event)` → `sha256=<hex>` over `event_id|event_type|occurred_at|trace_id`
- `verifyEvent()` NEVER throws — returns `false`, logs `signature_failure` hardening event
- `TraceRedactor` presentation-layer only — never mutates DB

### Required Secrets (generate with `openssl rand -hex 32`)
- `XDR_INGEST_SECRET` — ingestion-gateway HMAC signing
- `XDR_INTERNAL_AUTH_SECRET` — internal service token auth
- `XDR_NORMALIZER_INTERNAL_TOKEN`, `XDR_ALERT_WRITER_INTERNAL_TOKEN` — service-to-service tokens

---

## Detection Rule Registry

File: `docs/detection/rules/registry.v1.json` — 133 rules total.

| Category | Count |
|---|---|
| staged_active (identity/cloud/SaaS) | 12 |
| shadow (endpoint behavioral) | 32 |
| shadow (endpoint LLTET) | 8 |
| shadow (UEBA) | 9 |
| shadow (network: DNS/proxy/firewall) | 9 |
| shadow (threat-intel/IOC) | 3 |
| shadow (advanced: cred/persist/evasion/lateral/container) | 20 |
| shadow (detection depth Phase 2) | 40 |

`ACTIVE_ALLOWLIST` intentionally empty. Never add without domain-specific 6h soak PASS.
Validator: `python scripts/xdr_rule_registry_validate.py` (21 checks, exit 0 = PASS).

---

## Correlation Engine Config

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
```

Circuit breaker: 3 consecutive failures → fallback to legacy PHP. **Do NOT expand scope beyond `identity-cloud`.**

---

## Response / SOAR Constraints

- `response_plan_actions.action_types` are `recommend_*` ONLY — no `execute_*`
- Endpoint response `ALLOWED_TYPES`: `noop`, `collect_diagnostics`, `refresh_config`, `upload_health_snapshot`
- All governance services: `autonomous_approval=false`, `self_approve_blocked=true`
- Chaos/soak bounded: `MAX_BOUNDED_DURATION=600s`
- Pilot bounded: `MIN_ENDPOINTS=5`, `MAX_ENDPOINTS=20`

---

## Standard Validation Commands

```powershell
php artisan migrate:fresh --force && php artisan test
# → 3077 passed, 0 failures

python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
# → 186 passed

python scripts/xdr_rule_registry_validate.py
# → PASS rules=133 checks=21/21

python scripts/xdr_contract_validate.py --output reports/xdr_contract_validation.json
python scripts/xdr_fleet_simulation_validate.py   # → 8/8 passed
php artisan security:validate-secrets
docker compose config --quiet
```

---

## Key Invariants

1. Endpoint shadow alerts → `xdr.alerts.shadow.endpoint`, NEVER to `xdr.alerts` active topic
2. Append-only tables: never UPDATE/DELETE — TraceRedactor is presentation-layer only
3. `xdr_operational_events`: replay-safe with `ON CONFLICT DO NOTHING`
4. All advisory services: `is_advisory=true`, no autonomous containment/execution
5. `Gate::before()` in `AuthServiceProvider` is mandatory — without it all `@can('soc:*')` returns false
6. Never run parallel `php artisan test` against same PostgreSQL instance
7. Always use `migrate:fresh --force` before tests to avoid `QueryException` from stale schema

---

## Antigravity (Reviewer Agent) Constraints & Aligned Rules

* **Role Definition**: Antigravity (Gemini) operates strictly as a **Read-Only Audit and Design Reviewer**.
* **System Visibility**: Authorized to read and analyze all codebase files, configuration settings, environment states, database models/migrations, and tests across the entire Enterprise SIEM platform.
* **Authorized File Modifications**: Antigravity is restricted from modifying functional code (Go/Python/PHP codebase logic). It is only authorized to write and manage:
  * Master audit reports and checklists (e.g., `REVIEW_REPORTS.md`, `REVIEW_ALL.md`).
  * Process tracking documents (e.g., `REVIEW_BACKLOG.md`, `REVIEW_COMPLETED.md`).
  * Environment templates and file exclusion rules (e.g., `.env.example`, `.gitignore`).
  * Agent instructions and workflow files (e.g., `GITHUB_PROJECT_WORKFLOW.md`, `AGENTS.md`, `claude.md`).
* **Operational Mode**:
  1. Inspect the codebase incrementally and explain what files are being opened.
  2. Document code analysis results in English using the structured audit template (IG-1, IG-2 format).
  3. Maintain the `Backlog Candidate List` (for high-confidence risks) and `Not Backlog` lists separately.
  4. Propose backlog tasks by appending them to the bottom of `REVIEW_BACKLOG.md` locally. Do NOT push issues directly to GitHub.
* **Backlog Boundary**: Tasks listed in [REVIEW_BACKLOG.md](REVIEW_BACKLOG.md) and all `[BACKLOG-XXX]` issues on GitHub are strictly reserved for implementation agents (Claude/Codex). Antigravity must never attempt to resolve, write functional code for, or close backlog implementation tasks.



