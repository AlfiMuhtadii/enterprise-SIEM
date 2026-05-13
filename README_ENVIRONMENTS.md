# Environment Profiles

The platform supports three operational profiles:

- `local`: developer/demo environment.
- `staging`: production-like validation environment.
- `production`: hardened operational deployment.

## Profile Files

```text
.env.local.example
.env.staging.example
.env.production.example
```

## Validate Profile

```powershell
python scripts/validate_environment.py --profile local --env-file .env.local.example
python scripts/validate_environment.py --profile staging --env-file .env.staging.example
python scripts/validate_environment.py --profile production --env-file .env.production.example
```

Production validation requires:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_FORCE_HTTPS=true`
- `SESSION_SECURE_COOKIE=true`
- non-placeholder `APP_KEY`
- non-default `DB_PASSWORD`
- non-placeholder `SOC_WEBHOOK_SECRET`
- non-sync queue connection
- HTTPS `APP_URL`

## Deployment Verification

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prod-start.ps1
powershell -ExecutionPolicy Bypass -File scripts/prod-health.ps1
python scripts/load_test_soc.py --base-url http://127.0.0.1:8000 --duration 30 --concurrency 8
```

