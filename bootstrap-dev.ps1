# bootstrap-dev.ps1
# Development environment bootstrap for XDR Platform demo.
# DEVELOPMENT ONLY — runs migrate:fresh which drops all data.
# All demo scenarios are synthetic, replay-safe, advisory-only, and bounded.

param(
    [switch]$SkipDockerCheck,
    [switch]$SkipSeed
)

$ErrorActionPreference = "Stop"

Write-Host "=== XDR Platform Bootstrap (Development) ===" -ForegroundColor Cyan
Write-Host "Advisory only — no destructive execution, no real malware, no autonomous remediation." -ForegroundColor Yellow
Write-Host ""

# Step 1 — Docker health check
if (-not $SkipDockerCheck) {
    Write-Host "[1/5] Checking Docker..." -ForegroundColor Green
    try {
        $null = docker info 2>&1
        Write-Host "  Docker: OK" -ForegroundColor Green
    } catch {
        Write-Host "  ERROR: Docker not running. Start Docker Desktop first." -ForegroundColor Red
        exit 1
    }
}

# Step 2 — Start infrastructure services
Write-Host "[2/5] Starting infrastructure services..." -ForegroundColor Green
docker compose up -d --wait 2>&1 | Out-Null
Write-Host "  Services: started" -ForegroundColor Green

# Step 3 — Fresh database migration (dev only)
Write-Host "[3/5] Running fresh migration (DEV ONLY — drops all data)..." -ForegroundColor Yellow
php artisan migrate:fresh --force
if ($LASTEXITCODE -ne 0) {
    Write-Host "  ERROR: Migration failed." -ForegroundColor Red
    exit 1
}
Write-Host "  Migration: OK" -ForegroundColor Green

# Step 4 — Seed demo fixtures
if (-not $SkipSeed) {
    Write-Host "[4/5] Seeding demo fixtures..." -ForegroundColor Green
    php artisan db:seed --class=DemoScenarioSeeder
    if ($LASTEXITCODE -ne 0) {
        Write-Host "  WARNING: Seeder failed. Continuing without demo data." -ForegroundColor Yellow
    } else {
        Write-Host "  Seed: OK" -ForegroundColor Green
    }
} else {
    Write-Host "[4/5] Skipping seed (--SkipSeed)" -ForegroundColor Gray
}

# Step 5 — Startup validation
Write-Host "[5/5] Running startup validation..." -ForegroundColor Green
$result = php artisan route:list --path=demo-platform 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "  Routes: OK" -ForegroundColor Green
} else {
    Write-Host "  WARNING: Route check failed. Check Laravel logs." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Bootstrap complete ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Platform URL:    http://localhost:8000" -ForegroundColor White
Write-Host "  Demo Dashboard:  http://localhost:8000/demo-platform" -ForegroundColor White
Write-Host "  Grafana:         http://localhost:3000" -ForegroundColor White
Write-Host ""
Write-Host "Start the Laravel server:" -ForegroundColor Yellow
Write-Host "  php artisan serve" -ForegroundColor White
Write-Host ""
Write-Host "Run tests to verify the environment:" -ForegroundColor Yellow
Write-Host "  php artisan test" -ForegroundColor White
Write-Host "  python -m unittest discover -s tests/endpoint_agent -p 'test_*.py' -v" -ForegroundColor White
