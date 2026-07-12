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

## 2026-05-19 — Cross-Domain Correlation + Active Response + Streaming Telemetry

**Cross-Domain Correlation Phase 1** — endpoint↔identity/cloud/SaaS correlation, attack stage timelines, correlation evidence links
- 5 new Go shadow rules → 42 total
- New tables: `cross_domain_correlations`, `attack_stage_timelines`, `correlation_evidence_links` (all append-only)

**Controlled Active Response Phase 2** — dual approval, simulation-first, 11-state machine, blast radius scoring, rollback
- ALLOWED_TYPES: `noop`, `collect_diagnostics`, `refresh_config`, `upload_health_snapshot` + 1 simulation type
- New tables: `response_execution_events`, `response_execution_rollbacks`, `response_execution_simulations` (append-only)

**Real-Time Streaming Endpoint Telemetry Phase 1** — bounded streaming engine, 8 event types, 5 Go shadow rules → 47 total
- New tables: `endpoint_stream_events`, `endpoint_stream_offsets`, `endpoint_stream_checkpoints`, `endpoint_stream_health` (all append-only)

Test counts at milestone: PHP 934, Python 126

---

## 2026-05-19 — Production Ops + Network Analytics + SOC Collaboration + Enterprise Integrations + UEBA

**Production Operations Hardening Phase 1** — retention governance, backup/recovery validation, DLQ replay, queue/stream health, deployment graph
- 10 tables, `php artisan dlq:replay` safe path

**DNS/Proxy/Firewall Analytics Phase 1** — 15 analytics detectors, 9 Go shadow rules → 56 total
- 5 append-only tables, dns-v1/proxy-v1/firewall-v1 normalizers

**SOC Collaboration & Analyst Workflow Phase 1** — escalation routing, SLA tracking, watchlists, shift handoffs, investigation sharing
- 9 tables (7 append-only), `InvestigationFactory`

**Enterprise Integrations Phase 1** — IdP events (Okta/Azure AD), SaaS audit logs (O365/GSuite), ticketing case links, notification dispatch
- 7 tables, 4 Go normalizers, simulated delivery by default, no auto-close, no account actions

**UEBA Phase 1** — baseline analytics, 9 UEBA shadow rules → 65 total, 4 risk weights, robust z-score
- 4 tables (2 mutable + 2 append-only), `UEBABaselineService`

Test counts at milestone: PHP 1234, Python 126

---

## 2026-05-20 — Detection Engineering + Advanced Investigation + SOAR + HA/Reliability + Compliance + Capacity + Release Governance

**Detection Engineering Lifecycle Phase 1** — rule versioning, replay validation, FP/FN analysis, suppression governance (9 tables, `DetectionEngineeringService`)
**Advanced Threat Hunting & Investigation Phase 1** — multi-hop pivot, attack timeline reconstruction, 7 tables, `InvestigationGraphService`
**SOAR Governance & Response Orchestration Phase 1** — simulation-first, approval-gated, no autonomous execution (9 tables, `SoarOrchestrationService`)
**HA / Distributed Reliability Phase 1** — worker heartbeats, SHA-256 idempotency, duplicate detection, storage pressure, degraded mode audit
**Compliance / Governance / Evidence Integrity Phase 1** — evidence hashing, PII audit, tenant isolation, export self-approve blocked
**Performance / Capacity / Cost Governance Phase 1** — linear projection (confidence=0.70, 10× headroom), SAFE_AMPLIFICATION_RATIO=3.0

Test counts at milestone: PHP 1779, Python 186

---

## 2026-05-21–22 — Release Governance + Advanced Detection + Sensor Hardening + Multi-Tenant + Soak/Chaos + Pilot Readiness

**Production Readiness / Release Governance Phase 1** — deterministic manifest hash, go/no-go gates, rollback validation
**Advanced Detection Coverage & Adversarial Validation Phase 1** — +20 shadow rules → 93 total (cred/persist/evasion/lateral/container)
**Sensor Hardening Phase 2** — resource governance, collector health, integrity/gap/sequence/package/offline/upgrade audit (9 all-append tables)
**Multi-Tenant Production Isolation Phase 1** — tenant isolation/replay/graph/export/namespace/evidence governance (9 all-append tables)
**Long-Duration Soak & Chaos Validation Phase 1** — bounded chaos (MAX=600s), drift detection, `SoakChaosValidationService`
**Production Pilot Readiness Phase 1** — bounded EPS/endpoint/duration limits, approval-gated, `PilotReadinessService`

