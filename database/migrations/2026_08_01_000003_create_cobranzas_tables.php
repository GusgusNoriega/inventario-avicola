<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobradores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 180);
            $table->string('estado', 20)->default('ACTIVO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre'], 'cobrador_empresa_nombre_unique');
            $table->index(['empresa_id', 'estado', 'nombre'], 'cobrador_empresa_estado_nombre_index');
        });

        Schema::create('cobranzas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('cobrador_id')->constrained('cobradores')->restrictOnDelete();
            $table->string('cobrador_nombre_snapshot', 180);
            $table->string('codigo', 50)->nullable();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->foreignId('cuenta_destino_id')->constrained('cuentas_financieras')->restrictOnDelete();
            $table->foreignId('proveedor_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago')->restrictOnDelete();
            $table->timestamp('fecha_hora');
            $table->string('referencia', 100);
            $table->char('moneda', 3);
            $table->decimal('importe_total', 14, 2);
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('REGISTRADO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 250)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo'], 'cobranza_empresa_codigo_unique');
            $table->unique(['empresa_id', 'idempotency_key'], 'cobranza_empresa_idempotency_unique');
            $table->index(['empresa_id', 'estado', 'fecha_hora'], 'cobranza_empresa_estado_fecha_index');
            $table->index(['empresa_id', 'cobrador_id', 'fecha_hora'], 'cobranza_empresa_cobrador_fecha_index');
            $table->unique(
                ['empresa_id', 'cuenta_destino_id', 'referencia'],
                'cobranza_empresa_cuenta_referencia_unique'
            );
        });

        Schema::create('cobranza_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cobranza_id')->constrained('cobranzas')->restrictOnDelete();
            $table->foreignId('pago_id')->unique()->constrained('pagos')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('terceros')->restrictOnDelete();
            $table->date('fecha_recepcion');
            $table->string('medio_recepcion', 20)->default('EFECTIVO');
            $table->decimal('importe', 14, 2);
            $table->unsignedSmallInteger('orden');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['cobranza_id', 'orden'], 'cobranza_detalle_lote_orden_unique');
            $table->index(['cobranza_id', 'cliente_id'], 'cobranza_detalle_lote_cliente_index');
            $table->index(['cliente_id', 'fecha_recepcion'], 'cobranza_detalle_cliente_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobranza_detalles');
        Schema::dropIfExists('cobranzas');
        Schema::dropIfExists('cobradores');
    }
};
