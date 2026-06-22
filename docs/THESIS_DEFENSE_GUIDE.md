# Thesis Defense Guide

**Title:** Hybrid Near Real-Time Web Attack Detection Platform using Rule-Based Detection and Multiclass Logistic Regression within an Event-Driven Investigation Architecture  
**Estimated defense time:** 45–90 minutes  
**Platform validation date:** 2026-05-24

---

## Defense Preparation Checklist

- [ ] Run `php artisan migrate:fresh --force && php artisan test` — confirm 3077 passed
- [ ] Run `python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v` — confirm 186 passed
- [ ] Run `python scripts/xdr_rule_registry_validate.py` — confirm PASS, 133 rules
- [ ] Start platform: `.\bootstrap-dev.ps1` then `php artisan serve`
- [ ] Open Demo Dashboard at http://localhost:8000/demo-platform
- [ ] Open XDR Maturity at http://localhost:8000/xdr-maturity
- [ ] Open XDR Certification at http://localhost:8000/xdr-certification
- [ ] Have `docs/FINAL_CAPABILITY_MATRIX.md` open for reference

---

## Section 1 — Research Methodology

### Q: What is the research problem?

**Answer:** Web application attacks are becoming increasingly sophisticated and operate at speeds that outpace manual SOC analysis. Existing open-source solutions offer either rule-based detection (high precision, low adaptability) or machine learning (high adaptability, difficult to interpret). This research combines both approaches in an event-driven architecture to achieve near real-time detection with interpretable, advisory-only outputs.

### Q: Why event-driven architecture?

**Answer:** Event-driven architecture provides replay safety — every detection decision can be reconstructed from the append-only event log. This enables deterministic validation, audit integrity, and rollback without data loss. It also enables the strangler pattern: we can migrate individual detection domains from a monolith to microservices incrementally, with a circuit breaker for fallback.

### Q: What does "hybrid" mean in the title?

**Answer:** Hybrid refers to two detection mechanisms operating in parallel:
1. **Rule-based detection** — explicit rules per MITRE ATT&CK tactic/technique, high precision, deterministic
2. **Multiclass logistic regression** — behavioral classification trained on normalized telemetry, high recall on novel attack patterns

The outputs are correlated in the event-driven pipeline, producing a fused advisory verdict.

---

## Section 2 — Architecture Defense

### Q: Why PHP/Laravel for the control plane instead of a microservice?

**Answer:** Laravel serves as the SOC control plane — RBAC, dashboards, incidents, governance. It is not on the alert write path. The performance-critical components (ingestion, normalization, correlation) are in Go. Laravel handles stateful analyst workflows where developer velocity and maintainability matter more than throughput.

### Q: Why is the endpoint correlation shadow-only?

**Answer:** A domain-specific 6h soak is required before promoting any shadow domain to active. Identity/cloud/SaaS passed its soak on 2026-05-14. Endpoint behavioral correlation has not yet been soaked — promoting it prematurely would violate the replay-safe architecture discipline. The `ACTIVE_ALLOWLIST` is intentionally empty for shadow domains.

### Q: What is the strangler migration pattern?

**Answer:** We started with a monolith Laravel SOC and incrementally extracted performance-critical components to microservices — first Go ingestion, then Go normalization, then Go correlation. Each extraction is validated by a 6h soak before promotion. The circuit breaker (`XDR_CORRELATION_FALLBACK_TO_LEGACY=true`) ensures we can fall back to the legacy path at any time.

### Q: How does replay safety work?

**Answer:** All event stores use `ON CONFLICT DO NOTHING` with deterministic idempotency keys. Every piece of telemetry has a trace_id that propagates through the entire pipeline. Given the same trace_id and the same events, the system produces identical outputs on every replay. This is validated by the replay pipeline explorer and the event flow resilience validator.

---

## Section 3 — Detection & Validation

### Q: How do you validate detection quality?

**Answer:** We use `CodeLevelXdrMaturityService` to score detection quality across 7 dimensions: precision, recall, false-positive rate, false-negative rate, ATT&CK coverage, evidence linkage, and detection stability. The scores are deterministic — same input produces same output — and advisory-only. We also run adversarial replay validation to test detection against evasion techniques.

### Q: What does the rule registry validate?

**Answer:** The registry validator (`xdr_rule_registry_validate.py`) runs 21 checks across 133 rules: schema validity, field completeness, stage classification consistency, ACTIVE_ALLOWLIST integrity, and shadow domain boundaries. It exits 0 on PASS, 1 on FAIL, 2 on ERROR.

### Q: Why 133 rules? Why not more?

**Answer:** Quality over quantity. Each rule has a defined ATT&CK mapping, a stage classification (staged_active vs shadow), and has passed the registry validator. We chose 133 rules that provide meaningful ATT&CK coverage across 11 tactics rather than inflating the count with low-quality rules.

### Q: What is the XDR maturity score?

**Answer:** We use a 5-tier model (initial/developing/defined/managed/optimizing) based on a weighted average of 7 subsystem scores. The platform self-assesses at "Managed" (tier 4) — we have defined, measured, and governance-controlled processes. "Optimizing" would require automated continuous improvement loops, which is outside the advisory-only academic scope.

---

## Section 4 — Limitations & Future Work

### Q: What are the known limitations?

**Answer:** See `docs/KNOWN_LIMITATIONS.md`. Key limitations:
1. No kernel EDR — endpoint visibility is user-space only via the Python agent
2. No live host containment — all response is advisory and analyst-approved
3. Single-node infrastructure — HA governance is implemented but not deployed on a real cluster
4. Simulated integrations — Okta, Office 365, Jira, Slack use simulated delivery by default
5. No automated endpoint recovery — bounded to 1800s max via governance constants

### Q: What would production deployment require?

**Answer:** Kubernetes orchestration, multi-node Redpanda cluster, production PostgreSQL with replication, real IdP/SIEM integration credentials, and domain-specific 6h soaks for all shadow domains. The governance infrastructure is in place — the deployment gap is infrastructure, not software.

### Q: What is the future roadmap?

**Answer:** See `docs/FUTURE_ROADMAP.md`. Priority items:
1. Kernel telemetry integration (eBPF-based, production-grade)
2. Live host containment with multi-analyst approval
3. Automated ML model retraining pipeline
4. Multi-node Kubernetes deployment
5. Real-time threat intelligence feed integration

---

## Section 5 — Live Demo for Defense

**Recommended 10-minute demo sequence:**

1. Show the Demo Readiness Dashboard — `overall_ready=true`
2. Show the Attack Timeline — phishing chain with ATT&CK annotations
3. Show the XDR Maturity scorecard — maturity tier and component scores
4. Show the Capability Matrix — honest tier disclosure including `not_implemented`
5. Show the XDR Certification — acceptance gates, SELF_APPROVE_BLOCKED
6. Show `php artisan test` output — 3077 passed, 0 failures

**Key statement for defense:** "Every component shown is backed by a test, every architectural claim is validated by a script, and every advisory-only constraint is enforced by code — not just documented."
