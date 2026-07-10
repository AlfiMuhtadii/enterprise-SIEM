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
| `XDR_GUARDDUTY_BATCH_SIZE` | `100` | events per forwarded batch |

## Restart-safe file tracking

Same pattern as `log-connector-cloudtrail`: processed file paths persist to
`<watch-dir>/.guardduty-connector-state.json` (atomic write-then-rename).
The state file itself is explicitly excluded from re-scanning (a bug caught
via test in the CloudTrail connector, fixed proactively here from the start).

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
