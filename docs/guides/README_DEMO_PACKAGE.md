# Demo Package Quickstart

This package prepares a reviewer-friendly SOC demo with seeded users, incidents, alerts, telemetry, audit events, and generated SVG screenshots.

## One-Command Startup

```powershell
powershell -ExecutionPolicy Bypass -File scripts/demo-package-start.ps1
```

If Docker services are already running:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/demo-package-start.ps1 -SkipDocker
```

If the Laravel app is already running:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/demo-package-start.ps1 -SkipDocker -SkipServe
```

For a disposable reviewer database, reset and seed everything:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/demo-package-start.ps1 -FreshDemo
```

`-FreshDemo` is destructive because it runs `migrate:fresh`; use it only for demo/staging databases.

## Demo Accounts

```text
Admin:   soc-admin@example.com / password
Analyst: soc-analyst@example.com / password
Viewer:  soc-viewer@example.com / password
```

## Reviewer Walkthrough

1. Login as `soc-admin@example.com`.
2. Open `/soc` and review operational summary, incident trend, severity summary, MITRE overview, and recent alerts.
3. Open `DEMO-INC-001` to inspect evidence chain, related alerts, affected entities, notes, workflow history, and MITRE mapping.
4. Login as `soc-analyst@example.com` and update an incident status or add a note.
5. Open export actions and download JSONL, SIEM JSONL, or STIX-like export.
6. Open audit trail API or dashboard audit section to verify sensitive actions are recorded.
7. Review telemetry correlation using the seeded telemetry and sample real-world datasets.

## Demo Screenshots

Generated SVG screenshots:

```text
demo/screenshots/dashboard.svg
demo/screenshots/incident-detail.svg
demo/screenshots/exports-audit.svg
```

Regenerate:

```powershell
python scripts/generate_demo_screenshots.py
```

## Dataset Trial

Run realistic sample validation:

```powershell
python scripts/real_dataset_validation.py --manifest samples/real-world/manifest.json --output reports/real_dataset_validation_demo.json
```

The report summarizes normalized events, invalid events, false positives, false negatives, alert quality, MITRE coverage, and replay stability.
