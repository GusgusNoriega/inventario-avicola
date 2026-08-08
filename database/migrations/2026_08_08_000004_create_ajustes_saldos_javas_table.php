<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_saldos_javas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('jornada_id')->constrained('jornadas_operativas')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('terceros')->restrictOnDelete();
            $table->integer('saldo_anterior_javas');
            $table->unsignedInteger('saldo_nuevo_javas');
            $table->integer('diferencia_javas');
            $table->integer('saldo_anterior_bandejas');
            $table->unsignedInteger('saldo_nuevo_bandejas');
            $table->integer('diferencia_bandejas');
            $table->string('motivo', 500);
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->index(
                ['empresa_id', 'cliente_id', 'created_at'],
                'ajustes_saldos_javas_empresa_cliente_fecha_index'
            );
            $table->index(
                ['sucursal_id', 'jornada_id', 'created_at'],
                'ajustes_saldos_javas_sucursal_jornada_fecha_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_saldos_javas');
    }
};
