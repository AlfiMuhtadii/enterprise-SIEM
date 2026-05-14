# Operational Dashboards

Grafana dashboard guide for the polyglot XDR pipeline. All metrics are sourced from JSON `/metrics` endpoints exposed by each service.

---

## Metric Sources

| Service | Endpoint | Key metrics |
|---|---|---|
| ingestion-gateway | http://ingestion-gateway:8091/metrics | requests, accepted, rejected, publish_errors, retry_count |
| normalizer-worker | http://normalizer-worker:8092/metrics | processed, forwarded, malformed, publish_errors, consumer_errors, reconnect_count, poll_error_count, consumer_recreate_count, queue_depth, goroutines, heap_alloc_mb |
| correlation-worker | http://correlation-worker:8093/metrics | processed, alerts, published, last_latency_ms, publish_errors, consumer_errors, reconnect_count, poll_error_count, consumer_recreate_count, retry_count, goroutines, heap_alloc_mb |
| Redpanda | Redpanda Console + HTTP API | consumer lag per group, topic throughput, partition health |

Note: Metrics endpoints return JSON, not Prometheus format. Use Grafana's Infinity data source plugin or a custom JSON exporter to scrape them.

---

## Dashboard 1: Pipeline Throughput

**Purpose:** Confirm end-to-end event flow is healthy.

| Panel | Metric | Source |
|---|---|---|
| Ingest rate | `accepted` per second | ingestion-gateway |
| Normalize rate | `forwarded` per second | normalizer-worker |
| Alert publish rate | `published` per second | correlation-worker |
| Total alerts | `alerts` cumulative | correlation-worker |
| Malformed events | `malformed` per second | normalizer-worker |

Alert thresholds:
- `accepted` = 0 for > 60s → pipeline stalled
- `forwarded` < 80% of `accepted` for > 2 min → normalization bottleneck
- `malformed` rate > 1% of `processed` → data quality issue

---

## Dashboard 2: Consumer Lag

**Purpose:** Detect backlog buildup in Redpanda consumer groups.

| Panel | Consumer Group | Topic |
|---|---|---|
| Normalizer lag | normalizer-worker-v1 | telemetry.raw |
| Correlation lag | correlation-worker-v1 | telemetry.normalized |
| Alert writer lag | alert-writer-v1 | xdr.alerts |
| Incident builder lag | incident-builder-v1 | alerts.created |

Alert thresholds:
- Consumer lag > 50,000 messages → backpressure building
- Consumer lag > 200,000 messages → pipeline stalled, ops required
- Consumer lag growing monotonically for > 10 min → consumer not processing

---

## Dashboard 3: Reconnect Spikes

**Purpose:** Detect Redpanda connection instability in Go consumers.

| Panel | Metric | Source |
|---|---|---|
| Normalizer reconnects | `reconnect_count` rate | normalizer-worker |
| Correlation reconnects | `reconnect_count` rate | correlation-worker |
| Normalizer poll errors | `poll_error_count` rate | normalizer-worker |
| Correlation poll errors | `poll_error_count` rate | correlation-worker |
| Consumer recreates | `consumer_recreate_count` rate | both |

Alert thresholds:
- `reconnect_count` increases > 5 in any 5-minute window → Redpanda instability
- `poll_error_count` > 0 sustained → investigate consumer health
- `consumer_recreate_count` growing without stabilizing → reconnect loop not settling

---

## Dashboard 4: Fallback Activity

**Purpose:** Monitor circuit breaker and legacy fallback behavior.

| Panel | Source |
|---|---|
| Fallback events per interval | Laravel cutover status metrics |
| Circuit breaker trips | Laravel cutover status metrics |
| Correlation publish errors | correlation-worker `/metrics` |
| Correlation engine mode | `XDR_CORRELATION_ENGINE` config |

Alert thresholds:
- Any fallback event → requires investigation before next soak can pass
- `publish_errors` rate > 0 sustained → alert pipeline may be blocked
- Circuit breaker trips → investigate correlation-worker health

---

## Dashboard 5: Alert Throughput

**Purpose:** Confirm alerts are generated and flowing to persistence.

| Panel | Metric | Source |
|---|---|---|
| Alerts generated rate | `alerts` per second | correlation-worker |
| Alerts published rate | `published` per second | correlation-worker |
| Alert write rate | `processed` per second | alert-writer-service |
| xdr.alerts throughput | messages/sec | Redpanda Console |

Alert thresholds:
- `published` < `alerts` sustained → DLQ activity (publish failures)
- Gap between `correlation-worker.published` and `alert-writer.processed` growing → backlog in xdr.alerts

---

## Dashboard 6: Incident Throughput

**Purpose:** Confirm incidents are built and delivered to the SOC dashboard.

| Panel | Source |
|---|---|
| Incidents created per interval | incident-builder-service metrics |
| incidents.updated throughput | Redpanda Console |
| DLQ spike indicator | incidents.builder.dlq message count |

---

## Dashboard 7: Memory and Goroutine Health

Add to all service-specific dashboards.

| Panel | Metric | Expected behavior |
|---|---|---|
| Goroutine count | `goroutines` | Flat line; growth = potential leak |
| Heap allocation | `heap_alloc_mb` | Stable or declining; sustained growth = pressure |
| Normalizer queue | `queue_depth` | Should stabilize; sustained growth = consumer falling behind |

Alert thresholds:
- `goroutines` grows > 10% over 30 minutes → goroutine leak
- `heap_alloc_mb` grows > 128 MB over 1 hour without returning → memory pressure
- `queue_depth` > 80% of `queue_capacity` sustained → backpressure risk

---

## Laravel / Python Services

For Python services and Laravel, expose:
- `/health` — process alive
- `/ready` — ready to process
- `/metrics` (JSON) with:
  - `processing_latency` (ms, p50/p95)
  - `request_failures` count
  - `dlq_count` cumulative
  - `retry_count` cumulative

Laravel metrics to expose for cutover monitoring:
- `cutover_engine` (current engine mode)
- `fallback_count` (cumulative fallback events)
- `circuit_breaker_trips` (cumulative trips)
- `validation_status` (last known soak status)

---

## Grafana JSON Data Source Setup

Since services expose JSON (not Prometheus), use the Grafana Infinity plugin:

```yaml
# Example Infinity data source config
url: http://correlation-worker:8093/metrics
method: GET
format: json
```

Map each JSON key to a Grafana field using the field picker. Use `Stat` panels for counters and `Time series` panels for rate calculations (use Grafana's transform: "Add field from calculation" → "Difference").

For Redpanda consumer lag, use the Redpanda Console REST API or configure a Redpanda metrics exporter.
