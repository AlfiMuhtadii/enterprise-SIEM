# Dummy Security Demo Runbook

This runbook makes the demo reproducible in a few commands.

## 1) Reset DB + Seed Known Accounts

```bash
php artisan migrate:fresh --seed
```

Seeded credentials:

- `admin@example.com` / `password` (role: `admin`)
- `user@example.com` / `password` (role: `user`)

## 2) Start App

```bash
php artisan serve
```

Default URL: `http://127.0.0.1:8000`

## 3) Run Attack Simulations (Cross-platform)

In another terminal:

```bash
php artisan sim:bruteforce
php artisan sim:scan
php artisan sim:injection
```

Optional parameters:

- `php artisan sim:bruteforce --attempts=50 --ip=203.0.113.10`
- `php artisan sim:bruteforce --vary-ip=1`
- `php artisan sim:scan --count=50`
- `php artisan sim:injection --base-url=http://127.0.0.1:8000`

## 4) Generate 403 Pattern

Login as `user@example.com`, then open:

`/admin`

This should return `403` and emit `authorization_denied`.

## 5) Inspect Security Logs

PowerShell:

```powershell
Get-Content .\storage\logs\security.jsonl -Tail 50
```

Bash:

```bash
tail -n 50 storage/logs/security.jsonl
```

## 6) Hard Validation Checklist

Confirm these signals exist in `storage/logs/security.jsonl`:

- `auth_login_success` after valid login
- burst of `auth_login_failed` after `sim:bruteforce`
- `authorization_denied` after non-admin opens `/admin`
- many `http_request` with `status` `404` after `sim:scan`
- `/search` requests contain `query_hash`
- suspicious `/search` requests set `has_sql_keywords` and/or `has_script_payload`

## Notes

- App only trusts proxies listed in `TRUSTED_PROXIES`. Default local demo value is `127.0.0.1,::1`, so simulated `X-Forwarded-*` headers still work via local `php artisan serve`.
- PII safety: logs store hashed `email`/`user-agent` only. Raw password/email are not logged.
- Query safety: raw `/search` query text is not logged; only hash + indicator flags are stored.
