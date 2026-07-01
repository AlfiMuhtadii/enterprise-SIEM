# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Open Backlog Tasks

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **AI-KB-FEEDBACK-LOOP** | [AI-KB] Create closed-loop analyst feedback ingestion service for approved suggestions | `app/Http/Controllers/SocAiController.php`, `app/Support/AiAnalystManager.php` | Low | Proposed |
| **ENV-CACHE-DRIFT-BATCH** | [CONFIG] (Partial — runtime slice done 2026-07-01) Migrate remaining direct `env()` calls in `app/` to `config()`. DONE: 4 integration adapters (→ `config/integrations.php`), `TrustProxies`, `AppServiceProvider` force_https. REMAINING: CLI/advisory tooling (`ResilienceValidationService`, `Phase1SoakExecutionService`, `RealDomainSoakPlanService`, `EndpointSoakPlanService`, `Ops/TenantBackfill` commands, `SecurityHardeningController`) — several map to existing `config/xdr.php` keys whose defaults must be reconciled first. | `app/Services/*`, `app/Console/Commands/*`, `app/Http/Controllers/Security/SecurityHardeningController.php` | Medium | Proposed |






> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). The 5 hardening tasks (ENV-CACHE-DRIFT, CMD-SHARED-HMAC, AGENT-TENANCY-GAP, TENANT-UNSCOPED-TABLES, RATE-LIMIT-BYPASS) → COMPLETED at `4ee9675`. See REVIEW_REJECTED.md / REVIEW_COMPLETED.md.
>
> **Completed (2026-06-30):** `NOTIFY-TENANCY-GAP` → `5db597c`. `PERF-IOC-LOOP` + `PERF-ALERT-TUNE` (alert-write-path N+1 → bulk) → `1e4bc3c`. `PERF-AGENT-UPDATE` + `PERF-AGENT-HEALTH-N1` (agent-management N+1 → bulk/eager-load) → this batch. See REVIEW_COMPLETED.md.
>
> **Completed (2026-07-01):** review-finding fixes `IOC-HITS-IDEMPOTENCY` (unique index + idempotent enrich), `AGENT-SECRET-DECRYPT-500` (guarded decrypt → 401), `RESP-POLICY-FAIL-OPEN` (fail-closed on malformed expiry). `ENV-CACHE-DRIFT-BATCH` runtime slice (integration adapters + TrustProxies + force_https) done; CLI/advisory slice remains. See REVIEW_COMPLETED.md.
>
> **Deferred (2026-07-01):** hot-path Go (`PERF-GO-LIMITER`, `PERF-GO-OVERCONCURRENT`, `PERF-GO-HOT-HTTP`), core-pipeline rearchitecture (`PERF-REST-POLL`, `PERF-REST-REBALANCE`, `ARCH-KAFKA-NATIVE`, `ARCH-DB-SPLIT`), infra (`ARCH-MTLS-SEC`, `ARCH-DISCOVERY`), `RATE-LIMIT-DOS` (careful Go work), and AI live-model (`AI-KB-SEMANTIC`, `AI-KB-FEED-INGEST`) → see REVIEW_REJECTED.md §2 (BATCH-DEFER-2026-07-01).











---
