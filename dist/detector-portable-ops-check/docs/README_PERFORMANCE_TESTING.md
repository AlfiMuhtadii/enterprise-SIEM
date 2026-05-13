# Performance and Load Testing

Use `scripts/load_test_soc.py` for lightweight operational performance validation.

## Public Health Load Test

```powershell
python scripts/load_test_soc.py --base-url http://127.0.0.1:8000 --duration 30 --concurrency 8
```

Output:

```text
reports/performance/soc_load_report.json
```

The report includes:

- throughput requests per second
- average latency
- p50, p95, p99 latency
- status distribution
- endpoint-level latency

## Authenticated SOC API Load Test

Pass an authenticated Laravel session cookie:

```powershell
python scripts/load_test_soc.py --base-url http://127.0.0.1:8000 --include-auth --cookie "laravel_session=..."
```

This measures:

- SOC dashboard response time
- SOC API latency
- operational metrics response time
- incident and alert list response time

## Telemetry File Validation Throughput

```powershell
python scripts/load_test_soc.py --telemetry-jsonl storage/app/telemetry_sample.jsonl --duration 10
```

This measures schema validation throughput for telemetry JSONL before replay/import.

## Queue and Ingestion Lag

When `--cookie` is provided, the script captures `/soc/api/metrics` before and after the load test. Use this to compare:

- ingestion lag
- failed jobs
- notification delivery metrics
- open and overdue incident counts

