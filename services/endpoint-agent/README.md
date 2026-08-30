# XDR Endpoint Telemetry Agent

Lightweight, telemetry-only Linux endpoint agent. No kernel driver, no packet sniffing, no privilege escalation. Reads `/proc` directly for process and network data and sends signed batches to the XDR ingestion gateway.

**Endpoint alerts are shadow-only** — output goes to `xdr.alerts.shadow.endpoint`, never to the active alert path.

---

## Requirements

- Python 3.9+ (stdlib only — no pip install needed)
- Linux (primary). Windows: process/network collection falls back to `ps`/`ss` where available.
- Access to `/proc` (standard on any Linux system; no root required for process snapshots of own processes)

---

## How to run locally

```bash
# 1. Copy the example config
cp services/endpoint-agent/config.json.example services/endpoint-agent/config.json

# 2. Edit config.json — set ingestion_gateway_url and ingestion_gateway_secret
#    to match your running ingestion-gateway instance.

# 3. Create state/buffer dirs (the agent creates them on first run, but you can pre-create)
mkdir -p /tmp/xdr-agent

# 4. Run (one cycle, then exit — good for smoke testing)
python services/endpoint-agent/agent.py --config services/endpoint-agent/config.json --once

# 5. Run continuously
python services/endpoint-agent/agent.py --config services/endpoint-agent/config.json

# 6. Debug mode (verbose logging)
python services/endpoint-agent/agent.py --config services/endpoint-agent/config.json --debug
```

---

## How to run a 2-machine lab

**Machine A: XDR stack** (runs ingestion-gateway, Redpanda, Laravel SOC)

```bash
# Start infrastructure
docker compose up -d

# Start pipeline services
docker compose --profile strangler up -d

# Start Laravel queue worker (required for Scenario Runner)
php artisan queue:work --sleep=1 --tries=1
```

Note the host IP of Machine A (e.g. `192.168.1.10`).

**Machine B: Endpoint (Linux target)**

```bash
# Copy agent and config
scp services/endpoint-agent/agent.py user@192.168.1.20:/tmp/xdr-agent/
scp services/endpoint-agent/config.json.example user@192.168.1.20:/tmp/xdr-agent/config.json

# On Machine B: edit config.json
#   "ingestion_gateway_url": "http://192.168.1.10:8091"
#   "ingestion_gateway_secret": "dev-secret-change-me"   # must match XDR_INGEST_SECRET
#   "soc_api_url": "http://192.168.1.10:8000"

# Run agent on Machine B
ssh user@192.168.1.20
python /tmp/xdr-agent/agent.py --config /tmp/xdr-agent/config.json --debug
```

For an mTLS-protected gateway, use an `https://` URL and configure all three credential paths:

```json
{
  "ingestion_gateway_url": "https://ingestion.example.internal:8091",
  "ingestion_gateway_mtls_enabled": true,
  "ingestion_gateway_mtls_ca": "/etc/detector-agent/ca.crt",
  "ingestion_gateway_mtls_client_cert": "/etc/detector-agent/client.crt",
  "ingestion_gateway_mtls_client_key": "/etc/detector-agent/client.key"
}
```

The agent verifies the gateway hostname against the configured CA and presents the client identity. Enabling mTLS with HTTP or incomplete credentials fails closed during client initialization. Provision endpoint-specific private keys outside the deployment package.

---

## How to verify heartbeat

The agent sends two kinds of heartbeat:

**1. Telemetry heartbeat** — a `heartbeat` event published through the full XDR pipeline.

```bash
# On Machine A: query security_alerts (heartbeat events appear as shadow alerts)
# or check the ingestion-gateway logs:
docker compose logs ingestion-gateway --tail 20

# Look for lines like:
# published event event_type=heartbeat agent_id=... trace_id=...
```

**2. SOC API heartbeat** — a direct HTTP POST to Laravel `/api/agents/heartbeat`.

```bash
# On Machine A: check Laravel logs
tail -f storage/logs/laravel.log | grep heartbeat

# Or check endpoint_agents table
php artisan tinker --execute="echo \App\Models\EndpointAgent::latest()->first()?->last_seen_at;"
```

**DNS simulation mode** — write query records to the fixture file and verify they appear:

```bash
# In config.json, set:
#   "dns_fixture_path": "/tmp/xdr-dns-fixture.jsonl"

# Append a simulated DNS query
echo '{"domain":"malicious.example.com","query_type":"A"}' >> /tmp/xdr-dns-fixture.jsonl

# The agent will pick it up on the next collection cycle and ship a dns_query event.
```

---

## How to verify telemetry appears in SOC

1. Open the SOC dashboard at `http://127.0.0.1:8000`
2. Navigate to **Endpoint** → inventory — the agent's host should appear after first heartbeat
3. Navigate to **Endpoint** → select the host → **Timeline** to see shadow events
4. Navigate to **Traces** and search by `agent_id` or the `trace_id` logged by the agent

From the command line:

```bash
# Check shadow alerts in PostgreSQL
psql -U postgres -d xdr -c \
  "SELECT alert_type, detected_at, trace_id FROM security_alerts \
   WHERE alert_type LIKE 'ENDPOINT_%' OR alert_type LIKE 'SUSPICIOUS_%' \
   ORDER BY detected_at DESC LIMIT 10;"

# OR via Laravel tinker
php artisan tinker --execute="
  \App\Models\SecurityAlert::where('alert_type', 'LIKE', 'ENDPOINT_%')
    ->latest('detected_at')->limit(5)->get(['alert_type','detected_at','trace_id']);
"
```

**Note:** Endpoint alerts route to `xdr.alerts.shadow.endpoint` — they are tracked as shadow evidence but never promoted to the active `security_alerts` path until a domain-specific soak PASS.

---

## DNS simulation fixture format

When `dns_fixture_path` is set, the agent reads new lines appended to that JSONL file on each collection cycle. Each line is a JSON object:

```json
{"domain": "example.com", "query_type": "A"}
{"domain": "c2server.bad", "query_type": "A"}
{"domain": "exfil.attacker.io", "query_type": "TXT"}
```

Valid `query_type` values: `A`, `AAAA`, `MX`, `TXT`, `CNAME`, `NS`, `PTR`, `SOA`, `SRV`, `ANY`.

---

## File write watcher

Set `telemetry.file: true` and list paths in `watch_paths`. The agent uses `os.walk` + `stat` polling — no inotify, no kernel hooks. Only configured paths are watched.

```json
{
  "telemetry": { "file": true },
  "watch_paths": ["/tmp/xdr-test-watch", "/var/log/app"]
}
```

---

## Security boundaries

This agent is telemetry-only:

- **No credential collection** — does not read `/etc/shadow`, browser cookies, or keychains
- **No packet sniffing** — uses `/proc/net/tcp` for connection metadata only (no payload capture)
- **No kernel module** — pure userland Python, no `ioctl`, no eBPF
- **No process killing** — read-only `/proc` access
- **No persistence install** — does not write crontabs or systemd units
- **No privilege escalation** — runs as the invoking user; `/proc` entries for other users gracefully skipped on `PermissionError`
- **Endpoint alerts remain shadow-only** — output topic is `xdr.alerts.shadow.endpoint`

---

## Running the tests

```bash
python -m pytest tests/endpoint_agent/ -v
```
