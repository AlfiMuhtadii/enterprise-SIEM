# Runtime Observability & SLO Readiness

**Module:** BACKLOG-OBS-029
**Validation:** `python scripts/xdr_observability_slo_validate.py --profile=<profile>`

---

## Overview

This document defines SLO targets and metric inventory for all XDR pipeline services.
All EASM output is advisory-only. No metrics trigger automated remediation.

---

## Profiles

| Profile | Purpose | Threshold posture |
|---|---|---|
| `local` | Local dev and demo/thesis | WARN for exceedances; most FAILs lenient |
| `staging` | Pre-production validation | FAIL for sustained exceedances |
| `production` | Production pilot | Tight FAILs; CB opens always FAIL |

---

## Metric Inventory

### ingestion-gateway — `http://ingestion-gateway:8091/metrics`

| Metric | Type | Description |
|---|---|---|
| `accepted` | counter | Events admitted to pipeline |
| `rejected` | counter | Events rejected (auth, schema, private IP) |
| `rate_limited` | counter | Events dropped by per-tenant rate limiter |
| `publish_errors` | counter | Failures publishing to `telemetry.raw` |
| `p95_latency_ms` | gauge | 95th percentile request latency (ms) |
| `p99_latency_ms` | gauge | 99th percentile request latency (ms) |
| `circuit_breaker_opens` | counter | Circuit breaker open events |

### normalizer-worker — `http://normalizer-worker:8092/metrics`

| Metric | Type | Description |
|---|---|---|
| `processed` | counter | Events consumed from `telemetry.raw` |
| `forwarded` | counter | Events published to `telemetry.normalized` |
| `malformed` | counter | Events sent to DLQ (parse failures) |
| `publish_errors` | counter | Failures publishing to `telemetry.normalized` |
| `dlq_writes` | counter | Records written to `telemetry.normalization_failed` |
| `consumer_errors` | counter | Consumer poll errors |
| `reconnect_count` | counter | Consumer reconnect events |
| `goroutines` | gauge | Active goroutine count |
| `heap_alloc_mb` | gauge | Heap allocation (MB) |

### correlation-worker — `http://correlation-worker:8093/metrics`

| Metric | Type | Description |
|---|---|---|
| `processed` | counter | Events consumed from `telemetry.normalized` |
| `alerts` | counter | Alerts generated |
| `published` | counter | Alerts published to `xdr.alerts` |
| `publish_errors` | counter | Failures publishing alerts |
| `p95_latency_ms` | gauge | 95th percentile correlation latency (ms) |
| `consumer_errors` | counter | Consumer poll errors |
| `reconnect_count` | counter | Consumer reconnect events |
| `goroutines` | gauge | Active goroutine count |
| `heap_alloc_mb` | gauge | Heap allocation (MB) |

### alert-writer-service — `http://alert-writer:8094/metrics`

| Metric | Type | Description |
|---|---|---|
| `processed` | counter | Alerts consumed from `xdr.alerts` |
| `written` | counter | Alerts written to PostgreSQL + OpenSearch |
| `dlq_writes` | counter | Failures routed to `xdr.alert_write_failed` |
| `dlq_errors` | counter | DLQ write failures themselves |
| `p95_latency_ms` | gauge | 95th percentile write latency (ms) |
| `errors` | counter | Total processing errors |

### incident-builder-service — `http://incident-builder:8095/metrics`

| Metric | Type | Description |
|---|---|---|
| `processed` | counter | Alert events consumed from `alerts.created` |
| `created` | counter | Incidents created in PostgreSQL |
| `dlq_writes` | counter | Failures routed to DLQ |
| `dlq_errors` | counter | DLQ write failures |
| `errors` | counter | Total processing errors |

### DLQ Summary — derived from `dlq_records` table

| Metric | Type | Description |
|---|---|---|
| `total_records` | gauge | Total DLQ records |
| `pending_records` | gauge | Records awaiting review/replay |
| `replayable_records` | gauge | Records eligible for replay |
| `failed_records` | gauge | Permanently failed records |
| `dlq_error_rate_pct` | gauge | `failed / total * 100` |

### EASM (advisory-only) — from `easm_posture_snapshots` + `easm_asset_risk_scores`

