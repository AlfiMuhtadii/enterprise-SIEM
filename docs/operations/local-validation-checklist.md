# Local Validation Checklist

Step-by-step validation checklist for changes to the polyglot pipeline. Run the relevant steps for the type of change being made.

---

## When to Run Each Validation

| Change type | Required validations |
|---|---|
| Docker Compose or infra config | Docker validation |
| Laravel / PHP changes | Laravel tests |
| Event contract changes | Contract validation + replay validation |
| Go service changes | Resilience validation + mini soak |
| Python service changes | Resilience validation + mini soak |
| Correlation logic changes | Mini soak + 6h soak before cutover |
| Any change before staged cutover | All steps below |

---

## Validation Commands

### 1. Docker Compose Validation

```powershell
docker compose config --quiet
```

**Pass:** exit code 0, no output.
**Fail:** any output or non-zero exit code — fix before proceeding.

---

### 2. Laravel Tests

```powershell
php artisan test
```

**Pass:** all tests green, zero failures.
**Fail:** any failure — fix before proceeding.

**Note:** Do not run multiple `php artisan test` processes against the same PostgreSQL test database. `RefreshDatabase` conflicts during schema drop/create.

---

### 3. Event Contract Validation

```powershell
python scripts\xdr_contract_validate.py --output reports\xdr_contract_validation.json
```

**Pass:** all contracts valid, report shows no violations.
**Fail:** any contract violation — fix and re-run before proceeding.

---

### 4. Replay Validation

```powershell
python scripts\xdr_event_flow_resilience_validate.py `
    --replays 3 `
    --restart-services 0 `
    --send-malformed 1 `
    --output reports\xdr_event_flow_resilience_validation.json
```

**Pass:**
- Replay results consistent across 3 replays
- Malformed events rejected, no consumer crash
- No goroutine growth after replay

**Fail:** any inconsistency, consumer crash, or goroutine leak — stop and investigate.

---

### 5. Resilience Validation (with restart)

```powershell
python scripts\xdr_event_flow_resilience_validate.py `
    --replays 3 `
    --restart-services 1 `
    --send-malformed 1 `
    --output reports\xdr_event_flow_resilience_validation.json
```

**Pass:**
- Consumer reconnects and resumes after restart
- No events lost after reconnect
- No goroutine growth

**Fail:** any reconnect failure, event loss, or goroutine leak — stop and investigate.

---

### 6. Mini Soak (5 minutes)

```powershell
python scripts\xdr_correlation_soak.py `
    --duration-minutes 5 `
    --batch-size 5000 `
    --sleep-ms 100 `
    --output reports\xdr_correlation_mini_soak.json
```

**Pass (all must be true):**
- `fallback_count = 0`
- `failure_count = 0`
- `status_failures = 0`
- `p95_latency_ms < 300 ms`
- `goroutine_growth = 0`
- Memory stable (no sustained growth)

**Note:** Mini soak PASS is a smoke check. It does NOT authorize staged cutover.

---

### 7. Shadow Prep Validation (endpoint/DNS/proxy only)

```powershell
python scripts\xdr_endpoint_dns_proxy_shadow_prep.py `
    --output reports\xdr_endpoint_dns_proxy_shadow_prep.json
```

Run only when preparing shadow-only domains. Not required for identity-cloud changes.

---

### 8. Full 6h Soak (pre-cutover only)

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

**Required gates (all must pass):**

| Gate | Threshold |
|---|---|
| `fallback_count` | = 0 |
| `failure_count` | = 0 |
| `status_failures` | = 0 |
| `p95_latency_ms` | < 300 ms |
| `worker_p95_latency_ms` | < 300 ms |
| `memory_growth_mb` | stable, no sustained growth |
| `goroutine_growth` | = 0 |
| `latency_drift` | none sustained |

If any gate fails: remain shadow or rollback. Never force promotion.

---

## Post-Soak Analysis

```powershell
php artisan xdr:soak-analyze --report=reports/xdr_correlation_soak_6h.json --json

python scripts\xdr_soak_fallback_debug.py `
    --input reports\xdr_correlation_soak_6h.json `
    --output reports\xdr_correlation_soak_fallback_debug.json
```

---

## STOP Conditions

Stop and do not proceed if:
- Docker compose config fails
- Any Laravel test fails
- Any contract is invalid
- Any replay result is inconsistent
- Any resilience validation fails
- Any gate metric is out of range during mini or full soak
- Fallback events observed during soak
- Goroutine or memory growth detected

When stopped: remain shadow or rollback to legacy. Fix the root cause, re-run validation from the beginning.

---

## Metrics to Check After Any Go Service Change

```powershell
curl http://127.0.0.1:8091/metrics   # ingestion-gateway
curl http://127.0.0.1:8092/metrics   # normalizer-worker
curl http://127.0.0.1:8093/metrics   # correlation-worker
```

Check for:
- `consumer_errors` not increasing
- `reconnect_count` flat (no new reconnects)
- `poll_error_count` = 0
- `publish_errors` = 0
- `goroutines` stable
- `heap_alloc_mb` stable
