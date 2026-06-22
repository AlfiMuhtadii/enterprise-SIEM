# Live Pipeline Recovery Runbook

**Audience:** Anyone running `demo_causal_verify.py` or `validate_live_xdr_pipeline.py` and seeing failures.
**Scope:** Local demo environment recovery only. Not a production ops runbook.

---

## Quick Orientation

The full strangler pipeline involves three layers. All three must be healthy:

| Layer | Services | Start command |
|---|---|---|
| Infrastructure | Redpanda, PostgreSQL, OpenSearch, Qdrant, ClickHouse, Grafana | `docker compose up -d` |
| Go + Python pipeline | ingestion-gateway, normalizer-worker, correlation-worker, alert-writer-service, incident-builder-service, ai-rag-service | `docker compose --profile strangler up -d` |
| Laravel application | app, queue worker, scheduler | `php artisan serve` + `php artisan queue:work` |

The causal proof verifier (`demo_causal_verify.py`) only needs layers 1 and 2.

---

## 1. Pipeline Readiness Failure

**Symptom:** `python scripts/demo_causal_verify.py` prints `FAIL: pipeline not ready.` at step 1.

**First step — run the readiness validator directly:**

```powershell
python scripts/validate_live_xdr_pipeline.py
```

This runs 9 checks and prints which ones fail. Example output for a healthy pipeline:

```
[1/9] ingestion-gateway /health          PASS  HTTP 200
[2/9] normalizer-worker /health          PASS  HTTP 200
[3/9] correlation-worker /health         PASS  HTTP 200
[4/9] alert-writer-service /health       PASS  HTTP 200
[5/9] incident-builder /health           PASS  HTTP 200
[6/9] Redpanda REST API reachable        PASS  HTTP 200
[7/9] Required topics exist              PASS  telemetry.raw,telemetry.normalized,xdr.alerts
[8/9] XDR_CORRELATION_EVENT_LOOP_ENABLED PASS  true
[9/9] XDR_EVENT_LOOP_ENABLED             PASS  true

LIVE_PIPELINE_READY=true
```

**If a service health check fails:**

```powershell
# Check which containers are running
docker compose --profile strangler ps

# Start missing services
docker compose --profile strangler up -d

# Check logs for a failing service
docker compose --profile strangler logs --tail=50 normalizer-worker
docker compose --profile strangler logs --tail=50 correlation-worker
docker compose --profile strangler logs --tail=50 alert-writer-service
```

**If env loop flags are missing:**

Ensure `.env` contains:
```
XDR_CORRELATION_EVENT_LOOP_ENABLED=true
XDR_EVENT_LOOP_ENABLED=true
```

These are two independent flags for two separate services. Both must be true for the full pipeline to run end-to-end.

| Flag | Service | Effect |
|---|---|---|
| `XDR_CORRELATION_EVENT_LOOP_ENABLED` | correlation-worker (Go) | Consumes `telemetry.normalized`, produces `xdr.alerts` |
| `XDR_EVENT_LOOP_ENABLED` | alert-writer-service (Python) | Consumes `xdr.alerts`, writes `security_alerts` |

---

## 2. Stale Docker Container / Network State

**Symptom:** Service is shown as running but cannot reach `postgres` or `redpanda`.

**Cause:** The container was created before the Docker network was set up, or the network was recreated and the container retained the old network reference.

**Diagnosis:**

```powershell
# Check container IP — empty IP means the container lost its network
docker inspect detector-xdr-alert-writer | findstr IPAddress

# Check if container can reach postgres
docker compose exec alert-writer-service ping postgres -c 2
```

**Recovery:**

```powershell
# Remove the stale container
docker compose --profile strangler rm -f alert-writer-service

# Recreate it
docker compose --profile strangler up -d alert-writer-service
```

For a full reset of all strangler services:

```powershell
docker compose --profile strangler down
docker compose --profile strangler up -d
```

---

## 3. Missing Redpanda Topics

**Symptom:** Check 7 fails: `Required topics exist — FAIL`.

**Check what topics exist:**

```powershell
docker compose exec redpanda rpk topic list --brokers=redpanda:9092
```

**Required topics:**

| Topic | Producer | Consumer |
|---|---|---|
| `telemetry.raw` | ingestion-gateway | normalizer-worker |
| `telemetry.normalized` | normalizer-worker | correlation-worker |
| `xdr.alerts` | correlation-worker | alert-writer-service |
| `alerts.created` | alert-writer-service | incident-builder-service |

**Create missing topics:**

