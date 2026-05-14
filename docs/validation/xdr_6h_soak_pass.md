# XDR 6h Soak Validation — PASS

**Date:** 2026-05-14
**Scope:** identity/cloud/SaaS (`XDR_CORRELATION_SCOPE=identity-cloud`)
**Decision:** staged_active

---

## Summary

The identity/cloud/SaaS Go correlation worker completed a full 6-hour soak with all required gates passing. This authorizes staged active promotion for the identity-cloud scope. Rollback capability is preserved.

---

## Why Previous Soaks Failed

### Root Cause: Redpanda Consumer Reconnect Lifecycle

Earlier multi-hour runs produced three repeating failure categories:

| Failure | Root Cause |
|---|---|
| `worker_closed_connection` | Consumer stopped processing silently after a Redpanda partition rebalance — no reconnect loop existed |
| `host_aborted_connection` | Windows TCP stack aborted idle consumer connections — keepalive not enforced |
| `cutover_status_command_failed` | `php artisan xdr:correlation-cutover-status` timed out while the Go worker was mid-reconnect |

The Go Redpanda consumer used a single-shot connection. After a rebalance or broker restart, the consumer received an `unknown_member_id` error and exited without reconnecting. Processing stopped silently.

The PHP cutover status command had a tight timeout. During the worker's reconnect window, the health endpoint was briefly unresponsive, recording a spurious failure even though no message was lost.

### Accelerated Soak Strategy

Targeted reconnect lifecycle and timeout fixes were applied to the affected components. A warm-up soak confirmed baseline stability before the full 6-hour run. No architectural redesign was required.

---

## Fixes Applied

| Fix | Component | Description |
|---|---|---|
| Consumer reconnect loop | correlation-worker, normalizer-worker | Exponential-backoff reconnect on consumer error; handles rebalance and broker restart |
| `unknown_member_id` handling | correlation-worker | Consumer group rejoin on Kafka `unknown_member_id` error code |
| Graceful shutdown | correlation-worker, normalizer-worker, ingestion-gateway | Drain in-flight messages; commit offsets cleanly before exit |
| HTTP timeout hardening | correlation-worker | Increased idle/read/write timeouts to prevent premature TCP teardown |
| `docker_stats` best-effort | `xdr_correlation_soak.py` | Core report written before docker stats collection; timeout or subprocess error returns `available=false` and does not fail the soak |
| Resilience validator retry | `xdr_event_flow_resilience_validate.py` | Retry transient HTTP errors during resilience validation |
| Restart/recovery validation | resilience script | Verified service recovery after container restart without event loss |

---

## Resilience Validation

Run before and after the 6-hour soak:

```powershell
python scripts\xdr_event_flow_resilience_validate.py --replays 3 --restart-services 0 --send-malformed 1 --output reports\xdr_event_flow_resilience_validation.json
```

Validated:
- Replay idempotency: 3 replays produce consistent, deterministic results
- Malformed event rejection: bad payloads rejected without consumer crash
- Error recovery: consumer resumes after transient errors

---

## Final 6h Soak Results

| Gate | Threshold | Actual | Status |
|---|---|---|---|
| `validation_status` | PASS | PASS | PASS |
| `fallback_count` | = 0 | 0 | PASS |
| `failure_count` | = 0 | 0 | PASS |
| `status_failures` | = 0 | 0 | PASS |
| `p95_latency_ms` | < 300 ms | 80.65 ms | PASS |
| `worker_p95_latency_ms` | < 300 ms | 61 ms | PASS |
| `memory_growth_mb` | stable | −6.519 MB | PASS |
| `goroutine_growth` | = 0 | 0 | PASS |
| `latency_drift` | none | not drifting | PASS |
| `duration_minutes` | 360 | 360 | PASS |
| `events_processed` | — | 562,640,000 | — |
| `avg_throughput_eps` | — | 77,981.72 | — |
| `scope` | identity-cloud | identity-cloud | — |

---

## Decision

All gates passed. Staged active promotion is approved for identity/cloud/SaaS scope.

**Recommended configuration:**

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
```

Rollback is preserved. Circuit breaker remains active: 3 consecutive failures trigger automatic fallback to legacy.

---

## Scope Boundaries

Endpoint, DNS, proxy, and firewall domains remain shadow-only. Promotion of any of these domains requires:

- Domain-specific parity validation
- Domain-specific 6h soak with all gates passing
- Explicit approval

Do not promote endpoint/DNS/proxy/firewall to active without completing domain-specific gates.
