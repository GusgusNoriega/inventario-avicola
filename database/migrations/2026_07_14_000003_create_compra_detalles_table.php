<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('tipo_pollo_id')->nullable()->constrained('tipos_pollo')->restrictOnDelete();
            $table->string('descripcion', 250);
            $table->unsignedInteger('cantidad_aves')->nullable();
            $table->decimal('peso_kg', 12, 3);
            $table->decimal('precio_kg', 12, 4);
            $table->decimal('subtotal', 14, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['compra_id', 'tipo_pollo_id'], 'compra_detalle_compra_tipo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_detalles');
    }
};
