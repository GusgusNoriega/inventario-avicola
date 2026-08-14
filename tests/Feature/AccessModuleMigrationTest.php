<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccessModuleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_markers_are_created_from_the_central_catalogue(): void
    {
        $moduleCodes = array_keys(config('access_modules.modules'));

        $this->assertCount(13, $moduleCodes);
        $this->assertEqualsCanonicalizing(
            $moduleCodes,
            Permission::query()
                ->whereIn('codigo', $moduleCodes)
                ->pluck('codigo')
                ->all(),
        );
    }

    public function test_migration_translates_legacy_roles_and_grants_all_modules_to_administrator(): void
    {
        $user = User::factory()->create();
        $financeRole = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'FINANZAS_LEGACY',
            'nombre' => 'Finanzas legacy',
        ]);
        $financeRole->permissions()->attach(
            Permission::query()->where('codigo', 'FINANZAS_VER')->value('id'),
        );
        $administratorRole = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);

        $migration = $this->moduleMigration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('usuarios', 'debe_cambiar_password'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('usuarios', 'debe_cambiar_password'));
        $this->assertSame(
            ['MODULO_FINANZAS'],
            $financeRole->fresh()->permissions()
                ->where('codigo', 'like', 'MODULO_%')
                ->pluck('codigo')
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_keys(config('access_modules.modules')),
            $administratorRole->fresh()->permissions()
                ->where('codigo', 'like', 'MODULO_%')
                ->pluck('codigo')
                ->all(),
        );
    }

    public function test_provider_report_migration_grants_existing_operational_roles(): void
    {
        $user = User::factory()->create();
        $operator = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
        ]);
        $receivingRole = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'RECEPCION_EXISTENTE',
            'nombre' => 'Recepción existente',
        ]);
        $journeyRole = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'JORNADA_EXISTENTE',
            'nombre' => 'Jornada existente',
        ]);
        $unrelatedRole = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'SIN_ACCESO',
            'nombre' => 'Sin acceso',
        ]);
        $receivingPermission = Permission::query()->firstOrCreate(
            ['codigo' => 'RECEPCIONES_VER'],
            ['descripcion' => 'Consultar recepciones'],
        );
        $receivingRole->permissions()->attach($receivingPermission);
        $journeyRole->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_JORNADA_PROVEEDORES')->value('id'),
        );

        $migration = $this->providerReportMigration();
        $migration->down();
        $migration->up();

        foreach ([
            'operator' => $operator,
            'receiving role' => $receivingRole,
            'journey role' => $journeyRole,
        ] as $roleLabel => $eligibleRole) {
            $this->assertTrue(
                $eligibleRole->fresh()->permissions()->where(
                    'codigo',
                    'MODULO_REPORTE_PROVEEDORES',
                )->exists(),
                "The {$roleLabel} should receive the provider report module. Current permissions: ".
                    $eligibleRole->fresh()->permissions()->pluck('codigo')->implode(', '),
            );
        }
        $this->assertFalse(
            $unrelatedRole->fresh()->permissions()->where(
                'codigo',
                'MODULO_REPORTE_PROVEEDORES',
            )->exists(),
        );
    }

    public function test_second_wholesale_dispatch_migration_creates_an_isolated_marker_and_rolls_back_cleanly(): void
    {
        $user = User::factory()->create();
        $operator = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
        ]);
        $operator->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_DESPACHO_MAYORISTA')->value('id'),
        );

        $migration = $this->secondWholesaleDispatchMigration();
        $migration->down();
        $migration->up();

        $permission = Permission::query()
            ->where('codigo', 'MODULO_DESPACHO_MAYORISTA_2')
            ->firstOrFail();

        $this->assertFalse(
            $operator->fresh()->permissions()->whereKey($permission->id)->exists(),
        );

        $operator->permissions()->attach($permission);
        $migration->down();

        $this->assertDatabaseMissing('rol_permisos', [
            'rol_id' => $operator->id,
            'permiso_id' => $permission->id,
        ]);
        $this->assertDatabaseMissing('permisos', [
            'id' => $permission->id,
            'codigo' => 'MODULO_DESPACHO_MAYORISTA_2',
        ]);
    }

    private function moduleMigration(): Migration
    {
        return require database_path(
            'migrations/2026_07_16_000003_add_module_access_and_password_change_flag.php',
        );
    }

    private function providerReportMigration(): Migration
    {
        return require database_path(
            'migrations/2026_08_01_000002_add_provider_report_module.php',
        );
    }

    private function secondWholesaleDispatchMigration(): Migration
    {
        return require database_path(
            'migrations/2026_08_14_000001_add_second_wholesale_dispatch_module.php',
        );
    }
}
