# Interview and Viva Explanation

Last updated: 2026-05-17

---

## Official Title

**Hybrid Near Real-Time Web Attack Detection Platform using Rule-Based Detection and Multiclass Logistic Regression within an Event-Driven Investigation Architecture**

---

## One-Sentence Summary

> This platform ingests web and endpoint telemetry, detects suspicious activity using rule-based correlation and a logistic regression baseline, builds an entity graph for investigation pivoting, and guides analysts through a structured workflow from alert to investigation to advisory-only response documentation.

---

## 90-Second Explanation

> Modern attacks generate scattered telemetry across web servers, identity systems, and endpoints. This platform centralizes that telemetry, correlates events using 12 detection rules and a statistical baseline, and organizes findings into an investigation workflow.
>
> The architecture is event-driven: telemetry flows through a Go ingestion gateway, a normalizer, and a correlation worker, then into PostgreSQL via a Python alert writer. The Laravel control plane provides the analyst interface — investigation workflow, entity graph pivoting, risk scoring, and advisory-only response planning.
>
> Three things distinguish this from a simple log aggregator: first, replay-safe event sourcing — every event is idempotent in the audit store, so service restarts cannot corrupt the forensic record. Second, entity graph pivoting — analysts can navigate from an alert to every related entity without constructing manual queries. Third, structured workflow — investigation states, assignments, and approvals are enforced by a state machine with an append-only audit trail.
>
> Endpoint detection runs as shadow-only — the correlation engine evaluates endpoint rules, but the output is isolated to a separate topic, not persisted as active alerts. This is an explicit operational safety boundary, not a limitation of the detection capability.
>
> The platform is a research prototype. It is not a full EDR, not an autonomous response system, and not a commercial SIEM replacement.

---

## Architecture Answer (technical)

Five layers:

1. **Ingestion layer (Go)**: HMAC-signed telemetry collection via `POST /v1/ingest`, rate limiting, backpressure admission control, publishes to `telemetry.raw` on Redpanda.

2. **Processing layer (Go)**: Normalizer translates heterogeneous telemetry to canonical XDR schema. Correlation worker applies sliding-window detection rules against `telemetry.normalized`.

3. **Persistence layer (Python)**: Alert writer persists alerts to PostgreSQL with fingerprint deduplication and ON CONFLICT idempotency. Incident builder groups alerts into incidents deterministically.

4. **Intelligence layer (Python)**: AI/RAG service provides analyst-assist with heuristic and ML-based investigation suggestions. Qdrant vector store for semantic retrieval.

5. **Control plane (Laravel/PHP)**: SOC dashboard, RBAC, investigation workflow, entity graph, risk scoring, response planning, export center, security hardening, resilience validation.

---

## Detection Answer (hybrid)

Detection combines two approaches:

**Rule-based correlation** — 12 staged-active rules encode expert SOC knowledge as sliding-window thresholds. Example: `IDENTITY_MFA_FAILURE_BURST` fires when a single actor accumulates ≥5 MFA failure events within a configured time window. Rules are deterministic, explainable, and validated through a 6-hour operational soak.

**Logistic regression baseline** — multiclass classification trained on labeled telemetry feature vectors. Provides detection coverage for statistically anomalous events that no specific rule captures. Interpretable via feature weight attribution.

**Hybrid advantage**: Rules provide high precision for known patterns; logistic regression provides recall for anomalous patterns. Neither alone is sufficient — rules miss novel attacks; ML alone has high false positive risk.

---

## Investigation Answer

The entity graph transforms alert data into an investigatable structure:

- Every alert names entities (users, hosts, IPs, domains)
- The graph projects these entities and their relationships
- Analysts pivot: alert → entity → all related alerts → all related incidents
- Timeline view shows chronological observations across services
- Risk scoring provides prioritization (0–10 deterministic score)

Investigation workflow is a formal state machine (8 states) with:
- Enforce valid transitions at service layer
- Append-only audit trail (every transition, note, assignment preserved)
- Assignment history (deactivated, not deleted — full provenance)

---

## Response Safety Answer

Response planning is advisory-only by architectural constraint:

1. No `execute_*` database columns exist
2. No network calls from the approval workflow
3. No process management from any state transition
4. `completed_documented` = analyst confirmed an external manual action — zero system execution

This is intentional. In a regulated SOC context, response actions must be attributed to a specific analyst decision, not automated. The platform provides the documentation framework; the analyst makes the decision.

Every response plan view displays the disclaimer: *"Recommendations are advisory-only and were not automatically executed by the platform."*

---

## Resilience and Operational Safety Answer

Replay-safe event sourcing is the foundational operational guarantee:

- `xdr_operational_events` enforces `ON CONFLICT (event_id) DO NOTHING` at database level
- Any service restart replays from Redpanda consumer offset — no duplicate records
- 14 validated resilience scenarios confirm recovery behavior
- Active scenarios verify: endpoint shadow isolation, signature failure non-destructiveness, auth token rejection with no replay corruption

The 6-hour operational soak (2026-05-14) validated: 562M events, p95 < 81ms, zero fallbacks, stable memory growth.

---

## Limitation Honesty Answer

> I want to be transparent about the scope. This is a research prototype. Endpoint detection is shadow-only — the engine runs but output is isolated, not in the active alert path. Response is advisory — the system recommends but does not act. There is no HA infrastructure, no production multi-tenancy, and no empirical precision/recall evaluation against a public benchmark dataset. The thesis contribution is the architectural integration and workflow design, not a claim of outperforming commercial tools.

---

## Closing Answer

> The platform demonstrates that event-driven architecture, replay-safe event sourcing, and entity-graph pivoting can be integrated with hybrid rule-based and statistical ML detection in a cohesive SOC investigation workflow. The research prototype achieves near real-time throughput (77,000 eps at p95 < 81ms), a formally auditable investigation trail, and a structured response documentation layer — all within a polyglot microservices architecture designed for operational safety.
