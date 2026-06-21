<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pago_aplicaciones', function (Blueprint $table) {
            $table->foreignId('pago_id')->constrained('pagos')->cascadeOnDelete();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->restrictOnDelete();
            $table->decimal('importe_aplicado', 14, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['pago_id', 'comprobante_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_aplicaciones');
    }
};