Test counts at milestone: PHP 2162, Python 186

---

## 2026-05-22 — Pilot Execution + Detection Depth + Analyst Optimization + Telemetry Scale + Long-Running Ops

**Real Pilot Execution Phase 1** — `PilotExecutionService` (MIN=5/MAX=20 endpoints), 9 all-append tables
**Detection Depth Expansion Phase 2** — +40 shadow rules → **133 total** (cred/persist/evasion/lateral/cloud/container), `OperationalIntelligenceService`
**Analyst Optimization Phase 1** — `AnalystOptimizationService` (MAX_SUPPRESSION_DAYS=30, MAX_PRIORITY_AMP=2.5, fatigue threshold=10)
**Telemetry Scale Pilot Phase 1** — `TelemetryScalePilotService` (MIN=50/MAX=100 endpoints, MAX_REPLAY_AMP=3.0, 3 scale profiles)
**Long-Running Operational Phase 1** — `LongRunningOperationalService` (7d/14d/30d windows, drift composite scoring, FP evolution)

Test counts at milestone: PHP 2498, Python 186

---

## 2026-05-23 — Endpoint Sensor Advanced Telemetry + Enterprise Deployment + Enterprise Operations + Commercial Readiness + Enterprise Scale HA

**Endpoint Sensor Advanced Telemetry Phase 3** — file hash lineage, module loads, registry timelines, socket lifecycle, anti-evasion, MIN_EVASION_CONFIDENCE_HIGH=0.75
**Enterprise Deployment Hardening Phase 1** — MAX_CANARY=10, drift severity tiers, env validation, upgrade compatibility
**Enterprise Operations Automation Phase 1** — MAX_RECOVERY=1800s, MAX_SIM=600s, MAX_DEP_DEPTH=10, CONTINUITY_PASS=0.80; autonomous=false always
**Commercial Readiness Phase 1** — tenant onboarding, commercial release history, support bundle exports, deployment packaging
**Enterprise Scale HA Phase 1** — cluster topology, failover coordination, MAX_CLUSTER_NODES=50, HA_PASS_THRESHOLD=0.80

Test counts at milestone: PHP 2819, Python 186

---

## 2026-05-24 — Final XDR Certification + RC Stabilization + Code Maturity + Demo Packaging + Doc Freeze

**Final XDR Readiness Certification Phase 1** — acceptance gates, readiness scoring, risk register, SELF_APPROVE_BLOCKED
**Release Candidate Stabilization Phase 1** — RC manifests, feature freeze audit, MAX_PILOT=20, RC_PASS=0.85
**Code-Level XDR Maturity Acceleration Phase 1** — synthetic attack fixtures, detection quality scoring, MAX_FIXTURE=100
**Final Demo / Portfolio / Thesis Packaging Phase 1** — demo scenario runner, readiness snapshots, MAX_SCENARIO=200
**Final Documentation Freeze Phase 1** — README rewrite, 13 new docs, 7 PlantUML diagrams, bootstrap polish; 34-test `DocumentationFreezeTest`

Test counts at milestone: PHP 3077, Python 186

---

## 2026-06-22–25 — Post-RC Enterprise Hardening (ATTR + E036–E041)

