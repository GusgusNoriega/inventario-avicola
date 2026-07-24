[CmdletBinding()]
param(
    [ValidateScript({
        if (-not $_.IsAbsoluteUri -or $_.Scheme -notin @('http', 'https')) {
            throw 'La URL debe ser absoluta y usar http o https.'
        }

        $true
    })]
    [uri]$Url = [uri]'https://sada-csa.com/',

    [ValidateSet('Auto', 'Edge', 'Chrome')]
    [string]$Browser = 'Auto',

    [string]$ShortcutName = 'Sistema Pollos - Pantalla completa',

    [switch]$DirectPrint = $true,

    [string]$PrinterName,

    [switch]$Launcher,

    [string]$ConfigPath,

    [switch]$ForceNormalPrint
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

function Find-SistemaPollosBrowser {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet('Edge', 'Chrome')]
        [string]$BrowserName
    )

    $candidates = if ($BrowserName -eq 'Edge') {
        @(
            "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
            "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
            "$env:LOCALAPPDATA\Microsoft\Edge\Application\msedge.exe"
        )
    } else {
        @(
            "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
            "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
            "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
        )
    }

    return $candidates |
        Where-Object { -not [string]::IsNullOrWhiteSpace($_) -and (Test-Path -LiteralPath $_ -PathType Leaf) } |
        Select-Object -First 1
}

function Initialize-SistemaPollosPrinterTypes {
    Add-Type -AssemblyName System.Drawing -ErrorAction Stop
}

function Get-SistemaPollosInstalledPrinterNames {
    Initialize-SistemaPollosPrinterTypes

    $names = foreach ($printer in [System.Drawing.Printing.PrinterSettings]::InstalledPrinters) {
        [string]$printer
    }

    return @($names)
}

function Find-SistemaPollosInstalledPrinterName {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RequestedName,

        [string[]]$InstalledNames = @()
    )

    foreach ($installedName in $InstalledNames) {
        if ([string]::Equals($installedName, $RequestedName, [System.StringComparison]::OrdinalIgnoreCase)) {
            return $installedName
        }
    }

    return $null
}

function Get-SistemaPollosDefaultPrinterName {
    Initialize-SistemaPollosPrinterTypes

    $settings = New-Object System.Drawing.Printing.PrinterSettings
    $name = [string]$settings.PrinterName

    if (
        -not $settings.IsValid -or
        -not $settings.IsDefaultPrinter -or
        [string]::IsNullOrWhiteSpace($name)
    ) {
        return $null
    }

    return $name
}

function Get-SistemaPollosPrinterPortName {
    param(
        [Parameter(Mandatory = $true)]
        [string]$PrinterName
    )

    $registryRoot = 'HKLM:\SYSTEM\CurrentControlSet\Control\Print\Printers'

    try {
        if (Test-Path -LiteralPath $registryRoot) {
            foreach ($printerKey in @(Get-ChildItem -LiteralPath $registryRoot -ErrorAction Stop)) {
                if ([string]::Equals(
                    $printerKey.PSChildName,
                    $PrinterName,
                    [System.StringComparison]::OrdinalIgnoreCase
                )) {
                    $properties = Get-ItemProperty -LiteralPath $printerKey.PSPath -ErrorAction Stop
                    $portName = [string]$properties.Port

                    if (-not [string]::IsNullOrWhiteSpace($portName)) {
                        return $portName
                    }
                }
            }
        }
    } catch {
        # Some managed Windows accounts cannot read the print registry.
    }

    try {
        foreach ($printer in @(Get-CimInstance -ClassName Win32_Printer -ErrorAction Stop)) {
            if ([string]::Equals(
                [string]$printer.Name,
                $PrinterName,
                [System.StringComparison]::OrdinalIgnoreCase
            )) {
                $portName = [string]$printer.PortName

                if (-not [string]::IsNullOrWhiteSpace($portName)) {
                    return $portName
                }
            }
        }
    } catch {
        # PrinterSettings remains the authority for the default printer.
    }

    return $null
}

function Test-SistemaPollosVirtualPrinter {
    param(
        [Parameter(Mandatory = $true)]
        [string]$PrinterName,

        [string]$PortName
    )

    if ($PrinterName -match '(?i)(PDF|XPS|OneNote|Fax)') {
        return $true
    }

    $normalizedPort = [string]$PortName
    if ($normalizedPort.Trim() -match '^(?i:PORTPROMPT:|FILE:|SHRFAX:)$') {
        return $true
    }

    return $false
}

