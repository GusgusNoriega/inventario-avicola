<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobante_pesadas', function (Blueprint $table) {
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $table->foreignId('pesada_id')->constrained('pesadas')->restrictOnDelete();
            $table->decimal('importe_aplicado', 14, 2);
            $table->primary(['comprobante_id', 'pesada_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_pesadas');
    }
};
