<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pago_aplicacion_operaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('pago_id')->constrained('pagos')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->decimal('importe_total', 14, 2);
            $table->json('aplicaciones');
            $table->string('observaciones', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['empresa_id', 'idempotency_key'],
                'pago_aplicacion_operacion_empresa_key_unique'
            );
            $table->index(
                ['pago_id', 'created_at'],
                'pago_aplicacion_operacion_pago_fecha_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_aplicacion_operaciones');
    }
};