**ATTR-001:** MITRE ATT&CK TTP tagging on `security_alerts` — `ttp_tags`, `tactic`, `technique_id`, `technique_name`
**ATTR-002/003:** Alert attribution context + offline GeoIP/ASN lookup
**Demo Lineage Pipeline:** `XDR_CORRELATION_EVENT_LOOP_ENABLED` fix, Go `makeAlert()` lineage, `demo_causal_verify.py` LIVE_CAUSAL_PROOF
**Topic Bootstrap Phase 1:** `xdr_topic_bootstrap.py` creates missing topics via rpk, +3 advisory validator checks
**Consumer Offset Recovery:** alert-writer + incident-builder recover from `offset_out_of_range` with ms-resolution group recreation
**Internal Trust Boundary Hardening:** `XDR_ENFORCE_INTERNAL_AUTH` fail-fast, Pandaproxy loopback-only, checks 15-16 in validator
**Shadow Alert Consumer Phase 1:** `advisory_findings` tables, `shadow_event_loop()`, SOC views, `RBAC advisory.view/review`
**DLQ Review & Replay Workflow Phase 1:** `dlq_records` (mutable) + `dlq_normalization_events` (append-only), `php artisan dlq:replay`
**Internal Auth Coverage Expansion:** extended to alert-writer + incident-builder + correlation-worker; checks 17-19
**Correlation & Alert-Writer Failure DLQ Coverage:** `xdr.correlation_failed` (Go) + `xdr.alert_write_failed` (Python)
**Unified DLQ Review Phase 1:** `replayable` + `error_reason` columns, `isReplayableClass()`
**Shadow Domain Soak Harness (BACKLOG-018):** advisory evidence accumulation for endpoint/network/UEBA, `DomainSoakHarnessService`
**Tenant Isolation Hardening (BACKLOG-019–023):** `TenantBoundaryService`, `TenantContextAuthority`, `XDR_TENANT_STRICT_MODE`
**Pipeline Tenant & Lineage Phase 1:** 11 normalizer helpers + 4 lineage fields, tenant_id in alert/incident write paths
**SCALE-026:** Ingestion hardening validation (6 scenarios, 65 tests)
**DR-027:** Backup/Restore/Recovery runbook + `xdr_recovery_validate.py`
**LIVE-028:** Full live regression & evidence freeze (5 offline stages, 71 tests)
**EASM-030/031:** Passive posture monitoring + posture history & risk trend
**PILOT-034:** Controlled enterprise pilot readiness matrix (`EnterprisePilotReadinessMatrixService`)
**OBS-029:** Runtime observability & SLO readiness (`xdr_observability_slo_validate.py`, 17-panel Grafana dashboard)
**PILOT-LIVE-035:** Final live evidence run
**ENTERPRISE-036:** Production deployment profile (`docker-compose.prod.yml`, 14-check validator)
**ENTERPRISE-037:** Real restore drill (`xdr_restore_drill.py`, dry-run default, PRE-06 target isolation)
**ENTERPRISE-038:** Live soak/load validation (`xdr_live_soak_validate.py`, 6 bounds checks)
**ENTERPRISE-039:** RBAC audit coverage — self-approval guards, `RbacAuditCoverageTest`
**ENTERPRISE-040:** RLS decision record ADR + `xdr_tenant_isolation_posture.py`
**ENTERPRISE-041:** Pilot operator runbook + `xdr_operator_readiness_check.py`

Test counts at milestone: PHP 3618, Python 1156

---

## 2026-06-26–27 — Stability Freeze + Phase 1 Soak Execution (E044–E063)

**ENTERPRISE-044:** Live environment evidence execution and freeze
**ENTERPRISE-045:** Detection domain promotion readiness (`DetectionPromotionReadinessService`)
**ENTERPRISE-046:** Tenant strict mode & null backfill closure (`XDR_TENANT_STRICT_MODE`)
**ENTERPRISE-047:** Shadow-ready promotion decision (`ShadowReadyPromotionDecisionService`, promotion_recommended always false)
**ENTERPRISE-048:** Endpoint shadow domain soak plan (`EndpointSoakPlanService`)
**ENTERPRISE-049:** Stability Evidence Freeze v2 — aggregates E045–E048 evidence
**ENTERPRISE-050–054:** Rule evidence governance, pilot tenant onboarding, real endpoint enrollment, integration reality validation, detection config validation
**ENTERPRISE-055:** Stability Evidence Freeze v3 — consolidates E045–E054
**ENTERPRISE-056:** Detection replay fixtures — 12 tier-1 JSON fixtures in `tests/fixtures/detection/tier1_batch1/`
**ENTERPRISE-057:** Domain soak simulation (`DomainSoakSimulationService`, `domain:soak-simulate`)
**ENTERPRISE-058:** Confidence source refresh (`ConfidenceSourceRefreshService`, `rule:refresh-confidence`)
**ENTERPRISE-059:** Stability Evidence Freeze v4 — covers E055–E058
**ENTERPRISE-060:** Real domain soak execution plan (`RealDomainSoakPlanService`, `soak:plan-review`)
**ENTERPRISE-061:** Phase 1 soak execution framework (`Phase1SoakExecutionService`, `soak:phase1-run`, P1G-01..P1G-08)
**ENTERPRISE-062:** Wired P1G-07/P1G-08 evidence from `reports/xdr_correlation_soak_6h.json`
**ENTERPRISE-063:** Fixed `DetectionReplayFixtureService::persist()` — `updateOrInsert` + `has_validation_evidence=true`; added `--warm-up` flag; `Phase1ConfidenceChainTest` (19 tests)

