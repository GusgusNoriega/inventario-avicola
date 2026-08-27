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

    public function test_view_is_independent_and_contains_reception_lanes_and_two_ticket_drafts(): void
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
            ->assertSee('dentro de cada tabla')
            ->assertSee('Entrada a almacén')
            ->assertSee('Tickets de despacho Mayorista 1')
            ->assertSee('Dos borradores independientes')
            ->assertSee('Ticket de despacho')
            ->assertSee('Registrar ticket')
            ->assertSee('Elegir cliente')
            ->assertSee('Cliente:')
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

        $renderedView = $response->getContent();
        $laneMatrix = [
            1 => ['is-own-lane', 'is-male-lane', 'lir-icon-rooster', 'Gallo · Macho', 'Mi empresa'],
            2 => ['is-own-lane', 'is-female-lane', 'lir-icon-hen', 'Gallina · Hembra', 'Mi empresa'],
            3 => ['is-external-lane', 'is-male-lane', 'lir-icon-rooster', 'Gallo · Macho', 'Empresa externa'],
            4 => ['is-external-lane', 'is-female-lane', 'lir-icon-hen', 'Gallina · Hembra', 'Empresa externa'],
        ];

        foreach ($laneMatrix as $lane => [$ownerClass, $sexClass, $iconId, $sexText, $ownerText]) {
            preg_match('/<article class="([^"]*)" data-live-lane="'.$lane.'">([\s\S]*?)<\/article>/u', $renderedView, $laneMarkup);

            $this->assertNotEmpty($laneMarkup, "No se encontró la columna {$lane}.");
            $this->assertStringContainsString($ownerClass, $laneMarkup[1]);
            $this->assertStringContainsString($sexClass, $laneMarkup[1]);
            $this->assertStringContainsString('href="#'.$iconId.'"', $laneMarkup[2]);
            $this->assertStringContainsString('aria-hidden="true" focusable="false"', $laneMarkup[2]);
            $this->assertStringContainsString($sexText, $laneMarkup[2]);
            $this->assertStringContainsString($ownerText, $laneMarkup[2]);
        }

        $this->get('/')
            ->assertOk()
            ->assertSee(route('recepcion-pollo-vivo'), false);

        $view = (string) file_get_contents(resource_path('views/recepcion-pollo-vivo.blade.php'));
        $javascript = (string) file_get_contents(public_path('js/recepcion-pollo-vivo.js'));
        $stylesheet = (string) file_get_contents(public_path('css/recepcion-pollo-vivo.css'));

        $this->assertStringContainsString(
            'content="width=device-width, initial-scale=1, minimum-scale=1, viewport-fit=cover"',
            $view,
        );

        preg_match('/<form id="liveIntakeSettingsForm"[\s\S]*?<\/form>/', $view, $generalSettings);
        $this->assertNotEmpty($generalSettings);
        $this->assertStringNotContainsString('liveIntakeManualWeight', $generalSettings[0]);
        $this->assertStringNotContainsString('liveIntakeBaudRate', $generalSettings[0]);
        $this->assertStringContainsString('id="liveIntakeScaleSettingsModal"', $view);
        $this->assertStringContainsString('id="liveIntakeManualWeightModal"', $view);
        $this->assertStringContainsString('id="liveIntakeClientModal"', $view);
        $this->assertStringContainsString('id="liveIntakeClientSearch"', $view);
        $this->assertStringContainsString('id="liveIntakeDeliveryTruckModal"', $view);
        $this->assertStringContainsString('id="liveIntakeDeliveryDriverModal"', $view);
        $this->assertStringContainsString('id="liveIntakeWeighingEditorModal"', $view);
        $this->assertStringContainsString('id="liveIntakeZoomSurface"', $view);
        $this->assertStringContainsString('id="liveIntakeTicketEditorModal"', $view);
        $this->assertStringContainsString('id="liveIntakeTicketEditReason"', $view);
        $this->assertStringContainsString('role="region" aria-label="Clientes disponibles"', $view);
        $this->assertStringContainsString('role="region" tabindex="0" aria-label="Tabla desplazable de registros de la columna', $view);
        $this->assertMatchesRegularExpression(
            '/<header class="lir-direct-lane-head">[\s\S]*?<button class="lir-lane-select"[\s\S]*?<\/button>\s*<button[\s\S]*?data-live-choose-client=/u',
            $view,
        );
        $this->assertMatchesRegularExpression('/liveIntakeScaleWeight[\s\S]*liveIntakeOpenManualWeight/', $view);

        $this->assertStringContainsString('RetailScaleController', $javascript);
        $this->assertStringContainsString('BALANZA_RECEPCION_POLLO_VIVO', $javascript);
        $this->assertStringContainsString('ZOOM_STORAGE_KEY', $javascript);
        $this->assertStringContainsString('const ZOOM_LEVELS = [100, 110, 125, 150]', $javascript);
        $this->assertStringContainsString('document.documentElement.style.removeProperty("zoom")', $javascript);
        $this->assertStringContainsString('elements.main.style.removeProperty("zoom")', $javascript);
        $this->assertStringContainsString('elements.zoomSurface.style.removeProperty("width")', $javascript);
        $this->assertStringContainsString('elements.zoomSurface.style.zoom = String(scale)', $javascript);
        $this->assertStringNotContainsString('elements.main.style.zoom =', $javascript);
        $this->assertStringNotContainsString('elements.zoomSurface.style.width =', $javascript);
        $this->assertStringNotContainsString('document.documentElement.style.zoom =', $javascript);
        $this->assertStringContainsString('/recepcion-pollo-vivo/pesadas', $javascript);
        $this->assertStringContainsString('/recepcion-pollo-vivo/tickets', $javascript);
        $this->assertStringContainsString('printWeightControlTicket', $javascript);
        $this->assertStringContainsString('data-live-open-ticket', $javascript);
        $this->assertStringContainsString('data-live-edit-weighing', $javascript);
        $this->assertStringContainsString('data-live-edit-draft-weighing', $javascript);
        $this->assertStringContainsString('function addCaptureToDispatchDraft', $javascript);
        $this->assertStringContainsString('function submitDispatchTicket', $javascript);
        $this->assertStringContainsString('function saveTicketEditor', $javascript);
        $this->assertStringContainsString('data-live-select-lane', $javascript);
        $this->assertStringContainsString('const LANE_NUMBERS = [1, 2, 3, 4, 5, 6]', $javascript);
        $this->assertStringContainsString('layout_version: LAYOUT_VERSION', $javascript);
        $this->assertStringContainsString('const LAYOUT_VERSION = 4', $javascript);
        $this->assertStringContainsString('dispatch_client_id', $javascript);
        $this->assertStringContainsString('function openClientPicker', $javascript);
        $this->assertStringContainsString('function captureRequestPayload', $javascript);
        $this->assertStringContainsString('const RECORD_TABLE_COLUMN_COUNT = 13', $javascript);
        $this->assertStringContainsString('<table class="lir-record-table">', $javascript);
        $this->assertStringContainsString('<th scope="col">Peso bruto</th>', $javascript);
        $this->assertStringContainsString('<th scope="col">Peso neto</th>', $javascript);
        $this->assertMatchesRegularExpression(
            '/<thead>[\s\S]*?<th scope="col">Peso bruto<\/th>[\s\S]*?<th scope="col">Acciones<\/th>\s*<th scope="col">Registro<\/th>[\s\S]*?<\/thead>/u',
            $javascript,
        );
        $this->assertStringNotContainsString('data-live-owner=', $view);
        $this->assertStringContainsString('@media (max-width: 820px)', $stylesheet);
        $this->assertStringContainsString('scroll-snap-type: x mandatory', $stylesheet);
        $this->assertStringContainsString('.lir-lanes.is-warehouse-lanes', $stylesheet);
        $this->assertStringContainsString('width: calc(200% + 8px)', $stylesheet);
        $this->assertStringContainsString('body { min-width: 0; overflow-x: hidden; }', $stylesheet);
        $this->assertStringContainsString('.lir-zoom-surface', $stylesheet);
        $this->assertStringContainsString('max-width: 100vw', $stylesheet);
        $this->assertStringContainsString('overflow-x: clip', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-warehouse', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-client', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-male-lane', $stylesheet);
        $this->assertStringContainsString('.lir-lane.is-female-lane', $stylesheet);
        $this->assertStringContainsString('--lir-lane-owner-accent', $stylesheet);
        $this->assertStringContainsString('--lir-lane-sex-accent', $stylesheet);
        $this->assertStringContainsString('.lir-lane-sex-icon', $stylesheet);
        $this->assertStringContainsString('.lir-lane-owner-badge', $stylesheet);
        $this->assertStringContainsString('.lir-client-picker-trigger', $stylesheet);
        $this->assertStringContainsString('.lir-client-options', $stylesheet);
        $this->assertStringContainsString('.lir-ticket-record', $stylesheet);
        $this->assertStringContainsString('.lir-record-table', $stylesheet);
        $this->assertStringContainsString('min-width: 1410px', $stylesheet);
        $this->assertStringContainsString('overscroll-behavior-inline: contain', $stylesheet);
        $this->assertStringContainsString('touch-action: pan-x pan-y pinch-zoom', $stylesheet);
        $this->assertStringContainsString('font-size: 1rem', $stylesheet);
        $this->assertStringContainsString('.lir-ticket-weighing-editor', $stylesheet);
        $this->assertStringContainsString('max-height: calc(100dvh - 32px)', $stylesheet);
        $this->assertStringContainsString('max-height: min(calc(100dvh - 32px), 100%)', $stylesheet);
        $this->assertStringContainsString('.lir-modal-card.is-weighing-editor label span', $stylesheet);
        $this->assertStringContainsString('@media (max-width: 360px)', $stylesheet);
        $this->assertStringContainsString('.lir-register-ticket', $stylesheet);
        $this->assertStringContainsString('grid-template-rows: auto minmax(0, 1fr) auto', $stylesheet);
        $this->assertStringContainsString('.lir-selected-total { position: static;', $stylesheet);
    }
}
