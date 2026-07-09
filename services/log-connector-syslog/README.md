# log-connector-syslog

CONNECTOR-FRAMEWORK phase 1: a syslog (UDP/TCP) receiver that parses
ArcSight CEF payloads — the most widely implemented vendor-neutral format
for firewalls, WAFs, IDS/IPS, and other network security appliances — and
forwards every event through the existing HMAC-signed `ingestion-gateway`
`/v1/ingest` endpoint. Onboarding a new syslog/CEF source requires pointing
the appliance at this connector, not a Go pipeline code change.

Lines that are not valid CEF are still forwarded, as
`telemetry_type=syslog_raw`, rather than dropped.

All events flow through the existing normalize -> correlate shadow path.
This connector adds no new active alert domain, does not block or modify
any traffic, and performs no outbound action beyond the signed forward to
ingestion-gateway.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `XDR_SYSLOG_UDP_ADDR` | `:5140` | UDP listen address |
| `XDR_SYSLOG_TCP_ADDR` | `:5140` | TCP listen address (newline-delimited framing; RFC6587 octet-counting not supported) |
| `XDR_SYSLOG_METRICS_ADDR` | `:8095` | `/health` + `/metrics` listen address |
| `XDR_INGEST_URL` | `http://127.0.0.1:8091/v1/ingest` | ingestion-gateway target |
| `XDR_INGEST_SECRET` | `dev-secret-change-me` | HMAC secret shared with ingestion-gateway |
| `XDR_SYSLOG_TENANT_ID` | (empty) | tenant_id stamped on every forwarded event |
| `XDR_SYSLOG_BATCH_SIZE` | `50` | events per forwarded batch |
| `XDR_SYSLOG_FLUSH_MS` | `500` | max time before a partial batch is flushed |

## Field mapping

A parsed CEF message becomes `telemetry_type=syslog_cef`, `event_type`
derived from the CEF `Name` field, with the full extension preserved
verbatim under `cef_extension` and common fields promoted to top-level
aliases the normalizer's generic envelope already understands: `src`/
`sourceAddress` -> `source_ip`, `dst`/`destinationAddress` ->
`destination_ip`, `spt`/`sourcePort` -> `source_port`, `dpt`/
`destinationPort` -> `destination_port`, `proto`/`transportProtocol` ->
`protocol`, `act`/`deviceAction` -> `action`, `suser`/`sourceUserName`/
`duser` -> `user`.
