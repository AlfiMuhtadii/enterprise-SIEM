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

For external deployment, build and extract the Windows package into its final,
stable location. Review `config.json`, then run the packaged installer. It
verifies `MANIFEST.sha256` and every payload file, copies only manifest-listed
files to `%ProgramFiles%\Detector\EndpointAgent`, verifies that destination
again, and only then creates the service. The destination must be empty:

```powershell
python verify_agent_package.py --package .
powershell -ExecutionPolicy Bypass -File .\install-agent-service.ps1
```

For source-tree development only, the legacy installer path remains available
after placing a filled-in `config.json` under `services\endpoint-agent`:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/agent/windows/install-agent-service.ps1 -RepoPath D:\project\Detector
```

Uninstall:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/agent/windows/uninstall-agent-service.ps1
```

## Linux systemd

For external deployment, build and extract the Linux package, review
`config.json`, and run the packaged installer. Verification runs before user,
file, or systemd mutation. Existing `/etc/detector/agent/config.json` is never
overwritten:

```bash
python3 verify_agent_package.py --package .
sudo bash install-agent-service.sh
```

The installer requires `python3`, creates the bounded `detector` service user,
installs the agent under `/opt/detector`, keeps state under `/var/lib/xdr-agent`,
and installs configuration with group-readable mode `0640`. Use `--no-start`
to install without enabling the service. Manifest SHA-256 is an integrity
check, not publisher authentication; production distribution still requires a
trusted external artifact signature.

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
