<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepcion_pollo_vivo_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recepcion_id')
                ->constrained('recepciones_pollo_vivo')
                ->restrictOnDelete();
            $table->foreignId('ticket_despacho_id')
                ->unique()
                ->constrained('tickets_despacho')
                ->restrictOnDelete();
            $table->foreignId('movimiento_inventario_id')
                ->nullable()
                ->unique()
                ->constrained('movimientos_inventario')
                ->restrictOnDelete();
            $table->unsignedTinyInteger('columna');
            $table->char('request_hash', 64);
            $table->unsignedInteger('cantidad_javas_aplicada')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->index(
                ['recepcion_id', 'columna'],
                'recepcion_ticket_columna_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcion_pollo_vivo_tickets');
    }
};
