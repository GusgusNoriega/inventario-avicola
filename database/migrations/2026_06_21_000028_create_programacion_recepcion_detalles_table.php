<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programacion_recepcion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programacion_id')->constrained('programaciones_recepcion')->cascadeOnDelete();
            $table->foreignId('proveedor_vehiculo_id')->constrained('proveedor_vehiculos')->restrictOnDelete();
            $table->foreignId('conductor_id')->nullable()->constrained('conductores')->nullOnDelete();
            $table->string('conductor_nombre_snapshot', 150)->nullable();
            $table->string('conductor_dni_snapshot', 20)->nullable();
            $table->unsignedInteger('numero_visita')->default(1);
            $table->unsignedInteger('orden_llegada')->nullable();
            $table->time('hora_estimada')->nullable();
            $table->string('estado', 20)->default('PENDIENTE');
            $table->text('observaciones')->nullable();
            $table->timestamp('llegada_at')->nullable();
            $table->timestamp('recepcion_iniciada_at')->nullable();
            $table->timestamp('completada_at')->nullable();
            $table->foreignId('estado_actualizado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['programacion_id', 'proveedor_vehiculo_id', 'numero_visita'], 'programacion_proveedor_vehiculo_visita_unique');
            $table->index(['programacion_id', 'estado', 'orden_llegada'], 'programacion_estado_orden_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programacion_recepcion_detalles');
    }
};
