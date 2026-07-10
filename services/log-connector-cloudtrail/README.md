# log-connector-cloudtrail

CONNECTOR-FRAMEWORK phase 4: watches a local directory for AWS CloudTrail
log export files — the stable, well-documented `{"Records": [...]}` JSON
format CloudTrail writes to S3, gzip-compressed by default — and forwards
every record through the existing HMAC-signed `ingestion-gateway`
`/v1/ingest` endpoint.

## Scope: file-based, not live S3 API polling

This connector is deliberately scoped to **file-based ingestion of
already-exported CloudTrail logs**. Point it at a local directory an
operator keeps synced from S3 (e.g. via `aws s3 sync s3://my-trail-bucket
./cloudtrail-logs` on a cron, or an existing log-shipper drop directory) —
it recursively watches that directory and picks up new `.json`/`.json.gz`
files as they appear.

**Not implemented, explicitly open scope**: live AWS S3 API polling
(`ListObjectsV2`/`GetObject`). That requires AWS SigV4 request signing and
real AWS credentials — neither of which this environment can exercise or
verify, so rather than ship untested auth code, this phase stays honest
about what it actually does: ingest files already on disk.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `XDR_CLOUDTRAIL_WATCH_DIR` | `./cloudtrail-logs` | Directory to recursively scan for `.json`/`.json.gz` CloudTrail export files |
| `XDR_CLOUDTRAIL_METRICS_ADDR` | `:8097` | `/health` + `/metrics` listen address |
| `XDR_CLOUDTRAIL_POLL_SECONDS` | `30` | Scan interval |
| `XDR_INGEST_URL` | `http://127.0.0.1:8091/v1/ingest` | ingestion-gateway target |
| `XDR_INGEST_SECRET` | `dev-secret-change-me` | HMAC secret shared with ingestion-gateway |
| `XDR_CLOUDTRAIL_TENANT_ID` | (empty) | tenant_id stamped on every forwarded event |
| `XDR_CLOUDTRAIL_BATCH_SIZE` | `100` | events per forwarded batch |

## Restart-safe file tracking

Each processed file's path is recorded in `<watch-dir>/.cloudtrail-connector-state.json`
(atomic write-then-rename), so a restart doesn't re-ingest every file
already forwarded. CloudTrail export files are immutable once written, so
"seen once, never re-read" is the correct semantics — no file modification
tracking is needed.

## Field mapping

A parsed CloudTrail record becomes `telemetry_type=cloudtrail`, mapped
directly onto the same canonical field names the normalizer's existing
generic fallback envelope already recognizes (`source_ip`/`user`/
`cloud_account`/`action`/`result`/`event_source`) — so, like the
config-driven parser registry in `services/log-connector-syslog`, this
connector requires **zero `normalizer-worker` code changes**. The full
original record is preserved verbatim under `cloudtrail_record`.

| Output field | Source |
|---|---|
| `event_type` / `action` | `eventName` |
| `event_source` | `eventSource` |
| `source_ip` | `sourceIPAddress` |
| `user` | `userIdentity.userName`, falling back to `.arn`, then `.principalId` |
| `cloud_account` | `recipientAccountId`, falling back to `userIdentity.accountId` |
| `result` | `errorCode` if present, else `"success"` |
| `aws_region` | `awsRegion` |
