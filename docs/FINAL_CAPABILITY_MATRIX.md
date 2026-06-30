# Final Capability Matrix

**Platform:** Hybrid Near Real-Time Web Attack Detection Platform  
**Date:** 2026-05-24  
**Posture:** All capabilities advisory-only unless explicitly marked active

---

## Capability Tiers

| Tier | Meaning |
|---|---|
| **implemented** | Fully implemented, validated, test-covered |
| **advisory_only** | Implemented but produces advisory output only — no enforcement |
| **shadow** | Implemented in shadow pipeline — not promoted to active |
| **not_implemented** | Intentionally absent from this academic platform |

---

## SIEM Capabilities

| Capability | Tier | Notes |
|---|---|---|
| Security alert ingestion | implemented | Via alert-writer-service → PostgreSQL |
| Alert correlation | implemented | Identity/cloud/SaaS active; endpoint shadow |
| Incident creation | implemented | Via incident-builder-service |
| Alert deduplication | implemented | SHA-256 idempotency fingerprint |
| Alert enrichment | advisory_only | Entity graph projection |
| SLA tracking | implemented | Per-alert SLA events, breach detection |
| OpenSearch indexing | implemented | Graceful DLQ fallback |
| Analyst queue management | implemented | Analyst-driven, no autonomous triage |

## XDR Capabilities

| Capability | Tier | Notes |
|---|---|---|
| Identity/cloud/SaaS correlation | implemented | Staged active — 6h soak PASS |
| Endpoint behavioral analytics | advisory_only | Shadow pipeline — no active promotion |
| Cross-domain correlation | advisory_only | Endpoint↔identity/cloud cross-correlation |
| Attack stage timeline | advisory_only | Append-only timeline reconstruction |
| DNS/Proxy/Firewall analytics | advisory_only | 15 detectors, shadow-only |
| Threat-intel/IOC matching | advisory_only | Shadow-only; no live feed |
| Endpoint response framework | advisory_only | 4 safe commands; analyst-approved only |
| Detection rule governance | implemented | Versioning, replay, suppression, FP/FN |
| Detection quality scoring | implemented | Precision/recall/coverage/ATT&CK mapping |
| Adversarial validation | advisory_only | Replay-safe, no live exploitation |

## SOAR Capabilities

| Capability | Tier | Notes |
|---|---|---|
| Playbook versioning | implemented | Append-only version history |
| Simulation-first execution | implemented | All actions simulated before approval |
| Dual-approval workflow | implemented | Two distinct approvals required |
| Blast-radius scoring | implemented | Advisory score before action |
| Rollback readiness | implemented | Rollback plan required |
| Response plan approval lifecycle | implemented | 8-state machine, analyst-driven |
| Autonomous execution | not_implemented | Intentionally absent |
| `execute_*` action types | not_implemented | Only `recommend_*` actions allowed |

## UEBA Capabilities

| Capability | Tier | Notes |
|---|---|---|
| Entity behavioral baselines | advisory_only | Z-score anomaly scoring per dimension |
| Peer group profiling | advisory_only | Group-based baseline comparison |
| Anomaly scoring | advisory_only | Bounded [0.0, 1.0], deterministic |
| Risk weight aggregation | advisory_only | 4 UEBA risk factors |
| Real-time enforcement | not_implemented | Advisory output only |

## Endpoint Capabilities

| Capability | Tier | Notes |
|---|---|---|
| Endpoint agent enrollment | implemented | Signed heartbeat, health states |
| Process ancestry tracking | advisory_only | Shadow behavioral analytics |
| Persistence inventory | advisory_only | Registry/scheduled task detection |
| Process-network correlation | advisory_only | Behavioral correlation |
| Low-level telemetry (file/registry/socket) | advisory_only | Shadow pipeline, Phase 3 |
| Anti-evasion detection | advisory_only | Shadow pipeline, evasion indicators |
| Live host containment | not_implemented | Intentionally absent |
| Process killing | not_implemented | Intentionally absent |
| Kernel telemetry | not_implemented | Intentionally absent |

## Threat Hunting Capabilities

| Capability | Tier | Notes |
|---|---|---|
| Structured query engine | implemented | 177 domains, allowlisted fields |
| Multi-hop graph pivot | implemented | Up to MAX_GRAPH_DEPTH=5 |
| Retrospective investigation | implemented | Replay-safe, bounded window (30d max) |
| Attack timeline reconstruction | implemented | Append-only investigation timeline |
| Hunt results export | implemented | Advisory-only, append-only records |
| Arbitrary query execution | not_implemented | All queries are allowlisted |

## Operational Governance Capabilities

| Capability | Tier | Notes |
|---|---|---|
| HA validation | implemented | Cluster topology, quorum, failover |
| Capacity governance | implemented | Linear projection, storage pressure |
| Compliance / evidence integrity | implemented | Evidence hashing, PII audit |
| Tenant isolation | implemented | Cross-tenant detection, replay isolation |
| Release governance | implemented | RC manifests, go/no-go gates |
| XDR maturity scoring | implemented | 5-tier (initial → optimizing) |
| Pilot readiness | implemented | Bounded onboarding, approval-gated |
| Commercial readiness | implemented | Tenant onboarding, support bundles |
| Demo packaging | implemented | Replay-safe scenarios, readiness snapshots |

---

## ATT&CK Coverage (Shadow + Active)

| Tactic | Coverage |
|---|---|
| Initial Access | Phishing detection (identity events), credential abuse |
| Credential Access | Shadow: credential dumping indicators, password spray |
| Execution | LOLBin detection, script execution tracking |
| Persistence | Registry/scheduled task/startup indicators |
| Privilege Escalation | Process privilege escalation tracking |
| Defense Evasion | Anti-evasion indicators, evasion resilience |
| Discovery | Process enumeration, network scanning indicators |
| Lateral Movement | Cross-host correlation, process ancestry chaining |
| Collection | Data staging behavioral indicators |
| Command and Control | Beacon pattern detection, DNS tunneling |
| Exfiltration | Data volume anomaly, external transfer indicators |

---

## Platform Maturity Self-Assessment

| Dimension | Score | Tier |
|---|---|---|
| Detection quality | 0.82 | Managed |
| Telemetry coverage | 0.78 | Defined |
| Investigation depth | 0.85 | Managed |
| Response governance | 0.80 | Managed |
| Operational resilience | 0.88 | Managed |
| Compliance posture | 0.83 | Managed |
| **Overall XDR Maturity** | **0.83** | **Managed** |

> Self-assessment based on CodeLevelXdrMaturityService scoring. "Managed" = fourth of five tiers (initial/developing/defined/managed/optimizing).
