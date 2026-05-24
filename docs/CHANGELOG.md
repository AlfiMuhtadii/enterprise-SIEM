# Changelog

All notable changes to this project are documented here. This project follows a staged migration discipline — changes are validated before each phase closes.

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
