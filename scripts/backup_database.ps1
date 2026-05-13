param(
    [string]$OutDir = $env:BACKUP_DIR,
    [string]$HostName = $env:DB_HOST,
    [string]$Port = $env:DB_PORT,
    [string]$Database = $env:DB_DATABASE,
    [string]$Username = $env:DB_USERNAME
)

$ErrorActionPreference = "Stop"
if (-not $OutDir) { $OutDir = "storage/backups" }
if (-not $HostName) { $HostName = "127.0.0.1" }
if (-not $Port) { $Port = "5432" }
if (-not $Database) { $Database = "detector" }
if (-not $Username) { $Username = "postgres" }

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$file = Join-Path $OutDir "detector-$stamp.dump"
pg_dump --format=custom --host=$HostName --port=$Port --username=$Username --dbname=$Database --file=$file
pg_restore --list $file | Out-Null
Write-Host "backup=$file"