Infrastructure fixes:
- Pandaproxy URL hardcoded per service in docker-compose (was using `${VAR:-default}` fallback that never fired due to `.env`)
- Redpanda healthcheck: `rpk cluster health` → `curl http://127.0.0.1:9644/v1/brokers`

**Result:** Phase 1 pre-soak Decision: **PASS** (all 8 gates green, 2026-06-27)

Test counts at milestone: PHP ~4274+, Python 186 (endpoint_agent)

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
| 2026-05-19a | 42 | 12 | 30 (+5 cross-domain) |
| 2026-05-19b | 47 | 12 | 35 (+5 streaming) |
| 2026-05-19c | 56 | 12 | 44 (+9 network) |
| 2026-05-20a | 65 | 12 | 53 (+9 UEBA) |
| 2026-05-22a | 73 | 12 | 61 (+8 LLTET) |
| 2026-05-22b | 93 | 12 | 81 (+20 advanced Phase 1) |
| 2026-05-22c | **133** | 12 | 121 (+40 detection depth Phase 2) |

Current: **133 rules** — 12 staged_active, 121 shadow

---

## Test Count Growth Summary

| Date | PHP Tests | Python Tests | Notes |
|---|---|---|---|
| 2026-05-17 | 591 | 22 | Full resilience + hardening |
| 2026-05-17 | 632 | 51 | + Endpoint hardening |
| 2026-05-17 | 659 | 59 | + Response framework |
| 2026-05-18 | 688 | 80 | + Behavioral visibility |
| 2026-05-18 | 720 | 89 | + Behavioral analytics |
| 2026-05-18 | 764 | 95 | + Threat hunting |
| 2026-05-19 | 934 | 126 | + Cross-domain, active response, streaming |
| 2026-05-19 | 1234 | 126 | + Ops hardening, network, SOC collab, integrations, UEBA |
| 2026-05-20 | 1779 | 186 | + Detection eng, ATHI, SOAR, HA, compliance, capacity |
| 2026-05-22 | 2162 | 186 | + Release gov, advanced detection, sensor, multi-tenant, soak/chaos, pilot |
| 2026-05-22 | 2498 | 186 | + Pilot exec, detection depth, analyst opt, telemetry scale, long-running ops |
| 2026-05-23 | 2819 | 186 | + Endpoint sensor v3, enterprise deploy, ops auto, commercial, scale HA |
| 2026-05-24 | 3077 | 186 | + Final XDR cert, RC, code maturity, demo pkg, doc freeze |
| 2026-06-25 | 3618 | 1156 | + ATTR/DLQ/shadow consumer/tenant/EASM/pilot/E036–E041 |
| 2026-06-27 | ~4274+ | 186 (endpoint_agent) | + E044–E063 (~656 test methods) |
| 2026-06-30 | 4538 | 1556 (all suites) | + NOTIFY-TENANCY, alert/agent N+1 bulk batches, TZ-AGENT-STALE (pgsql session UTC) fix, AI-context enrichment, Python service HTTP-session + in-process ClickHouse sync, IOC pre-lowercase + agent-status DRY refactor |
| 2026-07-02 | 4545 | 1556 (all suites) | Target reframed → enterprise demo. + review fixes (IOC-hits idempotency, agent-decrypt 401, response-policy fail-closed), enterprise hardening (RATE-LIMIT-DOS tenant-bucket cap, PERF-GO-HOT-HTTP IOC cache), ENV-CACHE-DRIFT-BATCH config:cache-safe integration adapters/proxies. Go: ingestion-gateway + correlation-worker gained cap/cache tests |
| 2026-07-07 | 4788 | 1669 (all suites) | Platform alignment & audit doc updates. Verified all test suites PASS. Hardened .gitignore and .env.example with missing configurations. |
