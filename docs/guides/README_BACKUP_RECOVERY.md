# Backup and Recovery

## Database Backup

Requires `pg_dump` and `pg_restore` on the host.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/backup_database.ps1
```

The script writes a custom-format PostgreSQL dump to `storage/backups` and verifies it with `pg_restore --list`.

## Restore

```powershell
powershell -ExecutionPolicy Bypass -File scripts/restore_database.ps1 -BackupFile storage/backups/detector-YYYYMMDD-HHMMSS.dump
```

Restore uses:

```text
pg_restore --clean --if-exists --no-owner
```

## Incident Archive Export

```powershell
python scripts/incident_archive_export.py --output storage/archive/incidents.jsonl
```

## Retention Cleanup

```powershell
python scripts/storage_maintenance.py --archive --retention-days 30
python scripts/storage_maintenance.py --cleanup --retention-days 30
```

## Recovery Checklist

1. Restore database dump.
2. Run `php artisan migrate --force`.
3. Run `php artisan config:cache route:cache view:cache`.
4. Verify `/health/ready`.
5. Verify `/soc/api/metrics`.
6. Run `php artisan soc:sla-report`.
7. Confirm latest incidents and alerts in `/soc`.
