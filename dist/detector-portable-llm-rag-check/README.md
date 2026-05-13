# Portable Detector Kit

This folder documents how to extract the detector from this repo and deploy it beside another Laravel application without exposing that application's source code.

## Integration Model

Use this split:

1. Client Laravel app emits security events as JSONL.
2. A file shipper or producer streams JSONL events to Redpanda/Kafka.
3. The detector engine consumes Kafka events, applies rules + supervised ML, and writes alerts/responses to the detector database.
4. Grafana or the Laravel dashboard reads detector-owned storage only.

The client does not need to provide full source code. It only needs to install a small event logger/adapter and share the generated event stream.

## What To Export

Run from repo root:

```powershell
python scripts/export_portable_detector.py --output dist/detector-portable
```

The exported package contains:

- `engine/scripts`: detector runtime scripts.
- `storage/app`: trained model, thresholds, allowlist, model card.
- `storage/app/anomaly_profile.json`: behavioral baseline for unknown/anomaly detection.
- `infra`: Redpanda, ClickHouse, and Grafana compose files.
- `database/migrations`: detector-owned PostgreSQL tables when using Laravel migrations.
- `adapters/laravel`: minimal Laravel files to copy into a new Laravel app.
- `docs`: event contract and deployment runbook.

## Minimum Client Contract

Every event must be one JSON object per line and must satisfy schema v1:

```json
{
  "schema_version": 1,
  "ts": "2026-05-09T02:30:00+07:00",
  "event": "http_request",
  "request_id": "2f15fca8-571f-4f2d-a0bc-7fd7e8570f84",
  "ip": "198.51.100.77",
  "user_agent_hash": "64-char-lowercase-hex",
  "user_id": null,
  "email_hash": null,
  "method": "GET",
  "path": "/search",
  "status": 200,
  "latency_ms": 24,
  "query_hash": "64-char-lowercase-hex-or-null",
  "has_sql_keywords": false,
  "has_script_payload": false
}
```

Sensitive values such as email, user-agent, and query string should be hashed in the client app before leaving the client environment.

## Runtime Commands

Install Python dependencies:

```powershell
python -m pip install -r engine/scripts/requirements-ingest.txt
```

Start infrastructure:

```powershell
docker compose -f infra/redpanda/docker-compose.redpanda.yml up -d
docker compose -f infra/analytics/docker-compose.analytics.yml up -d
```

Stream events from a client JSONL file:

```powershell
python engine/scripts/stream_producer_kafka.py --file C:\client-app\storage\logs\security.jsonl
```

Run realtime detector:

```powershell
python engine/scripts/realtime_detector_kafka_consumer.py --use-active-deployment=0 --require-lock=0 --response-mode=recommend
```

The runtime now uses three detection paths when artifacts are present: rules, supervised ML, and anomaly behavior scoring.

If no Laravel `.env` exists in the detector package, pass the Postgres DSN explicitly:

```powershell
python engine/scripts/realtime_detector_kafka_consumer.py --dsn "host=127.0.0.1 port=5432 dbname=detector user=postgres password=postgres" --use-active-deployment=0 --require-lock=0 --response-mode=recommend
```

Validate a client event file:

```powershell
python engine/scripts/security_event_contract.py --file C:\client-app\storage\logs\security.jsonl
```

Retrain anomaly baseline after collecting real normal traffic:

```powershell
python engine/scripts/train_anomaly_profile.py --input storage/app/security_dataset.csv
```

## Recommended Production Shape

For a client that does not want to disclose source code:

- Install only the adapter files in the client Laravel app.
- Give the detector operator read-only access to `storage/logs/security.jsonl`, or forward it through Fluent Bit/Filebeat.
- Keep the detector database, model registry, Redpanda, ClickHouse, and Grafana outside the client app.
- Use `RESPONSE_MODE=recommend` first. Switch to `auto` only after the response policy has been reviewed.
