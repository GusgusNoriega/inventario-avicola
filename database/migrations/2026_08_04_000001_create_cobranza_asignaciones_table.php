<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobranza_asignaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('cobranza_id')->constrained('cobranzas')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->decimal('importe_pendiente_antes', 14, 2);
            $table->decimal('importe_asignado', 14, 2);
            $table->decimal('importe_pendiente_despues', 14, 2);
            $table->foreignId('pago_pendiente_anterior_id')->constrained('pagos')->restrictOnDelete();
            $table->foreignId('pago_reversa_id')->constrained('pagos')->restrictOnDelete();
            $table->foreignId('pago_pendiente_nuevo_id')->nullable()->constrained('pagos')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['empresa_id', 'idempotency_key'],
                'cobranza_asignacion_empresa_key_unique'
            );
            $table->index(
                ['cobranza_id', 'created_at'],
                'cobranza_asignacion_cobranza_fecha_index'
            );
        });

        Schema::table('cobranza_detalles', function (Blueprint $table): void {
            $table->foreignId('asignacion_id')
                ->nullable()
                ->after('cobranza_id')
                ->constrained('cobranza_asignaciones')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cobranza_detalles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('asignacion_id');
        });

        Schema::dropIfExists('cobranza_asignaciones');
    }
};
