<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programaciones_recepcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->date('fecha_operativa');
            $table->string('estado', 20)->default('BORRADOR');
            $table->text('observaciones')->nullable();
            $table->foreignId('publicada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('publicada_at')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['sucursal_id', 'fecha_operativa']);
            $table->index(['sucursal_id', 'estado', 'fecha_operativa'], 'programacion_sucursal_estado_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programaciones_recepcion');
    }
};
