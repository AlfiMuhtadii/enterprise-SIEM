# Detector Attack Lab

Standalone traffic generator for testing the detector with real HTTP requests.

This tool does not insert dummy events, alerts, or responses. It sends requests to the target app, so the normal logging middleware and detector pipeline must process them.

## Files

- `attack_lab.py`: CLI scenario runner.
- `attack_lab_ui.py`: local web UI wrapper.
- `profiles/*.json`: reusable scenario profiles.
- `campaigns/*.json`: stateful multi-step campaigns.
- `coverage/*.json`: detector assertions and coverage expectations.
- `payloads/*.txt`: path and payload wordlists.
- `state/*.json`: campaign execution state.
- `reports/`: generated JSON/HTML reports.

## Safety Defaults

- Targets only `127.0.0.1`, `localhost`, or `::1` by default.
- UI binds only to `127.0.0.1:8765`.
- Use only against systems you own or are explicitly authorized to test.

## Run UI

From repo root:

```powershell
python tools/attack-lab/attack_lab_ui.py
```

Open:

```text
http://127.0.0.1:8765
```

Set `Detector Root` to this repo path if you want the UI to show alert summary after each run:

```text
D:\project\Detector
```

Set `Detector Mode`:

- `none`: only sends HTTP requests.
- `ingest`: sends HTTP requests, then imports `security.jsonl` into `security_events`.
- `replay`: sends HTTP requests, imports `security.jsonl`, then runs the detector replay so alerts appear without manually starting producer/consumer.

## Run CLI

```powershell
python tools/attack-lab/attack_lab.py full --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector
```

If you have not started the realtime producer/consumer, use replay mode:

```powershell
python tools/attack-lab/attack_lab.py full --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay
```

Use the real socket source IP instead of the simulated `X-Forwarded-For` IP:

```powershell
python tools/attack-lab/attack_lab.py full --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --real-source-ip
```

