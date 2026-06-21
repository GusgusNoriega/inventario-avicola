<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jornadas_operativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->date('fecha_operativa');
            $table->string('estado', 20)->default('ABIERTA');
            $table->foreignId('abierta_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('inicio_at');
            $table->timestamp('cierre_programado_at');
            $table->foreignId('cerrada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->unique(['sucursal_id', 'fecha_operativa']);
            $table->index(['sucursal_id', 'estado', 'cierre_programado_at'], 'jornada_sucursal_estado_cierre_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jornadas_operativas');
    }
};
