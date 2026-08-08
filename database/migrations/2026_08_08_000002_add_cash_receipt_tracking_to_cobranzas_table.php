<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobranzas', function (Blueprint $table): void {
            $table->boolean('recibido_en_caja')->nullable()->after('estado');
            $table->timestamp('recepcion_caja_actualizada_at')->nullable()->after('recibido_en_caja');
            $table->foreignId('recepcion_caja_actualizada_por')
                ->nullable()
                ->after('recepcion_caja_actualizada_at')
                ->constrained('usuarios')
                ->nullOnDelete();
            $table->string('recepcion_caja_actualizada_por_nombre', 180)
                ->nullable()
                ->after('recepcion_caja_actualizada_por');
            $table->index(
                ['empresa_id', 'cuenta_destino_id', 'estado', 'fecha_hora'],
                'cobranza_empresa_cuenta_estado_fecha_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cobranzas', function (Blueprint $table): void {
            $table->dropIndex('cobranza_empresa_cuenta_estado_fecha_index');
            $table->dropForeign(['recepcion_caja_actualizada_por']);
            $table->dropColumn([
                'recibido_en_caja',
                'recepcion_caja_actualizada_at',
                'recepcion_caja_actualizada_por',
                'recepcion_caja_actualizada_por_nombre',
            ]);
        });
    }
};
