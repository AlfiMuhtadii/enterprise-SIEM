# Investigation and Threat Hunting Operations

This phase adds analyst-oriented investigation workflows on top of the existing SOC telemetry platform.

## Threat Hunting UI

Open `SOC Dashboard -> Threat hunting`.

Supported hunting filters:

- `host_id`
- `user_name_hash`
- `process_name`
- `src_ip` / `dst_ip`
- domain text inside telemetry payload
- `event_type`
- severity context
- time window

When a hunt is executed, the platform stores a `threat_hunt_runs` record with filters, result count, and a compact result snapshot. Results are correlation-aware: matching telemetry rows include recent alerts for the same host context where available.

Saved hunts are stored in `threat_hunts`. Hunt result export is available as JSONL from the hunt page.

## Endpoint Timeline

Open an endpoint from hunt results or browse `/soc/endpoints/{host_id}`.

The endpoint timeline combines:

- process telemetry
- network connection telemetry
- file-change telemetry
- related alerts
- related incidents
- response workflow records

Timeline filters support event type and time range. Alerts are severity-highlighted and include evidence chain details when present.

## Forensic Bundle Collection

Open `SOC Dashboard -> Manage agents -> Forensic Collection`.

Supported safe collection types:

- `process-snapshot`
- `network-snapshot`
- `telemetry-snapshot`
- `recent-alert-evidence`
- `endpoint-diagnostics`

Collection jobs are created in `pending_approval` status and require an analyst/admin approval action before execution. Approval generates a JSON artifact and, when PHP `ZipArchive` is available, a ZIP bundle. Each job records status, approval actor, artifact path, checksum, and audit logs.

## Agent Hardening Signals

The endpoint agent reports lightweight integrity metadata in heartbeat payloads:

- agent script SHA-256
- package manifest validation status
- startup integrity result
- local policy/config hash
- unexpected restart count
- last start timestamp

`php artisan soc:agent-health-check` raises tamper-related alerts for failed startup integrity checks and unexpected restarts.

## SOC Investigation Visibility

The dashboard now summarizes:

- active hunts
- hunt matches
- endpoint investigation sessions
- pending forensic jobs
- suspicious timeline spike hosts
- agent integrity warnings

This gives analysts a single operational view of investigation activity without replacing existing incident, alert, and agent workflows.
