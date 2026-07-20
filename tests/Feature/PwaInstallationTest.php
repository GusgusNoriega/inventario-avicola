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
}
