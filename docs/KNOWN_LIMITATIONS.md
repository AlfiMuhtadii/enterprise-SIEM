# Known Limitations

**Date:** 2026-05-24  
**Status:** Documented, academically defensible, intentionally scoped

This document honestly describes the known limitations of the platform. These are not defects — they reflect deliberate academic scope boundaries, advisory-only safety posture, and resource constraints appropriate for a research project.

---

## 1. Endpoint Telemetry — User-Space Only

**Limitation:** The Python endpoint agent collects user-space telemetry only. Kernel-level events (system calls, raw network packets, kernel module loads) are not captured.

**Why:** Kernel telemetry requires a kernel driver or eBPF program. This is outside the academic scope of this project and would introduce operational complexity far beyond what is appropriate for a research platform.

**Impact:** Some advanced evasion techniques that operate below user-space are not detectable. Process injection, rootkit activity, and kernel-mode persistence are not visible to this agent.

**Mitigation:** Anti-evasion indicators are tracked at user-space level (process ancestry anomalies, hollow process indicators, image load mismatches). 8 anti-evasion shadow rules are implemented.

---

## 2. No Live Host Containment

**Limitation:** The platform cannot isolate, quarantine, or contain a host. Response actions are advisory-only and analyst-approved. `isolateHost` and `quarantineHost` are intentionally absent from the codebase.

**Why:** Autonomous host isolation in an academic platform creates serious operational risk. The academic scope is advisory detection and investigation, not live remediation.

**Impact:** In a real incident, an analyst would need to take containment action via a separate tool (EDR platform, firewall rule, network ACL).

---

## 3. No Autonomous Remediation

**Limitation:** All response plans require simulation + dual analyst approval. No `execute_*` action types exist. `autoRemediate` is not implemented.

**Why:** Autonomous remediation requires production-grade safety validation that is outside the academic scope. The governance framework (simulation-first, dual-approval, blast-radius scoring) is implemented as the correct discipline.

**Impact:** Response time is bounded by analyst availability. This is appropriate for a SOC workflow platform.

---

## 4. Shadow-Only Endpoint/DNS/Proxy Correlation

**Limitation:** Endpoint behavioral, DNS, proxy, and firewall correlation remain shadow-only. Alerts from these domains are not written to the active `xdr.alerts` topic.

**Why:** Domain-specific 6h soaks have not been conducted for these domains. The `ACTIVE_ALLOWLIST` is intentionally empty — no shadow rule has been promoted without validation evidence.

**Impact:** Detections from these domains are visible in the advisory/shadow pipeline but do not create live security alerts.

**Path to production:** Conduct a domain-specific 6h soak for each domain; if PASS (fallback_count=0, failure_count=0, duplicate_rate=0), add the domain to `ACTIVE_ALLOWLIST` and re-run the registry validator.

---

## 5. Single-Node Infrastructure

**Limitation:** The platform runs on a single Docker Compose host. There is no Kubernetes deployment, no multi-node Redpanda cluster, no PostgreSQL replication.

**Why:** Academic project. Multi-node infrastructure would add significant operational overhead without changing the architectural correctness of the platform.

**Impact:** The HA governance subsystem (cluster topology, failover coordination) is fully implemented and validates correctly, but it governs a simulated topology rather than a real distributed cluster.

---

## 6. Simulated External Integrations

**Limitation:** External integrations (Okta, Azure AD, Office 365, GSuite, Jira, ServiceNow, Slack, PagerDuty) use simulated delivery by default. No real credentials are configured.

**Why:** Real integration credentials require production accounts and create operational dependencies outside the academic scope.

**Impact:** Integration events (IdP logins, SaaS audit logs, ticket creation, notifications) are generated from synthetic fixtures. The integration pipelines are fully implemented; only the delivery endpoints are simulated.

---

## 7. AI/RAG Heuristic Fallback

**Limitation:** The AI/RAG service (`ai-rag-service`) falls back to heuristic responses when Qdrant is unavailable or the vector store is empty.

**Why:** Vector store population requires a seeding pipeline with real telemetry corpus. The heuristic fallback ensures the service remains functional in development environments.

**Impact:** Analyst assist quality degrades gracefully when Qdrant is unavailable. The RAG capability is not demonstrated in the primary evaluation path.

---

## 8. No Automated ML Retraining

**Limitation:** The logistic regression model is static. There is no automated retraining pipeline triggered by new telemetry.

**Why:** Automated ML retraining requires data labeling pipelines, model versioning, and shadow deployment infrastructure that is outside the academic scope.

**Impact:** Detection quality for novel attack patterns does not improve over time without manual retraining.

---

## 9. Performance at Scale

**Limitation:** The platform has not been validated at production telemetry volumes (10,000+ events/second). The `TelemetryScalePilotService` validates up to 100 endpoints (MAX=100).

**Why:** Academic project. Production load testing requires dedicated infrastructure.

**Impact:** Performance at scale is modeled via capacity governance projections (linear, confidence=0.70, 10× headroom) but not empirically validated.

---

## 10. No Detection Model Explanation (XAI)

**Limitation:** The logistic regression outputs a classification and confidence score but does not provide feature importance or SHAP explanations per alert.

**Why:** XAI integration is planned as future work (see `docs/FUTURE_ROADMAP.md`).

**Impact:** Analysts receive the classification verdict and confidence score but cannot inspect which features drove the decision.

---

## 11. Validator Processing Movement — Not True Kafka Consumer Lag

**Limitation:** The live pipeline validator's checks 12–13 (`check_worker_processing_movement`) report `delta = max_offset − processed_since_restart`. This is **not** true Kafka consumer group committed-offset lag.

**Why:** After any container restart, `processed_since_restart` resets to 0 while `max_offset` accumulates. `delta = max_offset` even when the worker has committed its offset and is fully healthy. True lag (`committed_offset − high_watermark` per consumer group) requires a broker consumer group query — a side effect that this read-only validator cannot perform.

**Impact:** A large delta after a restart is expected and does not indicate a problem. The validator correctly interprets `recreate_count >= 10` or `poll_error_count >= 10` as failure indicators (these survive restarts). For accurate lag measurement use `rpk consumer-group describe <group>`.

**Mitigation:** The validator function and check labels explicitly say "processing movement (not committed-offset lag)". The evidence strings say `delta~{n}` not `lag~{n}`. Section 12 of `docs/guides/LIMITATIONS_AND_CLAIMS.md` documents this in full.
