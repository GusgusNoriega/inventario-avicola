<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->string('titulo_ticket_despacho', 180)
                ->nullable()
                ->after('titulo_pantalla_cliente');
        });

        Schema::table('tickets_despacho_productos', function (Blueprint $table): void {
            $table->unsignedTinyInteger('numero_lista')
                ->nullable()
                ->after('referencia_externa');
            $table->string('titulo_ticket_snapshot', 180)
                ->nullable()
                ->after('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho_productos', function (Blueprint $table): void {
            $table->dropColumn([
                'numero_lista',
                'titulo_ticket_snapshot',
            ]);
        });

        Schema::table('configuraciones_despacho_productos', function (Blueprint $table): void {
            $table->dropColumn('titulo_ticket_despacho');
        });
    }
};
