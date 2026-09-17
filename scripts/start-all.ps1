# Lance l'API Laravel et le site Next.js de Mavi'oh dans deux fenetres separees.
#   .\scripts\start-all.ps1
param(
    [int]$BackendPort = 8000,
    [int]$WebPort = 3000
)

$ErrorActionPreference = "Stop"
$scripts = $PSScriptRoot
. "$scripts\_tools.ps1"

$ip = Get-MaviohLanIp

Start-Process powershell -ArgumentList @(
    "-NoExit", "-ExecutionPolicy", "Bypass",
    "-File", "$scripts\start-backend.ps1", "-Port", $BackendPort
)

Start-Process powershell -ArgumentList @(
    "-NoExit", "-ExecutionPolicy", "Bypass",
    "-File", "$scripts\start-web.ps1", "-Port", $WebPort
)

Write-Output ""
Write-Output "[mavioh] API      : http://localhost:${BackendPort}/api"
Write-Output "[mavioh] Site web : http://localhost:${WebPort}"
Write-Output "[mavioh] Mobile   : .\scripts\start-mobile.ps1   (API http://${ip}:${BackendPort}/api)"
Write-Output "[mavioh] Compte de demonstration : demo@mavioh.app / Demo1234!"
