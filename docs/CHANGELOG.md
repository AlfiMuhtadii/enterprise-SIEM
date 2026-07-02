# Changelog

All notable changes to this project are documented here. This project follows a staged migration discipline — changes are validated before each phase closes.

---

## [1.0.0-rc4] — 2026-07-02

**Positioning:** demo target reframed from *thesis demo* → **enterprise demo**. Deferred
hot-path/architecture items are now tracked as **enterprise roadmap** (staged, in-scope),
not "out of scope for academic posture". The hard safety boundaries (no active
containment / autonomous response, shadow-rule soak gates) remain in force regardless of
target.

### Fixed (review findings)
- **IOC-HITS-IDEMPOTENCY**: `ioc_hits` had no unique key, so re-running IOC enrichment appended duplicate rows. Added a dedup-first migration + unique `(ioc_id, alert_id)` index → `insertOrIgnore` now de-dups (idempotent enrichment).
- **AGENT-SECRET-DECRYPT-500**: `AgentIngestionController::verifiedAgent()` now guards `Crypt::decryptString()` → graceful 401 (`secret_decrypt_failed`) instead of an unhandled 500 on a corrupt secret / APP_KEY rotation.
- **RESP-POLICY-FAIL-OPEN**: `SecurityResponsePolicy::recordIsActive()` now fails **closed** on an unparseable `expires_at` (was active-forever); null/empty still = permanent.

### Added / Changed (enterprise hardening)
- **RATE-LIMIT-DOS** (ingestion-gateway): bounded distinct per-tenant rate-limiter buckets (`XDR_INGEST_MAX_TENANT_BUCKETS`, default 10000). Beyond the cap, new tenant IDs share a lazily-created overflow bucket (still rate-limited) so an authenticated client can't exhaust memory by flooding distinct tenant_ids. `/metrics` exposes `tenant_bucket_count`/`tenant_bucket_max`.
- **PERF-GO-HOT-HTTP** (correlation-worker): bounded, TTL, thread-safe `iocCache` in front of `lookupIOC` — repeated indicators in a batch no longer re-issue a synchronous HTTP GET. Only definitive HTTP-200 outcomes cached (transient errors retried). `XDR_IOC_CACHE_TTL_SECONDS`=60, `XDR_IOC_CACHE_MAX`=10000; `/metrics` exposes `ioc_cache_hits`.
- **ENV-CACHE-DRIFT-BATCH** (runtime slice): integration adapters (Jira/PagerDuty/ServiceNow/Slack) → new `config/integrations.php`; `TrustProxies` + `force_https` → `config/xdr.php` — config-cache-safe (env() no longer null under `php artisan config:cache`).

### Deferred (enterprise roadmap, staged)
- Hot-path Go (`PERF-GO-LIMITER`, `PERF-GO-OVERCONCURRENT`), core-pipeline rearchitecture (`PERF-REST-POLL`, `PERF-REST-REBALANCE`, `ARCH-KAFKA-NATIVE`, `ARCH-DB-SPLIT`), infra (`ARCH-MTLS-SEC`, `ARCH-DISCOVERY`), AI live-model (`AI-KB-SEMANTIC`, `AI-KB-FEED-INGEST`) — each needs a dedicated, validated effort (Go hot-path items require a live-pipeline verifier).

### Validation
- `php artisan test` → **4544 passed, 0 failures**. Python suites: **1556** (endpoint_agent 186, alert_writer 13, incident_builder 10, scripts 5, xdr_topic_bootstrap 1342). Go services: `go test` green (ingestion-gateway + correlation-worker gained cap/cache tests). Rule registry **133 rules**, threat-hunting **177 domains**.

---

## [1.0.0-rc3] — 2026-06-30

### Fixed
- **TZ-AGENT-STALE**: PostgreSQL server session ran at +07 while `app.timezone=UTC`, so naive query-builder timestamps read back ~7h off in PHP. This silently broke every `now()->diffInSeconds($row->last_seen_at)` comparison — agent online/offline `computed_status` across the SOC dashboard, `SocAgentController`, `SocApiController`, `EndpointController`, `OpsHealthController` telemetry-lag, and `soc:agent-health-check` (flagged all agents stale + spurious `AGENT_STALE_OR_STOPPED` alerts). Pinned the pgsql connection `timezone` to UTC (`config/database.php`) + in-DB staleness comparison. Locked by `TimezoneRoundTripTest`.

