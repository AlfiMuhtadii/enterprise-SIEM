# Phase 6: Real-time-ish Scoring Pipeline

This phase adds a Kafka-compatible stream (Redpanda) and near-real-time alerting.

## Architecture

- `security.jsonl` (app telemetry)
- Fluent Bit tail -> topic `security_events` (Redpanda)
- Python detector consumer group (`rules + ML`) -> `security_alerts` (Postgres)

## 1) Run Redpanda locally

```bash
docker compose up -d redpanda redpanda-console
```

Kafka broker for local clients: `127.0.0.1:19092`

Console UI: `http://127.0.0.1:8080`

Create topic once:

```bash
docker compose exec -T redpanda rpk topic create security_events -p 6 -r 1
```

## 2) Prepare DB

```bash
php artisan migrate
```

Creates table `security_alerts`.

## 3) Stream events to topic

### Option A (enterprise story): Fluent Bit

Run Fluent Bit with mounted configs:

- `infra/fluent-bit/fluent-bit.conf`
- `infra/fluent-bit/parsers.conf`

Tail source must map `storage/logs/security.jsonl` to `/logs/security.jsonl`.

### Option B (fallback): Python producer

```bash
pip install -r scripts/requirements-ingest.txt
python scripts/stream_producer_jsonl.py --from-start --rest-url http://127.0.0.1:8082 --topic security_events
```

## 4) Start realtime detector consumer

```bash
python scripts/realtime_detector_consumer.py \
  --rest-url http://127.0.0.1:8082 \
  --topic security_events \
  --group-id detector-realtime-v1 \
  --model storage/app/ai_detector_model.pkl
```

**ENT-DETECT-ML-NOT-LIVE:** `--output-mode` defaults to `shadow` — the consumer
writes advisory findings into `advisory_findings` (domain `web_request`) only,
never `security_alerts`/`security_responses`, and never auto-promotes. This
matches the platform's existing shadow-alert-consumer boundary (see
`services/alert-writer-service/main.py`'s `shadow_event_loop`). Pass
`--output-mode active` (or set `DETECTOR_OUTPUT_MODE=active`) only after a
domain-specific 6h soak PASS for `web_request`, per CLAUDE.md — that mode
preserves the original direct-to-`security_alerts` behavior byte-for-byte.

Also runnable as a Compose service, deliberately in its own opt-in profile
(not part of the default `strangler`/`app` bring-up):

```bash
docker compose --profile ml-shadow up -d ml-shadow-detector
```

## 5) Scale out

Run multiple consumer instances with the same `--group-id`.
Kafka will rebalance partitions across consumers.

## 6) Validate near-real-time alerts

Generate traffic:

```bash
php artisan sim:scenario --base-url=http://127.0.0.1:8000 --rounds=1 --profile=fast
```

Check latest alerts:

```sql
select detected_at, alert_type, severity, ip, score
from security_alerts
order by detected_at desc
limit 50;
```
