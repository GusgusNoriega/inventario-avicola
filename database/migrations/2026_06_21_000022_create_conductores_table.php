<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 150)->nullable();
            $table->string('dni', 20)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
            $table->unique(['empresa_id', 'dni']);
            $table->index(['empresa_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductores');
    }
};
