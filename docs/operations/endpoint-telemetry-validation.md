# Endpoint Telemetry Validation

Operations guide for validating endpoint telemetry normalization. Endpoint domain is shadow-only — this validates the normalization pipeline, not active correlation.

---

## What This Validates

| Check | Tool |
|---|---|
| Endpoint normalization produces correct output shape | `xdr_endpoint_normalization_validate.py` |
| All required normalized fields present | `xdr_endpoint_normalization_validate.py` |
| Normalization is idempotent | `xdr_endpoint_normalization_validate.py` |
| No duplicate normalized event IDs | `xdr_endpoint_normalization_validate.py` |
| Normalizer service accepts endpoint events | `xdr_endpoint_normalization_validate.py` (service mode) |
| Existing identity-cloud flow unaffected | `xdr_correlation_soak.py` (mini soak) |
| Event contracts still valid | `xdr_contract_validate.py` |

---

## Prerequisites

```powershell
# Infrastructure and services must be running
docker compose up -d
docker compose --profile strangler up -d --build

# Verify normalizer is healthy
curl http://127.0.0.1:8092/health
```

---

## Running Endpoint Normalization Validation

```powershell
python scripts\xdr_endpoint_normalization_validate.py `
    --fixture-dir tests/fixtures/endpoint `
    --normalizer-url http://127.0.0.1:8092 `
    --output reports/xdr_endpoint_normalization_validation.json
```

For an mTLS-enabled normalizer service:

```powershell
python scripts\xdr_endpoint_normalization_validate.py `
    --normalizer-url https://normalizer-worker:8092 `
    --mtls-enabled `
    --mtls-ca certs/ca.crt `
    --mtls-client-cert certs/client.crt `
    --mtls-client-key certs/client.key
```

Invalid mTLS configuration exits `2` before fixture loading or report writes.
Offline mode (`--use-normalizer-service 0`) remains certificate-independent and
must not be combined with `--mtls-enabled`.

**Without normalizer service (local validation only):**

```powershell
python scripts\xdr_endpoint_normalization_validate.py `
    --fixture-dir tests/fixtures/endpoint `
    --use-normalizer-service 0 `
    --output reports/xdr_endpoint_normalization_validation.json
```

---

## Expected Pass Criteria

All of the following must be true for a PASS:

| Check | Expected |
|---|---|
| `fixtures_loaded` | `true` — at least 1 fixture loaded |
| `all_required_fields_present` | `true` — no missing normalized fields |
| `all_event_types_valid` | `true` — all event_types in valid set |
| `normalization_idempotent` | `true` — same event normalized twice → same output |
| `no_duplicate_normalized_ids` | `true` — all fixtures have distinct event IDs |
| `nested_sections_present` | `true` — process, network, dns, file, auth all present |
| `all_fixtures_normalized_ok` | `true` — zero failures across all fixtures |

The report is written to `reports/xdr_endpoint_normalization_validation.json`.

---

## Running Full Validation Suite After Endpoint Changes

Run these commands in order:

```powershell
# 1. Docker compose config
docker compose config --quiet

# 2. Endpoint normalization validation
python scripts\xdr_endpoint_normalization_validate.py `
    --output reports/xdr_endpoint_normalization_validation.json

# 3. Contract validation (verify existing contracts unaffected)
python scripts\xdr_contract_validate.py `
    --output reports/xdr_contract_validation.json

# 4. Event flow resilience (verify identity-cloud flow unaffected)
python scripts\xdr_event_flow_resilience_validate.py `
    --replays 3 --restart-services 1 --send-malformed 1 `
    --output reports/xdr_event_flow_resilience_validation.json

# 5. Mini soak on identity-cloud (confirm no regression)
python scripts\xdr_correlation_soak.py `
    --duration-minutes 5 --batch-size 5000 --sleep-ms 100 `
    --output reports/xdr_correlation_endpoint_patch_check.json
```

A STOP condition is reached if any step fails. Do not proceed to the next step if any check returns FAIL.

---

## Normalizer Metrics to Check After Changes

```powershell
curl http://127.0.0.1:8092/metrics
```

Watch for:
- `malformed` not increasing abnormally when sending valid endpoint fixtures
- `forwarded` increasing when endpoint fixtures are sent
- `consumer_errors` = 0
- `goroutines` stable
- `heap_alloc_mb` stable

---

## Shadow-Only Constraints

The following are forbidden during the endpoint normalization phase:

- Do NOT change `XDR_CORRELATION_SCOPE` to include endpoint
- Do NOT promote endpoint correlation to active without completing the gates in `docs/architecture/endpoint-shadow-correlation-plan.md`
- Do NOT modify the correlation-worker's `correlateIdentityCloud()` function for endpoint rules
- Do NOT add response actions, containment, or kernel-mode instrumentation
- Do NOT treat endpoint normalization validation as cutover authorization

The endpoint domain will remain shadow-only until all promotion gates pass.

---

## Troubleshooting

| Symptom | Investigation |
|---|---|
| `fixtures_loaded: false` | Check `tests/fixtures/endpoint/` directory exists and contains `.json` files |
| `normalization returned None` | Fixture is missing `ts`, `event_type`, or `host` field |
| `normalization_idempotent: false` | `normalize_endpoint_local()` has non-deterministic behavior — check for timestamp generation inside normalization |
| `normalizer service` returns `malformed > 0` | Fixture fails normalizer's `missing_required_fields` check — verify `telemetry_type`, `event_type`, `ts` are present |
| `all_required_fields_present: false` | A required normalized field is null/empty — check mapping rules in `endpoint-normalization-rules.md` |
| Mini soak FAIL after changes | Endpoint normalization code path is interfering with identity-cloud events — check the `if telemetryType == "endpoint"` routing guard in `normalize()` |
