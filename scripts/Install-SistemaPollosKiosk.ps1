param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ $_.Scheme -in @('http', 'https') })]
    [uri]$Url,

    [ValidateSet('Edge', 'Chrome')]
    [string]$Browser = 'Edge',

    [string]$ShortcutName = 'Sistema Pollos - Pantalla completa'
)

$browserCandidates = if ($Browser -eq 'Edge') {
    @(
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
    )
} else {
    @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe"
    )
}

$browserPath = $browserCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $browserPath) {
    throw "No se encontró $Browser instalado en este equipo."
}

$desktopPath = [Environment]::GetFolderPath('DesktopDirectory')
$kioskUrl = $Url.AbsoluteUri

$shortcutPath = Join-Path $desktopPath "$ShortcutName.lnk"
$arguments = if ($Browser -eq 'Edge') {
    "--kiosk=`"$kioskUrl`" --edge-kiosk-type=fullscreen --no-first-run"
} else {
    "--kiosk `"$kioskUrl`" --no-first-run"
}

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $browserPath
$shortcut.Arguments = $arguments
$shortcut.WorkingDirectory = Split-Path -Parent $browserPath
$shortcut.IconLocation = "$browserPath,0"
$shortcut.Description = 'Abre Sistema Pollos en modo kiosco a pantalla completa.'
$shortcut.Save()

Write-Host "Acceso directo creado: $shortcutPath"
Write-Host 'Para salir del modo kiosco use Alt+F4.'
