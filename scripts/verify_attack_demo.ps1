param(
    [string]$BaseUrl = "http://127.0.0.1:8000",
    [int]$Minutes = 15,
    [int]$TailLines = 4000
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-PropValue {
    param(
        [object]$Obj,
        [string]$Name,
        [object]$Default = $null
    )
    if ($null -eq $Obj) {
        return $Default
    }
    $p = $Obj.PSObject.Properties[$Name]
    if ($null -eq $p) {
        return $Default
    }
    return $p.Value
}

function Test-AppUp {
    param([string]$Url)
    try {
        $null = Invoke-WebRequest -Uri "$Url/login" -Method Get -UseBasicParsing -TimeoutSec 5
        return $true
    }
    catch {
        return $false
    }
}

function Get-CsrfToken {
    param([string]$Html)
    $m = [regex]::Match($Html, 'name="_token"\s+value="([^"]+)"')
    if (-not $m.Success) {
        throw "CSRF token not found on login page."
    }
    return $m.Groups[1].Value
}

function Invoke-Login {
    param(
        [string]$Url,
        [string]$Email,
        [string]$Password
    )

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $loginPage = Invoke-WebRequest -Uri "$Url/login" -Method Get -WebSession $session -UseBasicParsing
    $token = Get-CsrfToken -Html $loginPage.Content

    $form = @{
        _token = $token
        email = $Email
        password = $Password
    }

    $resp = Invoke-WebRequest -Uri "$Url/login" -Method Post -Body $form -WebSession $session -MaximumRedirection 0 -ErrorAction SilentlyContinue
    return @{
        Session = $session
        StatusCode = if ($resp) { $resp.StatusCode } else { 302 }
    }
}

function Invoke-AdminProbe {
    param(
        [string]$Url,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )
    try {
        $resp = Invoke-WebRequest -Uri "$Url/admin" -Method Get -WebSession $Session -UseBasicParsing -ErrorAction Stop
        return $resp.StatusCode
    }
    catch {
        if ($_.Exception.Response) {
            return [int]$_.Exception.Response.StatusCode
        }
        throw
    }
}

if (-not (Test-AppUp -Url $BaseUrl)) {
    Write-Host "App is not reachable at $BaseUrl. Start server first: php artisan serve"
    exit 1
}

Write-Host "[1/5] Generate success login + 403 probe..."
$userLogin = Invoke-Login -Url $BaseUrl -Email "user@example.com" -Password "password"
$adminStatus = Invoke-AdminProbe -Url $BaseUrl -Session $userLogin.Session
Write-Host "User login status: $($userLogin.StatusCode), /admin status: $adminStatus"

Write-Host "[2/5] Run attack simulators..."
php artisan sim:bruteforce --base-url=$BaseUrl
php artisan sim:scan --base-url=$BaseUrl
php artisan sim:injection --base-url=$BaseUrl

Write-Host "[3/5] Report from DB (last $Minutes minutes)..."
php artisan security:report --minutes=$Minutes

Write-Host "[4/5] Alert report (last $Minutes minutes)..."
php artisan security:alerts-report --minutes=$Minutes

Write-Host "[5/5] Validate raw security log checklist..."
$logPath = "storage/logs/security.jsonl"
if (-not (Test-Path $logPath)) {
    Write-Host "FAIL: $logPath not found."
    exit 2
}

$rows = Get-Content $logPath -Tail $TailLines | ForEach-Object {
    try { $_ | ConvertFrom-Json } catch { $null }
} | Where-Object { $_ -ne $null }

$cntLoginSuccess = ($rows | Where-Object {
    $eventName = Get-PropValue $_ "event_type"
    if (-not $eventName) { $eventName = Get-PropValue $_ "event" "" }
    $eventName -eq "auth_login_success"
}).Count

$cntLoginFailed = ($rows | Where-Object {
    $eventName = Get-PropValue $_ "event_type"
    if (-not $eventName) { $eventName = Get-PropValue $_ "event" "" }
    $eventName -eq "auth_login_failed"
}).Count

$cntAuthzDenied = ($rows | Where-Object {
    $eventName = Get-PropValue $_ "event_type"
    if (-not $eventName) { $eventName = Get-PropValue $_ "event" "" }
    $path = Get-PropValue $_ "path" ""
    $status = Get-PropValue $_ "status" 0
    ($eventName -eq "authorization_denied") -or ($path -eq "/admin" -and [int]$status -eq 403)
}).Count

$cnt404 = ($rows | Where-Object {
    [int](Get-PropValue $_ "status" 0) -eq 404
}).Count

$cntInjection = ($rows | Where-Object {
    (Get-PropValue $_ "has_sql_keywords" $false) -eq $true -or (Get-PropValue $_ "has_script_payload" $false) -eq $true
}).Count

Write-Host "Checklist counts (tail $TailLines rows):"
Write-Host " - auth_login_success: $cntLoginSuccess"
Write-Host " - auth_login_failed:  $cntLoginFailed"
Write-Host " - authorization_denied or /admin 403: $cntAuthzDenied"
Write-Host " - status 404:         $cnt404"
Write-Host " - injection indicators: $cntInjection"

$pass = $true
if ($cntLoginSuccess -lt 1) { Write-Host "FAIL: missing login success signal"; $pass = $false }
if ($cntLoginFailed -lt 1) { Write-Host "FAIL: missing login failed signal"; $pass = $false }
if ($cntAuthzDenied -lt 1) { Write-Host "FAIL: missing authorization denied signal"; $pass = $false }
if ($cnt404 -lt 1) { Write-Host "FAIL: missing 404 scan signal"; $pass = $false }
if ($cntInjection -lt 1) { Write-Host "FAIL: missing injection indicator signal"; $pass = $false }

if ($pass) {
    Write-Host "PASS: demo telemetry + attack signals are present."
    exit 0
}

exit 3
