# Subsystem Explainability Reference

For each subsystem: purpose, why it exists, operational role, design tradeoff, limitations, and future work boundary.

Last updated: 2026-05-17

---

## 1. ingestion-gateway (Go)

**Purpose:** Authenticated, rate-limited entry point for all telemetry.

**Why it exists:** The SOC platform must accept telemetry from multiple sources (web application, endpoint agent) without exposing the streaming backbone directly. Centralizing admission control here prevents malformed events, replay attacks, and burst floods from reaching the correlation engine.

**Operational role:**
- Validates HMAC-SHA256 signature on every ingest batch (`X-XDR-Signature: sha256=...`)
- Enforces per-second rate limit (token bucket)
- Checks normalizer queue depth before accepting (admission control)
- Injects `trace_id` if not present in payload
- Publishes to `telemetry.raw` on Redpanda

**Design tradeoff:** Synchronous admission check against normalizer metrics adds ~1–3ms latency per batch. This is acceptable: the alternative (no backpressure) allows queue depth to grow unbounded, eventually causing OOM in the normalizer.

**Limitations:** Single-instance; no clustering. HMAC secret is a single shared secret (not per-source client certificates). Does not parse event content — malformed but correctly-signed events pass through to normalizer.

**Future work boundary:** Certificate-based per-source authentication; multi-instance load balancing; content-level schema validation at ingestion.

---

## 2. normalizer-worker (Go)

**Purpose:** Translate heterogeneous telemetry formats into a canonical XDR schema.

**Why it exists:** Event sources emit different field names for the same concepts (`src_ip`, `client_ip`, `source_ip`). Downstream correlation rules must reference stable field names. Normalization centralizes this translation so correlation rules do not contain per-source field mapping logic.

**Operational role:**
- Consumes `telemetry.raw`
- Maps vendor-specific fields to canonical schema (`telemetry_type`, `event_type`, `user`, `source_ip`, `action`, etc.)
- Handles endpoint-v1 format separately (process/network/DNS/file/auth sub-objects)
- Routes malformed events to DLQ topic (`telemetry.normalized.dlq`)
- Publishes to `telemetry.normalized`
- Exposes `/metrics` for admission control feedback to ingestion-gateway

**Design tradeoff:** Normalization by field presence (tries multiple field name candidates via `first()`) is permissive — it accepts events that are partially malformed. This reduces false DLQ rates at the cost of potentially passing events with missing fields to correlation.

**Limitations:** Does not perform semantic validation (e.g., verifying source_ip is a valid IP address). No schema registry integration — field mappings are hardcoded. Does not support encrypted payloads.

**Future work boundary:** Schema registry (Confluent Schema Registry or Redpanda Schema Registry) for contract-first validation; semantic field validation; per-source normalization plugins.

---

## 3. correlation-worker (Go)

**Purpose:** Apply detection rules to normalized event streams and generate alerts.

**Why it exists:** This is the core detection engine. It processes normalized events, maintains sliding-window state per actor key, and fires rules when thresholds are met. Separating correlation from normalization allows each to scale independently and evolve independently.

**Operational role:**
- Consumes `telemetry.normalized`
- Maintains per-actor event window state
- Evaluates 12 staged-active rules (identity/cloud/SaaS): IDENTITY_MFA_FAILURE_BURST, CLOUD_SUSPICIOUS_OBJECT_ACCESS, etc.
- Evaluates 9 shadow endpoint behavioral rules + 3 threat-intel IOC rules
- Active rules → `xdr.alerts` (consumed by alert-writer)
- Shadow rules → `xdr.alerts.shadow.endpoint` (NOT consumed, observation only)
- Preserves `trace_id` in all alert outputs
- Circuit breaker: falls back to legacy on 3 consecutive failures

**Design tradeoff:** Sliding window state is in-memory. A worker restart loses in-progress window state — events that were accumulating toward a threshold reset. This means an attack spread across a restart may be missed. The tradeoff is simplicity over full persistence; a persistent window store (Redis) would solve this.

**Limitations:** Fixed window sizes and thresholds. No adaptive learning. No cross-actor correlation (patterns across multiple users simultaneously are not detected). Memory-bound state — large actor key spaces increase memory pressure.

**Future work boundary:** Persistent window state (Redis/RocksDB); dynamic threshold tuning; cross-entity correlation rules; probabilistic rule composition.

---