### Added
- **NOTIFY-TENANCY-GAP**: per-tenant SOC notification routing — `tenant_notification_settings` (mutable, isolated) + `tenant_id` on `notification_delivery_logs`; `TenantNotificationResolver`; `soc:sla-escalate` + `soc:notify-critical` route per incident tenant.
- **AI-CONTEXT-EMPTY**: `AiAnalystManager::compactContext()` now includes bounded alert details + retrieved knowledge text (not just counts) so LLM answers are grounded.

### Changed (performance / refactor)
- **PERF-IOC-LOOP / PERF-ALERT-TUNE**: alert-write-path N+1 loops batched (chunked inserts + one UPDATE per entity in a transaction); fixed latent multi-match evidence loss.
- **PERF-AGENT-UPDATE / PERF-AGENT-HEALTH-N1**: agent-management N+1 → bulk `whereIn` updates + eager-loaded keyed maps.
- **PERF-TRANSACTION-GAP**: SLA escalation state change made atomic (`DB::transaction`).
- **PERF-PYTHON-HTTP**: alert-writer + incident-builder reuse a module-level `requests.Session()` (keep-alive pooling).
- **PERF-SUBPROCESS-POLL**: ClickHouse sync daemon runs in-process instead of spawning an interpreter subprocess each cycle.
- **PERF-IOC-STR-LOWER**: IOC values pre-lowercased once before the matching loop.
- **REFINE-AGENT-STATUS**: duplicated online/offline staleness expression extracted to `EndpointAgentService::computeStatus()`.
- **GIT-RM-PYC**: untracked compiled `*.pyc` / Go `*.exe` build artifacts.

### Rejected / Deferred
- **STATE-REDIS-05** rejected (correlation worker uses no Redis). **PERF-DB-CONN-LEAK** deferred (psycopg3 `with conn:` already closes — no leak; safe pooling needs `psycopg_pool`, not installed). **EDR-EXEC-02 / AI-CONF-BANDS** rejected (forbidden automated containment); **TENANT-ENFORCE-RLS** deferred.

### Validation
- `php artisan test` → **4538 passed, 0 failures**. Python suites: **1556** across endpoint_agent (186), alert_writer (13), incident_builder (10), scripts (5), xdr_topic_bootstrap (1342). Rule registry **133 rules**, threat-hunting **177 domains**.

---

## [1.0.0-rc2] — 2026-06-27

### Fixed
- Redpanda healthcheck: changed from `rpk cluster health` (times out during consumer reconnect storms) to `curl -fsS http://127.0.0.1:9644/v1/brokers` (admin HTTP API, unaffected by Pandaproxy state)
- Pandaproxy URL bug in docker-compose: all strangler services now hardcode `http://redpanda:8082` instead of substituting from `.env` (which held the host loopback `http://127.0.0.1:8082`)
- E063: `DetectionReplayFixtureService::persist()` used `->where('rule_id')->update()` — silent no-op on empty table after `migrate:fresh`; changed to `updateOrInsert()` + `has_validation_evidence=true` so fixture runs populate `rule_fixture_backlogs` on first run
- E062: P1G-07/P1G-08 evidence sources wired from `reports/xdr_correlation_soak_6h.json`

### Added
- E063: `--warm-up` flag on `soak:phase1-run` — auto-seeds fixture confidence evidence before gate check
- E063: `Phase1ConfidenceChainTest` — 19 tests covering full E2E fixture → confidence → P1G-04 chain
- Phase 1 pre-soak Decision: **PASS** (all 8 gates green, 2026-06-27)

---

## [1.0.0-rc1-enterprise] — 2026-06-25

### Added
- ENTERPRISE-039: RBAC audit coverage — self-approval guards on `EndpointResponseCommandService` + `SocResponseController`; `RbacAuditCoverageTest` (26 tests); 3618 PHP green
- ENTERPRISE-040: RLS decision record ADR + `xdr_tenant_isolation_posture.py` (14 checks + 2 advisory, offline); 61 Python tests
- ENTERPRISE-041: `PILOT_OPERATOR_RUNBOOK.md` (17 procedures, 24-command index) + `xdr_operator_readiness_check.py` (16 checks + 2 advisory); 43 Python tests
- ENTERPRISE-044: Live environment evidence execution and freeze document

---

## [1.0.0-rc1-e044-e063] — 2026-06-22 to 2026-06-27

