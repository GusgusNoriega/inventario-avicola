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
            $table->unsignedBigInteger('almacen_columna_3_id')->nullable()->after('almacen_columna_2_id');
            $table->unsignedBigInteger('almacen_columna_4_id')->nullable()->after('almacen_columna_3_id');

            $table->foreign('almacen_columna_3_id', 'cfg_recepcion_almacen_3_fk')
                ->references('id')->on('almacenes')->restrictOnDelete();
            $table->foreign('almacen_columna_4_id', 'cfg_recepcion_almacen_4_fk')
                ->references('id')->on('almacenes')->restrictOnDelete();
        });

        // Se conservan intactas las columnas de todas las pesadas históricas.
        // El servicio presenta los antiguos despachos 3/4 como 5/6 usando su
        // tipo de destino, sin reescribir ni perder el significado original.
        DB::table('configuraciones_recepcion_pollo_vivo')->update([
            'almacen_columna_3_id' => DB::raw('almacen_columna_1_id'),
            'almacen_columna_4_id' => DB::raw('almacen_columna_2_id'),
        ]);
    }

    public function down(): void
    {
        // El código anterior solo entiende cuatro columnas. Estos estados son
        // inequívocamente del diseño nuevo y se llevan a su equivalente viejo
        // sin cambiar propietario, sexo ni el destino real de la pesada.
        DB::table('pesadas_recepcion_pollo_vivo')
            ->where('destino_tipo', 'CLIENTE')
            ->where('columna', 5)
            ->update(['columna' => 3]);
        DB::table('pesadas_recepcion_pollo_vivo')
            ->where('destino_tipo', 'CLIENTE')
            ->where('columna', 6)
            ->update(['columna' => 4]);
        DB::table('pesadas_recepcion_pollo_vivo')
            ->where('destino_tipo', 'ALMACEN')
            ->where('columna', 3)
            ->update(['columna' => 1]);
        DB::table('pesadas_recepcion_pollo_vivo')
            ->where('destino_tipo', 'ALMACEN')
            ->where('columna', 4)
            ->update(['columna' => 2]);

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('configuraciones_recepcion_pollo_vivo', function (Blueprint $table) use ($isSqlite): void {
            $table->dropForeign($isSqlite ? ['almacen_columna_3_id'] : 'cfg_recepcion_almacen_3_fk');
            $table->dropForeign($isSqlite ? ['almacen_columna_4_id'] : 'cfg_recepcion_almacen_4_fk');
            $table->dropColumn(['almacen_columna_3_id', 'almacen_columna_4_id']);
        });
    }
};
