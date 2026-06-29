# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Open Backlog Tasks

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **NOTIFY-TENANCY-GAP** | [NOTIFICATION] Implement tenant-specific lookup and isolation for webhook, Slack, and Discord alerts | `app/Console/Commands/SocSlaEscalationCommand.php`, `app/Services/SocNotifier.php`, DB migrations | High | Proposed |
| **AI-KB-SEMANTIC** | [AI-KB] Implement Qdrant vector semantic search and sentence embedding pipeline | `app/Support/SocKnowledgeRetriever.php`, Qdrant config | High | Proposed |
| **AI-KB-FEED-INGEST** | [AI-KB] Build MITRE ATT&CK and threat intelligence RSS feed dynamic ingestion pipeline | `app/Services/AiKnowledgeSeedService.php`, `AiSeedKnowledgeCommand.php` | Medium | Proposed |
| **AI-KB-FEEDBACK-LOOP** | [AI-KB] Create closed-loop analyst feedback ingestion service for approved suggestions | `app/Http/Controllers/SocAiController.php`, `app/Support/AiAnalystManager.php` | Low | Proposed |
| **PERF-AGENT-UPDATE** | [PERF] Convert N+1 UPDATE queries in agent command retrieval loop to bulk updates | `app/Http/Controllers/AgentIngestionController.php` | Medium | Proposed |
| **PERF-IOC-LOOP** | [PERF] Refactor threat intel nested loop with synchronous writes to use bulk insert | `app/Http/Controllers/SocThreatIntelController.php` | High | Proposed |
| **PERF-ALERT-TUNE** | [PERF] Refactor alert suppression rule matching N+1 queries to use bulk updates and inserts | `app/Http/Controllers/SocTuningController.php` | High | Proposed |
| **GIT-RM-PYC** | [REFACTOR] Remove tracked compiled Python bytecode (*.pyc) files from Git cache | Root directory (.gitignore, git cached rm) | Low | Proposed |
| **PERF-SUBPROCESS-POLL** | [PERF] Refactor ClickHouse sync daemon to use in-process polling instead of spawning python subprocesses | `scripts/clickhouse_sync_daemon.py`, `scripts/sync_postgres_to_clickhouse.py` | Medium | Proposed |
| **PERF-AGENT-HEALTH-N1** | [PERF] Convert N+1 database queries in agent health check schedule loop to joins/eager loading | `app/Console/Commands/AgentHealthCheckCommand.php` | Medium | Proposed |
| **PERF-GO-LIMITER** | [PERF] Refactor Go ingestion rate limiters to use mathematical time-delta calculations instead of channel loops | `services/ingestion-gateway/main.go` | High | Proposed |
| **PERF-PYTHON-HTTP** | [PERF] Refactor python HTTP client requests in alert writer and incident builder to use persistent Session pools | `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py` | Medium | Proposed |
| **PERF-TRANSACTION-GAP** | [PERF] Wrap sequential Laravel write operations in database transactions to preserve data integrity | `app/Http/Controllers/*`, `app/Console/Commands/SocSlaEscalationCommand.php` | Medium | Proposed |
| **AI-CONTEXT-EMPTY** | [AI] Include alert details and RAG knowledge base text in compactContext to prevent LLM blindness | `app/Support/AiAnalystManager.php` | High | Proposed |
| **STATE-REDIS-05** | [CORRELATION] Refactor Go Correlation worker state store to use Redis connection pooling | `services/correlation-worker/main.go` | Medium | Proposed |

> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). The 5 hardening tasks (ENV-CACHE-DRIFT, CMD-SHARED-HMAC, AGENT-TENANCY-GAP, TENANT-UNSCOPED-TABLES, RATE-LIMIT-BYPASS) → COMPLETED at `4ee9675`. See REVIEW_REJECTED.md / REVIEW_COMPLETED.md.











---
