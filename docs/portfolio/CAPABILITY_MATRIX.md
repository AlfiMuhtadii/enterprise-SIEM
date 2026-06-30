# Capability Matrix

## Platform: Hybrid Near Real-Time Web Attack Detection Platform

> Academic research prototype demonstrating hybrid detection within an event-driven SOC investigation architecture.
> All capabilities are advisory-only unless stated as staged_active.

---

## SIEM Capabilities

| Capability | Status | Scope |
|---|---|---|
| Alert ingestion and normalization | **Implemented** | Active |
| Incident correlation and grouping | **Implemented** | Active |
| Rule-based detection (identity/cloud/SaaS) | **Implemented** | Staged Active (6h soak PASS) |
| Endpoint behavioral detection | Advisory only | Shadow |
| Network analytics (DNS/proxy/firewall) | Advisory only | Shadow |
| Threat-intel/IOC correlation | Advisory only | Shadow |
| Append-only audit trail | **Implemented** | Active |
| Export and reporting | **Implemented** | Active |

---

## XDR Capabilities

| Capability | Status | Scope |
|---|---|---|
| Endpoint behavioral visibility | Advisory only | Shadow |
| Cross-domain correlation (endpoint↔identity↔cloud) | Advisory only | Shadow |
| Attack stage timeline reconstruction | Advisory only | Shadow |
| Replay-safe event sourcing | **Implemented** | Active |
| Detection rule governance lifecycle | **Implemented** | Active |
| Endpoint agent (process/persistence/network/signed heartbeat) | **Implemented** | Agent |
| Active host isolation/containment | **Not implemented** | Intentional scope boundary |
| Kernel driver telemetry | **Not implemented** | Intentional scope boundary |

---

## SOAR Governance

| Capability | Status | Scope |
|---|---|---|
| Playbook governance and versioning | **Implemented** | Advisory only |
| Simulation-first approval gating | **Implemented** | Advisory only |
| Dual-approval response execution | **Implemented** | Advisory only |
| Blast radius scoring | **Implemented** | Advisory only |
| Rollback support | **Implemented** | Advisory only |
| Autonomous execution | **Not implemented** | Intentional scope boundary |

---

## UEBA Coverage

| Capability | Status | Scope |
|---|---|---|
| Behavioral baseline analytics (z-score) | Advisory only | Shadow |
| Anomaly scoring | Advisory only | Shadow |
| Peer group profiling | Advisory only | Shadow |
| Real-time behavioral enforcement | **Not implemented** | Intentional scope boundary |

---

## Endpoint Telemetry

| Capability | Status | Scope |
|---|---|---|
| Process ancestry collection | **Implemented** | Agent |
| Persistence inventory | **Implemented** | Agent |
| Signed heartbeat telemetry | **Implemented** | Agent |
| Behavioral snapshot streaming | **Implemented** | Agent |
| File hash lineage | **Implemented** | Agent (shadow) |
| Module load tracking | **Implemented** | Agent (shadow) |
| Registry timeline | **Implemented** | Agent (shadow) |
| Anti-evasion indicators | Advisory only | Shadow |
| Live containment/isolation | **Not implemented** | Intentional scope boundary |

---

## Threat Hunting

| Capability | Status | Scope |
|---|---|---|
| Multi-domain retrospective hunting | **Implemented** | Advisory only |
| Allowlisted bounded queries (no arbitrary SQL) | **Implemented** | Active |
| 177 supported hunt domains | **Implemented** | Active |
| Append-only hunt records | **Implemented** | Active |
| Cross-domain pivot graphs | **Implemented** | Advisory only |

---

## Operational Governance

| Capability | Status | Scope |
|---|---|---|
| Retention governance | **Implemented** | Active |
| DLQ replay recovery | **Implemented** | Active |
| Long-duration soak validation (6h PASS) | **Implemented** | Active |
| Chaos simulation (bounded) | **Implemented** | Advisory only |
| HA governance | **Implemented** | Advisory only |
| Commercial readiness governance | **Implemented** | Advisory only |
| Release candidate stabilization | **Implemented** | Advisory only |
| Final XDR certification | **Implemented** | Advisory only |

---

## Detection Rules

| Category | Count | Status |
|---|---|---|
| Staged active (identity/cloud/SaaS) | 12 | Active |
| Shadow (endpoint behavioral) | 32 | Shadow only |
| Shadow (UEBA) | 9 | Shadow only |
| Shadow (network: DNS/proxy/firewall) | 9 | Shadow only |
| Shadow (threat-intel/IOC) | 3 | Shadow only |
| Shadow (advanced detection Phase 1) | 20 | Shadow only |
| Shadow (detection depth Phase 2) | 40 | Shadow only |
| Shadow (low-level endpoint) | 8 | Shadow only |
| **Total** | **133** | — |

---

## Honest Limitations

- No real-time host containment (advisory recommendations only)
- No kernel-level telemetry (user-space agent only)
- Active correlation limited to identity/cloud/SaaS pending domain-specific soak
- No live customer deployment (research prototype)
- No commercial SLA guarantees
