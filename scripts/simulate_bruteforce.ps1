param(
    [string]$BaseUrl = "http://127.0.0.1:8000",
    [int]$Attempts = 40,
    [string]$SourceIp = "10.0.0.10"
)

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest -Uri "$BaseUrl/login" -WebSession $session

$token = $null
if ($loginPage.Content -match 'name="_token"\s+value="([^"]+)"') {
    $token = $Matches[1]
}

if (-not $token) {
    Write-Host "CSRF token not found. Is the app running?"
    exit 1
}

for ($i = 1; $i -le $Attempts; $i++) {
    $body = @{
        email = "attacker$i@example.com"
        password = "wrongpassword"
        _token = $token
    }

    try {
        Invoke-WebRequest -Uri "$BaseUrl/login" -Method POST -WebSession $session -Headers @{
            "X-Forwarded-For" = $SourceIp
        } -Body $body | Out-Null
    } catch {
        # ignore errors for demo
    }
}

Write-Host "Bruteforce simulation finished: $Attempts attempts from $SourceIp"
