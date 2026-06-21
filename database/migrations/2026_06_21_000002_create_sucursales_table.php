<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('codigo', 30);
            $table->string('nombre', 120);
            $table->string('direccion', 250)->nullable();
            $table->string('zona_horaria', 60)->default('America/Lima');
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
