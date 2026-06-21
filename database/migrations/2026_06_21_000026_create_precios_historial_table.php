<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precios_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_precio_id')->constrained('listas_precios')->restrictOnDelete();
            $table->foreignId('tipo_pollo_id')->constrained('tipos_pollo')->restrictOnDelete();
            $table->decimal('precio_kg', 12, 4);
            $table->timestamp('vigente_desde');
            $table->timestamp('vigente_hasta')->nullable();
            $table->string('motivo_cambio', 250)->nullable();
            $table->foreignId('reemplaza_precio_id')->nullable()->constrained('precios_historial')->nullOnDelete();
            $table->foreignId('registrado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['lista_precio_id', 'tipo_pollo_id', 'vigente_desde'], 'precio_lista_tipo_vigencia_unique');
            $table->index(['lista_precio_id', 'tipo_pollo_id', 'vigente_hasta'], 'precio_lista_tipo_fin_index');
            $table->index(['registrado_por', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precios_historial');
    }
};
