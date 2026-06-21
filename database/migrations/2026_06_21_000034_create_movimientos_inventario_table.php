<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets_despacho')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->foreignId('almacen_origen_id')->nullable()->constrained('almacenes')->restrictOnDelete();
            $table->foreignId('almacen_destino_id')->nullable()->constrained('almacenes')->restrictOnDelete();
            $table->foreignId('tercero_origen_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->foreignId('tercero_destino_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->string('estado', 20)->default('BORRADOR');
            $table->timestamp('fecha_hora');
            $table->foreignId('confirmado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('confirmado_at')->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->index(['ticket_id', 'tipo']);
            $table->index(['almacen_origen_id', 'fecha_hora']);
            $table->index(['almacen_destino_id', 'fecha_hora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
