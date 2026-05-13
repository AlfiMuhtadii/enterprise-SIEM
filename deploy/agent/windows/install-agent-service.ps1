param(
    [string]$Python = "python",
    [string]$RepoPath = "C:\Detector",
    [string]$ServerUrl = "http://127.0.0.1:8000",
    [string]$EnrollmentToken = "",
    [int]$Interval = 60
)

$ErrorActionPreference = "Stop"
$script = Join-Path $RepoPath "scripts\endpoint_telemetry_agent.py"
$args = "`"$script`" --daemon --server-url `"$ServerUrl`" --enrollment-token `"$EnrollmentToken`" --interval $Interval --state-file `"$RepoPath\storage\app\endpoint_agent_state.json`" --buffer-file `"$RepoPath\storage\app\endpoint_agent_retry_queue.jsonl`""

New-Service -Name "DetectorEndpointAgent" -BinaryPathName "$Python $args" -DisplayName "Detector Endpoint Agent" -StartupType Automatic
Start-Service DetectorEndpointAgent

