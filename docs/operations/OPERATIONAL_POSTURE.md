# Operational Posture

Current correlation mode, domain status, and environment configuration.
Last updated: 2026-06-27

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

Current shadow rules: 121 total shadow (133 registry − 12 staged_active).
Breakdown: 32 endpoint behavioral, 8 LLTET, 9 UEBA, 9 network, 3 threat-intel/IOC, 20 advanced detection Phase 1, 40 detection depth Phase 2.
Shadow alerts go to `xdr.alerts.shadow.endpoint` — they are NOT persisted to `security_alerts`.

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

---

## Infrastructure Fixes (2026-06-27)

### Pandaproxy URL — container vs host

All strangler service definitions in `docker-compose.yml` use:
```yaml
XDR_REDPANDA_REST_URL: http://redpanda:8082
```
This is hardcoded (not `${XDR_REDPANDA_REST_URL:-...}`). The `:-` fallback only fires when the variable is unset; since `.env` has `XDR_REDPANDA_REST_URL=http://127.0.0.1:8082`, the old pattern caused all containers to use the host loopback, breaking container-to-container Pandaproxy access.

`.env` `XDR_REDPANDA_REST_URL=http://127.0.0.1:8082` remains for host-side scripts only.

### Redpanda healthcheck

Changed from `rpk cluster health` (full Kafka metadata fetch, times out during consumer reconnect storms) to:
```yaml
test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:9644/v1/brokers >/dev/null 2>&1"]
```
Admin HTTP API on port 9644 is unaffected by Pandaproxy state.

### Consumer group corruption recovery

Corrupt consumer groups with `65535 = 0xFFFF` sentinel in Redpanda metadata (from stale timestamp-suffixed group IDs) cause a `group.cc:3367 WARN` loop. To clear:
```powershell
docker exec detector-redpanda rpk group delete <corrupt-group-id> --brokers=127.0.0.1:9092
```
Clean group base names are in `.env`: `XDR_NORMALIZER_GROUP=normalizer-worker-v1`, `XDR_CORRELATION_GROUP=correlation-worker-v1`.
