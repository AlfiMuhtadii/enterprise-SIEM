# Endpoint Shadow Correlation — Runtime Architecture

Describes how endpoint shadow correlation integrates with the existing polyglot event pipeline without entering the active incident workflow.

---

## Event Flow — Normal Active Path (identity/cloud/SaaS)

```
[telemetry.normalized]
    │  consumed by: correlation-worker-v1
    ▼
[correlation-worker :8093]
    │  correlateIdentityCloud(events)  ← active, staged_active
    │  produces: xdr.alerts
    ▼
[Redpanda: xdr.alerts]
    │  consumed by: alert-writer-service
    ▼
[alert-writer-service :8095]
    │  writes PostgreSQL + OpenSearch
    │  publishes: alerts.created
    ▼
[Redpanda: alerts.created]
    ▼
[incident-builder-service :8096]
    ▼
[Redpanda: incidents.updated]
    ▼
[Laravel SOC control-plane]
```

---

## Event Flow — Endpoint Shadow Path (shadow-only)

```
[telemetry.normalized]
    │  same topic, same consumer group (correlation-worker-v1)
    ▼
[correlation-worker :8093]
    │  correlateEndpointShadow(rawMaps)  ← shadow-only, runs alongside active
    │  filters telemetry_type == "endpoint"
    │  applies 6 shadow rules
    │  produces: xdr.alerts.shadow.endpoint  ← SEPARATE TOPIC
    ▼
[Redpanda: xdr.alerts.shadow.endpoint]
    │  NOT consumed by alert-writer-service
    │  NOT consumed by incident-builder-service
    │  NOT consumed by Laravel SOC
    │  Available for: security analytics, shadow monitoring, validation
    ▼
[Shadow Alert Store / Analytics]  (future — not yet implemented)
```

The shadow path runs inside the same `consumeOnce()` loop that processes the active path. It uses the `rawMaps` variable (parallel to the `events []Event` used by the active path) to access the nested endpoint normalized format.

---

## Separation from Active Incident Flow

| Aspect | Active Path | Shadow Path |
|---|---|---|
| Input topic | `telemetry.normalized` | `telemetry.normalized` (same) |
| Consumer group | `correlation-worker-v1` | `correlation-worker-v1` (same) |
| Correlation function | `correlateIdentityCloud()` | `correlateEndpointShadow()` |
| Output topic | `xdr.alerts` | `xdr.alerts.shadow.endpoint` |
| Downstream consumers | alert-writer, incident-builder, Laravel SOC | none (shadow-only) |
| `shadow_mode` flag | `true` (Go worker) | `true` (always) |
| Affects incidents | yes (via active path) | no |
| Circuit breaker | covered | NOT covered (shadow failures are logged only) |
| Soak validation | required before cutover | current shadow validation only |

The shadow path failure (e.g., publish to `xdr.alerts.shadow.endpoint` failing) is logged and counted in `publish_errors` but does NOT affect:
- The active identity-cloud correlation output
- The circuit breaker behavior
- The Laravel cutover status

---

## HTTP Endpoint for Replay Validation

The correlation-worker exposes a synchronous HTTP endpoint for shadow correlation testing:

```
POST /v1/correlate-endpoint-shadow
Content-Type: application/json
Body: JSON array of normalized endpoint events

Response:
{
  "events": <int>,
  "shadow_alerts": [...],
  "alert_count": <int>,
  "latency_ms": <int>,
  "shadow_mode": true,
  "topic": "xdr.alerts.shadow.endpoint"
}
```

This endpoint:
- Does NOT publish to Redpanda
- Returns shadow alert results synchronously
- Is used by `xdr_endpoint_shadow_correlation_validate.py` for fixture replay
- Is NOT part of the active correlation path

---

## Replay Validation Path

```
[Fixture files: tests/fixtures/endpoint_shadow/]
    │  loaded by validator
    ▼
[xdr_endpoint_shadow_correlation_validate.py]
    │  POST /v1/correlate-endpoint-shadow  ← synchronous, no Redpanda
    ▼
[correlation-worker shadow rules]
    │  correlateEndpointShadow()
    ▼
[Validator checks alert shape, rule_id, shadow_mode, trace_id]
    ▼
[reports/xdr_endpoint_shadow_correlation_validation.json]
```

Replay validation does NOT require Redpanda to be running. It validates rule logic directly via HTTP.

---

## Shadow Alert Format

```json
{
  "alert_id": "sha256-derived-40-char-hex",
  "rule_id": "suspicious_temp_file_write",
  "version": "v1",
  "title": "Executable Written to Temporary Directory",
  "severity": "high",
  "confidence": 0.78,
  "host": "workstation-ep01.corp.test",
  "user": "alice",
  "trace_id": "shadow-trace-suspicious-003",
  "shadow_mode": true,
  "evidence_ids": ["ep-fw-shadow-001"],
  "event_type": "file_write",
  "evidence": {
    "file_path": "C:\\Users\\alice\\AppData\\Local\\Temp\\update_payload.exe",
    "operation": "create"
  }
}
```

Key fields:
- `shadow_mode: true` — always true for endpoint shadow alerts
- `rule_id` — identifies which detection rule triggered
- `trace_id` — propagated from the normalized event
- Alert is published under a `shadow_mode: true` envelope to `xdr.alerts.shadow.endpoint`

---

## Rollback Path

No cutover has been made for endpoint. Rollback scenarios:

**If shadow correlation causes active flow regression:**
1. `XDR_CORRELATION_ENGINE=shadow` → disables Go active correlation (identity-cloud falls back to legacy)
2. Investigate root cause
3. Fix, re-run mini soak on identity-cloud
4. Re-enable with `XDR_CORRELATION_ENGINE=go`

**If shadow publish fails repeatedly:**
- Publish errors are logged and counted in `publish_errors`
- They do NOT affect the active pipeline
- Monitor `publish_errors` metric; acceptable if `xdr.alerts.shadow.endpoint` topic doesn't exist in dev environments

---

## Metrics Exposed

New metrics added to `GET /metrics` for shadow monitoring:

| Metric | Description |
|---|---|
| `shadow_alerts_published` | Cumulative shadow alerts published to `xdr.alerts.shadow.endpoint` |

Shadow publish errors are counted in the existing `publish_errors` counter.

---

## Future Staged_Active Gates

Before endpoint shadow correlation can be promoted to active:

1. **Gate 1 (current):** Shadow normalization validation PASS
2. **Gate 2 (current):** Shadow correlation rule validation PASS (benign/suspicious fixture replay)
3. **Gate 3 (future):** Endpoint replay dataset at scale (≥ 100k events)
4. **Gate 4 (future):** Endpoint parity validation (shadow vs legacy alert output match ≥ 0.95)
5. **Gate 5 (future):** Endpoint 6h soak PASS
6. **Gate 6 (future):** Rollback validation for endpoint scope change
7. **Approval:** Explicit operator decision after all gates pass

See `docs/architecture/endpoint-shadow-correlation-plan.md` for full gate definitions.
