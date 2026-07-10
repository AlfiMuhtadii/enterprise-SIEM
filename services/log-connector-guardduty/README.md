# log-connector-guardduty

CONNECTOR-FRAMEWORK phase 5: watches a local directory for AWS GuardDuty
finding export files — GuardDuty's native "export findings" feature (NDJSON,
one finding object per line, gzip-compressed by default) — and forwards
every finding through the existing HMAC-signed `ingestion-gateway`
`/v1/ingest` endpoint.

## Scope: file-based, not live GuardDuty API polling

Same scope decision as `log-connector-cloudtrail`: this connector is
**file-based ingestion of already-exported findings**, not live
`GetFindings`/`ListFindings` API polling. Point it at a local directory an
operator keeps synced from the S3 bucket GuardDuty's export feature writes
to (e.g. via `aws s3 sync s3://my-guardduty-bucket ./guardduty-findings` on
a cron). Live API polling needs AWS credentials this environment cannot
exercise or verify.

## Format difference from CloudTrail

CloudTrail exports one `{"Records": [...]}` JSON array per file. GuardDuty
exports **one finding JSON object per line** (NDJSON) — a materially
different shape, so this connector has its own `internal/guardduty` parser
rather than reusing CloudTrail's.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `XDR_GUARDDUTY_WATCH_DIR` | `./guardduty-findings` | Directory to recursively scan for `.json`/`.json.gz`/`.jsonl`/`.jsonl.gz` finding export files |
| `XDR_GUARDDUTY_METRICS_ADDR` | `:8098` | `/health` + `/metrics` listen address |
| `XDR_GUARDDUTY_POLL_SECONDS` | `30` | Scan interval |
| `XDR_INGEST_URL` | `http://127.0.0.1:8091/v1/ingest` | ingestion-gateway target |
| `XDR_INGEST_SECRET` | `dev-secret-change-me` | HMAC secret shared with ingestion-gateway |
| `XDR_GUARDDUTY_TENANT_ID` | (empty) | tenant_id stamped on every forwarded event |
| `XDR_GUARDDUTY_REQUIRE_TENANT` | `false` | CONN-UNTENANTED-INGEST: if `true` and `XDR_GUARDDUTY_TENANT_ID` is empty, the connector refuses to start rather than forwarding unattributed telemetry |
| `XDR_GUARDDUTY_BATCH_SIZE` | `100` | events per forwarded batch |
| `XDR_GUARDDUTY_FORWARD_MAX_RETRIES` | `3` | CONN-DELIVERY-LOSS: max forward attempts per batch before the source file is left unprocessed for retry on the next scan |
| `XDR_GUARDDUTY_FORWARD_RETRY_BASE_MS` | `200` | CONN-DELIVERY-LOSS: initial retry backoff (doubles each attempt, capped at `_RETRY_MAX_MS`) |
| `XDR_GUARDDUTY_FORWARD_RETRY_MAX_MS` | `2000` | CONN-DELIVERY-LOSS: retry backoff cap |
| `XDR_GUARDDUTY_MAX_FILE_BYTES` | `104857600` (100 MiB) | CONN-UNBOUNDED-FILE: on-disk (compressed) file size ceiling; a file over this is quarantined without being read further. `0` disables the bound |
| `XDR_GUARDDUTY_MAX_EXPANDED_BYTES` | `524288000` (500 MiB) | CONN-UNBOUNDED-FILE: gzip-decompressed size ceiling — the compression-bomb defense; exceeding it quarantines the file. `0` disables the bound |
| `XDR_GUARDDUTY_MAX_RECORD_BYTES` | `1048576` (1 MiB) | CONN-UNBOUNDED-FILE: single NDJSON-line size ceiling; an oversized line is skipped and counted (`oversized_records_skipped` metric) but does not quarantine the rest of the file. `0` disables the bound |

## Restart-safe file tracking

Same pattern as `log-connector-cloudtrail`: processed file paths persist to
`<watch-dir>/.guardduty-connector-state.json` (atomic write-then-rename).
The state file itself is explicitly excluded from re-scanning (a bug caught
via test in the CloudTrail connector, fixed proactively here from the start).

CONN-DELIVERY-LOSS: a file is only marked processed — and the state file
only saved — after every batch derived from that file has been forwarded
successfully (with bounded retry). Each file's batches are delivered
independently of any other file's, never mixed into a shared cross-file
buffer, so a file is either fully acknowledged or left entirely
unprocessed for retry on the next scan; there is no partial-file
checkpoint.

## Size ceilings and quarantine (CONN-UNBOUNDED-FILE)

Same mechanism as `log-connector-cloudtrail`: a file over
`XDR_GUARDDUTY_MAX_FILE_BYTES` (on disk) or `XDR_GUARDDUTY_MAX_EXPANDED_BYTES`
(after gzip decompression — the compression-bomb defense) is quarantined —
left in place, recorded in `<watch-dir>/.guardduty-connector-quarantine.jsonl`
(append-only, `path`/`reason`/`quarantined_at`), and never re-attempted
after a restart. Since this connector's own extension filter
(`hasFindingsExtension`) already accepts `.jsonl`, the quarantine log itself
is explicitly excluded from scanning the same way the state file is. A
single oversized NDJSON line within an otherwise-acceptable file is skipped
and counted (`oversized_records_skipped`) without invalidating the rest of
the file. Malformed (non-JSON) lines remain a separate, pre-existing
concern — GuardDuty's NDJSON parser already skips a poison line without
aborting the file, so this is unaffected by CONN-UNBOUNDED-FILE.

## Field mapping

A parsed finding becomes `telemetry_type=guardduty`, mapped onto the same
canonical field names the normalizer's generic fallback envelope already
recognizes — zero `normalizer-worker` changes needed, same pattern as every
other connector in this framework.

| Output field | Source |
|---|---|
| `event_type` / `action` | `Type` (e.g. `UnauthorizedAccess:EC2/SSHBruteForce`) |
| `cloud_account` | `AccountId` |
| `source_ip` | Best-effort extraction from `Service.Action.*` — GuardDuty findings carry different action shapes per type (`NetworkConnectionAction`, `AwsApiCallAction`, `DnsRequestAction`, `KubernetesApiCallAction`, `PortProbeAction`); checks the common ones, returns empty if none match rather than guessing |
| `aws_region` | `Region` |
| `risk_score` | `Severity` |
| `message` | `Title` |

The full original finding is preserved verbatim under `guardduty_finding`
regardless of which fields were promoted.
