<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_pollo', function (Blueprint $table) {
            $table->foreignId('precio_fuente_tipo_pollo_id')
                ->nullable()
                ->after('permite_despacho');
        });
        $liveTypeId = DB::table('tipos_pollo')
            ->where('codigo', 'POLLO_VIVO')
            ->value('id');

        DB::table('tipos_pollo')->updateOrInsert(
            ['codigo' => 'POLLO_MUERTO'],
            [
                'nombre' => 'Pollo muerto',
                'permite_despacho' => true,
                'precio_fuente_tipo_pollo_id' => $liveTypeId,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('tipos_pollo')
            ->where('codigo', 'POLLO_MUERTO')
            ->update([
                'precio_fuente_tipo_pollo_id' => null,
                'permite_despacho' => false,
                'estado' => 'INACTIVO',
                'updated_at' => now(),
            ]);

        Schema::table('tipos_pollo', function (Blueprint $table) {
            $table->dropColumn('precio_fuente_tipo_pollo_id');
        });
    }
};