```powershell
docker compose exec redpanda rpk topic create telemetry.raw --brokers=redpanda:9092
docker compose exec redpanda rpk topic create telemetry.normalized --brokers=redpanda:9092
docker compose exec redpanda rpk topic create xdr.alerts --brokers=redpanda:9092
docker compose exec redpanda rpk topic create alerts.created --brokers=redpanda:9092
```

Topics are created automatically on first message by the services, but explicit creation avoids a startup race condition.

After creating topics, restart the pipeline services:

```powershell
docker compose --profile strangler restart
```

---

## 4. Stale Consumer Group Offsets

**Symptom:**
- Events are accepted by ingestion-gateway (`accepted=5`)
- `telemetry.raw` contains events (visible via `rpk topic describe`)
- `telemetry.normalized` is empty
- normalizer-worker metrics show `processed=0` or `consumer_recreate_count` is high
- `rpk group describe normalizer-worker-v1` shows `TOTAL-LAG=0` even though events were sent

**Cause:** Redpanda stores committed consumer group offsets. After a Redpanda volume wipe or full restart, the log starts fresh (offset 0), but the group's committed offset may point to a higher position that no longer exists. Pandaproxy returns `offset_out_of_range`. The Go services recover by recreating with `auto.offset.reset=earliest`, but if the group name is reused, the committed offset persists and the service loops between "recover" and "commit stale offset".

**Diagnosis:**

```powershell
# Check normalizer consumer group lag
docker compose exec redpanda rpk group describe normalizer-worker-v1 --brokers=redpanda:9092

# Check normalizer metrics
curl http://localhost:8092/metrics

# TOTAL-LAG=0 but processed=0 = stale committed offset at topic end
```

**Recovery — rotate the consumer group name:**

1. Get a fresh millisecond timestamp:

```powershell
# PowerShell
[DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
```

2. Update `.env` with the new group names:

```env
XDR_NORMALIZER_GROUP=normalizer-worker-v1-<new_timestamp>
XDR_CORRELATION_GROUP=correlation-worker-v1-<new_timestamp>
```

Example:
```env
XDR_NORMALIZER_GROUP=normalizer-worker-v1-1782200000000
XDR_CORRELATION_GROUP=correlation-worker-v1-1782200000000
```

3. Restart the affected services:

```powershell
docker compose --profile strangler up -d normalizer-worker correlation-worker
```

4. Verify the new group name appears in logs:

```powershell
docker compose --profile strangler logs --tail=10 normalizer-worker
# Expected: normalizer consuming topic=telemetry.raw group=normalizer-worker-v1-1782200000000
```

**Why Python services (alert-writer, incident-builder) do not need manual rotation:**
Both services use millisecond-resolution timestamps in their group names (`alert-writer-v1-{ms}`) and automatically recover from `offset_out_of_range` by deleting the stale consumer instance and recreating with a fresh ms-resolution group name. See `services/alert-writer-service/main.py:_is_offset_range_error()` and `consumer_create()`.

---

## 5. Static Event ID / Duplicate AlertID Issue

**Symptom:** First demo run shows `LIVE_CAUSAL_PROOF=PASS`, but all subsequent runs show `LIVE_CAUSAL_PROOF=FAIL`. Alert-writer metrics show `duplicates` increasing but no new `alerts_written`.

**Cause (fixed as of commit 2f05e44):** The Go correlation worker computes alert fingerprints as:

```
AlertID = sha256(alertType + "|" + actor + "|" + sorted(event_ids))[:40]
```

If the scenario JSONL uses static `event_id` values (e.g., `atk-cloud-001`), every run produces identical event_ids, identical `AlertID`s, and alert-writer deduplicates them — only the first run writes to DB.

**Fix applied:** `scripts/demo_feed.py` `tag_events()` now replaces `event_id` with `trace_id`:

```python
ev["trace_id"] = f"{demo_run_id}-trace-{seq}"   # unique per run per event
ev["event_id"] = ev["trace_id"]                  # replaces static scenario event_id
ev["source_event_id"] = original_event_id        # original preserved here
```

This ensures each run generates unique event_ids, unique alert fingerprints, and new non-duplicate alerts in the database.

**If you are still seeing duplicates after this fix:** Confirm you are running the latest `demo_feed.py` (commit 2f05e44 or later) and that the tagged event file in `storage/logs/` contains trace_id-based event_ids.

---

## 6. Layer-by-Layer Diagnosis

Use this to locate exactly where a stuck pipeline is broken.

### Check 1 — Did ingestion-gateway accept the events?

