# Polyglot Dev Workflow

Standard operational workflow for the polyglot XDR microservices platform. Covers local development, validation steps, staged cutover, and rollback.

---

## Service Runtimes

| Service | Runtime | Profile |
|---|---|---|
| Infrastructure | Docker Compose | (default) |
| Laravel SOC | PHP/Laravel + Docker | `app` |
| Go services | Docker Compose | `strangler` |
| Python services | Docker Compose | `strangler` |

---

## Local Bootstrap

```powershell
# 1. Start infrastructure (Redpanda, PostgreSQL, ClickHouse, OpenSearch, Qdrant, Grafana)
docker compose up -d

# 2. Verify compose config
docker compose config --quiet

# 3. Start Laravel SOC control-plane
docker compose --profile app up -d --build

# 4. Start Go + Python strangler services
docker compose --profile strangler up -d --build

# 5. Verify service health
curl http://127.0.0.1:8091/health   # ingestion-gateway
curl http://127.0.0.1:8092/health   # normalizer-worker
curl http://127.0.0.1:8093/health   # correlation-worker
curl http://127.0.0.1:8095/health   # alert-writer-service
curl http://127.0.0.1:8096/health   # incident-builder-service

# 6. Run Laravel tests (single process — do not parallelize)
php artisan test
```

---

## Correlation Engine Mode

Check current mode:
```powershell
php artisan xdr:correlation-cutover-status --engine=go --scope=identity-cloud --audit=0 --json
```

Set mode via environment:
```env
# Shadow (safe default — Go runs but output discarded)
XDR_CORRELATION_ENGINE=shadow

# Staged active (post-6h soak, identity-cloud scope)
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true

# Legacy (bypass Go entirely)
XDR_CORRELATION_ENGINE=legacy
```

**Current mode:** `go` / `identity-cloud` (staged_active, 6h soak PASS 2026-05-14).

---

## Event Contract Validation

Validates all topic event contracts against defined schemas:

```powershell
python scripts\xdr_contract_validate.py --output reports\xdr_contract_validation.json
```

Run: after any change to event producers, event schemas, or contract files.

Pass: all contracts valid, no violations.

---

## Replay Validation

Validates that events replay deterministically and malformed events are rejected correctly:

```powershell
python scripts\xdr_event_flow_resilience_validate.py `
    --replays 3 `
    --restart-services 0 `
    --send-malformed 1 `
    --output reports\xdr_event_flow_resilience_validation.json
```

Pass:
- 3 replay results consistent (idempotent)
- Malformed events rejected, consumers not crashed
- No goroutine leak after replay

---

## Resilience Validation (with restart)

Validates service recovery after container restart:

```powershell
python scripts\xdr_event_flow_resilience_validate.py `
    --replays 3 `
    --restart-services 1 `
    --send-malformed 1 `
    --output reports\xdr_event_flow_resilience_validation.json
```

Pass:
- Consumer reconnects and resumes processing
- No events lost after reconnect
- No goroutine leak

---

## Mini Soak (5–15 minutes)

Quick stability check before a full 6h run:

```powershell
python scripts\xdr_correlation_soak.py `
    --duration-minutes 5 `
    --batch-size 5000 `
    --sleep-ms 100 `
    --output reports\xdr_correlation_mini_soak.json
```

Pass: same gates as 6h soak.

A mini soak PASS confirms the pipeline is stable but does **not** authorize staged cutover. It is a precondition for investing in a 6h run.

---

## 6h Soak Validation

Required before any staged active promotion:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

Analyze:
```powershell
php artisan xdr:soak-analyze --report=reports/xdr_correlation_soak_6h.json --json

python scripts\xdr_soak_fallback_debug.py `
    --input reports\xdr_correlation_soak_6h.json `
    --output reports\xdr_correlation_soak_fallback_debug.json
```

Required gates:

| Gate | Threshold |
|---|---|
| fallback_count | = 0 |
| failure_count | = 0 |
| status_failures | = 0 |
| p95_latency_ms | < 300 ms |
| memory_growth_mb | stable (no sustained growth) |
| goroutine_growth | = 0 |
| latency_drift | none sustained |

If any gate fails: remain shadow or rollback. Never force promotion.

---

## Shadow Prep Validation (endpoint/DNS/proxy only)

Run only when preparing shadow-only domains for future promotion:

```powershell
python scripts\xdr_endpoint_dns_proxy_shadow_prep.py `
    --output reports\xdr_endpoint_dns_proxy_shadow_prep.json
```

Not required for identity-cloud changes.

---

## Staged Cutover Workflow

**Prerequisites:** 6h soak PASS for the target scope, all gates confirmed.

1. Verify gates: `php artisan xdr:soak-analyze --report=reports/xdr_correlation_soak_6h.json --json`
2. Update correlation engine config:
   ```env
   XDR_CORRELATION_ENGINE=go
   XDR_CORRELATION_SCOPE=identity-cloud
   XDR_CORRELATION_FALLBACK_TO_LEGACY=true
   ```
3. Redeploy services: `docker compose --profile strangler up -d --build`
4. Monitor metrics for the first 30 minutes:
   ```powershell
   curl http://127.0.0.1:8093/metrics
   ```
5. Check cutover status:
   ```powershell
   php artisan xdr:correlation-cutover-status --engine=go --scope=identity-cloud --audit=0 --json
   ```

---

## Rollback Workflow

If anomalies are observed after cutover:

```powershell
# Step 1: Set engine back to shadow
# Update XDR_CORRELATION_ENGINE=shadow in .env

# Step 2: Redeploy
docker compose --profile strangler up -d --build

# Step 3: Verify
php artisan xdr:correlation-cutover-status --engine=go --scope=identity-cloud --audit=0 --json

# Step 4: Investigate
python scripts\xdr_soak_fallback_debug.py `
    --input reports\xdr_correlation_soak_6h.json `
    --output reports\xdr_correlation_soak_fallback_debug.json
```

The circuit breaker provides automatic runtime fallback (3 consecutive failures → legacy) without requiring a redeploy.

---

## Forbidden Workflow Steps

- Never run multiple `php artisan test` processes against the same PostgreSQL test database
- Never promote endpoint/DNS/proxy/firewall without a domain-specific soak PASS
- Never skip the mini soak before a full 6h run
- Never merge `XDR_CORRELATION_ENGINE=go` to `.env` in the repo without soak evidence
- Never force cutover after a failed soak — remain shadow or rollback
- Never treat a mini soak PASS as cutover authorization
