<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas', 'id', 'fk_cdpr_empresa')
                ->restrictOnDelete();
            $table->foreignId('sucursal_id')
                ->constrained('sucursales', 'id', 'fk_cdpr_sucursal')
                ->restrictOnDelete();
            $table->unsignedInteger('merma_preset_1_gramos_unidad')->default(0);
            $table->unsignedInteger('merma_preset_2_gramos_unidad')->default(50);
            $table->unsignedInteger('merma_preset_3_gramos_unidad')->default(100);
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'sucursal_id'],
                'config_despacho_producto_empresa_sucursal_unique',
            );
        });

        Schema::table('pesadas_despacho_productos', function (Blueprint $table): void {
            $table->unsignedInteger('merma_aplicada_gramos_unidad')->default(0);
            $table->unsignedBigInteger('tara_gramos')->default(0);
        });

        Schema::table('tickets_despacho_productos', function (Blueprint $table): void {
            $table->unsignedBigInteger('tara_total_gramos')->default(0);
        });

        $this->backfillAppliedWastePerUnit();
    }

    public function down(): void
    {
        Schema::table('tickets_despacho_productos', function (Blueprint $table): void {
            $table->dropColumn('tara_total_gramos');
        });

        Schema::table('pesadas_despacho_productos', function (Blueprint $table): void {
            $table->dropColumn([
                'merma_aplicada_gramos_unidad',
                'tara_gramos',
            ]);
        });

        Schema::dropIfExists('configuraciones_despacho_productos');
    }

    private function backfillAppliedWastePerUnit(): void
    {
        DB::table('pesadas_despacho_productos')
            ->select(['id', 'cantidad', 'merma_total_gramos'])
            ->where('cantidad', '>', 0)
            ->chunkById(500, function ($weighings): void {
                foreach ($weighings as $weighing) {
                    $quantity = (int) $weighing->cantidad;

                    if ($quantity <= 0) {
                        continue;
                    }

                    DB::table('pesadas_despacho_productos')
                        ->where('id', $weighing->id)
                        ->update([
                            'merma_aplicada_gramos_unidad' => (int) round(
                                (int) $weighing->merma_total_gramos / $quantity,
                            ),
                        ]);
                }
            });
    }
};
