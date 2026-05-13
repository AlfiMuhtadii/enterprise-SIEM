# Next Phase: Telemetry Agent, Intelligence, Hunting, and Detection Quality

This phase adds operational depth without replacing the existing SOC platform.

## Lightweight Endpoint Telemetry Agent

Collect one endpoint snapshot:

```powershell
python scripts/endpoint_telemetry_agent.py --output storage/logs/endpoint_agent.jsonl
```

Continuous short collection:

```powershell
python scripts/endpoint_telemetry_agent.py --iterations 5 --interval 10 --output storage/logs/endpoint_agent.jsonl
```

The agent writes normalized telemetry JSONL for:

- process snapshots
- network connection snapshots

It does not perform remote execution, persistence, exploit logic, or C2 behavior.

## ETW/Sysmon Ingestion Enrichment

Enrich normalized telemetry before ingest:

```powershell
python scripts/telemetry_enrichment.py --input storage/logs/endpoint_agent.jsonl --output storage/logs/endpoint_agent_enriched.jsonl
```

Enrichment adds:

- Sysmon event mapping
- ETW/provider hints when present
- entity hints
- private/public network scope
- process and admin-protocol risk signals
- risk score hints

## Better Correlation

The existing correlation engine can consume enriched telemetry because enrichment is stored under the event payload and does not break the schema contract.

Recommended flow:

```powershell
python scripts/telemetry_enrichment.py --input samples/real-world/sysmon_sample.jsonl --output storage/logs/sysmon_enriched.jsonl
python scripts/ingest_telemetry_events.py --file storage/logs/sysmon_enriched.jsonl --from-start
python scripts/telemetry_rule_engine.py --minutes 1440
python scripts/telemetry_correlation_detector.py --minutes 1440
```

## Threat Hunting

Run hunts from JSONL:

```powershell
python scripts/threat_hunt.py --jsonl storage/logs/endpoint_agent_enriched.jsonl --output reports/threat_hunt_endpoint.json
```

Run hunts from database:

```powershell
python scripts/threat_hunt.py --minutes 1440 --output reports/threat_hunt_db.json
```

Included hunts:

- PowerShell network activity
- repeated DNS queries
- admin port fan-out
- repeated failed login sources

## Incident Intelligence

Generate an analyst-ready incident report:

```powershell
python scripts/incident_intelligence.py --incident-id DEMO-INC-001
```

The report includes:

- alert distribution
- severity distribution
- IOCs
- MITRE techniques
- evidence count
- recommended investigation actions
- notes count

## Detection Quality

Generate a quality rollup:

```powershell
python scripts/detection_quality_report.py --output reports/detection_quality_rollup.json
```

The report combines:

- benchmark summary
- real dataset trial summary
- detection quality history
- quality gates for precision, recall, false-positive rate, and replay stability

