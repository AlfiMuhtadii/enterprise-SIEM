# SOC-Oriented Detection Platform Final Portfolio README

## System Summary

This project is a SOC-oriented telemetry, detection, investigation, and response platform. It started as a Laravel web attack detector and evolved into a lightweight endpoint-aware SOC platform with telemetry ingestion, correlation, incident workflow, endpoint agent management, threat hunting, AI-assisted analysis, threat intelligence enrichment, and operational readiness tooling.

## Core Capabilities

- Web attack detection from Laravel security events.
- Endpoint telemetry ingestion from lightweight agents.
- Declarative rule and correlation pipeline.
- Alert deduplication, incident creation, and SOC workflow.
- Analyst dashboard, incident detail pages, threat hunting, and endpoint timelines.
- Detection tuning, IOC enrichment, playbooks, and executive reporting.
- AI-assisted incident summaries with guardrails and RAG-backed SOC knowledge retrieval.
- Safe response workflow with approval, audit, forensic collection, and containment simulation.
- Enterprise readiness checks, soak testing, retention/cost reporting, and HA planning.

## Demo Quickstart

Run infrastructure and app:

```bash
docker compose up -d
php artisan migrate --seed
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Run demo validation:

```bash
powershell -ExecutionPolicy Bypass -File .\scripts\final-present.ps1 -SkipUp
```

Useful manual commands:

```bash
php artisan soc:env-validate local
php artisan soc:soak-test --events=10000 --environment=local
php artisan soc:retention-cost --days=30 --environment=local
php artisan security:alerts-report --minutes=30
```

## Pages to Show

- `/soc`: SOC dashboard.
- `/soc/agents`: agent inventory, response workflow, containment simulation.
- `/soc/hunts`: threat hunting.
- `/soc/incidents/{incident_id}`: incident investigation.
- `/soc/threat-intel`: IOC import, external enrichment, and alert enrichment.
- `/soc/knowledge`: SOC knowledge base and RAG source material.
- `/soc/tuning`: false-positive feedback and suppression workflow.
- `/soc/reports`: executive and operational reports.

## Recommended Demo Story

1. Show dashboard summary and recent alerts.
2. Open an incident and explain evidence chain, MITRE mapping, and timeline.
3. Run a hunt query filtered by host/IP/process.
4. Add or enrich an IOC and show matched alert context.
5. Generate AI incident assistance and show guardrail/audit history.
6. Recommend a containment simulation such as `isolate-host`.
7. Approve it and show audit trail plus `containment_simulations`.
8. Run enterprise readiness commands and show stored validation reports.

## Safety Statement

This platform is defensive. Attack generation and containment features are built for lab validation and tabletop simulation. Containment actions do not modify live network, firewall, DNS, EDR, or endpoint policy state unless a future production integration is explicitly added and reviewed.
