<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_javas', function (Blueprint $table): void {
            $table->foreignId('jornada_id')
                ->nullable()
                ->after('sucursal_id')
                ->constrained('jornadas_operativas')
                ->restrictOnDelete();
            $table->index(
                ['jornada_id', 'tipo', 'fecha_movimiento'],
                'movimientos_javas_jornada_tipo_fecha_index'
            );
        });

        $this->backfillJourneys();
    }

    public function down(): void
    {
        Schema::table('movimientos_javas', function (Blueprint $table): void {
            $table->dropIndex('movimientos_javas_jornada_tipo_fecha_index');
            $table->dropConstrainedForeignId('jornada_id');
        });
    }

    private function backfillJourneys(): void
    {
        DB::table('movimientos_javas')
            ->whereNull('jornada_id')
            ->whereNotNull('ticket_despacho_id')
            ->orderBy('id')
            ->select(['id', 'ticket_despacho_id'])
            ->chunkById(200, function ($movements): void {
                $journeys = DB::table('tickets_despacho')
                    ->whereIn('id', $movements->pluck('ticket_despacho_id'))
                    ->pluck('jornada_id', 'id');

                foreach ($movements as $movement) {
                    $journeyId = $journeys[$movement->ticket_despacho_id] ?? null;

                    if ($journeyId) {
                        DB::table('movimientos_javas')
                            ->where('id', $movement->id)
                            ->update(['jornada_id' => $journeyId]);
                    }
                }
            });

        DB::table('movimientos_javas')
            ->whereNull('jornada_id')
            ->orderBy('id')
            ->select(['id', 'sucursal_id', 'fecha_movimiento'])
            ->chunkById(200, function ($movements): void {
                foreach ($movements as $movement) {
                    $journeyId = DB::table('jornadas_operativas')
                        ->where('sucursal_id', $movement->sucursal_id)
                        ->where('inicio_at', '<=', $movement->fecha_movimiento)
                        ->where('cierre_programado_at', '>=', $movement->fecha_movimiento)
                        ->orderByDesc('id')
                        ->value('id');

                    if ($journeyId) {
                        DB::table('movimientos_javas')
                            ->where('id', $movement->id)
                            ->update(['jornada_id' => $journeyId]);
                    }
                }
            });
    }
};
