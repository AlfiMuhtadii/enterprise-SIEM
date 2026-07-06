# Platform Positioning

## Official Project Title

**Hybrid Near Real-Time Web Attack Detection Platform using Rule-Based Detection and Multiclass Logistic Regression within an Event-Driven Investigation Architecture**

---

## Positioning Statement

This project demonstrates a hybrid detection and investigation platform that combines:

1. **Rule-based correlation** — deterministic sliding-window rules encoding SOC expert knowledge (12 active rules, identity/cloud/SaaS domain)
2. **Statistical/ML detection** — multiclass logistic regression trained on labeled telemetry for anomaly classification
3. **Event-driven investigation architecture** — replay-safe event sourcing, append-only audit trail, entity-graph pivoting, and structured workflow orchestration

The platform demonstrates how these three components integrate into a cohesive SOC investigation workflow. It is not a full EDR or a production-grade SIEM replacement — see "What This Platform Is NOT" below for the current capability boundary.

---

## What This Platform IS

| Characteristic | Description |
|---|---|
| Detection approach | Hybrid: rule-based correlation + statistical ML baseline |
| Architecture pattern | Event-driven, strangler migration, replay-safe |
| Scope | SOC investigation workflow platform |
| Pipeline | Near real-time (sub-100ms p95 at 77,000 eps) |
| Telemetry sources | Web application HTTP events + Linux endpoint agent |
| Investigation | Entity graph, risk scoring, workflow orchestration |
| Response | Advisory-only recommendation documentation |
| Validation | 6h operational soak, 591 automated tests, resilience scenarios |

## What This Platform Is NOT

| NOT | Reason |
|---|---|
| Full XDR | No kernel telemetry, no EDR enforcement, no autonomous containment |
| Full EDR | No kernel driver, no memory scanning, no process kill capability |
| Autonomous XDR | All response is advisory — analyst is always the decision maker |
| Full enterprise HA deployment | No multi-node HA and no DB-level multi-tenant isolation yet — tracked by ENT-REL-SIMULATED-HA / ENT-TENANCY-NO-DB-ENFORCEMENT |
| Production-grade EDR | Endpoint detection is shadow-only, no active enforcement |
| Hyperscale SIEM | Single-node PostgreSQL, not horizontally sharded |

---

## Consistent Terminology

Use these terms consistently across all documentation, UI, and verbal explanations:

| Correct term | Avoid |
|---|---|
| "hybrid detection platform" | "full XDR", "enterprise XDR" |
| "rule-based correlation" | "AI-powered detection" |
| "ML-assisted baseline" | "autonomous ML detection" |
| "advisory-only recommendations" | "automated response", "containment" |
| "shadow-only endpoint detection" | "endpoint protection", "EDR" |
| "event-driven investigation architecture" | "real-time SIEM", "enterprise SIEM" |
| "near real-time" | "real-time" (avoid implying sub-ms guarantees) |
| "SOC investigation workflow" | "full SOC automation" |
| "current-phase platform" | "fully production-ready system" |

---

## The Hybrid Detection Argument

### Rule-Based Component
- Encodes expert SOC knowledge as sliding-window correlation rules
- Deterministic and explainable: same input → same alert (reproducible)
- 12 staged-active rules covering identity, cloud, and SaaS attack patterns
- Validated through 6h soak: 562M events, zero false-positive explosions

### Statistical ML Component
- Multiclass logistic regression for anomaly/attack class classification
- Trained on labeled telemetry with interpretable feature weights
- Serves as a detection baseline complementary to rule-based correlation
- Advantages: handles unseen patterns, provides probability scores

### Why Hybrid?
- Pure rule-based: brittle to novel attacks, requires domain expert for every rule
- Pure ML: black-box, high false-positive risk, requires labeled training data
- **Hybrid**: rules provide precision; ML provides recall for unseen patterns
- Established precedent: hybrid detection is widely studied (NSM, IDPS literature)

---

## Platform Limitations

These are explicit, known, and documented limitations of the current scope.

### Domain Limitations
1. **Endpoint detection is shadow-only** — endpoint correlation runs but output goes to an isolated topic (`xdr.alerts.shadow.endpoint`) that is never persisted to `security_alerts`. Reason: premature promotion without domain-specific validation soak would risk false-positive floods. Architectural gate is enforced in code.

2. **Threat-intel matching is shadow-only** — IOC correlation rules exist (3 rules) but are also shadow-only. Reason: threat intel freshness and IOC quality are out of the current scope.

3. **DNS/proxy/firewall detection not implemented** — telemetry collection for these domains exists in schema but correlation rules are not in scope.

### Response Limitations
4. **No active response** — all response planning is advisory documentation only. The system generates recommendations but takes zero automated action. There are no `execute_*` database columns by design.

5. **No automated containment** — host isolation, IP blocking, and process termination are not implemented. These require operational-safety gating that is out of the current scope.

### Infrastructure Limitations
6. **No Kubernetes orchestration** — services run as standalone processes. No autoscaling, no pod management. Appropriate at the current deployment scale.

7. **No production HA deployment** — single-instance PostgreSQL, single Redpanda node. Not designed for production redundancy yet (tracked by ENT-REL-SIMULATED-HA). Operational soak validates throughput, not availability.

8. **No multi-tenancy DB enforcement** — single-organization deployment today. RBAC covers analyst/admin/viewer roles but not DB-level tenant isolation (tracked by ENT-TENANCY-NO-DB-ENFORCEMENT).

### Telemetry Limitations
9. **Limited telemetry scope** — web HTTP events and Linux /proc-based endpoint events. No Windows telemetry, no kernel-level syscall tracing, no network packet capture.

10. **Endpoint agent is simulation-capable only** — uses DNS fixture files and /proc polling; does not perform live packet sniffing or kernel hook.

### Detection Limitations
11. **Deterministic recommendation engine** — response recommendations are generated by rule-based logic (risk factor thresholds), not by an LLM or trained recommender model. This is intentional for reproducibility and explainability.

12. **Fixed rule threshold** — correlation rules use fixed sliding-window thresholds (e.g., MFA failures ≥ 5). Adaptive threshold tuning is not implemented.

---

## Technical Contributions

1. **Replay-safe event sourcing for SOC audit integrity** — `xdr_operational_events` with `ON CONFLICT DO NOTHING` ensures forensic-quality audit trail that survives service restarts.

2. **Shadow-only boundary enforcement** — architectural pattern for staging new detection domains without operational risk. Hard gate in code (not process/convention).

3. **Deterministic risk scoring** — entity risk scoring with explainable weighted factors; same data always produces same score. Enables reproducible comparisons.

4. **Integrated investigation workflow** — entity-to-alert-to-investigation-to-response linkage as a unified data model, observable through TraceRedactor-protected export.

5. **Operational resilience validation framework** — 14 validated failure scenarios including broker restart, consumer reconnect, DLQ replay recovery, and signature verification failure.
