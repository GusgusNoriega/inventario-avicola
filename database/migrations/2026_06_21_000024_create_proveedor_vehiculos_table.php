<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_vehiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('terceros')->restrictOnDelete();
            $table->foreignId('vehiculo_id')->constrained('vehiculos')->restrictOnDelete();
            $table->string('alias', 100)->nullable();
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->string('estado', 20)->default('ACTIVO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['proveedor_id', 'vehiculo_id', 'vigente_desde'], 'proveedor_vehiculo_vigencia_unique');
            $table->index(['proveedor_id', 'estado']);
            $table->index(['vehiculo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_vehiculos');
    }
};
