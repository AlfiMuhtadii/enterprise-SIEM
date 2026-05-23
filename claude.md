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

| Change Type              | Validation Command                                |
|--------------------------|---------------------------------------------------|
| Docker / infra           | `docker compose config --quiet`                   |
| Laravel / PHP            | `php artisan test`                                |
| Event contracts          | `python scripts/xdr_contract_validate.py`         |
| Correlation / resilience | `python scripts/xdr_event_flow_resilience_validate.py` |
| Resilience scenarios     | `python scripts/xdr_resilience_validate.py`       |
| Fault injection          | `python scripts/xdr_fault_injection.py`           |
| Secret validation        | `php artisan security:validate-secrets`           |
| Fleet simulation         | `python scripts/xdr_fleet_simulation_validate.py` |
| Full soak                | `run_xdr_correlation_soak_6h.ps1`                 |

### Pass Criteria

```
docker compose config    → exit code 0, no errors
php artisan test         → 2692 passed, zero failures (always prefix with migrate:fresh --force)
python endpoint agent    → 186 tests, 0 failures
rule registry validator  → status=PASS  rules=133  checks=21/21
fleet simulation         → 8/8 passed
contract validate        → all contracts valid
soak validation          → see docs/validation/VALIDATION_BASELINES.md
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
3. Architecture Boundaries ← understand the system
4. Standard Commands ← know how to validate
5. Required Cutover Gates ← know pass criteria

---

# Operational Context

This is an ongoing distributed systems migration project. NOT a greenfield rewrite.

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

**Academic title:** Hybrid Near Real-Time Web Attack Detection Platform using Rule-Based Detection and Multiclass Logistic Regression within an Event-Driven Investigation Architecture.

Distributed AI-assisted XDR-like platform with operational polyglot microservices.

Academic scope is stable and defensible as of 2026-05-18. The platform continues to evolve under controlled architectural boundaries — endpoint behavioral analytics, threat hunting, and orchestration capabilities extend iteratively within the shadow/advisory posture. Focus is on demo stability, thesis defensibility, and documentation quality.

identity/cloud/SaaS Go correlation: staged active (6h soak PASS, 2026-05-14).
Endpoint behavioral analytics, orchestration, and threat hunting: shadow/advisory-only, non-destructive, no active containment, no autonomous response. Cutover not approved.
Threat-intel/IOC correlation, DNS, proxy, firewall: shadow-only, cutover not approved.
Threat hunting: replay-safe retrospective investigation across behavioral data, allowlisted bounded queries, advisory-only hunt records (append-only), no destructive operations. 120 supported domains.

SOC Collaboration & Analyst Workflow: escalation routing, SLA tracking, watchlists, shift handoffs, analyst queue, investigation sharing — all analyst-driven, no autonomous SOC operations.

Enterprise Integrations: inbound IdP events (Okta, Azure AD), SaaS audit logs (Office 365, GSuite), ticketing case links (Jira, ServiceNow), notification dispatch (Slack, PagerDuty) — advisory-only, no account suspension, no autonomous ticket closure, simulated delivery by default.

Academic positioning: `docs/thesis/THESIS_POSITIONING.md`
Defense preparation: `docs/thesis/DEFENSE_PREPARATION.md`
Diagrams: `docs/architecture/diagrams.md`
Module inventory: `docs/architecture/FEATURE_REGISTRY.md`
Implementation history: `docs/architecture/ARCHITECTURE_CHANGELOG.md`

---

# Architecture Boundaries

## Service Responsibilities

| Service | Technology | Primary Role |
|---|---|---|
| Laravel SOC | PHP/Blade | Control plane: RBAC, dashboard, incidents, investigations, response, export, entity graph, risk, hardening, resilience, scenario runner, trace, detection governance, behavioral analytics, threat hunting |
| ingestion-gateway | Go | Signed telemetry ingestion (`POST /v1/ingest`, HMAC-SHA256), rate limiting, backpressure |
| normalizer-worker | Go | `telemetry.raw` → `telemetry.normalized`, endpoint-v1 normalization |
| correlation-worker | Go | identity/cloud/SaaS correlation (active) + endpoint behavioral shadow analytics + cross-domain shadow correlation; advisory-only |
| alert-writer-service | Python/FastAPI | `xdr.alerts` → PostgreSQL `security_alerts` + OpenSearch + `alerts.created` |
| incident-builder-service | Python/FastAPI | `alerts.created` → `security_incidents` + `incidents.updated` |
| ai-rag-service | Python/FastAPI | Analyst assist, Qdrant vector store, heuristic fallback |
| endpoint-agent | Python stdlib | Lightweight behavioral endpoint visibility and orchestration foundation: process ancestry, persistence inventory, process-network correlation, long-lived tracking, behavioral snapshots, signed heartbeat, command lifecycle; posts to ingestion-gateway |

Do NOT remove Laravel from control-plane responsibilities.

## Event Flow

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

## Infrastructure

| Component | Role |
|---|---|
| Redpanda | Kafka-compatible event streaming backbone |
| PostgreSQL | Primary SOC state |
| ClickHouse | Async analytics (not on alert write path) |
| OpenSearch | Alert indexing (graceful DLQ fallback) |
| Qdrant | Vector store for AI/RAG |
| Grafana | Observability dashboards |

Docker profiles:
- Default: postgres, redpanda, clickhouse, opensearch, qdrant, grafana
- `--profile strangler`: Go + Python pipeline services
- `--profile app`: Laravel + queue worker + scheduler

For module routes, RBAC details, and subsystem docs: `docs/architecture/FEATURE_REGISTRY.md`

---

# Detection Rule Registry

Registry: `docs/detection/rules/registry.v1.json` — **133 rules total** (current).

| Category | Count |
|---|---|
| staged_active (identity/cloud/SaaS) | 12 |
| shadow (endpoint behavioral + streaming) | 32 |
| shadow (endpoint low-level telemetry / LLTET Phase 1) | 8 |
| shadow (UEBA behavioral analytics) | 9 |
| shadow (network: DNS/proxy/firewall) | 9 |
| shadow (threat-intel/IOC) | 3 |
| shadow (advanced detection: credential/persist/evasion/lateral/container) | 20 |
| shadow (detection depth: cred/persist/evasion/lateral/cloud/container Phase 2) | 40 |

Hard gate: endpoint, network, and threat-intel rules can **NEVER** be promoted to `staged_active` without a domain-specific 6h soak PASS. `ACTIVE_ALLOWLIST` is intentionally empty.

Validator: `python scripts/xdr_rule_registry_validate.py` (21 checks, exit 0=PASS, 1=FAIL, 2=ERROR)

---

# Security Detector

`SecurityRequestLogger` (global middleware) logs HTTP requests to `security.jsonl`.

Authenticated users on internal SOC/admin paths are excluded from logging to prevent false-positive alerts.

Excluded paths (authenticated users): `soc/*`, `security/alerts*`, `scenario/*`, `admin/*`, `profile/*`, `dashboard`, `threat-hunts/*`

Config: `config/security_detector.php` — `ignored_paths`

---

# Authorization — Gate / RBAC

`AuthServiceProvider::boot()` registers a `Gate::before()` hook that forwards every `soc:<permission>` ability to `Rbac::can()`.

This enables `@can('soc:scenario.run')` Blade directives to work correctly. Without this hook, all `@can` calls for SOC permissions return false.

---

# Event Store

```
xdr_operational_events
```

Requirements: replay-safe, idempotent, deterministic, event-sourced. Uses `ON CONFLICT DO NOTHING`.

---

# Current Active Blocker

**No active blocker.**

6h soak: PASS (2026-05-14). Decision: staged_active for identity/cloud/SaaS.

Resolved failures (archived — do not re-introduce):
- `worker_closed_connection` — fixed: consumer reconnect loop
- `host_aborted_connection` — fixed: HTTP timeout hardening
- `cutover_status_command_failed` — fixed: resilience validator retry handling
- SOC buttons invisible — fixed: `Gate::before()` in `AuthServiceProvider`
- `ANOMALY_BEHAVIOR`/`EXPLOIT_CHAIN_SUSPECTED` false positives — fixed: `SecurityRequestLogger` excludes authenticated SOC paths

---

# Current Operational Rules

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
```

Circuit breaker: 1–2 transient failures → no fallback. 3 consecutive → fallback to legacy.

Current active alert domains: identity, cloud, SaaS
Current shadow/advisory domains:
- **Endpoint behavioral analytics, orchestration, and retrospective threat hunting** — advisory-only, non-destructive, no active containment, no autonomous response
- **Threat-intel/IOC** — shadow-only
- **DNS, proxy, firewall** — shadow-only analytics (15 detectors implemented, advisory-only, no blocking)

**Do NOT expand active cutover beyond identity/cloud/SaaS.**

For full env config and domain status table: `docs/operations/OPERATIONAL_POSTURE.md`

---

# Standard Commands

## Laravel Tests (primary gate — run after every change)

```powershell
php artisan migrate:fresh --force && php artisan test
```

Current: **2692 tests**, all green. Always prefix with `migrate:fresh --force` to avoid intermittent `QueryException` failures from stale schema state. Do NOT run parallel processes against the same PostgreSQL test database.

Rule registry: **133 rules** (12 staged_active, 121 shadow). Run `python scripts/xdr_rule_registry_validate.py`.

## Endpoint Agent Python Tests

```powershell
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
```

Current: **186 tests**, all green.

## Rule Registry Validator

```powershell
python scripts/xdr_rule_registry_validate.py
```

Current: **133 rules**, 21/21 checks PASS.

## Contract Validation

```powershell
python scripts/xdr_contract_validate.py --output reports/xdr_contract_validation.json
```

## Event Resilience Validation

```powershell
python scripts/xdr_event_flow_resilience_validate.py --replays 3 --restart-services 0 --send-malformed 1 --output reports/xdr_event_flow_resilience_validation.json
```

## Resilience Validation Suite

```powershell
python scripts/xdr_resilience_validate.py --output reports/resilience/resilience-validation-report.json
python scripts/xdr_fault_injection.py --output reports/resilience/fault-injection-report.json
php artisan resilience:validate --list
php artisan resilience:validate --scenario=broker_restart
php artisan resilience:validate
```

## Secret Validation

```powershell
php artisan security:validate-secrets
php artisan security:validate-secrets --record
```

## Shadow Prep Validation

```powershell
python scripts/xdr_endpoint_dns_proxy_shadow_prep.py --output reports/xdr_endpoint_dns_proxy_shadow_prep.json
```

## Docker Validation

```powershell
docker compose config --quiet
```

## 6h Soak

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
php artisan xdr:soak-analyze --report=reports/xdr_correlation_soak_6h.json --json
python scripts/xdr_soak_fallback_debug.py --input reports/xdr_correlation_soak_6h.json --output reports/xdr_correlation_soak_fallback_debug.json
```

For full pass criteria and soak gate thresholds: `docs/validation/VALIDATION_BASELINES.md`

---

# Required Cutover Gates

Permanent cutover forbidden until ALL gates PASS:

* fallback_count = 0
* failure_count = 0
* duplicate_rate = 0
* goroutine_growth = 0
* stable memory usage
* p95_latency_ms < 300
* no sustained latency drift
* alert type match >= 0.95
* evidence match >= 0.98
* alert count delta <= 1–2%

If any gate fails → remain shadow OR rollback to legacy. **Never force promotion.**

---

# Architecture Direction Lock

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

* promote endpoint/DNS/proxy/firewall to active before domain-specific 6h soak PASS
* expand Go active scope beyond identity/cloud/SaaS
* remove rollback capability (`XDR_CORRELATION_FALLBACK_TO_LEGACY`)
* remove Laravel as SOC control plane
* bypass validation gates
* claim production hyperscale XDR or full EDR
* ignore replay/idempotency guarantees
* ignore failed validation gates
* manually create SOC alerts/incidents from Scenario Runner (real mode must go through pipeline)
* promote endpoint/threat-intel alert paths to `xdr.alerts` active topic
* add credential collection, packet sniffing, kernel module, process killing, persistence install, or active response to endpoint agent
* add entries to `ACTIVE_ALLOWLIST` in `xdr_rule_registry_validate.py` without domain-specific 6h soak PASS
* mutate DB data inside `TraceRedactor` (redaction is presentation-layer only)
* remove `proc_root` / `proc_net_tcp` test-override kwargs from endpoint agent collectors
* add execution logic to `response_plan_actions` (`action_types` are `recommend_*` only — NO `execute_*`)
* mark response plan as `completed_documented` without analyst explicit action
* delete or update records in append-only tables: `export_audit_logs`, `investigation_events`, `response_plan_approvals`, `security_hardening_events`, `entity_observations`, `endpoint_agent_heartbeats`, `endpoint_response_command_events`, `endpoint_behavioral_findings`, `threat_hunts`, `threat_hunt_queries`, `threat_hunt_results`, `cross_domain_correlations`, `attack_stage_timelines`, `correlation_evidence_links`, `response_execution_events`, `response_execution_rollbacks`, `response_execution_simulations`, `endpoint_stream_events`, `endpoint_stream_offsets`, `endpoint_stream_checkpoints`, `endpoint_stream_health`, `retention_audit_events`, `recovery_validations`, `dlq_replay_events`, `service_health_snapshots`, `queue_lag_metrics`, `stream_pressure_metrics`, `dns_events`, `proxy_events`, `firewall_events`, `network_behavioral_findings`, `investigation_collaborators`, `investigation_watchers`, `escalation_events`, `analyst_handoffs`, `watchlist_events`, `sla_events`, `sla_breaches`, `integration_sync_events`, `external_case_links`, `notification_events`, `notification_deliveries`, `saas_audit_events`, `identity_provider_events`, `baseline_observations`, `baseline_anomaly_scores`, `endpoint_agent_policy_assignments`, `endpoint_agent_enrollment_events`, `endpoint_tamper_events`, `endpoint_spool_snapshots`, `endpoint_script_executions`, `endpoint_privilege_escalations`, `endpoint_container_activities`, `detection_rule_versions`, `detection_replay_results`, `detection_false_positive_reports`, `detection_promotion_requests`, `investigation_graph_nodes`, `investigation_graph_edges`, `investigation_evidence_links`, `investigation_timeline_events`, `soar_playbook_versions`, `soar_execution_results`, `soar_approval_requests`, `soar_execution_audit`, `soar_simulation_results`, `stream_consumer_lag_snapshots`, `duplicate_event_reports`, `storage_pressure_snapshots`, `degraded_mode_events`, `recovery_validation_runs`, `evidence_integrity_runs`, `evidence_integrity_failures`, `audit_export_access_logs`, `tenant_isolation_validation_runs`, `pii_access_audit`, `governance_review_findings`, `telemetry_capacity_snapshots`, `replay_economics_runs`, `query_performance_snapshots`, `storage_capacity_snapshots`, `cardinality_pressure_reports`, `capacity_projection_runs`, `replay_amplification_reports`, `infrastructure_cost_estimates`, `release_manifests`, `deployment_readiness_runs`, `environment_drift_reports`, `rollback_validation_runs`, `release_approval_requests`, `release_audit_events`, `go_nogo_decisions`, `operational_runbook_versions`, `adversarial_validation_runs`, `chained_detection_graphs`, `evasion_resilience_reports`, `attack_chain_timelines`, `detection_confidence_reports`, `tactic_progression_snapshots`, `cross_host_correlation_runs`, `sensor_resource_snapshots`, `collector_health_events`, `telemetry_integrity_runs`, `telemetry_gap_reports`, `package_signature_validations`, `offline_recovery_runs`, `collector_restart_audit`, `telemetry_sequence_validations`, `endpoint_upgrade_validations`, `tenant_isolation_audits`, `tenant_context_propagation_runs`, `tenant_replay_validation_runs`, `tenant_graph_isolation_reports`, `tenant_export_validation_runs`, `tenant_namespace_validation_reports`, `tenant_boundary_violation_reports`, `tenant_replay_lineage`, `tenant_evidence_integrity_reports`, `soak_validation_runs`, `soak_validation_metrics`, `chaos_simulation_runs`, `chaos_failure_events`, `recovery_validation_artifacts`, `operational_drift_reports`, `replay_recovery_runs`, `telemetry_continuity_reports`, `pilot_onboarding_runs`, `pilot_health_validations`, `pilot_success_metrics`, `pilot_rollback_validations`, `telemetry_onboarding_pressure`, `operator_readiness_reviews`, `pilot_audit_events`, `onboarding_approval_requests`, `live_pilot_runs`, `pilot_endpoint_enrollments`, `pilot_health_checkpoints`, `pilot_operational_reviews`, `pilot_drift_reviews`, `pilot_rollback_audit`, `live_telemetry_validations`, `production_observation_checkpoints`, `pilot_execution_audit`, `operational_intelligence_snapshots`, `analyst_investigation_summaries`, `detection_confidence_history`, `false_positive_drift_reports`, `attack_progression_scores`, `replay_confidence_validations`, `suppression_effectiveness_reports`, `analyst_acknowledgment_patterns`, `analyst_workload_snapshots`, `alert_prioritization_scores`, `false_positive_tuning_reports`, `analyst_acknowledgment_audit`, `escalation_quality_reviews`, `alert_recurrence_reports`, `operational_fatigue_indicators`, `shift_handoff_validations`, `telemetry_scale_validation_runs`, `telemetry_scale_metrics`, `replay_scale_recovery_runs`, `analyst_load_stability_reports`, `infrastructure_pressure_runs`, `telemetry_growth_drift_reports`, `queue_recovery_validation_reports`, `scale_pilot_audit`, `operational_validation_windows`, `telemetry_trend_reports`, `analyst_behavior_trends`, `false_positive_evolution_reports`, `operational_drift_history`, `governance_reporting_runs`, `replay_durability_history`, `infrastructure_stability_reports`, `production_governance_audit`, `endpoint_file_hash_lineage`, `endpoint_module_loads`, `endpoint_registry_timelines`, `endpoint_socket_lifecycle`, `endpoint_process_ancestry_validation`, `endpoint_anti_evasion_indicators`, `endpoint_runtime_visibility`, `endpoint_lineage_confidence`, `endpoint_socket_anomalies`, `deployment_package_manifests`, `deployment_integrity_reports`, `rollout_validation_runs`, `deployment_upgrade_history`, `environment_validation_reports`, `deployment_drift_reports`, `deployment_observability_snapshots`, `rollout_checkpoint_history`, `enterprise_deployment_audit`, `operational_recovery_runs`, `service_lifecycle_audit`, `recovery_checkpoint_history`, `failover_validation_runs`, `bounded_automation_reports`, `recovery_dependency_graphs`, `operational_continuity_reports`, `recovery_simulation_runs`, `enterprise_operations_audit`
* push firewall rules, block IPs/domains, or perform DPI inspection — DNS/proxy/firewall are shadow-only advisory analytics
* promote `network` domain rules to `staged_active` without a domain-specific 6h soak PASS
* bypass `InternalServiceAuthMiddleware` on `/api/internal/*` routes
* add execution-type commands to `ALLOWED_TYPES` in endpoint response framework (Phase 1 allows only: `noop`, `collect_diagnostics`, `refresh_config`, `upload_health_snapshot`)

Operational rule: gate fails → remain shadow OR rollback to legacy. **Never force cutover after failed soak.**
