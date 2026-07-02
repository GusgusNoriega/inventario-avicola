<?php

namespace App\Services;

use App\Models\Conductor;
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

        $quantity = (int) Pesada::query()
            ->where('ticket_id', $ticket->id)
            ->where('estado', Pesada::STATUS_ACTIVE)
            ->sum('cantidad_javas');
        $clientMovements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $ticket->cliente_destino_id)
            ->lockForUpdate()
            ->get(['tipo', 'cantidad', 'ticket_despacho_id']);
        $otherDispatches = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_DISPATCH)
            ->where('ticket_despacho_id', '!=', $ticket->id)
            ->sum('cantidad');
        $receipts = (int) $clientMovements
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->sum('cantidad');

        if ($quantity + $otherDispatches < $receipts) {
            throw ValidationException::withMessages([
                'cages' => 'No se puede reducir esta cantidad porque el cliente ya devolvió javas asociadas a su saldo.',
            ]);
        }

        if ($quantity === 0) {
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
                'cantidad' => $quantity,
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
            ->where('es_propio', true)
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
                ->get(['tipo', 'cantidad']);
            $balance = (int) $movements->sum(
                fn (MovimientoJava $movement): int => $movement->tipo === MovimientoJava::TYPE_DISPATCH
                    ? $movement->cantidad
                    : -$movement->cantidad
            );
            $quantity = (int) $data['quantity'];

            if ($quantity > $balance) {
                throw ValidationException::withMessages([
                    'quantity' => "El cliente solo tiene {$balance} javas pendientes. No se pueden recibir {$quantity}.",
                ]);
            }

            return MovimientoJava::query()->create([
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'jornada_id' => $journey->id,
                'cliente_id' => $client->id,
                'tipo' => MovimientoJava::TYPE_RECEIPT,
                'cantidad' => $quantity,
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
}
