<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('tercero_id')->constrained('terceros')->restrictOnDelete();
            $table->string('operacion', 20);
            $table->string('tipo_documento', 30)->default('INTERNO');
            $table->string('codigo', 50);
            $table->string('origen_codigo', 20);
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento')->nullable();
            $table->char('moneda', 3)->default('PEN');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('impuesto', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('saldo_pendiente', 14, 2);
            $table->string('estado', 20)->default('BORRADOR');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'codigo']);
            $table->index(['tercero_id', 'fecha_emision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes');
    }
};
