# Feature Registry

Canonical module and capability inventory for the XDR platform.
Last updated: 2026-06-27

For chronological history see `docs/architecture/ARCHITECTURE_CHANGELOG.md`.
For current test/rule baselines see `docs/validation/VALIDATION_BASELINES.md`.
For current correlation posture see `docs/operations/OPERATIONAL_POSTURE.md`.

---

## Laravel SOC Control Plane — Module Map

```
Route prefix        Module                          Permission gate
──────────────────────────────────────────────────────────────────
/soc                SOC Dashboard                   soc:dashboard.view
/soc/agents         Endpoint Agent Mgmt             soc:agents.manage
/soc/rules          Detection Rule Mgmt             soc:rules.manage
/soc/hunts          Threat Hunt (legacy route)      soc:dashboard.view

/scenario           XDR Scenario Runner             soc:scenario.view
/traces             Trace Investigation             soc:trace.view
/detection          Detection Rule Governance       soc:rules.govern

/endpoint           Endpoint Telemetry UI           soc:agents.manage
/endpoint-agents    Endpoint Agent Inventory        soc:agents.manage
  /{id}/activity               Activity timeline
  /{id}/process-tree           Process ancestry
  /{id}/persistence            Persistence inventory
  /{id}/process-network        Process-to-network correlation
  /{id}/long-lived             Long-lived processes (>1h)
  /{id}/analytics              Behavioral findings dashboard
  /{id}/analytics/chains       Execution chain timeline
  /{id}/analytics/beacon       Beacon pattern view
  /{id}/analytics/rare-parent-child
  /{id}/analytics/persistence-correlation
  /response-queue              Response approval queue
  /commands/{commandId}        Command detail + lifecycle

/entity             Entity Graph                    soc:entity.view
/entity-risk        Entity Risk Scoring             soc:entity.view

/investigations     Investigation Workflow          soc:investigation.view
/response-plans     Response Planning               soc:response.view
/exports            Export Center                   soc:exports.view
/security/hardening Security Hardening Dashboard    soc:security.view
/resilience         Resilience Validation           soc:security.view

/threat-hunts       Threat Hunting & Query Engine   soc:dashboard.view
  /new                Query builder
  /history            Hunt history + replay
  /pivot              Pivot explorer
  /beacon             Beacon investigation
  /persistence        Persistence investigation
  /chains             Chain explorer
  /{huntId}           Hunt detail

/security/alerts/attribution   Alert Attribution (MITRE TTP + GeoIP/ASN)   soc:dashboard.view

/detection/promotion-readiness          Detection Domain Promotion Readiness     soc:rules.govern
/detection/shadow-promotion-decisions   Shadow-Ready Promotion Decision          soc:rules.govern
/detection/endpoint-soak-plan           Endpoint Shadow Domain Soak Plan         soc:rules.govern
/detection/stability-freeze-v2          Stability Evidence Freeze v2             soc:rules.govern
/detection/stability-freeze-v3          Stability Evidence Freeze v3             soc:rules.govern
/detection/stability-freeze-v4          Stability Evidence Freeze v4             soc:rules.govern
/detection/fixture-batches              Detection Replay Fixture Batches         soc:rules.govern
/detection/domain-soak-simulations      Domain Soak Simulations                  soc:rules.govern
/detection/confidence-source-refresh    Confidence Source Refresh                soc:rules.govern
/detection/soak-execution-plan          Real Domain Soak Execution Plan          soc:rules.govern
/detection/phase1-soak                  Phase 1 Soak Evidence (P1G-01..P1G-08)   soc:rules.govern
/detection/rule-evidence-governance     Rule Evidence Governance                 soc:rules.govern

/soak-chaos         Soak & Chaos Validation Dashboard   soc:audit.view
  /chaos              Chaos explorer
  /replay             Replay recovery
  /telemetry          Telemetry continuity
  /drift              Drift detection
  /queue              Queue pressure
  /worker             Worker restart
  /recovery           Recovery timeline
  /stability          Stability dashboard

/pilot-tenants      Pilot Tenant Onboarding (index, /{id})   soc:audit.view
/endpoint-enrollments  Real Endpoint Enrollment              soc:agents.manage

/pilot-execution    Real Pilot Execution                soc:audit.view
  /enrollment, /telemetry, /reviews, /rollback, /observation, /drift, /audit, /health

/op-intel           Operational Intelligence Dashboard   soc:dashboard.view
  /confidence, /false-positives, /analyst-optimization, /scale, /governance, /long-running

/easm               EASM Passive Posture Monitoring     soc:security.view
  /posture, /findings, /risk-trend, /history

/enterprise-pilot   Enterprise Pilot Readiness Matrix   soc:audit.view
/shadow-soak        Shadow Domain Soak Harness          soc:shadow.soak.view
/dlq                DLQ Review & Replay                 soc:dlq.view
/advisory           Shadow Alert Advisory Findings      soc:advisory.view
```

