# Endpoint Agent Operations

This phase turns the snapshot collector into a persistent lightweight endpoint telemetry agent.

## Enrollment

Set server-side token:

```env
SOC_AGENT_ENROLLMENT_TOKEN=replace-with-strong-token
SOC_AGENT_HEARTBEAT_INTERVAL_SECONDS=60
SOC_AGENT_OFFLINE_AFTER_SECONDS=180
```

Copy `services/endpoint-agent/config.json.example` to `config.json`, set
`enrollment_token` to the same value, then run:

```powershell
python services/endpoint-agent/agent.py --config services/endpoint-agent/config.json
```

The agent stores local state and buffer at the paths configured in `config.json`
(`state_path`/`buffer_path`, defaulting to `/var/lib/xdr-agent/state.json` and
`/var/lib/xdr-agent/buffer.jsonl` if unset).

## Secure Shipping

Telemetry goes to the ingestion gateway, not directly to Laravel:

```text
POST {ingestion_gateway_url}/v1/ingest
```

signed with:

```text
X-XDR-Timestamp: <unix ts>
X-XDR-Signature: sha256=HMAC_SHA256(ingestion_gateway_secret, "<ts>." + raw_json_body)
```

Enrollment, heartbeats, behavioral snapshots, and command polling/ack go to the Laravel
SOC control-plane:

```text
POST /api/agents/register
POST /api/agents/{agentId}/heartbeat
POST /api/agents/{agentId}/behavioral-snapshot
GET  /api/agents/{agentId}/commands
POST /api/agents/{agentId}/commands/{commandId}/ack
POST /api/agents/{agentId}/commands/{commandId}/result
```

Registration uses `Authorization: Bearer <enrollment_token>`. Heartbeat/behavioral-snapshot/
command-ack/command-result requests add:

```text
X-Agent-Signature: sha256=HMAC_SHA256(enrollment_token, sort_keys_json_body)
```

## Daemon Behavior

The daemon supports:

- periodic collection
- local event buffering
- retry queue
- exponential backoff
- heartbeat reporting
- event count metrics
- collection error tracking
- agent version tracking

## Windows Service

Install (place a filled-in `config.json` at `services\endpoint-agent\config.json` under
`-RepoPath` first, or pass `-ConfigPath` to point elsewhere):

```powershell
powershell -ExecutionPolicy Bypass -File deploy/agent/windows/install-agent-service.ps1 -RepoPath D:\project\Detector
```

Uninstall:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/agent/windows/uninstall-agent-service.ps1
```

## Linux systemd

Copy service file:

```bash
sudo cp deploy/agent/linux/detector-endpoint-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now detector-endpoint-agent
```

Place a filled-in `config.json` (copied from `services/endpoint-agent/config.json.example`,
with `ingestion_gateway_url`/`ingestion_gateway_secret`/`soc_api_url`/`enrollment_token`/
`state_path`/`buffer_path` set) at `/etc/detector/agent/config.json` before starting the
service — the unit file's `ExecStart` reads its configuration from that path, not from
environment variables. Also confirm the working directory and `User=`/`Group=` in the unit
file match your deployment.

## SOC Visibility

Agents are visible in:

- `/soc` Endpoint Agents panel
- `/soc/api/agents`

The dashboard shows:

- registered agents
- online/offline status
- latest heartbeat
- event throughput
- collection errors
- agent version

