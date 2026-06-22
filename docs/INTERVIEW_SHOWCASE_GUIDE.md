# Interview Showcase Guide

**Audience:** Technical interviews, portfolio presentations, recruiter demos  
**Estimated time:** 5 minutes (quick) / 15 minutes (detailed)  
**Objective:** Demonstrate engineering depth, architecture thinking, and production discipline

---

## 5-Minute Showcase

### Opening Statement (30 seconds)

> "This is an academic XDR-like security platform I built from scratch. It demonstrates a polyglot microservice architecture — Go ingestion pipeline, Python analytics services, PHP SOC control plane — all connected via a Kafka-compatible event backbone. The key discipline is that every architectural decision has a test, and every operational boundary is enforced by code."

### 3 Things to Show (4 minutes)

**1. The validation baseline** (1 minute)

```powershell
php artisan test
```

Show: 3077 tests, 0 failures. Point out the test count is not inflated — each test covers a real behavior or contract.

**2. The architecture boundary** (1.5 minutes)

Navigate to http://localhost:8000/demo-platform/architecture

Show the active vs shadow scope visualization. Explain:
- Identity/cloud/SaaS: active (passed 6h soak)
- Endpoint/DNS/proxy: shadow (no domain-specific soak yet)
- Explain why this matters: "You can't just flip a switch to production. Every domain needs validation evidence."

**3. The governance depth** (1.5 minutes)

Navigate to http://localhost:8000/xdr-certification

Show the acceptance gates. Point out:
- `SELF_APPROVE_BLOCKED` — can't approve your own release
- Rollback-ready gate
- Soak validation gate

> "The governance isn't theater. It's enforced by code — if you try to self-approve, the service throws an exception."

---

## 15-Minute Showcase

### Phase 1 — Architecture (3 minutes)

Show the event flow:

```
Telemetry → ingestion-gateway (Go, HMAC-SHA256)
  → Redpanda (event backbone)
  → normalizer-worker (Go)
  → correlation-worker (Go)
  → alert-writer-service (Python)
  → Laravel SOC control plane
```

Explain the strangler pattern: "We started with a Laravel monolith and incrementally extracted Go microservices. Each extraction is validated before promotion. The circuit breaker ensures we can always fall back."

### Phase 2 — Detection Depth (3 minutes)

Navigate to http://localhost:8000/xdr-maturity/detection

Show:
- 133 rules across 8 categories
- Precision/recall scoring per category
- ATT&CK tactic/technique coverage
- Shadow vs active rule classification

Explain: "Rule-based detection for precision. Logistic regression for recall on novel patterns. Combined in the correlation worker."

### Phase 3 — Threat Hunting (3 minutes)

Navigate to http://localhost:8000/threat-hunts

Create a hunt on `processes` domain with filter `is_suspicious = true`.

Show:
- 158 query domains available
- Allowlisted fields per domain (no arbitrary SQL injection risk)
- Advisory-only results (append-only, never destructive)
- Multi-hop graph investigation available

### Phase 4 — Governance Maturity (3 minutes)

Show two governance subsystems:

**SOAR** (http://localhost:8000/soar): simulation-first → blast radius score → dual approval → execution
**Compliance** (http://localhost:8000/compliance): evidence hashing, PII audit, tenant isolation, export governance

### Phase 5 — Engineering Discipline (3 minutes)

Show these numbers:
- 3077 PHP tests, 186 Python tests
- 133 detection rules validated by 21-check registry
- 50+ governance subsystems
- 158 threat hunting domains
- Append-only architecture: 100+ audit tables, no DELETE/UPDATE on records
- 6h soak validation evidence for active domains

---

## Common Interview Questions & Answers

### "How did you handle the polyglot complexity?"

"Each service has a single responsibility and communicates via typed Redpanda topics. The event contracts are validated by `xdr_contract_validate.py`. If a service crashes, the consumer reconnect loop resumes from the last committed offset — no data loss."

### "Why did you choose Go for the pipeline?"

"Go's goroutine model maps well to the producer/consumer pattern. Each correlation domain runs as a separate goroutine. Memory usage is predictable. Startup time is milliseconds. For the SOC control plane — RBAC, governance, analyst workflows — PHP/Laravel is a better fit because of developer velocity."

### "How do you ensure correctness without a staging environment?"

"The replay-safe architecture means I can run `php artisan migrate:fresh --force && php artisan test` and get a completely deterministic result. Every validator produces exit code 0 for PASS, 1 for FAIL — no ambiguity. The 6h soak is the production-equivalent gate."

### "What would you do differently?"

"I'd add kernel-level telemetry from day one using eBPF. User-space endpoint visibility has gaps — the agent misses kernel events. I'd also deploy the Go services on Kubernetes from the start rather than adding the HA governance layer retroactively."

### "What was the hardest architectural decision?"

"Deciding what to keep shadow-only. The temptation is to mark everything active. But without a domain-specific 6h soak, you don't know if your detection logic produces false positives at scale. The ACTIVE_ALLOWLIST being empty for shadow domains is the right discipline — it forced us to be honest about what's validated vs what's implemented."

---

## Technical Depth Questions (If Asked)

| Question | Quick Answer |
|---|---|
| Event ordering | Redpanda consumer groups with committed offsets; sequence_id on stream events |
| Idempotency | SHA-256 fingerprint + ON CONFLICT DO NOTHING |
| Rollback | XDR_CORRELATION_FALLBACK_TO_LEGACY=true; circuit breaker at 3 consecutive failures |
| Tenant isolation | Tenant ID in all critical tables; cross-tenant detection in governance layer |
| AI/ML | Logistic regression in correlation-worker; RAG with Qdrant for analyst assist |
| Test strategy | RefreshDatabase trait; migrate:fresh before full suite; no parallel DB tests |