---

## API Routes

```
POST /api/agents/enroll
POST /api/agents/{id}/heartbeat
GET  /api/agents/{id}/config
GET  /api/agents/{id}/commands
POST /api/agents/{id}/commands/{cmdId}/ack
POST /api/agents/{id}/commands/{cmdId}/result
POST /api/agents/{id}/behavioral-snapshot

GET  /api/traces/*                              (6 endpoints)
GET  /api/entities/*
GET  /api/entity-risk/*
GET  /api/investigations/*
GET  /api/response-plans/*
GET  /api/exports/*

GET  /api/threat-hunts
GET  /api/threat-hunts/{huntId}
GET  /api/threat-hunts/{huntId}/results
GET  /api/threat-hunts/pivot/{entityType}?id=
POST /api/threat-hunts/query

/api/internal/*     X-Internal-Service-Token required (InternalServiceAuthMiddleware)
```

---

## RBAC Roles and Key Permissions

Config: `config/soc.php` `permissions` array.
Gate forward: `AuthServiceProvider::boot()` → `Gate::before()` → `Rbac::can()`.

| Role | Key Capabilities |
|---|---|
| admin | All permissions including security.view, agents.manage, rules.manage |
| analyst | Incidents, investigations, response, export, scenario.view/replay |
| viewer | Read-only: dashboard, incidents, entities, investigations, response, reports |
| scenario_operator | scenario.view/run/replay/export, entity.view, investigation.view |
| detection_engineer | scenario + registry.manage + rules.manage + evidence.view + investigation.create |

---

## XDR Scenario Runner

Config: `config/scenarios.php`

| Key | Default | Description |
|---|---|---|
| `pipeline_mode` | `stub` | `stub` = synthetic / `real` = publishes to ingestion-gateway |
| `ingestion_gateway_url` | `http://127.0.0.1:8091` | Must match Go service |
| `ingestion_gateway_secret` | — | Matches `XDR_INGEST_SECRET` |
| `pipeline_timeout_seconds` | `30` | Seconds to poll for real pipeline artifacts |
| `pipeline_poll_ms` | `1000` | Polling interval |
| `stage_delay_ms` | `350` | Inter-stage delay (0 in production) |

Job: `ExecuteScenarioRunJob` — dispatched via database queue (`QUEUE_CONNECTION=database`).
Worker: `php artisan queue:work --sleep=1 --tries=1`

Available scenarios:

| Scenario ID | Rule Triggered | Pipeline Mode |
|---|---|---|
| `sql_injection_emulation` | `CLOUD_SUSPICIOUS_OBJECT_ACCESS` | active |
| `failed_login_burst` | `IDENTITY_MFA_FAILURE_BURST` | active |
| `suspicious_dns_query` | shadow endpoint only | shadow |
| `ioc_match` | shadow endpoint only | shadow |
| `suspicious_powershell_event` | shadow endpoint only | shadow |

Real-mode poll: `security_alerts` by `actor_key = 'scenario-actor@test.local'` + `alert_type` + `detected_at >= started_at`.

---

## Endpoint Agent

Location: `services/endpoint-agent/agent.py`
Stdlib-only (no pip). Linux `/proc`-based.

Collectors:

