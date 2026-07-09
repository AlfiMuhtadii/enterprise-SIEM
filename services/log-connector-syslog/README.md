# log-connector-syslog

CONNECTOR-FRAMEWORK: a syslog (UDP/TCP) receiver that parses ArcSight CEF
and IBM LEEF payloads — the two most widely implemented vendor-neutral
formats for firewalls, WAFs, IDS/IPS, and SIEM-integrated appliances — and
forwards every event through the existing HMAC-signed `ingestion-gateway`
`/v1/ingest` endpoint. It also carries a config-driven parser registry (see
below) so a simple `marker + key=value` source that is neither CEF nor LEEF
can be onboarded by editing a JSON file, with no Go code change at all.

Lines that don't match CEF, LEEF, or any configured registry source are
still forwarded, as `telemetry_type=syslog_raw`, rather than dropped.

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
| `XDR_SYSLOG_PARSER_REGISTRY` | (empty) | path to a JSON config-driven parser registry (see below); empty means no registry sources, CEF/LEEF/raw dispatch only |

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

A parsed LEEF message (1.0 or 2.0) becomes `telemetry_type=syslog_leef`
analogously, with the full extension preserved under `leef_extension` and
`src`/`dst`/`srcPort`/`dstPort`/`proto`/`usrName`/`cat` promoted the same way.

## Config-driven parser registry (onboard a new source without code)

CEF and LEEF are hand-written because both have version-specific header
grammars a flat field map can't express. Most other simple appliance
formats, though, are just a fixed marker string followed by
`key=value key2=value2 ...` — for those, point `XDR_SYSLOG_PARSER_REGISTRY`
at a JSON file shaped like `parsers.sample.json` in this directory:

```json
{
  "sources": [
    {
      "name": "generic_appliance_fw",
      "marker": "APPFW:",
      "telemetry_type": "syslog_generic_kv",
      "event_type_field": "act",
      "field_map": {
        "source_ip": "src",
        "destination_ip": "dst",
        "action": "act",
        "user": "suser"
      }
    }
  ]
}
```

`marker` is matched as a plain substring anywhere in the line (like
`CEF:`/`LEEF:`); everything after it is parsed as space-separated
`key=value` pairs. `field_map` promotes the named source keys to canonical
top-level output fields — using the **same canonical names** the
normalizer's existing generic fallback envelope already recognizes
(`source_ip`, `destination_ip`, `user`, `action`, ...) means a newly
onboarded source needs **zero `normalizer-worker` code changes** either; it
flows through the same generic envelope any other unrecognized
`telemetry_type` already uses. Every key found (mapped or not) is also
preserved verbatim under `generic_extension`, so nothing is lost. CEF and
LEEF are tried before the registry, in that order; the first matching
registry source wins.
