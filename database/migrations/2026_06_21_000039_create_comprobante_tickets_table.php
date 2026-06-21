<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobante_tickets', function (Blueprint $table) {
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets_despacho')->restrictOnDelete();
            $table->decimal('importe_aplicado', 14, 2);
            $table->primary(['comprobante_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_tickets');
    }
};
