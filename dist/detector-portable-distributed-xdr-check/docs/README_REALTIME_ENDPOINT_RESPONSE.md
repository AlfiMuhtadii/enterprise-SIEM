# Real-Time Endpoint Telemetry and Response Workflow

## Streaming Endpoint Agent

Run streaming mode:

```powershell
python scripts/endpoint_telemetry_agent.py --daemon --stream --server-url http://127.0.0.1:8000 --enrollment-token local-agent-token --interval 10
```

Optional file watching:

```powershell
python scripts/endpoint_telemetry_agent.py --daemon --stream --watch-path C:\temp --tail-file C:\Windows\System32\winevt\Logs\Security.evtx
```

Linux auth/audit tailing:

```bash
python3 scripts/endpoint_telemetry_agent.py --daemon --stream --tail-file /var/log/auth.log --tail-file /var/log/audit/audit.log
```

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
python scripts/build_agent_package.py --platform windows --env staging --server-url https://detector.example --enrollment-token TOKEN
```

Linux package:

```powershell
python scripts/build_agent_package.py --platform linux --env production --server-url https://detector.example --enrollment-token TOKEN
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

