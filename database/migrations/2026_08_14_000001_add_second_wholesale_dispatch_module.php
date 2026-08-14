<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULE_CODE = 'MODULO_DESPACHO_MAYORISTA_2';

    public function up(): void
    {
        $now = now();

        DB::table('permisos')->updateOrInsert(
            ['codigo' => self::MODULE_CODE],
            [
                'descripcion' => 'Despacho mayorista 2',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
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