## 4. alert-writer-service (Python/FastAPI)

**Purpose:** Persist alerts from the event stream to durable storage with idempotency guarantees.

**Why it exists:** Redpanda guarantees at-least-once delivery. Without idempotent writes, a service restart could produce duplicate alerts in PostgreSQL. This service is the authoritative writer for `security_alerts`, applying fingerprint deduplication and ON CONFLICT logic.

**Operational role:**
- Consumes `xdr.alerts`
- Fingerprint deduplication: `sha256(alert_type|severity|actor_key|evidence_ids)` — in-process SEEN set prevents duplicate DB writes in a single session
- Writes to PostgreSQL `security_alerts` with `ON CONFLICT (alert_id) DO UPDATE`
- Indexes to OpenSearch (DLQ on failure — PostgreSQL write is primary, OpenSearch is secondary)
- Preserves `trace_id` through `COALESCE(excluded.trace_id, security_alerts.trace_id)`
- Publishes `alerts.created` envelope to Redpanda
- Stores `xdr_operational_events` entry for each alert (replay-safe)

**Design tradeoff:** In-process SEEN set is not persisted across restarts. A restart after a crash mid-batch may re-process some alerts. The `ON CONFLICT DO UPDATE` at DB level handles this — no duplicate rows. The SEEN set is a performance optimization (avoids DB round-trip for known duplicates), not the primary idempotency mechanism.

**Limitations:** SEEN set is bounded by process memory — very high alert volumes could exhaust it. OpenSearch indexing failure is tolerated (DLQ), meaning search results may lag PostgreSQL by the DLQ retry window.

**Future work boundary:** Persistent SEEN set (Redis sorted set with TTL); OpenSearch index synchronization via change data capture (CDC); Kafka Streams stateful deduplication.

---

## 5. incident-builder-service (Python/FastAPI)

**Purpose:** Group related alerts into incidents and maintain a unified incident view.

**Why it exists:** SOC analysts cannot triage individual alerts at scale. Incident grouping reduces analyst cognitive load by linking related alerts (same actor, same attack family) into a single case. This is analogous to Splunk's notable events or Elastic's case management.

**Operational role:**
- Consumes `alerts.created`
- Groups by: `{alert_type.split('_')[0]}|{primary_entity}` — deterministic group key
- `incident_id = sha256(group_key)[:24]` — deterministic, stable across restarts
- `INSERT ... ON CONFLICT (incident_id) DO UPDATE` — upsert with timeline/severity/entity accumulation
- Preserves `trace_id` from most recent grouped alert
- Publishes `incidents.updated` to Redpanda

**Design tradeoff:** Simple string-based group key may over-cluster (alerts with same actor family treated as one incident even if temporally distant) or under-cluster (alerts from different actor representations for the same attack not linked). A proper incident correlation algorithm is a research problem in itself.

**Limitations:** Group key does not consider temporal proximity — alerts from the same actor 30 days apart may merge into the same incident. No automatic incident expiry. MITRE mapping is from alert evidence, not computed independently.

**Future work boundary:** Time-windowed incident grouping; confidence-based similarity clustering; multi-actor incident correlation; automated incident expiry policies.

---

## 6. Entity Graph (Laravel module)

**Purpose:** Investigation pivoting — from an alert to all related entities and their history.

**Why it exists:** Alerts name actors and IPs, but analysts need to see the full picture: what else has this user done? What hosts has this IP connected to? The entity graph provides this without requiring analysts to construct complex queries.

**Operational role:**
- `EntityGraphService::projectFromAlerts()` — read-only scan of `security_alerts`, projects entity records
- `upsertEntity()` — creates or updates entity observation count (uses `DB::table()->increment()` to bypass Eloquent cast issues with PostgreSQL)
- `appendObservation()` — ALWAYS INSERT, never update (append-only audit trail)
- `buildAdjacency()` — depth-bounded graph traversal (max depth 1, limit 30 nodes by default)
- Entity types: user, host, ip, domain, process, file_hash, alert, incident, trace

**Design tradeoff:** The entity graph is a projection/index, not an authoritative source. This means: (1) it can be rebuilt from `security_alerts` if corrupted; (2) it does not require consistency guarantees with the pipeline. The tradeoff is eventual consistency — a new alert may take one projection cycle to appear in the graph.

**Limitations:** Graph depth is bounded (not a full graph traversal). Entity linkage is based on field co-occurrence in alerts (same actor_key → same user entity), not on semantic identity resolution. Large graphs (>30 nodes) are truncated.

