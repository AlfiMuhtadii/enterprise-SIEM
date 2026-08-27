param(
    [string]$Python = "python",
    [string]$RepoPath = "C:\Detector",
    [string]$ConfigPath = "",
    [string]$InstallPath = "$env:ProgramFiles\Detector\EndpointAgent"
)

$ErrorActionPreference = "Stop"
$packageRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$packageManifest = Join-Path $packageRoot "MANIFEST.json"
$pythonCommand = Get-Command $Python -ErrorAction Stop

if (Test-Path -LiteralPath $packageManifest -PathType Leaf) {
    $verifier = Join-Path $packageRoot "verify_agent_package.py"
    & $pythonCommand.Source $verifier --package $packageRoot
    if ($LASTEXITCODE -ne 0) {
        throw "Endpoint agent package verification failed with exit code $LASTEXITCODE."
    }
    $resolvedPackageRoot = [IO.Path]::GetFullPath($packageRoot).TrimEnd('\')
    $resolvedInstallPath = [IO.Path]::GetFullPath($InstallPath).TrimEnd('\')
    if ($resolvedPackageRoot -ne $resolvedInstallPath) {
        if (Test-Path -LiteralPath $resolvedInstallPath) {
            $existing = Get-ChildItem -LiteralPath $resolvedInstallPath -Force | Select-Object -First 1
            if ($null -ne $existing) {
                throw "InstallPath must be empty for a verified package install: $resolvedInstallPath"
            }
        }
        New-Item -ItemType Directory -Path $resolvedInstallPath -Force | Out-Null
        $manifest = Get-Content -LiteralPath $packageManifest -Raw | ConvertFrom-Json
        $packageFiles = @($manifest.files.path) + @("MANIFEST.json", "MANIFEST.sha256")
        foreach ($relativePath in $packageFiles) {
            $windowsPath = $relativePath.Replace('/', '\')
            $sourcePath = Join-Path $resolvedPackageRoot $windowsPath
            $destinationPath = Join-Path $resolvedInstallPath $windowsPath
            New-Item -ItemType Directory -Path (Split-Path -Parent $destinationPath) -Force | Out-Null
            Copy-Item -LiteralPath $sourcePath -Destination $destinationPath
        }
        $installedVerifier = Join-Path $resolvedInstallPath "verify_agent_package.py"
        & $pythonCommand.Source $installedVerifier --package $resolvedInstallPath
        if ($LASTEXITCODE -ne 0) {
            throw "Installed endpoint agent package verification failed with exit code $LASTEXITCODE."
        }
        $packageRoot = $resolvedInstallPath
    }
    $script = Join-Path $packageRoot "agent.py"
    if ([string]::IsNullOrEmpty($ConfigPath)) {
        $ConfigPath = Join-Path $packageRoot "config.json"
    }
} else {
    $script = Join-Path $RepoPath "services\endpoint-agent\agent.py"
    if ([string]::IsNullOrEmpty($ConfigPath)) {
        $ConfigPath = Join-Path $RepoPath "services\endpoint-agent\config.json"
    }
}
if (-not (Test-Path -LiteralPath $script -PathType Leaf)) {
    throw "Agent script not found at $script."
}
if (-not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
    throw "Config file not found at $ConfigPath -- copy services\endpoint-agent\config.json.example and edit it first."
}
$serviceArgs = "`"$script`" --config `"$ConfigPath`""

New-Service -Name "DetectorEndpointAgent" -BinaryPathName "`"$($pythonCommand.Source)`" $serviceArgs" -DisplayName "Detector Endpoint Agent" -StartupType Automatic
Start-Service DetectorEndpointAgent
