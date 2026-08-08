<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobranzas', function (Blueprint $table): void {
            $table->index(
                ['empresa_id', 'cuenta_destino_id', 'estado', 'recibido_en_caja', 'fecha_hora'],
                'cobranza_recepcion_pendiente_fecha_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cobranzas', function (Blueprint $table): void {
            $table->dropIndex('cobranza_recepcion_pendiente_fecha_index');
        });
    }
};