### Added
- ATTR-001: MITRE ATT&CK TTP tagging on `security_alerts` (`ttp_tags`, `tactic`, `technique_id`, `technique_name`)
- ATTR-002/003: Alert attribution context + offline GeoIP/ASN lookup (`source_country`, `source_asn`, `source_org`)
- ENTERPRISE-045: Detection domain promotion readiness framework (`DetectionPromotionReadinessService`)
- ENTERPRISE-046: Tenant strict mode + null backfill closure (`XDR_TENANT_STRICT_MODE`, `TenantContextMissingException`)
- ENTERPRISE-047: `ShadowReadyPromotionDecisionService` (advisory only, promotion_recommended always false)
- ENTERPRISE-048: Endpoint shadow domain soak plan (`EndpointSoakPlanService`)
- ENTERPRISE-049: Stability Evidence Freeze v2 — aggregates E045–E048 evidence
- ENTERPRISE-050–054: Rule evidence governance, pilot tenant onboarding, real endpoint enrollment, integration reality validation, detection config validation
- ENTERPRISE-055: Stability Evidence Freeze v3 — consolidates E045–E054
- ENTERPRISE-056: Detection replay fixtures — 12 tier-1 JSON fixtures (`tests/fixtures/detection/tier1_batch1/`), `DetectionReplayFixtureService`, `rule:run-fixtures`
- ENTERPRISE-057: Domain soak simulation (`DomainSoakSimulationService`, `domain:soak-simulate`)
- ENTERPRISE-058: Confidence source refresh (`ConfidenceSourceRefreshService`, `rule:refresh-confidence`)
- ENTERPRISE-059: Stability Evidence Freeze v4 — covers E055–E058 delta
- ENTERPRISE-060: Real domain soak execution plan (`RealDomainSoakPlanService`, `soak:plan-review`)
- ENTERPRISE-061: Phase 1 soak execution framework (`Phase1SoakExecutionService`, `soak:phase1-run`, P1G-01..P1G-08)

---

## [1.0.0-rc1] — 2026-05-24

### Added
- Final Demo / Portfolio / Thesis Packaging Phase 1 — demo scenario runner, readiness snapshots, showcase exports, evaluator walkthrough console
- Code-Level XDR Maturity Acceleration Phase 1 — synthetic attack fixtures, detection quality scoring, XDR maturity scorecard (5-tier)
- Final Documentation Freeze Phase 1 — README polish, release notes, evaluator guides, thesis artifacts, architecture diagrams

### Changed
- README.md rewritten: evaluator-friendly, recruiter-friendly, technically accurate
- Bootstrap scripts polished with teardown/reset flow
- PlantUML diagrams expanded (7 diagrams total)

---

## [0.42.0] — 2026-05-24

### Added
- Release Candidate Stabilization Phase 1 — RC manifests, feature freeze audit, deployment artifact integrity, reproducibility scoring

---

## [0.41.0] — 2026-05-24

### Added
- Final XDR Readiness Certification Phase 1 — acceptance gates, readiness scoring, risk register, go-live validation (SELF_APPROVE_BLOCKED)

---

## [0.40.0] — 2026-05-23

### Added
- Enterprise Scale HA Phase 1 — cluster topology, failover coordination, HA validation (MAX_CLUSTER_NODES=50, HA_PASS_THRESHOLD=0.80)
- Commercial Readiness Phase 1 — tenant onboarding, commercial release history, support bundle exports, deployment packaging

---

## [0.38.0] — 2026-05-23

### Added
- Enterprise Operations Automation Phase 1 — recovery governance, service lifecycle audit, continuity reporting (MAX_RECOVERY=1800s)
- Enterprise Deployment Hardening Phase 1 — deployment integrity, canary validation (MAX_CANARY=10), upgrade compatibility

---

## [0.36.0] — 2026-05-23

### Added
- Endpoint Sensor Advanced Telemetry Phase 3 — file hash lineage, module loads, registry timelines, socket lifecycle, anti-evasion indicators
- Detection Depth Expansion Phase 2 — +40 shadow rules → 133 total; cred/persist/evasion/lateral/container detection

---

## [0.34.0] — 2026-05-22

### Added
- Long-Running Operational Validation Phase 1 — 7d/14d/30d windows, drift scoring, FP evolution
- Telemetry Scale Pilot Phase 1 — scale validation (50–100 endpoints), MAX_REPLAY_AMP=3.0
- Analyst Optimization Phase 1 — workload snapshots, priority amplification (MAX_PRIORITY_AMP=2.5), fatigue detection

---

## [0.31.0] — 2026-05-22

