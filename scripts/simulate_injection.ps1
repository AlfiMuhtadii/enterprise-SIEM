param(
    [string]$BaseUrl = "http://127.0.0.1:8000"
)

Add-Type -AssemblyName System.Web

$payloads = @(
    "' OR 1=1--",
    "<script>alert(1)</script>"
)

foreach ($payload in $payloads) {
    $encoded = [System.Web.HttpUtility]::UrlEncode($payload)
    try {
        Invoke-WebRequest -Uri "$BaseUrl/search?q=$encoded" -Method GET | Out-Null
    } catch {
        # ignore errors
    }
}

Write-Host "Injection simulation finished: $($payloads.Count) requests"
