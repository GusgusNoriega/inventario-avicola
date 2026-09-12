<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductDispatchJourneyReclassificationService
{
    public function __construct(private readonly FinancialAuditService $audit) {}

    /**
     * The caller holds the company lock and the surrounding transaction.
     * Keep a ticket together when its weighings span the newly configured cutoff.
     */
    public function reclassify(int $companyId, string $cutoff): int
    {
        $changed = 0;
        $storageTimezone = (string) config('app.timezone');

        DB::table('tickets_despacho_productos as ticket')
            ->join('sucursales as branch', 'branch.id', '=', 'ticket.sucursal_id')
            ->where('ticket.empresa_id', $companyId)
            ->where('branch.empresa_id', $companyId)
            ->select([
                'ticket.id',
                'ticket.fecha_operativa',
                'ticket.registrado_at',
                'ticket.updated_at',
                'branch.zona_horaria',
            ])
            ->selectSub(
                DB::table('pesadas_despacho_productos as weighing')
                    ->whereColumn('weighing.ticket_despacho_producto_id', 'ticket.id')
                    ->selectRaw('MIN(weighing.pesada_at)'),
                'first_weighed_at',
            )
            ->lockForUpdate()
            ->chunkById(500, function (Collection $tickets) use ($companyId, $cutoff, $storageTimezone, &$changed): void {
                foreach ($tickets as $ticket) {
                    $timezone = (string) ($ticket->zona_horaria ?: $storageTimezone);
                    // Weighings store the branch clock; registration uses the application clock.
                    $at = $ticket->first_weighed_at
                        ? CarbonImmutable::parse($ticket->first_weighed_at, $timezone)
                        : CarbonImmutable::parse($ticket->registrado_at, $storageTimezone)->setTimezone($timezone);
                    $cutoffAt = $at->startOfDay()->setTimeFromTimeString($cutoff);
                    $date = ($at->greaterThanOrEqualTo($cutoffAt) ? $at->addDay() : $at)->toDateString();

                    if ($date === (string) $ticket->fecha_operativa) {
                        continue;
                    }

                    $updatedAt = CarbonImmutable::now($storageTimezone)->startOfSecond();
                    if ($ticket->updated_at) {
                        $updatedAt = $updatedAt->max(CarbonImmutable::parse($ticket->updated_at, $storageTimezone)->addSecond());
                    }

                    DB::table('tickets_despacho_productos')
                        ->where('id', $ticket->id)
                        ->update(['fecha_operativa' => $date, 'updated_at' => $updatedAt]);
                    $this->audit->record($companyId, auth()->id(), 'tickets_despacho_productos', $ticket->id,
                        'RECALCULAR_JORNADA', ['fecha_operativa' => $ticket->fecha_operativa], ['fecha_operativa' => $date]);

                    // Preserve custom issue/due dates; only move dates inherited from the journey.
                    $documents = DB::table('comprobantes')
                        ->where('empresa_id', $companyId)
                        ->where('origen_clave', 'VENTA:TICKET_PRODUCTOS:'.$ticket->id)
                        ->where('tipo_documento', 'INTERNO')
                        ->where('origen_codigo', 'AUTOMATICO')
                        ->lockForUpdate()->get();
                    foreach ($documents as $document) {
                        $values = [];
                        foreach (['fecha_emision', 'fecha_vencimiento'] as $field) {
                            if ($document->{$field} === $ticket->fecha_operativa) {
                                $values[$field] = $date;
                            }
                        }
                        if ($values !== []) {
                            DB::table('comprobantes')->where('id', $document->id)
                                ->update([...$values, 'updated_at' => $updatedAt]);
                            $this->audit->record($companyId, auth()->id(), 'comprobantes', $document->id,
                                'RECALCULAR_JORNADA', array_intersect_key((array) $document, $values), $values);
                        }
                    }

                    $changed++;
                }
            }, 'ticket.id', 'id');

        return $changed;
    }
}
