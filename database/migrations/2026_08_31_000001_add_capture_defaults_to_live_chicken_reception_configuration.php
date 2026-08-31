<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_recepcion_pollo_vivo', function (Blueprint $table): void {
            $table->unsignedSmallInteger('aves_por_java_macho')->default(7);
            $table->unsignedSmallInteger('aves_por_java_hembra')->default(9);
            $table->unsignedSmallInteger('cantidad_javas_predeterminada')->default(5);
            $table->unsignedBigInteger('tipo_java_predeterminado_id')->nullable();
            $table->foreign('tipo_java_predeterminado_id', 'cfg_recepcion_tipo_java_pred_fk')
                ->references('id')->on('tipos_java')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('configuraciones_recepcion_pollo_vivo', function (Blueprint $table) use ($isSqlite): void {
            $table->dropForeign($isSqlite ? ['tipo_java_predeterminado_id'] : 'cfg_recepcion_tipo_java_pred_fk');
            $table->dropColumn([
                'aves_por_java_macho',
                'aves_por_java_hembra',
                'cantidad_javas_predeterminada',
                'tipo_java_predeterminado_id',
            ]);
        });
    }
};
