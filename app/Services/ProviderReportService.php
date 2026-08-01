<?php

namespace App\Services;

use App\Models\JornadaOperativa;
use App\Models\Pesada;
use App\Models\TicketDespacho;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProviderReportService
{
    private const TRUCK_WITHOUT_PLATE = 'SIN_PLACA';

    public function __construct(
        private readonly JourneyPlanService $journeys,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(int $companyId, object $branch, array $filters): array
    {
        $currentWindow = $this->journeys->currentWindow($companyId, $branch);
        $currentDate = $currentWindow['operating_date']->format('Y-m-d');
        $selectedDate = (string) ($filters['jornada'] ?? $currentDate);
        $providerId = isset($filters['proveedor_id']) ? (int) $filters['proveedor_id'] : null;
        $truckFilter = $this->normalizeTruckFilter($filters['camion'] ?? null, $providerId);
        $perPage = (int) ($filters['per_page'] ?? 30);

        $dateRecords = $this->recordsForDate(
            $companyId,
            (int) $branch->id,
            $selectedDate,
        );
        $filteredRecords = $dateRecords
            ->when(
                $providerId,
                fn (Collection $records) => $records->where('proveedor_origen_id', $providerId),
            )
            ->when(
                $truckFilter,
                fn (Collection $records) => $records->filter(
                    fn (Pesada $record): bool => $this->matchesTruckFilter($record, $truckFilter)
                ),
            )
            ->values();
        $total = $filteredRecords->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min((int) ($filters['page'] ?? 1), $lastPage);
        $pageRecords = $filteredRecords
            ->slice(($currentPage - 1) * $perPage, $perPage)
            ->values();

        return [
            'branch' => [
                'id' => (int) $branch->id,
                'code' => (string) $branch->codigo,
                'name' => (string) $branch->nombre,
                'timezone' => (string) $branch->zona_horaria,
            ],
            'current_operating_date' => $currentDate,
            'current_window' => [
                'starts_at' => $currentWindow['starts_at']->toIso8601String(),
                'ends_at' => $currentWindow['ends_at']->toIso8601String(),
                'cutoff' => substr($currentWindow['cutoff'], 0, 5),
            ],
            'selected_operating_date' => $selectedDate,
            'is_current_journey' => $selectedDate === $currentDate,
            'selected_journey' => $this->selectedJourney((int) $branch->id, $selectedDate),
            'generated_at' => now((string) $branch->zona_horaria)->toISOString(),
            'catalog' => [
                'journeys' => $this->journeyCatalog((int) $branch->id, $currentDate, $selectedDate),
                'providers' => $this->providerCatalog($dateRecords),
                'trucks' => $this->truckCatalog($dateRecords),
            ],
            'applied_filters' => [
                'provider_id' => $providerId,
                'truck' => $truckFilter,
            ],
            'summary' => $this->summary($filteredRecords),
            'records' => $pageRecords
                ->map(fn (Pesada $record): array => $this->formatRecord($record))
                ->all(),
            'pagination' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : (($currentPage - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($currentPage * $perPage, $total),
            ],
        ];
    }

    /**
     * @return Collection<int, Pesada>
     */
    private function recordsForDate(int $companyId, int $branchId, string $date): Collection
    {
        return Pesada::query()
            ->whereNotNull('proveedor_origen_id')
            ->where('estado', Pesada::STATUS_ACTIVE)
            ->whereHas(
                'proveedorOrigen',
                fn (Builder $query) => $query->where('empresa_id', $companyId),
            )
            ->whereHas('ticket', fn (Builder $query) => $query
                ->where('estado', '!=', TicketDespacho::STATUS_VOIDED)
                ->where('tipo_operacion', TicketDespacho::OPERATION_DISPATCH)
                ->whereHas('jornada', fn (Builder $journey) => $journey
                    ->where('sucursal_id', $branchId)
                    ->whereDate('fecha_operativa', $date)))
            ->with([
                'proveedorOrigen:id,tipo_documento,numero_documento,nombre_razon_social',
                'vehiculo:id,placa,marca,modelo',
                'tipoPollo:id,codigo,nombre',
                'tipoJava:id,codigo,nombre',
                'ticket:id,jornada_id,codigo,cliente_destino_id,almacen_destino_id,estado',
                'ticket.jornada:id,fecha_operativa,estado',
                'ticket.clienteDestino:id,nombre_razon_social',
                'ticket.almacenDestino:id,codigo,nombre',
            ])
            ->orderByDesc('pesada_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedJourney(int $branchId, string $date): array
    {
        $journey = JornadaOperativa::query()
            ->where('sucursal_id', $branchId)
            ->whereDate('fecha_operativa', $date)
            ->first();

        return [
            'id' => $journey?->id,
            'date' => $date,
            'status' => $journey?->estado ?? 'SIN_MOVIMIENTOS',
            'exists' => (bool) $journey,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journeyCatalog(int $branchId, string $currentDate, string $selectedDate): array
    {
        $journeys = JornadaOperativa::query()
            ->where('sucursal_id', $branchId)
            ->orderByDesc('fecha_operativa')
            ->limit(180)
            ->get(['id', 'fecha_operativa', 'estado'])
            ->map(fn (JornadaOperativa $journey): array => [
                'id' => (int) $journey->id,
                'date' => $journey->fecha_operativa?->format('Y-m-d'),
                'status' => $journey->estado,
                'exists' => true,
            ]);

        foreach ([$currentDate, $selectedDate] as $date) {
            if (! $journeys->contains('date', $date)) {
                $journeys->push([
                    'id' => null,
                    'date' => $date,
                    'status' => 'SIN_MOVIMIENTOS',
                    'exists' => false,
                ]);
            }
        }

        return $journeys
            ->sortByDesc('date')
            ->unique('date')
            ->values()
            ->map(fn (array $journey): array => [
                ...$journey,
                'is_current' => $journey['date'] === $currentDate,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Pesada>  $records
     * @return list<array<string, mixed>>
     */
    private function providerCatalog(Collection $records): array
    {
        return $records
            ->map(fn (Pesada $record): array => [
                'id' => (int) $record->proveedorOrigen->id,
                'name' => $record->proveedorOrigen->nombre_razon_social,
                'document' => $record->proveedorOrigen->numero_documento,
            ])
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Pesada>  $records
     * @return list<array<string, mixed>>
     */
    private function truckCatalog(Collection $records): array
    {
        return $records
            ->map(fn (Pesada $record): array => [
                'value' => $this->truckOptionValue($record),
                'vehicle_id' => $record->vehiculo_id ? (int) $record->vehiculo_id : null,
                'plate' => $this->truckPlate($record),
                'provider_id' => (int) $record->proveedor_origen_id,
                'provider_name' => $record->proveedorOrigen->nombre_razon_social,
            ])
            ->unique('value')
            ->sortBy(
                fn (array $truck): string => mb_strtolower(
                    $truck['provider_name'].'|'.$truck['plate'],
                    'UTF-8',
                ),
                SORT_NATURAL,
            )
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Pesada>  $records
     * @return array<string, mixed>
     */
    private function summary(Collection $records): array
    {
        $birds = (int) $records->sum('cantidad_aves');
        $netWeight = round((float) $records->sum('peso_neto_kg'), 3);
        $truckGroups = $records->groupBy(fn (Pesada $record): string => $this->truckKey($record));
        $destinationGroups = $records->groupBy(
            fn (Pesada $record): string => $this->destinationKey($record)
        );

        return [
            'records' => $records->count(),
            'tickets' => $records->pluck('ticket_id')->unique()->count(),
            'providers' => $records->pluck('proveedor_origen_id')->unique()->count(),
            'trucks' => $truckGroups->count(),
            'destinations' => $destinationGroups->count(),
            'cages' => (int) $records->sum('cantidad_javas'),
            'birds' => $birds,
            'gross_weight_kg' => round((float) $records->sum('peso_bruto_kg'), 3),
            'tare_weight_kg' => round((float) $records->sum('tara_total_kg'), 3),
            'net_weight_kg' => $netWeight,
            'average_weight_per_bird_kg' => $birds > 0 ? round($netWeight / $birds, 3) : 0,
            'by_truck' => $truckGroups
                ->map(fn (Collection $items): array => $this->summarizeTruck($items))
                ->sortByDesc('cages')
                ->values()
                ->all(),
            'by_destination' => $destinationGroups
                ->map(fn (Collection $items): array => $this->summarizeDestination($items))
                ->sortByDesc('cages')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Pesada>  $records
     * @return array<string, mixed>
     */
    private function summarizeTruck(Collection $records): array
    {
        /** @var Pesada $first */
        $first = $records->first();
        $birds = (int) $records->sum('cantidad_aves');
        $netWeight = round((float) $records->sum('peso_neto_kg'), 3);

        return [
            'provider' => [
                'id' => (int) $first->proveedorOrigen->id,
                'name' => $first->proveedorOrigen->nombre_razon_social,
            ],
            'truck' => [
                'vehicle_id' => $first->vehiculo_id ? (int) $first->vehiculo_id : null,
                'plate' => $this->truckPlate($first),
            ],
            'records' => $records->count(),
            'tickets' => $records->pluck('ticket_id')->unique()->count(),
            'destinations' => $records
                ->map(fn (Pesada $record): array => $this->destination($record))
                ->unique(fn (array $destination): string => $destination['type'].'|'.$destination['id'].'|'.$destination['name'])
                ->values()
                ->all(),
            'cages' => (int) $records->sum('cantidad_javas'),
            'birds' => $birds,
            'net_weight_kg' => $netWeight,
            'average_weight_per_bird_kg' => $birds > 0 ? round($netWeight / $birds, 3) : 0,
        ];
    }

    /**
     * @param  Collection<int, Pesada>  $records
     * @return array<string, mixed>
     */
    private function summarizeDestination(Collection $records): array
    {
        /** @var Pesada $first */
        $first = $records->first();

        return [
            'destination' => $this->destination($first),
            'providers' => $records->pluck('proveedor_origen_id')->unique()->count(),
            'trucks' => $records->map(fn (Pesada $record): string => $this->truckKey($record))->unique()->count(),
            'records' => $records->count(),
            'cages' => (int) $records->sum('cantidad_javas'),
            'birds' => (int) $records->sum('cantidad_aves'),
            'net_weight_kg' => round((float) $records->sum('peso_neto_kg'), 3),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRecord(Pesada $record): array
    {
        $birds = (int) $record->cantidad_aves;
        $netWeight = (float) $record->peso_neto_kg;

        return [
            'id' => (int) $record->id,
            'number' => (int) $record->numero,
            'ticket' => [
                'id' => (int) $record->ticket->id,
                'code' => $record->ticket->codigo,
            ],
            'provider' => [
                'id' => (int) $record->proveedorOrigen->id,
                'name' => $record->proveedorOrigen->nombre_razon_social,
                'document_type' => $record->proveedorOrigen->tipo_documento,
                'document' => $record->proveedorOrigen->numero_documento,
            ],
            'truck' => [
                'vehicle_id' => $record->vehiculo_id ? (int) $record->vehiculo_id : null,
                'plate' => $this->truckPlate($record),
            ],
            'destination' => $this->destination($record),
            'chicken_type' => [
                'code' => $record->tipoPollo?->codigo,
                'name' => $record->tipoPollo?->nombre ?? 'Sin tipo registrado',
            ],
            'chicken_condition' => $record->condicion_pollo,
            'chicken_sex' => $record->sexo,
            'cage_type' => [
                'code' => $record->tipoJava?->codigo,
                'name' => $record->tipoJava?->nombre ?? 'Sin tipo registrado',
            ],
            'cages' => (int) $record->cantidad_javas,
            'birds_per_cage' => (int) $record->aves_por_java,
            'birds' => $birds,
            'gross_weight_kg' => (float) $record->peso_bruto_kg,
            'tare_weight_kg' => (float) $record->tara_total_kg,
            'net_weight_kg' => $netWeight,
            'average_weight_per_bird_kg' => $birds > 0 ? round($netWeight / $birds, 3) : 0,
            'weighed_at' => $record->pesada_at?->toISOString(),
        ];
    }

    /**
     * @return array{type: string, id: ?int, name: string}
     */
    private function destination(Pesada $record): array
    {
        if ($record->ticket->clienteDestino) {
            return [
                'type' => 'CLIENTE',
                'id' => (int) $record->ticket->clienteDestino->id,
                'name' => $record->ticket->clienteDestino->nombre_razon_social,
            ];
        }

        return [
            'type' => 'ALMACEN',
            'id' => $record->ticket->almacenDestino?->id
                ? (int) $record->ticket->almacenDestino->id
                : null,
            'name' => $record->ticket->almacenDestino?->nombre ?? 'Sin destino registrado',
        ];
    }

    private function destinationKey(Pesada $record): string
    {
        $destination = $this->destination($record);

        return $destination['type'].'|'.$destination['id'].'|'.$destination['name'];
    }

    private function truckKey(Pesada $record): string
    {
        return $this->truckOptionValue($record);
    }

    private function truckOptionValue(Pesada $record): string
    {
        return (int) $record->proveedor_origen_id.':'.$this->truckPlateToken($record);
    }

    private function truckPlateToken(Pesada $record): string
    {
        $plate = $this->truckPlate($record);

        return $plate === 'Sin placa' ? self::TRUCK_WITHOUT_PLATE : $plate;
    }

    private function matchesTruckFilter(Pesada $record, string $filter): bool
    {
        if (str_contains($filter, ':')) {
            return $this->truckOptionValue($record) === $filter;
        }

        return $this->truckPlateToken($record) === $filter;
    }

    private function truckPlate(Pesada $record): string
    {
        $plate = trim((string) ($record->placa_snapshot ?: $record->vehiculo?->placa));

        return $plate === '' ? 'Sin placa' : mb_strtoupper($plate, 'UTF-8');
    }

    private function normalizeTruckFilter(mixed $value, ?int $providerId): ?string
    {
        $normalized = mb_strtoupper(trim((string) $value), 'UTF-8');

        if ($normalized === '') {
            return null;
        }

        if ($providerId && ! preg_match('/^\d+:/', $normalized)) {
            return $providerId.':'.$normalized;
        }

        return $normalized;
    }
}
