<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class LiveChickenReceptionViewTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_view_is_independent_and_contains_the_six_lane_touch_workflow(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_RECEPCION_POLLO_VIVO']);

        $response = $this->actingAs($user)
            ->get('/recepcion-pollo-vivo');

        $response
            ->assertOk()
            ->assertSee('Recepción de pollo vivo')
            ->assertSee('Camión del día')
            ->assertSee('Balanza de recepción')
            ->assertSee('Mi empresa')
            ->assertSee('Empresa externa')
            ->assertDontSee('data-live-owner=', false)
            ->assertSee('Asignación automática')
            ->assertSee('Cuatro columnas por propietario y sexo')
            ->assertSee('Entrada a almacén')
            ->assertSee('Recepción y despacho automático')
            ->assertSee('Recepción + despacho simultáneo')
            ->assertSee('Elegir cliente')
            ->assertSee('Próximo despacho')
            ->assertSee('Columna 6')
            ->assertSee('Columna seleccionada')
            ->assertSee('Zoom de la vista')
            ->assertSee('Configurar balanza')
            ->assertSee('Colocar peso manual')
            ->assertSee('Configuración general')
            ->assertSee(asset('css/recepcion-pollo-vivo.css'), false)
            ->assertSee(asset('js/recepcion-pollo-vivo.js'), false)
            ->assertDontSee(asset('css/style.css'), false)
            ->assertDontSee(asset('js/app.js'), false);

        $this->assertSame(2, substr_count($response->getContent(), 'data-live-choose-client='));

        $this->get('/')
            ->assertOk()
            ->assertSee(route('recepcion-pollo-vivo'), false);

        $view = (string) file_get_contents(resource_path('views/recepcion-pollo-vivo.blade.php'));
        $javascript = (string) file_get_contents(public_path('js/recepcion-pollo-vivo.js'));
        $stylesheet = (string) file_get_contents(public_path('css/recepcion-pollo-vivo.css'));

        preg_match('/<form id="liveIntakeSettingsForm"[\s\S]*?<\/form>/', $view, $generalSettings);
        $this->assertNotEmpty($generalSettings);
        $this->assertStringNotContainsString('liveIntakeManualWeight', $generalSettings[0]);
        $this->assertStringNotContainsString('liveIntakeBaudRate', $generalSettings[0]);
        $this->assertStringContainsString('id="liveIntakeScaleSettingsModal"', $view);
        $this->assertStringContainsString('id="liveIntakeManualWeightModal"', $view);
        $this->assertStringContainsString('id="liveIntakeClientModal"', $view);
        $this->assertStringContainsString('id="liveIntakeClientSearch"', $view);
        $this->assertStringContainsString('role="region" aria-label="Clientes disponibles"', $view);
        $this->assertMatchesRegularExpression(
            '/<header class="lir-direct-lane-head">[\s\S]*?<button class="lir-lane-select"[\s\S]*?<\/button>\s*<button[\s\S]*?data-live-choose-client=/u',
            $view,
        );
        $this->assertMatchesRegularExpression('/liveIntakeScaleWeight[\s\S]*liveIntakeOpenManualWeight/', $view);

        $this->assertStringContainsString('RetailScaleController', $javascript);
        $this->assertStringContainsString('BALANZA_RECEPCION_POLLO_VIVO', $javascript);
        $this->assertStringContainsString('ZOOM_STORAGE_KEY', $javascript);
        $this->assertStringContainsString('document.documentElement.style.zoom', $javascript);
        $this->assertStringContainsString('/recepcion-pollo-vivo/pesadas', $javascript);
        $this->assertStringContainsString('data-live-select-lane', $javascript);
        $this->assertStringContainsString('const LANE_NUMBERS = [1, 2, 3, 4, 5, 6]', $javascript);
        $this->assertStringContainsString('layout_version: LAYOUT_VERSION', $javascript);
        $this->assertStringContainsString('const LAYOUT_VERSION = 3', $javascript);
        $this->assertStringContainsString('dispatch_client_id', $javascript);
        $this->assertStringContainsString('function openClientPicker', $javascript);
        $this->assertStringContainsString('function captureRequestPayload', $javascript);
        $this->assertStringNotContainsString('data-live-owner=', $view);
        $this->assertStringContainsString('@media (max-width: 820px)', $stylesheet);
        $this->assertStringContainsString('scroll-snap-type: x mandatory', $stylesheet);
        $this->assertStringContainsString('.lir-lanes.is-warehouse-lanes', $stylesheet);
        $this->assertStringContainsString('width: calc(200% + 8px)', $stylesheet);
        $this->assertStringContainsString('body { min-width: 0; overflow-x: hidden; }', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-warehouse', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-client', $stylesheet);
        $this->assertStringContainsString('.lir-client-picker-trigger', $stylesheet);
        $this->assertStringContainsString('.lir-client-options', $stylesheet);
        $this->assertStringContainsString('grid-template-rows: auto minmax(0, 1fr) auto', $stylesheet);
        $this->assertStringContainsString('.lir-selected-total { position: static;', $stylesheet);
    }
}
