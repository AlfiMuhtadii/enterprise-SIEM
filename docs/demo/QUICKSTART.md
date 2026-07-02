# Quickstart Guide

## Prerequisites

- Docker Desktop installed and running
- PHP 8.2+ with Composer
- Python 3.9+

## One-Command Startup (Development)

### Windows (PowerShell)
```powershell
.\bootstrap-dev.ps1
```

### Linux/macOS (bash)
```bash
./bootstrap-dev.sh
```

## Manual Startup (3 commands)

```bash
# 1. Start infrastructure
docker compose up -d

# 2. Initialize Laravel and seed demo data
php artisan migrate:fresh --force && php artisan db:seed --class=DemoScenarioSeeder

# 3. Start Laravel dev server
php artisan serve
```

## Access

| URL | Purpose |
|-----|---------|
| `http://localhost:8000` | Laravel SOC dashboard |
| `http://localhost:8000/demo-platform` | Demo Platform Dashboard |
| `http://localhost:3000` | Grafana observability |
| `http://localhost:19092` | Redpanda broker |

## Demo Login

Register via `/register` or use the seeded demo account.

## Validate the Environment

```bash
php artisan migrate:fresh --force && php artisan test
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
python scripts/xdr_rule_registry_validate.py
```

Expected: **4544 PHP tests passed, 0 failures; 1556 Python tests; rules=133 PASS**

---

> **Note:** All demo scenarios are synthetic, replay-safe, and advisory-only. No destructive execution is performed.
