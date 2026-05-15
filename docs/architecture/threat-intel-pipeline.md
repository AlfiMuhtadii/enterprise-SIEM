# Threat Intelligence Pipeline

Foundation architecture for the shadow-only IOC enrichment pipeline. IOC matches produce shadow alerts to `xdr.alerts.shadow.endpoint` only — they do NOT enter the active incident workflow.

**Status:** foundation / shadow-only  
**Storage:** SQLite (`storage/threat_intel/iocs.db`)  
**Lookup API:** `scripts/xdr_ioc_store.py --serve` (port 8097)  
**Enrichment scope:** endpoint shadow correlation only  

---

## IOC Ingestion Flow

```
[IOC Feed / Fixture File]
    │  JSON batch (ioc-batch.v1 schema)
    ▼
[xdr_ioc_store.py: IoCStore.load_batch()]
    │  Idempotent insert by ioc_id
    │  Dedup by (ioc_type, value) — same indicator, different id → update
    │  Expiration: compute expires_at from ttl_seconds if not set
    ▼
[SQLite: storage/threat_intel/iocs.db]
    │  Indexed by (ioc_type, value, active)
    │  Expired IOCs retained but filtered on lookup
    ▼
[IOC Lookup Service: http://127.0.0.1:8097]
    │  GET /v1/lookup?type=ip&value=X
    │  Returns matched IOC or {"matched": false}
```

---

## Normalization Flow

Each IOC is stored with:
- `normalization_version: "ioc-v1"` 
- `active: true` (default)
- `expires_at` computed from `ttl_seconds` if `expires_at` not provided
- Tags stored as JSON array
- Values normalized to lowercase for ip, domain, file_hash types

---

## Lookup Flow

```
GET /v1/lookup?type=ip&value=185.220.101.200
    │
    ▼
[IoCStore.lookup(ioc_type, value)]
    │  Filter: active=1 AND (expires_at IS NULL OR expires_at > NOW())
    │  If no match → {"matched": false}
    │  If match → full IOC record with matched=true
    ▼
[Response to correlation-worker or validator]
```

Expired and inactive IOCs are excluded from lookup results. They remain in the store for audit purposes.

---

## Endpoint Shadow Enrichment Flow

```
[telemetry.normalized]
    │  (endpoint events from normalizer-worker)
    ▼
[correlation-worker: consumeOnce()]
    │  correlateEndpointShadowAll(rawMaps, iocLookupURL)
    │    ├── correlateEndpointShadow()  (6 existing shadow rules)
    │    └── correlateEndpointShadowIOC()  (3 IOC enrichment rules)
    │          ├── ruleIOCIPMatch:     checks network.destination_ip, network.source_ip
    │          ├── ruleIOCDomainMatch: checks dns.domain
    │          └── ruleIOCHashMatch:   checks process.hash, file.hash
    │  All results deduplicated before publish
    ▼
[Redpanda: xdr.alerts.shadow.endpoint]
    │  shadow_mode = true
    │  NOT consumed by alert-writer-service
    │  NOT consumed by incident-builder-service
```

IOC enrichment only runs when `XDR_IOC_LOOKUP_URL` is configured. If the IOC service is unavailable, the three IOC rules produce no alerts (graceful degradation). Existing shadow rules are unaffected.

---

## Replay and Idempotency

- IOC inserts are idempotent by `ioc_id` — replaying the same IOC batch produces the same store state
- Same `(ioc_type, value)` from a different source updates the existing record
- SQLite provides durable, local, replay-safe storage
- The IOC store can be cleared and rebuilt from fixtures at any time
- IOC shadow alerts use deterministic `alert_id` (SHA-256 of rule_id + host + event_id)

---

## Expiration Handling

IOCs are expired by:
1. `expires_at` — explicit timestamp, set by feed or computed from `ttl_seconds`
2. `active = false` — manually deactivated, not by time

Expired IOCs remain in the database (not deleted). They are excluded from lookup results by the `expires_at > NOW()` filter. Expired IOC counts are tracked in `ioc_expired_total`.

Replay safety: replaying expired IOCs does not reactivate them unless the replayed record has a future `expires_at`.

---

## Rollback Considerations

- SQLite file can be deleted and rebuilt from fixtures at any time
- Clearing the store clears all IOC shadow enrichment (no active pipeline impact)
- Go correlation-worker's IOC enrichment is gated on `XDR_IOC_LOOKUP_URL` — set to empty to disable without redeploying
- Disabling IOC enrichment does NOT affect identity-cloud active correlation or existing endpoint shadow rules

---

## Shadow-Only Constraints

- IOC shadow alerts publish to `xdr.alerts.shadow.endpoint` ONLY
- `xdr.alerts.shadow.endpoint` is NOT consumed by alert-writer-service or incident-builder-service
- IOC matches do NOT generate `alerts.created` events
- IOC matches do NOT create incidents
- No automated response actions are triggered by IOC matches
- The IOC store is read-only from the Go correlation-worker's perspective (lookup only, no writes)

---

## Current Limitations (Foundation Phase)

- Single-node SQLite storage (not distributed, not HA)
- No automatic feed ingestion (feeds loaded manually via fixtures or batch load script)
- No deduplication across feeds (same IOC from two feeds = last-write-wins on type+value)
- No IOC scoring decay (confidence does not decay over time automatically)
- URL IOC type is stored but not yet matched against endpoint telemetry (ip/domain/file_hash matching only)
- No TLP (Traffic Light Protocol) classification enforcement
