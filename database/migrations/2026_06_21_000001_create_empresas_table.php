<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('razon_social', 180);
            $table->string('nombre_comercial', 180)->nullable();
            $table->string('ruc', 20)->unique();
            $table->char('pais_codigo', 2)->default('PE');
            $table->char('moneda', 3)->default('PEN');
            $table->string('zona_horaria', 60)->default('America/Lima');
            $table->time('hora_corte_operativo')->default('21:00:00');
            $table->boolean('sunat_habilitado')->default(false);
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
