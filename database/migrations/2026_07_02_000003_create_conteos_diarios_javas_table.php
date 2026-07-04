<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteos_diarios_javas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('jornada_id')->unique()->constrained('jornadas_operativas')->restrictOnDelete();
            $table->unsignedInteger('cantidad_en_empresa');
            $table->unsignedInteger('cantidad_esperada');
            $table->integer('diferencia');
            $table->timestamp('contado_at');
            $table->foreignId('contado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'contado_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conteos_diarios_javas');
    }
};