### Added
- Real Pilot Execution Phase 1 — live telemetry validation, production observation checkpoints (MIN=5/MAX=20 endpoints)
- Production Pilot Readiness Phase 1 — bounded EPS, operator readiness reviews, approval-gated onboarding
- Long-Duration Soak & Chaos Validation Phase 1 — bounded chaos (MAX=600s), drift detection
- Multi-Tenant Production Isolation Phase 1 — tenant isolation audit, replay isolation, boundary violation detection

---

## [0.27.0] — 2026-05-22

### Added
- Advanced Detection Coverage & Adversarial Validation Phase 1 — +20 shadow rules, chained detection, adversarial replay validation
- Sensor Hardening Phase 2 — collector health, package signature validation, offline recovery

---

## [0.25.0] — 2026-05-21

### Added
- Production Readiness / Release Governance Phase 1 — deterministic manifests, go/no-go gates, rollback validation
- Performance / Capacity / Cost Governance Phase 1 — linear projection, replay economics, storage pressure

---

## [0.23.0] — 2026-05-20

### Added
- Compliance / Governance / Evidence Integrity Phase 1 — evidence hashing, PII audit, export governance
- HA / Distributed Reliability Phase 1 — worker heartbeats, SHA-256 idempotency, duplicate detection, degraded mode audit
- Detection Engineering Lifecycle Phase 1 — rule versioning, replay validation, FP/FN analysis, suppression governance
- Advanced Threat Hunting & Investigation Phase 1 — multi-hop graph pivot, attack timeline reconstruction
- SOAR Governance & Response Orchestration Phase 1 — simulation-first, dual-approval, blast-radius scoring
- Low-Level Endpoint Telemetry Phase 1 — process executions, network connections, script execution, privilege escalation
- Endpoint Fleet Simulation Phase 2 — 8-scenario Python validator (8/8 PASS)
- Endpoint Fleet Hardening Phase 1 — tamper detection, spool stats, advisory risk scoring

---

## [0.18.0] — 2026-05-19

### Added
- UEBA Phase 1 — entity behavioral baselines, z-score anomaly scoring, peer group profiling (9 shadow rules)
- Enterprise Integrations Phase 1 — IdP events (Okta/Azure AD), SaaS audit (Office 365/GSuite), ticketing (Jira/ServiceNow), notifications (Slack/PagerDuty)
- SOC Collaboration & Analyst Workflow Phase 1 — escalation routing, SLA tracking, watchlists, shift handoffs
- DNS/Proxy/Firewall Analytics Phase 1 — 15 detectors, 9 shadow rules, shadow-only
- Production Operations Hardening Phase 1 — retention governance, DLQ replay, queue/stream health

---

## [0.12.0] — 2026-05-19

### Added
- Real-Time Streaming Endpoint Telemetry Phase 1 — bounded streaming engine, 8 event types
- Controlled Active Response Phase 2 — dual approval, simulation-first, blast radius scoring, rollback
- Cross-Domain Correlation Phase 1 — endpoint↔identity/cloud/SaaS correlation, attack stage timelines

---

## [0.8.0] — 2026-05-18

### Added
- Behavioral Detection Analytics Phase 1 — execution chains, beacon patterns, LOLBin analytics, persistence correlation
- Endpoint Behavioral Visibility Phase 1 — process ancestry, long-lived tracking, persistence inventory
- Threat Hunting & Investigation Query Engine Phase 1 — initial 8 query domains, advisory-only
- CLAUDE.md refactored to operational truth + 4 supporting docs

---

## [0.5.0] — 2026-05-17

### Added
- Endpoint Response Framework Phase 1 — safe-command allowlist (4 types), 8-state approval lifecycle
- Endpoint Agent Hardening Phase 1 — signed heartbeat, health states, config policy
- Security Hardening Module — secret validation, audit hardening, event integrity
- Resilience Validation Module — 14 failure scenarios, fault injection scripts

---

## [0.3.0] — 2026-05-16

### Added
- Entity Graph Module — projection layer, entity/relationship/observation tables
- Export Center Module — exportable reports (JSON/MD/HTML), TraceRedactor

---

## [0.1.0] — 2026-05-14

### Added
- Identity/Cloud/SaaS correlation active — 6h soak PASS
- Laravel SOC control-plane
- Go ingestion-gateway, normalizer-worker, correlation-worker
- Python alert-writer-service, incident-builder-service, ai-rag-service
- Python endpoint-agent (stdlib)
- Redpanda event backbone, PostgreSQL, ClickHouse, OpenSearch, Qdrant, Grafana
