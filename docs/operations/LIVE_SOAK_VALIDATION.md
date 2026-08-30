# Live Soak / Load Validation

**Task:** ENTERPRISE-038  
**Status:** Controlled load test workflow — dry-run by default, execute mode requires explicit opt-in.

---

## Purpose

The live soak validator stress-tests the XDR ingestion pipeline with safe synthetic
demo telemetry and collects stability metrics. It proves runtime stability after
production profile hardening, restore drill, ingestion hardening, observability, EASM,
and pilot evidence work.

**This does NOT:**
- Send real threat data or actual user events
- Change detection rules, ACTIVE_ALLOWLIST, or shadow/active domain boundaries
- Perform active response, containment, or autonomous remediation
- Mutate production data (events carry `demo_run_id` + `_soak=true` metadata)

**This DOES:**
- Send synthetic cloud/identity/SaaS demo events to ingestion gateway
- Measure ingest latency (p95, p99, mean)
- Track accepted/rejected/rate-limited/timeout counts
- Detect circuit breaker opens (inferred from ≥3 consecutive 503s)
- Capture Redpanda topic high watermarks before and after the soak
- Evaluate metrics against pass/warn/fail bounds

---

## Safety Invariants

| Invariant | Enforcement |
|---|---|
| Dry-run default | `--execute` required for real event ingestion |
| Max duration | `MAX_DURATION_MINUTES = 60` |
| Max events per batch | `MAX_EVENTS_PER_BATCH = 50` |
| Total events cap | `MAX_TOTAL_EVENTS = 1000` |
| Min batch interval | `MIN_BATCH_INTERVAL_MS = 200ms` |
| Demo lineage on every event | `demo_run_id`, `scenario_id`, `tenant_id`, `source_event_id` |
| Active domain only | Events use cloud/identity/saas domains — no endpoint/DNS/firewall |
| No SQL mutation | Script contains no INSERT/UPDATE/DELETE/TRUNCATE |

---

## Usage

### Dry-run (default — no events sent)

```bash
python scripts/xdr_live_soak_validate.py
```

Runs pre-flight checks and prints the soak plan. No ingestion occurs.

```bash
python scripts/xdr_live_soak_validate.py \
    --duration-minutes 2 \
    --events-per-batch 5 \
    --batch-interval-ms 2000
```

### Execute mode (actual soak)

Requires the ingestion pipeline running:

```bash
docker compose --profile=strangler up -d
# In correlation-worker: XDR_CORRELATION_EVENT_LOOP_ENABLED=true
# In alert-writer-service: XDR_EVENT_LOOP_ENABLED=true
```

Then:

```bash
python scripts/xdr_live_soak_validate.py --execute \
    --duration-minutes 5 \
    --events-per-batch 10 \
    --batch-interval-ms 1000 \
    --output reports/live_soak_$(date +%Y%m%d).json
```

### Production gateway with mTLS

```bash
python scripts/xdr_live_soak_validate.py --execute \
    --ingest-url https://gateway.example.com:8091/v1/ingest \
    --mtls-enabled \
    --mtls-ca /secure/xdr/ca.crt \
    --mtls-client-cert /secure/xdr/soak-client.crt \
    --mtls-client-key /secure/xdr/soak-client.key \
    --duration-minutes 5 \
    --events-per-batch 10 \
    --batch-interval-ms 1000
```

The same CA-verifying `SSLContext` is used by the `/health` preflight and the
persistent ingestion connection. Hostname verification remains enabled. HTTP
URLs or incomplete identity paths fail before any event is sent and return exit
code 2.

---

## Pre-flight Checks

| Step | Description | Severity |
|---|---|---|
| PRE-01 | Duration within bounds (1–60m) | FAIL |
| PRE-02 | Batch parameters within bounds | FAIL |
| PRE-03 | Total events ≤ 1000 (safety cap) | FAIL |
| PRE-04 | Ingestion gateway reachable (GET /health) | FAIL (execute) / WARN (dry-run) |

---

## Metrics Collected

