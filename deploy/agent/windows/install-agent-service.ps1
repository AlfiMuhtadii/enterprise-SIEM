param(
    [string]$Python = "python",
    [string]$RepoPath = "C:\Detector",
    [string]$ConfigPath = ""
)

$ErrorActionPreference = "Stop"
$script = Join-Path $RepoPath "services\endpoint-agent\agent.py"
if ([string]::IsNullOrEmpty($ConfigPath)) {
    $ConfigPath = Join-Path $RepoPath "services\endpoint-agent\config.json"
}
if (-not (Test-Path $ConfigPath)) {
    throw "Config file not found at $ConfigPath -- copy services\endpoint-agent\config.json.example and edit it first."
}
$args = "`"$script`" --config `"$ConfigPath`""

New-Service -Name "DetectorEndpointAgent" -BinaryPathName "$Python $args" -DisplayName "Detector Endpoint Agent" -StartupType Automatic
Start-Service DetectorEndpointAgent

