# Operational Posture

Current correlation mode, domain status, and environment configuration.
Last updated: 2026-05-18

This is the authoritative source for current active/shadow domain decisions.

---

## Current Correlation Mode

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
```

Decision rationale: 6h soak PASS (2026-05-14). Staged active for identity/cloud/SaaS.
Rollback preserved: circuit breaker triggers legacy fallback after 3 consecutive failures.

Circuit breaker thresholds:
- 1–2 transient failures → no fallback
- 3 consecutive failures → fallback to legacy

---

## Domain Status

| Domain | Status | Topic | Persisted to `security_alerts` |
|---|---|---|---|
| identity | staged_active | `xdr.alerts` | Yes |
| cloud | staged_active | `xdr.alerts` | Yes |
| SaaS | staged_active | `xdr.alerts` | Yes |
| endpoint | shadow-only | `xdr.alerts.shadow.endpoint` | **NO** |
| DNS | shadow-only | `xdr.alerts.shadow.endpoint` | **NO** |
| proxy | shadow-only | `xdr.alerts.shadow.endpoint` | **NO** |
| firewall | shadow-only | `xdr.alerts.shadow.endpoint` | **NO** |

**Do NOT expand active scope beyond identity/cloud/SaaS without a domain-specific 6h soak PASS.**

---

## Scenario Runner Config

```env
QUEUE_CONNECTION=database
SCENARIO_PIPELINE_MODE=real
SCENARIO_INGESTION_GATEWAY_URL=http://127.0.0.1:8091
SCENARIO_INGESTION_GATEWAY_SECRET=dev-secret-change-me
SCENARIO_PIPELINE_TIMEOUT_SECONDS=30
SCENARIO_PIPELINE_POLL_MS=1000
SCENARIO_STAGE_DELAY_MS=350
```

---

## Agent Config

```env
SOC_AGENT_ENROLLMENT_TOKEN=<token>
SOC_AGENT_HEARTBEAT_INTERVAL_SECONDS=60
SOC_AGENT_OFFLINE_AFTER_SECONDS=180
SOC_AGENT_LATEST_VERSION=0.2.0
```

---

## Internal Service Auth

```env
XDR_INTERNAL_AUTH_SECRET=<secret>
XDR_NORMALIZER_INTERNAL_TOKEN=<token>
XDR_ALERT_WRITER_INTERNAL_TOKEN=<token>
```

Token format: `base64(serviceId|timestamp|HMAC-SHA256)`, 5-minute validity window.
Go/Python services log `[SECURITY-WARN]` at startup for missing/dev-default secrets.

---

## Rollback Posture

Rollback capability is preserved and MUST NOT be removed.

To trigger manual fallback:
```env
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=1
```

Automatic circuit breaker: 3 consecutive correlation failures → fallback to legacy Laravel detector.

---

## Cutover Gates

Permanent cutover from `staged_active` to full production requires ALL of these:

| Gate | Threshold |
|---|---|
| fallback_count | = 0 |
| failure_count | = 0 |
| duplicate_rate | = 0 |
| goroutine_growth | = 0 |
| memory_usage | stable |
| p95_latency_ms | < 300 |
| latency_drift | none sustained |
| alert_type_match | >= 0.95 |
| evidence_match | >= 0.98 |
| alert_count_delta | <= 1–2% |

If any gate fails → remain shadow OR rollback to legacy. **Never force cutover.**

---

## Endpoint Posture

Endpoint detection is permanently shadow-only until:
1. A domain-specific 6h soak PASS for the endpoint domain
2. All cutover gates above pass for endpoint telemetry

Current shadow rules: 22 endpoint behavioral, 3 threat-intel/IOC (25 shadow total).
These are deployed and generating alerts on `xdr.alerts.shadow.endpoint` — they are NOT persisted.

---

## Start Full Stack

```powershell
# Infrastructure
docker compose up -d

# Go + Python pipeline services
docker compose --profile strangler up -d

# Laravel queue worker (required for Scenario Runner real mode)
php artisan queue:work --sleep=1 --tries=1

# Verify health
curl http://localhost:8091/health    # ingestion-gateway
curl http://localhost:8092/health    # normalizer-worker
```

Expected health responses:
```json
{"status":"ok","service":"ingestion-gateway"}
{"status":"ok","service":"telemetry-normalizer"}
{"status":"ok","service":"alert-writer"}
{"status":"ok","service":"incident-builder"}
```
