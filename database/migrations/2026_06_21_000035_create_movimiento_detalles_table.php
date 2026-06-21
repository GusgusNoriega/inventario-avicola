<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimiento_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_id')->constrained('movimientos_inventario')->cascadeOnDelete();
            $table->foreignId('pesada_id')->nullable()->unique()->constrained('pesadas')->restrictOnDelete();
            $table->foreignId('tipo_pollo_id')->constrained('tipos_pollo')->restrictOnDelete();
            $table->integer('cantidad_aves');
            $table->decimal('peso_neto_kg', 12, 3);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['movimiento_id', 'tipo_pollo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimiento_detalles');
    }
};
