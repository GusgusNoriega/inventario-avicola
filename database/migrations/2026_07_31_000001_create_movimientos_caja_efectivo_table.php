<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_caja_efectivo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('pago_id')->unique()->constrained('pagos')->restrictOnDelete();
            $table->string('codigo', 50)->nullable();
            $table->uuid('idempotency_key');
            $table->foreignId('caja_id')->constrained('cuentas_financieras')->restrictOnDelete();
            $table->string('direccion', 20);
            $table->string('contraparte_tipo', 20);
            $table->foreignId('cliente_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->foreignId('otra_caja_id')->nullable()->constrained('cuentas_financieras')->restrictOnDelete();
            $table->string('detalle', 500);
            $table->string('estado', 20)->default('REGISTRADO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'codigo'],
                'movimiento_caja_empresa_codigo_unique',
            );
            $table->unique(
                ['empresa_id', 'idempotency_key'],
                'movimiento_caja_empresa_idempotency_unique',
            );
            $table->index(
                ['empresa_id', 'caja_id', 'estado'],
                'movimiento_caja_empresa_caja_estado_index',
            );
            $table->index(
                ['empresa_id', 'otra_caja_id', 'estado'],
                'movimiento_caja_empresa_otra_caja_estado_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja_efectivo');
    }
};
