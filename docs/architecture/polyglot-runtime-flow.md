# Polyglot Runtime Flow

Describes the runtime event flow, error paths, consumer group topology, and reconnect behavior of the polyglot microservices pipeline.

---

## Normal Flow

```
[Telemetry Source]
    │  HTTP POST /v1/ingest (HMAC-SHA256 signed batch)
    ▼
[ingestion-gateway :8091]
    │  validates signature, rate-limits, checks normalizer queue depth
    │  publishes to Redpanda
    ▼
[Redpanda: telemetry.raw]
    │  consumed by: normalizer-worker-v1
    ▼
[normalizer-worker :8092]
    │  normalizes schema, validates required fields
    │  malformed → telemetry.normalized.dlq
    │  publishes batches via 4 parallel producers
    ▼
[Redpanda: telemetry.normalized]
    │  consumed by: correlation-worker-v1
    ▼
[correlation-worker :8093]
    │  identity/cloud/SaaS correlation (scope: identity-cloud)
    │  deterministic alert IDs (SHA-256)
    │  publish errors → xdr.alerts.dlq
    ▼
[Redpanda: xdr.alerts]
    │  consumed by: alert-writer-service
    ▼
[alert-writer-service :8095]
    │  writes PostgreSQL + OpenSearch
    │  persistence failures → xdr.alerts.dlq
    ▼
[Redpanda: alerts.created]
    ├──▶ [incident-builder-service :8096]
    │         builds/updates incidents
    │         failures → incidents.builder.dlq
    │         ▼
    │    [Redpanda: incidents.updated]
    │         ▼
    │    [Laravel SOC :8000]
    │
    └──▶ [Laravel SOC :8000]
              consumes alerts + incidents
              triggers AI enrichment
              ▼
         [Redpanda: ai.analysis.requests]
              ▼
         [ai-rag-service :8094]
              ▼
         [Redpanda: ai.analysis.results]
              ▼
         [Laravel SOC :8000]
```

---

## DLQ and Error Flow

```
telemetry.normalized.dlq  ◀── normalizer-worker (malformed raw events)
xdr.alerts.dlq            ◀── correlation-worker (invalid events, publish failures)
xdr.alerts.dlq            ◀── alert-writer-service (persistence failures)
incidents.builder.dlq     ◀── incident-builder-service (aggregation failures)
```

DLQ events are retained for 7 days. They are not automatically retried. Ops team reviews DLQ spikes and triggers manual replay where appropriate.

---

## Consumer Group Layout

| Topic | Consumer Group | Service | Mode |
|---|---|---|---|
| telemetry.raw | normalizer-worker-v1 | normalizer-worker | active |
| telemetry.normalized | correlation-worker-v1 | correlation-worker | active (identity-cloud) |
| xdr.alerts | alert-writer-v1 | alert-writer-service | active |
| alerts.created | incident-builder-v1 | incident-builder-service | active |
| alerts.created | soc-consumer-v1 | Laravel SOC | active |
| incidents.updated | soc-consumer-v1 | Laravel SOC | active |
| ai.analysis.requests | ai-rag-v1 | ai-rag-service | active |
| ai.analysis.results | soc-ai-consumer-v1 | Laravel SOC | active |

---

## Reconnect Behavior (Go Services)

Both `correlation-worker` and `normalizer-worker` use a reconnect loop for Redpanda consumers:

```
consumeLoop():
  loop forever:
    consumeOnce()           ← consumerRecreateCount++
    reconnectCount++
    sleep 5s
    
consumeOnce():
  consumerCreate()         ← creates new consumer instance in group
  consumerSubscribe()      ← subscribes to input topic
  loop:
    consumerPoll()
      on error:
        pollErrorCount++
        consumerErrors++
        return              ← exits consumeOnce, triggers reconnect
    process records
    publish to output topic
      on error:
        publishErrors++
        retryCount++
        publish to DLQ
```

Reconnect does not involve offset reset. The consumer group retains committed offsets, so processing resumes from where it left off.

---

## Circuit Breaker (Laravel)

Separate from the Go reconnect loop. Managed by Laravel's cutover status logic:

- 1–2 consecutive correlation failures: no fallback
- 3 consecutive failures: fallback to legacy correlation engine

The circuit breaker triggers on HTTP-level failures to the correlation worker. It does not trigger on Redpanda consumer reconnects, which are internal to the Go process.

---

## Ingestion-Gateway Publish Retry

The ingestion-gateway retries Redpanda publish up to 3 attempts with linear backoff (100ms, 200ms, 300ms). Each retry increments `retry_count`. After 3 failures, it returns HTTP 502 to the caller.

---

## Backpressure

```
Telemetry source
    │  rate limited by ingestion-gateway (XDR_INGEST_RPS)
    ▼
ingestion-gateway
    │  checks normalizer queue depth (XDR_NORMALIZER_METRICS_URL)
    │  if queue >= XDR_MAX_NORMALIZER_QUEUE_DEPTH → 429 Too Many Requests
    ▼
normalizer-worker
    │  in-process queue (XDR_NORMALIZER_QUEUE_DEPTH = 200000)
    │  4 producer goroutines flush batches every 100ms or 5000 events
    ▼
Redpanda
```

---

## Health, Ready, Metrics per Service

| Service | /health | /ready | /metrics fields |
|---|---|---|---|
| ingestion-gateway :8091 | ✓ | ✓ | requests, accepted, rejected, publish_errors, retry_count |
| normalizer-worker :8092 | ✓ | ✓ | processed, malformed, forwarded, publish_errors, consumer_polls, consumer_errors, reconnect_count, poll_error_count, consumer_recreate_count, queue_depth, queue_capacity, goroutines, heap_alloc_mb |
| correlation-worker :8093 | ✓ | ✓ | processed, alerts, published, last_latency_ms, publish_errors, consumer_polls, consumer_errors, reconnect_count, poll_error_count, consumer_recreate_count, retry_count, goroutines, heap_alloc_mb |

`/health` does not check downstream dependencies — it returns 200 if the process is alive.
`/ready` returns 200 when the service is ready to accept or process requests.
`/metrics` returns a JSON object; no Prometheus format currently.

---

## Correlation Engine Modes

| `XDR_CORRELATION_ENGINE` | Behavior |
|---|---|
| `shadow` | Go correlation runs, output discarded; legacy path used |
| `go` | Go correlation output used; legacy fallback available via circuit breaker |
| `legacy` | Go correlation bypassed entirely |

Current: `go` (staged_active, identity-cloud scope).

Fallback always available via `XDR_CORRELATION_FALLBACK_TO_LEGACY=true`.
