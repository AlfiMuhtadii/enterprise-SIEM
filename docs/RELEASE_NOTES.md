# Release Notes — XDR Platform

**Release:** Release Candidate 1 (RC1)  
**Date:** 2026-05-24  
**Status:** Feature-complete, presentation-ready  
**Validation:** 4788 PHP / 1669 Python tests green; all validators PASS

---

## Summary

This release represents the final feature-complete state of the Hybrid Near Real-Time Web Attack Detection Platform. All core subsystems are implemented, validated, and documented. The platform is thesis-ready, evaluator-ready, and portfolio-ready.

---

## What's New in RC1

### Final Packaging (2026-05-24)

- **Final Demo / Portfolio / Thesis Packaging Phase 1** — demo scenario runner, readiness snapshots, platform showcase exports, evaluator walkthrough console, one-command bootstrap
- **Code-Level XDR Maturity Acceleration Phase 1** — synthetic attack fixtures, detection quality scoring, FP/FN analysis, telemetry quality scoring, analyst triage simulation, performance hotspot analysis, XDR maturity scorecard (5-tier)
- **Release Candidate Stabilization Phase 1** — RC manifests, feature freeze audit, deployment artifact integrity, reproducibility scoring (RC_PASS threshold = 0.85)

### Enterprise Governance (2026-05-23)

- **Final XDR Readiness Certification Phase 1** — acceptance gates, readiness scoring, risk register, go-live validation (SELF_APPROVE_BLOCKED)
- **Enterprise Scale HA Phase 1** — cluster topology governance, failover coordination, HA validation (HA_PASS_THRESHOLD = 0.80, MAX_CLUSTER_NODES = 50)
- **Commercial Readiness Phase 1** — tenant onboarding, commercial release lifecycle, support bundle exports, deployment packaging
- **Enterprise Operations Automation Phase 1** — recovery governance, service lifecycle audit, failover validation, continuity reporting (MAX_RECOVERY = 1800s, all bounded, no autonomous actions)
- **Enterprise Deployment Hardening Phase 1** — deployment integrity, canary rollout validation (MAX_CANARY = 10), upgrade compatibility, drift detection

### Detection & Analytics (2026-05-22)

- **Detection Depth Expansion Phase 2** — +40 shadow rules → 133 total; credential, persistence, evasion, lateral movement, container detection
- **Endpoint Sensor Advanced Telemetry Phase 3** — file hash lineage, module load tracking, registry timelines, socket lifecycle, anti-evasion indicators
- **Advanced Detection Coverage & Adversarial Validation Phase 1** — +20 shadow rules → 93 total; chained detection graphs, adversarial replay validation, evasion resilience
- **Analyst Optimization Phase 1** — workload snapshots, priority amplification (MAX_PRIORITY_AMP = 2.5), fatigue detection
- **Long-Running Operational Validation Phase 1** — 7d/14d/30d operational windows, drift scoring, FP evolution tracking
- **Telemetry Scale Pilot Phase 1** — scale validation (50–100 endpoints), replay amplification bounds (MAX_REPLAY_AMP = 3.0)

### Production & Pilot Governance (2026-05-22)

- **Real Pilot Execution Phase 1** — bounded pilot runs (MIN=5/MAX=20 endpoints), live telemetry validation, production observation checkpoints
- **Production Pilot Readiness Phase 1** — approval-gated onboarding, bounded EPS, operator readiness reviews
- **Long-Duration Soak & Chaos Validation Phase 1** — bounded chaos (MAX=600s), drift detection, replay continuity validation
- **Multi-Tenant Production Isolation Phase 1** — tenant isolation audit, replay isolation, graph isolation, boundary violation detection

### Platform Hardening (2026-05-20 to 2026-05-21)

- **Compliance / Governance / Evidence Integrity Phase 1** — evidence hashing, PII audit, export self-approve blocked, stale access review
- **HA / Distributed Reliability Phase 1** — worker heartbeats, SHA-256 idempotency fingerprint, duplicate detection, degraded mode audit
- **Performance / Capacity / Cost Governance Phase 1** — linear capacity projection (10× headroom, confidence=0.70), replay economics, storage pressure
- **Production Readiness / Release Governance Phase 1** — deterministic manifest hash, self-approve blocked, rollback validation
- **Sensor Hardening Phase 2** — collector health, telemetry gap detection, package signature validation, offline recovery
- **Advanced Detection Coverage Phase 1** — chained detection, adversarial validation, evasion resilience

