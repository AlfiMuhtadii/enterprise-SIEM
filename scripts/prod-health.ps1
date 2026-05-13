param(
    [string]$BaseUrl = "http://127.0.0.1:8000"
)

$ErrorActionPreference = "Stop"
Invoke-RestMethod "$BaseUrl/health/live"
Invoke-RestMethod "$BaseUrl/health/ready"
