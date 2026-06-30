# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Open Backlog Tasks

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **AI-KB-SEMANTIC** | [AI-KB] Implement Qdrant vector semantic search and sentence embedding pipeline | `app/Support/SocKnowledgeRetriever.php`, Qdrant config | High | Proposed |
| **AI-KB-FEED-INGEST** | [AI-KB] Build MITRE ATT&CK and threat intelligence RSS feed dynamic ingestion pipeline | `app/Services/AiKnowledgeSeedService.php`, `AiSeedKnowledgeCommand.php` | Medium | Proposed |
| **AI-KB-FEEDBACK-LOOP** | [AI-KB] Create closed-loop analyst feedback ingestion service for approved suggestions | `app/Http/Controllers/SocAiController.php`, `app/Support/AiAnalystManager.php` | Low | Proposed |
| **PERF-SUBPROCESS-POLL** | [PERF] Refactor ClickHouse sync daemon to use in-process polling instead of spawning python subprocesses | `scripts/clickhouse_sync_daemon.py`, `scripts/sync_postgres_to_clickhouse.py` | Medium | Proposed |
| **PERF-GO-LIMITER** | [PERF] Refactor Go ingestion rate limiters to use mathematical time-delta calculations instead of channel loops | `services/ingestion-gateway/main.go` | High | Proposed |
| **PERF-PYTHON-HTTP** | [PERF] Refactor python HTTP client requests in alert writer and incident builder to use persistent Session pools | `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py` | Medium | Proposed |
| **STATE-REDIS-05** | [CORRELATION] Refactor Go Correlation worker state store to use Redis connection pooling | `services/correlation-worker/main.go` | Medium | Proposed |
| **RATE-LIMIT-DOS** | [INGESTION] Restrict rate limiter instantiation to verified tenants to prevent memory exhaustion DoS | `services/ingestion-gateway/main.go` | High | Proposed |
| **PERF-DB-CONN-LEAK** | [PERF] Refactor Python workers (alert writer / incident builder) to use database connection pooling | `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py` | High | Proposed |
| **PERF-REST-POLL** | [PERF] Refactor Go workers (normalizer / correlation) to use native binary Kafka protocol instead of HTTP REST | `services/normalizer-worker/main.go`, `services/correlation-worker/main.go` | High | Proposed |
| **PERF-GO-OVERCONCURRENT** | [PERF] Eliminate per-batch goroutine and channel allocations in Go workers to avoid GC churn | `services/normalizer-worker/main.go`, `services/correlation-worker/main.go` | Medium | Proposed |
| **PERF-GO-HOT-HTTP** | [PERF] Refactor hot-loop synchronous HTTP IOC lookups to use thread-safe in-memory cache | `services/correlation-worker/main.go` | High | Proposed |



> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). The 5 hardening tasks (ENV-CACHE-DRIFT, CMD-SHARED-HMAC, AGENT-TENANCY-GAP, TENANT-UNSCOPED-TABLES, RATE-LIMIT-BYPASS) → COMPLETED at `4ee9675`. See REVIEW_REJECTED.md / REVIEW_COMPLETED.md.
>
> **Completed (2026-06-30):** `NOTIFY-TENANCY-GAP` → `5db597c`. `PERF-IOC-LOOP` + `PERF-ALERT-TUNE` (alert-write-path N+1 → bulk) → `1e4bc3c`. `PERF-AGENT-UPDATE` + `PERF-AGENT-HEALTH-N1` (agent-management N+1 → bulk/eager-load) → this batch. See REVIEW_COMPLETED.md.











---
