<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listas_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('tercero_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->string('codigo', 50);
            $table->string('nombre', 120);
            $table->string('operacion', 20);
            $table->string('estado', 20)->default('ACTIVO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'codigo']);
            $table->index(['tercero_id', 'operacion', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listas_precios');
    }
};
