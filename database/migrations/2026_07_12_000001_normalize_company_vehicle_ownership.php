<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            $table->boolean('es_propio')->default(true)->change();
        });

        DB::table('vehiculos')->update([
            'es_propio' => true,
            'tercero_propietario_id' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            $table->boolean('es_propio')->default(false)->change();
        });

        // No se reconstruye una propiedad de terceros: las asignaciones historicas
        // permanecen preservadas en proveedor_vehiculos.
    }
};
