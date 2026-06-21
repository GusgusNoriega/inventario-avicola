<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_despacho', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('jornadas_operativas')->restrictOnDelete();
            $table->string('codigo', 40);
            $table->string('canal', 20)->default('MAYORISTA');
            $table->foreignId('cliente_destino_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->foreignId('almacen_destino_id')->nullable()->constrained('almacenes')->restrictOnDelete();
            $table->string('estado', 20)->default('ABIERTO');
            $table->text('observaciones')->nullable();
            $table->foreignId('cerrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('cerrado_at')->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['jornada_id', 'codigo']);
            $table->index(['cliente_destino_id', 'estado']);
            $table->index(['almacen_destino_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_despacho');
    }
};
