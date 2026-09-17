# Lance l'application Flutter Mavi'oh sur Windows.
#   .\scripts\start-mobile.ps1                 -> telephone Android connecte, API sur l'IP LAN de ce PC
#   .\scripts\start-mobile.ps1 -Device chrome  -> dans le navigateur, API sur 127.0.0.1
#   .\scripts\start-mobile.ps1 -ApiBaseUrl https://api.mondomaine.fr/api
param(
    [string]$Device = "",
    [string]$ApiBaseUrl = "",
    [int]$BackendPort = 8000
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
. "$PSScriptRoot\_tools.ps1"

$flutter = Get-MaviohFlutter
Set-Location "$root\mobile"

if (-not $ApiBaseUrl) {
    if ($Device -eq "chrome" -or $Device -eq "edge" -or $Device -eq "windows") {
        $ApiBaseUrl = "http://127.0.0.1:${BackendPort}/api"
    }
    else {
        $ip = Get-MaviohLanIp
        $ApiBaseUrl = "http://${ip}:${BackendPort}/api"
    }
}

Write-Output "[mobile] API_BASE_URL = $ApiBaseUrl"
Write-Output "[mobile] Le backend doit tourner avec --host 0.0.0.0 (scripts\start-backend.ps1)"

$flutterArgs = @("run", "--dart-define=API_BASE_URL=$ApiBaseUrl")
if ($Device) { $flutterArgs += @("-d", $Device) }

& $flutter @flutterArgs
