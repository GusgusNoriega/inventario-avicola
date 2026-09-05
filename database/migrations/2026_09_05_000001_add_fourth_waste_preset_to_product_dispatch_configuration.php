<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->unsignedInteger('merma_preset_4_gramos_unidad')
                ->default(150)
                ->after('merma_preset_3_gramos_unidad');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->dropColumn('merma_preset_4_gramos_unidad');
        });
    }
};
