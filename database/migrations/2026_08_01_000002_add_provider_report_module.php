<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULE_CODE = 'MODULO_REPORTE_PROVEEDORES';

    public function up(): void
    {
        $now = now();

        DB::table('permisos')->updateOrInsert(
            ['codigo' => self::MODULE_CODE],
            [
                'descripcion' => 'Reporte de proveedores',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $permissionId = DB::table('permisos')
            ->where('codigo', self::MODULE_CODE)
            ->value('id');
        $sourcePermissionIds = DB::table('permisos')
            ->whereIn('codigo', [
                'MODULO_JORNADA_PROVEEDORES',
                'MODULO_RESUMEN_JORNADA',
                'RECEPCIONES_VER',
            ])
            ->pluck('id');
        $eligibleRoleIds = DB::table('roles')
            ->whereIn('codigo', ['ADMINISTRADOR', 'OPERADOR'])
            ->when($sourcePermissionIds->isNotEmpty(), fn ($query) => $query->orWhereIn(
                'id',
                DB::table('rol_permisos')
                    ->whereIn('permiso_id', $sourcePermissionIds)
                    ->select('rol_id'),
            ))
            ->pluck('id');

        foreach ($eligibleRoleIds as $roleId) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permisos')
            ->where('codigo', self::MODULE_CODE)
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('rol_permisos')->where('permiso_id', $permissionId)->delete();
        DB::table('permisos')->where('id', $permissionId)->delete();
    }
};