```powershell
curl http://localhost:8091/metrics
# Look for: "accepted": N  (should increase after demo_feed.py)
# If "publish_errors" > 0: Redpanda is unreachable from ingestion-gateway
```

### Check 2 — Are events in telemetry.raw?

```powershell
docker compose exec redpanda rpk group describe normalizer-worker-v1 --brokers=redpanda:9092
# TOTAL-LAG > 0: normalizer has not consumed the events yet (processing in progress)
# TOTAL-LAG = 0 but normalizer processed=0: stale committed offset (see section 4)
```

### Check 3 — Did normalizer process and forward events?

```powershell
curl http://localhost:8092/metrics
# processed=N, forwarded=N: normalizer consumed and forwarded
# forwarded=0 with processed>0: publish_errors to telemetry.normalized
# consumer_recreate_count=N (growing): repeated offset_out_of_range — see section 4
```

### Check 4 — Are there events in telemetry.normalized?

```powershell
docker compose exec redpanda rpk group describe correlation-worker-v1 --brokers=redpanda:9092
# TOTAL-LAG > 0: correlation has not processed them yet
# TOTAL-LAG = 0 but alerts=0: stale committed offset or events did not match any rule
```

### Check 5 — Did correlation-worker generate alerts?

```powershell
curl http://localhost:8093/metrics
# alerts=N, published=N: alerts generated and published to xdr.alerts
# processed=N but alerts=0: events did not match any rule in XDR_CORRELATION_SCOPE=identity-cloud
#   -> Verify the events have telemetry_type=cloud or telemetry_type=identity
```

### Check 6 — Are there alerts in xdr.alerts?

```powershell
docker compose exec redpanda rpk group describe alert-writer-v1-<group> --brokers=redpanda:9092
# TOTAL-LAG > 0: alert-writer has not consumed the alerts yet
```

### Check 7 — Did alert-writer write to security_alerts?

```powershell
curl http://localhost:8095/metrics
# alerts_written=N: alerts written to PostgreSQL
# duplicates=N: same alert fingerprint already in DB (see section 5)
# postgres_failures>0: DB connection issue
# opensearch_failures>0: OpenSearch unavailable (non-blocking; alerts still written to PostgreSQL)
```

### Check 8 — Are alerts in the database with correct demo_run_id?

```powershell
php artisan tinker --execute="
DB::table('security_alerts')
  ->where('evidence', 'like', '%demo_run_id%')
  ->orderBy('created_at', 'desc')
  ->limit(5)
  ->get(['id','alert_type','created_at'])
  ->each(function(\$r){ echo \$r->id.' '.\$r->alert_type.' '.\$r->created_at.PHP_EOL; });
"
```

### Check 9 — Does the report find the alert?

```powershell
php artisan security:alerts-report --demo-run=<demo_run_id>
# FIELD_MATCH=PASS: field-level match succeeded
# FIELD_MATCH=WARN: time-window match only (demo_run_id not in evidence)
# FIELD_MATCH=FAIL: no alerts found by either method
```

---

## Full Reset Procedure

If the pipeline is in an unknown state and you want to start fresh:

```powershell
# 1. Stop all services
docker compose --profile strangler down

# 2. (Optional) Wipe Redpanda data to clear all topics and committed offsets
docker compose down -v
# WARNING: -v removes ALL Docker volumes including PostgreSQL data

# 3. Restart infrastructure
docker compose up -d

# 4. Wait for Redpanda to be healthy (10-15 seconds)
docker compose exec redpanda rpk cluster health --brokers=redpanda:9092

# 5. Create required topics
docker compose exec redpanda rpk topic create telemetry.raw telemetry.normalized xdr.alerts alerts.created --brokers=redpanda:9092

# 6. Rotate group names to avoid inheriting any stale offsets
# Edit .env:
#   XDR_NORMALIZER_GROUP=normalizer-worker-v1-<new_timestamp>
#   XDR_CORRELATION_GROUP=correlation-worker-v1-<new_timestamp>

# 7. Start pipeline services
docker compose --profile strangler up -d

# 8. Migrate and seed (if PostgreSQL was wiped)
php artisan migrate:fresh --force

# 9. Verify
python scripts/validate_live_xdr_pipeline.py

# 10. Run demo
python scripts/demo_causal_verify.py --timeout-seconds 120
```

---

*See also:*
- *[Demo Causal Proof](DEMO_CAUSAL_PROOF.md) — what a successful run looks like*
- *[Limitations and Corrected Claims](LIMITATIONS_AND_CLAIMS.md) — what the proof covers*
- *`scripts/validate_live_xdr_pipeline.py` — automated readiness checker*
