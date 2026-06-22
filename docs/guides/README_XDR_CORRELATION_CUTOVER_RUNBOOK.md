# XDR Identity/Cloud/SaaS Cutover Runbook

This runbook is for staged Go correlation cutover only for identity/cloud/SaaS. Endpoint, DNS, proxy, and firewall remain legacy until they pass separate gates.

## 1. Run Soak

Before the soak, make sure the hardened correlation worker is running:

```powershell
docker compose --profile strangler up -d --build correlation-worker
php artisan config:clear
php artisan xdr:correlation-cutover-status --engine=go --scope=identity-cloud --audit=0 --json
```

The status output must show:

```text
fallback_active = false
go_worker.status = healthy
go_worker.failure_threshold = 3
```

6-hour minimum:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

24-hour stronger validation:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_24h.ps1
```

Do not close the terminal while the soak is running.

## 2. Analyze Report

```powershell
php artisan xdr:soak-analyze --report=reports/xdr_correlation_soak_6h.json --json
```

If no report path is provided, the analyzer reads the newest available report in this order:

1. `reports/xdr_correlation_soak_24h.json`
2. `reports/xdr_correlation_soak_6h.json`
3. `reports/xdr_correlation_soak_smoke.json`

## 3. Gate Checklist

Required PASS gates:

- `validation_status = PASS`
- `fallback_count = 0`
- `failure_count = 0`
- `p95_latency_ms < 300`
- `goroutine_growth = 0`
- `memory_growth_mb` remains stable
- `latency_ms_trend.delta <= 0` or no sustained upward drift

Health/fallback rules:

- a single transient health error must not trigger fallback
- fallback is allowed only after `XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD` consecutive health failures
- default threshold is `3`
- health check uses retry and longer timeout before incrementing the failure counter

## 4. Decision Format

### keep_shadow

Use this when:

- no 6-hour/24-hour report exists yet
- only smoke test exists
- soak report duration is below 360 minutes

Configuration:

```env
XDR_CORRELATION_ENGINE=shadow
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
```

### staged_active

Use this only when 6-hour or longer soak passes all gates.

Configuration:

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
```

Then run:

```powershell
php artisan config:clear
php artisan xdr:correlation-cutover-status --json
```

### rollback

Use this when any critical gate fails.

Configuration:

```env
XDR_CORRELATION_ENGINE=legacy
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
```

Then run:

```powershell
php artisan config:clear
php artisan xdr:correlation-cutover-status --json
```

## 5. How To Read The Soak Report

Important fields:

- `validation_status`: overall soak status
- `metrics.fallback_count`: must be `0`
- `metrics.failure_count`: must be `0`
- `metrics.p95_latency_ms`: must be below `300`
- `metrics.memory_growth_mb`: should be stable and below threshold
- `metrics.goroutine_growth`: must be `0`
- `metrics.latency_ms_trend.delta`: should not show sustained upward drift
- `samples`: per-iteration telemetry for latency, heap, goroutines, fallback, health

## 6. Dashboard Monitoring

Open SOC dashboard:

```text
/soc
```

Check the `Go Correlation Soak Gate` panel for:

- p95 latency
- memory growth
- goroutine growth
- fallback count
- failure count
- latency drift
- decision: `keep_shadow`, `staged_active`, or `rollback`

Metrics API:

```powershell
Invoke-RestMethod http://127.0.0.1:8000/soc/api/metrics
```

The response includes:

```json
xdr_distributed.correlation_soak
```

## 7. Risks If Gate Fails

- fallback count > 0: Go worker may be unstable or health checks are flapping
- failure count > 0: correlation requests are failing and active cutover is unsafe
- p95 >= 300ms: latency budget is not stable under sustained load
- goroutine growth > 0: possible goroutine leak
- memory growth keeps rising: possible memory leak or GC pressure problem
- latency drift keeps rising: sustained-load degradation risk

If any of these happen, keep `shadow` or rollback to `legacy` and inspect the report samples before re-testing.
