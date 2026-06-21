<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobante_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $table->foreignId('tipo_pollo_id')->nullable()->constrained('tipos_pollo')->restrictOnDelete();
            $table->string('descripcion', 250);
            $table->integer('cantidad_aves')->nullable();
            $table->decimal('peso_neto_kg', 12, 3)->nullable();
            $table->decimal('precio_kg', 12, 4)->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_detalles');
    }
};
