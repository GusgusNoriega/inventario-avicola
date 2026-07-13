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
            $table->unsignedInteger('cantidad_bandejas')->default(0)->after('cantidad');
        });
        Schema::table('inventarios_javas', function (Blueprint $table): void {
            $table->unsignedInteger('cantidad_total_bandejas')->nullable()->after('cantidad_total');
        });
        Schema::table('conteos_diarios_javas', function (Blueprint $table): void {
            $table->unsignedInteger('cantidad_en_empresa_bandejas')->nullable()->after('cantidad_en_empresa');
            $table->unsignedInteger('cantidad_esperada_bandejas')->nullable()->after('cantidad_esperada');
            $table->integer('diferencia_bandejas')->nullable()->after('diferencia');
        });

        $this->backfillRetailDispatches();
    }

    public function down(): void
    {
        DB::table('movimientos_javas')
            ->where('cantidad', 0)
            ->whereIn('ticket_despacho_id', DB::table('tickets_despacho')
                ->where('canal', 'MINORISTA')
                ->select('id'))
            ->delete();

        Schema::table('conteos_diarios_javas', function (Blueprint $table): void {
            $table->dropColumn([
                'cantidad_en_empresa_bandejas',
                'cantidad_esperada_bandejas',
                'diferencia_bandejas',
            ]);
        });
        Schema::table('inventarios_javas', function (Blueprint $table): void {
            $table->dropColumn('cantidad_total_bandejas');
        });

        Schema::table('movimientos_javas', function (Blueprint $table): void {
            $table->dropColumn('cantidad_bandejas');
        });
    }

    private function backfillRetailDispatches(): void
    {
        DB::table('tickets_despacho')
            ->join('jornadas_operativas', 'jornadas_operativas.id', '=', 'tickets_despacho.jornada_id')
            ->join('sucursales', 'sucursales.id', '=', 'jornadas_operativas.sucursal_id')
            ->where('tickets_despacho.canal', 'MINORISTA')
            ->where('tickets_despacho.tipo_operacion', 'DESPACHO')
            ->whereNotNull('tickets_despacho.cliente_destino_id')
            ->orderBy('tickets_despacho.id')
            ->select([
                'tickets_despacho.id',
                'tickets_despacho.jornada_id',
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
                $trayQuantities = DB::table('pesadas')
                    ->whereIn('ticket_id', $tickets->pluck('id'))
                    ->where('estado', 'ACTIVA')
                    ->groupBy('ticket_id')
                    ->pluck(DB::raw('COALESCE(SUM(cantidad_bandejas), 0)'), 'ticket_id');

                foreach ($tickets as $ticket) {
                    $trayQuantity = (int) ($trayQuantities[$ticket->id] ?? 0);

                    if ($trayQuantity === 0) {
                        continue;
                    }

                    $values = [
                        'empresa_id' => $ticket->empresa_id,
                        'sucursal_id' => $ticket->sucursal_id,
                        'jornada_id' => $ticket->jornada_id,
                        'cliente_id' => $ticket->cliente_destino_id,
                        'tipo' => 'DESPACHO',
                        'cantidad_bandejas' => $trayQuantity,
                        'vehiculo_id' => $ticket->vehiculo_entrega_id,
                        'conductor_id' => $ticket->conductor_entrega_id,
                        'fecha_movimiento' => $ticket->cerrado_at ?: $ticket->created_at,
                        'updated_at' => $ticket->updated_at ?: now(),
                    ];
                    $existing = DB::table('movimientos_javas')
                        ->where('ticket_despacho_id', $ticket->id)
                        ->exists();

                    if ($existing) {
                        DB::table('movimientos_javas')
                            ->where('ticket_despacho_id', $ticket->id)
                            ->update($values);

                        continue;
                    }

                    DB::table('movimientos_javas')->insert([
                        ...$values,
                        'cantidad' => 0,
                        'ticket_despacho_id' => $ticket->id,
                        'observaciones' => null,
                        'created_by' => $ticket->created_by,
                        'created_at' => $ticket->created_at ?: now(),
                    ]);
                }
            }, 'tickets_despacho.id', 'id');
    }
};