### Core Detection & Investigation (2026-05-19 to 2026-05-20)

- **SOC Collaboration & Analyst Workflow** — escalation routing, SLA tracking, watchlists, shift handoffs, investigation sharing
- **Enterprise Integrations** — Okta/Azure AD IdP events, Office 365/GSuite SaaS audit, Jira/ServiceNow case links, Slack/PagerDuty notifications (advisory-only, simulated delivery)
- **UEBA Phase 1** — entity behavioral baselines, z-score anomaly scoring, peer group profiling (9 shadow rules)
- **DNS/Proxy/Firewall Analytics Phase 1** — 15 network analytics detectors, 9 shadow rules; shadow-only, no blocking
- **SOAR Governance & Response Orchestration** — simulation-first, dual-approval, blast-radius scoring, 5 safe actions only
- **Advanced Threat Hunting & Investigation** — multi-hop graph pivot, attack timeline reconstruction, 5 hunt domains

### Foundation (2026-05-14 to 2026-05-19)

- **Identity/Cloud/SaaS correlation** → staged_active after 6h soak PASS (2026-05-14)
- **Endpoint behavioral analytics** → shadow/advisory-only, non-destructive
- **Real-Time Streaming Endpoint Telemetry** — bounded streaming engine, 8 event types
- **Production Operations Hardening** — retention governance, DLQ replay, queue/stream health
- **Cross-Domain Correlation** — endpoint↔identity/cloud/SaaS correlation, attack stage timelines
- **Endpoint Response Framework** — safe-command allowlist (4 types), 8-state approval lifecycle, no execute-type commands
- **Endpoint Behavioral Visibility** — process ancestry, long-lived tracking, persistence inventory
- **Threat Hunting Engine** — 181 query domains, advisory-only, append-only results

---

## Validation Baselines

| Validator | Result | Date |
|---|---|---|
| `php artisan test` | **4788 passed, 0 failures** | 2026-07-07 |
| Python suites (all) | **1669 passed** (endpoint_agent 191, alert_writer 49, incident_builder 36, ai_rag 1, scripts 5, demo_causal_verify 7, demo_feed 15, xdr_topic_bootstrap 1365) | 2026-07-07 |
| Go services (`go test`) | **PASS** (ingestion-gateway + correlation-worker, incl. RATE-LIMIT-DOS cap + IOC-cache tests) | 2026-07-02 |
| `php artisan test` (rc3) | **4538 passed, 0 failures** | 2026-06-30 |
| `php artisan test` (rc2) | **3043 passed, 0 failures** | 2026-05-24 |
| Python endpoint agent | **186 passed** | 2026-05-24 |
| Rule registry | **PASS — 133 rules, 21/21 checks** | 2026-05-24 |
| Contract validation | **PASS** | 2026-05-24 |
| Resilience validation | **PASS — 8/8** | 2026-05-24 |
| Fleet simulation | **PASS — 8/8** | 2026-05-24 |
| 6h correlation soak | **PASS** | 2026-05-14 |

---

## Known Limitations

See `docs/KNOWN_LIMITATIONS.md` for full details.

**Summary:**
- Endpoint/DNS/proxy/firewall rules remain shadow-only (no domain-specific 6h soak conducted)
- No kernel EDR, no live host containment, no autonomous remediation
- Single-node infrastructure (no production Kubernetes)
- All integrations (Okta, Office 365, Jira, Slack) use simulated delivery by default
- AI/RAG: heuristic fallback when Qdrant unavailable

---

## Security Posture

- All detections are advisory-only
- All response plans are simulation-first and analyst-approved
- `ACTIVE_ALLOWLIST` is intentionally empty for all shadow domains
- Rollback capability preserved: `XDR_CORRELATION_FALLBACK_TO_LEGACY=true`
- Circuit breaker: 3 consecutive failures → automatic fallback to legacy
