<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class MenuFullscreenTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->makeAdministrator($user);
        $this->actingAs($user);
    }

    public function test_main_menu_exposes_an_accessible_fullscreen_toggle(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertSee('id="menuFullscreenButton"', false)
            ->assertSee('id="menuFullscreenLabel"', false)
            ->assertSee('id="menuFullscreenStatus"', false)
            ->assertSee('Pantalla completa')
            ->assertSee(asset('js/menu.js'), false);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $button = $xpath->query('//*[@id="menuFullscreenButton"]')->item(0);

        $this->assertNotNull($button);
        $this->assertSame('button', $button->nodeName);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertSame('false', $button->getAttribute('aria-pressed'));
        $this->assertSame('Activar pantalla completa', $button->getAttribute('aria-label'));
        $this->assertCount(
            4,
            $xpath->query('//*[@id="menuFullscreenButton"]//*[contains(concat(" ", normalize-space(@class), " "), " menu-fullscreen-icon ")]/*'),
        );
    }

    public function test_menu_javascript_enters_exits_and_tracks_fullscreen_state(): void
    {
        $javascript = file_get_contents(public_path('js/menu.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('function getFullscreenElement()', $javascript);
        $this->assertStringContainsString('function canUseFullscreen()', $javascript);
        $this->assertStringContainsString('async function toggleFullscreen()', $javascript);
        $this->assertStringContainsString('root.requestFullscreen()', $javascript);
        $this->assertStringContainsString('document.exitFullscreen()', $javascript);
        $this->assertStringContainsString('root.webkitRequestFullscreen()', $javascript);
        $this->assertStringContainsString('document.webkitExitFullscreen()', $javascript);
        $this->assertStringContainsString('menuFullscreenButton.setAttribute("aria-pressed", String(isFullscreen))', $javascript);
        $this->assertStringContainsString('"Restaurar pantalla"', $javascript);
        $this->assertStringContainsString('menuFullscreenButton.disabled = true', $javascript);
        $this->assertStringContainsString('menuFullscreenButton.disabled = false', $javascript);
        $this->assertStringContainsString('document.addEventListener("fullscreenchange", handleFullscreenChange)', $javascript);
        $this->assertStringContainsString('document.addEventListener("webkitfullscreenchange", handleFullscreenChange)', $javascript);
        $this->assertStringContainsString('catch (error)', $javascript);
    }

    public function test_fullscreen_button_is_touch_sized_and_responsive(): void
    {
        $stylesheet = file_get_contents(public_path('css/style.css'));

        $this->assertIsString($stylesheet);
        $this->assertMatchesRegularExpression(
            '/\.menu-fullscreen-button\s*\{[^}]*position:\s*absolute;[^}]*top:\s*18px;[^}]*right:\s*18px;[^}]*min-width:\s*194px;[^}]*min-height:\s*54px;[^}]*touch-action:\s*manipulation;/s',
            $stylesheet,
        );
        $this->assertStringContainsString('.menu-fullscreen-button:focus-visible', $stylesheet);
        $this->assertStringContainsString('.menu-fullscreen-button.is-active', $stylesheet);
        $this->assertStringContainsString('.menu-fullscreen-button:disabled', $stylesheet);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width:\s*760px\).*?\.menu-fullscreen-button\s*\{[^}]*min-width:\s*52px;[^}]*min-height:\s*52px;/s',
            $stylesheet,
        );
    }
}
