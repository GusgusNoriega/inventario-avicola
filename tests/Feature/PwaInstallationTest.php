<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaInstallationTest extends TestCase
{
    public function test_login_exposes_the_installable_app_metadata(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('manifest.webmanifest', false)
            ->assertSee(asset('js/pwa-register.js').'?v='.md5_file(public_path('js/pwa-register.js')), false)
            ->assertSee('icons/icon-192.png', false);
    }

    public function test_pwa_files_are_publicly_available(): void
    {
        $this->assertFileExists(public_path('manifest.webmanifest'));
        $this->assertFileExists(public_path('service-worker.js'));
        $this->assertFileExists(public_path('icons/icon-192.png'));
        $this->assertFileExists(public_path('icons/icon-512.png'));
        $this->assertFileExists(public_path('icons/icon-maskable-512.png'));

        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertCount(3, $manifest['icons']);
    }

    public function test_pwa_updates_all_static_assets_without_a_hard_refresh(): void
    {
        $serviceWorker = (string) file_get_contents(public_path('service-worker.js'));
        $registration = (string) file_get_contents(public_path('js/pwa-register.js'));

        $this->assertStringContainsString('sistema-pollos-static-v3', $serviceWorker);
        $this->assertStringContainsString('["style", "script", "image", "font"]', $serviceWorker);
        $this->assertStringContainsString('fetch(request, { cache: "no-cache" })', $serviceWorker);
        $this->assertStringContainsString('.catch(() => caches.match(request))', $serviceWorker);
        $this->assertStringNotContainsString('caches.match(request).then((cached)', $serviceWorker);
        $this->assertStringContainsString('controllerchange', $registration);
        $this->assertStringContainsString('updateViaCache: "none"', $registration);
        $this->assertStringContainsString('registration.update()', $registration);
    }

    public function test_kiosk_installer_uses_the_production_domain_and_an_adaptive_local_launcher(): void
    {
        $installer = (string) file_get_contents(base_path('scripts/Install-SistemaPollosKiosk.ps1'));
        $documentation = (string) file_get_contents(base_path('docs/pwa-y-modo-kiosco.md'));

        $this->assertStringContainsString('[uri]$Url = [uri]\'https://sada-csa.com/\'', $installer);
        $this->assertStringContainsString('[string]$Browser = \'Auto\'', $installer);
        $this->assertStringContainsString("foreach (\$browserCandidate in @('Chrome', 'Edge'))", $installer);
        $this->assertStringContainsString('Browser     = $resolvedBrowser', $installer);
        $this->assertStringContainsString('[switch]$DirectPrint = $true', $installer);
        $this->assertStringContainsString('[string]$PrinterName', $installer);
        $this->assertStringContainsString('[switch]$Launcher', $installer);
        $this->assertStringContainsString('[switch]$ForceNormalPrint', $installer);
        $this->assertStringContainsString('ConvertFrom-Json -ErrorAction Stop', $installer);
        $this->assertStringContainsString('Copy-Item -LiteralPath $PSCommandPath', $installer);
        $this->assertStringContainsString('Join-Path $env:LOCALAPPDATA \'SistemaPollos\\KioskLauncher\'', $installer);
        $this->assertStringContainsString('Start-Process -FilePath $browserPath -ArgumentList $arguments', $installer);
        $this->assertStringContainsString('https://sada-csa.com/', $documentation);
        $this->assertStringContainsString('%LOCALAPPDATA%\\SistemaPollos\\KioskLauncher', $documentation);
        $this->assertStringContainsString('puede eliminarse después de la instalación', $documentation);
    }

    public function test_kiosk_launcher_only_uses_silent_printing_with_a_valid_physical_default(): void
    {
        $installer = (string) file_get_contents(base_path('scripts/Install-SistemaPollosKiosk.ps1'));
        $documentation = (string) file_get_contents(base_path('docs/pwa-y-modo-kiosco.md'));

        $this->assertStringContainsString('System.Drawing.Printing.PrinterSettings', $installer);
        $this->assertStringContainsString('InstalledPrinters', $installer);
        $this->assertStringContainsString('$settings.IsDefaultPrinter', $installer);
        $this->assertStringContainsString('(?i)(PDF|XPS|OneNote|Fax)', $installer);
        $this->assertStringContainsString('PORTPROMPT:', $installer);
        $this->assertStringContainsString('FILE:', $installer);
        $this->assertStringContainsString('$silentPrinting = [bool]$printState.CanPrintSilently', $installer);
        $this->assertStringContainsString('if ($silentPrinting)', $installer);
        $this->assertStringContainsString('--user-data-dir=', $installer);
        $this->assertStringContainsString('--use-system-default-printer', $installer);
        $this->assertStringContainsString('--kiosk-printing', $installer);
        $this->assertStringContainsString('$profileMode = if ($silentPrinting) { \'Direct\' } else { \'Normal\' }', $installer);
        $this->assertStringContainsString('Windows no tiene una impresora predeterminada valida.', $installer);
        $this->assertStringContainsString('No se pudo validar el puerto de la impresora predeterminada.', $installer);

        $this->assertStringContainsString('muestra su ventana normal para elegir una impresora', $documentation);
        $this->assertStringContainsString('nunca habilitan la impresión silenciosa', $documentation);
        $this->assertStringContainsString('perfiles separados llamados `Direct` y `Normal`', $documentation);
        $this->assertStringContainsString('pestaña común o desde la PWA instalada', $documentation);
        $this->assertStringContainsString('una sola impresora predeterminada por usuario', $documentation);
    }

    public function test_kiosk_installer_validates_printer_selection_and_creates_recovery_shortcuts(): void
    {
        $installer = (string) file_get_contents(base_path('scripts/Install-SistemaPollosKiosk.ps1'));
        $documentation = (string) file_get_contents(base_path('docs/pwa-y-modo-kiosco.md'));

        $this->assertStringContainsString('$printerNetwork.SetDefaultPrinter($installedName)', $installer);
        $this->assertStringContainsString('Get-SistemaPollosDefaultPrinterName', $installer);
        $this->assertStringContainsString('Windows no confirmo \'$installedName\' como impresora predeterminada.', $installer);
        $this->assertStringContainsString('No se eligio otra impresora.', $installer);
        $this->assertStringContainsString('Sistema Pollos - Seleccionar impresora.lnk', $installer);
        $this->assertStringContainsString('Configurar impresora - Sistema Pollos.lnk', $installer);
        $this->assertStringContainsString('-ForceNormalPrint', $installer);
        $this->assertStringContainsString("'ms-settings:printers'", $installer);

        $this->assertStringContainsString('Sistema Pollos - Seleccionar impresora', $documentation);
        $this->assertStringContainsString('Configurar impresora - Sistema Pollos', $documentation);
        $this->assertStringContainsString('comprueba que el nombre corresponda exactamente', $documentation);
        $this->assertStringContainsString('cierre por completo el kiosco', $documentation);
        $this->assertStringContainsString('-DirectPrint:$false', $documentation);
    }
}
