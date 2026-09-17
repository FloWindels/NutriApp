# Resolution des outils (PHP, Composer, Node, Flutter) pour les scripts Windows de Mavi'oh.
# Cherche d'abord dans le PATH, puis dans la copie portable %LOCALAPPDATA%\mavioh-tools.

function Get-MaviohToolsRoot {
    return (Join-Path $env:LOCALAPPDATA "mavioh-tools")
}

function Resolve-MaviohTool {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$PortableRelativePath,
        [Parameter(Mandatory = $true)][string]$InstallHint
    )

    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    $portable = Join-Path (Get-MaviohToolsRoot) $PortableRelativePath
    if (Test-Path $portable) { return $portable }

    throw "$Name introuvable. $InstallHint"
}

function Get-MaviohPhp {
    return Resolve-MaviohTool -Name "php" -PortableRelativePath "php\php.exe" `
        -InstallHint "Installe PHP 8.1+ (https://windows.php.net/download) ou place-le dans $(Get-MaviohToolsRoot)\php."
}

function Get-MaviohComposer {
    $phar = Join-Path (Get-MaviohToolsRoot) "composer.phar"
    if (Test-Path $phar) { return $phar }
    $cmd = Get-Command composer -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw "Composer introuvable. Telecharge composer.phar depuis https://getcomposer.org/download/."
}

function Get-MaviohNpm {
    return Resolve-MaviohTool -Name "npm" -PortableRelativePath "node\npm.cmd" `
        -InstallHint "Installe Node.js LTS (https://nodejs.org) ou place-le dans $(Get-MaviohToolsRoot)\node."
}

function Get-MaviohFlutter {
    return Resolve-MaviohTool -Name "flutter" -PortableRelativePath "flutter\bin\flutter.bat" `
        -InstallHint "Installe Flutter (https://docs.flutter.dev/get-started/install/windows) ou place-le dans $(Get-MaviohToolsRoot)\flutter."
}

function Get-MaviohLanIp {
    $ip = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" -and $_.PrefixOrigin -ne "WellKnown" } |
        Sort-Object -Property InterfaceMetric |
        Select-Object -First 1 -ExpandProperty IPAddress
    if (-not $ip) { return "127.0.0.1" }
    return $ip
}
