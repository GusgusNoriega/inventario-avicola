<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ProductDispatchCustomerDisplayTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_guest_cannot_open_the_product_dispatch_customer_display(): void
    {
        $this->get('/despacho-productos/pantalla-cliente')
            ->assertRedirect('/login');
    }

    public function test_display_route_declares_the_product_module_and_dispatch_permission(): void
    {
        $route = app('router')->getRoutes()->getByName('despacho-productos.pantalla-cliente');

        $this->assertNotNull($route);
        $this->assertContains(
            'module:MODULO_DESPACHO_PRODUCTOS',
            $route->gatherMiddleware(),
        );
        $this->assertContains(
            'permission:PRODUCTOS_DESPACHO_DESPACHAR',
            $route->gatherMiddleware(),
        );

        $moduleOnlyUser = User::factory()->create();
        $this->grantModules(
            $moduleOnlyUser,
            ['MODULO_DESPACHO_PRODUCTOS'],
            'PRODUCTOS_MODULO_SOLAMENTE',
        );

        $this->actingAs($moduleOnlyUser);
        // El módulo deriva sus permisos técnicos, incluido DESPACHAR.
        $this->get('/despacho-productos/pantalla-cliente')->assertOk();

        $permissionWithoutModuleUser = User::factory()->create();
        $this->grantModules(
            $permissionWithoutModuleUser,
            ['PRODUCTOS_DESPACHO_DESPACHAR'],
            'PRODUCTOS_PERMISO_SIN_MODULO',
        );

        $this->actingAs($permissionWithoutModuleUser);
        $this->get('/despacho-productos/pantalla-cliente')->assertForbidden();

    }

    public function test_another_dispatch_module_does_not_grant_access_to_the_product_display(): void
    {
        $retailUser = User::factory()->create();
        $this->grantModules(
            $retailUser,
            ['MODULO_DESPACHO_MINORISTA_1'],
            'MINORISTA_SIN_PRODUCTOS',
        );

        $this->actingAs($retailUser);
        $this->get('/despacho-minorista/pantalla-cliente')->assertOk();
        $this->get('/despacho-productos/pantalla-cliente')->assertForbidden();
    }

    public function test_product_display_exposes_only_the_live_customer_information(): void
    {
        $user = User::factory()->create();
        $this->grantModules(
            $user,
            ['MODULO_DESPACHO_PRODUCTOS', 'PRODUCTOS_DESPACHO_DESPACHAR'],
            'PRODUCTOS_PANTALLA_CONTENIDO',
        );

        $this->actingAs($user);

        $this->get('/despacho-productos/pantalla-cliente')
            ->assertOk()
            ->assertSee("data-authenticated-user-id=\"{$user->id}\"", false)
            ->assertSee('id="productCustomerDisplayTitle"', false)
            ->assertSee('id="productCustomerDisplayLiveNet"', false)
            ->assertSee('id="productCustomerDisplayLiveAmount"', false)
            ->assertSee('id="productCustomerDisplayListHeading"', false)
            ->assertSee('id="productCustomerDisplayCustomer"', false)
            ->assertSee('id="productCustomerDisplayRows"', false)
            ->assertSee('id="productCustomerDisplayListNet"', false)
            ->assertSee('id="productCustomerDisplayListAmount"', false)
            ->assertSee('id="productCustomerDisplayAnnouncement"', false)
            ->assertSee('Peso neto')
            ->assertSee('Importe')
            ->assertSee('Lista de venta')
            ->assertSee('Producto')
            ->assertSee('Cant.')
            ->assertSee('P. neto')
            ->assertSee('Total')
            ->assertSee('<table>', false)
            ->assertSee('<th scope="col">Producto</th>', false)
            ->assertSee('id="productCustomerDisplayChooseScreen"', false)
            ->assertSee('id="productCustomerDisplayFullscreen"', false)
            ->assertSee('id="productCustomerDisplayScreenDialog"', false)
            ->assertSee('id="productCustomerDisplayScreenList"', false)
            ->assertSee('id="productCustomerDisplayOpenTypography"', false)
            ->assertSee('aria-controls="productCustomerDisplayTypographyPanel"', false)
            ->assertSee('aria-label="Configurar tipografía"', false)
            ->assertSee('id="productCustomerDisplayTypographyPanel"', false)
            ->assertSee('id="productCustomerDisplayTypographySearch"', false)
            ->assertSee('id="productCustomerDisplayTypographyControls"', false)
            ->assertSee('data-pdcd-font-preset="standard"', false)
            ->assertSee('data-pdcd-font-reset-all', false)
            ->assertSee('Tipografía')
            ->assertSee(asset('css/despacho-productos-pantalla-cliente.css'), false)
            ->assertSee(asset('js/despacho-productos-pantalla-cliente.js'), false)
            ->assertDontSee(asset('js/pantalla-cliente-minorista.js'), false)
            ->assertDontSee(asset('js/pantalla-cliente.js'), false)
            ->assertDontSee('<form', false)
            ->assertDontSee('id="pddCaptureWeight"', false)
            ->assertDontSee('id="pddSaveDraft"', false)
            ->assertDontSee('id="retailWeighingForm"', false)
            ->assertDontSee('Registrar pesada');
    }
}
