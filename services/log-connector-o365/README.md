# log-connector-o365

CONNECTOR-FRAMEWORK phase 7: polls the Office 365 Management Activity API
(`https://manage.office.com/api/v1.0/{tenant}/activity/feed/...`) for new
audit content and forwards every record through the existing HMAC-signed
`ingestion-gateway` `/v1/ingest` endpoint.

## Scope: live pull API, not file-based (unlike the other 3 cloud connectors)

`log-connector-{cloudtrail,guardduty,gcp-audit}` are deliberately scoped as
file-based ingestion of already-exported logs — there is no equivalent
fallback for O365 audit data. The Management Activity API is pull-only: an
operator needs a real **Azure AD app registration** (client ID/secret,
tenant ID) with Activity Feed API permissions granted, and an **active
subscription** (`POST .../activity/feed/subscriptions/start?contentType=...`,
done once out-of-band — this connector only lists/fetches already-subscribed
content, it does not manage subscriptions itself) before this connector can
list any content.

**This environment has no such credentials.** The OAuth2 client-credentials
flow, content listing (with `NextPageUri` pagination), and content-blob
fetch/parse logic (`internal/o365`) are built and unit-tested against a
local mock OAuth token endpoint + mock Activity API server — proven correct
in isolation (67 tests, real HTTP round trips against `httptest` servers),
but **never exercised against a real Microsoft tenant**.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `XDR_O365_AZURE_TENANT_ID` | (empty) | Azure AD tenant ID (GUID) the app registration belongs to |
| `XDR_O365_CLIENT_ID` | (empty) | Azure AD app registration client ID |
| `XDR_O365_CLIENT_SECRET` | (empty) | Azure AD app registration client secret |
| `XDR_O365_TOKEN_URL` | `https://login.microsoftonline.com/{XDR_O365_AZURE_TENANT_ID}/oauth2/v2.0/token` | OAuth2 token endpoint |
| `XDR_O365_ACTIVITY_BASE_URL` | `https://manage.office.com` | Management Activity API base URL |
| `XDR_O365_CONTENT_TYPES` | `Audit.AzureActiveDirectory,Audit.Exchange,Audit.SharePoint,Audit.General,DLP.All` | comma-separated content types to poll |
| `XDR_O365_POLL_SECONDS` | `300` | Poll interval |
| `XDR_O365_STATE_DIR` | `./o365-state` | Directory for the restart-safe processed-content state file |
| `XDR_O365_METRICS_ADDR` | `:8100` | `/health` + `/metrics` listen address |
| `XDR_INGEST_URL` | `http://127.0.0.1:8091/v1/ingest` | ingestion-gateway target |
| `XDR_INGEST_SECRET` | `dev-secret-change-me` | HMAC secret shared with ingestion-gateway |
| `XDR_O365_TENANT_ID` | (empty) | XDR platform tenant_id stamped on every forwarded event (**not** the Azure AD tenant — see `XDR_O365_AZURE_TENANT_ID` above) |
| `XDR_O365_REQUIRE_TENANT` | `false` | CONN-UNTENANTED-INGEST: if `true` and `XDR_O365_TENANT_ID` is empty, the connector refuses to start rather than forwarding unattributed telemetry |
| `XDR_O365_BATCH_SIZE` | `100` | events per forwarded batch |
| `XDR_O365_FORWARD_MAX_RETRIES` | `3` | CONN-DELIVERY-LOSS: max forward attempts per batch before the source content is left unprocessed for retry on the next poll |
| `XDR_O365_FORWARD_RETRY_BASE_MS` | `200` | CONN-DELIVERY-LOSS: initial retry backoff (doubles each attempt, capped at `_RETRY_MAX_MS`) |
| `XDR_O365_FORWARD_RETRY_MAX_MS` | `2000` | CONN-DELIVERY-LOSS: retry backoff cap |
| `XDR_O365_MAX_CONTENT_BYTES` | `104857600` (100 MiB) | CONN-UNBOUNDED-FILE: content blob response-body size ceiling; over this, the blob is rejected before being fully read. `0` disables the bound |
| `XDR_O365_MAX_RECORD_BYTES` | `1048576` (1 MiB) | CONN-UNBOUNDED-FILE: single-record size ceiling; an oversized record is skipped and counted (`oversized_records_skipped` metric) but does not affect the rest of the content blob. `0` disables the bound |

