<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programacion_recepcion_almacenes', function (Blueprint $table) {
            $table->foreignId('programacion_id')->constrained('programaciones_recepcion')->cascadeOnDelete();
            $table->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['programacion_id', 'almacen_id'], 'programacion_almacen_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programacion_recepcion_almacenes');
    }
};
