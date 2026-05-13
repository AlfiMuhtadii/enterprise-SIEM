# Endpoint Agent Operations

This phase turns the snapshot collector into a persistent lightweight endpoint telemetry agent.

## Enrollment

Set server-side token:

```env
SOC_AGENT_ENROLLMENT_TOKEN=replace-with-strong-token
SOC_AGENT_HEARTBEAT_INTERVAL_SECONDS=60
SOC_AGENT_OFFLINE_AFTER_SECONDS=180
```

Run one enrollment/daemon command:

```powershell
python scripts/endpoint_telemetry_agent.py --daemon --server-url http://127.0.0.1:8000 --enrollment-token replace-with-strong-token --interval 60
```

The agent stores local state in:

```text
storage/app/endpoint_agent_state.json
```

The retry queue is stored in:

```text
storage/app/endpoint_agent_retry_queue.jsonl
```

## Secure Shipping

The server exposes:

```text
POST /api/agents/register
POST /api/agents/heartbeat
POST /api/agents/telemetry
```

Registration uses `X-Agent-Enrollment-Token`.

Heartbeat and telemetry use:

```text
X-Agent-Id
X-Agent-Timestamp
X-Agent-Signature
```

The signature is:

```text
HMAC_SHA256(agent_secret, timestamp + "." + raw_json_body)
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

Install:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/agent/windows/install-agent-service.ps1 -RepoPath D:\project\Detector -ServerUrl http://127.0.0.1:8000 -EnrollmentToken replace-with-strong-token
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

Edit `/etc/systemd/system/detector-endpoint-agent.service` to set:

- `DETECTOR_SERVER_URL`
- `SOC_AGENT_ENROLLMENT_TOKEN`
- working directory
- service user

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

