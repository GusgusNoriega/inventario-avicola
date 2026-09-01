<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->boolean('productos_rapidos_configurados')
                ->default(false)
                ->after('merma_preset_3_gramos_unidad');

            for ($position = 1; $position <= 4; $position++) {
                $table->foreignId("producto_rapido_{$position}_id")
                    ->nullable()
                    ->after($position === 1
                        ? 'productos_rapidos_configurados'
                        : 'producto_rapido_'.($position - 1).'_id')
                    ->constrained(
                        'productos_despacho',
                        'id',
                        "fk_cdpr_producto_rapido_{$position}",
                    )
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            for ($position = 1; $position <= 4; $position++) {
                $table->dropForeign("fk_cdpr_producto_rapido_{$position}");
            }

            $table->dropColumn([
                'productos_rapidos_configurados',
                'producto_rapido_1_id',
                'producto_rapido_2_id',
                'producto_rapido_3_id',
                'producto_rapido_4_id',
            ]);
        });
    }
};
