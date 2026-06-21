<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturas_balanza', function (Blueprint $table) {
            $table->id();
            $table->foreignId('balanza_id')->constrained('balanzas')->restrictOnDelete();
            $table->decimal('peso_kg', 12, 3);
            $table->string('trama_cruda', 500)->nullable();
            $table->string('modo_conexion', 20)->nullable();
            $table->string('dispositivo', 180)->nullable();
            $table->timestamp('capturada_at');
            $table->foreignId('capturada_por')->constrained('usuarios')->restrictOnDelete();
            $table->index(['balanza_id', 'capturada_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturas_balanza');
    }
};