function Get-SistemaPollosPrintState {
    try {
        $installedNames = @(Get-SistemaPollosInstalledPrinterNames)
        $defaultName = Get-SistemaPollosDefaultPrinterName

        if ([string]::IsNullOrWhiteSpace($defaultName)) {
            return [pscustomobject]@{
                CanPrintSilently = $false
                PrinterName      = $null
                PortName         = $null
                Reason           = 'Windows no tiene una impresora predeterminada valida.'
            }
        }

        $installedName = Find-SistemaPollosInstalledPrinterName `
            -RequestedName $defaultName `
            -InstalledNames $installedNames

        if ([string]::IsNullOrWhiteSpace($installedName)) {
            return [pscustomobject]@{
                CanPrintSilently = $false
                PrinterName      = $defaultName
                PortName         = $null
                Reason           = 'La impresora predeterminada ya no esta instalada.'
            }
        }

        $portName = Get-SistemaPollosPrinterPortName -PrinterName $installedName
        if (Test-SistemaPollosVirtualPrinter -PrinterName $installedName -PortName $portName) {
            return [pscustomobject]@{
                CanPrintSilently = $false
                PrinterName      = $installedName
                PortName         = $portName
                Reason           = 'La impresora predeterminada es virtual o requiere elegir un archivo.'
            }
        }

        if ([string]::IsNullOrWhiteSpace($portName)) {
            return [pscustomobject]@{
                CanPrintSilently = $false
                PrinterName      = $installedName
                PortName         = $null
                Reason           = 'No se pudo validar el puerto de la impresora predeterminada.'
            }
        }

        return [pscustomobject]@{
            CanPrintSilently = $true
            PrinterName      = $installedName
            PortName         = $portName
            Reason           = 'Impresora fisica predeterminada validada.'
        }
    } catch {
        return [pscustomobject]@{
            CanPrintSilently = $false
            PrinterName      = $null
            PortName         = $null
            Reason           = "No se pudo comprobar la impresora predeterminada: $($_.Exception.Message)"
        }
    }
}

function Set-SistemaPollosDefaultPrinter {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RequestedName
    )

    $installedNames = @(Get-SistemaPollosInstalledPrinterNames)
    $installedName = Find-SistemaPollosInstalledPrinterName `
        -RequestedName $RequestedName `
        -InstalledNames $installedNames

    if ([string]::IsNullOrWhiteSpace($installedName)) {
        throw "La impresora '$RequestedName' no esta instalada. No se cambio la impresora predeterminada."
    }

    $printerNetwork = $null

    try {
        $printerNetwork = New-Object -ComObject WScript.Network -ErrorAction Stop
        $printerNetwork.SetDefaultPrinter($installedName)
    } catch {
        throw "No se pudo establecer '$installedName' como impresora predeterminada. No se eligio otra impresora. Detalle: $($_.Exception.Message)"
    } finally {
        if ($null -ne $printerNetwork -and [System.Runtime.InteropServices.Marshal]::IsComObject($printerNetwork)) {
            [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($printerNetwork)
        }
    }

    for ($attempt = 0; $attempt -lt 6; $attempt++) {
        if ($attempt -gt 0) {
            Start-Sleep -Milliseconds 250
        }

        $confirmedName = Get-SistemaPollosDefaultPrinterName
        if ([string]::Equals(
            $confirmedName,
            $installedName,
            [System.StringComparison]::OrdinalIgnoreCase
        )) {
            return $installedName
        }
    }

    throw "Windows no confirmo '$installedName' como impresora predeterminada. No se eligio otra impresora."
}

function Show-SistemaPollosLauncherError {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Message
    )

    try {
        $shell = New-Object -ComObject WScript.Shell
        [void]$shell.Popup(
            $Message,
            0,
            'Sistema Pollos',
            16
        )
    } catch {
        Write-Error $Message
    }
}

