# Distributed XDR Architecture Upgrade

This layer separates the XDR-like SOC platform into operational service boundaries while keeping the current Laravel control plane intact.

## Service Boundaries

| Service | Responsibility | Produces | Consumes |
|---|---|---|---|
| ingestion-gateway | Accept raw telemetry and publish raw events | `telemetry.raw` | none |
| telemetry-normalizer | Normalize source logs into canonical XDR events | `telemetry.normalized` | `telemetry.raw` |
| xdr-correlation | Correlate cross-domain telemetry and create alerts/incidents | `xdr.alerts`, `incidents.updated` | `telemetry.normalized` |
| ai-rag | Generate guarded AI analysis and knowledge retrieval | `ai.analysis.results` | `ai.analysis.requests`, `incidents.updated` |
| soc-control-plane | Laravel UI/API, RBAC, workflow, reports, audit | `ai.analysis.requests`, `incidents.updated` | `xdr.alerts`, `ai.analysis.results` |

Health endpoints:

```text
/health/services/ingestion-gateway
/health/services/telemetry-normalizer
/health/services/xdr-correlation
/health/services/ai-rag
/health/services/soc-control-plane
```

## Streaming Backbone

Configured topics:

- `telemetry.raw`
- `telemetry.normalized`
- `xdr.alerts`
- `incidents.updated`
- `ai.analysis.requests`
- `ai.analysis.results`

Local deterministic stream abstraction:

```bash
python scripts/xdr_stream_bus.py topics
python scripts/xdr_stream_bus.py produce --topic telemetry.raw --file storage/logs/xdr_m365_sample.jsonl
python scripts/xdr_stream_bus.py lag --topic telemetry.raw --consumer-group telemetry-normalizer
```

Redpanda mode:

```bash
python scripts/xdr_stream_bus.py produce --backend redpanda --redpanda-rest http://127.0.0.1:8082 --topic telemetry.raw --file storage/logs/xdr_m365_sample.jsonl
```

Operational metrics:

```bash
php artisan xdr:stream-metrics
php artisan xdr:stream-metrics --replay=1
```

## Specialized Storage

Configured storage responsibilities:

- ClickHouse: raw telemetry and long-running analytical scans.
- PostgreSQL: incidents, workflow, RBAC, audit, and control-plane state.
- OpenSearch: searchable telemetry and fast analyst queries.
- Qdrant: RAG/vector retrieval for knowledge base and incident context.

Validate storage configuration:

```bash
php artisan xdr:storage-validate
```

## XDR Validation Realism

Generate mixed normal + malicious validation metrics:

```bash
php artisan xdr:validate-realism --normal=10000 --malicious=800 --replay-seconds=300
```

The report tracks:

- FP/FN by telemetry domain
- latency measurements
- correlation accuracy
- replay stability
- ingestion throughput
- correlation throughput

## Operational Visibility

The SOC dashboard now surfaces:

- service health
- topic count
- consumer lag
- DLQ count
- stream processing latency
- storage status
- latest XDR validation quality metrics

Operational metrics API:

```text
/soc/api/metrics
```

The response includes `xdr_distributed` with service, stream, storage, and validation summaries.