| Collector | Method | Notes |
|---|---|---|
| Process | `/proc/[pid]/status` + `/proc/[pid]/cmdline` | No subprocess |
| Network | `/proc/net/tcp` + `/proc/net/tcp6` hex parse | ESTABLISHED only, no loopback |
| DNS | JSONL fixture (`dns_fixture_path`) or syslog fallback | No packet sniff |
| File write | `os.walk + stat` on `watch_paths` only | No full filesystem scan |
| Scheduled tasks | `/var/spool/cron` + `/etc/cron.d` | Read-only |
| Services | `/etc/systemd/system/*.service` | Detects new files |
| Behavioral snapshot | `build_process_inventory` + `collect_persistence_items` + `build_network_correlations` | Posted to `/api/agents/{id}/behavioral-snapshot` |

Test path overrides (required — do NOT remove):
- `collect_processes(proc_root=...)` — allows fake `/proc` in tests
- `collect_network(proc_net_tcp=..., proc_net_tcp6=...)` — allows fake `/proc/net/tcp` in tests

Security boundaries (MUST NOT be removed):
- No credential collection
- No packet sniffing
- No kernel module
- No process killing
- No persistence install
- No privilege escalation
- No active response / quarantine / containment
- Output MUST remain `xdr.alerts.shadow.endpoint` only

Run:
```bash
python services/endpoint-agent/agent.py --config services/endpoint-agent/config.json --once
python services/endpoint-agent/agent.py --config services/endpoint-agent/config.json
```

---

## Endpoint Response Approval Framework

Allowed command types (`ALLOWED_TYPES`): `noop`, `collect_diagnostics`, `refresh_config`, `upload_health_snapshot`

Forbidden command types (`FORBIDDEN_TYPES`): `isolate_host`, `kill_process`, `quarantine_file`, `delete_file`, `remove_persistence`, `block_ip`, `disable_service`, `wipe_disk`

State machine: `draft → pending_approval → approved → dispatched → acknowledged → completed` (also: `cancelled`, `failed`)

Route ordering note: literal routes (`/endpoint-agents/response-queue`, `/endpoint-agents/commands/{id}`) MUST be declared BEFORE the wildcard `/{agentId}` route. Any new literal paths under `/endpoint-agents/` must precede `{agentId}`.

---

## Threat Hunting & Investigation Query Engine

Service: `App\Services\ThreatHuntingService`

Safety bounds: `MAX_RESULTS=500`, `MAX_QUERY_WINDOW_DAYS=30`, `MAX_GRAPH_DEPTH=5`

Supported query domains with allowlisted fields:

| Domain | Example Fields |
|---|---|
| `processes` | process_name, parent_process_name, command_line (contains), is_shell, is_long_lived, duration_seconds |
| `persistence_items` | item_type, item_key (contains), is_new |
| `execution_chains` | chain_length, chain_score (>=), involves_shell, involves_outbound |
| `beacon_patterns` | process_name, remote_ip, connection_count (>=) |
| `behavioral_findings` | finding_type, severity, involves_shell, involves_outbound |
| `hosts` | hostname, platform, health_state, agent_id |
| `network_correlations` | remote_ip, remote_port, process_name (contains) |
| `alerts` | alert_type, severity, actor_key (=), trace_id |

Pivot types: `host`, `process`, `persistence`, `trace`, `entity`

All hunts are append-only records (`threat_hunts`, `threat_hunt_queries`, `threat_hunt_results`). No destructive operations.

Supported domains: 164 total (see `ThreatHuntingService::supportedDomains()`)

---

## Artisan Commands — Detection Engineering

| Command | Description |
|---|---|
| `rule:evidence-inventory` | Inventory 133 rules for replay fixture and evidence debt (E050) |
| `rule:run-fixtures` | Run detection replay fixture batch — tier_1_immediate; advisory-only (E056) |
| `rule:refresh-confidence` | Refresh `confidence_source` labels across all rules — empirical/fixture_tested/manual (E058) |
| `domain:soak-simulate` | Run offline domain soak simulation; promotion_recommended=false always (E057) |
| `stability:freeze-v2` | Stability Evidence Freeze v2 — aggregates E045–E048 evidence; advisory-only |
| `stability:freeze-v3` | Stability Evidence Freeze v3 — consolidates E045–E054 |
| `stability:freeze-v4` | Stability Evidence Freeze v4 — covers E055–E058 delta |
| `soak:plan-review` | Display the real domain soak execution plan + evaluate pre-soak readiness gates (E060) |
| `soak:phase1-run [--warm-up] [--dry-run] [--duration=30]` | Phase 1 pre-soak gate check P1G-01..P1G-08 (E061/E062/E063) |

