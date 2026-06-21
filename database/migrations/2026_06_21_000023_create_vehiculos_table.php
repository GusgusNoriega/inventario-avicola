<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('placa', 20);
            $table->foreignId('tercero_propietario_id')->nullable()->constrained('terceros')->nullOnDelete();
            $table->foreignId('conductor_habitual_id')->nullable()->constrained('conductores')->nullOnDelete();
            $table->string('marca', 80)->nullable();
            $table->string('modelo', 80)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('descripcion', 150)->nullable();
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
            $table->unique(['empresa_id', 'placa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
    }
};
