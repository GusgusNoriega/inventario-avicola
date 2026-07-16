<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conteos_diarios_javas', function (Blueprint $table): void {
            $table->unsignedInteger('cantidad_en_local')->nullable()->after('cantidad_en_empresa');
            $table->unsignedInteger('cantidad_en_local_bandejas')->nullable()->after('cantidad_en_empresa_bandejas');
            $table->unsignedInteger('cantidad_clientes_externos')->nullable()->after('cantidad_esperada_bandejas');
            $table->unsignedInteger('cantidad_clientes_externos_bandejas')->nullable()->after('cantidad_clientes_externos');
            $table->unsignedInteger('cantidad_clientes_internos')->nullable()->after('cantidad_clientes_externos_bandejas');
            $table->unsignedInteger('cantidad_clientes_internos_bandejas')->nullable()->after('cantidad_clientes_internos');
            $table->unsignedInteger('cantidad_total_inventario')->nullable()->after('cantidad_clientes_internos_bandejas');
            $table->unsignedInteger('cantidad_total_inventario_bandejas')->nullable()->after('cantidad_total_inventario');
        });
    }

    public function down(): void
    {
        Schema::table('conteos_diarios_javas', function (Blueprint $table): void {
            $table->dropColumn([
                'cantidad_en_local',
                'cantidad_en_local_bandejas',
                'cantidad_clientes_externos',
                'cantidad_clientes_externos_bandejas',
                'cantidad_clientes_internos',
                'cantidad_clientes_internos_bandejas',
                'cantidad_total_inventario',
                'cantidad_total_inventario_bandejas',
            ]);
        });
    }
};
