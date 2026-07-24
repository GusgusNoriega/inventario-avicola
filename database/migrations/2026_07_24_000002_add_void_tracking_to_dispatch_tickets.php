<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->foreignId('anulado_por')
                ->nullable()
                ->after('cerrado_at')
                ->constrained('usuarios')
                ->nullOnDelete();
            $table->timestamp('anulado_at')->nullable()->after('anulado_por');
            $table->string('motivo_anulacion', 250)->nullable()->after('anulado_at');
            $table->index(['estado', 'anulado_at'], 'ticket_estado_anulado_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->dropIndex('ticket_estado_anulado_index');
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropColumn(['anulado_at', 'motivo_anulacion']);
        });
    }
};
