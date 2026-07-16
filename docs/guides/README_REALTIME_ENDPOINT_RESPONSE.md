# Real-Time Endpoint Telemetry and Response Workflow

## Streaming Endpoint Agent

The canonical agent (`services/endpoint-agent/agent.py`) is config-driven, not CLI-flag-driven —
copy `services/endpoint-agent/config.json.example` to `config.json`, edit it, then run:

```powershell
python services/endpoint-agent/agent.py --config services/endpoint-agent/config.json
```

Optional file watching — in `config.json`, set `"telemetry": {"file": true}` and list
directories under `watch_paths`:

```json
{
  "telemetry": { "file": true },
  "watch_paths": ["C:\\temp"]
}
```

`log_paths` is a DNS-only fallback (tails syslog/messages for DNS-query lines when
`dns_fixture_path` is unset) — it is not a generic log tailer:

```json
{
  "log_paths": ["/var/log/syslog", "/var/log/messages"]
}
```

**Known gap (not carried over from the retired `endpoint_telemetry_agent.py`):** generic
Linux `auth.log`/`audit.log` tailing for arbitrary security-event lines has no equivalent in
`agent.py` today — Windows gets dedicated Security/Sysmon/PowerShell-log collectors
(`collect_windows_security_events`/`collect_windows_sysmon`/`collect_windows_powershell_events`),
but Linux has no analogous auth/audit collector. Tracked as follow-up scope, not silently
dropped.

Streaming mode supports:

- process creation delta events
- network connection delta events
- file change events
- log tail events
- local buffering
- streaming batch delivery
- retry/backoff
- event latency metrics
- dropped/retry counters

## Detection-to-Response Workflow

SOC users can create approval-required response recommendations from `/soc/agents`.

Safe response actions:

- `collect-now`
- `refresh-policy`
- `flush-local-queue`
- `rotate-agent-secret`
- `restart-agent-loop`

Workflow:

```text
pending_approval -> approved_queued -> command sent -> command result
pending_approval -> rejected
```

All decisions are audited.

## Agent Package Builder

Windows package:

```powershell
python scripts/build_agent_package.py --platform windows --env staging --ingestion-gateway-url https://ingest.detector.example --soc-api-url https://detector.example --enrollment-token TOKEN
```

Linux package:

```powershell
python scripts/build_agent_package.py --platform linux --env production --ingestion-gateway-url https://ingest.detector.example --soc-api-url https://detector.example --enrollment-token TOKEN
```

The package includes:

- agent script
- service template
- embedded config JSON
- package manifest
- SHA-256 checksums
- deployment README
- zip archive

## SOC Visibility

The `/soc/agents` page now shows:

- stream health
- event throughput
- dropped event counters
- retry counters
- response workflows
- pending approvals
- command status

