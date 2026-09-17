# Lance le site web Next.js de Mavi'oh sur Windows.
#   .\scripts\start-web.ps1              -> http://localhost:3000
param(
    [int]$Port = 3000
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
. "$PSScriptRoot\_tools.ps1"

$npm = Get-MaviohNpm
Set-Location "$root\web"

if (-not (Test-Path ".env.local")) {
    Copy-Item ".env.example" ".env.local"
    Write-Output "[web] .env.local cree depuis .env.example"
}
if (-not (Test-Path "node_modules")) {
    Write-Output "[web] Installation des dependances (npm ci)..."
    & $npm ci
}

Write-Output "[web] Site sur http://localhost:${Port}"
& $npm run dev -- --port $Port
