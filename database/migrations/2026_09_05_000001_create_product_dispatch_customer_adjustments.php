<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_despacho_productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->string('tipo', 20);
            $table->foreignId('comprobante_id')->nullable()->unique()->constrained('comprobantes');
            $table->foreignId('pago_id')->nullable()->unique()->constrained('pagos');
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->dateTime('fecha_hora');
            $table->string('estado', 20)->default('REGISTRADO');
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'idempotency_key'], 'ajustes_productos_company_request_unique');
            $table->index(['empresa_id', 'sucursal_id', 'estado'], 'ajustes_productos_company_branch_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_despacho_productos');
    }
};
