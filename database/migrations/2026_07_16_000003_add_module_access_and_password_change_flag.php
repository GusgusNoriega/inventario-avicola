<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('usuarios', 'debe_cambiar_password')) {
            Schema::table('usuarios', function (Blueprint $table): void {
                $table->boolean('debe_cambiar_password')->default(false)->after('password_hash');
            });
        }

        /** @var array<string, array<string, mixed>> $modules */
        $modules = config('access_modules.modules', []);
        $now = now();

        foreach ($modules as $moduleCode => $module) {
            DB::table('permisos')->updateOrInsert(
                ['codigo' => $moduleCode],
                [
                    'descripcion' => (string) ($module['name'] ?? $moduleCode),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permisos')->pluck('id', 'codigo');

        DB::table('roles')
            ->orderBy('id')
            ->each(function (object $role) use ($modules, $permissionIds, $now): void {
                $currentCodes = DB::table('rol_permisos')
                    ->join('permisos', 'permisos.id', '=', 'rol_permisos.permiso_id')
                    ->where('rol_permisos.rol_id', $role->id)
                    ->pluck('permisos.codigo')
                    ->all();
                $selectedModules = [];

                foreach ($modules as $moduleCode => $module) {
                    $legacyCodes = $module['legacy_permissions'] ?? [];

                    if ($role->codigo === 'ADMINISTRADOR'
                        || array_intersect($legacyCodes, $currentCodes) !== []) {
                        $selectedModules[] = $moduleCode;
                    }
                }

                foreach ($selectedModules as $code) {
                    $permissionId = $permissionIds->get($code);

                    if (! $permissionId) {
                        continue;
                    }

                    DB::table('rol_permisos')->insertOrIgnore([
                        'rol_id' => $role->id,
                        'permiso_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        $moduleCodes = array_keys(config('access_modules.modules', []));
        $permissionIds = DB::table('permisos')
            ->whereIn('codigo', $moduleCodes)
            ->pluck('id');

        DB::table('rol_permisos')->whereIn('permiso_id', $permissionIds)->delete();
        DB::table('permisos')->whereIn('id', $permissionIds)->delete();

        if (Schema::hasColumn('usuarios', 'debe_cambiar_password')) {
            Schema::table('usuarios', function (Blueprint $table): void {
                $table->dropColumn('debe_cambiar_password');
            });
        }
    }
};
