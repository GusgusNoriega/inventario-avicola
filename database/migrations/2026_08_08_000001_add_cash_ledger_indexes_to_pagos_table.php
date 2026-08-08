<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table): void {
            $table->index(
                ['empresa_id', 'cuenta_origen_id', 'estado', 'reversa_de_pago_id', 'fecha_hora'],
                'pago_empresa_origen_estado_reversa_fecha_index',
            );
            $table->index(
                ['empresa_id', 'cuenta_destino_id', 'estado', 'reversa_de_pago_id', 'fecha_hora'],
                'pago_empresa_destino_estado_reversa_fecha_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table): void {
            $table->dropIndex('pago_empresa_origen_estado_reversa_fecha_index');
            $table->dropIndex('pago_empresa_destino_estado_reversa_fecha_index');
        });
    }
};