**Future work boundary:** Full graph traversal with configurable depth limits; semantic entity resolution (deduplicating aliases); real-time entity updates (streaming projection instead of batch).

---

## 7. Entity Risk Scoring (Laravel module)

**Purpose:** Prioritize investigation by providing a deterministic, explainable risk score per entity.

**Why it exists:** Without risk scores, analysts must manually evaluate each entity. A score system (0–10) with explainable factors (critical_alerts count, C2 indicators, incident involvement) lets analysts triage efficiently — focus on score ≥ 7.5 (critical) first.

**Operational role:**
- `EntityRiskScoringService::calculateRisk()` — pure read-only, deterministic computation
- Weighted factors: critical_alerts (×3.0), c2_indicator (×3.5), incident_involvement (×3.0), MFA_failure_burst (×2.5), shadow_alert_advisory (×0.5), etc.
- Shadow indicators (endpoint/C2) are advisory-weighted at 0.5 — visible but not driving high scores
- Score = `min(sum of weighted factors, 10.0)` — bounded at 10
- `calculateAndPersist()` — updates entity + appends snapshot to `entity_risk_snapshots`

**Design tradeoff:** Fixed weights are set by domain expertise, not learned from data. This makes scores reproducible and explainable but not adaptive. A different analyst might weight C2 indicators differently than persistence indicators.

**Limitations:** Weights are static (no feedback loop). Shadow indicators could legitimately be high without representing a real threat (high false-positive risk for C2 indicator on endpoint domain). Score does not decay over time — an entity that had a critical alert 6 months ago still carries that weight.

**Future work boundary:** Analyst-labeled weight tuning (supervised learning over risk score → investigation outcome pairs); temporal decay function; confidence-weighted scoring.

---

## 8. Investigation Workflow Orchestration (Laravel module)

**Purpose:** Structured, auditable case management for SOC analysts.

**Why it exists:** Without a structured workflow, SOC investigations are tracked in external tools (tickets, spreadsheets), creating fragmentation and audit gaps. An integrated workflow keeps the investigation, evidence, and audit trail co-located with the alert and entity data.

**Operational role:**
- `InvestigationOrchestratorService` manages 8-state machine
- State transitions enforced at service layer (`assertValidTransition()` → `InvalidArgumentException`)
- All transitions → `investigation_events` (APPEND-ONLY)
- Assignment history → `investigation_assignments` with `is_active` flag (previous assignments preserved)
- `contained_manual` state = analyst documented external action; ZERO system execution

**Design tradeoff:** A state machine that rejects invalid transitions can frustrate analysts who need to skip states in an emergency. The state machine is intentionally strict for audit integrity. Analysts can always add notes explaining context.

**Limitations:** Single-analyst assignment model (no shared ownership). No SLA tracking integrated into state machine (SLA is a separate monitoring concern). No automated state transitions based on external signals.

**Future work boundary:** Multi-analyst collaborative investigation; SLA-triggered escalation automation; integration with ticketing systems (Jira, ServiceNow) via webhook.

---

## 9. Response Planning (Laravel module)

**Purpose:** Document analyst response recommendations and approvals with zero automated execution.

**Why it exists:** SOC analysts need to document their response decisions for compliance, post-incident review, and knowledge transfer. A formal recommendation layer (with approval workflow) provides this documentation structure while making it architecturally impossible to trigger automated actions.

**Operational role:**
- `ResponsePlanningService::generateRecommendationsForEntity()` — deterministic, no DB writes
- `recommend_*` action types only (10 types) — no `execute_*` columns exist
- 6-state approval workflow: draft → pending_approval → approved → completed_documented
- `response_plan_approvals` APPEND-ONLY
- Disclaimer enforced in every view: *"Recommendations are advisory-only and were not automatically executed by the platform."*

**Design tradeoff:** Deterministic rule-based recommendation generation (not LLM-based) ensures reproducibility and explainability at the cost of recommendation quality for novel attack scenarios. An LLM would suggest more contextually appropriate responses but introduces hallucination risk.

**Limitations:** Recommendations are based on entity risk factors (user/host/ip type + risk level), not on alert evidence content. No learning from analyst decisions (which recommendations were actually followed). No integration with external ticket systems.