## Restart-safe content tracking (CONN-DELIVERY-LOSS)

Each processed content ID is recorded in
`<XDR_O365_STATE_DIR>/.o365-connector-state.json` (atomic write-then-rename),
the same mechanism the file connectors use for processed file paths. A
content ID is only marked processed — and the state file only saved — after
**every** batch derived from that content blob has been forwarded
successfully (with bounded retry via `deliver.WithRetry`). If a batch
exhausts its retries, the content is left unprocessed and re-fetched in
full on the next poll; there is no partial-content checkpoint.

## Size ceilings (CONN-UNBOUNDED-FILE)

A content blob whose response body exceeds `XDR_O365_MAX_CONTENT_BYTES` is
rejected by `Client.FetchContent` via a bounded `io.LimitReader` — it is
never fully read into memory. Unlike the file connectors' full durable
quarantine log (`<watch-dir>/.<service>-connector-quarantine.jsonl`, with a
`path`/`reason`/`quarantined_at` audit trail per rejection), an oversized
content blob here is simply marked processed (via the same state file, so
it isn't re-fetched every poll) and counted in the `content_too_large`
metric, with a log line naming the content ID — no separate quarantine
file. This is a deliberately lighter-weight treatment: a live API's content
blob is far less attacker-controllable than an operator-facing file-drop
directory (the file connectors' quarantine finding was specifically about a
directory an attacker could drop an oversized file into), so a metric +
log is proportionate here rather than a full durable-audit-trail file. A
single oversized record within an otherwise-acceptable blob is handled the
same as the file connectors: skipped and counted
(`oversized_records_skipped`), the rest of the blob's records still
forwarded normally.

## Field mapping

A parsed audit record becomes `telemetry_type=o365_audit` — **deliberately
not** `saas_audit`, which `normalizer-worker`/`correlation-worker` already
remap to the platform's currently-**active** `saas` correlation domain (see
`CLAUDE.md`: "Current active alert domains: identity, cloud, SaaS"). Using
a distinct, non-remapped `telemetry_type` matches the same "connector adds
no new active alert domain" precedent every other connector in this
framework already follows (`cloudtrail`/`guardduty`/`gcp_audit` are equally
distinct, non-remapped type strings) — this connector's events flow through
the existing normalize → correlate path but do not themselves trigger the
active SaaS correlation rules just by existing.

| Output field | Source |
|---|---|
| `event_type` / `action` | `Operation` (e.g. `UserLoggedIn`) |
| `event_source` | `Workload` (e.g. `AzureActiveDirectory`, `Exchange`, `SharePoint`) |
| `user` | `UserId` |
| `source_ip` | `ClientIP` |
| `cloud_account` | `OrganizationId` |
| `result` | `ResultStatus`, lowercased; defaults to `success` when absent |

The full original record is preserved verbatim under `o365_record`
regardless of which fields were promoted.

## What this connector does NOT do

- Does not create or manage Activity Feed subscriptions
  (`POST .../subscriptions/start`) — an operator must have already started
  a subscription for each content type out-of-band; this connector only
  lists/fetches already-subscribed content.
- Does not poll the live Azure AD Sign-in/Audit Logs Graph API directly —
  only the Management Activity API's content-blob export mechanism.
- Has never been run against a real Azure AD tenant or Office 365
  organization in this environment — the auth/polling/parsing mechanism is
  real and tested against mocks, not verified end-to-end against Microsoft's
  actual API.
