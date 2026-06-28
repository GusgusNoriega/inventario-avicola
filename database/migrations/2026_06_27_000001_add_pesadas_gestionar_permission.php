<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissionId = DB::table('permisos')
            ->where('codigo', 'PESADAS_GESTIONAR')
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permisos')->insertGetId([
                'codigo' => 'PESADAS_GESTIONAR',
                'descripcion' => 'Editar y anular pesadas registradas',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $dispatchPermissionId = DB::table('permisos')
            ->where('codigo', 'DESPACHOS_CREAR')
            ->value('id');

        if (! $dispatchPermissionId) {
            return;
        }

        DB::table('rol_permisos')
            ->where('permiso_id', $dispatchPermissionId)
            ->pluck('rol_id')
            ->each(function (int $roleId) use ($permissionId, $now): void {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $roleId,
                    'permiso_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        $permissionId = DB::table('permisos')
            ->where('codigo', 'PESADAS_GESTIONAR')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('rol_permisos')->where('permiso_id', $permissionId)->delete();
        DB::table('permisos')->where('id', $permissionId)->delete();
    }
};
