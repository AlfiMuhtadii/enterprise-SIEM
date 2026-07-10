# log-connector-gcp-audit

CONNECTOR-FRAMEWORK phase 6: watches a local directory for GCP Cloud Audit
Log export files — written by a GCP log sink to Cloud Storage as
newline-delimited JSON (NDJSON, one `LogEntry` object per line) — and
forwards every entry through the existing HMAC-signed `ingestion-gateway`
`/v1/ingest` endpoint.

## Scope: file-based, not live GCP Logging API polling

Same scope decision as `log-connector-cloudtrail` and
`log-connector-guardduty`: this connector is **file-based ingestion of
already-exported logs**, not live Cloud Logging API polling. Point it at a
local directory an operator keeps synced from the GCS bucket a log sink
writes to (e.g. via `gsutil rsync` or `gcloud storage rsync` on a cron).
Live API polling needs GCP credentials this environment cannot exercise or
verify.

## Format

GCP log sinks write NDJSON (one `LogEntry` per line) to Cloud Storage,
plain JSON by default (gzip only if the sink destination is configured that
way — both are auto-detected here via the gzip magic bytes, same as the
other connectors). This is a materially different payload shape from both
CloudTrail (flat record) and GuardDuty (`Service.Action` variants): GCP
audit data is nested inside `protoPayload` (a
`google.cloud.audit.AuditLog`), so this connector has its own
`internal/gcpaudit` parser.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `XDR_GCP_AUDIT_WATCH_DIR` | `./gcp-audit-logs` | Directory to recursively scan for `.json`/`.json.gz`/`.jsonl`/`.jsonl.gz` export files |
| `XDR_GCP_AUDIT_METRICS_ADDR` | `:8099` | `/health` + `/metrics` listen address |
| `XDR_GCP_AUDIT_POLL_SECONDS` | `30` | Scan interval |
| `XDR_INGEST_URL` | `http://127.0.0.1:8091/v1/ingest` | ingestion-gateway target |
| `XDR_INGEST_SECRET` | `dev-secret-change-me` | HMAC secret shared with ingestion-gateway |
| `XDR_GCP_AUDIT_TENANT_ID` | (empty) | tenant_id stamped on every forwarded event |
| `XDR_GCP_AUDIT_REQUIRE_TENANT` | `false` | CONN-UNTENANTED-INGEST: if `true` and `XDR_GCP_AUDIT_TENANT_ID` is empty, the connector refuses to start rather than forwarding unattributed telemetry |
| `XDR_GCP_AUDIT_BATCH_SIZE` | `100` | events per forwarded batch |
| `XDR_GCP_AUDIT_FORWARD_MAX_RETRIES` | `3` | CONN-DELIVERY-LOSS: max forward attempts per batch before the source file is left unprocessed for retry on the next scan |
| `XDR_GCP_AUDIT_FORWARD_RETRY_BASE_MS` | `200` | CONN-DELIVERY-LOSS: initial retry backoff (doubles each attempt, capped at `_RETRY_MAX_MS`) |
| `XDR_GCP_AUDIT_FORWARD_RETRY_MAX_MS` | `2000` | CONN-DELIVERY-LOSS: retry backoff cap |

## Restart-safe file tracking

Same pattern as the other file connectors: processed file paths persist to
`<watch-dir>/.gcp-audit-connector-state.json` (atomic write-then-rename),
explicitly excluded from re-scanning.

CONN-DELIVERY-LOSS: a file is only marked processed — and the state file
only saved — after every batch derived from that file has been forwarded
successfully (with bounded retry). Each file's batches are delivered
independently of any other file's, never mixed into a shared cross-file
buffer, so a file is either fully acknowledged or left entirely
unprocessed for retry on the next scan; there is no partial-file
checkpoint.

## Field mapping

A parsed log entry becomes `telemetry_type=gcp_audit`, mapped onto the same
canonical field names the normalizer's existing generic fallback envelope
already recognizes — zero `normalizer-worker` changes needed.

| Output field | Source |
|---|---|
| `event_type` / `action` | `protoPayload.methodName` (e.g. `storage.objects.get`) |
| `event_source` | `protoPayload.serviceName` |
| `cloud_account` | `resource.labels.project_id` |
| `user` | `protoPayload.authenticationInfo.principalEmail` |
| `source_ip` | `protoPayload.requestMetadata.callerIp` |
| `result` | `error` if `protoPayload.status` carries a non-empty code/message, else `success` (GCP audit-log convention: an empty status object means the call succeeded) |
| `severity` | top-level `severity` (e.g. `NOTICE`, `ERROR`) |

The full original entry is preserved verbatim under `gcp_audit_entry`
regardless of which fields were promoted.
