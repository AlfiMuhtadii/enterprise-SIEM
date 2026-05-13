param(
    [Parameter(Mandatory=$true)][string]$BackupFile,
    [string]$HostName = $env:DB_HOST,
    [string]$Port = $env:DB_PORT,
    [string]$Database = $env:DB_DATABASE,
    [string]$Username = $env:DB_USERNAME
)

$ErrorActionPreference = "Stop"
if (-not $HostName) { $HostName = "127.0.0.1" }
if (-not $Port) { $Port = "5432" }
if (-not $Database) { $Database = "detector" }
if (-not $Username) { $Username = "postgres" }

pg_restore --clean --if-exists --no-owner --host=$HostName --port=$Port --username=$Username --dbname=$Database $BackupFile
Write-Host "restored=$BackupFile"
