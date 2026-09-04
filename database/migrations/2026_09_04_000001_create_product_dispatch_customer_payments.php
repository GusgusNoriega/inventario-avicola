<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_despacho_productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('pago_id')->unique()->constrained('pagos')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('estado', 20)->default('REGISTRADO');
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'idempotency_key'], 'pago_producto_empresa_key_unique');
            $table->index(['empresa_id', 'sucursal_id', 'estado'], 'pago_producto_empresa_sucursal_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_despacho_productos');
    }
};
