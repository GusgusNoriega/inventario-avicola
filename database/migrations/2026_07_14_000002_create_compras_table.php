<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('terceros')->restrictOnDelete();
            $table->foreignId('comprobante_id')->nullable()->unique()->constrained('comprobantes')->restrictOnDelete();
            $table->foreignId('pago_inicial_id')->nullable()->unique()->constrained('pagos')->restrictOnDelete();
            $table->string('codigo', 50)->nullable();
            $table->string('idempotency_key', 100);
            $table->string('tipo_documento', 30);
            $table->string('numero_documento', 80)->nullable();
            $table->date('fecha_compra');
            $table->date('fecha_vencimiento')->nullable();
            $table->string('condicion', 20);
            $table->char('moneda', 3)->default('PEN');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('impuesto', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('estado', 20)->default('REGISTRADA');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 250)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo'], 'compra_empresa_codigo_unique');
            $table->unique(
                ['empresa_id', 'idempotency_key'],
                'compra_empresa_idempotency_unique'
            );
            $table->unique(
                ['empresa_id', 'proveedor_id', 'tipo_documento', 'numero_documento'],
                'compra_proveedor_documento_unique'
            );
            $table->index(
                ['empresa_id', 'estado', 'fecha_compra'],
                'compra_empresa_estado_fecha_index'
            );
            $table->index(
                ['proveedor_id', 'condicion', 'fecha_compra'],
                'compra_proveedor_condicion_fecha_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
