param(
    [string]$BaseUrl = "http://127.0.0.1:8000"
)

$paths = @(
    "/.env",
    "/phpMyAdmin",
    "/wp-admin",
    "/vendor"
)

for ($i = 0; $i -lt 46; $i++) {
    $paths += "/scan/" + ([Guid]::NewGuid().ToString("N").Substring(0, 8))
}

foreach ($path in $paths) {
    try {
        Invoke-WebRequest -Uri ($BaseUrl + $path) -Method GET | Out-Null
    } catch {
        # ignore 404s
    }
}

Write-Host "Scan simulation finished: $($paths.Count) paths"