Important: if the target is `http://127.0.0.1:8000`, the real source IP will normally be `127.0.0.1` because the traffic uses loopback. To make the app see your LAN IP, run Laravel with `--host=0.0.0.0`, target your machine LAN address, and allow non-local target only in an authorized lab:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
python tools/attack-lab/attack_lab.py full --base-url http://YOUR_LAN_IP:8000 --detector-root D:\project\Detector --detector-mode replay --real-source-ip --allow-non-local
```

Available scenarios:

```powershell
python tools/attack-lab/attack_lab.py normal --base-url http://127.0.0.1:8000
python tools/attack-lab/attack_lab.py bruteforce --base-url http://127.0.0.1:8000
python tools/attack-lab/attack_lab.py scan --base-url http://127.0.0.1:8000
python tools/attack-lab/attack_lab.py injection --base-url http://127.0.0.1:8000
python tools/attack-lab/attack_lab.py privilege --base-url http://127.0.0.1:8000
python tools/attack-lab/attack_lab.py anomaly --base-url http://127.0.0.1:8000
python tools/attack-lab/attack_lab.py full --base-url http://127.0.0.1:8000
```

## Profile-Based Runner

List available profiles:

```powershell
python tools/attack-lab/attack_lab.py --list-profiles
```

Run a profile:

```powershell
python tools/attack-lab/attack_lab.py --profile profiles/laravel-basic.json --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay
```

Run with advanced detector correlation:

```powershell
python tools/attack-lab/attack_lab.py --profile profiles/laravel-basic.json --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced
```

Run focused injection profile:

```powershell
python tools/attack-lab/attack_lab.py --profile profiles/injection-focused.json --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay
```

Run lightweight crawler:

```powershell
python tools/attack-lab/attack_lab.py --crawl --base-url http://127.0.0.1:8000 --count 30
```

## Campaign Orchestrator

List campaigns:

```powershell
python tools/attack-lab/attack_lab.py --list-campaigns
```

Run a campaign:

```powershell
python tools/attack-lab/attack_lab.py --campaign campaigns/web-detector-validation.json --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced
```

Campaign mode adds:

- `campaign_id`
- per-step state
- conditional execution
- state file in `tools/attack-lab/state`
- campaign HTML/JSON report
- optional final detector ingest/replay
- detector coverage matrix when `coverage_expectations` is configured

Supported campaign conditions:

- `previous_failed_lte`
- `min_total_requests`

## Detector Assertion + Coverage Matrix

Run coverage directly:

```powershell
python scripts/detector_coverage_matrix.py --expectations tools/attack-lab/coverage/web-basic-coverage.json
```

Coverage answers:

```text
What should have been detected?
What was actually detected?
Which assertion passed or failed?
```

The default campaign automatically runs coverage after detector replay because it defines:

```json
"coverage_expectations": "tools/attack-lab/coverage/web-basic-coverage.json"
```

Reports are written to:

```text
tools/attack-lab/reports
```

Each run creates JSON and HTML reports.

## Advanced Telemetry Correlation

The web detector can be extended with endpoint, network, and DNS telemetry without changing the protected Laravel app source code. External collectors only need to write normalized JSONL that follows `scripts/telemetry_event_contract.py`.

Demo the telemetry layer:

```powershell
php artisan migrate
python scripts/generate_telemetry_sample.py
python scripts/telemetry_event_contract.py --file storage/logs/telemetry.jsonl
python scripts/ingest_telemetry_events.py --file storage/logs/telemetry.jsonl --from-start
python scripts/telemetry_correlation_detector.py --minutes 60
php artisan security:alerts-report --minutes=60
```

Validate false-positive baseline with approved normal telemetry:

```powershell
python scripts/generate_telemetry_sample.py --kind normal --output storage/logs/telemetry_normal.jsonl
python scripts/telemetry_event_contract.py --file storage/logs/telemetry_normal.jsonl
python scripts/ingest_telemetry_events.py --file storage/logs/telemetry_normal.jsonl --from-start
python scripts/telemetry_correlation_detector.py --minutes 60 --dry-run
```

Baseline configuration is stored in:

```text
storage/app/telemetry_baseline.json
```

Use it for known admin sources, approved remote-admin pairs, known DNS domains, and approved persistence-like maintenance activity.

Expected advanced alert families:

- `PERSISTENCE_INDICATOR`: scheduled task, service, startup item, registry run key, or cron modification telemetry.
- `SANDBOX_EVASION_INDICATOR`: debugger, VM artifact, sandbox, or environment probing telemetry.
- `LATERAL_MOVEMENT_SUSPECTED`: one source reaching multiple internal hosts over remote-admin ports.
- `INTERNAL_RECON_SUSPECTED`: one source enumerating many internal hosts or destination ports.
- `C2_DNS_BEACON_PATTERN`: repeated DNS query cadence with low interval variance.

Each telemetry alert stores an `evidence_chain` in `security_alerts.evidence`, including timestamp, event id, host, source IP, destination IP/port, process, user hash, and domain where available. Confidence is also stored as signal names, score, and severity.

Build MITRE coverage from alert evidence:

```powershell
python scripts/mitre_coverage_matrix.py --expectations tools/attack-lab/coverage/mitre-advanced-coverage.json --minutes 60
```

This layer is defensive correlation. It detects telemetry patterns associated with advanced threats; it does not implement C2, persistence, lateral movement, sandbox bypass, or exploit behavior.

## Mature Detection Engineering Layer

Normalize real telemetry sources into the existing `telemetry_events` schema:

```powershell
python scripts/telemetry_adapters.py --adapter sysmon-json --input samples/sysmon.jsonl --output storage/logs/telemetry_normalized.jsonl
python scripts/telemetry_adapters.py --adapter sysmon-xml --input samples/sysmon.xml --output storage/logs/telemetry_normalized.jsonl
python scripts/telemetry_adapters.py --adapter zeek-conn --input samples/conn.log --output storage/logs/telemetry_normalized.jsonl
python scripts/telemetry_adapters.py --adapter zeek-dns --input samples/dns.log --output storage/logs/telemetry_normalized.jsonl
python scripts/telemetry_adapters.py --adapter suricata-eve --input samples/eve.json --output storage/logs/telemetry_normalized.jsonl
python scripts/telemetry_adapters.py --adapter windows-security-json --input samples/windows-security.jsonl --output storage/logs/telemetry_normalized.jsonl
python scripts/telemetry_adapters.py --adapter linux-auth --input samples/auth.log --output storage/logs/telemetry_normalized.jsonl
python scripts/ingest_telemetry_events.py --file storage/logs/telemetry_normalized.jsonl --from-start
```

Run declarative rule correlation:

```powershell
python scripts/telemetry_rule_engine.py --rules storage/app/telemetry_rules.json --minutes 60
```

Run async stream ingestion from Redpanda REST:

```powershell
python scripts/telemetry_stream_worker.py --topic telemetry_events --group-id telemetry-worker-v1
```

Build graph-based evidence visualization:

```powershell
python scripts/entity_graph_builder.py --minutes 60 --output-html reports/evidence_graph.html
```

Run formal metrics:

```powershell
python scripts/detection_benchmark.py --labels tools/attack-lab/coverage/telemetry-benchmark-labels.json --minutes 60
python scripts/false_positive_evaluator.py --patterns storage/app/normal_telemetry_patterns.json --minutes 60
```

Declarative rules live in:

```text
storage/app/telemetry_rules.json
```

The rule schema supports `rule_id`, `name`, `description`, `severity`, MITRE mapping, required event types, conditions, sliding time window, thresholds, scoring, temporal sequence, and evidence fields.

## Operational SOC Layer

Validate rule quality and regression test cases:

```powershell
python scripts/rule_quality_manager.py --action validate
python scripts/rule_quality_manager.py --action test
```

Replay/import real telemetry datasets from a manifest:

```powershell
python scripts/real_dataset_validation.py --manifest storage/app/real_dataset_manifest.json --minutes 120
```

Apply alert deduplication and build incidents:

```powershell
python scripts/alert_deduplicator.py --minutes 120
python scripts/incident_manager.py --minutes 120
python scripts/soc_workflow.py list
```

SOC workflow examples:

```powershell
python scripts/soc_workflow.py assign --incident-id INC_ID --analyst analyst1
python scripts/soc_workflow.py note --incident-id INC_ID --author analyst1 --body "Validated suspicious DNS beacon"
python scripts/soc_workflow.py status --incident-id INC_ID --status investigating
python scripts/soc_workflow.py false-positive --incident-id INC_ID --author analyst1 --reason "Approved maintenance"
```

Storage maintenance and operational exports:

```powershell
python scripts/storage_maintenance.py --stats
python scripts/storage_maintenance.py --archive --retention-days 30
python scripts/storage_maintenance.py --cleanup --retention-days 30
python scripts/storage_partition_manager.py --months-ahead 6 --copy-existing
python scripts/integration_exporter.py --format jsonl --output reports/alerts_export.jsonl
python scripts/integration_exporter.py --format siem --output reports/siem_export.jsonl
python scripts/integration_exporter.py --format stix --output reports/stix_bundle.json
python scripts/quality_history_recorder.py --source local
```

SOC dashboard:

```text
http://127.0.0.1:8000/soc
```

Internal SOC APIs:

```text
GET  /soc/api/stats
GET  /soc/api/incidents
GET  /soc/api/incidents/{incident_id}
GET  /soc/api/alerts
GET  /soc/api/benchmarks
GET  /soc/api/audit
POST /soc/api/incidents/{incident_id}/workflow
```

## What The Scenarios Do

- `normal`: ordinary page/search traffic.
- `bruteforce`: repeated failed login requests with CSRF handling.
- `scan`: suspicious path enumeration such as `/.env`, `/wp-admin`, and random scan paths.
- `injection`: search requests containing SQL/XSS-like payload indicators.
- `privilege`: unauthenticated access attempts to `/admin`.
- `anomaly`: many valid-looking search requests from one IP to trigger behavior scoring.
- `full`: runs all scenarios in sequence.

## Expected Demo Flow

1. Start Laravel app.
2. Start Redpanda/ClickHouse/Grafana if needed.
3. Start producer and detector.
4. Open Attack Lab UI.
5. Click `full`.
6. Open `/security/alerts` or Grafana to verify alerts.

This proves the detector is responding to real HTTP requests, not hardcoded dummy inserts.

## Profile Format

Minimal profile:

```json
{
  "name": "custom-test",
  "defaults": {
    "base_url": "http://127.0.0.1:8000",
    "count": 20,
    "sleep_ms": 20,
    "source_ip": "203.0.113.50",
    "spoof_ip": true
  },
  "steps": [
    {
      "name": "scan",
      "type": "scan",
      "count": 20,
      "payload_file": "payloads/scan-paths.txt"
    },
    {
      "name": "injection",
      "type": "injection",
      "path": "/search",
      "param": "q",
      "payload_files": ["payloads/sqli.txt", "payloads/xss.txt"]
    }
  ]
}
```
