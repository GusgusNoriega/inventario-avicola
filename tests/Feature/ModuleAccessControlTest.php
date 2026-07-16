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
            '/finanzas',
            '/finanzas/saldos',
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
            '/finanzas',
            '/finanzas/saldos',
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
            '/finanzas/movimientos/nuevo',
            '/compras',
            '/compras/nueva',
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
            ->assertDontSee(route('despacho-minorista'), false)
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
            '/despacho-minorista',
            '/despacho-minorista-2',
            '/tickets-dia',
            '/gestion-pesadas',
            '/directorio',
            '/flota',
            '/finanzas',
            '/control-javas',
            '/jornada',
            '/administracion/accesos',
        ] as $path) {
            $this->get($path)->assertOk();
        }
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
