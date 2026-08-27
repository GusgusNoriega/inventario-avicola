<?php

namespace App\Services;

use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\TicketDespacho;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LiveChickenReceptionHistoryService
{
    public function __construct(
        private readonly JourneyPlanService $journeys,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function history(int $companyId, object $branch, array $filters): array
    {
        $window = $this->journeys->currentWindow($companyId, $branch);
        $journeys = $this->journeyRows($companyId, (int) $branch->id);
        $currentDate = $window['operating_date']->format('Y-m-d');
        $currentJourney = $journeys->first(
            fn (object $journey): bool => (string) $journey->operating_date === $currentDate,
        );
        $requestedJourneyId = isset($filters['journey_id'])
            ? (int) $filters['journey_id']
            : null;
        $selectedJourney = $requestedJourneyId
            ? $journeys->first(
                fn (object $journey): bool => (int) $journey->id === $requestedJourneyId,
            )
            : $journeys->first();

        if ($requestedJourneyId) {
            abort_unless($selectedJourney, 404, 'Jornada operativa no encontrada.');
        }

        $status = (string) ($filters['status'] ?? 'TODAS');
        $source = (string) ($filters['source'] ?? 'TODAS');
        $perPage = (int) ($filters['per_page'] ?? 30);
        $page = (int) ($filters['page'] ?? 1);
        $summary = $this->emptySummary();
        $records = [];
        $pagination = $this->emptyPagination($page, $perPage);

        if ($selectedJourney) {
            $allRecords = DB::query()->fromSub(
                $this->normalizedRows(
                    $companyId,
                    (int) $branch->id,
                    (int) $selectedJourney->id,
                ),
                'history_rows',
            );

            // Los indicadores muestran siempre la producción vigente y la
            // trazabilidad anulada completas de la jornada seleccionada.
            $summary = $this->summary(clone $allRecords);
            $filteredRecords = $this->applyFilters(clone $allRecords, $status, $source);
            $paginator = $filteredRecords
                ->orderByDesc('weighed_at')
                ->orderByDesc('source_rank')
                ->orderByDesc('weighing_id')
                ->paginate($perPage, ['*'], 'page', $page);
            $records = collect($paginator->items())
                ->map(fn (object $record): array => $this->formatRecord($record))
                ->all();
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        $formattedCurrentJourney = $currentJourney
            ? $this->formatJourney($currentJourney, true)
            : [
                'id' => null,
                'operating_date' => $currentDate,
                'status' => 'SIN_MOVIMIENTOS',
                'starts_at' => $window['starts_at']->toIso8601String(),
                'ends_at' => $window['ends_at']->toIso8601String(),
                'closed_at' => null,
                'is_current' => true,
            ];

        return [
            'branch' => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->nombre,
                'timezone' => (string) $branch->zona_horaria,
            ],
            'current_journey_id' => $currentJourney ? (int) $currentJourney->id : null,
            'current_operating_date' => $currentDate,
            'current_journey' => $formattedCurrentJourney,
            'selected_journey' => $selectedJourney
                ? $this->formatJourney(
                    $selectedJourney,
                    $currentJourney && (int) $currentJourney->id === (int) $selectedJourney->id,
                )
                : null,
            'is_current_journey' => $selectedJourney && $currentJourney
                ? (int) $selectedJourney->id === (int) $currentJourney->id
                : false,
            'catalog' => [
                'journeys' => $this->journeyCatalog($journeys, $currentDate),
            ],
            'applied_filters' => [
                'status' => $status,
                'source' => $source,
            ],
            'summary' => $summary,
            'records' => $records,
            'pagination' => $pagination,
        ];
    }

    /**
     * Build the complete, active-only dataset used by the journey report.
     *
     * @return array<string, mixed>
     */
    public function report(int $companyId, object $branch, int $journeyId): array
    {
        $window = $this->journeys->currentWindow($companyId, $branch);
        $journey = $this->journeyRows($companyId, (int) $branch->id)
            ->first(fn (object $row): bool => (int) $row->id === $journeyId);

        abort_unless($journey, 404, 'Jornada operativa no encontrada.');

        $activeRecords = DB::query()
            ->fromSub(
                $this->normalizedRows($companyId, (int) $branch->id, $journeyId),
                'report_rows',
            )
            ->where('effective_status', PesadaRecepcionPolloVivo::STATUS_ACTIVE)
            ->orderBy('weighed_at')
            ->orderBy('source_rank')
            ->orderBy('weighing_id')
            ->get();
        $summary = $this->reportSummary($activeRecords);
        $records = $activeRecords
            ->map(fn (object $record): array => $this->formatRecord($record))
            ->all();

        return [
            'branch' => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->nombre,
                'timezone' => (string) $branch->zona_horaria,
            ],
            'journey' => $this->formatJourney(
                $journey,
                (string) $journey->operating_date === $window['operating_date']->format('Y-m-d'),
            ),
            'records' => $records,
            'summary' => $summary,
            'generated_at' => CarbonImmutable::now((string) $branch->zona_horaria)->toISOString(),
        ];
    }

    /** @return Collection<int, object> */
    private function journeyRows(int $companyId, int $branchId): Collection
    {
        return DB::table('jornadas_operativas as journey')
            ->join('sucursales as branch', 'branch.id', '=', 'journey.sucursal_id')
            ->join('recepciones_pollo_vivo as reception', 'reception.jornada_id', '=', 'journey.id')
            ->where('branch.id', $branchId)
            ->where('branch.empresa_id', $companyId)
            ->orderByDesc('journey.fecha_operativa')
            ->orderByDesc('journey.id')
            ->get([
                'journey.id',
                'journey.fecha_operativa as operating_date',
                'journey.estado as status',
                'journey.inicio_at as starts_at',
                'journey.cierre_programado_at as ends_at',
                'journey.cerrada_at as closed_at',
            ]);
    }

    /**
     * @param  Collection<int, object>  $journeys
     * @return list<array<string, mixed>>
     */
    private function journeyCatalog(Collection $journeys, string $currentDate): array
    {
        return $journeys
            ->map(fn (object $journey): array => $this->formatJourney(
                $journey,
                (string) $journey->operating_date === $currentDate,
            ))
            ->sortByDesc('operating_date')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function formatJourney(object $journey, bool $isCurrent): array
    {
        return [
            'id' => (int) $journey->id,
            'operating_date' => (string) $journey->operating_date,
            'status' => (string) $journey->status,
            'starts_at' => $this->isoTimestamp($journey->starts_at),
            'ends_at' => $this->isoTimestamp($journey->ends_at),
            'closed_at' => $this->isoTimestamp($journey->closed_at),
            'is_current' => $isCurrent,
        ];
    }

    private function normalizedRows(int $companyId, int $branchId, int $journeyId): Builder
    {
        return $this->receptionRows($companyId, $branchId, $journeyId)
            ->unionAll($this->ticketRows($companyId, $branchId, $journeyId));
    }

    private function receptionRows(int $companyId, int $branchId, int $journeyId): Builder
    {
        return DB::table('pesadas_recepcion_pollo_vivo as weighing')
            ->join('recepciones_pollo_vivo as reception', 'reception.id', '=', 'weighing.recepcion_id')
            ->join('jornadas_operativas as journey', 'journey.id', '=', 'reception.jornada_id')
            ->join('sucursales as branch', 'branch.id', '=', 'journey.sucursal_id')
            ->leftJoin('terceros as external_owner', 'external_owner.id', '=', 'weighing.propietario_externo_id')
            ->leftJoin('almacenes as destination_warehouse', 'destination_warehouse.id', '=', 'weighing.almacen_destino_id')
            ->leftJoin('terceros as destination_client', 'destination_client.id', '=', 'weighing.cliente_destino_id')
            ->leftJoin('tipos_pollo as chicken_type', 'chicken_type.id', '=', 'weighing.tipo_pollo_id')
            ->leftJoin('tipos_java as cage_type', 'cage_type.id', '=', 'weighing.tipo_java_id')
            ->leftJoin('lecturas_balanza as scale_reading', 'scale_reading.id', '=', 'weighing.lectura_balanza_id')
            ->leftJoin('balanzas as scale', 'scale.id', '=', 'scale_reading.balanza_id')
            ->leftJoin('usuarios as creator', 'creator.id', '=', 'weighing.created_by')
            ->where('branch.id', $branchId)
            ->where('branch.empresa_id', $companyId)
            ->where('journey.id', $journeyId)
            ->select([
                DB::raw('1 as source_rank'),
                DB::raw("'RECEPCION' as source"),
                DB::raw("'RECEPTION_WEIGHING' as record_kind"),
                'weighing.id as weighing_id',
                'reception.id as reception_id',
                'reception.origen as reception_origin',
                'reception.estado as reception_status',
                'journey.id as journey_id',
                'journey.estado as journey_status',
                'journey.fecha_operativa as operating_date',
                'weighing.columna as source_lane',
                DB::raw("CASE WHEN weighing.destino_tipo = 'CLIENTE' AND weighing.columna IN (3, 4) THEN weighing.columna + 2 ELSE weighing.columna END as lane"),
                DB::raw("CASE WHEN weighing.destino_tipo = 'CLIENTE' OR (weighing.destino_tipo = 'ALMACEN' AND ((weighing.columna = 1 AND (weighing.propietario_tipo != 'PROPIA' OR weighing.sexo != 'MACHO')) OR (weighing.columna = 2 AND (weighing.propietario_tipo != 'PROPIA' OR weighing.sexo != 'HEMBRA')) OR (weighing.columna = 3 AND (weighing.propietario_tipo != 'EXTERNA' OR weighing.sexo != 'MACHO')) OR (weighing.columna = 4 AND (weighing.propietario_tipo != 'EXTERNA' OR weighing.sexo != 'HEMBRA')))) THEN 1 ELSE 0 END as uses_previous_layout"),
                'weighing.numero as number',
                DB::raw("CASE WHEN weighing.estado = 'ANULADA' THEN 'ANULADA' ELSE 'ACTIVA' END as effective_status"),
                'weighing.estado as weighing_status',
                DB::raw('NULL as ticket_id'),
                DB::raw('NULL as ticket_code'),
                DB::raw('NULL as ticket_status'),
                DB::raw('NULL as ticket_registered_at'),
                'weighing.propietario_tipo as owner_type',
                'weighing.propietario_externo_id as owner_id',
                DB::raw("CASE WHEN weighing.propietario_tipo = 'PROPIA' THEN 'Mi empresa' ELSE COALESCE(external_owner.nombre_razon_social, 'Empresa externa') END as owner_name"),
                'weighing.destino_tipo as destination_type',
                DB::raw('COALESCE(weighing.almacen_destino_id, weighing.cliente_destino_id) as destination_id'),
                DB::raw('COALESCE(destination_warehouse.nombre, destination_client.nombre_razon_social) as destination_name'),
                'weighing.sexo as sex',
                'weighing.tipo_pollo_id as chicken_type_id',
                'chicken_type.codigo as chicken_type_code',
                'chicken_type.nombre as chicken_type_name',
                'weighing.tipo_java_id as cage_type_id',
                'cage_type.codigo as cage_type_code',
                'cage_type.nombre as cage_type_name',
                'weighing.peso_java_kg_snapshot as cage_weight_kg',
                'weighing.lectura_balanza_id as scale_reading_id',
                DB::raw("CASE WHEN weighing.origen_peso = 'MANUAL' THEN 'MANUAL' ELSE COALESCE(scale.codigo, weighing.origen_peso) END as weight_source"),
                'weighing.aves_por_java as birds_per_cage',
                'weighing.cantidad_javas as cages',
                'weighing.cantidad_aves as birds',
                'weighing.peso_leido_kg as read_weight_kg',
                'weighing.peso_bruto_kg as gross_weight_kg',
                'weighing.tara_total_kg as tare_weight_kg',
                'weighing.peso_neto_kg as net_weight_kg',
                'weighing.pesada_at as weighed_at',
                'weighing.anulada_at as voided_at',
                'weighing.motivo_anulacion as void_reason',
                'weighing.created_by as created_by_id',
                'creator.nombre as created_by_name',
                'weighing.updated_at as updated_at',
                DB::raw('NULL as delivery_vehicle_id'),
                DB::raw('NULL as delivery_plate'),
                DB::raw('NULL as delivery_driver_id'),
                DB::raw('NULL as delivery_driver_name'),
            ]);
    }

    private function ticketRows(int $companyId, int $branchId, int $journeyId): Builder
    {
        return DB::table('pesadas as weighing')
            ->join('tickets_despacho as ticket', 'ticket.id', '=', 'weighing.ticket_id')
            ->join('recepcion_pollo_vivo_tickets as reception_link', 'reception_link.ticket_despacho_id', '=', 'ticket.id')
            ->join('recepciones_pollo_vivo as reception', 'reception.id', '=', 'reception_link.recepcion_id')
            ->join('jornadas_operativas as journey', 'journey.id', '=', 'reception.jornada_id')
            ->join('sucursales as branch', 'branch.id', '=', 'journey.sucursal_id')
            ->leftJoin('terceros as destination_client', 'destination_client.id', '=', 'ticket.cliente_destino_id')
            ->leftJoin('almacenes as destination_warehouse', 'destination_warehouse.id', '=', 'ticket.almacen_destino_id')
            ->leftJoin('tipos_pollo as chicken_type', 'chicken_type.id', '=', 'weighing.tipo_pollo_id')
            ->leftJoin('tipos_java as cage_type', 'cage_type.id', '=', 'weighing.tipo_java_id')
            ->leftJoin('lecturas_balanza as scale_reading', 'scale_reading.id', '=', 'weighing.lectura_balanza_id')
            ->leftJoin('balanzas as scale', 'scale.id', '=', 'scale_reading.balanza_id')
            ->leftJoin('vehiculos as delivery_vehicle', 'delivery_vehicle.id', '=', 'ticket.vehiculo_entrega_id')
            ->leftJoin('conductores as delivery_driver', 'delivery_driver.id', '=', 'ticket.conductor_entrega_id')
            ->leftJoin('usuarios as creator', 'creator.id', '=', 'weighing.created_by')
            ->where('branch.id', $branchId)
            ->where('branch.empresa_id', $companyId)
            ->where('journey.id', $journeyId)
            ->where('ticket.modulo_origen', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION)
            ->whereColumn('ticket.jornada_id', 'journey.id')
            ->select([
                DB::raw('2 as source_rank'),
                DB::raw("'TICKET' as source"),
                DB::raw("'DISPATCH_TICKET_WEIGHING' as record_kind"),
                'weighing.id as weighing_id',
                'reception.id as reception_id',
                'reception.origen as reception_origin',
                'reception.estado as reception_status',
                'journey.id as journey_id',
                'journey.estado as journey_status',
                'journey.fecha_operativa as operating_date',
                'reception_link.columna as source_lane',
                'reception_link.columna as lane',
                DB::raw('0 as uses_previous_layout'),
                'weighing.numero as number',
                DB::raw("CASE WHEN weighing.estado = 'ANULADA' OR ticket.estado = 'ANULADO' THEN 'ANULADA' ELSE 'ACTIVA' END as effective_status"),
                'weighing.estado as weighing_status',
                'ticket.id as ticket_id',
                'ticket.codigo as ticket_code',
                'ticket.estado as ticket_status',
                'ticket.cerrado_at as ticket_registered_at',
                DB::raw("'PROPIA' as owner_type"),
                DB::raw('NULL as owner_id'),
                DB::raw("'Mi empresa' as owner_name"),
                DB::raw("CASE WHEN ticket.cliente_destino_id IS NOT NULL THEN 'CLIENTE' ELSE 'ALMACEN' END as destination_type"),
                DB::raw('COALESCE(ticket.cliente_destino_id, ticket.almacen_destino_id) as destination_id'),
                DB::raw('COALESCE(destination_client.nombre_razon_social, destination_warehouse.nombre) as destination_name'),
                'weighing.sexo as sex',
                'weighing.tipo_pollo_id as chicken_type_id',
                'chicken_type.codigo as chicken_type_code',
                'chicken_type.nombre as chicken_type_name',
                'weighing.tipo_java_id as cage_type_id',
                'cage_type.codigo as cage_type_code',
                'cage_type.nombre as cage_type_name',
                'weighing.peso_java_kg_snapshot as cage_weight_kg',
                'weighing.lectura_balanza_id as scale_reading_id',
                DB::raw("CASE WHEN weighing.origen_peso = 'MANUAL' THEN 'MANUAL' ELSE COALESCE(scale.codigo, weighing.origen_peso) END as weight_source"),
                'weighing.aves_por_java as birds_per_cage',
                'weighing.cantidad_javas as cages',
                'weighing.cantidad_aves as birds',
                'weighing.peso_leido_kg as read_weight_kg',
                'weighing.peso_bruto_kg as gross_weight_kg',
                'weighing.tara_total_kg as tare_weight_kg',
                'weighing.peso_neto_kg as net_weight_kg',
                'weighing.pesada_at as weighed_at',
                DB::raw('COALESCE(weighing.anulada_at, ticket.anulado_at) as voided_at'),
                DB::raw('COALESCE(weighing.motivo_anulacion, ticket.motivo_anulacion) as void_reason'),
                'weighing.created_by as created_by_id',
                'creator.nombre as created_by_name',
                'weighing.updated_at as updated_at',
                'ticket.vehiculo_entrega_id as delivery_vehicle_id',
                'delivery_vehicle.placa as delivery_plate',
                'ticket.conductor_entrega_id as delivery_driver_id',
                'delivery_driver.nombre_completo as delivery_driver_name',
            ]);
    }

    private function applyFilters(Builder $query, string $status, string $source): Builder
    {
        return $query
            ->when($status !== 'TODAS', fn (Builder $records) => $records
                ->where('effective_status', $status))
            ->when($source !== 'TODAS', fn (Builder $records) => $records
                ->where('source', $source));
    }

    /** @return array<string, mixed> */
    private function summary(Builder $query): array
    {
        $rows = $query
            ->select('effective_status')
            ->selectRaw('COUNT(*) as weighings')
            ->selectRaw('COALESCE(SUM(cages), 0) as cages')
            ->selectRaw('COALESCE(SUM(birds), 0) as birds')
            ->selectRaw('COALESCE(SUM(read_weight_kg), 0) as read_weight_kg')
            ->selectRaw('COALESCE(SUM(gross_weight_kg), 0) as gross_weight_kg')
            ->selectRaw('COALESCE(SUM(tare_weight_kg), 0) as tare_weight_kg')
            ->selectRaw('COALESCE(SUM(net_weight_kg), 0) as net_weight_kg')
            ->groupBy('effective_status')
            ->get()
            ->keyBy('effective_status');
        $active = $this->formatSummary($rows->get(PesadaRecepcionPolloVivo::STATUS_ACTIVE));
        $voided = $this->formatSummary($rows->get(PesadaRecepcionPolloVivo::STATUS_VOIDED));

        return [
            'active' => $active,
            'voided' => $voided,
            'total' => $this->addSummaries($active, $voided),
        ];
    }

    /**
     * @param  Collection<int, object>  $records
     * @return array<string, array<string, int|float>>
     */
    private function reportSummary(Collection $records): array
    {
        $own = $this->summarizeRecords($records->where(
            'owner_type',
            PesadaRecepcionPolloVivo::OWNER_OWN,
        ));
        $external = $this->summarizeRecords($records->where(
            'owner_type',
            PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
        ));

        return [
            'own' => $own,
            'external' => $external,
            'total' => $this->addSummaries($own, $external),
        ];
    }

    /**
     * @param  Collection<int, object>  $records
     * @return array<string, int|float>
     */
    private function summarizeRecords(Collection $records): array
    {
        return [
            ...$this->formatSummary((object) [
                'weighings' => $records->count(),
                'cages' => $records->sum('cages'),
                'birds' => $records->sum('birds'),
                'read_weight_kg' => $records->sum('read_weight_kg'),
                'gross_weight_kg' => $records->sum('gross_weight_kg'),
                'tare_weight_kg' => $records->sum('tare_weight_kg'),
                'net_weight_kg' => $records->sum('net_weight_kg'),
            ]),
            'male_birds' => (int) $records
                ->whereStrict('sex', Pesada::SEX_MALE)
                ->sum('birds'),
            'female_birds' => (int) $records
                ->whereStrict('sex', Pesada::SEX_FEMALE)
                ->sum('birds'),
        ];
    }

    /** @return array<string, int|float> */
    private function formatSummary(?object $row): array
    {
        $birds = (int) ($row?->birds ?? 0);
        $netWeight = round((float) ($row?->net_weight_kg ?? 0), 3);

        return [
            'weighings' => (int) ($row?->weighings ?? 0),
            'cages' => (int) ($row?->cages ?? 0),
            'birds' => $birds,
            'read_weight_kg' => round((float) ($row?->read_weight_kg ?? 0), 3),
            'gross_weight_kg' => round((float) ($row?->gross_weight_kg ?? 0), 3),
            'tare_weight_kg' => round((float) ($row?->tare_weight_kg ?? 0), 3),
            'net_weight_kg' => $netWeight,
            'average_weight_per_bird_kg' => $birds > 0
                ? round($netWeight / $birds, 3)
                : 0.0,
        ];
    }

    /**
     * @param  array<string, int|float>  $left
     * @param  array<string, int|float>  $right
     * @return array<string, int|float>
     */
    private function addSummaries(array $left, array $right): array
    {
        $birds = (int) $left['birds'] + (int) $right['birds'];
        $netWeight = round(
            (float) $left['net_weight_kg'] + (float) $right['net_weight_kg'],
            3,
        );

        $summary = [
            'weighings' => (int) $left['weighings'] + (int) $right['weighings'],
            'cages' => (int) $left['cages'] + (int) $right['cages'],
            'birds' => $birds,
            'read_weight_kg' => round(
                (float) $left['read_weight_kg'] + (float) $right['read_weight_kg'],
                3,
            ),
            'gross_weight_kg' => round(
                (float) $left['gross_weight_kg'] + (float) $right['gross_weight_kg'],
                3,
            ),
            'tare_weight_kg' => round(
                (float) $left['tare_weight_kg'] + (float) $right['tare_weight_kg'],
                3,
            ),
            'net_weight_kg' => $netWeight,
            'average_weight_per_bird_kg' => $birds > 0
                ? round($netWeight / $birds, 3)
                : 0.0,
        ];

        if (array_key_exists('male_birds', $left) || array_key_exists('male_birds', $right)) {
            $summary['male_birds'] = (int) ($left['male_birds'] ?? 0)
                + (int) ($right['male_birds'] ?? 0);
            $summary['female_birds'] = (int) ($left['female_birds'] ?? 0)
                + (int) ($right['female_birds'] ?? 0);
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    private function formatRecord(object $record): array
    {
        $isTicket = $record->source === 'TICKET';

        return [
            'id' => (int) $record->weighing_id,
            'row_key' => $isTicket
                ? "ticket-weighing:{$record->weighing_id}"
                : "reception-weighing:{$record->weighing_id}",
            'record_kind' => (string) $record->record_kind,
            'source' => (string) $record->source,
            'status' => (string) $record->effective_status,
            'weighing_status' => (string) $record->weighing_status,
            'number' => (int) $record->number,
            'lane' => (int) $record->lane,
            'source_lane' => (int) $record->source_lane,
            'uses_previous_layout' => (bool) $record->uses_previous_layout,
            'journey' => [
                'id' => (int) $record->journey_id,
                'operating_date' => (string) $record->operating_date,
                'status' => (string) $record->journey_status,
            ],
            'reception' => [
                'id' => (int) $record->reception_id,
                'origin' => (string) $record->reception_origin,
                'status' => (string) $record->reception_status,
            ],
            'ticket' => $isTicket ? [
                'id' => (int) $record->ticket_id,
                'code' => (string) $record->ticket_code,
                'status' => (string) $record->ticket_status,
                'registered_at' => $this->isoTimestamp($record->ticket_registered_at),
                'delivery' => $record->delivery_vehicle_id || $record->delivery_driver_id
                    ? [
                        'vehicle' => $record->delivery_vehicle_id ? [
                            'id' => (int) $record->delivery_vehicle_id,
                            'plate' => $record->delivery_plate,
                        ] : null,
                        'driver' => $record->delivery_driver_id ? [
                            'id' => (int) $record->delivery_driver_id,
                            'name' => $record->delivery_driver_name,
                        ] : null,
                    ]
                    : null,
            ] : null,
            'owner' => [
                'type' => (string) $record->owner_type,
                'id' => $record->owner_id === null ? null : (int) $record->owner_id,
                'name' => (string) $record->owner_name,
            ],
            'destination' => [
                'type' => (string) $record->destination_type,
                'id' => $record->destination_id === null ? null : (int) $record->destination_id,
                'name' => $record->destination_name,
            ],
            'sex' => (string) $record->sex,
            'chicken_type' => [
                'id' => (int) $record->chicken_type_id,
                'code' => $record->chicken_type_code,
                'name' => $record->chicken_type_name,
            ],
            'cage_type' => [
                'id' => (int) $record->cage_type_id,
                'code' => $record->cage_type_code,
                'name' => $record->cage_type_name,
                'weight_kg' => (float) $record->cage_weight_kg,
            ],
            'birds_per_cage' => (int) $record->birds_per_cage,
            'cages' => (int) $record->cages,
            'birds' => (int) $record->birds,
            'read_weight_kg' => (float) $record->read_weight_kg,
            'gross_weight_kg' => (float) $record->gross_weight_kg,
            'tare_weight_kg' => (float) $record->tare_weight_kg,
            'net_weight_kg' => (float) $record->net_weight_kg,
            'average_weight_per_bird_kg' => (int) $record->birds > 0
                ? round((float) $record->net_weight_kg / (int) $record->birds, 3)
                : 0.0,
            'weight_source' => (string) $record->weight_source,
            'scale_reading_id' => $record->scale_reading_id === null
                ? null
                : (int) $record->scale_reading_id,
            'weighed_at' => $this->isoTimestamp($record->weighed_at),
            'voided_at' => $this->isoTimestamp($record->voided_at),
            'void_reason' => $record->void_reason,
            'created_by' => [
                'id' => (int) $record->created_by_id,
                'name' => $record->created_by_name,
            ],
            'updated_at' => $this->isoTimestamp($record->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        $empty = $this->formatSummary(null);

        return [
            'active' => $empty,
            'voided' => $empty,
            'total' => $empty,
        ];
    }

    /** @return array<string, int|null> */
    private function emptyPagination(int $page, int $perPage): array
    {
        return [
            'current_page' => $page,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
    }

    private function isoTimestamp(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toISOString();
    }
}
