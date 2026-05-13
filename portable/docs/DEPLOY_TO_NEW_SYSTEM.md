# Deploy Detector To A New System

## Option A: No Client Source Access

Use this when the client does not want to reveal application source code.

1. Give the client the Laravel adapter from `adapters/laravel`.
2. Client installs the adapter and writes `security.jsonl`.
3. Detector operator runs `stream_producer_kafka.py` against the JSONL file or receives the file through a shipper.
4. Detector engine, database, Redpanda, ClickHouse, and Grafana stay outside the client application.

This option is the most realistic for production service delivery because the detector only needs event telemetry.

## Option B: Full Source Integration

Use this when the client allows source-level integration.

1. Install adapter middleware.
2. Add explicit `SecurityLogger::log()` calls at authentication and authorization points.
3. Tune `SECURITY_DETECTOR_CAPTURE_QUERY_PATHS` for search, filter, upload, and API endpoints.
4. Run real traffic and export labeled data for retraining.

This gives better detection because login failures, authorization denials, and application-specific risky endpoints are captured explicitly.

## Detector Runtime

Start Redpanda:

```powershell
docker compose up -d redpanda redpanda-console
```

Run producer:

```powershell
python engine/scripts/stream_producer_kafka.py --file C:\client-app\storage\logs\security.jsonl
```

Run detector:

```powershell
python engine/scripts/realtime_detector_kafka_consumer.py --use-active-deployment=0 --require-lock=0 --response-mode=recommend
```

By default, the detector uses three paths:

- rule-based detection for explicit known patterns
- supervised ML for trained attack classes
- anomaly detection for behavior that deviates from the baseline profile

If the package is not running inside this Laravel repo, pass database connection explicitly:

```powershell
python engine/scripts/realtime_detector_kafka_consumer.py --dsn "host=127.0.0.1 port=5432 dbname=detector user=postgres password=postgres" --use-active-deployment=0 --require-lock=0 --response-mode=recommend
```

Run health checks:

```powershell
python engine/scripts/security_event_contract.py --file C:\client-app\storage\logs\security.jsonl
```

## When To Retrain

Retrain when one of these happens:

- The target app has very different route patterns.
- New attack labels are added.
- False positives are high on normal client traffic.
- Drift report says retrain is required.

Retrain command:

```powershell
python engine/scripts/train_ai_detector.py --input storage/app/security_dataset.csv
python engine/scripts/train_anomaly_profile.py --input storage/app/security_dataset.csv
python engine/scripts/mlops_register_model.py --deploy --env local
```
