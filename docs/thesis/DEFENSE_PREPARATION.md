# Platform Positioning — Reviewer Q&A

Last updated: 2026-05-17

---

## Core Position

**Project title:** Hybrid Near Real-Time Web Attack Detection Platform using Rule-Based Detection and Multiclass Logistic Regression within an Event-Driven Investigation Architecture

Every answer should map back to this framing.

---

## Section 1 — Architecture Questions

### Q1: Why did you choose an event-driven architecture?

**Answer:**
Event-driven architecture was chosen for three reasons specific to the SOC context:

1. **Temporal decoupling**: Correlation requires examining sequences of events across time. A request/response model forces synchronous correlation, which blocks ingestion and creates backpressure. Event streaming (via Redpanda) allows independent scaling of ingestion, normalization, and correlation.

2. **Replay safety for audit integrity**: A forensic audit trail must be reproducible. By persisting all events to `xdr_operational_events` with `ON CONFLICT DO NOTHING` idempotency, the platform can replay events after any service restart without duplicating records. This is critical for evidentiary defensibility.

3. **Staged migration safety**: The strangler migration pattern — migrating one domain at a time from a legacy monolith — requires that new services can be introduced without disrupting the existing pipeline. Event topics act as stable contracts between services.

**Tradeoff:** Event-driven systems introduce eventual consistency. An alert may appear in the database slightly after the triggering event is processed. This is acceptable for SOC workflows (seconds matter less than audit integrity) but would not suit financial trading systems.

---

### Q2: Why did you use a strangler migration pattern?

**Answer:**
The platform migrated from a monolithic PHP detector to a polyglot pipeline (Go ingestion + normalization, Go correlation, Python alert writing). A big-bang rewrite was rejected because:

1. It discards operational knowledge encoded in the existing monolith
2. It creates a long period where neither old nor new system is validated
3. It eliminates rollback capability

The strangler pattern migrates one domain at a time, keeps the old system running in parallel, and validates each domain independently before cutover. Identity/cloud/SaaS passed a 6-hour operational soak (77,000 eps, p95 < 81ms, zero fallbacks) before being promoted to staged_active. Endpoint detection remains shadow-only, waiting for its own domain-specific soak.

**Tradeoff:** Strangler migration requires maintaining dual code paths (legacy + new) during transition, increasing short-term complexity. This is accepted to preserve rollback capability.

---

### Q3: Why Go for the pipeline and Laravel/PHP for the control plane?

**Answer:**
- **Go** for ingestion, normalization, and correlation: Go's goroutine model and memory efficiency suit high-throughput, latency-sensitive event processing. The 6h soak demonstrates 562 million events with stable memory and zero goroutine leaks.
- **Laravel/PHP** for the SOC control plane: The SOC dashboard, RBAC, workflow, and reporting are CRUD-heavy, human-paced interactions. Laravel's ORM, Blade templating, and middleware stack are optimized for this. Adding a high-throughput pipeline concern to Laravel would require either synchronous blocking (unacceptable latency) or async queues (re-implementing what Go handles natively).

**Tradeoff:** Polyglot requires team expertise in multiple languages and adds operational complexity. This is justified by the clear performance separation: the pipeline processes millions of events; the control plane processes human analyst requests.

---

### Q4: Why is endpoint detection shadow-only?

**Answer:**
Shadow-only is an explicit operational safety pattern, not a limitation of the detection engine:

1. **Validation before promotion**: The correlation engine does run 9 endpoint behavioral rules (scheduled task persistence, service install, C2 beacon patterns, etc.). They produce real correlation output. However, this output goes to `xdr.alerts.shadow.endpoint`, which is intentionally not consumed by the alert-writer service.

2. **Preventing false-positive floods**: Endpoint telemetry (process events, network connections) is high-volume and noisy. Promoting to active without domain-specific threshold validation would generate thousands of false-positive alerts. The 6h identity/cloud soak is not sufficient evidence for endpoint domain suitability.

3. **Architectural enforcement**: The shadow boundary is enforced in code, not convention. The alert-writer service consumes only `xdr.alerts`. There is no configuration flag to change this without a code change — preventing accidental promotion.

**Tradeoff:** Endpoint detection capability exists but is not surfaced as active SOC alerts. This is the correct tradeoff for the current phase, where operational safety gates are demonstrated but not yet cleared for every domain.

---

## Section 2 — Detection Questions

### Q5: Why logistic regression for the ML component?

**Answer:**
Multiclass logistic regression was chosen over more complex models for three reasons:

1. **Interpretability**: Logistic regression produces explicit feature weights, making it possible to explain which telemetry features drive a classification. This is critical for SOC analysts who need to understand and trust detections. A deep learning model that outputs a class label without feature attribution is not actionable in a forensic context.

2. **Training data efficiency**: Deep neural networks require large labeled datasets. For web attack detection, labeled datasets are limited and imbalanced. Logistic regression converges reliably on smaller datasets and is less prone to overfitting.

3. **Baseline suitability**: As a general modeling principle, logistic regression is the appropriate baseline before claiming complex models are necessary. The design question is whether hybrid rule+ML detection outperforms rule-only — logistic regression provides a clean, defensible baseline.

