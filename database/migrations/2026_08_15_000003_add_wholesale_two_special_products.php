<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRODUCT_CODES = [
        'GALLINA_ROJA' => 'Gallina roja',
        'GALLINA_DOBLE' => 'Gallina doble',
        'OTROS' => 'Otros',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('tipos_pollo')->insertOrIgnore(
            collect(self::PRODUCT_CODES)
                ->map(fn (string $name, string $code): array => [
                    'codigo' => $code,
                    'nombre' => $name,
                    'permite_despacho' => true,
                    'precio_fuente_tipo_pollo_id' => null,
                    'estado' => 'ACTIVO',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all()
        );

        Schema::table('ticket_precios', function (Blueprint $table): void {
            $table->unsignedBigInteger('precio_historial_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $specialTypeIds = DB::table('tipos_pollo')
            ->whereIn('codigo', array_keys(self::PRODUCT_CODES))
            ->pluck('id')
            ->all();

        $referencingColumns = [
            'precios_historial' => 'tipo_pollo_id',
            'ticket_precios' => 'tipo_pollo_id',
            'pesadas' => 'tipo_pollo_id',
            'movimiento_detalles' => 'tipo_pollo_id',
            'existencias_almacen' => 'tipo_pollo_id',
            'comprobante_detalles' => 'tipo_pollo_id',
            'compra_detalles' => 'tipo_pollo_id',
            'tipos_pollo' => 'precio_fuente_tipo_pollo_id',
        ];

        if (
            $specialTypeIds !== []
            && collect($referencingColumns)->contains(
                fn (string $column, string $table): bool => DB::table($table)
                    ->whereIn($column, $specialTypeIds)
                    ->exists()
            )
        ) {
            throw new RuntimeException(
                'No se puede revertir mientras existan movimientos o historiales de los productos especiales.'
            );
        }

        if (DB::table('ticket_precios')->whereNull('precio_historial_id')->exists()) {
            throw new RuntimeException(
                'No se puede revertir mientras existan precios manuales sin historial.'
            );
        }

        Schema::table('ticket_precios', function (Blueprint $table): void {
            $table->unsignedBigInteger('precio_historial_id')->nullable(false)->change();
        });

        DB::table('tipos_pollo')
            ->whereIn('codigo', array_keys(self::PRODUCT_CODES))
            ->delete();
    }
};
