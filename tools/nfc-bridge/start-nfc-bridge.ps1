$ErrorActionPreference = 'Stop'

$bridgeDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$logDir = Join-Path $bridgeDir 'logs'
$outLog = Join-Path $logDir 'bridge.out.log'
$errLog = Join-Path $logDir 'bridge.err.log'

if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir | Out-Null
}

Set-Location $bridgeDir

# Avoid launching duplicate bridge processes on every login/restart.
$existing = Get-CimInstance Win32_Process |
    Where-Object {
        $_.Name -eq 'node.exe' -and
        $_.CommandLine -like '*tools\nfc-bridge\server.cjs*'
    }

if ($existing) {
    exit 0
}

Start-Process -FilePath 'cmd.exe' `
    -ArgumentList '/c', 'npm start' `
    -WorkingDirectory $bridgeDir `
    -WindowStyle Minimized `
    -RedirectStandardOutput $outLog `
    -RedirectStandardError $errLog