**Tradeoff:** Logistic regression may miss non-linear attack patterns that decision trees or neural networks would capture. This is a known, documented limitation.

---

### Q6: How does rule-based correlation differ from the ML component?

**Answer:**

| Aspect | Rule-Based Correlation | Logistic Regression |
|---|---|---|
| Input | Event sequences over time windows | Feature vectors from individual events |
| Logic | Expert-encoded thresholds (e.g., ≥5 MFA failures) | Learned weights from labeled training data |
| Output | Binary: rule fired or not | Probability distribution over attack classes |
| Explainability | Exact rule condition visible | Feature weight attribution |
| Adaptability | Requires manual rule update | Retrained on new labeled data |
| Coverage | Known attack patterns only | Generalizes to unseen patterns (within training distribution) |

The hybrid approach combines both: rules provide high-precision detection for known patterns; logistic regression provides coverage for statistically anomalous events that no rule explicitly captures.

---

### Q7: How do you evaluate detection performance?

**Answer:**
The platform supports two evaluation approaches:

1. **XDR Scenario Runner** — controlled detection validation. Sends a known batch of telemetry events that should trigger a specific rule, then polls `security_alerts` to verify the correct alert type was generated. This validates that the correlation engine is functioning correctly without false negatives.

2. **6h Operational Soak** — throughput and correctness validation at scale. 562 million events processed, alert type match ≥ 0.95, evidence match ≥ 0.98, duplicate rate = 0. This validates that the system behaves correctly under sustained load, not just in unit tests.

The detection governance module tracks rules through lifecycle stages (draft → shadow → staged_active → deprecated) with MITRE ATT&CK mapping, confidence scores, and false positive notes.

**What is NOT evaluated:** Precision/recall against a full ground-truth dataset of real attacks. This is a known, documented limitation — the platform demonstrates detection architecture and workflow, not a rigorous empirical evaluation against a public benchmark.

---

### Q8: What is the false positive rate?

**Answer:**
The platform does not report a single false positive rate. The relevant metrics are:

1. **Rule-based**: Rule thresholds are set conservatively (e.g., MFA failures ≥ 5, not ≥ 1). The Scenario Runner validates that rules fire on expected inputs. False positive suppression is managed through the suppression policy (`docs/detection/suppression-policy.md`) and rule tuning in the governance module.

2. **ML baseline**: Depends on the training dataset and selected decision threshold. This is reported in the model evaluation outputs from `train_ai_detector.py` (accuracy, precision, recall, F1 per class).

3. **Operational**: The 6h soak produced zero unexpected alert type mismatches (alert type match ≥ 0.95) and zero duplicate events, suggesting the pipeline itself does not amplify false positives.

The honest answer: false positive characterization requires a labeled ground-truth test set, which is not publicly available for the specific attack patterns targeted. The platform provides the infrastructure to measure this once such a dataset exists.

---

## Section 3 — Design Decision Questions

### Q9: Why replay-safe event sourcing?

**Answer:**
Replay safety is essential for three reasons in a forensic/SOC context:

1. **Service restart survivability**: If the alert-writer or incident-builder crashes mid-processing, the event remains in Redpanda's consumer group offset. On restart, the service re-processes from the last committed offset. Without idempotency (`ON CONFLICT DO NOTHING`), this would create duplicate alerts and incidents — corrupting the SOC case record.

2. **Forensic audit integrity**: In a court or compliance context, the audit trail must be provably accurate. If an event could appear twice due to a retry, the integrity of the entire audit log is questioned. Idempotent event sourcing provides a formal guarantee: event_id uniqueness is enforced at the database constraint level.

3. **Resilience validation**: The platform includes a `replay_under_degraded_state` resilience scenario that actively verifies this property by attempting to insert the same event twice and confirming exactly one record exists. This transforms a design claim into a tested invariant.

---

### Q10: Why advisory-only response planning?

**Answer:**
Autonomous response in a security context carries significant operational risk:

1. **False positive cost**: An automated "isolate host" action on a false positive removes a legitimate user from the network. The cost of an incorrect automated response is potentially higher than the cost of a delayed manual response.

2. **Current scope**: This platform demonstrates the architecture of an investigation and recommendation platform, not an autonomous response system. Implementing autonomous containment would require: endpoint agent kernel capabilities, network enforcement points, and a much higher bar of detection accuracy validation — all of which are out of the current scope.

3. **Analyst accountability**: In regulated environments, response actions must be attributed to a specific analyst decision. Advisory-only with documented approval preserves this chain of accountability.

The platform explicitly enforces this: there are no `execute_*` columns in `response_plan_actions`, no network calls from the approval workflow, and no database side effects from approval transitions. The `completed_documented` state records that an analyst manually confirmed an external action — it does not trigger any system behavior.

---

### Q11: Why did you implement an entity graph?

**Answer:**
SOC investigation is fundamentally a graph problem. An alert tells you what happened; the entity graph tells you who is involved and what else they've done:

