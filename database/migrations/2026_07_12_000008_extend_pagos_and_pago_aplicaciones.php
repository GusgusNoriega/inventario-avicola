<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->foreignId('tercero_id')->nullable()->change();
            $table->string('codigo', 50)->nullable()->after('empresa_id');
            $table->string('tipo', 40)->nullable()->after('tercero_id');
            $table->foreignId('cliente_id')->nullable()->after('tipo')->constrained('terceros')->restrictOnDelete();
            $table->foreignId('proveedor_id')->nullable()->after('cliente_id')->constrained('terceros')->restrictOnDelete();
            $table->foreignId('cuenta_origen_id')->nullable()->after('proveedor_id')->constrained('cuentas_financieras')->restrictOnDelete();
            $table->foreignId('cuenta_destino_id')->nullable()->after('cuenta_origen_id')->constrained('cuentas_financieras')->restrictOnDelete();
            $table->foreignId('metodo_pago_id')->nullable()->after('cuenta_destino_id')->constrained('metodos_pago')->restrictOnDelete();
            $table->string('estado', 20)->default('REGISTRADO')->after('importe');
            $table->string('idempotency_key', 100)->nullable()->after('estado');
            $table->foreignId('reversa_de_pago_id')->nullable()->unique()->constrained('pagos')->restrictOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 250)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['empresa_id', 'codigo'], 'pago_empresa_codigo_unique');
            $table->unique(
                ['empresa_id', 'idempotency_key'],
                'pago_empresa_idempotency_unique'
            );
            $table->index(
                ['empresa_id', 'estado', 'fecha_hora'],
                'pago_empresa_estado_fecha_index'
            );
            $table->index(['cliente_id', 'fecha_hora'], 'pago_cliente_fecha_index');
            $table->index(['proveedor_id', 'fecha_hora'], 'pago_proveedor_fecha_index');
        });

        Schema::table('pago_aplicaciones', function (Blueprint $table) {
            $table->string('lado', 10)->default('CXC')->after('comprobante_id');
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();

            $table->index(
                ['comprobante_id', 'lado'],
                'pago_aplicacion_comprobante_lado_index'
            );
        });
    }

    public function down(): void
    {
        // El esquema original no admite movimientos sin tercero (saldos,
        // ajustes, transferencias y cobros minoristas anónimos).
        $anonymousPaymentIds = DB::table('pagos')
            ->whereNull('tercero_id')
            ->pluck('id');
        if ($anonymousPaymentIds->isNotEmpty()) {
            DB::table('pago_aplicaciones')
                ->whereIn('pago_id', $anonymousPaymentIds)
                ->delete();
            DB::table('pagos')
                ->whereIn('reversa_de_pago_id', $anonymousPaymentIds)
                ->delete();
            DB::table('pagos')
                ->whereIn('id', $anonymousPaymentIds)
                ->delete();
        }

        Schema::table('pago_aplicaciones', function (Blueprint $table) {
            $table->dropIndex('pago_aplicacion_comprobante_lado_index');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('lado');
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->dropIndex('pago_proveedor_fecha_index');
            $table->dropIndex('pago_cliente_fecha_index');
            $table->dropIndex('pago_empresa_estado_fecha_index');
            $table->dropUnique('pago_empresa_idempotency_unique');
            $table->dropUnique('pago_empresa_codigo_unique');
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropForeign(['reversa_de_pago_id']);
            $table->dropUnique('pagos_reversa_de_pago_id_unique');
            $table->dropConstrainedForeignId('metodo_pago_id');
            $table->dropConstrainedForeignId('cuenta_destino_id');
            $table->dropConstrainedForeignId('cuenta_origen_id');
            $table->dropConstrainedForeignId('proveedor_id');
            $table->dropConstrainedForeignId('cliente_id');
            $table->dropColumn([
                'codigo',
                'tipo',
                'estado',
                'idempotency_key',
                'reversa_de_pago_id',
                'anulada_at',
                'motivo_anulacion',
                'updated_at',
            ]);
            $table->foreignId('tercero_id')->nullable(false)->change();
        });
    }
};
