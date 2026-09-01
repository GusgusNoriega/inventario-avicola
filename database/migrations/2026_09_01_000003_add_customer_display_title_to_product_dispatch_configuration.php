<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->string('titulo_pantalla_cliente', 120)
                ->nullable()
                ->after('producto_rapido_4_id');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->dropColumn('titulo_pantalla_cliente');
        });
    }
};