### soak:phase1-run flags

| Flag | Effect |
|---|---|
| `--warm-up` | Calls `rule:run-fixtures` + `rule:refresh-confidence` before gate check (seeds P1G-04 empirical evidence) |
| `--dry-run` | Gate check only — no persistence to `phase1_soak_runs` |
| `--duration=N` | Planned soak duration (30–60 min) for planning metadata |

---

## Artisan Commands — Operations & Validation

| Command | Description |
|---|---|
| `dlq:replay` | Safe DLQ replay — only sends `NORMALIZER_SAFE_EVENT_TYPES`; never triggers from HTTP |
| `tenant:null-audit` | Read-only audit of tenant_id nulls across append-only tables; exit 0/1 |
| `endpoint:verify-enrollment` | Verify real endpoint enrollment state |
| `tenant:onboard-pilot` | Onboard a pilot tenant with bounded limits |
| `security:validate-secrets` | Validate required secrets; `--record` persists a snapshot |

---

## Trace Investigation

Routes: `/traces`, `/traces/{traceId}`, `/api/traces/*` (6 endpoints)
Controller: `app/Http/Controllers/Trace/TraceInvestigationController.php`
Redactor: `app/Support/TraceRedactor.php`

TraceRedactor rules:
- Deeply redacts fields: `payload`, `evidence`, `raw_event`, `metadata`, `config`, `results`
- Sensitive keys → `[REDACTED]`: password, token, secret, cookie, authorization, bearer, api_key, private_key, session_id
- Emails → `[EMAIL]` inside JSON payload fields
- Preserved at top level: `actor_key`, `ip`, `alert_type`, `trace_id`
- **Presentation-layer only — DB is never mutated**

---

## Detection Rule Governance

Routes: `/detection/*`
Service: `app/Services/RuleRegistryService.php`
DB table: `detection_rules` — mutable governance state (lifecycle tracking)
Registry JSON: `docs/detection/rules/registry.v1.json` — immutable rule definitions

Lifecycle: `draft → shadow → staged_active → deprecated`

Hard gate (enforced in PHP + Python validator):
- endpoint domain → blocked from `staged_active`
- threat-intel domain → blocked from `staged_active`

---

## Behavioral Analytics

Service: `App\Services\BehavioralAnalyticsService`

Finding types: `suspicious_execution_chain`, `beacon_pattern`, `lolbin_usage`, `persistence_correlation`, `rare_parent_child`

Automatically invoked from `EndpointBehavioralService::storeSnapshot()` after entity projection.

Entity graph relationships emitted:
- `suspicious_chain_involves_process`
- `process_reused_destination`
- `persistence_triggered_process`

---

## Internal Security

Service-to-service tokens: `InternalAuthService::signToken(serviceId)` → base64(serviceId|timestamp|HMAC-SHA256), 5-min window
Event envelope signatures: `InternalAuthService::signEvent(event)` → `sha256=HMAC(event_id|event_type|occurred_at|trace_id)`
Middleware: `InternalServiceAuthMiddleware` on `/api/internal/*`
Failure: logged to `security_hardening_events`, never throws, never corrupts pipeline

---

## Append-Only Tables

These tables MUST NOT be updated or deleted by any platform code:

| Table | Type |
|---|---|
| `investigation_events` | State transitions, notes, artifacts |
| `response_plan_approvals` | Approval/rejection history |
| `entity_observations` | Entity observation projections |
| `export_audit_logs` | Every export record |
| `security_hardening_events` | Auth/signature failures, secret warnings |
| `endpoint_agent_heartbeats` | Signed heartbeat log |
| `endpoint_response_command_events` | Command lifecycle audit trail |
| `endpoint_behavioral_findings` | Advisory behavioral findings |
| `threat_hunts` | Hunt session records |
| `threat_hunt_queries` | Structured query params per hunt |
| `threat_hunt_results` | Result snapshots per hunt |

---

## PostgreSQL Gotcha

Cannot reference SELECT aliases in HAVING. Use subquery form:
```sql
SELECT COUNT(*) AS cnt FROM (SELECT col FROM tbl GROUP BY col HAVING COUNT(*) > 1) sub
```
NOT: `->having('cnt', '>', 1)` — fails in PostgreSQL.
