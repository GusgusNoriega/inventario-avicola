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

    public function test_view_is_independent_and_contains_the_portrait_four_lane_workflow(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_RECEPCION_POLLO_VIVO']);

        $this->actingAs($user)
            ->get('/recepcion-pollo-vivo')
            ->assertOk()
            ->assertSee('Recepción de pollo vivo')
            ->assertSee('Camión del día')
            ->assertSee('Balanza de recepción')
            ->assertSee('Mi empresa')
            ->assertSee('Empresa externa')
            ->assertSee('data-live-owner="PROPIA"', false)
            ->assertSee('data-live-owner="EXTERNA"', false)
            ->assertSee('Entrada a almacén')
            ->assertSee('Despacho directo')
            ->assertSee('Columna seleccionada')
            ->assertSee('Zoom de la vista')
            ->assertSee('Configurar balanza')
            ->assertSee('Colocar peso manual')
            ->assertSee('Configuración general')
            ->assertSee(asset('css/recepcion-pollo-vivo.css'), false)
            ->assertSee(asset('js/recepcion-pollo-vivo.js'), false)
            ->assertDontSee(asset('css/style.css'), false)
            ->assertDontSee(asset('js/app.js'), false);

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
        $this->assertMatchesRegularExpression('/liveIntakeScaleWeight[\s\S]*liveIntakeOpenManualWeight/', $view);

        $this->assertStringContainsString('RetailScaleController', $javascript);
        $this->assertStringContainsString('BALANZA_RECEPCION_POLLO_VIVO', $javascript);
        $this->assertStringContainsString('ZOOM_STORAGE_KEY', $javascript);
        $this->assertStringContainsString('document.documentElement.style.zoom', $javascript);
        $this->assertStringContainsString('/recepcion-pollo-vivo/pesadas', $javascript);
        $this->assertStringContainsString('data-live-select-lane', $javascript);
        $this->assertStringContainsString('@media (max-width: 820px) and (orientation: portrait)', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: 1fr 1fr', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-warehouse', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-client', $stylesheet);
        $this->assertStringContainsString('grid-template-rows: auto minmax(0, 1fr) auto', $stylesheet);
        $this->assertStringContainsString('.lir-selected-total { position: static;', $stylesheet);
    }
}
