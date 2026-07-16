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

        $this->assertCount(11, $moduleCodes);
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

    private function moduleMigration(): Migration
    {
        return require database_path(
            'migrations/2026_07_16_000003_add_module_access_and_password_change_flag.php',
        );
    }
}
