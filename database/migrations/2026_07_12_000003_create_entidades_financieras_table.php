<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidades_financieras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->foreignId('proveedor_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->string('tipo_documento', 20)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->string('razon_social', 180);
            $table->string('nombre_comercial', 180)->nullable();
            $table->string('direccion', 250)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('estado', 20)->default('ACTIVO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'numero_documento'],
                'entidad_financiera_empresa_documento_unique'
            );
            $table->index(
                ['empresa_id', 'tipo', 'estado'],
                'entidad_financiera_empresa_tipo_estado_index'
            );
            $table->index(
                ['empresa_id', 'proveedor_id'],
                'entidad_financiera_empresa_proveedor_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entidades_financieras');
    }
};