function Invoke-SistemaPollosLauncher {
    param(
        [Parameter(Mandatory = $true)]
        [string]$LauncherConfigPath,

        [switch]$AlwaysShowPrintDialog
    )

    if (-not (Test-Path -LiteralPath $LauncherConfigPath -PathType Leaf)) {
        throw "No se encontro la configuracion local: $LauncherConfigPath"
    }

    $config = Get-Content -Raw -LiteralPath $LauncherConfigPath -Encoding UTF8 |
        ConvertFrom-Json -ErrorAction Stop

    foreach ($requiredProperty in @('Url', 'Browser', 'BrowserPath', 'DirectPrint')) {
        if ($null -eq $config.PSObject.Properties[$requiredProperty]) {
            throw "La configuracion local no contiene '$requiredProperty'."
        }
    }

    $configuredBrowser = [string]$config.Browser
    if ($configuredBrowser -notin @('Edge', 'Chrome')) {
        throw 'La configuracion local contiene un navegador no valido.'
    }

    $configuredUrl = $null
    if (
        -not [uri]::TryCreate([string]$config.Url, [System.UriKind]::Absolute, [ref]$configuredUrl) -or
        $configuredUrl.Scheme -notin @('http', 'https')
    ) {
        throw 'La configuracion local contiene una URL no valida.'
    }

    $browserPath = [string]$config.BrowserPath
    $expectedExecutable = if ($configuredBrowser -eq 'Edge') { 'msedge.exe' } else { 'chrome.exe' }

    if (
        -not (Test-Path -LiteralPath $browserPath -PathType Leaf) -or
        -not [string]::Equals(
            [System.IO.Path]::GetFileName($browserPath),
            $expectedExecutable,
            [System.StringComparison]::OrdinalIgnoreCase
        )
    ) {
        $browserPath = Find-SistemaPollosBrowser -BrowserName $configuredBrowser
    }

    if ([string]::IsNullOrWhiteSpace($browserPath)) {
        throw "No se encontro $configuredBrowser instalado en este equipo."
    }

    $silentPrinting = $false
    if ([bool]$config.DirectPrint -and -not $AlwaysShowPrintDialog) {
        $printState = Get-SistemaPollosPrintState
        $silentPrinting = [bool]$printState.CanPrintSilently
    }

    $profileMode = if ($silentPrinting) { 'Direct' } else { 'Normal' }
    $profileRoot = Join-Path (Split-Path -Parent $LauncherConfigPath) 'Profiles'
    $profilePath = Join-Path $profileRoot "$configuredBrowser-$profileMode"
    New-Item -ItemType Directory -Path $profilePath -Force | Out-Null

    $kioskUrl = $configuredUrl.AbsoluteUri
    $arguments = if ($configuredBrowser -eq 'Edge') {
        @("--kiosk=$kioskUrl", '--edge-kiosk-type=fullscreen', '--no-first-run')
    } else {
        @('--kiosk', $kioskUrl, '--no-first-run')
    }

    $arguments += "--user-data-dir=`"$profilePath`""

    if ($silentPrinting) {
        $arguments += '--use-system-default-printer'
        $arguments += '--kiosk-printing'
    }

    Start-Process -FilePath $browserPath -ArgumentList $arguments
}

function Assert-SistemaPollosShortcutName {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Name
    )

    if (
        [string]::IsNullOrWhiteSpace($Name) -or
        $Name.IndexOfAny([System.IO.Path]::GetInvalidFileNameChars()) -ge 0 -or
        $Name -in @('.', '..')
    ) {
        throw 'El nombre del acceso directo contiene caracteres no validos.'
    }

    if ($Name -in @(
        'Sistema Pollos - Seleccionar impresora',
        'Configurar impresora - Sistema Pollos'
    )) {
        throw "El nombre '$Name' esta reservado para un acceso de recuperacion."
    }
}

function New-SistemaPollosShortcut {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,

        [Parameter(Mandatory = $true)]
        [string]$TargetPath,

        [string]$Arguments = '',

        [string]$WorkingDirectory = '',

        [string]$IconLocation = '',

        [string]$Description = ''
    )

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($Path)
    $shortcut.TargetPath = $TargetPath
    $shortcut.Arguments = $Arguments

    if (-not [string]::IsNullOrWhiteSpace($WorkingDirectory)) {
        $shortcut.WorkingDirectory = $WorkingDirectory
    }

    if (-not [string]::IsNullOrWhiteSpace($IconLocation)) {
        $shortcut.IconLocation = $IconLocation
    }

    $shortcut.Description = $Description
    $shortcut.Save()
}

if ($Launcher) {
    try {
        if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
            throw 'El launcher necesita la ruta de su configuracion local.'
        }

        Invoke-SistemaPollosLauncher `
            -LauncherConfigPath $ConfigPath `
            -AlwaysShowPrintDialog:$ForceNormalPrint
        exit 0
    } catch {
        Show-SistemaPollosLauncherError -Message $_.Exception.Message
        exit 1
    }
}

Assert-SistemaPollosShortcutName -Name $ShortcutName

$resolvedBrowser = $Browser
$browserPath = $null

if ($Browser -eq 'Auto') {
    foreach ($browserCandidate in @('Chrome', 'Edge')) {
        $candidatePath = Find-SistemaPollosBrowser -BrowserName $browserCandidate
        if (-not [string]::IsNullOrWhiteSpace($candidatePath)) {
            $resolvedBrowser = $browserCandidate
            $browserPath = $candidatePath
            break
        }
    }
} else {
    $browserPath = Find-SistemaPollosBrowser -BrowserName $Browser
}

if ([string]::IsNullOrWhiteSpace($browserPath)) {
    throw 'No se encontro Google Chrome ni Microsoft Edge instalado en este equipo.'
}

$hasPrinterName = -not [string]::IsNullOrWhiteSpace($PrinterName)
$confirmedPrinterName = $null
if ($hasPrinterName) {
    $confirmedPrinterName = Set-SistemaPollosDefaultPrinter -RequestedName $PrinterName.Trim()
}

$installRoot = Join-Path $env:LOCALAPPDATA 'SistemaPollos\KioskLauncher'
$profileRoot = Join-Path $installRoot 'Profiles'
$installedScriptPath = Join-Path $installRoot 'Install-SistemaPollosKiosk.ps1'
$localConfigPath = Join-Path $installRoot 'config.json'
$temporaryConfigPath = "$localConfigPath.tmp"

New-Item -ItemType Directory -Path $installRoot -Force | Out-Null
New-Item -ItemType Directory -Path $profileRoot -Force | Out-Null

$sourceScriptPath = [System.IO.Path]::GetFullPath($PSCommandPath)
$targetScriptPath = [System.IO.Path]::GetFullPath($installedScriptPath)
if (-not [string]::Equals(
    $sourceScriptPath,
    $targetScriptPath,
    [System.StringComparison]::OrdinalIgnoreCase
)) {
    Copy-Item -LiteralPath $PSCommandPath -Destination $installedScriptPath -Force
}

$configuration = [ordered]@{
    Version     = 1
    Url         = $Url.AbsoluteUri
    Browser     = $resolvedBrowser
    BrowserPath = $browserPath
    DirectPrint = [bool]$DirectPrint
}

$configuration |
    ConvertTo-Json -Depth 3 |
    Set-Content -LiteralPath $temporaryConfigPath -Encoding UTF8
Move-Item -LiteralPath $temporaryConfigPath -Destination $localConfigPath -Force

$powershellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
if (-not (Test-Path -LiteralPath $powershellPath -PathType Leaf)) {
    $powershellPath = (Get-Command powershell.exe -ErrorAction Stop).Source
}

$desktopPath = [Environment]::GetFolderPath('DesktopDirectory')
$mainShortcutPath = Join-Path $desktopPath "$ShortcutName.lnk"
$normalShortcutPath = Join-Path $desktopPath 'Sistema Pollos - Seleccionar impresora.lnk'
$settingsShortcutPath = Join-Path $desktopPath 'Configurar impresora - Sistema Pollos.lnk'
$baseLauncherArguments = "-NoLogo -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$installedScriptPath`" -Launcher -ConfigPath `"$localConfigPath`""

New-SistemaPollosShortcut `
    -Path $mainShortcutPath `
    -TargetPath $powershellPath `
    -Arguments $baseLauncherArguments `
    -WorkingDirectory $installRoot `
    -IconLocation "$browserPath,0" `
    -Description 'Abre Sistema Pollos y usa impresion directa solo con una impresora fisica predeterminada.'

New-SistemaPollosShortcut `
    -Path $normalShortcutPath `
    -TargetPath $powershellPath `
    -Arguments "$baseLauncherArguments -ForceNormalPrint" `
    -WorkingDirectory $installRoot `
    -IconLocation "$browserPath,0" `
    -Description 'Abre Sistema Pollos mostrando siempre el selector normal de impresora.'

$explorerPath = Join-Path $env:SystemRoot 'explorer.exe'
New-SistemaPollosShortcut `
    -Path $settingsShortcutPath `
    -TargetPath $explorerPath `
    -Arguments 'ms-settings:printers' `
    -WorkingDirectory $env:SystemRoot `
    -IconLocation "$explorerPath,0" `
    -Description 'Abre la configuracion de impresoras de Windows.'

Write-Host "Launcher instalado en: $installedScriptPath"
Write-Host "Sistema configurado para: $($Url.AbsoluteUri)"
Write-Host "Navegador configurado: $resolvedBrowser"
Write-Host "Acceso principal creado: $mainShortcutPath"
Write-Host "Acceso de impresion normal creado: $normalShortcutPath"
Write-Host "Acceso para configurar impresoras creado: $settingsShortcutPath"

if ($confirmedPrinterName) {
    Write-Host "Impresora predeterminada confirmada: $confirmedPrinterName"
}

if ($DirectPrint) {
    Write-Host 'En cada apertura se habilitara la impresion directa solamente si la predeterminada es una impresora fisica valida.'
} else {
    Write-Host 'La impresion directa esta desactivada; el navegador mostrara siempre el selector de impresora.'
}

Write-Host 'Despues de cambiar la impresora predeterminada, cierre el kiosco y vuelva a abrirlo.'
Write-Host 'Para salir del modo kiosco use Alt+F4.'
