param(
    [string]$ComposeFile = "docker-compose.yml"
)

$ErrorActionPreference = "Stop"
docker compose -f $ComposeFile --profile app up -d --build
docker compose -f $ComposeFile exec app php artisan migrate --force
docker compose -f $ComposeFile exec app php artisan config:cache
docker compose -f $ComposeFile exec app php artisan route:cache
docker compose -f $ComposeFile exec app php artisan view:cache
docker compose -f $ComposeFile ps
