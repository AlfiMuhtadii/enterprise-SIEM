# Endpoint Shadow Validation

Operations guide for validating endpoint shadow correlation. Endpoint domain is shadow-only — shadow alerts do NOT enter the active incident pipeline.

---

## What Shadow Validation Checks

| Check | Tool |
|---|---|
| Benign fixtures produce zero shadow alerts | `xdr_endpoint_shadow_correlation_validate.py` |
| Suspicious fixtures produce expected shadow alerts | `xdr_endpoint_shadow_correlation_validate.py` |
| Each alert has correct `rule_id` | `xdr_endpoint_shadow_correlation_validate.py` |
| `shadow_mode = true` on all alerts | `xdr_endpoint_shadow_correlation_validate.py` |
| `trace_id` propagated from event to alert | `xdr_endpoint_shadow_correlation_validate.py` |
| No duplicate alert IDs | `xdr_endpoint_shadow_correlation_validate.py` |
| Shadow alerts publish to `xdr.alerts.shadow.endpoint` | correlation-worker `/metrics` + report `topic` field |
| Active identity-cloud flow unaffected | mini soak on identity-cloud events |

---

## Prerequisites

```powershell
# Services must be running
docker compose up -d
docker compose --profile strangler up -d --build

# Verify correlation-worker is healthy
curl http://127.0.0.1:8093/health
curl http://127.0.0.1:8093/ready

# Verify shadow endpoint is registered
curl -X POST http://127.0.0.1:8093/v1/correlate-endpoint-shadow `
    -H "Content-Type: application/json" `
    -d "[]"
```

---

## Running Shadow Correlation Validation

```powershell
python scripts\xdr_endpoint_shadow_correlation_validate.py `
    --fixture-dir-benign tests/fixtures/endpoint_shadow/benign `
    --fixture-dir-suspicious tests/fixtures/endpoint_shadow/suspicious `
    --correlation-url http://127.0.0.1:8093 `
    --output reports/xdr_endpoint_shadow_correlation_validation.json
```

For an mTLS-enabled correlation service:

```powershell
python scripts\xdr_endpoint_shadow_correlation_validate.py `
    --correlation-url https://correlation-worker:8093 `
    --mtls-enabled `
    --mtls-ca certs/ca.crt `
    --mtls-client-cert certs/client.crt `
    --mtls-client-key certs/client.key
```

Invalid mTLS configuration exits `2` before fixture loading or report writes.
Offline simulation (`--use-correlation-service 0`) remains certificate-independent
and must not be combined with `--mtls-enabled`.

**Without correlation service (schema/logic validation only):**

```powershell
python scripts\xdr_endpoint_shadow_correlation_validate.py `
    --use-correlation-service 0 `
    --output reports/xdr_endpoint_shadow_correlation_validation.json
```

---

## Expected Pass Criteria

All of the following must be true for a PASS:

| Check | Expected |
|---|---|
| `benign_fixtures_produce_no_alerts` | `true` |
| `suspicious_fixtures_produce_expected_alerts` | `true` |
| `all_alerts_shadow_mode` | `true` — every alert has `shadow_mode = true` |
| `all_alerts_have_rule_id` | `true` — every alert has non-empty `rule_id` |
| `trace_id_propagated` | `true` — alert `trace_id` matches fixture event `trace_id` |
| `no_duplicate_alert_ids` | `true` |
| `no_active_incident_activation` | `true` — shadow_mode confirmed; not via `xdr.alerts` |

---

## Full Validation Suite After Endpoint Shadow Changes

Run these commands in order. Stop at the first FAIL.

```powershell
# 1. Docker compose config
docker compose config --quiet

# 2. Endpoint normalization validation (no regression)
python scripts\xdr_endpoint_normalization_validate.py `
    --output reports/xdr_endpoint_normalization_validation.json

# 3. Endpoint shadow correlation validation
python scripts\xdr_endpoint_shadow_correlation_validate.py `
    --output reports/xdr_endpoint_shadow_correlation_validation.json

# 4. Event contracts unaffected
python scripts\xdr_contract_validate.py `
    --output reports/xdr_contract_validation.json

# 5. Event flow resilience (active flow unaffected)
python scripts\xdr_event_flow_resilience_validate.py `
    --replays 3 --restart-services 1 --send-malformed 1 `
    --output reports/xdr_event_flow_resilience_validation.json

# 6. Mini soak (identity-cloud active flow unaffected)
python scripts\xdr_correlation_soak.py `
    --duration-minutes 5 --batch-size 5000 --sleep-ms 100 `
    --output reports/xdr_correlation_endpoint_patch_check.json
```

---

## Metrics to Watch

```powershell
curl http://127.0.0.1:8093/metrics
```

After sending shadow test events:
- `shadow_alerts_published` should be non-zero
- `publish_errors` should not increase
- `goroutines` should remain stable
- `heap_alloc_mb` should remain stable

The `shadow_alerts_published` counter is SEPARATE from `alerts` (identity-cloud active alerts). Shadow alerts have zero effect on the active pipeline.

---

## Rollback Guidance

If shadow correlation causes any regression in the active pipeline:

1. Set `XDR_CORRELATION_ENGINE=shadow` or `XDR_CORRELATION_ENGINE=legacy`
2. Redeploy: `docker compose --profile strangler up -d --build`
3. Verify active flow: `python scripts\xdr_correlation_soak.py --duration-minutes 5 ...`
4. The `correlateEndpointShadow()` code path can be disabled by setting `XDR_ENDPOINT_SHADOW_ENABLED=false` (if that env var is wired) or by redeploying without the shadow rules

The circuit breaker covers the active identity-cloud path. Shadow correlation failures (e.g., publish to `xdr.alerts.shadow.endpoint` failing) are logged and counted but do NOT trigger the circuit breaker or fallback mechanism.

---

## Shadow-Only Constraints — Forbidden Actions

- Do NOT change the shadow alert output topic to `xdr.alerts`
- Do NOT wire `xdr.alerts.shadow.endpoint` as input to alert-writer-service
- Do NOT configure incident-builder-service to consume `xdr.alerts.shadow.endpoint`
- Do NOT promote endpoint shadow alerts into the Laravel SOC incident workflow
- Do NOT treat a shadow validation PASS as cutover authorization
- Do NOT set `XDR_CORRELATION_SCOPE` to include `endpoint` in production config

Shadow validation PASS means: the correlation rules produce expected output in shadow mode. It does NOT satisfy the cutover gates in `docs/architecture/endpoint-shadow-correlation-plan.md`.

---

## Fixture Reference

| Fixture | Expected alerts | Rule |
|---|---|---|
| `benign/benign_browser_network.json` | 0 | — |
| `benign/benign_login.json` | 0 | — |
| `suspicious/suspicious_powershell_encoded.json` | ≥ 1 | `powershell_encoded_command` |
| `suspicious/suspicious_temp_file_drop.json` | ≥ 1 | `suspicious_temp_file_write` |
| `suspicious/suspicious_failed_logins.json` | ≥ 1 | `failed_login_burst` |
| `suspicious/suspicious_dns_beacon.json` | ≥ 1 | `suspicious_dns_query` |
| `suspicious/suspicious_outbound.json` | ≥ 1 | `suspicious_outbound_connection` |
| `suspicious/suspicious_parent_child.json` | ≥ 1 | `suspicious_parent_child_process` |
