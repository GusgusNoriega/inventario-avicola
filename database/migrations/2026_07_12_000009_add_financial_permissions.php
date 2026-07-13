<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $permissions = [
        'FINANZAS_VER' => 'Ver finanzas',
        'CUENTAS_FINANCIERAS_GESTIONAR' => 'Gestionar cuentas financieras',
        'PAGOS_REGISTRAR' => 'Registrar pagos',
        'PAGOS_ANULAR' => 'Anular pagos',
        'SALDOS_AJUSTAR' => 'Ajustar saldos',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as $code => $description) {
            DB::table('permisos')->updateOrInsert(
                ['codigo' => $code],
                ['descripcion' => $description, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $permissionIds = DB::table('permisos')
            ->whereIn('codigo', array_keys($this->permissions))
            ->pluck('id');
        $administratorIds = DB::table('roles')
            ->where('codigo', 'ADMINISTRADOR')
            ->pluck('id');

        foreach ($administratorIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $roleId,
                    'permiso_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permisos')
            ->whereIn('codigo', array_keys($this->permissions))
            ->pluck('id');

        DB::table('rol_permisos')->whereIn('permiso_id', $permissionIds)->delete();
        DB::table('permisos')->whereIn('id', $permissionIds)->delete();
    }
};
