<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebViewsTest extends TestCase
{
    public function test_main_menu_is_the_application_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Menú principal')
            ->assertSee(route('operacion').'#despacho', false)
            ->assertSee('Despacho mayorista')
            ->assertDontSee('Registro de pesadas y balanzas')
            ->assertSee(route('tickets-dia'), false)
            ->assertSee('Resumen de la jornada')
            ->assertSee('Consolidado diario por cliente')
            ->assertSee(route('gestion-pesadas'), false)
            ->assertSee(route('jornada'), false)
            ->assertSee(route('directorio'), false)
            ->assertSee(route('flota'), false)
            ->assertSee(route('control-javas'), false)
            ->assertSee('Control de javas')
            ->assertSee('Mi flota y choferes');
    }

    public function test_operation_view_is_available_without_database_queries(): void
    {
        $this->get('/operacion')
            ->assertOk()
            ->assertSee('Entrada de Camiones de Pollos')
            ->assertSee('<select id="truckPlate"', false)
            ->assertDontSee('Precio general pollo')
            ->assertDontSee('generalPriceVivoKg', false)
            ->assertSee('type="module"', false)
            ->assertSee(asset('js/app.js'), false);
    }

    public function test_operation_javascript_does_not_render_or_send_prices(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $this->assertStringNotContainsString('general_prices', $javascript);
        $this->assertStringNotContainsString('Total S/', $javascript);
        $this->assertStringNotContainsString('Precios cliente', $javascript);
        $this->assertStringNotContainsString('Precios generales', $javascript);
        $this->assertStringNotContainsString('response.data?.prices', $javascript);
    }

    public function test_operation_weighing_has_an_exclusive_touch_sex_selector(): void
    {
        $view = file_get_contents(resource_path('views/operacion.blade.php'));
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($view);
        $this->assertIsString($javascript);
        $this->assertStringContainsString('data-sex="macho"', $view);
        $this->assertStringContainsString('data-sex="hembra"', $view);
        $this->assertStringContainsString('aria-pressed="true"', $view);
        $this->assertStringContainsString('function getSuggestedChickenSex', $javascript);
        $this->assertStringContainsString('birdCount === 7', $javascript);
        $this->assertStringContainsString('birdCount === 9', $javascript);
        $this->assertStringContainsString('chicken_sex: getChickenSexMeta(cage.chickenSex).apiCode', $javascript);
        $this->assertStringContainsString('class="truck-head-sex">Sexo', $javascript);
        $this->assertStringContainsString('chicken-sex-badge', $javascript);
    }

    public function test_selected_dispatch_totals_show_compact_bird_counts_by_sex(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('maleBirds: birdsBySex.macho', $javascript);
        $this->assertStringContainsString('femaleBirds: birdsBySex.hembra', $javascript);
        $this->assertStringContainsString('M: ${totals.maleBirds} | H: ${totals.femaleBirds}', $javascript);
        $this->assertStringContainsString('class="selected-truck-sex-counts"', $javascript);
    }

    public function test_all_operation_numeric_fields_use_the_touch_keypad(): void
    {
        $view = file_get_contents(resource_path('views/operacion.blade.php'));

        $this->assertIsString($view);
        preg_match_all('/<input\\b[^>]*\\btype="number"[^>]*>/i', $view, $matches);
        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $numericInput) {
            $this->assertStringContainsString('readonly', $numericInput);
            $this->assertStringContainsString('inputmode="none"', $numericInput);
            $this->assertStringContainsString('data-keypad-label=', $numericInput);
        }

        $this->assertStringContainsString('id="numericPadMessage"', $view);
        $this->assertStringNotContainsString('touch-number-open', $view);
        $this->assertStringNotContainsString('data-keypad-target=', $view);
    }

    public function test_operation_ticket_type_button_switches_between_dispatch_and_return(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $toggleStart = strpos($javascript, 'function toggleTicketOperation()');
        $toggleEnd = strpos($javascript, 'function openClientModal', $toggleStart);
        $this->assertNotFalse($toggleStart);
        $this->assertNotFalse($toggleEnd);

        $toggle = substr($javascript, $toggleStart, $toggleEnd - $toggleStart);
        $this->assertStringContainsString('TICKET_OPERATIONS.DISPATCH', $toggle);
        $this->assertStringContainsString('TICKET_OPERATIONS.RETURN', $toggle);
        $this->assertStringContainsString('originId: null', $toggle);
        $this->assertStringContainsString('truck.cages = [];', $toggle);

        $this->assertStringContainsString('is-dispatch-action', $javascript);
        $this->assertStringContainsString('is-return-action', $javascript);
    }

    public function test_dispatch_print_template_uses_the_control_weight_format(): void
    {
        $javascript = file_get_contents(public_path('js/ticket-printer.js'));

        $this->assertIsString($javascript);
        $templateStart = strpos($javascript, 'function buildWeightControlTicketHtml');
        $templateEnd = strpos($javascript, 'function printWeightControlTicket', $templateStart);
        $this->assertNotFalse($templateStart);
        $this->assertNotFalse($templateEnd);

        $template = substr($javascript, $templateStart, $templateEnd - $templateStart);

        $this->assertStringContainsString('DISTRIBUIDORA<br>DIEGO ALBERTO', $template);
        $this->assertStringContainsString('CONTROL DE PESO', $template);
        $this->assertStringContainsString('<th>C/A</th>', $template);
        $this->assertStringContainsString('<th>CJ</th>', $template);
        $this->assertStringContainsString('PESO<br>BRUTO', $template);
        $this->assertStringContainsString('PESO<br>TARA', $template);
        $this->assertStringContainsString('<p>OBSERV:</p>', $template);
        $this->assertStringContainsString('font-size: 11px', $template);
        $this->assertStringContainsString('font-weight: 700', $template);
        $this->assertStringNotContainsString('CONTROL PESO', $template);
        $this->assertStringNotContainsString('P.NETO', $template);
        $this->assertStringNotContainsString('TOTAL AVES:', $template);
        $this->assertStringNotContainsString('PLACA:', $template);
        $this->assertStringNotContainsString('ORIGEN:', $template);
    }

    public function test_printing_a_registered_ticket_clears_its_dispatch_column(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $printStart = strpos($javascript, 'function printDispatchTicket');
        $printEnd = strpos($javascript, 'function buildDispatchTicketPayload', $printStart);
        $this->assertNotFalse($printStart);
        $this->assertNotFalse($printEnd);

        $printFlow = substr($javascript, $printStart, $printEnd - $printStart);
        $this->assertStringContainsString('onSuccess: () => clearRegisteredTruckColumn(', $printFlow);
        $this->assertStringContainsString('onError:', $printFlow);
    }

    public function test_directory_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio')
            ->assertOk()
            ->assertSee('Clientes y proveedores')
            ->assertSee(asset('js/clientes.js'), false);
    }

    public function test_company_fleet_view_manages_only_own_trucks_and_drivers(): void
    {
        $this->get('/flota')
            ->assertOk()
            ->assertSee('Flota de la empresa')
            ->assertSee('Camiones propios')
            ->assertSee('Choferes de la empresa')
            ->assertSee('Solo recursos de mi empresa')
            ->assertSee('id="truckPlate"', false)
            ->assertSee('id="driverName"', false)
            ->assertSee(asset('js/flota.js'), false)
            ->assertSee(route('menu'), false);

        $javascript = file_get_contents(public_path('js/flota.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('apiRequest(`/${state.activeType}', $javascript);
        $this->assertStringContainsString('camiones', $javascript);
        $this->assertStringContainsString('choferes', $javascript);
        $this->assertStringNotContainsString('/proveedores/', $javascript);
        $this->assertStringNotContainsString('/clientes/', $javascript);
    }

    public function test_java_control_view_is_available_without_database_queries(): void
    {
        $this->get('/control-javas')
            ->assertOk()
            ->assertSee('Trazabilidad de javas por jornada')
            ->assertSee('Javas que salieron')
            ->assertSee('Javas que entraron')
            ->assertSee('Resumen de la jornada actual', false)
            ->assertSee('Javas que debe devolver')
            ->assertSee('Registrar entrada de javas')
            ->assertSee('id="javaJourneyFilter"', false)
            ->assertSee('Este filtro solo cambia el consolidado y el detalle', false)
            ->assertSee('id="javaTruckActivityRows"', false)
            ->assertSee('<th>Chofer</th>', false)
            ->assertSee('id="javaReceiptClient"', false)
            ->assertSee('id="javaReceiptTruck"', false)
            ->assertSee('id="javaReceiptDriver"', false)
            ->assertDontSee('id="javaReceiptDate"', false)
            ->assertSee('id="javaClientPagination"', false)
            ->assertSee('id="javaMovementRows"', false)
            ->assertSee(asset('js/control-javas.js'), false)
            ->assertSee(route('menu'), false);

        $javascript = file_get_contents(public_path('js/control-javas.js'));

        $this->assertIsString($javascript);
        $this->assertStringNotContainsString('received_at', $javascript);
        $this->assertStringContainsString('data.clients_pagination', $javascript);
        $this->assertStringContainsString('new URLSearchParams', $javascript);
        $this->assertStringContainsString('journey_id', $javascript);
    }

    public function test_daily_tickets_view_is_available_without_database_queries(): void
    {
        $this->get('/tickets-dia')
            ->assertOk()
            ->assertSee('Resumen de la jornada')
            ->assertSee('dailyClientTotals', false)
            ->assertSee(route('menu'), false)
            ->assertSee('Menú')
            ->assertSee(asset('js/tickets-dia.js'), false)
            ->assertDontSee('dailyOperationSummary', false)
            ->assertDontSee('dailyTicketsFilters', false)
            ->assertDontSee('dailyTypeTotals', false)
            ->assertDontSee('dailyTicketList', false)
            ->assertDontSee('Importe')
            ->assertDontSee('Precio/kg');
    }

    public function test_journey_configuration_view_is_available_without_database_queries(): void
    {
        $this->get('/jornada')
            ->assertOk()
            ->assertSee('Orígenes de la jornada')
            ->assertSee('Precios globales')
            ->assertDontSee('precios de proveedor', false)
            ->assertSee(asset('js/jornada.js'), false);

        $javascript = file_get_contents(public_path('js/jornada.js'));

        $this->assertIsString($javascript);
        $this->assertStringNotContainsString('truck.prices', $javascript);
        $this->assertStringNotContainsString('Precio proveedor/kg', $javascript);
    }

    public function test_weighing_management_view_is_available_without_database_queries(): void
    {
        $this->get('/gestion-pesadas')
            ->assertOk()
            ->assertSee('Gestión de pesadas')
            ->assertSee('ticketSearchInput', false)
            ->assertSee('selectedTicketPanel', false)
            ->assertSee('editTicketDeliveryModal', false)
            ->assertSee('editTicketVehicle', false)
            ->assertSee('editTicketDriver', false)
            ->assertSee(asset('js/gestion-pesadas.js'), false);

        $dispatchJavascript = file_get_contents(public_path('js/app.js'));
        $managementJavascript = file_get_contents(public_path('js/gestion-pesadas.js'));

        $this->assertIsString($dispatchJavascript);
        $this->assertIsString($managementJavascript);
        $this->assertStringContainsString('from "./ticket-printer.js"', $dispatchJavascript);
        $this->assertStringContainsString('from "./ticket-printer.js"', $managementJavascript);
        $this->assertStringContainsString('data-print-selected-ticket', $managementJavascript);
        $this->assertStringContainsString('data-edit-ticket-delivery', $managementJavascript);
        $this->assertStringContainsString('/transporte', $managementJavascript);
        $this->assertStringContainsString('printWeightControlTicket(buildSelectedTicketPrintData(ticket)', $managementJavascript);
    }

    public function test_customer_history_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio/clientes/15')
            ->assertOk()
            ->assertSee('Histórico de precios')
            ->assertSee('data-client-id="15"', false)
            ->assertSee(asset('js/cliente-detalle.js'), false);
    }

    public function test_provider_history_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio/proveedores/20')
            ->assertOk()
            ->assertSee('Camiones asignados')
            ->assertSee('Pesadas del proveedor')
            ->assertSee('data-provider-id="20"', false)
            ->assertSee(asset('js/proveedor-detalle.js'), false);
    }

    public function test_legacy_html_urls_redirect_to_laravel_routes(): void
    {
        $this->get('/menu.html')->assertRedirect('/');
        $this->get('/index.html')->assertRedirect('/operacion');
        $this->get('/clientes.html')->assertRedirect('/directorio');
    }
}
