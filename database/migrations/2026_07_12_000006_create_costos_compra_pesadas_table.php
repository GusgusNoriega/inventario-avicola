<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costos_compra_pesadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesada_id')->unique()->constrained('pesadas')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('terceros')->restrictOnDelete();
            $table->foreignId('precio_historial_id')->nullable()->constrained('precios_historial')->nullOnDelete();
            $table->decimal('precio_kg', 12, 4);
            $table->decimal('peso_kg', 12, 3);
            $table->decimal('importe', 14, 2);
            $table->string('estado', 20)->default('ACTIVO');
            $table->string('origen', 20)->default('MANUAL');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->index(
                ['proveedor_id', 'estado', 'created_at'],
                'costo_compra_proveedor_estado_fecha_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('costos_compra_pesadas');
    }
};
