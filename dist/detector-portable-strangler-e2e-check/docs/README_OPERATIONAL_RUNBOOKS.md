# Operational Runbooks

## Incident Response Workflow

1. Open `/soc` and review severity summary, recent alerts, and open incidents.
2. Open the incident detail page and review related alerts, evidence chain, affected entities, MITRE mapping, and timeline.
3. Assign an analyst and move the incident to `triaged`.
4. Add investigation notes for every major finding.
5. Escalate critical incidents or SLA-risk incidents.
6. Resolve with a resolution summary, or mark as `false_positive` with evidence.
7. Review audit trail after closure.

CLI fallback:

```powershell
python scripts/soc_workflow.py list
python scripts/soc_workflow.py assign --incident-id INC-123 --analyst analyst@example.com
python scripts/soc_workflow.py note --incident-id INC-123 --body "Initial investigation started"
python scripts/soc_workflow.py status --incident-id INC-123 --status investigating
```

## Backup and Restore

Daily backup:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/backup_database.ps1
```

Restore:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/restore_database.ps1 -BackupFile storage/backups/detector-YYYYMMDD-HHMMSS.dump
php artisan migrate --force
```

Verify:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prod-health.ps1
php artisan soc:sla-report
```

## Failed Queue Worker Recovery

1. Check `/soc/api/metrics` for `queue.failed_jobs`.
2. Check production containers:

```powershell
docker compose -f infra/production/docker-compose.production.yml ps
docker compose -f infra/production/docker-compose.production.yml logs queue --tail=100
```

3. Restart queue worker:

```powershell
docker compose -f infra/production/docker-compose.production.yml restart queue
```

4. Inspect failed jobs:

```powershell
php artisan queue:failed
```

5. Retry only after root cause is fixed:

```powershell
php artisan queue:retry all
```

## Notification Delivery Failure Handling

1. Check `/soc/api/metrics` notification summary.
2. Verify `SOC_WEBHOOK_URL`, `SOC_SLACK_URL`, and `SOC_DISCORD_URL`.
3. Confirm target platform is reachable from the server.
4. Re-run notification command after fixing the endpoint:

```powershell
php artisan soc:notify-critical --minutes=60
```

5. Review `notification_delivery_logs` and audit trails.

## Production Restart Procedure

Graceful restart:

```powershell
docker compose -f infra/production/docker-compose.production.yml restart app queue scheduler telemetry-worker
```

Full deployment refresh:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prod-start.ps1
powershell -ExecutionPolicy Bypass -File scripts/prod-health.ps1
```

## Telemetry Ingestion Troubleshooting

1. Check Redpanda REST URL and topic:

```powershell
Invoke-RestMethod http://127.0.0.1:8082/topics
```

2. Check telemetry worker logs:

```powershell
docker compose -f infra/production/docker-compose.production.yml logs telemetry-worker --tail=100
```

3. Check ingestion lag:

```text
GET /soc/api/metrics
```

4. Validate sample telemetry file:

```powershell
python scripts/load_test_soc.py --telemetry-jsonl storage/app/telemetry_sample.jsonl --duration 5
```

5. Check dead-letter file:

```text
storage/logs/telemetry_dead_letter.jsonl
```

