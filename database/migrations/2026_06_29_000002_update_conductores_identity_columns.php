<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conductores', function (Blueprint $table): void {
            $table->renameColumn('nombre', 'nombre_completo');
            $table->renameColumn('dni', 'numero_documento');
            $table->string('tipo_documento', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conductores', function (Blueprint $table): void {
            $table->dropColumn('tipo_documento');
            $table->renameColumn('numero_documento', 'dni');
            $table->renameColumn('nombre_completo', 'nombre');
        });
    }
};
