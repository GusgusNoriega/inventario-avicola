<?php

namespace App\Services;

use App\Models\JornadaOperativa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JourneyReclassificationService
{
    public function __construct(private readonly FinancialAuditService $audit) {}

    /**
     * The caller holds the company row lock and the transaction for the entire rebuild.
     * Tickets remain indivisible, including voided weighings, so restoring one never
     * changes the identity or the operating date of its original ticket.
     *
     * @return array<string, int>
     */
    public function reclassify(int $companyId, string $cutoff, ?int $actorId = null): array
    {
        $branches = DB::table('sucursales')->where('empresa_id', $companyId)->get()->keyBy('id');
        $journeys = DB::table('jornadas_operativas')->whereIn('sucursal_id', $branches->keys())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $programState = $this->programSnapshot($branches->keys()->all());
        $byDate = [];
        $counts = ['tickets' => 0, 'journeys' => 0, 'java_movements' => 0, 'java_adjustments' => 0,
            'daily_counts' => 0, 'origin_links' => 0, 'documents' => 0];

        foreach ($journeys as $journey) {
            $byDate[$journey->sucursal_id.'|'.$journey->fecha_operativa] = (int) $journey->id;
            $end = CarbonImmutable::parse($journey->fecha_operativa, $branches[$journey->sucursal_id]->zona_horaria)
                ->setTimeFromTimeString($cutoff);
            $values = ['inicio_at' => $end->subDay()->format('Y-m-d H:i:s'),
                'cierre_programado_at' => $end->format('Y-m-d H:i:s')];
            if ($journey->inicio_at !== $values['inicio_at'] || $journey->cierre_programado_at !== $values['cierre_programado_at']) {
                $this->change($companyId, $actorId, 'jornadas_operativas', $journey, $values);
                $counts['journeys']++;
            }
        }

        $journeyFor = function (int $branchId, string $date, int $creatorId) use (&$byDate, &$journeys, &$counts, $branches, $cutoff, $companyId, $actorId): int {
            $key = $branchId.'|'.$date;
            if (isset($byDate[$key])) {
                return $byDate[$key];
            }
            abort_unless(isset($branches[$branchId]), 422, 'La sucursal no pertenece a esta empresa.');
            $end = CarbonImmutable::parse($date, $branches[$branchId]->zona_horaria)->setTimeFromTimeString($cutoff);
            $values = ['sucursal_id' => $branchId, 'fecha_operativa' => $date,
                'estado' => JornadaOperativa::STATUS_OPEN, 'abierta_por' => $actorId ?: $creatorId,
                'inicio_at' => $end->subDay()->format('Y-m-d H:i:s'),
                'cierre_programado_at' => $end->format('Y-m-d H:i:s')];
            $id = (int) DB::table('jornadas_operativas')->insertGetId($values);
            $journeys->put($id, (object) ['id' => $id, ...$values]);
            $byDate[$key] = $id;
            $counts['journeys']++;
            $this->audit->record($companyId, $actorId, 'jornadas_operativas', $id, 'RECALCULAR_JORNADA', null, $values);

            return $id;
        };

        $tickets = DB::table('tickets_despacho')->whereIn('jornada_id', $journeys->keys())
            ->select('tickets_despacho.*')
            ->selectSub(DB::table('pesadas')->selectRaw('MIN(pesada_at)')
                ->whereColumn('ticket_id', 'tickets_despacho.id'), 'first_weighed_at')
            ->orderBy('id')->lockForUpdate()->get();
        $ticketJourneys = [];
        $destinations = [];
        foreach ($tickets as $ticket) {
            $branch = $branches[$journeys[$ticket->jornada_id]->sucursal_id];
            $rawTime = $ticket->first_weighed_at ?: ($ticket->cerrado_at ?: $ticket->created_at);
            $date = $this->operatingDate($rawTime, $branch->zona_horaria, $cutoff, ! $ticket->first_weighed_at);
            $key = $branch->id.'|'.$date.'|'.mb_strtolower($ticket->codigo);
            if (isset($destinations[$key])) {
                throw ValidationException::withMessages(['cutoff' => "Los tickets #{$destinations[$key]} y #{$ticket->id} tienen el mismo código {$ticket->codigo} y quedarían en la jornada {$date}. Revisa esos códigos antes de cambiar el horario. No se guardó ningún cambio."]);
            }
            $destinations[$key] = $ticket->id;
            $ticketJourneys[$ticket->id] = $journeyFor((int) $branch->id, $date, (int) $ticket->created_by);
        }

        $movedTickets = $tickets->filter(fn ($ticket) => (int) $ticket->jornada_id !== $ticketJourneys[$ticket->id]);
        // Vacate keys first: two tickets may exchange journeys. Original codes are
        // restored before commit and are never changed in the stored audit history.
        foreach ($movedTickets as $ticket) {
            DB::table('tickets_despacho')->where('id', $ticket->id)
                ->update(['codigo' => '~RECALC-'.$ticket->id.'-'.bin2hex(random_bytes(4))]);
        }
        foreach ($movedTickets as $ticket) {
            $target = $ticketJourneys[$ticket->id];
            DB::table('tickets_despacho')->where('id', $ticket->id)
                ->update(['jornada_id' => $target, 'codigo' => $ticket->codigo, 'updated_at' => $this->nextVersion($ticket)]);
            $this->audit->record($companyId, $actorId, 'tickets_despacho', $ticket->id, 'RECALCULAR_JORNADA',
                ['jornada_id' => (int) $ticket->jornada_id], ['jornada_id' => $target]);
            $counts['tickets']++;
        }

        foreach ($tickets as $ticket) {
            $target = $journeys[$ticketJourneys[$ticket->id]];
            $counts['origin_links'] += $this->alignOrigins($companyId, $actorId, $ticket, $target, $programState);
            foreach (DB::table('comprobantes')->where('empresa_id', $companyId)
                ->where('origen_clave', 'VENTA:TICKET:'.$ticket->id)->where('tipo_documento', 'INTERNO')
                ->where('origen_codigo', 'AUTOMATICO')->lockForUpdate()->get() as $document) {
                $values = [];
                foreach (['fecha_emision', 'fecha_vencimiento'] as $field) {
                    if ($document->{$field} === $journeys[$ticket->jornada_id]->fecha_operativa && $document->{$field} !== $target->fecha_operativa) {
                        $values[$field] = $target->fecha_operativa;
                    }
                }
                if ($values !== []) {
                    $this->change($companyId, $actorId, 'comprobantes', $document, $values, true);
                    $counts['documents']++;
                }
            }
        }

        foreach (DB::table('movimientos_javas')->where('empresa_id', $companyId)->lockForUpdate()->get() as $movement) {
            if ($movement->pesada_recepcion_pollo_vivo_id) {
                continue; // Aligned with the receipt by the reception rebuild below.
            }
            $branch = $branches->get($movement->sucursal_id);
            if (! $branch) {
                continue;
            }
            $target = $ticketJourneys[$movement->ticket_despacho_id] ?? $journeyFor((int) $branch->id,
                $this->operatingDate($movement->fecha_movimiento, $branch->zona_horaria, $cutoff), (int) $movement->created_by);
            if ((int) $movement->jornada_id !== $target) {
                $this->change($companyId, $actorId, 'movimientos_javas', $movement, ['jornada_id' => $target], true);
                $counts['java_movements']++;
            }
        }
        foreach (DB::table('ajustes_saldos_javas')->where('empresa_id', $companyId)->lockForUpdate()->get() as $adjustment) {
            $branch = $branches[$adjustment->sucursal_id];
            $target = $journeyFor((int) $branch->id, $this->operatingDate($adjustment->created_at, $branch->zona_horaria, $cutoff, true), (int) $adjustment->created_by);
            if ((int) $adjustment->jornada_id !== $target) {
                $this->change($companyId, $actorId, 'ajustes_saldos_javas', $adjustment, ['jornada_id' => $target], true);
                $counts['java_adjustments']++;
            }
        }

        // A count is a physical snapshot, not an amount that can be summed with
        // another count. Refuse ambiguous merges instead of overwriting either.
        $dailyCounts = DB::table('conteos_diarios_javas')->where('empresa_id', $companyId)->lockForUpdate()->get();
        $countTargets = [];
        $countDestinations = [];
        foreach ($dailyCounts as $count) {
            $branch = $branches[$journeys[$count->jornada_id]->sucursal_id];
            $date = $this->operatingDate($count->contado_at, $branch->zona_horaria, $cutoff);
            $target = $journeyFor((int) $branch->id, $date, (int) $count->contado_por);
            if (isset($countDestinations[$target])) {
                throw ValidationException::withMessages(['cutoff' => "Los conteos de javas #{$countDestinations[$target]} y #{$count->id} quedarían en la misma jornada {$date}. Revisa los conteos antes de cambiar el horario. No se guardó ningún cambio."]);
            }
            $countDestinations[$target] = $count->id;
            $countTargets[$count->id] = $target;
        }
        // The mapping of timestamps to consecutive dates is monotonic. Moving
        // from the vacant end avoids temporary collisions with a count leaving it.
        $pending = $dailyCounts->filter(fn ($count) => (int) $count->jornada_id !== $countTargets[$count->id])->keyBy('id');
        $occupied = $dailyCounts->pluck('id', 'jornada_id')->all();
        while ($pending->isNotEmpty()) {
            $progress = false;
            foreach ($pending as $id => $count) {
                $target = $countTargets[$id];
                if (isset($occupied[$target])) {
                    continue;
                }
                $this->change($companyId, $actorId, 'conteos_diarios_javas', $count, ['jornada_id' => $target], true);
                unset($occupied[$count->jornada_id]);
                $occupied[$target] = $id;
                $pending->forget($id);
                $counts['daily_counts']++;
                $progress = true;
            }
            if (! $progress) {
                throw ValidationException::withMessages(['cutoff' => 'Los conteos de javas tienen jornadas incompatibles con sus fechas. Revisa sus fechas antes de recalcular. No se guardó ningún cambio.']);
            }
        }

        $receptions = app(LiveReceptionJourneyReclassificationService::class)->reclassify($companyId, $cutoff, $journeyFor, $ticketJourneys);
        $products = app(ProductDispatchJourneyReclassificationService::class)->reclassify($companyId, $cutoff);
        $synced = app(ReceptionSyncJourneyReclassificationService::class)->reclassify($companyId, $cutoff);
        if (array_sum($counts) + array_sum($receptions) + $products > 0) {
            DB::table('reception_sync_snapshots')->where('company_id', $companyId)
                ->where('expires_at', '>', now())->update(['expires_at' => now()->subSecond()]);
        }

        return [...$counts, ...$receptions, 'product_tickets' => $products, 'synced_tickets' => $synced];
    }

    private function operatingDate(string $value, string $timezone, string $cutoff, bool $databaseTime = false): string
    {
        $storageTimezone = config('database.connections.'.config('database.default').'.timezone') ?: config('app.timezone');
        $time = CarbonImmutable::parse($value, $databaseTime ? $storageTimezone : $timezone)->setTimezone($timezone);

        return ($time->format('H:i:s') >= $cutoff ? $time->addDay() : $time)->toDateString();
    }

    private function change(int $companyId, ?int $actorId, string $table, object $record, array $values, bool $timestamps = false): void
    {
        $before = array_intersect_key((array) $record, $values);
        DB::table($table)->where('id', $record->id)->update($timestamps ? [...$values, 'updated_at' => $this->nextVersion($record)] : $values);
        $this->audit->record($companyId, $actorId, $table, $record->id, 'RECALCULAR_JORNADA', $before, $values);
    }

    private function alignOrigins(int $companyId, ?int $actorId, object $ticket, object $journey, array &$programState): int
    {
        if ((int) $ticket->jornada_id !== (int) $journey->id) {
            $originalJourney = DB::table('jornadas_operativas')->where('id', $ticket->jornada_id)->first();
            $sourceProgram = $programState['original_programs_by_date'][$originalJourney->sucursal_id.'|'.$originalJourney->fecha_operativa] ?? null;
            if ($sourceProgram) {
                $this->programFor($sourceProgram, $journey, $programState);
            }
        }
        $records = DB::table('pesadas')->where('ticket_id', $ticket->id)
            ->whereNotNull('programacion_recepcion_detalle_id')->lockForUpdate()->get();
        $changed = 0;
        foreach ($records as $record) {
            $detail = $programState['original_details'][$record->programacion_recepcion_detalle_id] ?? null;
            $source = $detail ? ($programState['original_programs'][$detail->programacion_id] ?? null) : null;
            if (! $source || ($source->fecha_operativa === $journey->fecha_operativa && (int) $source->sucursal_id === (int) $journey->sucursal_id)) {
                continue;
            }
            $program = $this->programFor($source, $journey, $programState);
            $targetId = $this->detailFor($detail, $program, $programState);
            $this->change($companyId, $actorId, 'pesadas', $record, ['programacion_recepcion_detalle_id' => $targetId], true);
            $changed++;
        }

        return $changed;
    }

    /** Freeze source selections before destinations begin receiving copied records. */
    private function programSnapshot(array $branchIds): array
    {
        $programs = DB::table('programaciones_recepcion')->whereIn('sucursal_id', $branchIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $programsByDate = [];
        foreach ($programs as $program) {
            $programsByDate[$program->sucursal_id.'|'.$program->fecha_operativa] = $program;
        }
        $details = DB::table('programacion_recepcion_detalles')->whereIn('programacion_id', $programs->keys())
            ->orderBy('id')->lockForUpdate()->get();
        $warehouses = DB::table('programacion_recepcion_almacenes')->whereIn('programacion_id', $programs->keys())
            ->lockForUpdate()->get();

        return [
            'original_programs' => $programs->all(),
            'original_programs_by_date' => $programsByDate,
            'original_details' => $details->keyBy('id')->all(),
            'original_warehouses' => $warehouses->groupBy('programacion_id')->all(),
            'target_programs_by_date' => $programsByDate,
            'target_details' => $details->groupBy('programacion_id')->map(fn ($rows) => $rows->all())->all(),
            'detail_targets' => [],
        ];
    }

    private function programFor(object $source, object $journey, array &$programState): object
    {
        $key = $journey->sucursal_id.'|'.$journey->fecha_operativa;
        $program = $programState['target_programs_by_date'][$key] ?? null;
        if (! $program) {
            $values = (array) $source;
            unset($values['id']);
            $values['sucursal_id'] = $journey->sucursal_id;
            $values['fecha_operativa'] = $journey->fecha_operativa;
            $id = DB::table('programaciones_recepcion')->insertGetId($values);
            $program = (object) ['id' => $id, ...$values];
            $programState['target_programs_by_date'][$key] = $program;
        }
        foreach ($programState['original_warehouses'][$source->id] ?? [] as $warehouse) {
            DB::table('programacion_recepcion_almacenes')->insertOrIgnore([
                'programacion_id' => $program->id, 'almacen_id' => $warehouse->almacen_id,
                'created_at' => $warehouse->created_at, 'updated_at' => $warehouse->updated_at,
            ]);
        }

        return $program;
    }

    private function detailFor(object $source, object $program, array &$programState): int
    {
        $key = $source->id.'|'.$program->id;
        if (isset($programState['detail_targets'][$key])) {
            return $programState['detail_targets'][$key];
        }

        $ignored = array_fill_keys(['id', 'programacion_id', 'numero_visita', 'created_at', 'updated_at'], true);
        $sourceMetadata = array_diff_key((array) $source, $ignored);
        $numberOccupied = false;
        $largestNumber = 0;
        foreach ($programState['target_details'][$program->id] ?? [] as $candidate) {
            if ((int) $candidate->proveedor_vehiculo_id !== (int) $source->proveedor_vehiculo_id) {
                continue;
            }
            if (array_diff_key((array) $candidate, $ignored) === $sourceMetadata) {
                return $programState['detail_targets'][$key] = (int) $candidate->id;
            }
            $largestNumber = max($largestNumber, (int) $candidate->numero_visita);
            $numberOccupied = $numberOccupied || (int) $candidate->numero_visita === (int) $source->numero_visita;
        }

        $values = (array) $source;
        unset($values['id']);
        $values['programacion_id'] = (int) $program->id;
        if ($numberOccupied) {
            $values['numero_visita'] = $largestNumber + 1;
        }
        $id = (int) DB::table('programacion_recepcion_detalles')->insertGetId($values);
        $programState['target_details'][$program->id][] = (object) ['id' => $id, ...$values];

        return $programState['detail_targets'][$key] = $id;
    }

    private function nextVersion(object $record): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return isset($record->updated_at) ? $now->max(CarbonImmutable::parse($record->updated_at)->addSecond()) : $now;
    }
}