| Metric | Type | Description |
|---|---|---|
| `total_assets` | gauge | Registered website assets |
| `open_findings_high` | gauge | Open high-severity findings |
| `open_findings_medium` | gauge | Open medium-severity findings |
| `min_posture_score` | gauge | Lowest posture score across assets (0–100) |
| `avg_posture_score` | gauge | Average posture score across assets |
| `is_advisory` | flag | Always true — no active response |

---

## SLO Thresholds

### Profile: local

| Check | SLO ID | Metric | WARN | FAIL |
|---|---|---|---|---|
| Ingestion p95 latency | SLO-01 | `p95_latency_ms` | > 200ms | N/A |
| Ingestion publish errors | SLO-02 | `publish_errors` | > 0 | > 20 |
| Ingestion rejection rate | SLO-03 | `rejected/(accepted+rejected)` | > 5% | > 20% |
| Ingestion CB opens | SLO-04 | `circuit_breaker_opens` | > 0 | > 5 |
| Normalizer malformed rate | SLO-05 | `malformed/processed` | > 2% | > 10% |
| Normalizer publish errors | SLO-06 | `publish_errors` | > 0 | > 20 |
| Normalizer DLQ writes | SLO-07 | `dlq_writes` | > 0 | > 50 |
| Correlation p95 latency | SLO-08 | `p95_latency_ms` | > 300ms | N/A |
| Correlation publish errors | SLO-09 | `publish_errors` | > 0 | > 20 |
| Alert-writer p95 latency | SLO-10 | `p95_latency_ms` | > 500ms | N/A |
| Alert-writer DLQ writes | SLO-11 | `dlq_writes` | > 0 | > 20 |
| Alert-writer DLQ errors | SLO-12 | `dlq_errors` | > 0 | > 5 |
| Incident-builder DLQ writes | SLO-13 | `dlq_writes` | > 0 | > 20 |
| Incident-builder errors | SLO-14 | `errors` | > 0 | > 10 |
| DLQ error rate | SLO-15 | `dlq_error_rate_pct` | > 1% | N/A |
| DLQ pending records | SLO-16 | `pending_records` | > 100 | N/A |
| EASM min posture score | SLO-17 | `min_posture_score` (advisory) | < 40 | N/A |

### Profile: staging

| Check | SLO ID | WARN | FAIL |
|---|---|---|---|
| Ingestion p95 latency | SLO-01 | > 100ms | > 300ms |
| Ingestion publish errors | SLO-02 | > 0 | > 10 |
| Ingestion rejection rate | SLO-03 | > 3% | > 10% |
| Ingestion CB opens | SLO-04 | > 0 | > 2 |
| Normalizer malformed rate | SLO-05 | > 1% | > 5% |
| Normalizer publish errors | SLO-06 | > 0 | > 10 |
| Normalizer DLQ writes | SLO-07 | > 0 | > 25 |
| Correlation p95 latency | SLO-08 | > 200ms | > 500ms |
| Correlation publish errors | SLO-09 | > 0 | > 10 |
| Alert-writer p95 latency | SLO-10 | > 300ms | > 800ms |
| Alert-writer DLQ writes | SLO-11 | > 0 | > 10 |
| Alert-writer DLQ errors | SLO-12 | > 0 | > 2 |
| Incident-builder DLQ writes | SLO-13 | > 0 | > 10 |
| Incident-builder errors | SLO-14 | > 0 | > 5 |
| DLQ error rate | SLO-15 | > 0.5% | > 2% |
| DLQ pending records | SLO-16 | > 50 | > 500 |
| EASM min posture score | SLO-17 | < 60 (advisory) | < 40 (advisory) |

### Profile: production

