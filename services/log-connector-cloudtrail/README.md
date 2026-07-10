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
| `XDR_CLOUDTRAIL_REQUIRE_TENANT` | `false` | CONN-UNTENANTED-INGEST: if `true` and `XDR_CLOUDTRAIL_TENANT_ID` is empty, the connector refuses to start rather than forwarding unattributed telemetry |
| `XDR_CLOUDTRAIL_BATCH_SIZE` | `100` | events per forwarded batch |
| `XDR_CLOUDTRAIL_FORWARD_MAX_RETRIES` | `3` | CONN-DELIVERY-LOSS: max forward attempts per batch before the source file is left unprocessed for retry on the next scan |
| `XDR_CLOUDTRAIL_FORWARD_RETRY_BASE_MS` | `200` | CONN-DELIVERY-LOSS: initial retry backoff (doubles each attempt, capped at `_RETRY_MAX_MS`) |
| `XDR_CLOUDTRAIL_FORWARD_RETRY_MAX_MS` | `2000` | CONN-DELIVERY-LOSS: retry backoff cap |
| `XDR_CLOUDTRAIL_MAX_FILE_BYTES` | `104857600` (100 MiB) | CONN-UNBOUNDED-FILE: on-disk (compressed) file size ceiling; a file over this is quarantined without being read further. `0` disables the bound |
| `XDR_CLOUDTRAIL_MAX_EXPANDED_BYTES` | `524288000` (500 MiB) | CONN-UNBOUNDED-FILE: gzip-decompressed size ceiling — the compression-bomb defense; exceeding it quarantines the file. `0` disables the bound |
| `XDR_CLOUDTRAIL_MAX_RECORD_BYTES` | `1048576` (1 MiB) | CONN-UNBOUNDED-FILE: single-record size ceiling; an oversized record is skipped and counted (`oversized_records_skipped` metric) but does not quarantine the rest of the file. `0` disables the bound |

## Restart-safe file tracking

Each processed file's path is recorded in `<watch-dir>/.cloudtrail-connector-state.json`
(atomic write-then-rename), so a restart doesn't re-ingest every file
already forwarded. CloudTrail export files are immutable once written, so
"seen once, never re-read" is the correct semantics — no file modification
tracking is needed.

CONN-DELIVERY-LOSS: a file is only marked processed — and the state file
only saved — after every batch derived from that file has been forwarded
successfully (with bounded retry). Each file's batches are delivered
independently of any other file's, never mixed into a shared cross-file
buffer, so a file is either fully acknowledged or left entirely
unprocessed for retry on the next scan; there is no partial-file
checkpoint.

## Size ceilings and quarantine (CONN-UNBOUNDED-FILE)

`os.ReadFile()`/gzip decompression previously had no ceiling at all, so one
oversized export file — or a small, highly-compressible file that expands
to gigabytes once decompressed (a compression bomb) — could exhaust memory
and restart-loop the connector. A file that exceeds `XDR_CLOUDTRAIL_MAX_FILE_BYTES`
(on disk) or `XDR_CLOUDTRAIL_MAX_EXPANDED_BYTES` (after gzip decompression)
is **quarantined**: left in place untouched (never deleted/moved), recorded
in `<watch-dir>/.cloudtrail-connector-quarantine.jsonl` (one JSON line per
rejection — `path`/`reason`/`quarantined_at`, an append-only durable audit
trail an operator can inspect for recovery), and never re-attempted on
later scans or after a restart (the quarantine log is reloaded at startup,
the same way `.cloudtrail-connector-state.json` is). A single oversized
record within an otherwise-acceptable file is handled more leniently: it is
skipped and counted in the `oversized_records_skipped` metric, but the rest
of the file's records are still parsed and forwarded normally — a `0`
`XDR_CLOUDTRAIL_MAX_RECORD_BYTES` in one huge event doesn't need to sink an
entire export. Malformed (non-JSON) files are a separate, unrelated
concern — parsed content that fails to decode as valid JSON is still
retried on every scan, matching CONN-DELIVERY-LOSS's philosophy: a
malformed file is not a resource-exhaustion attack, so it's left for the
existing `parse_errors` metric and operator investigation, not quarantined.

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
