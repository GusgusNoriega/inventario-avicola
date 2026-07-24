<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('pago_id')->unique()->constrained('pagos')->restrictOnDelete();
            $table->string('codigo', 50)->nullable();
            $table->uuid('idempotency_key');
            $table->string('categoria', 40);
            $table->string('concepto', 250);
            $table->string('destino', 250);
            $table->string('numero_documento', 100)->nullable();
            $table->string('estado', 20)->default('REGISTRADO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 250)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo'], 'gasto_empresa_codigo_unique');
            $table->unique(
                ['empresa_id', 'idempotency_key'],
                'gasto_empresa_idempotency_unique',
            );
            $table->index(
                ['empresa_id', 'estado', 'created_at'],
                'gasto_empresa_estado_fecha_index',
            );
            $table->index(
                ['empresa_id', 'categoria'],
                'gasto_empresa_categoria_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos_empresa');
    }
};
