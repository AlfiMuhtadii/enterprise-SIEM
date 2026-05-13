# Real Distributed XDR Infrastructure Integration

This layer connects the XDR architecture to real runtime infrastructure:

- Redpanda/Kafka-compatible streaming
- ClickHouse analytical telemetry storage
- OpenSearch searchable telemetry and alert storage
- Qdrant vector retrieval for SOC knowledge, incidents, IOCs, and analyst notes

## Start External Infrastructure

```bash
docker compose --profile strangler up -d
```

Default endpoints:

- Redpanda PandaProxy: `http://127.0.0.1:8082`
- ClickHouse HTTP: `http://127.0.0.1:8123`
- OpenSearch: `http://127.0.0.1:9200`
- Qdrant: `http://127.0.0.1:6333`

## Environment Variables

```env
XDR_REDPANDA_REST_URL=http://127.0.0.1:8082
XDR_CLICKHOUSE_HTTP_URL=http://127.0.0.1:8123
XDR_CLICKHOUSE_DB=detector_analytics
XDR_CLICKHOUSE_USER=detector
XDR_CLICKHOUSE_PASSWORD=detector
XDR_OPENSEARCH_URL=http://127.0.0.1:9200
XDR_OPENSEARCH_USER=
XDR_OPENSEARCH_PASSWORD=
XDR_QDRANT_URL=http://127.0.0.1:6333
XDR_QDRANT_COLLECTION=soc_knowledge
XDR_QDRANT_VECTOR_SIZE=384
```

## Setup Runtime Schemas

```bash
python scripts/xdr_setup_infra.py --output reports/xdr_infra_setup.json
```

This creates:

- ClickHouse `raw_telemetry`
- ClickHouse `normalized_telemetry`
- ClickHouse `xdr_pipeline_metrics`
- OpenSearch `xdr-*` index template
- Qdrant SOC knowledge collection

## Streaming Pipeline

Normalize sample telemetry:

```bash
python scripts/telemetry_adapters.py --adapter m365-audit --input samples/real-world/xdr/m365_audit_email_identity_chain.jsonl --output storage/logs/xdr_m365_sample.jsonl
```

Produce into Redpanda:

```bash
python scripts/xdr_stream_bus.py produce --backend redpanda --topic telemetry.raw --file storage/logs/xdr_m365_sample.jsonl
```

Local deterministic mode:

```bash
python scripts/xdr_stream_bus.py produce --topic telemetry.raw --file storage/logs/xdr_m365_sample.jsonl
python scripts/xdr_stream_bus.py lag --topic telemetry.raw --consumer-group telemetry-normalizer
```

## End-to-End Distributed Validation

```bash
python scripts/xdr_distributed_validate.py --input storage/logs/xdr_m365_sample.jsonl --output reports/xdr_distributed_validation.json
```

The validation flow checks:

- Redpanda health
- ClickHouse health and insert path
- OpenSearch health and indexing path
- Qdrant health, upsert, and retrieval path
- telemetry.raw production
- telemetry.normalized production
- throughput
- storage success
- replay stability
- dashboard visibility references

## Laravel Operational Metrics

```bash
php artisan xdr:storage-validate
php artisan xdr:stream-metrics
php artisan xdr:validate-realism --normal=10000 --malicious=800 --replay-seconds=300
```

Dashboard visibility:

- `/soc`: XDR distributed visibility section
- `/soc/api/metrics`: `xdr_distributed` metrics payload

## Failure Behavior

The integration uses timeout and retry handling. If an external service is unavailable:

- health status becomes `degraded` or `failed`
- DLQ JSONL files are written under `storage/streams`
- validation reports still generate
- Laravel control-plane remains usable
