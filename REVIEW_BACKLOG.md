# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Proposed Tasks (Awaiting Claude Validation)

### Proposed Task: [INGESTION-GATEWAY] Refactor normalizer queue depth checks to use asynchronous polling
* **Source**: [REVIEW_REPORTS.md](file:///D:/project/Detector/REVIEW_REPORTS.md#finding-ig-1-synchronous-metrics-polling-for-admission-control) (Finding IG-1)
* **Approved scope**: `services/ingestion-gateway/main.go`
* **Acceptance criteria**:
  - The gateway must run a background goroutine that polls the normalizer metrics endpoint (e.g. every 1 second) and caches the status.
  - The request handler's `admissionAllowed()` method must query the cached status instead of executing synchronous HTTP calls.
  - Ensure all other gateway tests pass.

---

### Proposed Task: [INGESTION-GATEWAY] Implement client-scoped or tenant-scoped rate limiting to prevent global starvation
* **Source**: [REVIEW_REPORTS.md](file:///D:/project/Detector/REVIEW_REPORTS.md#finding-ig-2-global-rate-limiting-starvation) (Finding IG-2)
* **Approved scope**: `services/ingestion-gateway/main.go`
* **Acceptance criteria**:
  - Refactor `rateLimit` middleware to enforce limits per client IP, client token, or tenant ID rather than a single global bucket.
  - Assert that a flooded client does not block traffic from other healthy clients.

---

### Proposed Task: [INGESTION-GATEWAY] Reduce publish HTTP client timeout to prevent socket exhaustion during outages
* **Source**: [REVIEW_REPORTS.md](file:///D:/project/Detector/REVIEW_REPORTS.md#finding-ig-3-overly-long-outage-retry-timeout) (Finding IG-3)
* **Approved scope**: `services/ingestion-gateway/main.go`
* **Acceptance criteria**:
  - Set the HTTP client timeout specifically used for Redpanda/Kafka publishes to 1-2 seconds instead of the default 15 seconds.
  - Verify that a slow Redpanda REST proxy does not block the ingest handler for an excessive duration.

---

### Proposed Task: [INFRA-1] Bind docker-compose datastore ports to localhost only
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-infra-1-datastore-ports-bound-to-0000-in-docker-composeyml) (Finding INFRA-1)
* **Approved scope**: `docker-compose.yml`
* **Acceptance criteria**:
  - Update port bindings for PostgreSQL (`5432`), ClickHouse (`8123`, `9000`), OpenSearch (`9200`, `9600`), and Qdrant (`6333`, `6334`) to bind explicitly to `127.0.0.1` (e.g. `127.0.0.1:5432:5432`).
  - Verify container configurations with `docker compose config --quiet`.

---

### Proposed Task: [INFRA-2] Extract docker-compose secrets to environment variables
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-infra-2-plaintext-hardcoded-secrets-in-docker-composeyml) (Finding INFRA-2)
* **Approved scope**: `docker-compose.yml`, `.env.example`
* **Acceptance criteria**:
  - Extract hardcoded ClickHouse password, Grafana admin password, and OpenSearch initial admin password into `.env` parameters.
  - Add safe defaults for these variables in `.env.example`.
  - Verify compose files with `docker compose config --quiet`.

