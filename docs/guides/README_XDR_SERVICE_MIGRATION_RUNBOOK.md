# XDR Service Migration Runbook

This migration does not change the pending identity/cloud/SaaS soak gate and does not expand cutover to endpoint/DNS/proxy.

## Services

- `alert-writer-service` consumes `xdr.alerts`, writes alerts to PostgreSQL/OpenSearch, then publishes `alerts.created`.
- `incident-builder-service` consumes `alerts.created`, builds grouped incidents, writes incident updates to PostgreSQL, then publishes `incidents.updated`.
- `ai-rag-service` provides defensive incident analysis with Laravel fallback to local heuristics.
- endpoint/DNS/proxy work is shadow-prep only: golden dataset, parity/diff report, latency benchmark, no active mode.

Target event flow:

```text
xdr.alerts
  -> alert-writer-service
  -> alerts.created
  -> incident-builder-service
  -> incidents.updated
```

Laravel remains the SOC control plane. It should not be the synchronous writer for this XDR alert/incident event path.

## Run Services

```powershell
python scripts\xdr_setup_infra.py --output reports\xdr_infra_setup_event_driven.json
docker compose --profile strangler up -d --build alert-writer-service incident-builder-service ai-rag-service
php artisan xdr:strangler-status
```

The setup command creates required Redpanda topics, including `alerts.created` and DLQ topics.

## Alert Writer API

```powershell
Invoke-RestMethod http://127.0.0.1:8095/health
Invoke-RestMethod http://127.0.0.1:8095/metrics
```

Compatibility endpoint:

```text
POST http://127.0.0.1:8095/v1/write
```

Payload:

```json
{
  "trace_id": "trace-demo",
  "source_topic": "xdr.alerts",
  "alerts": [
    {"alert_type":"IDENTITY_RISKY_IP_LOGIN","severity":"high","actor_key":"user@example.com","score":0.8,"evidence":{"evidence_ids":["evt-1"]}}
  ]
}
```

Metrics include write latency, failures, DLQ count, duplicate count, and idempotency cache size.

Primary runtime input is the `xdr.alerts` topic, not this HTTP endpoint.

## Incident Builder API

```powershell
Invoke-RestMethod http://127.0.0.1:8096/health
Invoke-RestMethod http://127.0.0.1:8096/metrics
```

Compatibility endpoint:

```text
POST http://127.0.0.1:8096/v1/build
```

The builder groups alerts by alert family and primary entity, aggregates severity/confidence, links related alerts, builds affected entities, evidence timeline, and MITRE mapping.

Primary runtime input is the `alerts.created` topic, not this HTTP endpoint.

## Event-Driven Smoke Test

```powershell
python scripts\xdr_stream_bus.py topics
Invoke-RestMethod http://127.0.0.1:8095/metrics
Invoke-RestMethod http://127.0.0.1:8096/metrics
docker exec detector-redpanda rpk topic consume incidents.updated -n 1
```

Expected result:

- alert-writer `alerts_seen` and `events_published` increase
- incident-builder `alerts_seen`, `incident_updates`, and `events_published` increase
- both DLQ endpoints return `count: 0`

## AI/RAG Runtime Integration

Default remains local heuristic. To route Laravel through FastAPI AI/RAG service:

```env
SOC_AI_SERVICE_ENABLED=true
XDR_AI_RAG_SERVICE_URL=http://127.0.0.1:8094
```

Fallback behavior: if `/health` or `/v1/analyze` fails or times out, Laravel uses `LocalAiAnalystProvider` and records provider fallback in AI execution history.

## Endpoint/DNS/Proxy Shadow Prep

Generate golden dataset:

```powershell
python scripts\xdr_generate_endpoint_dns_proxy_golden.py
```

Run shadow-only benchmark:

```powershell
python scripts\xdr_endpoint_dns_proxy_shadow_prep.py
```

Report:

```text
reports/xdr_endpoint_dns_proxy_shadow_prep.json
```

This does not permit cutover. Required before any future cutover:

- legacy parity comparison
- large replay parity
- latency gate <300ms
- duplicate rate 0
- rollback validation

## Dashboard / API Visibility

SOC dashboard `/soc` shows `Separated Service Migration` with:

- alert writer health, write latency, DLQ, dedup count
- incident builder health, latency, DLQ, built count
- AI/RAG service health and request counters
- endpoint/DNS/proxy shadow parity status

Metrics API includes:

```text
xdr_distributed.separated_services
```

## Safety Guardrails

- Do not change `XDR_CORRELATION_ENGINE` because the identity/cloud/SaaS soak gate is still pending.
- Do not activate endpoint/DNS/proxy correlation.
- Keep endpoint/DNS/proxy reports as shadow-prep only.
