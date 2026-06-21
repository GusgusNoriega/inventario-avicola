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
            ->assertSee(route('operacion'), false)
            ->assertSee(route('directorio'), false);
    }

    public function test_operation_view_is_available_without_database_queries(): void
    {
        $this->get('/operacion')
            ->assertOk()
            ->assertSee('Entrada de Camiones de Pollos')
            ->assertSee(asset('js/app.js'), false);
    }

    public function test_directory_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio')
            ->assertOk()
            ->assertSee('Clientes y proveedores')
            ->assertSee(asset('js/clientes.js'), false);
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
