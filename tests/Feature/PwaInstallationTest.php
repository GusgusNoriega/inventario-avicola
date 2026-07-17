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
            ->assertSee('js/pwa-register.js', false)
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
}