| Check | SLO ID | WARN | FAIL |
|---|---|---|---|
| Ingestion p95 latency | SLO-01 | > 50ms | > 100ms |
| Ingestion publish errors | SLO-02 | > 0 | > 1 |
| Ingestion rejection rate | SLO-03 | > 1% | > 5% |
| Ingestion CB opens | SLO-04 | > 0 | **> 0 (any occurrence = FAIL)** |
| Normalizer malformed rate | SLO-05 | > 0.5% | > 2% |
| Normalizer publish errors | SLO-06 | > 0 | > 5 |
| Normalizer DLQ writes | SLO-07 | > 0 | > 10 |
| Correlation p95 latency | SLO-08 | > 100ms | > 300ms |
| Correlation publish errors | SLO-09 | > 0 | > 5 |
| Alert-writer p95 latency | SLO-10 | > 200ms | > 500ms |
| Alert-writer DLQ writes | SLO-11 | > 0 | > 5 |
| Alert-writer DLQ errors | SLO-12 | > 0 | **> 0 (any = FAIL)** |
| Incident-builder DLQ writes | SLO-13 | > 0 | > 5 |
| Incident-builder errors | SLO-14 | > 0 | **> 0 (any = FAIL)** |
| DLQ error rate | SLO-15 | > 0% | > 1% |
| DLQ pending records | SLO-16 | > 10 | > 100 |
| EASM min posture score | SLO-17 | < 80 (advisory) | < 60 (advisory) |

---

## Running the Validator

```powershell
# Offline observability readiness only (no metrics input)
python scripts/xdr_observability_slo_validate.py --profile=local

# With metrics snapshot
python scripts/xdr_observability_slo_validate.py \
    --profile=production \
    --input reports/metrics_snapshot.json \
    --output reports/obs_slo_report.json

# Staging with custom repo root
python scripts/xdr_observability_slo_validate.py \
    --profile=staging \
    --input reports/metrics_snapshot.json
```

**Exit codes:** 0=PASS, 1=FAIL, 2=ERROR

---

## Metrics Collection

Collect a metrics snapshot with curl (requires services running):

```bash
curl -s http://localhost:8091/metrics > /tmp/ig.json
curl -s http://localhost:8092/metrics > /tmp/nw.json
curl -s http://localhost:8093/metrics > /tmp/cw.json
```

Merge into the expected input format and pass via `--input`.
The `xdr_posture_check.py` and `xdr_recovery_validate.py` scripts are
independent and run separately.

---

## Grafana Dashboard

Dashboard spec: `config/grafana/runtime-observability-slo.json`

Import via: Grafana → Dashboards → Import → Upload JSON

**Key panels:**
1. Ingestion rate (accepted/rejected/rate-limited)
2. Ingestion p95/p99 latency with SLO threshold lines
3. Circuit breaker opens (red on any occurrence in production)
4. Rejection rate (%)
5. DLQ writes by source
6. DLQ pending records and error rate
7. DLQ replayable vs non-replayable
8. Alert and incident generation rates
9. Publish errors by service
10. EASM posture score trend per asset (advisory)
11. EASM open findings by severity (advisory)
12. EASM risk score table with trend direction (advisory)
13. EASM finding change distribution — new/resolved/unchanged/regressed (advisory)
14. SLO validation results table (last validator run)

---

## Observability Readiness Checks (OBS-01 — OBS-08)

| Check | File | Severity if missing |
|---|---|---|
| OBS-01 | `config/grafana/runtime-observability-slo.json` | WARN |
| OBS-02 | `scripts/xdr_posture_check.py` | WARN |
| OBS-03 | `scripts/xdr_recovery_validate.py` | WARN |
| OBS-04 | `scripts/xdr_ingestion_hardening_validate.py` | WARN |
| OBS-05 | `docs/operations/RUNTIME_OBSERVABILITY_SLO.md` | WARN |
| OBS-06 | `docs/operations/observability-dashboards.md` | WARN |
| OBS-07 | `scripts/xdr_easm_posture_history.py` | WARN |
| OBS-08 | `docs/operations/BACKUP_RESTORE_RECOVERY.md` | WARN |

All OBS checks are WARN (never FAIL) — missing monitoring infrastructure
does not fail the platform, but operators should address gaps before production.

---

## Safety Constraints

- No autonomous remediation triggered by any metric threshold
- EASM output is advisory-only (SLO-17 never creates incidents)
- No detection rules modified by this module
- `ACTIVE_ALLOWLIST` unchanged
- Shadow/active domain boundaries unchanged
- All SLO thresholds are advisory targets for operator decision-making
