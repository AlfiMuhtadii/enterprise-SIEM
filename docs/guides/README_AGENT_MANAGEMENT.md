# Endpoint Agent Management

This phase adds centralized policy, lifecycle, tamper monitoring, and safe command management for lightweight endpoint agents.

## Policy Management

SOC users with `agents.manage` can open:

```text
/soc/agents
```

Policies control:

- collection interval
- enabled collectors
- max batch size
- retry policy
- telemetry categories
- policy version
- default policy assignment

Agents pull policy from:

```text
POST /api/agents/config
```

## Version and Upgrade Tracking

Agent inventory tracks:

- current `agent_version`
- `target_version`
- `upgrade_status`
- latest release metadata through `agent_releases`

Set latest expected version:

```env
SOC_AGENT_LATEST_VERSION=0.2.0
```

Agents older than the latest version are marked `upgrade_available`.

## Tamper and Health Detection

Scheduled check:

```powershell
php artisan soc:agent-health-check
```

The monitor creates alerts for:

- `AGENT_STALE_OR_STOPPED`
- `AGENT_RETRY_QUEUE_GROWTH`
- `AGENT_REPEATED_DELIVERY_FAILURE`
- `AGENT_POLICY_OUTDATED`

The scheduler runs this every five minutes.

## Safe Remote Commands

Supported commands:

- `collect-now`
- `flush-local-queue`
- `rotate-agent-secret`
- `refresh-policy`
- `restart-agent-loop`

Commands are pull-based. The server queues commands, and the agent retrieves them during config polling.

Command lifecycle:

```text
queued -> sent -> succeeded|failed|retry|timeout|unsupported
```

All command queue actions are audited.

## Agent Daemon Policy Pull

Run daemon:

```powershell
python scripts/endpoint_telemetry_agent.py --daemon --server-url http://127.0.0.1:8000 --enrollment-token local-agent-token
```

The agent reports:

- policy version seen
- config hash
- retry queue depth
- event count
- error count
- upgrade status

