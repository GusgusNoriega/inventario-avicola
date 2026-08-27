<?php

namespace App\Services;

use App\Models\InventarioJava;
use App\Models\MovimientoJava;
use App\Models\Pesada;
use App\Models\RecepcionPolloVivo;
use App\Models\RecepcionPolloVivoTicket;
use App\Models\TicketDespacho;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveChickenReceptionTicketInventoryService
{
    public function lockCompanyScope(int $companyId): void
    {
        // Acquire this mutex before inventarios_javas or movimientos_javas so
        // every live-reception path shares one lock order.
        abort_unless(
            DB::table('empresas')
                ->where('id', $companyId)
                ->lockForUpdate()
                ->first(['id']),
            404,
        );
        // empresa_id is unique, so InnoDB also protects the missing-row gap
        // until the first inventory row is created.
        DB::table('inventarios_javas')
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->first(['id']);
    }

    public function sync(
        int $companyId,
        User $actor,
        TicketDespacho $ticket,
        bool $forceRevision = false,
    ): ?RecepcionPolloVivoTicket {
        if ($ticket->modulo_origen !== TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION) {
            return null;
        }

        $ticket = TicketDespacho::query()
            ->with('jornada:id,sucursal_id')
            ->whereKey($ticket->id)
            ->lockForUpdate()
            ->firstOrFail();
        $branchBelongsToCompany = DB::table('sucursales')
            ->where('id', $ticket->jornada?->sucursal_id)
            ->where('empresa_id', $companyId)
            ->exists();
        abort_unless($branchBelongsToCompany, 404);

        $link = RecepcionPolloVivoTicket::query()
            ->where('ticket_despacho_id', $ticket->id)
            ->lockForUpdate()
            ->first();

        if (! $link) {
            throw ValidationException::withMessages([
                'ticket' => 'El ticket de recepción no tiene su vínculo operativo. No se aplicó ningún cambio.',
            ]);
        }

        $reception = RecepcionPolloVivo::query()->firstOrCreate(
            ['jornada_id' => $ticket->jornada_id],
            [
                'origen' => RecepcionPolloVivo::ORIGIN_DAILY_TRUCK,
                'estado' => RecepcionPolloVivo::STATUS_OPEN,
                'created_by' => $actor->id,
            ],
        );
        $records = Pesada::query()
            ->where('ticket_id', $ticket->id)
            ->where('estado', Pesada::STATUS_ACTIVE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $targetCages = $ticket->estado === TicketDespacho::STATUS_VOIDED
            ? 0
            : (int) $records->sum(fn (Pesada $record): int => (int) $record->cantidad_javas);
        $appliedCages = (int) $link->cantidad_javas_aplicada;

        if ($ticket->estado === TicketDespacho::STATUS_VOIDED && $appliedCages > 0) {
            $retainedDispatchCages = (int) MovimientoJava::query()
                ->where('empresa_id', $companyId)
                ->where('ticket_despacho_id', $ticket->id)
                ->where('tipo', MovimientoJava::TYPE_DISPATCH)
                ->lockForUpdate()
                ->value('cantidad');

            if ($retainedDispatchCages > 0) {
                throw ValidationException::withMessages([
                    'ticket' => 'No se puede anular este ingreso porque el cliente ya devolvió javas asociadas al despacho.',
                ]);
            }
        }

        $delta = $targetCages - $appliedCages;
        $inventory = InventarioJava::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->first();
        $currentTotal = (int) ($inventory?->cantidad_total ?? 0);
        $nextTotal = $currentTotal + $delta;

        if ($nextTotal < 0) {
            throw ValidationException::withMessages([
                'cages' => 'La corrección dejaría negativo el inventario general de javas.',
            ]);
        }

        $assignedToClients = $this->assignedCages($companyId);
        if ($nextTotal < $assignedToClients) {
            throw ValidationException::withMessages([
                'cages' => "No se puede reducir el ingreso: quedarían {$assignedToClients} javas con clientes y solo {$nextTotal} en el inventario general.",
            ]);
        }

        $changed = $forceRevision;
        if ($inventory) {
            if ($delta !== 0) {
                $inventory->update([
                    'cantidad_total' => $nextTotal,
                    'updated_by' => $actor->id,
                ]);
                $changed = true;
            }
        } elseif ($targetCages > 0) {
            InventarioJava::query()->create([
                'empresa_id' => $companyId,
                'cantidad_total' => $targetCages,
                'cantidad_total_bandejas' => null,
                'updated_by' => $actor->id,
            ]);
            $changed = true;
        }

        $movement = $link->movimiento_inventario_id
            ? DB::table('movimientos_inventario')
                ->where('id', $link->movimiento_inventario_id)
                ->lockForUpdate()
                ->first()
            : null;
        $movement ??= DB::table('movimientos_inventario')
            ->where('ticket_id', $ticket->id)
            ->where('tipo', 'DESPACHO_DIRECTO')
            ->lockForUpdate()
            ->first();
        $movementStatus = $ticket->estado === TicketDespacho::STATUS_VOIDED || $records->isEmpty()
            ? 'ANULADO'
            : 'CONFIRMADO';
        $movementDate = $ticket->cerrado_at ?: $ticket->created_at ?: now();
        $movementValues = [
            'sucursal_id' => (int) $ticket->jornada->sucursal_id,
            'ticket_id' => $ticket->id,
            'tipo' => 'DESPACHO_DIRECTO',
            'almacen_origen_id' => null,
            'almacen_destino_id' => null,
            'tercero_origen_id' => null,
            'tercero_destino_id' => $ticket->cliente_destino_id,
            'estado' => $movementStatus,
            'fecha_hora' => $movementDate,
            'updated_at' => now(),
        ];

        if (! $movement) {
            $movementId = DB::table('movimientos_inventario')->insertGetId([
                ...$movementValues,
                'confirmado_por' => $actor->id,
                'confirmado_at' => now(),
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
            $changed = true;
        } else {
            $movementId = (int) $movement->id;
            $headerChanged = (int) $movement->sucursal_id !== (int) $movementValues['sucursal_id']
                || (int) $movement->ticket_id !== (int) $ticket->id
                || (int) ($movement->tercero_destino_id ?? 0) !== (int) ($ticket->cliente_destino_id ?? 0)
                || (string) $movement->estado !== $movementStatus
                || (string) $movement->fecha_hora !== $movementDate->format('Y-m-d H:i:s');
            if ($headerChanged) {
                DB::table('movimientos_inventario')
                    ->where('id', $movementId)
                    ->update($movementValues);
                $changed = true;
            }
        }

        if ($ticket->estado !== TicketDespacho::STATUS_VOIDED && $records->isNotEmpty()) {
            $changed = $this->syncMovementDetails($movementId, $records) || $changed;
        }

        $linkChanges = [];
        if ((int) $link->recepcion_id !== (int) $reception->id) {
            $linkChanges['recepcion_id'] = $reception->id;
            $changed = true;
        }
        if ((int) ($link->movimiento_inventario_id ?? 0) !== $movementId) {
            $linkChanges['movimiento_inventario_id'] = $movementId;
            $changed = true;
        }
        if ($appliedCages !== $targetCages) {
            $linkChanges['cantidad_javas_aplicada'] = $targetCages;
            $changed = true;
        }
        if ($changed) {
            $linkChanges['revision'] = (int) $link->revision + 1;
            $link->update($linkChanges);
        }

        return $link->fresh();
    }

    /** @param Collection<int, Pesada> $records */
    private function syncMovementDetails(int $movementId, Collection $records): bool
    {
        $existing = DB::table('movimiento_detalles')
            ->where('movimiento_id', $movementId)
            ->whereNotNull('pesada_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('pesada_id');
        $activeIds = $records->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $changed = false;

        foreach ($records as $record) {
            $current = $existing->get((int) $record->id);
            $values = [
                'movimiento_id' => $movementId,
                'pesada_id' => $record->id,
                'pesada_recepcion_pollo_vivo_id' => null,
                'tipo_pollo_id' => $record->tipo_pollo_id,
                'cantidad_aves' => $record->cantidad_aves,
                'peso_neto_kg' => $record->peso_neto_kg,
            ];
            $detailChanged = ! $current
                || (int) $current->tipo_pollo_id !== (int) $record->tipo_pollo_id
                || (int) $current->cantidad_aves !== (int) $record->cantidad_aves
                || abs((float) $current->peso_neto_kg - (float) $record->peso_neto_kg) > 0.0005;

            if ($detailChanged) {
                DB::table('movimiento_detalles')->updateOrInsert(
                    ['pesada_id' => $record->id],
                    [...$values, 'created_at' => $current?->created_at ?? now()],
                );
                $changed = true;
            }
        }

        $staleQuery = DB::table('movimiento_detalles')
            ->where('movimiento_id', $movementId)
            ->whereNotNull('pesada_id');
        if ($activeIds->isNotEmpty()) {
            $staleQuery->whereNotIn('pesada_id', $activeIds);
        }
        if ($staleQuery->exists()) {
            $staleQuery->delete();
            $changed = true;
        }

        return $changed;
    }

    private function assignedCages(int $companyId): int
    {
        $balances = [];
        $movements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->get(['cliente_id', 'tipo', 'cantidad']);

        foreach ($movements as $movement) {
            $clientId = (int) $movement->cliente_id;
            $balances[$clientId] = ($balances[$clientId] ?? 0) + match ($movement->tipo) {
                MovimientoJava::TYPE_DISPATCH => (int) $movement->cantidad,
                MovimientoJava::TYPE_RECEIPT => -(int) $movement->cantidad,
                default => 0,
            };
        }

        foreach (DB::table('ajustes_saldos_javas')
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->get(['cliente_id', 'diferencia_javas']) as $adjustment) {
            $clientId = (int) $adjustment->cliente_id;
            $balances[$clientId] = ($balances[$clientId] ?? 0) + (int) $adjustment->diferencia_javas;
        }

        return (int) collect($balances)->filter(fn (int $balance): bool => $balance > 0)->sum();
    }
}
