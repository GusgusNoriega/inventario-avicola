<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('almacenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->string('codigo', 30);
            $table->string('nombre', 120);
            $table->string('direccion', 250)->nullable();
            $table->boolean('permite_stock_negativo')->default(false);
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
            $table->unique(['sucursal_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacenes');
    }
};
