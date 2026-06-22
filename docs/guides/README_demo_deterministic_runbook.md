# Deterministic Live-Demo Runbook (No Chance-Based Demo)

Script:
- `scripts/demo_runbook.py`

## Prerequisites (Hard Dependency)

Wajib sehat sebelum run:
- Docker Engine/Desktop running
- Compose services `redpanda`, `clickhouse`, `grafana` status `Up`
- App endpoint reachable (`/login`)

Fast check:

```bash
python scripts/preflight.py --base-url http://127.0.0.1:8000
```

Jika preflight gagal, jangan lanjut runbook.

## Commands

```bash
python scripts/demo_runbook.py up
python scripts/demo_runbook.py reset
python scripts/demo_runbook.py run --base-url http://127.0.0.1:8000
python scripts/demo_runbook.py verify --min-events 120 --min-alerts 10 --min-responses 5 --max-alert-age-sec 90
python scripts/demo_runbook.py open
```

One command:

```bash
python scripts/demo_runbook.py full --base-url http://127.0.0.1:8000
```

> Catatan: `php artisan serve` harus sudah jalan sebelum `run/full`.

## Hard Assertions (Fail Fast)

`demo_runbook.py verify` akan exit non-zero jika salah satu gagal:
- `security_events >= min-events`
- `security_alerts >= min-alerts`
- `security_responses >= min-responses` (default > 0)
- `latest alert age <= max-alert-age-sec`

## Why this removes `ResponsesCreated: 0` risk

- Realtime detector sekarang menghasilkan response **on alert edge** (saat alert dibuat), bukan menunggu polling window manual.
- Mapping response:
  - bruteforce/stuffing -> throttle login IP
  - scan/injection -> force captcha IP
  - privilege probing -> revoke session user
- Jika IP allowlist, response disimpan sebagai `suppressed` (tetap terlihat sebagai kontrol FP).

## Before/After evidence (opsional Q&A)

- Throttle:
  - `POST /login` JSON -> `429 THROTTLED`
- Captcha step-up:
  - `POST /login` JSON tanpa `captcha_token` -> `403 CHALLENGE_REQUIRED`
- Revoke session:
  - user login, lalu request berikutnya -> logout paksa via middleware `EnforceRevokedSessions`
