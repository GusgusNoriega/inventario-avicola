<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_javas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('terceros')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->unsignedInteger('cantidad');
            $table->foreignId('ticket_despacho_id')
                ->nullable()
                ->constrained('tickets_despacho')
                ->cascadeOnDelete();
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->restrictOnDelete();
            $table->foreignId('conductor_id')->nullable()->constrained('conductores')->restrictOnDelete();
            $table->timestamp('fecha_movimiento');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique('ticket_despacho_id');
            $table->index(['sucursal_id', 'cliente_id', 'fecha_movimiento']);
            $table->index(['empresa_id', 'tipo', 'fecha_movimiento']);
        });

        $this->importExistingDispatches();
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_javas');
    }

    private function importExistingDispatches(): void
    {
        DB::table('tickets_despacho')
            ->join('jornadas_operativas', 'jornadas_operativas.id', '=', 'tickets_despacho.jornada_id')
            ->join('sucursales', 'sucursales.id', '=', 'jornadas_operativas.sucursal_id')
            ->where('tickets_despacho.tipo_operacion', 'DESPACHO')
            ->whereNotNull('tickets_despacho.cliente_destino_id')
            ->orderBy('tickets_despacho.id')
            ->select([
                'tickets_despacho.id',
                'tickets_despacho.cliente_destino_id',
                'tickets_despacho.vehiculo_entrega_id',
                'tickets_despacho.conductor_entrega_id',
                'tickets_despacho.cerrado_at',
                'tickets_despacho.created_at',
                'tickets_despacho.updated_at',
                'tickets_despacho.created_by',
                'jornadas_operativas.sucursal_id',
                'sucursales.empresa_id',
            ])
            ->chunkById(200, function ($tickets): void {
                $ticketIds = $tickets->pluck('id');
                $quantities = DB::table('pesadas')
                    ->whereIn('ticket_id', $ticketIds)
                    ->where('estado', 'ACTIVA')
                    ->groupBy('ticket_id')
                    ->pluck(DB::raw('SUM(cantidad_javas)'), 'ticket_id');
                $rows = [];

                foreach ($tickets as $ticket) {
                    $quantity = (int) ($quantities[$ticket->id] ?? 0);

                    if ($quantity === 0) {
                        continue;
                    }

                    $rows[] = [
                        'empresa_id' => $ticket->empresa_id,
                        'sucursal_id' => $ticket->sucursal_id,
                        'cliente_id' => $ticket->cliente_destino_id,
                        'tipo' => 'DESPACHO',
                        'cantidad' => $quantity,
                        'ticket_despacho_id' => $ticket->id,
                        'vehiculo_id' => $ticket->vehiculo_entrega_id,
                        'conductor_id' => $ticket->conductor_entrega_id,
                        'fecha_movimiento' => $ticket->cerrado_at ?: $ticket->created_at,
                        'observaciones' => null,
                        'created_by' => $ticket->created_by,
                        'created_at' => $ticket->created_at,
                        'updated_at' => $ticket->updated_at,
                    ];
                }

                if ($rows !== []) {
                    DB::table('movimientos_javas')->insert($rows);
                }
            }, 'tickets_despacho.id', 'id');
    }
};
