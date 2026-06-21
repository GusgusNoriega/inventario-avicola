<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terceros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('tipo_documento', 20);
            $table->string('numero_documento', 30);
            $table->string('nombre_razon_social', 180);
            $table->string('direccion', 250);
            $table->string('telefono', 30)->nullable();
            $table->string('email', 180)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero_documento']);
            $table->index(['empresa_id', 'nombre_razon_social']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terceros');
    }
};
