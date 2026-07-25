<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_precio_ajuste_operaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->json('resultado')->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['empresa_id', 'idempotency_key'],
                'ticket_precio_ajuste_empresa_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_precio_ajuste_operaciones');
    }
};
