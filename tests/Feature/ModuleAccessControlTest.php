<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ModuleAccessControlTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_protected_web_views(): void
    {
        foreach ([
            '/',
            '/operacion',
            '/recepcion-pollo-vivo/menu',
            '/recepcion-pollo-vivo',
            '/recepcion-pollo-vivo/historial',
            '/despacho-mayorista-2',
            '/despacho-productos',
            '/despacho-productos/productos',
            '/despacho-productos/despacho',
            '/despacho-productos/clientes',
            '/despacho-productos/configuracion-ticket',
            '/despacho-productos/tickets',
            '/despacho-productos/estado-cuenta',
            '/despacho-productos/estado-cuenta/pdf',
            '/precios-jornada',
            '/reporte-proveedores',
            '/finanzas',
            '/finanzas/saldos',
            '/finanzas/caja-efectivo',
            '/finanzas/tickets',
            '/compras/nueva',
            '/control-javas/inventario',
            '/administracion/accesos',
            '/mi-cuenta',
        ] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_authenticated_user_cannot_open_an_unassigned_module_by_direct_url(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_DIRECTORIO']);

        $this->actingAs($user);

        foreach ([
            '/operacion',
            '/recepcion-pollo-vivo/menu',
            '/recepcion-pollo-vivo',
            '/recepcion-pollo-vivo/historial',
            '/despacho-mayorista-2',
            '/despacho-productos',
            '/despacho-productos/productos',
            '/despacho-productos/despacho',
            '/despacho-productos/clientes',
            '/despacho-productos/configuracion-ticket',
            '/despacho-productos/tickets',
            '/despacho-productos/estado-cuenta',
            '/despacho-productos/estado-cuenta/pdf',
            '/precios-jornada',
            '/reporte-proveedores',
            '/finanzas',
            '/finanzas/saldos',
            '/finanzas/caja-efectivo',
            '/finanzas/tickets',
            '/compras',
            '/control-javas',
            '/control-javas/inventario',
            '/administracion/accesos',
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_finance_module_unlocks_all_of_its_internal_web_views(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_FINANZAS']);

        $this->actingAs($user);

        foreach ([
            '/finanzas',
            '/finanzas/saldos',
            '/finanzas/entidades',
            '/finanzas/caja-efectivo',
            '/finanzas/movimientos/nuevo',
            '/finanzas/tickets',
            '/compras',
            '/compras/nueva',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_live_chicken_reception_module_unlocks_all_of_its_internal_web_views(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_RECEPCION_POLLO_VIVO']);

        $this->actingAs($user);

        foreach ([
            '/recepcion-pollo-vivo/menu',
            '/recepcion-pollo-vivo',
            '/recepcion-pollo-vivo/historial',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_java_control_module_unlocks_all_of_its_internal_web_views(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_CONTROL_JAVAS']);

        $this->actingAs($user);

        foreach ([
            '/control-javas',
            '/control-javas/inventario',
            '/control-javas/devoluciones',
            '/control-javas/trazabilidad',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_product_dispatch_module_unlocks_its_menu_catalog_dispatch_and_ticket_views(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_DESPACHO_PRODUCTOS']);

        $this->actingAs($user);

        $this->get('/despacho-productos')->assertOk();
        $this->get('/despacho-productos/productos')->assertOk();
        $this->get('/despacho-productos/despacho')->assertOk();
        $this->get('/despacho-productos/clientes')->assertOk();
        $this->get('/despacho-productos/configuracion-ticket')->assertOk();
        $this->get('/despacho-productos/tickets')->assertOk();
        $this->get('/despacho-productos/estado-cuenta')->assertOk();
        $this->get('/')
            ->assertOk()
            ->assertSee(route('despacho-productos.menu'), false);
    }

    public function test_product_dispatch_ticket_view_requires_its_module_and_management_permission(): void
    {
        $route = app('router')->getRoutes()->getByName('despacho-productos.tickets');

        $this->assertNotNull($route);
        $this->assertContains(
            'module:MODULO_DESPACHO_PRODUCTOS',
            $route->gatherMiddleware(),
        );
        $this->assertContains(
            'permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR',
            $route->gatherMiddleware(),
        );

        $apiRoutes = collect(app('router')->getRoutes()->getRoutes());
        foreach ([
            ['method' => 'GET', 'uri' => 'api/v1/despacho-productos/tickets'],
            ['method' => 'GET', 'uri' => 'api/v1/despacho-productos/tickets/{ticket}'],
            ['method' => 'PUT', 'uri' => 'api/v1/despacho-productos/tickets/{ticket}'],
            ['method' => 'DELETE', 'uri' => 'api/v1/despacho-productos/tickets/{ticket}'],
        ] as $expected) {
            $apiRoute = $apiRoutes->first(fn ($candidate): bool => $candidate->uri() === $expected['uri']
                && in_array($expected['method'], $candidate->methods(), true));

            $this->assertNotNull(
                $apiRoute,
                "No se registró {$expected['method']} {$expected['uri']}.",
            );
            $this->assertContains(
                'permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR',
                $apiRoute->gatherMiddleware(),
            );
        }

        $permissionOnlyUser = User::factory()->create();
        $this->grantModules(
            $permissionOnlyUser,
            ['PRODUCTOS_DESPACHO_TICKETS_GESTIONAR'],
            'TICKETS_PRODUCTOS_SIN_MODULO',
        );

        $this->actingAs($permissionOnlyUser)
            ->get('/despacho-productos/tickets')
            ->assertForbidden();

        $moduleUser = User::factory()->create();
        $this->grantModules(
            $moduleUser,
            ['MODULO_DESPACHO_PRODUCTOS'],
            'MODULO_PRODUCTOS_CON_TICKETS',
        );

        $this->actingAs($moduleUser)
            ->get('/despacho-productos/tickets')
            ->assertOk();
    }

    public function test_product_dispatch_quick_client_routes_require_the_module_and_dispatch_permission(): void
    {
        $webRoute = app('router')->getRoutes()->getByName('despacho-productos.clientes');

        $this->assertNotNull($webRoute);
        $this->assertContains(
            'module:MODULO_DESPACHO_PRODUCTOS',
            $webRoute->gatherMiddleware(),
        );
        $this->assertContains(
            'permission:PRODUCTOS_DESPACHO_DESPACHAR',
            $webRoute->gatherMiddleware(),
        );

        $apiRoutes = collect(app('router')->getRoutes()->getRoutes());
        foreach ([
            ['method' => 'GET', 'uri' => 'api/v1/despacho-productos/clientes'],
            ['method' => 'POST', 'uri' => 'api/v1/despacho-productos/clientes'],
            ['method' => 'PUT', 'uri' => 'api/v1/despacho-productos/clientes/{cliente}'],
            ['method' => 'DELETE', 'uri' => 'api/v1/despacho-productos/clientes/{cliente}'],
        ] as $expected) {
            $apiRoute = $apiRoutes->first(fn ($candidate): bool => $candidate->uri() === $expected['uri']
                && in_array($expected['method'], $candidate->methods(), true));

            $this->assertNotNull(
                $apiRoute,
                "No se registró {$expected['method']} {$expected['uri']}.",
            );
            $this->assertContains(
                'module:MODULO_DESPACHO_PRODUCTOS',
                $apiRoute->gatherMiddleware(),
            );
            $this->assertContains(
                'permission:PRODUCTOS_DESPACHO_DESPACHAR',
                $apiRoute->gatherMiddleware(),
            );
        }

        $permissionOnlyUser = User::factory()->create();
        $this->grantModules(
            $permissionOnlyUser,
            ['PRODUCTOS_DESPACHO_DESPACHAR'],
            'CLIENTES_PRODUCTOS_SIN_MODULO',
        );

        $this->actingAs($permissionOnlyUser)
            ->get('/despacho-productos/clientes')
            ->assertForbidden();

        $directoryOnlyUser = User::factory()->create();
        $this->grantModules(
            $directoryOnlyUser,
            ['MODULO_DIRECTORIO'],
            'DIRECTORIO_SIN_DESPACHO_PRODUCTOS',
        );
        Sanctum::actingAs($directoryOnlyUser, ['api']);

        $this->getJson('/api/v1/despacho-productos/clientes')->assertForbidden();
        $this->postJson('/api/v1/despacho-productos/clientes', [])->assertForbidden();
        $this->putJson('/api/v1/despacho-productos/clientes/1', [])->assertForbidden();
        $this->deleteJson('/api/v1/despacho-productos/clientes/1')->assertForbidden();

        $moduleUser = User::factory()->create();
        $this->grantModules(
            $moduleUser,
            ['MODULO_DESPACHO_PRODUCTOS'],
            'MODULO_PRODUCTOS_CON_CLIENTES',
        );

        $this->actingAs($moduleUser)
            ->get('/despacho-productos/clientes')
            ->assertOk();
    }

    public function test_product_dispatch_account_statement_routes_require_the_module_and_management_permission(): void
    {
        foreach ([
            'despacho-productos.estado-cuenta',
            'despacho-productos.estado-cuenta.pdf',
        ] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "No se registró la ruta {$routeName}.");
            $this->assertContains(
                'module:MODULO_DESPACHO_PRODUCTOS',
                $route->gatherMiddleware(),
            );
            $this->assertContains(
                'permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR',
                $route->gatherMiddleware(),
            );
        }

        $apiRoutes = collect(app('router')->getRoutes()->getRoutes());
        foreach ([
            'api/v1/despacho-productos/estado-cuenta/catalogo',
            'api/v1/despacho-productos/estado-cuenta',
        ] as $uri) {
            $apiRoute = $apiRoutes->first(fn ($candidate): bool => $candidate->uri() === $uri
                && in_array('GET', $candidate->methods(), true));

            $this->assertNotNull($apiRoute, "No se registró GET {$uri}.");
            $this->assertContains(
                'module:MODULO_DESPACHO_PRODUCTOS',
                $apiRoute->gatherMiddleware(),
            );
            $this->assertContains(
                'permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR',
                $apiRoute->gatherMiddleware(),
            );
        }

        $permissionOnlyUser = User::factory()->create();
        $this->grantModules(
            $permissionOnlyUser,
            ['PRODUCTOS_DESPACHO_TICKETS_GESTIONAR'],
            'ESTADO_CUENTA_PRODUCTOS_SIN_MODULO',
        );

        $this->actingAs($permissionOnlyUser)
            ->get('/despacho-productos/estado-cuenta')
            ->assertForbidden();

        $moduleUser = User::factory()->create();
        $this->grantModules(
            $moduleUser,
            ['MODULO_DESPACHO_PRODUCTOS'],
            'MODULO_PRODUCTOS_CON_ESTADO_CUENTA',
        );

        $this->actingAs($moduleUser)
            ->get('/despacho-productos/estado-cuenta')
            ->assertOk();
    }

    public function test_modules_from_multiple_roles_are_unioned_for_routes_and_menu(): void
    {
        $user = User::factory()->create();
        $this->grantModules(
            $user,
            ['MODULO_DIRECTORIO'],
            'DIRECTORIO',
            'Directorio',
        );
        $this->grantModules(
            $user,
            ['MODULO_FINANZAS'],
            'TESORERIA',
            'Tesorería',
        );

        $this->actingAs($user);

        $this->get('/directorio')->assertOk();
        $this->get('/finanzas')->assertOk();
        $this->get('/control-javas')->assertForbidden();

        $this->get('/')
            ->assertOk()
            ->assertSee(route('directorio'), false)
            ->assertSee(route('finanzas'), false)
            ->assertDontSee(route('control-javas'), false)
            ->assertDontSee(route('recepcion-pollo-vivo.menu'), false)
            ->assertDontSee(route('recepcion-pollo-vivo'), false)
            ->assertDontSee(route('despacho-productos.menu'), false)
            ->assertDontSee(route('operacion'), false);
    }

    public function test_menu_only_renders_tiles_authorized_for_the_user(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_FINANZAS']);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee(route('finanzas'), false)
            ->assertDontSee(route('operacion'), false)
            ->assertDontSee(route('recepcion-pollo-vivo.menu'), false)
            ->assertDontSee(route('recepcion-pollo-vivo'), false)
            ->assertDontSee(route('despacho-mayorista-2'), false)
            ->assertDontSee(route('despacho-minorista'), false)
            ->assertDontSee(route('despacho-productos.menu'), false)
            ->assertDontSee(route('precios-jornada'), false)
            ->assertDontSee(route('reporte-proveedores'), false)
            ->assertDontSee(route('directorio'), false)
            ->assertDontSee(route('flota'), false)
            ->assertDontSee(route('control-javas'), false)
            ->assertDontSee(route('jornada'), false)
            ->assertDontSee(url('/administracion/accesos'), false);
    }

    public function test_menu_renders_spanish_accents_without_mojibake(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_USUARIOS_ROLES']);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSeeText('Sesión activa')
            ->assertSeeText('Cerrar sesión')
            ->assertSeeText('Personas, contraseñas y accesos por módulo')
            ->assertDontSee("\u{00C3}", false)
            ->assertDontSee("\u{00C2}", false);
    }

    public function test_administrator_role_can_reach_every_module_without_explicit_assignments(): void
    {
        $user = User::factory()->create();
        $this->makeAdministrator($user);

        $this->actingAs($user);

        foreach ([
            '/operacion',
            '/recepcion-pollo-vivo/menu',
            '/recepcion-pollo-vivo',
            '/recepcion-pollo-vivo/historial',
            '/despacho-mayorista-2',
            '/despacho-minorista',
            '/despacho-minorista-2',
            '/despacho-productos',
            '/despacho-productos/productos',
            '/despacho-productos/despacho',
            '/despacho-productos/clientes',
            '/despacho-productos/configuracion-ticket',
            '/despacho-productos/tickets',
            '/despacho-productos/estado-cuenta',
            '/precios-jornada',
            '/tickets-dia',
            '/reporte-proveedores',
            '/gestion-pesadas',
            '/directorio',
            '/flota',
            '/finanzas',
            '/finanzas/tickets',
            '/control-javas',
            '/jornada',
            '/administracion/accesos',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_journey_prices_view_is_shared_only_by_the_two_retail_modules(): void
    {
        foreach ([
            'MODULO_DESPACHO_MINORISTA_1',
            'MODULO_DESPACHO_MINORISTA_2',
        ] as $index => $module) {
            $user = User::factory()->create();
            $this->grantModules($user, [$module], "MINORISTA_{$index}", "Minorista {$index}");

            $this->actingAs($user)
                ->get('/precios-jornada')
                ->assertOk();

            $this->get('/')
                ->assertOk()
                ->assertSee(route('precios-jornada'), false)
                ->assertDontSee(route('jornada'), false);
        }

        $journeyUser = User::factory()->create();
        $this->grantModules($journeyUser, ['MODULO_JORNADA_PROVEEDORES']);

        $this->actingAs($journeyUser)
            ->get('/precios-jornada')
            ->assertForbidden();
    }

    public function test_module_permission_protects_finance_api_and_grants_internal_operations(): void
    {
        config()->set('directory.public_access', false);

        $unauthorized = User::factory()->create();
        $this->grantModules($unauthorized, ['MODULO_DIRECTORIO']);
        Sanctum::actingAs($unauthorized, ['api']);

        $this->getJson('/api/v1/finanzas/catalogo')->assertForbidden();
        $this->postJson('/api/v1/finanzas/movimientos', [])->assertForbidden();

        $authorized = User::factory()->create();
        $this->grantModules($authorized, ['MODULO_FINANZAS']);
        Sanctum::actingAs($authorized, ['api']);

        $this->getJson('/api/v1/finanzas/catalogo')->assertOk();
        $this->postJson('/api/v1/finanzas/movimientos', [])->assertUnprocessable();
    }

    public function test_live_chicken_reception_api_requires_its_own_module(): void
    {
        $unauthorized = User::factory()->create();
        $this->grantModules($unauthorized, ['MODULO_DIRECTORIO']);
        Sanctum::actingAs($unauthorized, ['api']);

        $this->getJson('/api/v1/recepcion-pollo-vivo')->assertForbidden();
        $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', [])->assertForbidden();
    }

    public function test_directory_access_does_not_expose_financial_controls_in_party_details(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_DIRECTORIO']);

        $this->actingAs($user);

        $this->get('/directorio/clientes/15')
            ->assertOk()
            ->assertDontSee('id="customerFinanceSection"', false);

        $this->get('/directorio/proveedores/20')
            ->assertOk()
            ->assertDontSee('id="providerFinanceSection"', false)
            ->assertDontSee('id="providerDirectDepositsSection"', false)
            ->assertDontSee(route('compras.create').'?proveedor_id=20', false);
    }

    public function test_finance_controls_in_party_details_require_both_related_modules(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, [
            'MODULO_DIRECTORIO',
            'MODULO_FINANZAS',
        ]);

        $this->actingAs($user);

        $this->get('/directorio/clientes/15')
            ->assertOk()
            ->assertSee('id="customerFinanceSection"', false);

        $this->get('/directorio/proveedores/20')
            ->assertOk()
            ->assertSee('id="providerFinanceSection"', false)
            ->assertSee(route('compras.create').'?proveedor_id=20', false);
    }
}
