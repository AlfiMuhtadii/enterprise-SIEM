# Threat Intelligence Validation

Operations guide for validating the threat intelligence pipeline. All IOC matches produce shadow alerts only — they do NOT enter the active incident workflow.

---

## What This Validates

| Check | Tool |
|---|---|
| IOC load success (malicious IOCs) | `xdr_threat_intel_validate.py` |
| Duplicate-safe insert (same ioc_id twice = no-op) | `xdr_threat_intel_validate.py` |
| Expiration filtering (expired IOC → null lookup) | `xdr_threat_intel_validate.py` |
| Inactive IOC filtering (active=false → null lookup) | `xdr_threat_intel_validate.py` |
| Benign values not in store (no false matches) | `xdr_threat_intel_validate.py` |
| IOC IP match → shadow alert | `xdr_threat_intel_validate.py` |
| IOC domain match → shadow alert | `xdr_threat_intel_validate.py` |
| IOC file hash match → shadow alert | `xdr_threat_intel_validate.py` |
| No duplicate shadow alerts | `xdr_threat_intel_validate.py` |
| trace_id propagation | `xdr_threat_intel_validate.py` |
| shadow_mode = true on all alerts | `xdr_threat_intel_validate.py` |

---

## Prerequisites

```powershell
# Optional: start IOC lookup service for Go integration
python scripts\xdr_ioc_store.py `
    --serve `
    --port 8097 `
    --db storage/threat_intel/iocs.db

# Then set env var on correlation-worker:
# XDR_IOC_LOOKUP_URL=http://127.0.0.1:8097
```

The validation script can run without the IOC service (Python-side enrichment simulation).

---

## Running Threat Intel Validation

```powershell
python scripts\xdr_threat_intel_validate.py `
    --malicious-iocs tests/fixtures/threat_intel/malicious_iocs.json `
    --benign-iocs tests/fixtures/threat_intel/benign_iocs.json `
    --shadow-fixture-dir tests/fixtures/endpoint_shadow/suspicious `
    --output reports/xdr_threat_intel_validation.json
```

**With correlation-worker service validation:**

```powershell
python scripts\xdr_threat_intel_validate.py `
    --use-correlation-service 1 `
    --correlation-url http://127.0.0.1:8093 `
    --output reports/xdr_threat_intel_validation.json
```

For an mTLS-enabled correlation-worker, change the URL to HTTPS and add:

```powershell
    --mtls-enabled `
    --mtls-ca certs/ca.crt `
    --mtls-client-cert certs/client.crt `
    --mtls-client-key certs/client.key
```

Invalid mTLS configuration exits `2` before fixture loading, SQLite mutation, or
report writes. The optional `--ioc-service-url` is not an HTTP target in this
validator and does not receive the correlation-worker client identity.

---

## Expected Pass Criteria

| Check | Expected |
|---|---|
| `ioc_load_success` | `true` — malicious IOCs loaded without error |
| `duplicate_insert_safe` | `true` — second insert of same ioc_id = no-op |
| `expiration_filtered` | `true` — expired IOC lookup returns null |
| `inactive_filtered` | `true` — inactive IOC lookup returns null |
| `benign_not_matched` | `true` — benign values not in store |
| `ioc_ip_match_generates_alert` | `true` |
| `ioc_domain_match_generates_alert` | `true` |
| `ioc_hash_match_generates_alert` | `true` |
| `no_duplicate_alerts` | `true` |
| `trace_id_propagated` | `true` |
| `all_alerts_shadow_mode` | `true` |

---

## Replay Workflow

IOC store can be rebuilt at any time:

```powershell
# Clear store
Remove-Item storage\threat_intel\iocs.db -ErrorAction SilentlyContinue

# Reload from fixtures
python scripts\xdr_threat_intel_validate.py `
    --reload-only `
    --malicious-iocs tests/fixtures/threat_intel/malicious_iocs.json `
    --output reports/xdr_threat_intel_validation.json
```

Replaying the same IOC batch produces the same store state (idempotent).

---

## TTL Validation

IOCs with `ttl_seconds` set compute `expires_at = created_at + ttl_seconds`. To verify:

```powershell
python scripts\xdr_threat_intel_validate.py `
    --test-expiration `
    --output reports/xdr_threat_intel_validation.json
```

An IOC with `ttl_seconds = 1` inserted 2 seconds ago should return null on lookup.

---

## IOC Lookup Service

The lookup service serves IOC queries for Go correlation-worker integration:

```powershell
# Start service (run in separate terminal or background process)
python scripts\xdr_ioc_store.py --serve --port 8097

# Test lookup
curl "http://127.0.0.1:8097/v1/lookup?type=ip&value=185.220.101.200"
curl "http://127.0.0.1:8097/v1/lookup?type=domain&value=randomlookingdomainc2beacon12345.dynamic.invalid"
curl "http://127.0.0.1:8097/health"
curl "http://127.0.0.1:8097/metrics"
```

---

## Forbidden Changes

- Do NOT wire `xdr.alerts.shadow.endpoint` to alert-writer-service or incident-builder-service
- Do NOT add automated response actions triggered by IOC matches
- Do NOT implement host isolation, quarantine, or containment based on IOC matches
- Do NOT promote IOC shadow alerts into the active incident workflow
- Do NOT configure the IOC store to be writable from the Go correlation-worker
- Do NOT treat IOC validation PASS as cutover authorization for endpoint domain

---

## Shadow-Only Constraints

All IOC enrichment is shadow-only:
- IOC matches publish to `xdr.alerts.shadow.endpoint` with `shadow_mode = true`
- The IOC lookup service (`XDR_IOC_LOOKUP_URL`) is not required for identity-cloud active correlation
- Setting `XDR_IOC_LOOKUP_URL=""` disables IOC enrichment without affecting any active pipeline
- The IOC store is independent of identity-cloud soak validation artifacts
