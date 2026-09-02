<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION_CODE = 'PRODUCTOS_DESPACHO_TICKETS_GESTIONAR';

    public function up(): void
    {
        $now = now();

        DB::table('permisos')->updateOrInsert(
            ['codigo' => self::PERMISSION_CODE],
            [
                'descripcion' => 'Consultar, corregir y reimprimir tickets de despacho de productos',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $permissionId = DB::table('permisos')
            ->where('codigo', self::PERMISSION_CODE)
            ->value('id');
        $sourcePermissionIds = DB::table('permisos')
            ->whereIn('codigo', [
                'MODULO_DESPACHO_PRODUCTOS',
                'PRODUCTOS_DESPACHO_DESPACHAR',
            ])
            ->pluck('id');

        if (! $permissionId || $sourcePermissionIds->isEmpty()) {
            return;
        }

        DB::table('rol_permisos')
            ->whereIn('permiso_id', $sourcePermissionIds)
            ->distinct()
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
            ->where('codigo', self::PERMISSION_CODE)
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('rol_permisos')->where('permiso_id', $permissionId)->delete();
        DB::table('permisos')->where('id', $permissionId)->delete();
    }
};
