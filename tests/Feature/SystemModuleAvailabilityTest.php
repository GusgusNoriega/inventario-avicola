<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class SystemModuleAvailabilityTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_every_module_is_active_by_default_and_list_command_shows_instructions(): void
    {
        $modules = config('access_modules.modules');
        $moduleCodes = array_keys($modules);
        $assignableCodes = collect($modules)
            ->filter(fn (array $module): bool => (bool) ($module['assignable'] ?? true))
            ->keys();

        $this->assertCount(16, $moduleCodes);
        $this->assertCount(14, $assignableCodes);
        $this->assertSame(count($moduleCodes), DB::table('modulos_sistema')->count());
        $this->assertSame(0, DB::table('modulos_sistema')->where('activo', false)->count());

        $this->artisan('modulos')
            ->expectsOutputToContain('Estado de los módulos del sistema')
            ->expectsOutputToContain('MODULO_PRECIOS_JORNADA')
            ->expectsOutputToContain('MODULO_INSTALAR_APLICACION')
            ->expectsOutputToContain('Instrucciones')
            ->expectsOutputToContain('Activar un módulo:')
            ->expectsOutputToContain('Desactivar un módulo:')
            ->assertSuccessful();
    }

    public function test_terminal_command_disables_enables_and_handles_idempotent_or_invalid_actions(): void
    {
        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'modulo_finanzas',
        ])
            ->expectsOutputToContain('Módulo desactivado')
            ->assertSuccessful();

        $this->assertDatabaseHas('modulos_sistema', [
            'codigo' => 'MODULO_FINANZAS',
            'activo' => false,
        ]);

        $this->artisan('modulos')
            ->expectsOutputToContain('INACTIVO')
            ->assertSuccessful();

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_FINANZAS',
        ])
            ->expectsOutputToContain('Sin cambios')
            ->assertSuccessful();

        $this->artisan('modulos', [
            'accion' => 'activar',
            'modulo' => 'MODULO_FINANZAS',
        ])
            ->expectsOutputToContain('Módulo activado')
            ->assertSuccessful();

        $this->assertDatabaseHas('modulos_sistema', [
            'codigo' => 'MODULO_FINANZAS',
            'activo' => true,
        ]);

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_INEXISTENTE',
        ])
            ->expectsOutputToContain('no existe')
            ->assertFailed();
    }

    public function test_disabled_module_is_hidden_everywhere_and_blocked_without_affecting_other_modules(): void
    {
        $user = User::factory()->create();
        $role = $this->grantModules($user, [
            'MODULO_USUARIOS_ROLES',
            'MODULO_FINANZAS',
            'MODULO_DIRECTORIO',
        ]);
        $role->permissions()->attach(
            Permission::query()->where('codigo', 'FINANZAS_VER')->value('id')
        );
        $this->assertTrue($user->fresh()->hasPermission('FINANZAS_VER'));

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_FINANZAS',
        ])->assertSuccessful();

        $this->assertFalse($user->fresh()->hasPermission('FINANZAS_VER'));

        $this->actingAs($user, 'web');

        $this->get('/finanzas')
            ->assertForbidden()
            ->assertSeeText('Este módulo está desactivado en el servidor.');
        $this->get('/directorio')->assertOk();
        $this->get('/')
            ->assertOk()
            ->assertDontSee(route('finanzas'), false)
            ->assertSee(route('directorio'), false);

        Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/finanzas/catalogo')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');

        $catalogue = $this->getJson('/api/v1/admin/modules')->assertOk();
        $catalogue->assertJsonMissing(['code' => 'MODULO_FINANZAS']);
        $catalogue->assertJsonFragment(['code' => 'MODULO_DIRECTORIO']);

        $roles = $this->getJson('/api/v1/admin/roles')->assertOk();
        $roleData = collect($roles->json('data'))->firstWhere('id', $role->id);
        $this->assertIsArray($roleData);
        $this->assertNotContains('MODULO_FINANZAS', $roleData['module_codes']);
        $this->assertContains('MODULO_DIRECTORIO', $roleData['module_codes']);

        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertNotContains('MODULO_FINANZAS', $me->json('data.module_codes'));
        $this->assertNotContains('MODULO_FINANZAS', $me->json('data.permissions'));
        $this->assertNotContains('FINANZAS_VER', $me->json('data.permissions'));
        $this->assertContains('MODULO_DIRECTORIO', $me->json('data.module_codes'));
    }

    public function test_server_disable_overrides_administrator_access(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_FINANZAS',
        ])->assertSuccessful();

        $this->actingAs($administrator, 'web');
        $this->get('/finanzas')->assertForbidden();
        $this->get('/directorio')->assertOk();
        $this->get('/')
            ->assertOk()
            ->assertDontSee(route('finanzas'), false)
            ->assertSee(route('directorio'), false);

        Sanctum::actingAs($administrator, ['api']);
        $this->getJson('/api/v1/finanzas/catalogo')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_every_catalogued_module_hides_and_blocks_its_representative_view(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        $this->actingAs($administrator, 'web');

        foreach (config('access_modules.modules') as $code => $module) {
            $this->artisan('modulos', [
                'accion' => 'desactivar',
                'modulo' => $code,
            ])->assertSuccessful();

            $path = (string) $module['path'];
            $menuHref = $code === 'MODULO_DESPACHO_MAYORISTA'
                ? url($path).'#despacho'
                : url($path);

            $this->get($path)
                ->assertForbidden()
                ->assertSeeText('Este módulo está desactivado en el servidor.');
            $this->get('/')
                ->assertOk()
                ->assertDontSee('href="'.$menuHref.'"', false);

            $this->artisan('modulos', [
                'accion' => 'activar',
                'modulo' => $code,
            ])->assertSuccessful();
        }
    }

    public function test_global_availability_middleware_also_blocks_routes_without_authentication(): void
    {
        Route::get('/api/v1/module-availability-probe', fn () => response()->json(['ok' => true]))
            ->middleware('module.enabled:MODULO_DIRECTORIO');

        $this->getJson('/api/v1/module-availability-probe')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_DIRECTORIO',
        ])->assertSuccessful();

        $this->getJson('/api/v1/module-availability-probe')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_editing_a_role_preserves_hidden_assignment_and_reactivation_restores_access(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        $operator = $this->createUserForCompany($administrator);
        $role = $this->grantModules(
            $operator,
            ['MODULO_FINANZAS', 'MODULO_DIRECTORIO'],
            'OPERADOR_MODULOS',
            'Operador de módulos',
        );

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_FINANZAS',
        ])->assertSuccessful();

        Sanctum::actingAs($administrator, ['api']);

        $response = $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'module_codes' => ['MODULO_FLOTA'],
        ])->assertOk();

        $this->assertSame(['MODULO_FLOTA'], $response->json('data.module_codes'));
        $this->assertEqualsCanonicalizing(
            ['MODULO_FINANZAS', 'MODULO_FLOTA'],
            $role->fresh()->permissions()->pluck('codigo')->all(),
        );

        $this->artisan('modulos', [
            'accion' => 'activar',
            'modulo' => 'MODULO_FINANZAS',
        ])->assertSuccessful();

        $this->actingAs($operator, 'web');
        $this->get('/finanzas')->assertOk();
        $this->get('/flota')->assertOk();
        $this->get('/directorio')->assertForbidden();
    }

    public function test_disabled_module_cannot_be_assigned_through_the_administration_api(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_FINANZAS',
        ])->assertSuccessful();

        Sanctum::actingAs($administrator, ['api']);

        $this->postJson('/api/v1/admin/roles', [
            'code' => 'FINANZAS_NUEVO',
            'name' => 'Finanzas nuevo',
            'module_codes' => ['MODULO_FINANZAS'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_codes');

        $this->assertDatabaseMissing('roles', [
            'empresa_id' => $administrator->empresa_id,
            'codigo' => 'FINANZAS_NUEVO',
        ]);
    }

    public function test_server_only_modules_never_appear_as_assignable_role_permissions(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        Sanctum::actingAs($administrator, ['api']);

        $catalogue = $this->getJson('/api/v1/admin/modules')->assertOk();

        foreach (['MODULO_PRECIOS_JORNADA', 'MODULO_INSTALAR_APLICACION'] as $module) {
            $catalogue->assertJsonMissing(['code' => $module]);
            $this->assertDatabaseMissing('permisos', ['codigo' => $module]);

            $this->postJson('/api/v1/admin/roles', [
                'code' => 'ROL_'.str_replace('MODULO_', '', $module),
                'name' => 'Rol de prueba',
                'module_codes' => [$module],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('module_codes');
        }
    }

    public function test_shared_route_remains_available_while_at_least_one_owner_module_is_active(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_DESPACHO_MINORISTA_2']);

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_DESPACHO_MINORISTA_1',
        ])->assertSuccessful();

        $this->actingAs($user, 'web')
            ->get('/precios-jornada')
            ->assertOk();

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_DESPACHO_MINORISTA_2',
        ])->assertSuccessful();

        $this->get('/precios-jornada')->assertForbidden();

        $this->artisan('modulos', [
            'accion' => 'activar',
            'modulo' => 'MODULO_DESPACHO_MINORISTA_2',
        ])->assertSuccessful();
        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_PRECIOS_JORNADA',
        ])->assertSuccessful();

        $this->get('/precios-jornada')->assertForbidden();
        $this->get('/')
            ->assertOk()
            ->assertDontSee(route('precios-jornada'), false)
            ->assertSee(route('despacho-minorista-2'), false);
        $this->get('/despacho-minorista-2')
            ->assertOk()
            ->assertSee('data-journey-price-management-enabled="false"', false);

        Sanctum::actingAs($user, ['api']);
        $this->getJson('/api/v1/operacion/precios-jornada')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
        $retailPriceRead = $this->getJson('/api/v1/despacho-minorista-2/precios-jornada');
        $this->assertNotSame(403, $retailPriceRead->getStatusCode());
        $this->assertNotSame('MODULE_DISABLED', $retailPriceRead->json('code'));
        $this->putJson('/api/v1/operacion/precios-jornada', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_installation_module_hides_and_blocks_every_installation_view(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_DESPACHO_MINORISTA_1']);
        $this->actingAs($user, 'web');

        $this->get('/instalar')->assertOk();
        $this->get('/')
            ->assertOk()
            ->assertSee(route('install-app'), false);
        $this->get('/despacho-minorista')
            ->assertOk()
            ->assertSee(route('install-app').'#ticketPrinterSetup', false);

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_INSTALAR_APLICACION',
        ])->assertSuccessful();

        $this->get('/instalar')->assertForbidden();
        $this->get('/instalar/configurador-impresion')->assertForbidden();
        $this->get('/')
            ->assertOk()
            ->assertDontSee(route('install-app'), false);
        $this->get('/despacho-minorista')
            ->assertOk()
            ->assertDontSee(route('install-app').'#ticketPrinterSetup', false);

        $this->artisan('modulos', [
            'accion' => 'activar',
            'modulo' => 'MODULO_INSTALAR_APLICACION',
        ])->assertSuccessful();

        $this->get('/instalar')->assertOk();
    }

    public function test_internal_views_do_not_show_links_to_disabled_modules(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, [
            'MODULO_DESPACHO_MAYORISTA',
            'MODULO_DESPACHO_MAYORISTA_2',
            'MODULO_JORNADA_PROVEEDORES',
            'MODULO_CONTROL_JAVAS',
            'MODULO_FLOTA',
        ]);
        $this->actingAs($user, 'web');

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_JORNADA_PROVEEDORES',
        ])->assertSuccessful();

        $this->get('/operacion')
            ->assertOk()
            ->assertDontSee(route('jornada'), false);
        $this->get('/despacho-mayorista-2')
            ->assertOk()
            ->assertDontSee(route('jornada'), false);

        $this->artisan('modulos', [
            'accion' => 'activar',
            'modulo' => 'MODULO_JORNADA_PROVEEDORES',
        ])->assertSuccessful();
        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_DESPACHO_MAYORISTA',
        ])->assertSuccessful();

        $this->get('/jornada')
            ->assertOk()
            ->assertDontSee(route('operacion').'#despacho', false);

        $this->artisan('modulos', [
            'accion' => 'desactivar',
            'modulo' => 'MODULO_FLOTA',
        ])->assertSuccessful();

        $this->get('/control-javas/devoluciones')
            ->assertOk()
            ->assertDontSee(route('flota'), false);
    }
}
