# Production Deployment

This deployment profile is intended for a single-node production or staging SOC instance.

## Required Defaults

Set these in `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_KEY=base64:...
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
SOC_WEBHOOK_SECRET=change-me
SOC_API_RATE_LIMIT_PER_MINUTE=120
SOC_EXPORT_MAX_ROWS=500
```

## Start

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prod-start.ps1
```

Manual equivalent:

```powershell
docker compose -f infra/production/docker-compose.production.yml up -d --build
docker compose -f infra/production/docker-compose.production.yml exec app php artisan migrate --force
docker compose -f infra/production/docker-compose.production.yml exec app php artisan config:cache
docker compose -f infra/production/docker-compose.production.yml exec app php artisan route:cache
docker compose -f infra/production/docker-compose.production.yml exec app php artisan view:cache
```

## Services

- `app`: Laravel SOC UI and API.
- `queue`: queue worker using `php artisan queue:work`.
- `scheduler`: runs Laravel scheduler every minute.
- `telemetry-worker`: consumes telemetry from Redpanda REST into Postgres.
- `postgres`: production database.

## Health

```text
GET /health/live
GET /health/ready
GET /soc/api/metrics
```

Use:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prod-health.ps1
```

## Scheduler

The scheduler runs:

- `ops:heartbeat` every minute.
- `soc:sla-escalate` every 15 minutes.
- `soc:notify-critical` every 15 minutes.
- `soc:sla-report` hourly.
- retention cleanup daily.

## Hardening

- Keep `APP_DEBUG=false`.
- Set `SESSION_SECURE_COOKIE=true` behind HTTPS.
- Set `SOC_WEBHOOK_SECRET`.
- Restrict `/soc/*` behind authenticated RBAC.
- Use reverse proxy TLS and trusted proxy settings.
- Keep exports limited with `SOC_EXPORT_MAX_ROWS`.
