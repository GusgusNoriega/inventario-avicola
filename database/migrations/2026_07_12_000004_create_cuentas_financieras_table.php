<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_financieras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entidad_financiera_id')->constrained('entidades_financieras')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->string('alias', 100);
            $table->string('banco', 150)->nullable();
            $table->string('numero_cuenta', 80)->nullable();
            $table->string('cci', 80)->nullable();
            $table->char('moneda', 3)->default('PEN');
            $table->string('estado', 20)->default('ACTIVO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['entidad_financiera_id', 'alias'],
                'cuenta_financiera_entidad_alias_unique'
            );
            $table->unique(
                ['entidad_financiera_id', 'numero_cuenta'],
                'cuenta_financiera_entidad_numero_unique'
            );
            $table->index(
                ['entidad_financiera_id', 'estado'],
                'cuenta_financiera_entidad_estado_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_financieras');
    }
};
