<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->foreignId('vehiculo_entrega_id')
                ->nullable()
                ->after('almacen_destino_id')
                ->constrained('vehiculos')
                ->restrictOnDelete();
            $table->foreignId('conductor_entrega_id')
                ->nullable()
                ->after('vehiculo_entrega_id')
                ->constrained('conductores')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conductor_entrega_id');
            $table->dropConstrainedForeignId('vehiculo_entrega_id');
        });
    }
};
