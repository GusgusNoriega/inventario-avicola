<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteos_diarios_javas_camiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conteo_diario_java_id');
            $table->foreignId('vehiculo_id');
            $table->string('placa_snapshot', 20);
            $table->unsignedInteger('cantidad_javas')->default(0);
            $table->unsignedInteger('cantidad_bandejas')->default(0);
            $table->timestamps();

            $table->foreign('conteo_diario_java_id', 'cdjc_conteo_fk')
                ->references('id')
                ->on('conteos_diarios_javas')
                ->cascadeOnDelete();
            $table->foreign('vehiculo_id', 'cdjc_vehiculo_fk')
                ->references('id')
                ->on('vehiculos')
                ->restrictOnDelete();
            $table->unique(
                ['conteo_diario_java_id', 'vehiculo_id'],
                'cdjc_conteo_vehiculo_unique'
            );
            $table->index('vehiculo_id', 'cdjc_vehiculo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conteos_diarios_javas_camiones');
    }
};
