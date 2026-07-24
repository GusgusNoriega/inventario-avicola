<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_despacho_minorista', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->unsignedTinyInteger('estacion');
            $table->foreignId('metodo_pago_id')
                ->nullable()
                ->constrained('metodos_pago')
                ->nullOnDelete();
            $table->foreignId('cuenta_destino_id')
                ->nullable()
                ->constrained('cuentas_financieras')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'sucursal_id', 'estacion'],
                'config_minorista_empresa_sucursal_estacion_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_despacho_minorista');
    }
};
