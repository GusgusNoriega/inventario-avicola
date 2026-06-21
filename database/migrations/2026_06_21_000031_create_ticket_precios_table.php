<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets_despacho')->cascadeOnDelete();
            $table->foreignId('tipo_pollo_id')->constrained('tipos_pollo')->restrictOnDelete();
            $table->foreignId('precio_historial_id')->constrained('precios_historial')->restrictOnDelete();
            $table->decimal('precio_kg', 12, 4);
            $table->string('origen_precio', 30);
            $table->foreignId('congelado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['ticket_id', 'tipo_pollo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_precios');
    }
};