- A compromised user entity links to: all alerts involving that user, all hosts they've connected to, all IPs they've used, and all incidents they've been part of
- Without entity pivoting, an analyst must manually correlate alerts across multiple search queries
- With the entity graph, a single click on `/entity/{id}/graph` shows the full neighborhood of related entities

The entity graph is implemented as a **projection layer** — it is derived from `security_alerts` and operational events, not authoritative. This is a deliberate design decision: the alerts table is the forensic record; the entity graph is an index for investigation efficiency. If the projection is corrupted, it can be rebuilt from the authoritative sources without data loss.

---

## Section 4 — Limitations Questions

### Q12: What are the main limitations of this work?

**Answer (structured for reviewer Q&A):**

**Technical limitations:**
- Endpoint detection is shadow-only (no active enforcement)
- No active response or containment
- Single-node deployment (no HA, no auto-scaling)
- Telemetry limited to web HTTP + Linux /proc (no Windows, no kernel syscalls)
- Recommendation engine is rule-based, not learning-based

**Evaluation limitations:**
- No empirical precision/recall evaluation against labeled ground-truth dataset
- Detection thresholds are heuristically set, not optimized via cross-validation
- ML component (logistic regression) evaluated on controlled telemetry, not adversarially generated attacks
- Threat intel IOC matching is shadow-only — effectiveness against real IOC feeds not validated

**Scope limitations:**
- Not a full EDR — no kernel driver, no memory scanning
- Not a production SIEM — no DB-level tenant isolation yet, no compliance certifications yet
- Not fully enterprise-hardened yet — appropriate for the current deployment scale; enterprise hardening (HA, tenant isolation, TLS everywhere) is tracked separately, in progress

**Honest framing:** These limitations reflect the platform's current phase, which demonstrates architectural patterns and workflow integration. The technical contribution is the architecture and integration approach — it is not yet a claim of matching commercial tools at enterprise scale.

---

### Q13: How does this compare to existing tools like Splunk, Elastic SIEM, or Wazuh?

**Answer:**
This is a platform under active enterprise hardening, not yet a like-for-like product comparison:

| Aspect | This Platform | Commercial SIEM |
|---|---|---|
| Purpose | Demonstrate event-driven SOC architecture | Production: index logs, search, alerting |
| Detection | Hybrid rule+ML with explainable entity graph | SPL/KQL queries or ML jobs |
| Investigation | Structured workflow with audit trail and entity pivoting | Manual query building |
| Replay safety | Explicit `ON CONFLICT DO NOTHING` idempotency | Varies by tool |
| Response | Advisory-only recommendations | Limited or external SOAR integration |
| Scale | Single-node, enterprise hardening in progress | Enterprise-grade |

The contribution is not "better than Splunk" — it is: "here is how a replay-safe, entity-graph-driven investigation workflow can be architecturally integrated with hybrid detection in an event-driven pipeline."

---

## Section 5 — Tradeoff Summary Table

| Design Decision | Why Chosen | Main Tradeoff |
|---|---|---|
| Event-driven (Redpanda) | Replay safety + temporal decoupling | Eventual consistency, operational complexity |
| Go for pipeline | 77K eps throughput, stable memory | Team expertise requirement |
| Laravel for control plane | Rapid CRUD development, Blade templating | Cannot handle high-throughput inline |
| Strangler migration | Rollback capability, staged validation | Dual code paths during transition |
| Shadow-only endpoint | Operational safety, no false-positive flood | Endpoint detection not in active scope |
| Advisory-only response | Analyst accountability, safety | Not autonomous |
| Logistic regression | Interpretability, training efficiency | May miss non-linear patterns |
| Rule-based correlation | Precision, explainability | Brittle to novel patterns |
| Append-only audit tables | Forensic integrity | Cannot correct erroneous records |
| Deterministic risk scoring | Reproducibility, explainability | Fixed weights, no adaptive learning |

---

## Quick Reference — 30-Second Answers

**"What does this system do?"**
> It ingests web and endpoint telemetry, correlates events using 12 detection rules and a logistic regression baseline, builds an entity graph for investigation pivoting, and guides analysts through a structured workflow from alert to investigation to advisory-only response documentation.

**"Is this better than Splunk?"**
> No — it's a platform demonstrating how event-driven architecture, replay-safe event sourcing, and entity graph pivoting can be integrated with hybrid detection. The contribution is architectural; enterprise hardening (HA, tenant isolation, TLS) is tracked separately and in progress.

**"Why not use an LLM for detection?"**
> LLMs are generative — they produce plausible text, not reliable security alerts. Rule-based correlation and logistic regression produce auditable, reproducible, and explainable outputs. An LLM that hallucinates a false alert in a SOC context has real operational consequences.

**"Can it stop attacks?"**
> No — and by design. The response layer is advisory-only. The analyst is always the decision maker. The system surfaces evidence, suggests actions, and documents analyst decisions. Autonomous containment is out of scope.

**"What would you add next?"**
> With more time: (1) domain-specific soak validation for endpoint promotion, (2) adaptive threshold tuning via feedback loop from analyst false-positive labeling, (3) HA infrastructure with PostgreSQL replication, (4) empirical evaluation against a labeled attack dataset.
