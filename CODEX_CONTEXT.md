# Codex Context

This file is the short source of truth for future coding sessions. Read this before making changes.

## Current Architecture

The platform is a Laravel-based SOC/XDR control plane with extracted polyglot microservices and separated service migration in progress.

Core services:

- Laravel SOC control plane: dashboard, RBAC, incidents, workflow, reports, audit, configuration.
- Go ingestion gateway: signed telemetry ingestion, rate limiting, backpressure admission, publishes `telemetry.raw`.
- Go normalizer worker: consumes `telemetry.raw`, normalizes XDR schema, publishes `telemetry.normalized`.
- Go correlation worker: consumes `telemetry.normalized`, runs identity/cloud/SaaS correlation, publishes `xdr.alerts`, currently staged/shadow capable.
- Python alert writer service: consumes `xdr.alerts`, writes alerts to PostgreSQL/OpenSearch, publishes `alerts.created`.
- Python incident builder service: consumes `alerts.created`, builds/updates incidents, publishes `incidents.updated`.
- Python AI/RAG service: standalone analyst-assist service with heuristic fallback.
- Redpanda/Kafka-compatible stream layer.
- PostgreSQL for SOC state, incidents, alerts, RBAC, workflow, event store.
- ClickHouse/OpenSearch/Qdrant are present for distributed XDR storage/search/vector capabilities.

Event-driven target flow:

```text
telemetry.raw
  -> normalizer-worker
  -> telemetry.normalized
  -> correlation-worker
  -> xdr.alerts
  -> alert-writer-service
  -> alerts.created
  -> incident-builder-service
  -> incidents.updated
```

Alert/incident event-driven flow:

```text
xdr.alerts
  -> alert-writer-service
  -> alerts.created
  -> incident-builder-service
  -> incidents.updated
```

Versioned event contracts exist under:

```text
docs/contracts/events/
```

Replayable operational event store:

```text
xdr_operational_events
```

Stored replayable events:

- `alert.created`
- `incident.updated`
- `ai.analysis.completed`

## Migration State

Current migration posture:

- Laravel remains the SOC control plane and source of truth for workflow/RBAC/dashboard.
- Identity/cloud/SaaS Go correlation has staged cutover support.
- Default correlation engine must remain `shadow` unless a 6h or 24h soak passes.
- Endpoint/DNS/proxy correlation is shadow-only. No active cutover for these domains yet.
- Alert/incident flow has event contracts and event-store support.
- Alert writer and incident builder still accept legacy unwrapped payloads for compatibility.
- New service output should use the v1 event envelope.

Important config:

```env
XDR_CORRELATION_ENGINE=shadow
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
XDR_CORRELATION_HEALTH_TIMEOUT_SECONDS=5
XDR_CORRELATION_HEALTH_RETRIES=2
XDR_CORRELATION_HEALTH_RETRY_SLEEP_MS=150
```

Go correlation health now uses retry + circuit breaker:

- 1 transient failure: no fallback
- 2 consecutive failures: no fallback
- 3 consecutive failures: fallback to legacy

## Rules And Gates

Do not claim Go correlation is production/default until soak passes.

Required identity/cloud/SaaS cutover gates:

- `fallback_count = 0`
- `failure_count = 0`
- `p95_latency_ms < 300`
- `goroutine_growth = 0`
- memory growth stable
- no sustained latency drift
- alert type match `>= 0.95`
- evidence match `>= 0.98`
- alert count delta `<= 1-2%`
- duplicate rate `0`

Run 6h soak:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

Analyze:

```powershell
php artisan xdr:soak-analyze --report=reports/xdr_correlation_soak_6h.json --json
python scripts\xdr_soak_fallback_debug.py --input reports\xdr_correlation_soak_6h.json --output reports\xdr_correlation_soak_fallback_debug.json
```

Event contract validation:

```powershell
python scripts\xdr_contract_validate.py --output reports\xdr_contract_validation.json
```

Event-flow resilience validation:

```powershell
python scripts\xdr_event_flow_resilience_validate.py --replays 3 --restart-services 0 --send-malformed 1 --output reports\xdr_event_flow_resilience_validation.json
```

Endpoint/DNS/proxy shadow-only prep:

```powershell
python scripts\xdr_endpoint_dns_proxy_shadow_prep.py --output reports\xdr_endpoint_dns_proxy_shadow_prep.json
```

Standard verification:

```powershell
php artisan test
docker compose config --quiet
```

Note: do not run multiple `php artisan test` processes in parallel against the same PostgreSQL test database. `RefreshDatabase` can conflict during schema drop/create.

## Forbidden Changes

Do not:

- Set `XDR_CORRELATION_ENGINE=go` as permanent default before a clean 6h or 24h soak PASS.
- Expand Go active cutover beyond identity/cloud/SaaS before endpoint/DNS/proxy has golden parity, large replay parity, latency gate, duplicate gate, and rollback validation.
- Remove legacy compatibility paths from alert writer or incident builder yet.
- Remove Laravel as SOC control plane.
- Convert endpoint/DNS/proxy from shadow to active without explicit user instruction and gate evidence.
- Add advanced C2, persistence, stealth, privilege escalation, lateral movement, or offensive automation features.
- Make destructive database resets unless explicitly requested.
- Revert user or prior workspace changes.
- Treat a short smoke soak as production confidence.
- Ignore failed fallback/latency/memory/goroutine gates.

Operational rule:

If a gate fails, keep `shadow` or rollback to `legacy`. Do not move forward with cutover.
