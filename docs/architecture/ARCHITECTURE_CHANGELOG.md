# Architecture Changelog

Chronological implementation history.
For current operational state see `docs/operations/OPERATIONAL_POSTURE.md`.
For current test baselines see `docs/validation/VALIDATION_BASELINES.md`.

---

## 2026-05-12 — Initial Commit

- Laravel SOC monolith: RBAC, dashboard, incident workflow, SOC rules, alerts, knowledge base, AI/RAG
- Docker Compose infra: PostgreSQL, Redpanda, ClickHouse, OpenSearch, Qdrant, Grafana
- Go services scaffolded: ingestion-gateway, normalizer-worker, correlation-worker (all legacy mode)
- Python services scaffolded: alert-writer-service, incident-builder-service, ai-rag-service
- AI analyst tables: `ai_analyst_suggestions`, `ai_execution_history`, `ai_prompt_templates`

---

## 2026-05-14 — 6h Soak PASS

identity/cloud/SaaS correlation promoted to `staged_active`.

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

Resolved failures from soak stabilization:
- `worker_closed_connection` — fixed: consumer reconnect loop
- `host_aborted_connection` — fixed: HTTP timeout hardening
- `cutover_status_command_failed` — fixed: resilience validator retry handling

See: `docs/validation/xdr_6h_soak_pass.md`

---

## 2026-05-15 — Detection + Tracing Layer

**XDR Scenario Runner** — detection validation, stub/real mode, `ExecuteScenarioRunJob`
**Trace Investigation** — `/traces` UI, `TraceRedactor`, 6 API endpoints
**Detection Rule Governance** — full rule lifecycle, MITRE coverage, promotion gates
**Endpoint telemetry collection** — 3 new Go shadow rules, Laravel Endpoint UI, Grafana

Schema additions:
- `security_alerts` + `trace_id`
- `security_incidents` + `trace_id`
- `scenario_runs`, `scenario_evidence`
- `detection_rules`

Go shadow rules added (3): `c2_beacon_pattern`, `new_service_persistence`, `scheduled_task_persistence`

Fixes:
- SOC nav buttons invisible — fixed: `Gate::before()` in `AuthServiceProvider`
- `ANOMALY_BEHAVIOR`/`EXPLOIT_CHAIN_SUSPECTED` false positives — fixed: `SecurityRequestLogger` excludes authenticated SOC paths

Test counts at milestone:
- PHP: 591 tests, 2178 assertions
- Registry: 12 rules

See: `docs/operations/reconnect_resilience_fix.md`

---

## 2026-05-16 — Entity Graph + Endpoint Agent MVP

**Entity Graph + Investigation Pivoting** — entity registry (users/hosts/IPs/domains/processes/hashes), relationships, adjacency graph
**Linux Endpoint Agent MVP** — `/proc`-based collectors, DNS fixture, file watcher; stdlib-only

Schema additions:
- `entities`, `entity_relationships`, `entity_observations`, `entity_risk_snapshots`

Test counts at milestone:
- PHP: ~319 entity graph tests green

---

## 2026-05-17 — Full Investigation + Security Hardening

**Entity Risk Scoring Engine** — deterministic weighted scoring, 10 factors, advisory shadow indicators
**Investigation Workflow Orchestration** — 8-state machine, assignment, notes, artifacts, audit trail
**Response Planning & Recommendation Layer** — deterministic, advisory-only, no execution, 6-state machine
**Export Center** — JSON/Markdown/HTML, TraceRedactor enforced, append-only audit log
**Security Hardening Dashboard** — secret validation, internal auth status
**Operational Resilience Validation** — 14 failure scenarios, fault injection, recovery metrics
**Endpoint Agent Hardening Phase 1** — enrollment tokens, signed heartbeat, health states, config policy
**Endpoint Response Approval Framework Phase 1** — safe-command allowlist (4 types), 8-state lifecycle, agent API

Schema additions:
- `investigations`, `investigation_events`, `investigation_assignments`, `investigation_notes`, `investigation_artifacts`
- `response_plans`, `response_plan_actions`, `response_plan_approvals`, `response_plan_notes`
- `export_audit_logs` (append-only)
- `security_hardening_events` (append-only)
- `resilience_runs`, `resilience_metrics`
- `endpoint_agents` + enrollment columns (additive)
- `endpoint_agent_configs`, `endpoint_agent_heartbeats` (append-only), `endpoint_agent_metrics`
- `endpoint_response_commands`, `endpoint_response_command_events` (append-only)

Test counts at milestone:
- PHP: 659 tests, 2315 assertions
- Python: 59 tests
- Registry: 12 staged_active + 12 shadow = 24 total

---

## 2026-05-18 — Behavioral + Threat Hunting

**Endpoint Behavioral Visibility Phase 1** — process ancestry, long-lived tracking, persistence inventory, process-network correlation
- 4 new shadow rules: `suspicious_parent_child_chain`, `suspicious_shell_chain`, `suspicious_long_lived_process`, `suspicious_persistence_entry`
- Registry: 28 total at this sub-milestone

**Behavioral Detection Analytics Phase 1** — execution chains, beacon patterns, LOLBin analytics, rare parent-child
- 5 new shadow rules: `suspicious_execution_chain`, `suspicious_beacon_pattern`, `suspicious_lolbin_usage`, `suspicious_persistence_correlation`, `rare_parent_child_process`
- Registry: 33 total at this sub-milestone

**Endpoint Shadow Correlation Validation** — 4 new Go shadow rules
- `repeated_behavioral_chain`, `multi_host_beacon_pattern`, `repeated_lolbin_sequence`, `persistence_reactivation_pattern`
- Registry: 37 total (current)

**Threat Hunting & Investigation Query Engine Phase 1** — 8 query domains, pivot explorer, history replay; advisory-only, append-only

Schema additions:
- `endpoint_process_snapshots`, `endpoint_process_entries` (append-only), `endpoint_persistence_items`, `endpoint_network_correlations` (append-only)
- `endpoint_behavioral_findings` (append-only), `endpoint_execution_chains`, `endpoint_beacon_patterns`
- `threat_hunts` (append-only), `threat_hunt_queries` (append-only), `threat_hunt_results` (append-only)

Test counts at milestone (current):
- PHP: 764 tests
- Python: 95 tests
- Registry: 37 rules, 21/21 validator checks PASS

---

## Rule Count Growth Summary

| Date | Total | staged_active | shadow |
|---|---|---|---|
| 2026-05-12 | — | — | — |
| 2026-05-15 | 12 | 12 | 0 (initial active only) |
| 2026-05-15 | 12+3 shadow | 12 | 3 endpoint |
| 2026-05-17 | 24 | 12 | 12 (9 endpoint + 3 threat-intel) |
| 2026-05-18a | 28 | 12 | 16 (13 endpoint + 3 threat-intel) |
| 2026-05-18b | 33 | 12 | 21 (18 endpoint + 3 threat-intel) |
| 2026-05-18c | 37 | 12 | 25 (22 endpoint + 3 threat-intel) |

---

## Test Count Growth Summary

| Date | PHP Tests | Python Tests | Notes |
|---|---|---|---|
| 2026-05-17 | 591 | 22 | Full resilience + hardening |
| 2026-05-17 | 632 | 51 | + Endpoint hardening |
| 2026-05-17 | 659 | 59 | + Response framework |
| 2026-05-18 | 688 | 80 | + Behavioral visibility |
| 2026-05-18 | 720 | 89 | + Behavioral analytics |
| 2026-05-18 | 764 | 95 | + Threat hunting (current) |
