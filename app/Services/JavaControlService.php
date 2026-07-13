<?php

namespace App\Services;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JavaControlService
{
    /** @return array<string, mixed> */
    public function currentInventory(int $companyId, ?int $journeyId = null): array
    {
        $outsideJavaQuantity = $this->outsideQuantity($companyId, 'cantidad');
        $outsideTrayQuantity = $this->outsideQuantity($companyId, 'cantidad_bandejas');
        $inventory = InventarioJava::query()
            ->where('empresa_id', $companyId)
            ->first();
        $dailyCount = $journeyId
            ? ConteoDiarioJava::query()
                ->where('empresa_id', $companyId)
                ->where('jornada_id', $journeyId)
                ->first()
            : null;

        $javas = $this->formatInventoryAsset(
            $inventory,
            'cantidad_total',
            $outsideJavaQuantity,
            $this->formatDailyCount($dailyCount, 'javas')
        );
        $trays = $this->formatInventoryAsset(
            $inventory,
            'cantidad_total_bandejas',
            $outsideTrayQuantity,
            $this->formatDailyCount($dailyCount, 'trays')
        );

        return [
            ...$javas,
            'javas' => $javas,
            'trays' => $trays,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveInventoryTotal(int $companyId, User $actor, array $data): array
    {
        return DB::transaction(function () use ($companyId, $actor, $data): array {
            $outsideJavaQuantity = $this->outsideQuantity($companyId, 'cantidad');
            $outsideTrayQuantity = $this->outsideQuantity($companyId, 'cantidad_bandejas');
            $javaQuantity = (int) $data['java_quantity'];
            $trayQuantity = array_key_exists('tray_quantity', $data)
                ? (int) $data['tray_quantity']
                : null;
            $javaField = array_key_exists('total_quantity', $data)
                ? 'total_quantity'
                : 'java_quantity';

            if ($javaQuantity < $outsideJavaQuantity) {
                throw ValidationException::withMessages([
                    $javaField => "El total general no puede ser menor que las {$outsideJavaQuantity} javas que estan fuera con clientes.",
                ]);
            }

            if ($trayQuantity !== null && $trayQuantity < $outsideTrayQuantity) {
                throw ValidationException::withMessages([
                    'tray_quantity' => "El total general no puede ser menor que las {$outsideTrayQuantity} bandejas que estan fuera con clientes.",
                ]);
            }

            $values = [
                'cantidad_total' => $javaQuantity,
                'updated_by' => $actor->id,
            ];
            if ($trayQuantity !== null) {
                $values['cantidad_total_bandejas'] = $trayQuantity;
            }

            $inventory = InventarioJava::query()->updateOrCreate(
                ['empresa_id' => $companyId],
                $values
            );

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

            $occurredAt = CarbonImmutable::now($timezone);
            $journey = $this->currentJourney(
                $companyId,
                $branchId,
                $timezone,
                $actor,
                $occurredAt
            );
            $outsideJavaQuantity = $this->outsideQuantity($companyId, 'cantidad');
            $outsideTrayQuantity = $this->outsideQuantity($companyId, 'cantidad_bandejas');
            $expectedJavaQuantity = (int) $inventory->cantidad_total - $outsideJavaQuantity;
            $countedJavaQuantity = (int) $data['java_quantity'];
            $hasTrayCount = array_key_exists('tray_quantity', $data);

            if ($hasTrayCount && $inventory->cantidad_total_bandejas === null) {
                throw ValidationException::withMessages([
                    'tray_quantity' => 'Primero debes definir el total general de bandejas de la empresa.',
                ]);
            }

            $values = [
                'empresa_id' => $companyId,
                'cantidad_en_empresa' => $countedJavaQuantity,
                'cantidad_esperada' => $expectedJavaQuantity,
                'diferencia' => $countedJavaQuantity - $expectedJavaQuantity,
                'contado_at' => $occurredAt->format('Y-m-d H:i:s'),
                'contado_por' => $actor->id,
            ];
            if ($hasTrayCount) {
                $expectedTrayQuantity = (int) $inventory->cantidad_total_bandejas - $outsideTrayQuantity;
                $countedTrayQuantity = (int) $data['tray_quantity'];
                $values += [
                    'cantidad_en_empresa_bandejas' => $countedTrayQuantity,
                    'cantidad_esperada_bandejas' => $expectedTrayQuantity,
                    'diferencia_bandejas' => $countedTrayQuantity - $expectedTrayQuantity,
                ];
            }

            ConteoDiarioJava::query()->updateOrCreate(
                ['jornada_id' => $journey->id],
                $values
            );

            return $this->currentInventory($companyId, (int) $journey->id);
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
        $javaQuantity = (int) ($quantities?->javas ?? 0);
        $trayQuantity = (int) ($quantities?->trays ?? 0);
        $clientMovements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $ticket->cliente_destino_id)
            ->lockForUpdate()
            ->get(['tipo', 'cantidad', 'cantidad_bandejas', 'ticket_despacho_id']);
        $otherJavaDispatches = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_DISPATCH)
            ->where('ticket_despacho_id', '!=', $ticket->id)
            ->sum('cantidad');
        $javaReceipts = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->sum('cantidad');
        $otherTrayDispatches = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_DISPATCH)
            ->where('ticket_despacho_id', '!=', $ticket->id)
            ->sum('cantidad_bandejas');
        $trayReceipts = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->sum('cantidad_bandejas');

        if ($javaQuantity + $otherJavaDispatches < $javaReceipts) {
            throw ValidationException::withMessages([
                'cages' => 'No se puede reducir esta cantidad porque el cliente ya devolvió javas asociadas a su saldo.',
            ]);
        }

        if ($trayQuantity + $otherTrayDispatches < $trayReceipts) {
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
                'observaciones' => null,
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
            $movements = MovimientoJava::query()
                ->where('empresa_id', $companyId)
                ->where('cliente_id', $client->id)
                ->lockForUpdate()
                ->get(['tipo', 'cantidad', 'cantidad_bandejas']);
            $javaBalance = (int) $movements->sum(
                fn (MovimientoJava $movement): int => $movement->tipo === MovimientoJava::TYPE_DISPATCH
                    ? $movement->cantidad
                    : -$movement->cantidad
            );
            $trayBalance = (int) $movements->sum(
                fn (MovimientoJava $movement): int => $movement->tipo === MovimientoJava::TYPE_DISPATCH
                    ? $movement->cantidad_bandejas
                    : -$movement->cantidad_bandejas
            );
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

    private function outsideQuantity(int $companyId, string $column): int
    {
        if (! in_array($column, ['cantidad', 'cantidad_bandejas'], true)) {
            throw new \InvalidArgumentException('Columna de inventario no soportada.');
        }

        $balance = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN tipo = 'DESPACHO' THEN {$column} ELSE -{$column} END), 0) AS balance"
            )
            ->value('balance');

        return max(0, (int) $balance);
    }

    /** @return array<string, mixed> */
    private function formatInventoryAsset(
        ?InventarioJava $inventory,
        string $column,
        int $outsideQuantity,
        array $dailyCount
    ): array {
        $total = $inventory?->{$column};
        $configured = $total !== null;

        return [
            'configured' => $configured,
            'total' => $configured ? (int) $total : null,
            'inside' => $configured ? (int) $total - $outsideQuantity : null,
            'outside' => $outsideQuantity,
            'updated_at' => $configured ? $inventory?->updated_at?->toISOString() : null,
            'daily_count' => $dailyCount,
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
