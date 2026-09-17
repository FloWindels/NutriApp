# Lance l'API Laravel de Mavi'oh sur Windows.
#   .\scripts\start-backend.ps1            -> http://0.0.0.0:8000 (joignable depuis le téléphone)
#   .\scripts\start-backend.ps1 -Port 8001
param(
    [string]$BindHost = "0.0.0.0",
    [int]$Port = 8000
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
. "$PSScriptRoot\_tools.ps1"

$php = Get-MaviohPhp
Set-Location "$root\backend"

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    & $php artisan key:generate
}
if (-not (Test-Path "database\database.sqlite")) {
    New-Item -ItemType File "database\database.sqlite" | Out-Null
    & $php artisan migrate --force --seed
}

Write-Output "[backend] API Laravel sur http://${BindHost}:${Port}"
Write-Output "[backend] Depuis un telephone du meme reseau : http://<IP-de-ce-PC>:${Port}/api"
& $php artisan serve --host $BindHost --port $Port