**Future work boundary:** Feedback-loop learning from analyst follow-through rates; LLM-assisted recommendation generation with human review; SOAR playbook integration (via documented advisory output, not automated execution).

---

## 10. Security Hardening (Laravel module)

**Purpose:** Make the platform's own security posture observable and auditable.

**Why it exists:** A detection platform that is itself insecure is architecturally incomplete. The hardening module enforces service-to-service authentication, validates secrets, and provides an append-only audit trail of security events.

**Operational role:**
- `InternalAuthService::signToken/verifyToken` — time-bounded HMAC service tokens
- `InternalAuthService::signEvent/verifyEvent` — deterministic event envelope signatures
- `InternalServiceAuthMiddleware` — enforces `X-Internal-Service-Token` on `/api/internal/*`
- `SecretsValidationService` — detects dev defaults, missing secrets
- `security_hardening_events` APPEND-ONLY — auth_failure, signature_failure, secret_warning, startup_validation

**Design tradeoff:** Shared HMAC secret (not per-service certificates) is simpler to deploy but does not provide per-service revocation. If the shared secret is compromised, all internal API access is compromised.

**Limitations:** `verifyEvent()` is non-destructive on failure — invalid signatures are logged but events are not dropped. This is intentional (avoid pipeline disruption) but means the pipeline cannot guarantee event integrity end-to-end.

**Future work boundary:** Per-service mTLS certificates; event signature enforcement in normalizer-worker (reject events with invalid signatures); automatic secret rotation.

---

## 11. Resilience Validation (Laravel module)

**Purpose:** Provide evidence that the platform survives degraded and partial-failure conditions.

**Why it exists:** A detection platform that fails silently during a service restart or broker reconnection is operationally dangerous — you may be unaware that alerts are not being generated. The resilience module validates specific failure recovery behaviors.

**Operational role:**
- 14 scenarios: 9 simulation (validates code capability) + 5 active (executes real checks)
- Simulation scenarios: verify reconnect loops, DLQ fallback, ON CONFLICT design exist in code
- Active scenarios: actually test idempotency, endpoint shadow isolation, signature failure non-destructiveness, auth failure logging
- Metrics: recovery_duration_seconds, consumer_lag_peak, replay_idempotent, failed_signature_count
- Reports written to `storage/resilience/`

**Design tradeoff:** Simulation scenarios validate design capability, not live behavior. A "broker_restart" simulation validates that the reconnect loop code exists but does not actually restart Redpanda. This is appropriate for the current phase, where live chaos engineering against a single-node deployment would be destructive — real multi-node chaos validation is tracked separately (see ENT-REL-SIMULATED-HA).

**Limitations:** Simulation scenarios cannot detect regressions introduced by code changes that remove the reconnect loop. Active scenarios only cover a subset of all possible failure modes.

**Future work boundary:** Chaos engineering integration (controlled Redpanda restart in isolated test environment); property-based testing for resilience invariants; continuous resilience validation in CI pipeline.

---

## 12. Replay-Safe Validation (Platform-Wide Pattern)

**Purpose:** Guarantee that every event persisted to `xdr_operational_events` is idempotent — the same event replayed any number of times produces exactly one record.

**Why it exists:** SOC audit trails are forensic records. If a service restart causes duplicate entries, the integrity of the entire investigation timeline is compromised. Replay safety is the foundational guarantee for the event store.

**Operational role:**
- PostgreSQL `ON CONFLICT (event_id) DO NOTHING` — database-level uniqueness enforcement
- `insertOrIgnore()` in Laravel — generates `INSERT ... ON CONFLICT DO NOTHING` for PostgreSQL, safe in any transaction state
- `ResilienceValidationService::verifyOperationalEventIdempotency()` — active test: insert same event_id twice, verify count = 1
- Test: `test_replay_after_restart_preserves_deterministic_behavior` — automated invariant verification

**Design tradeoff:** `ON CONFLICT DO NOTHING` silently discards the second insert. If a genuine logic error causes two different events to receive the same event_id, the second is silently lost. The design assumes event_id generation is collision-resistant (UUID v4 or deterministic hash of content).

**Limitations:** Does not prevent two different service instances from generating the same event_id for semantically different events (unlikely with UUID v4, but not impossible). Does not detect all forms of data corruption — only duplicate-by-event_id.

**Future work boundary:** Content-hash-based event fingerprinting for richer idempotency; distributed event deduplication window (Redis-based) for cross-instance dedup before DB write.
