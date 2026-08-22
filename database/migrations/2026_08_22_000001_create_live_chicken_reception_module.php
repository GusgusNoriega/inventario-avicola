<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULE_CODE = 'MODULO_RECEPCION_POLLO_VIVO';

    public function up(): void
    {
        Schema::create('configuraciones_recepcion_pollo_vivo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sucursal_id')->unique()->constrained('sucursales')->cascadeOnDelete();
            $table->unsignedBigInteger('propietario_externo_predeterminado_id')->nullable();
            $table->unsignedBigInteger('almacen_columna_1_id')->nullable();
            $table->unsignedBigInteger('almacen_columna_2_id')->nullable();
            $table->unsignedBigInteger('cliente_columna_3_id')->nullable();
            $table->unsignedBigInteger('cliente_columna_4_id')->nullable();
            $table->foreignId('updated_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('propietario_externo_predeterminado_id', 'cfg_recepcion_propietario_fk')
                ->references('id')->on('terceros')->restrictOnDelete();
            $table->foreign('almacen_columna_1_id', 'cfg_recepcion_almacen_1_fk')
                ->references('id')->on('almacenes')->restrictOnDelete();
            $table->foreign('almacen_columna_2_id', 'cfg_recepcion_almacen_2_fk')
                ->references('id')->on('almacenes')->restrictOnDelete();
            $table->foreign('cliente_columna_3_id', 'cfg_recepcion_cliente_3_fk')
                ->references('id')->on('terceros')->restrictOnDelete();
            $table->foreign('cliente_columna_4_id', 'cfg_recepcion_cliente_4_fk')
                ->references('id')->on('terceros')->restrictOnDelete();
        });

        Schema::create('recepciones_pollo_vivo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jornada_id')->unique()->constrained('jornadas_operativas')->restrictOnDelete();
            $table->string('origen', 80)->default('Camión del día');
            $table->string('estado', 20)->default('ABIERTA')->index();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('pesadas_recepcion_pollo_vivo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recepcion_id')->constrained('recepciones_pollo_vivo')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedBigInteger('numero');
            $table->unsignedTinyInteger('columna');
            $table->string('propietario_tipo', 20);
            $table->foreignId('propietario_externo_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->string('destino_tipo', 20);
            $table->foreignId('almacen_destino_id')->nullable()->constrained('almacenes')->restrictOnDelete();
            $table->foreignId('cliente_destino_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->string('sexo', 20);
            $table->foreignId('tipo_pollo_id')->constrained('tipos_pollo')->restrictOnDelete();
            $table->foreignId('tipo_java_id')->constrained('tipos_java')->restrictOnDelete();
            $table->foreignId('lectura_balanza_id')->nullable()->unique()->constrained('lecturas_balanza')->nullOnDelete();
            $table->string('origen_peso', 30);
            $table->unsignedInteger('aves_por_java');
            $table->unsignedInteger('cantidad_javas');
            $table->unsignedInteger('cantidad_aves');
            $table->decimal('peso_java_kg_snapshot', 12, 3);
            $table->decimal('peso_leido_kg', 12, 3);
            $table->decimal('peso_bruto_kg', 12, 3);
            $table->decimal('tara_total_kg', 12, 3);
            $table->decimal('peso_neto_kg', 12, 3);
            $table->timestamp('pesada_at');
            $table->string('estado', 20)->default('ACTIVA');
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 250)->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['recepcion_id', 'numero'], 'pesada_recepcion_numero_unique');
            $table->index(['recepcion_id', 'columna', 'estado'], 'pesada_recepcion_columna_estado_index');
            $table->index(['propietario_tipo', 'propietario_externo_id'], 'pesada_recepcion_propietario_index');
            $table->index(['destino_tipo', 'almacen_destino_id', 'cliente_destino_id'], 'pesada_recepcion_destino_index');
        });

        Schema::table('movimiento_detalles', function (Blueprint $table): void {
            $table->unsignedBigInteger('pesada_recepcion_pollo_vivo_id')->nullable()->after('pesada_id');
            $table->unique('pesada_recepcion_pollo_vivo_id', 'movimiento_detalle_recepcion_unique');
            $table->foreign('pesada_recepcion_pollo_vivo_id', 'movimiento_detalle_recepcion_fk')
                ->references('id')->on('pesadas_recepcion_pollo_vivo')->restrictOnDelete();
        });

        Schema::table('movimientos_javas', function (Blueprint $table): void {
            $table->unsignedBigInteger('pesada_recepcion_pollo_vivo_id')->nullable()->after('ticket_despacho_id');
            $table->unique('pesada_recepcion_pollo_vivo_id', 'movimiento_java_recepcion_unique');
            $table->foreign('pesada_recepcion_pollo_vivo_id', 'movimiento_java_recepcion_fk')
                ->references('id')->on('pesadas_recepcion_pollo_vivo')->restrictOnDelete();
        });

        $this->createModulePermission();
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('movimientos_javas', function (Blueprint $table) use ($isSqlite): void {
            $table->dropForeign($isSqlite
                ? ['pesada_recepcion_pollo_vivo_id']
                : 'movimiento_java_recepcion_fk');
            $table->dropUnique('movimiento_java_recepcion_unique');
            $table->dropColumn('pesada_recepcion_pollo_vivo_id');
        });

        Schema::table('movimiento_detalles', function (Blueprint $table) use ($isSqlite): void {
            $table->dropForeign($isSqlite
                ? ['pesada_recepcion_pollo_vivo_id']
                : 'movimiento_detalle_recepcion_fk');
            $table->dropUnique('movimiento_detalle_recepcion_unique');
            $table->dropColumn('pesada_recepcion_pollo_vivo_id');
        });

        Schema::dropIfExists('pesadas_recepcion_pollo_vivo');
        Schema::dropIfExists('recepciones_pollo_vivo');
        Schema::dropIfExists('configuraciones_recepcion_pollo_vivo');

        $permissionId = DB::table('permisos')->where('codigo', self::MODULE_CODE)->value('id');

        if ($permissionId) {
            DB::table('rol_permisos')->where('permiso_id', $permissionId)->delete();
            DB::table('permisos')->where('id', $permissionId)->delete();
        }
    }

    private function createModulePermission(): void
    {
        $now = now();

        DB::table('permisos')->updateOrInsert(
            ['codigo' => self::MODULE_CODE],
            [
                'descripcion' => 'Recepción de pollo vivo',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $permissionId = DB::table('permisos')->where('codigo', self::MODULE_CODE)->value('id');
        $sourcePermissionIds = DB::table('permisos')
            ->whereIn('codigo', ['MODULO_JORNADA_PROVEEDORES', 'RECEPCIONES_CREAR'])
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
};
