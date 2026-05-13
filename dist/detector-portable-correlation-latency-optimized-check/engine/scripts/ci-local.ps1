param(
    [switch]$SkipDockerBuild
)

$ErrorActionPreference = "Stop"

php -l app\Http\Controllers\OpsHealthController.php
php -l app\Http\Controllers\SocDashboardController.php
php -l app\Http\Controllers\SocExportController.php
python -m compileall -q scripts
python scripts\validate_environment.py --profile local --env-file .env.example
python scripts\validate_environment.py --profile production --env-file .env.production.example --allow-placeholders
php artisan migrate:status
php artisan route:cache
php artisan route:clear
php artisan test
npm run build
docker compose -f infra\production\docker-compose.production.yml config --quiet
if (-not $SkipDockerBuild) {
    docker compose -f infra\production\docker-compose.production.yml build app
}
python scripts\export_portable_detector.py --output dist\detector-portable-ci-local --clean
