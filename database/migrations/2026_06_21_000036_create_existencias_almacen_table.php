<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existencias_almacen', function (Blueprint $table) {
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnDelete();
            $table->foreignId('tipo_pollo_id')->constrained('tipos_pollo')->restrictOnDelete();
            $table->integer('cantidad_aves')->default(0);
            $table->decimal('peso_neto_kg', 14, 3)->default(0);
            $table->timestamp('updated_at')->useCurrent();
            $table->primary(['almacen_id', 'tipo_pollo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('existencias_almacen');
    }
};
