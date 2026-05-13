param(
    [string]$BaseUrl = "http://127.0.0.1:8000",
    [int]$MinEvents = 50,
    [int]$MinAlerts = 5,
    [int]$MinResponses = 5,
    [int]$MaxAlertAgeSec = 180,
    [switch]$ManualApp = $true,
    [switch]$SkipUp,
    [switch]$SkipReset
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Say($text) {
    Write-Host ""
    Write-Host "=== $text ===" -ForegroundColor Cyan
}

function Run-Step($title, $command) {
    Say $title
    Write-Host $command -ForegroundColor DarkGray
    Invoke-Expression $command
}

function Test-Url($url) {
    try {
        $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 5
        return ($resp.StatusCode -lt 500)
    } catch {
        return $false
    }
}

Say "FINAL PRESENTATION FLOW"
Write-Host "Base URL: $BaseUrl"
Write-Host "Manual App Mode: $ManualApp"

if (-not $SkipUp) {
    Run-Step "1) Bring up infra + migrate seed" "python scripts/demo_runbook.py up"
} else {
    Say "1) Bring up infra + migrate seed (SKIPPED)"
}

if (-not $SkipReset) {
    Run-Step "2) Reset deterministic state" "python scripts/demo_runbook.py reset"
} else {
    Say "2) Reset deterministic state (SKIPPED)"
}

if ($ManualApp) {
    Say "3) Manual app prerequisite"
    Write-Host "Start the Laravel app in a separate terminal before continuing:"
    Write-Host "php artisan serve --host=127.0.0.1 --port=8000" -ForegroundColor Yellow
    Write-Host "Then rerun this script with -SkipUp -SkipReset if those steps already completed."
    if (-not (Test-Url "$BaseUrl/login")) {
        throw "App is not reachable at $BaseUrl. Start php artisan serve first."
    }
    Run-Step "4) Run deterministic attack scenario" "python scripts/demo_runbook.py run --base-url $BaseUrl --auto-serve=0"
} else {
    Run-Step "3) Run deterministic attack scenario" "python scripts/demo_runbook.py run --base-url $BaseUrl --auto-serve=1"
}
Run-Step "5) Verify end-to-end assertions" "python scripts/demo_runbook.py verify --min-events $MinEvents --min-alerts $MinAlerts --min-responses $MinResponses --max-alert-age-sec $MaxAlertAgeSec"
Run-Step "6) Show alert summary (rules + ML)" "php artisan security:alerts-report --minutes=15"
Run-Step "7) Show pipeline health" "php artisan security:pipeline-health --minutes=15"
Run-Step "8) Show URLs for live dashboard" "python scripts/demo_runbook.py open --base-url $BaseUrl"
Run-Step "9) Ensure active MLOps deployment" "python scripts/mlops_ensure_active_deployment.py --env local"
Run-Step "10) Show MLOps status (drift + retrain policy)" "python scripts/mlops_drift_monitor.py --dataset storage/app/security_dataset.csv --env local --lookback-hours 24 --output storage/app/ml_drift_report.json"
Run-Step "11) Show retrain policy decision" "python scripts/mlops_retrain_policy.py --env local --drift-report storage/app/ml_drift_report.json --output storage/app/ml_retrain_policy.json"

Say "DONE"
Write-Host "Use these files for slides/Q&A:"
Write-Host "- reports/phase10/report.json"
Write-Host "- README_phase12_mlops.md"
Write-Host "- README_phase13_threat_model.md"
Write-Host "- README_phase14_response.md"
