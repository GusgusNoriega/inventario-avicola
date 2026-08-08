<?php

namespace App\Services;

use App\Models\AjusteSaldoJava;
use App\Models\Conductor;
use App\Models\ConteoDiarioJava;
use App\Models\InventarioJava;
use App\Models\JornadaOperativa;
use App\Models\MovimientoJava;
use App\Models\Pesada;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\User;
use App\Models\Vehiculo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JavaControlService
{
    public function __construct(
        private readonly AccessAuditService $audit
    ) {}

    public function clientBalancesQuery(int $companyId): QueryBuilder
    {
        $movementBalances = DB::table('movimientos_javas')
            ->where('empresa_id', $companyId)
            ->groupBy('cliente_id')
            ->select('cliente_id')
            ->selectRaw(
                "SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad WHEN tipo = 'RECEPCION' THEN -cantidad ELSE 0 END) AS saldo_javas"
            )
            ->selectRaw(
                "SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad_bandejas WHEN tipo = 'RECEPCION' THEN -cantidad_bandejas ELSE 0 END) AS saldo_bandejas"
            );
        $adjustmentBalances = DB::table('ajustes_saldos_javas')
            ->where('empresa_id', $companyId)
            ->groupBy('cliente_id')
            ->select('cliente_id')
            ->selectRaw('SUM(diferencia_javas) AS saldo_javas')
            ->selectRaw('SUM(diferencia_bandejas) AS saldo_bandejas');

        return DB::query()
            ->fromSub($movementBalances->unionAll($adjustmentBalances), 'saldos_envases')
            ->groupBy('cliente_id')
            ->select('cliente_id')
            ->selectRaw('SUM(saldo_javas) AS saldo_javas')
            ->selectRaw('SUM(saldo_bandejas) AS saldo_bandejas');
    }

    /** @return array<string, mixed> */
    public function currentInventory(int $companyId, ?int $journeyId = null): array
    {
        $clientHolders = $this->clientHolders($companyId);
        $inventory = InventarioJava::query()
            ->where('empresa_id', $companyId)
            ->first();
        $dailyCount = $journeyId
            ? ConteoDiarioJava::query()
                ->where('empresa_id', $companyId)
                ->where('jornada_id', $journeyId)
                ->with([
                    'camiones.vehiculo:id,placa,estado',
                    'contador:id,nombre',
                ])
                ->first()
            : null;
        $activeTrucks = Vehiculo::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Vehiculo::STATUS_ACTIVE)
            ->orderBy('placa')
            ->get(['id', 'placa', 'estado']);

        $javas = $this->formatInventoryAsset(
            $inventory,
            'cantidad_total',
            (int) $clientHolders['totals']['external_javas'],
            (int) $clientHolders['totals']['internal_javas'],
            $this->formatDailyCount($dailyCount, 'javas')
        );
        $trays = $this->formatInventoryAsset(
            $inventory,
            'cantidad_total_bandejas',
            (int) $clientHolders['totals']['external_trays'],
            (int) $clientHolders['totals']['internal_trays'],
            $this->formatDailyCount($dailyCount, 'trays')
        );

        return [
            ...$javas,
            'javas' => $javas,
            'trays' => $trays,
            'client_holders' => $clientHolders,
            'count_breakdown' => $this->formatCountBreakdown(
                $dailyCount,
                $activeTrucks,
                $inventory,
                $clientHolders
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveInventoryTotal(int $companyId, User $actor, array $data): array
    {
        return DB::transaction(function () use ($companyId, $actor, $data): array {
            $inventory = InventarioJava::query()
                ->where('empresa_id', $companyId)
                ->lockForUpdate()
                ->first();
            $clientHolders = $this->clientHolders($companyId);
            $outsideJavaQuantity = (int) $clientHolders['totals']['all_clients_javas'];
            $outsideTrayQuantity = (int) $clientHolders['totals']['all_clients_trays'];
            $javaQuantity = (int) $data['java_quantity'];
            $trayQuantity = array_key_exists('tray_quantity', $data)
                ? (int) $data['tray_quantity']
                : null;
            $javaField = array_key_exists('total_quantity', $data)
                ? 'total_quantity'
                : 'java_quantity';

            if ($javaQuantity < $outsideJavaQuantity) {
                throw ValidationException::withMessages([
                    $javaField => "El total general no puede ser menor que las {$outsideJavaQuantity} javas asignadas a clientes.",
                ]);
            }

            if ($trayQuantity !== null && $trayQuantity < $outsideTrayQuantity) {
                throw ValidationException::withMessages([
                    'tray_quantity' => "El total general no puede ser menor que las {$outsideTrayQuantity} bandejas asignadas a clientes.",
                ]);
            }

            $values = [
                'cantidad_total' => $javaQuantity,
                'updated_by' => $actor->id,
            ];
            if ($trayQuantity !== null) {
                $values['cantidad_total_bandejas'] = $trayQuantity;
            }

            if ($inventory) {
                $inventory->update($values);
            } else {
                $inventory = InventarioJava::query()->create([
                    'empresa_id' => $companyId,
                    ...$values,
                ]);
            }

            return $this->currentInventory($companyId);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveDailyCount(
        int $companyId,
        int $branchId,
        string $timezone,
        User $actor,
        array $data
    ): array {
        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $timezone,
            $actor,
            $data
        ): array {
            $javaField = array_key_exists('quantity', $data) ? 'quantity' : 'java_quantity';
            $inventory = InventarioJava::query()
                ->where('empresa_id', $companyId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw ValidationException::withMessages([
                    $javaField => 'Primero debes definir el total general de javas de la empresa.',
                ]);
            }

            $isDetailedCount = array_key_exists('local_java_quantity', $data);
            $hasTrayCount = $isDetailedCount || array_key_exists('tray_quantity', $data);

            if ($hasTrayCount && $inventory->cantidad_total_bandejas === null) {
                throw ValidationException::withMessages([
                    $isDetailedCount ? 'local_tray_quantity' : 'tray_quantity' => 'Primero debes definir el total general de bandejas de la empresa.',
                ]);
            }

            $occurredAt = CarbonImmutable::now($timezone);
            $journey = $this->currentJourney(
                $companyId,
                $branchId,
                $timezone,
                $actor,
                $occurredAt
            );
            $clientHolders = $this->clientHolders($companyId);
            $outsideJavaQuantity = (int) $clientHolders['totals']['all_clients_javas'];
            $outsideTrayQuantity = (int) $clientHolders['totals']['all_clients_trays'];
            $expectedJavaQuantity = (int) $inventory->cantidad_total - $outsideJavaQuantity;
            $expectedTrayQuantity = $hasTrayCount
                ? (int) $inventory->cantidad_total_bandejas - $outsideTrayQuantity
                : null;
            $truckRows = [];
            $localJavaQuantity = null;
            $localTrayQuantity = null;

            if ($isDetailedCount) {
                $activeTrucks = Vehiculo::query()
                    ->where('empresa_id', $companyId)
                    ->where('estado', Vehiculo::STATUS_ACTIVE)
                    ->orderBy('placa')
                    ->lockForUpdate()
                    ->get(['id', 'placa']);
                $submittedCounts = collect($data['truck_counts'] ?? []);
                $submittedIds = $submittedCounts
                    ->pluck('vehicle_id')
                    ->map(fn (mixed $id): int => (int) $id);
                $activeIds = $activeTrucks->pluck('id')->map(fn (mixed $id): int => (int) $id);
                $missingIds = $activeIds->diff($submittedIds);
                $unexpectedIds = $submittedIds->diff($activeIds);

                if ($submittedIds->duplicates()->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'truck_counts' => 'Un camion no puede aparecer dos veces en el mismo conteo.',
                    ]);
                }

                if ($missingIds->isNotEmpty()) {
                    $missingPlates = $activeTrucks
                        ->whereIn('id', $missingIds)
                        ->pluck('placa')
                        ->implode(', ');

                    throw ValidationException::withMessages([
                        'truck_counts' => "Debes registrar todos los camiones activos, incluso en cero. Faltan: {$missingPlates}.",
                    ]);
                }

                if ($unexpectedIds->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'truck_counts' => 'El conteo contiene un camion inactivo o que no pertenece a la empresa.',
                    ]);
                }

                $countsByVehicle = $submittedCounts->keyBy(
                    fn (array $count): int => (int) $count['vehicle_id']
                );
                $localJavaQuantity = (int) $data['local_java_quantity'];
                $localTrayQuantity = (int) $data['local_tray_quantity'];

                foreach ($activeTrucks as $truck) {
                    $truckCount = $countsByVehicle->get((int) $truck->id);
                    $truckRows[] = [
                        'vehiculo_id' => (int) $truck->id,
                        'placa_snapshot' => $truck->placa,
                        'cantidad_javas' => (int) $truckCount['java_quantity'],
                        'cantidad_bandejas' => (int) $truckCount['tray_quantity'],
                    ];
                }

                $countedJavaQuantity = $localJavaQuantity + (int) collect($truckRows)->sum('cantidad_javas');
                $countedTrayQuantity = $localTrayQuantity + (int) collect($truckRows)->sum('cantidad_bandejas');
            } else {
                $countedJavaQuantity = (int) $data['java_quantity'];
                $countedTrayQuantity = $hasTrayCount ? (int) $data['tray_quantity'] : null;
            }

            $values = [
                'empresa_id' => $companyId,
                'cantidad_en_empresa' => $countedJavaQuantity,
                'cantidad_en_local' => $localJavaQuantity,
                'cantidad_esperada' => $expectedJavaQuantity,
                'diferencia' => $countedJavaQuantity - $expectedJavaQuantity,
                'cantidad_clientes_externos' => $isDetailedCount
                    ? (int) $clientHolders['totals']['external_javas']
                    : null,
                'cantidad_clientes_externos_bandejas' => $isDetailedCount
                    ? (int) $clientHolders['totals']['external_trays']
                    : null,
                'cantidad_clientes_internos' => $isDetailedCount
                    ? (int) $clientHolders['totals']['internal_javas']
                    : null,
                'cantidad_clientes_internos_bandejas' => $isDetailedCount
                    ? (int) $clientHolders['totals']['internal_trays']
                    : null,
                'cantidad_total_inventario' => $isDetailedCount
                    ? (int) $inventory->cantidad_total
                    : null,
                'cantidad_total_inventario_bandejas' => $isDetailedCount
                    ? (int) $inventory->cantidad_total_bandejas
                    : null,
                'contado_at' => $occurredAt->format('Y-m-d H:i:s'),
                'contado_por' => $actor->id,
            ];
            if ($hasTrayCount) {
                $values += [
                    'cantidad_en_empresa_bandejas' => $countedTrayQuantity,
                    'cantidad_en_local_bandejas' => $localTrayQuantity,
                    'cantidad_esperada_bandejas' => $expectedTrayQuantity,
                    'diferencia_bandejas' => $countedTrayQuantity - $expectedTrayQuantity,
                ];
            } else {
                $values += [
                    'cantidad_en_empresa_bandejas' => null,
                    'cantidad_en_local_bandejas' => null,
                    'cantidad_esperada_bandejas' => null,
                    'diferencia_bandejas' => null,
                ];
            }

            $dailyCount = ConteoDiarioJava::query()->updateOrCreate(
                ['jornada_id' => $journey->id],
                $values
            );
            $dailyCount->camiones()->delete();

            if ($truckRows !== []) {
                $dailyCount->camiones()->createMany($truckRows);
            }

            return $this->currentInventory($companyId, (int) $journey->id);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function adjustClientBalance(
        int $companyId,
        int $branchId,
        string $timezone,
        User $actor,
        int $clientId,
        array $data,
        ?string $ip
    ): AjusteSaldoJava {
        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $timezone,
            $actor,
            $clientId,
            $data,
            $ip
        ): AjusteSaldoJava {
            if ((int) $actor->empresa_id !== $companyId) {
                throw ValidationException::withMessages([
                    'actor' => 'El usuario que realiza la corrección no pertenece a la empresa.',
                ]);
            }

            $inventory = InventarioJava::query()
                ->where('empresa_id', $companyId)
                ->lockForUpdate()
                ->first();
            $client = Tercero::query()
                ->where('empresa_id', $companyId)
                ->where('estado', Tercero::STATUS_ACTIVE)
                ->conRol(TerceroRole::CLIENT)
                ->lockForUpdate()
                ->find($clientId);

            if (! $client) {
                throw ValidationException::withMessages([
                    'client_id' => 'El cliente seleccionado no está activo o no pertenece a la empresa.',
                ]);
            }

            $adjustedAt = CarbonImmutable::now($timezone);
            $journey = $this->currentJourney(
                $companyId,
                $branchId,
                $timezone,
                $actor,
                $adjustedAt
            );
            $current = $this->lockedClientBalance($companyId, (int) $client->id);
            $visibleCurrentJava = max(0, $current['javas']);
            $visibleCurrentTrays = max(0, $current['trays']);

            if (
                (int) $data['expected_java_balance'] !== $visibleCurrentJava
                || (int) $data['expected_tray_balance'] !== $visibleCurrentTrays
            ) {
                throw ValidationException::withMessages([
                    'balance' => 'El saldo cambió mientras lo estabas editando. Recarga la pantalla y vuelve a intentarlo.',
                ]);
            }

            $newJavaBalance = (int) $data['java_balance'];
            $newTrayBalance = (int) $data['tray_balance'];
            $javaDifference = $newJavaBalance - $current['javas'];
            $trayDifference = $newTrayBalance - $current['trays'];

            if ($javaDifference === 0 && $trayDifference === 0) {
                throw ValidationException::withMessages([
                    'balance' => 'Indica un saldo diferente al actual para guardar la corrección.',
                ]);
            }

            if ($inventory) {
                $holderTotals = $this->clientHolders($companyId)['totals'];
                $nextOutsideJavas = (int) $holderTotals['all_clients_javas']
                    - max(0, $current['javas'])
                    + $newJavaBalance;
                $nextOutsideTrays = (int) $holderTotals['all_clients_trays']
                    - max(0, $current['trays'])
                    + $newTrayBalance;

                if ($nextOutsideJavas > (int) $inventory->cantidad_total) {
                    throw ValidationException::withMessages([
                        'java_balance' => "El nuevo saldo dejaría {$nextOutsideJavas} javas con clientes, pero el inventario general es de {$inventory->cantidad_total}.",
                    ]);
                }

                if (
                    $inventory->cantidad_total_bandejas !== null
                    && $nextOutsideTrays > (int) $inventory->cantidad_total_bandejas
                ) {
                    throw ValidationException::withMessages([
                        'tray_balance' => "El nuevo saldo dejaría {$nextOutsideTrays} bandejas con clientes, pero el inventario general es de {$inventory->cantidad_total_bandejas}.",
                    ]);
                }
            }

            $reason = trim((string) $data['reason']);
            $adjustment = AjusteSaldoJava::query()->create([
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'jornada_id' => $journey->id,
                'cliente_id' => $client->id,
                'saldo_anterior_javas' => $current['javas'],
                'saldo_nuevo_javas' => $newJavaBalance,
                'diferencia_javas' => $javaDifference,
                'saldo_anterior_bandejas' => $current['trays'],
                'saldo_nuevo_bandejas' => $newTrayBalance,
                'diferencia_bandejas' => $trayDifference,
                'motivo' => $reason,
                'created_by' => $actor->id,
            ]);

            $before = [
                'cliente_id' => (int) $client->id,
                'cliente' => $client->nombre_razon_social,
                'saldo_javas' => $current['javas'],
                'saldo_bandejas' => $current['trays'],
            ];
            $after = [
                'cliente_id' => (int) $client->id,
                'cliente' => $client->nombre_razon_social,
                'saldo_javas' => $newJavaBalance,
                'saldo_bandejas' => $newTrayBalance,
                'diferencia_javas' => $javaDifference,
                'diferencia_bandejas' => $trayDifference,
                'motivo' => $reason,
            ];
            $this->audit->record(
                $companyId,
                (int) $actor->id,
                'ajustes_saldos_javas',
                (int) $adjustment->id,
                'AJUSTAR_SALDO_CLIENTE',
                $before,
                $after,
                $ip,
            );

            return $adjustment->load([
                'jornada:id,fecha_operativa,estado',
                'cliente:id,nombre_razon_social',
                'creador:id,nombre',
            ]);
        }, 3);
    }

    public function syncDispatchMovement(
        TicketDespacho $ticket,
        int $companyId,
        int $branchId
    ): void {
        if (
            $ticket->tipo_operacion !== TicketDespacho::OPERATION_DISPATCH
            || ! $ticket->cliente_destino_id
        ) {
            return;
        }

        $quantities = Pesada::query()
            ->where('ticket_id', $ticket->id)
            ->where('estado', Pesada::STATUS_ACTIVE)
            ->selectRaw('COALESCE(SUM(cantidad_javas), 0) AS javas')
            ->selectRaw('COALESCE(SUM(cantidad_bandejas), 0) AS trays')
            ->first();
        $isVoided = $ticket->estado === TicketDespacho::STATUS_VOIDED;
        $javaQuantity = $isVoided ? 0 : (int) ($quantities?->javas ?? 0);
        $trayQuantity = $isVoided ? 0 : (int) ($quantities?->trays ?? 0);
        $clientMovements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $ticket->cliente_destino_id)
            ->lockForUpdate()
            ->get(['tipo', 'cantidad', 'cantidad_bandejas', 'ticket_despacho_id']);
        $ticketMovement = $clientMovements->first(
            fn (MovimientoJava $movement): bool => $movement->tipo === MovimientoJava::TYPE_DISPATCH
                && (int) $movement->ticket_despacho_id === (int) $ticket->id
        );
        $adjustmentDeltas = $this->lockedAdjustmentDeltas(
            $companyId,
            (int) $ticket->cliente_destino_id
        );
        $otherJavaNet = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_DISPATCH)
            ->where('ticket_despacho_id', '!=', $ticket->id)
            ->sum('cantidad')
            - (int) $clientMovements
                ->where('tipo', MovimientoJava::TYPE_RECEIPT)
                ->sum('cantidad')
            + $adjustmentDeltas['javas'];
        $otherTrayNet = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_DISPATCH)
            ->where('ticket_despacho_id', '!=', $ticket->id)
            ->sum('cantidad_bandejas')
            - (int) $clientMovements
                ->where('tipo', MovimientoJava::TYPE_RECEIPT)
                ->sum('cantidad_bandejas')
            + $adjustmentDeltas['trays'];

        if ($isVoided && $ticketMovement) {
            // Las devoluciones y correcciones no están vinculadas a un ticket concreto.
            // Al anular se conserva solo la porción del despacho que las compensa y que
            // no puede respaldarse con otros tickets del cliente. Así el saldo queda en
            // cero y la trazabilidad sigue disponible para una eventual restauración.
            $javaQuantity = max(0, -$otherJavaNet);
            $trayQuantity = max(0, -$otherTrayNet);

            if ($javaQuantity > (int) $ticketMovement->cantidad) {
                throw ValidationException::withMessages([
                    'cages' => 'No se puede conciliar la anulación porque el saldo previo de javas es inconsistente. Revisa los movimientos del cliente.',
                ]);
            }

            if ($trayQuantity > (int) $ticketMovement->cantidad_bandejas) {
                throw ValidationException::withMessages([
                    'trays' => 'No se puede conciliar la anulación porque el saldo previo de bandejas es inconsistente. Revisa los movimientos del cliente.',
                ]);
            }
        }

        if (! $isVoided && $javaQuantity + $otherJavaNet < 0) {
            throw ValidationException::withMessages([
                'cages' => 'No se puede reducir esta cantidad porque el cliente ya devolvió javas asociadas a su saldo.',
            ]);
        }

        if (! $isVoided && $trayQuantity + $otherTrayNet < 0) {
            throw ValidationException::withMessages([
                'trays' => 'No se puede reducir esta cantidad porque el cliente ya devolvio bandejas asociadas a su saldo.',
            ]);
        }

        if ($javaQuantity === 0 && $trayQuantity === 0) {
            MovimientoJava::query()
                ->where('ticket_despacho_id', $ticket->id)
                ->delete();

            return;
        }

        MovimientoJava::query()->updateOrCreate(
            ['ticket_despacho_id' => $ticket->id],
            [
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'jornada_id' => $ticket->jornada_id,
                'cliente_id' => $ticket->cliente_destino_id,
                'tipo' => MovimientoJava::TYPE_DISPATCH,
                'cantidad' => $javaQuantity,
                'cantidad_bandejas' => $trayQuantity,
                'vehiculo_id' => $ticket->vehiculo_entrega_id,
                'conductor_id' => $ticket->conductor_entrega_id,
                'fecha_movimiento' => $ticket->cerrado_at ?: $ticket->created_at ?: now(),
                'observaciones' => $isVoided
                    ? "Saldo neutralizado por anulación del ticket {$ticket->codigo}; conserva devoluciones ya registradas."
                    : null,
                'created_by' => $ticket->created_by,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerReceipt(
        int $companyId,
        int $branchId,
        string $timezone,
        User $actor,
        array $data
    ): MovimientoJava {
        $client = Tercero::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::CLIENT)
            ->find($data['client_id']);
        $vehicle = Vehiculo::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Vehiculo::STATUS_ACTIVE)
            ->find($data['vehicle_id']);
        $driver = Conductor::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Conductor::STATUS_ACTIVE)
            ->find($data['driver_id']);

        if (! $client) {
            throw ValidationException::withMessages([
                'client_id' => 'El cliente seleccionado no está activo o no pertenece a la empresa.',
            ]);
        }
        if (! $vehicle) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'El camión seleccionado no pertenece a la flota activa de la empresa.',
            ]);
        }
        if (! $driver) {
            throw ValidationException::withMessages([
                'driver_id' => 'El chofer seleccionado no pertenece a la empresa o está inactivo.',
            ]);
        }

        $receivedAt = CarbonImmutable::now($timezone);

        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $actor,
            $data,
            $client,
            $vehicle,
            $driver,
            $receivedAt,
            $timezone
        ): MovimientoJava {
            $journey = $this->currentJourney(
                $companyId,
                $branchId,
                $timezone,
                $actor,
                $receivedAt
            );
            $balance = $this->lockedClientBalance($companyId, (int) $client->id);
            $javaBalance = $balance['javas'];
            $trayBalance = $balance['trays'];
            $javaQuantity = (int) ($data['java_quantity'] ?? $data['quantity'] ?? 0);
            $trayQuantity = (int) ($data['tray_quantity'] ?? 0);
            $javaField = array_key_exists('quantity', $data) ? 'quantity' : 'java_quantity';

            if ($javaQuantity > $javaBalance) {
                throw ValidationException::withMessages([
                    $javaField => "El cliente solo tiene {$javaBalance} javas pendientes. No se pueden recibir {$javaQuantity}.",
                ]);
            }
            if ($trayQuantity > $trayBalance) {
                throw ValidationException::withMessages([
                    'tray_quantity' => "El cliente solo tiene {$trayBalance} bandejas pendientes. No se pueden recibir {$trayQuantity}.",
                ]);
            }

            return MovimientoJava::query()->create([
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'jornada_id' => $journey->id,
                'cliente_id' => $client->id,
                'tipo' => MovimientoJava::TYPE_RECEIPT,
                'cantidad' => $javaQuantity,
                'cantidad_bandejas' => $trayQuantity,
                'vehiculo_id' => $vehicle->id,
                'conductor_id' => $driver->id,
                'fecha_movimiento' => $receivedAt->format('Y-m-d H:i:s'),
                'observaciones' => filled($data['observations'] ?? null)
                    ? trim((string) $data['observations'])
                    : null,
                'created_by' => $actor->id,
            ]);
        }, 3);
    }

    /** @return array{javas: int, trays: int} */
    public function lockedAdjustmentDeltas(int $companyId, int $clientId): array
    {
        $adjustments = AjusteSaldoJava::query()
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $clientId)
            ->lockForUpdate()
            ->get(['diferencia_javas', 'diferencia_bandejas']);

        return [
            'javas' => (int) $adjustments->sum('diferencia_javas'),
            'trays' => (int) $adjustments->sum('diferencia_bandejas'),
        ];
    }

    /** @return array{javas: int, trays: int} */
    private function lockedClientBalance(int $companyId, int $clientId): array
    {
        $movements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $clientId)
            ->lockForUpdate()
            ->get(['tipo', 'cantidad', 'cantidad_bandejas']);
        $adjustments = $this->lockedAdjustmentDeltas($companyId, $clientId);

        return [
            'javas' => (int) $movements->sum(
                fn (MovimientoJava $movement): int => match ($movement->tipo) {
                    MovimientoJava::TYPE_DISPATCH => $movement->cantidad,
                    MovimientoJava::TYPE_RECEIPT => -$movement->cantidad,
                    default => 0,
                }
            ) + $adjustments['javas'],
            'trays' => (int) $movements->sum(
                fn (MovimientoJava $movement): int => match ($movement->tipo) {
                    MovimientoJava::TYPE_DISPATCH => $movement->cantidad_bandejas,
                    MovimientoJava::TYPE_RECEIPT => -$movement->cantidad_bandejas,
                    default => 0,
                }
            ) + $adjustments['trays'],
        ];
    }

    private function currentJourney(
        int $companyId,
        int $branchId,
        string $timezone,
        User $actor,
        CarbonImmutable $occurredAt
    ): JornadaOperativa {
        $occurredAt = $occurredAt->setTimezone($timezone);
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $cutoffAt = $occurredAt->startOfDay()->setTimeFromTimeString($cutoff);
        $operatingDate = $occurredAt->greaterThanOrEqualTo($cutoffAt)
            ? $occurredAt->addDay()->startOfDay()
            : $occurredAt->startOfDay();
        $journey = JornadaOperativa::query()
            ->where('sucursal_id', $branchId)
            ->whereDate('fecha_operativa', $operatingDate->format('Y-m-d'))
            ->lockForUpdate()
            ->first();

        if (! $journey) {
            $journey = JornadaOperativa::query()->create([
                'sucursal_id' => $branchId,
                'fecha_operativa' => $operatingDate->format('Y-m-d'),
                'estado' => JornadaOperativa::STATUS_OPEN,
                'abierta_por' => $actor->id,
                'inicio_at' => $operatingDate->subDay()->setTimeFromTimeString($cutoff),
                'cierre_programado_at' => $operatingDate->setTimeFromTimeString($cutoff),
            ]);
        }

        if ($journey->estado !== JornadaOperativa::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'journey' => 'La jornada operativa actual ya está cerrada.',
            ]);
        }

        return $journey;
    }

    /** @return array<string, mixed> */
    public function clientHolders(int $companyId): array
    {
        $holders = DB::table('terceros')
            ->joinSub($this->clientBalancesQuery($companyId), 'saldos_envases', function ($join): void {
                $join->on('saldos_envases.cliente_id', '=', 'terceros.id');
            })
            ->where('terceros.empresa_id', $companyId)
            ->where(function ($query): void {
                $query->where('saldos_envases.saldo_javas', '>', 0)
                    ->orWhere('saldos_envases.saldo_bandejas', '>', 0);
            })
            ->orderBy('terceros.es_cliente_interno')
            ->orderBy('terceros.nombre_razon_social')
            ->get([
                'terceros.id',
                'terceros.nombre_razon_social',
                'terceros.numero_documento',
                'terceros.estado',
                'terceros.es_cliente_interno',
                'saldos_envases.saldo_javas',
                'saldos_envases.saldo_bandejas',
            ])
            ->map(fn (object $holder): array => [
                'id' => (int) $holder->id,
                'name' => $holder->nombre_razon_social,
                'document_number' => $holder->numero_documento,
                'status' => $holder->estado,
                'is_internal_client' => (bool) $holder->es_cliente_interno,
                'balance' => max(0, (int) $holder->saldo_javas),
                'java_balance' => max(0, (int) $holder->saldo_javas),
                'tray_balance' => max(0, (int) $holder->saldo_bandejas),
            ]);

        $externalClients = $holders
            ->reject(fn (array $holder): bool => $holder['is_internal_client'])
            ->values();
        $internalClients = $holders
            ->filter(fn (array $holder): bool => $holder['is_internal_client'])
            ->values();
        $group = static fn ($clients): array => [
            'clients_count' => $clients->count(),
            'java_clients_count' => $clients->filter(
                fn (array $client): bool => $client['java_balance'] > 0
            )->count(),
            'tray_clients_count' => $clients->filter(
                fn (array $client): bool => $client['tray_balance'] > 0
            )->count(),
            'java_quantity' => (int) $clients->sum('java_balance'),
            'tray_quantity' => (int) $clients->sum('tray_balance'),
            'clients' => $clients->all(),
        ];
        $external = $group($externalClients);
        $internal = $group($internalClients);

        return [
            'external' => $external,
            'internal' => $internal,
            'totals' => [
                'external_javas' => $external['java_quantity'],
                'external_trays' => $external['tray_quantity'],
                'external_clients_count' => $external['clients_count'],
                'external_java_clients_count' => $external['java_clients_count'],
                'external_tray_clients_count' => $external['tray_clients_count'],
                'internal_javas' => $internal['java_quantity'],
                'internal_trays' => $internal['tray_quantity'],
                'internal_clients_count' => $internal['clients_count'],
                'internal_java_clients_count' => $internal['java_clients_count'],
                'internal_tray_clients_count' => $internal['tray_clients_count'],
                'all_clients_javas' => $external['java_quantity'] + $internal['java_quantity'],
                'all_clients_trays' => $external['tray_quantity'] + $internal['tray_quantity'],
                'all_clients_count' => $external['clients_count'] + $internal['clients_count'],
                'all_java_clients_count' => $external['java_clients_count'] + $internal['java_clients_count'],
                'all_tray_clients_count' => $external['tray_clients_count'] + $internal['tray_clients_count'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatInventoryAsset(
        ?InventarioJava $inventory,
        string $column,
        int $externalQuantity,
        int $internalQuantity,
        array $dailyCount
    ): array {
        $total = $inventory?->{$column};
        $configured = $total !== null;
        $outsideQuantity = $externalQuantity + $internalQuantity;

        return [
            'configured' => $configured,
            'total' => $configured ? (int) $total : null,
            'inside' => $configured ? (int) $total - $outsideQuantity : null,
            'outside' => $outsideQuantity,
            'assigned_external' => $externalQuantity,
            'assigned_internal' => $internalQuantity,
            'updated_at' => $configured ? $inventory?->updated_at?->toISOString() : null,
            'daily_count' => $dailyCount,
        ];
    }

    /**
     * @param  mixed  $activeTrucks
     * @param  array<string, mixed>  $clientHolders
     * @return array<string, mixed>
     */
    private function formatCountBreakdown(
        ?ConteoDiarioJava $count,
        $activeTrucks,
        ?InventarioJava $inventory,
        array $clientHolders
    ): array {
        $configured = $count !== null;
        $detailed = $configured && $count->cantidad_en_local !== null;
        $savedCounts = $count?->camiones ?? collect();
        $savedByVehicle = $savedCounts->keyBy('vehiculo_id');
        $activeIds = $activeTrucks->pluck('id')->map(fn (mixed $id): int => (int) $id);

        $trucks = $activeTrucks->map(function (Vehiculo $truck) use ($savedByVehicle, $detailed): array {
            $saved = $savedByVehicle->get((int) $truck->id);

            return [
                'id' => (int) $truck->id,
                'plate' => $saved?->placa_snapshot ?: $truck->placa,
                'current_plate' => $truck->placa,
                'active' => true,
                'recorded' => $detailed && $saved !== null,
                'java_quantity' => $detailed && $saved ? (int) $saved->cantidad_javas : 0,
                'tray_quantity' => $detailed && $saved ? (int) $saved->cantidad_bandejas : 0,
            ];
        });
        $historicalTrucks = $savedCounts
            ->reject(fn ($saved): bool => $activeIds->contains((int) $saved->vehiculo_id))
            ->map(fn ($saved): array => [
                'id' => (int) $saved->vehiculo_id,
                'plate' => $saved->placa_snapshot,
                'current_plate' => $saved->vehiculo?->placa,
                'active' => false,
                'recorded' => $detailed,
                'java_quantity' => (int) $saved->cantidad_javas,
                'tray_quantity' => (int) $saved->cantidad_bandejas,
            ]);
        $trucks = $trucks->concat($historicalTrucks)->values();

        $currentExternalJava = (int) $clientHolders['totals']['external_javas'];
        $currentExternalTrays = (int) $clientHolders['totals']['external_trays'];
        $currentInternalJava = (int) $clientHolders['totals']['internal_javas'];
        $currentInternalTrays = (int) $clientHolders['totals']['internal_trays'];
        $externalJava = $detailed ? (int) $count->cantidad_clientes_externos : $currentExternalJava;
        $externalTrays = $detailed ? (int) $count->cantidad_clientes_externos_bandejas : $currentExternalTrays;
        $internalJava = $detailed ? (int) $count->cantidad_clientes_internos : $currentInternalJava;
        $internalTrays = $detailed ? (int) $count->cantidad_clientes_internos_bandejas : $currentInternalTrays;
        $propertyJava = $detailed
            ? (int) $count->cantidad_total_inventario
            : ($inventory?->cantidad_total !== null ? (int) $inventory->cantidad_total : null);
        $propertyTrays = $detailed
            ? (int) $count->cantidad_total_inventario_bandejas
            : ($inventory?->cantidad_total_bandejas !== null ? (int) $inventory->cantidad_total_bandejas : null);
        $directJava = $configured ? (int) $count->cantidad_en_empresa : 0;
        $directTrays = $configured && $count->cantidad_en_empresa_bandejas !== null
            ? (int) $count->cantidad_en_empresa_bandejas
            : ($configured ? null : 0);
        $expectedJava = $configured
            ? (int) $count->cantidad_esperada
            : ($propertyJava !== null ? $propertyJava - $externalJava - $internalJava : null);
        $expectedTrays = $configured
            ? ($count->cantidad_esperada_bandejas !== null ? (int) $count->cantidad_esperada_bandejas : null)
            : ($propertyTrays !== null ? $propertyTrays - $externalTrays - $internalTrays : null);
        $differenceJava = $configured ? (int) $count->diferencia : null;
        $differenceTrays = $configured && $count->diferencia_bandejas !== null
            ? (int) $count->diferencia_bandejas
            : null;
        $truckJava = $detailed ? (int) $savedCounts->sum('cantidad_javas') : null;
        $truckTrays = $detailed ? (int) $savedCounts->sum('cantidad_bandejas') : null;
        $insideAvicolaJava = $directJava + $internalJava;
        $insideAvicolaTrays = $directTrays !== null ? $directTrays + $internalTrays : null;
        $accountedJava = $insideAvicolaJava + $externalJava;
        $accountedTrays = $insideAvicolaTrays !== null ? $insideAvicolaTrays + $externalTrays : null;
        $fleetChanged = $detailed && (
            $activeIds->diff($savedCounts->pluck('vehiculo_id')->map(fn (mixed $id): int => (int) $id))->isNotEmpty()
            || $savedCounts->pluck('vehiculo_id')->map(fn (mixed $id): int => (int) $id)->diff($activeIds)->isNotEmpty()
        );
        $stale = $detailed && (
            $propertyJava !== (int) $inventory?->cantidad_total
            || $propertyTrays !== ($inventory?->cantidad_total_bandejas !== null ? (int) $inventory->cantidad_total_bandejas : null)
            || $externalJava !== $currentExternalJava
            || $externalTrays !== $currentExternalTrays
            || $internalJava !== $currentInternalJava
            || $internalTrays !== $currentInternalTrays
            || $fleetChanged
        );

        return [
            'configured' => $configured,
            'detailed' => $detailed,
            'legacy' => $configured && ! $detailed,
            'stale' => $stale,
            'fleet_changed' => $fleetChanged,
            'journey_id' => $configured ? (int) $count->jornada_id : null,
            'counted_at' => $count?->contado_at?->toISOString(),
            'counted_by' => $count?->contador
                ? ['id' => (int) $count->contador->id, 'name' => $count->contador->nombre]
                : null,
            'local' => [
                'javas' => $detailed ? (int) $count->cantidad_en_local : null,
                'trays' => $detailed ? (int) $count->cantidad_en_local_bandejas : null,
            ],
            'trucks_total' => ['javas' => $truckJava, 'trays' => $truckTrays],
            'direct_total' => ['javas' => $directJava, 'trays' => $directTrays],
            'expected_direct' => ['javas' => $expectedJava, 'trays' => $expectedTrays],
            'difference' => ['javas' => $differenceJava, 'trays' => $differenceTrays],
            'external_clients' => ['javas' => $externalJava, 'trays' => $externalTrays],
            'internal_clients' => ['javas' => $internalJava, 'trays' => $internalTrays],
            'inside_avicola' => ['javas' => $insideAvicolaJava, 'trays' => $insideAvicolaTrays],
            'accounted_total' => ['javas' => $accountedJava, 'trays' => $accountedTrays],
            'property_total' => ['javas' => $propertyJava, 'trays' => $propertyTrays],
            'trucks' => $trucks->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDailyCount(?ConteoDiarioJava $count, string $asset): array
    {
        $isTray = $asset === 'trays';
        $quantityColumn = $isTray ? 'cantidad_en_empresa_bandejas' : 'cantidad_en_empresa';
        $expectedColumn = $isTray ? 'cantidad_esperada_bandejas' : 'cantidad_esperada';
        $differenceColumn = $isTray ? 'diferencia_bandejas' : 'diferencia';

        if (! $count || $count->{$quantityColumn} === null) {
            return [
                'configured' => false,
                'journey_id' => null,
                'quantity' => null,
                'expected' => null,
                'difference' => null,
                'missing' => null,
                'counted_at' => null,
            ];
        }

        return [
            'configured' => true,
            'journey_id' => (int) $count->jornada_id,
            'quantity' => (int) $count->{$quantityColumn},
            'expected' => (int) $count->{$expectedColumn},
            'difference' => (int) $count->{$differenceColumn},
            'missing' => max(0, -(int) $count->{$differenceColumn}),
            'counted_at' => $count->contado_at?->toISOString(),
        ];
    }
}