| Metric | Description |
|---|---|
| `total_attempted` | Total events sent across all batches |
| `accepted` | Events with HTTP 202 response |
| `rate_limited` | Events with HTTP 429 response |
| `rejected` | Events with other non-202 response |
| `publish_failures` | Batches that got HTTP 0 (network error / timeout) |
| `timeouts` | Batches that timed out |
| `circuit_breaker_opens` | Count of ≥3 consecutive 503 sequences |
| `p95_latency_ms` | 95th percentile ingest round-trip latency |
| `p99_latency_ms` | 99th percentile ingest round-trip latency |
| `mean_latency_ms` | Mean batch round-trip latency |
| `watermarks_before` | Redpanda topic high watermarks before soak |
| `watermarks_after` | Redpanda topic high watermarks after soak |

Topics monitored:
- `telemetry.raw`, `telemetry.normalized`, `xdr.alerts` (pipeline flow)
- `telemetry.normalization_failed`, `xdr.correlation_failed`, `xdr.alert_write_failed` (DLQ topics)

---

## Pass/Warn/Fail Bounds

| Bound | Metric | PASS | WARN | FAIL |
|---|---|---|---|---|
| B-01 | Accepted rate | ≥ 0.90 | ≥ 0.80 | < 0.80 |
| B-02 | Rate-limited rate | ≤ 0.05 | ≤ 0.10 | > 0.10 |
| B-03 | p95 latency | < 300ms | < 500ms | ≥ 500ms |
| B-04 | p99 latency | < 600ms | < 1000ms | ≥ 1000ms |
| B-05 | Publish failures | 0 | ≤ 0 (any = WARN) | > 2 |
| B-06 | Circuit breaker opens | 0 | 0 (any = WARN) | > 1 |

---

## Synthetic Event Format

All events carry:
- `demo_run_id`: unique soak run identifier (`soak-YYYYMMDD-HHMMSS-xxxxxx`)
- `scenario_id`: `soak-scenario-038` (default)
- `tenant_id`: `soak-tenant-038` (default)
- `source_event_id`: `src-soak-NNNNNN`
- `event_id` == `trace_id` (for deduplication safety)
- `metadata._soak = true`

Event types rotate through:
| Type | Domain |
|---|---|
| `cloud.iam.api_key_created` | cloud |
| `cloud.security.setting_modified` | cloud |
| `saas.login.success` | saas |
| `identity.login.success` | identity |
| `cloud.storage.bucket_accessed` | cloud |

---

## CLI Reference

```
python scripts/xdr_live_soak_validate.py [OPTIONS]

Options:
  --execute                  Run the actual soak (default: dry-run)
  --duration-minutes N       Soak duration (default: 2, max: 60)
  --events-per-batch N       Events per batch (default: 5, max: 50)
  --batch-interval-ms N      Interval between batches (default: 2000, min: 200)
  --ingest-url URL           Ingestion gateway URL (default: http://localhost:8091/v1/ingest)
  --admin-url URL            Redpanda admin URL (default: http://localhost:9644)
  --scenario-id ID           Scenario ID tag (default: soak-scenario-038)
  --tenant-id ID             Tenant ID tag (default: soak-tenant-038)
  --profile local|staging|production
  --timeout-seconds N        HTTP request timeout (default: 10)
  --output PATH              Write JSON report
  --quiet                    Suppress console output
```

Exit codes: 0=PASS, 1=FAIL, 2=ERROR

---

## Report Location

JSON reports written to `reports/` when `--output` is provided:

```bash
python scripts/xdr_live_soak_validate.py --execute \
    --output reports/live_soak_$(date +%Y%m%d_%H%M%S).json
```

---

## Related Documents

- `docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md` — production deployment posture
- `docs/operations/RESTORE_DRILL.md` — backup/restore drill
- `docs/operations/BACKUP_RESTORE_RECOVERY.md` — RPO/RTO and manual backup commands
- `docs/validation/VALIDATION_BASELINES.md` — baseline pass criteria
- `scripts/xdr_observability_slo_validate.py` — SLO metric validation
- `scripts/xdr_posture_check.py` — runtime environment posture check
