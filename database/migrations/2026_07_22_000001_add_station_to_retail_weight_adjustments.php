<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ajustes_peso_minorista', function (Blueprint $table): void {
            $table->unsignedTinyInteger('estacion')->default(1)->after('empresa_id');
        });

        Schema::table('ajustes_peso_minorista', function (Blueprint $table): void {
            $table->dropUnique(['empresa_id', 'codigo']);
            $table->unique(['empresa_id', 'estacion', 'codigo'], 'ajuste_minorista_empresa_estacion_codigo_unique');
            $table->index(['empresa_id', 'estacion', 'estado', 'predeterminado'], 'ajuste_minorista_estacion_default_index');
        });

        DB::table('ajustes_peso_minorista')
            ->where('estacion', 1)
            ->orderBy('id')
            ->get()
            ->each(function (object $adjustment): void {
                DB::table('ajustes_peso_minorista')->insertOrIgnore([
                    'empresa_id' => $adjustment->empresa_id,
                    'estacion' => 2,
                    'codigo' => $adjustment->codigo,
                    'nombre' => $adjustment->nombre,
                    'sexo' => $adjustment->sexo,
                    'presentacion' => $adjustment->presentacion,
                    'gramos_adicionales' => $adjustment->gramos_adicionales,
                    'predeterminado' => $adjustment->predeterminado,
                    'estado' => $adjustment->estado,
                    'created_at' => $adjustment->created_at,
                    'updated_at' => $adjustment->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('ajustes_peso_minorista')
            ->where('estacion', '!=', 1)
            ->orderBy('id')
            ->get()
            ->each(function (object $adjustment): void {
                $stationOneId = DB::table('ajustes_peso_minorista')
                    ->where('empresa_id', $adjustment->empresa_id)
                    ->where('estacion', 1)
                    ->where('codigo', $adjustment->codigo)
                    ->value('id');

                if (! $stationOneId) {
                    $stationOneId = DB::table('ajustes_peso_minorista')->insertGetId([
                        'empresa_id' => $adjustment->empresa_id,
                        'estacion' => 1,
                        'codigo' => $adjustment->codigo,
                        'nombre' => $adjustment->nombre,
                        'sexo' => $adjustment->sexo,
                        'presentacion' => $adjustment->presentacion,
                        'gramos_adicionales' => $adjustment->gramos_adicionales,
                        'predeterminado' => $adjustment->predeterminado,
                        'estado' => $adjustment->estado,
                        'created_at' => $adjustment->created_at,
                        'updated_at' => $adjustment->updated_at,
                    ]);
                }

                DB::table('pesadas')
                    ->where('ajuste_peso_minorista_id', $adjustment->id)
                    ->update(['ajuste_peso_minorista_id' => $stationOneId]);
            });

        DB::table('ajustes_peso_minorista')->where('estacion', '!=', 1)->delete();

        Schema::table('ajustes_peso_minorista', function (Blueprint $table): void {
            $table->dropIndex('ajuste_minorista_estacion_default_index');
            $table->dropUnique('ajuste_minorista_empresa_estacion_codigo_unique');
            $table->dropColumn('estacion');
            $table->unique(['empresa_id', 'codigo']);
        });
    }
};
