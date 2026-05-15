# Endpoint Normalization Rules

Defines the field mapping from raw endpoint telemetry (as received by the ingestion-gateway) to the normalized endpoint event format (published to `telemetry.normalized` by normalizer-worker).

The Go implementation is in `services/normalizer-worker/main.go` → `normalizeEndpoint()`.
The Python mirror is in `scripts/xdr_endpoint_normalization_validate.py` → `normalize_endpoint_local()`.

---

## Routing

Events with `telemetry_type == "endpoint"` are routed to `normalizeEndpoint()` inside `normalize()`. All other telemetry types use the existing flat normalization path.

---

## Required Raw Fields (normalization fails if missing)

| Raw field | Fallback keys | Notes |
|---|---|---|
| `telemetry_type` | `source_type`, `category` | Must equal `"endpoint"` |
| `event_type` | `action`, `operation` | Must be one of the 6 endpoint event types |
| `ts` | `timestamp`, `event_time` | ISO-8601 preferred |
| `host` | `host_id`, `device_name` | Endpoint hostname |

If any required raw field is missing or empty, the event is routed to `telemetry.normalized.dlq` by the normalizer with error `missing_required_fields`.

---

## Top-Level Normalized Fields

| Normalized field | Source (raw) | Fallback keys | Notes |
|---|---|---|---|
| `schema_version` | — | — | Always `1` (integer) |
| `normalization_version` | — | — | Always `"endpoint-v1"` |
| `normalized_event_id` | `event_id` | `id` | Same as `raw_event_id`; normalization is a pure function |
| `raw_event_id` | `event_id` | `id` | Original event ID from raw telemetry |
| `ts` | `ts` | `timestamp`, `event_time` | Pass-through |
| `telemetry_type` | — | — | Always `"endpoint"` |
| `event_type` | `event_type` | `action`, `operation` | Lowercased |
| `host` | `host` | `host_id`, `device_name` | Pass-through |
| `user` | `user` | — | Optional; null if absent |
| `risk_score` | `risk_score` | `risk`, `score` | Float; null if absent |
| `event_source` | `event_source` | `source_adapter`, `vendor` | EDR agent identifier; null if absent |
| `trace_id` | `trace_id` | — | Propagated from ingestion; null if absent |

---

## Nested Section: `process`

Present in all normalized endpoint events. Fields are null for event types where not applicable.

| Normalized field | Source (raw) | Fallback keys | Relevant event types |
|---|---|---|---|
| `process.name` | `process_name` | — | process_start, process_exit, network_connection, file_write |
| `process.pid` | `pid` | — | process_start, process_exit, network_connection, dns_query, file_write |
| `process.ppid` | `ppid` | — | process_start, process_exit |
| `process.command_line` | `command_line` | — | process_start |
| `process.path` | `process_path` | — | process_start, process_exit |
| `process.hash` | `file_hash` | `sha256` | process_start, process_exit (binary hash) |

Integer fields (`pid`, `ppid`) are extracted as `int64`. JSON numbers arrive as `float64` in Go and are cast to `int64`.

---

## Nested Section: `network`

Present in all normalized endpoint events. Fields are null for event types where not applicable.

| Normalized field | Source (raw) | Fallback keys | Relevant event types |
|---|---|---|---|
| `network.source_ip` | `source_ip` | `src_ip`, `client_ip` | network_connection, dns_query, login_event |
| `network.destination_ip` | `destination_ip` | `dst_ip`, `server_ip` | network_connection |
| `network.destination_port` | `destination_port` | — | network_connection |
| `network.protocol` | `protocol` | — | network_connection; lowercased |

`destination_port` is extracted as `int64`.

---

## Nested Section: `dns`

Present in all normalized endpoint events. Fields are null for event types where not applicable.

| Normalized field | Source (raw) | Fallback keys | Relevant event types |
|---|---|---|---|
| `dns.domain` | `domain` | `query`, `url_domain` | dns_query |
| `dns.resolved_ips` | `resolved_ips` | — | dns_query; passed through as array or null |

---

## Nested Section: `file`

Present in all normalized endpoint events. Fields are null for event types where not applicable.

| Normalized field | Source (raw) | Fallback keys | Relevant event types |
|---|---|---|---|
| `file.path` | `file_path` | — | file_write |
| `file.hash` | `file_hash` | `sha256` | file_write (content hash) |
| `file.operation` | `operation` | — | file_write; lowercased |

---

## Nested Section: `auth`

Present in all normalized endpoint events. Fields are null for event types where not applicable.

| Normalized field | Source (raw) | Fallback keys | Relevant event types |
|---|---|---|---|
| `auth.action` | `action` | — | login_event; lowercased |
| `auth.result` | `result` | `outcome` | login_event; lowercased |

---

## Idempotency

Normalization is a pure function of the raw event. The same raw event always produces the same normalized output. `normalized_event_id == raw_event_id` guarantees that reprocessing the same raw event does not create a different normalized event ID.

---

## Shadow-Only Status

Endpoint normalized events are published to `telemetry.normalized` but are NOT consumed by the correlation-worker in active mode. The correlation-worker scope is `identity-cloud`. Endpoint events flow through the normalizer (shadow validation) but are silently dropped by the correlation-worker's scope filter.

Do not activate endpoint correlation without completing the gates in `docs/architecture/endpoint-shadow-correlation-plan.md`.
