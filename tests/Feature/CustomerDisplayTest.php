<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class CustomerDisplayTest extends TestCase
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

    public function test_wholesale_view_opens_the_customer_display(): void
    {
        $this->get('/operacion')
            ->assertOk()
            ->assertSee('id="openCustomerDisplayBtn"', false)
            ->assertSee(route('operacion.pantalla-cliente'), false)
            ->assertSee('target="pantalla-cliente"', false);
    }

    public function test_customer_display_only_exposes_live_weighing_summary(): void
    {
        $this->get('/operacion/pantalla-cliente')
            ->assertOk()
            ->assertSee('id="customerDisplayName"', false)
            ->assertSee('id="customerDisplayWeight"', false)
            ->assertSee('id="customerDisplayCages"', false)
            ->assertSee('id="customerDisplayBirds"', false)
            ->assertSee(asset('js/pantalla-cliente.js'), false)
            ->assertDontSee('Precio')
            ->assertDontSee('Registrar');
    }

    public function test_customer_display_uses_realtime_channel_with_storage_fallback(): void
    {
        $operationJavascript = (string) file_get_contents(public_path('js/app.js'));
        $displayJavascript = (string) file_get_contents(public_path('js/pantalla-cliente.js'));

        $this->assertStringContainsString('new BroadcastChannel(CUSTOMER_DISPLAY_CHANNEL_NAME)', $operationJavascript);
        $this->assertStringContainsString('function isInstalledDesktopApplication()', $operationJavascript);
        $this->assertStringContainsString('(display-mode: ${mode})', $operationJavascript);
        $this->assertStringContainsString('function openCustomerDisplay(event)', $operationJavascript);
        $this->assertStringContainsString('window.open(', $operationJavascript);
        $this->assertStringContainsString('"pantalla-cliente"', $operationJavascript);
        $this->assertStringContainsString('publishCustomerDisplayState(Math.max(grossWeight, 0))', $operationJavascript);
        $this->assertStringContainsString('customerName:', $operationJavascript);
        $this->assertStringContainsString('cages: javaCount', $operationJavascript);
        $this->assertStringContainsString('calculateBirdTotal(birdsPerJava, javaCount)', $operationJavascript);
        $this->assertStringContainsString('new BroadcastChannel(CHANNEL_NAME)', $displayJavascript);
        $this->assertStringContainsString('window.addEventListener("storage"', $displayJavascript);
        $this->assertStringContainsString('customer-display-request', $displayJavascript);
    }
}
