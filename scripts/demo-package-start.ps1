param(
    [switch]$SkipDocker,
    [switch]$SkipServe,
    [switch]$FreshDemo,
    [string]$BaseUrl = "http://127.0.0.1:8000"
)

$ErrorActionPreference = "Stop"

if (-not $SkipDocker) {
    docker compose up -d redpanda redpanda-console clickhouse grafana
}

if ($FreshDemo) {
    php artisan migrate:fresh --force
} else {
    php artisan migrate --force
}
php artisan db:seed --class=UserSeeder
php artisan db:seed --class=DemoSocSeeder
python scripts/generate_demo_screenshots.py

if (-not $SkipServe) {
    Write-Host "Starting Laravel app at $BaseUrl"
    php artisan serve --host=127.0.0.1 --port=8000
} else {
    Write-Host "Demo data is ready."
    Write-Host "Start app manually: php artisan serve --host=127.0.0.1 --port=8000"
}
