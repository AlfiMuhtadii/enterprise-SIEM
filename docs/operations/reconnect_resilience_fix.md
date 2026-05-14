# Reconnect and Resilience Fix

**Date:** 2026-05-14
**Affected:** correlation-worker, normalizer-worker, ingestion-gateway, xdr_correlation_soak.py, xdr_event_flow_resilience_validate.py

---

## Problem

The 6-hour soak failed repeatedly before this fix due to connection lifecycle issues in the Go Redpanda consumers.

### Observed Failures

| Category | Symptom |
|---|---|
| `worker_closed_connection` | Consumer dropped connection silently after Redpanda partition rebalance |
| `host_aborted_connection` | Windows TCP stack aborted idle consumer connections between batch submissions |
| `cutover_status_command_failed` | PHP status check timed out while Go worker was in reconnect window |

### Root Cause

The Redpanda consumer used a single-shot connection. On `unknown_member_id` — returned when a consumer group member is evicted after a rebalance or session timeout — the consumer exited without reconnecting. Processing stopped silently with no operator-visible error until the soak script recorded a failure.

The PHP `xdr:correlation-cutover-status` command polled the Go worker health endpoint with a tight timeout. During the worker's reconnect window, the health endpoint was briefly unresponsive, causing the status check to record a failure even though no message was lost and processing resumed automatically.

---

## Fixes

### 1. Consumer Reconnect Loop

**Files:** `services/correlation-worker/main.go`, `services/normalizer-worker/main.go`

The consumer now runs in a reconnect loop. On any consumer error:

1. Log the error and classify the failure category
2. Close the existing consumer connection
3. Wait with exponential backoff (initial: configurable; cap: 30s)
4. Re-create the consumer and rejoin the consumer group
5. Resume processing

Handles: Redpanda restarts, partition rebalances, broker failover, session timeouts.

### 2. `unknown_member_id` Handling

**Files:** `services/correlation-worker/main.go`

Kafka/Redpanda returns `unknown_member_id` when a consumer group member is evicted. The consumer now detects this error code and performs an explicit group rejoin rather than treating it as a fatal exit condition.

### 3. Graceful Shutdown

**Files:** `services/correlation-worker/main.go`, `services/normalizer-worker/main.go`, `services/ingestion-gateway/main.go`

On SIGTERM/SIGINT:

1. Stop accepting new messages
2. Complete in-flight batch processing
3. Commit offsets for all processed messages
4. Close consumer and producer connections cleanly

Prevents mid-batch teardown, partial offset commits, and duplicate processing on restart.

### 4. HTTP Timeout Hardening

**Files:** `services/correlation-worker/main.go`

Increased idle connection timeout and read/write deadlines on the Go HTTP server. Prevents the Windows TCP stack from aborting connections during the idle gap between batch submissions.

### 5. `docker_stats` Best-Effort

**Files:** `scripts/xdr_correlation_soak.py`

A `subprocess.TimeoutExpired` from `docker stats --no-stream` previously crashed the soak script after all validation had completed, preventing the report from being written to disk.

Changes:
- Core soak report written to disk before `docker stats` collection
- `docker stats` timeout increased from 20s to 60s
- `TimeoutExpired` caught: returns `{"available": false, "error": "docker_stats_timeout", "container": "..."}`
- Other subprocess errors caught: returns `{"available": false, "error": "docker_stats_failed", "details": "..."}`
- `docker_stats_available` and `docker_stats_error` fields added to report
- Soak exit code based on `validation_status` (core gates), not docker stats availability

### 6. Resilience Validator Retry

**Files:** `scripts/xdr_event_flow_resilience_validate.py`

Added retry handling for transient HTTP errors during resilience validation. A single slow response from the correlation worker no longer fails the entire resilience validation run.

---

## Validation After Fixes

### Event-Flow Resilience

```powershell
python scripts\xdr_event_flow_resilience_validate.py --replays 3 --restart-services 0 --send-malformed 1 --output reports\xdr_event_flow_resilience_validation.json
```

### 6h Soak

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

Result: PASS — see `docs/validation/xdr_6h_soak_pass.md`

---

## Rollback

All fixes are additive and non-breaking to the event pipeline.

Runtime rollback: set `XDR_CORRELATION_ENGINE=shadow` or `XDR_CORRELATION_ENGINE=legacy`.

The circuit breaker provides automatic runtime fallback: 3 consecutive correlation failures trigger legacy fallback regardless of engine setting.
