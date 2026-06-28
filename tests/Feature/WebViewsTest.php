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
            ->assertSee(route('directorio'), false);
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

    public function test_directory_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio')
            ->assertOk()
            ->assertSee('Clientes y proveedores')
            ->assertSee(asset('js/clientes.js'), false);
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
            ->assertSee('Proveedores de la jornada')
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
            ->assertSee(asset('js/gestion-pesadas.js'), false);
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
