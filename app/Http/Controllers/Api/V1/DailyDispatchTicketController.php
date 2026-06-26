<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pesada;
use App\Models\TicketDespacho;
use App\Services\OperationContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyDispatchTicketController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'to_time' => ['nullable', 'date_format:H:i'],
            'ticket' => ['nullable', 'string', 'max:40'],
        ]);
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $operatingDate = $filters['date']
            ?? $this->currentOperatingDate($companyId, $branch->zona_horaria);
        $cutoff = $this->cutoff($companyId);
        [$from, $to] = $this->resolveRange($filters, $operatingDate, $cutoff, $branch->zona_horaria);
        $ticketSearch = trim($filters['ticket'] ?? '');

        $tickets = TicketDespacho::query()
            ->whereHas(
                'jornada',
                fn (Builder $query) => $query->where('sucursal_id', $branch->id)
            )
            ->whereHas(
                'pesadas',
                fn (Builder $query) => $this->applyRecordRange($query, $from, $to)
                    ->where('estado', Pesada::STATUS_ACTIVE)
            )
            ->when(
                $ticketSearch !== '',
                fn (Builder $query) => $query->where('codigo', 'like', "%{$ticketSearch}%")
            )
            ->with([
                'jornada',
                'clienteDestino',
                'almacenDestino',
                'pesadas' => fn ($query) => $this->applyRecordRange($query, $from, $to)
                    ->orderBy('numero'),
                'pesadas.tipoPollo',
                'pesadas.tipoJava',
                'pesadas.proveedorOrigen',
                'pesadas.almacenOrigen',
                'pesadas.vehiculo',
            ])
            ->orderByDesc('cerrado_at')
            ->orderByDesc('created_at')
            ->get();
        $records = $tickets
            ->flatMap(fn (TicketDespacho $ticket) => $ticket->pesadas)
            ->filter(fn (Pesada $record) => $record->estado === Pesada::STATUS_ACTIVE)
            ->values();

        return response()->json([
            'data' => [
                'branch' => [
                    'id' => $branch->id,
                    'code' => $branch->codigo,
                    'name' => $branch->nombre,
                    'timezone' => $branch->zona_horaria,
                ],
                'operating_date' => $operatingDate,
                'range' => [
                    'from' => $from->toISOString(),
                    'to' => $to->toISOString(),
                    'from_date' => $from->format('Y-m-d'),
                    'from_time' => $from->format('H:i'),
                    'to_date' => $to->format('Y-m-d'),
                    'to_time' => $to->format('H:i'),
                    'cutoff_time' => substr($cutoff, 0, 5),
                ],
                'generated_at' => now($branch->zona_horaria)->toISOString(),
                'summary' => [
                    ...$this->summarizeRecords($records, $tickets->count()),
                    'by_operation' => $this->summarizeByOperation($tickets),
                ],
                'tickets' => $tickets
                    ->map(fn (TicketDespacho $ticket) => $this->formatTicket($ticket))
                    ->values(),
            ],
        ]);
    }

    private function currentOperatingDate(int $companyId, string $timezone): string
    {
        $cutoff = $this->cutoff($companyId);
        $now = CarbonImmutable::now($timezone);
        $cutoffAt = $now->startOfDay()->setTimeFromTimeString($cutoff);

        return $now->greaterThanOrEqualTo($cutoffAt)
            ? $now->addDay()->toDateString()
            : $now->toDateString();
    }

    private function cutoff(int $companyId): string
    {
        return (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveRange(
        array $filters,
        string $operatingDate,
        string $cutoff,
        string $timezone
    ): array {
        $defaultTo = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$operatingDate} 00:00:00", $timezone)
            ->setTimeFromTimeString($cutoff);
        $defaultFrom = $defaultTo->subDay();
        $fromDate = $filters['from_date'] ?? $defaultFrom->format('Y-m-d');
        $fromTime = $filters['from_time'] ?? $defaultFrom->format('H:i');
        $toDate = $filters['to_date'] ?? $defaultTo->format('Y-m-d');
        $toTime = $filters['to_time'] ?? $defaultTo->format('H:i');
        $from = $this->parseRangePoint($fromDate, $fromTime, $timezone);
        $to = $this->parseRangePoint($toDate, $toTime, $timezone);

        if (! $to->greaterThan($from)) {
            throw ValidationException::withMessages([
                'to_date' => 'La fecha y hora final debe ser posterior a la fecha y hora inicial.',
            ]);
        }

        return [$from, $to];
    }

    private function parseRangePoint(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$date} {$time}:00", $timezone);
    }

    private function applyRecordRange($query, CarbonImmutable $from, CarbonImmutable $to)
    {
        return $query
            ->where('pesada_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('pesada_at', '<', $to->format('Y-m-d H:i:s'));
    }

    /**
     * @param  Collection<int, Pesada>  $records
     * @return array<string, mixed>
     */
    private function summarizeRecords(Collection $records, int $ticketCount): array
    {
        return [
            'tickets' => $ticketCount,
            'records' => $records->count(),
            'cages' => (int) $records->sum('cantidad_javas'),
            'birds' => (int) $records->sum('cantidad_aves'),
            'gross_weight_kg' => round((float) $records->sum('peso_bruto_kg'), 3),
            'tare_weight_kg' => round((float) $records->sum('tara_total_kg'), 3),
            'net_weight_kg' => round((float) $records->sum('peso_neto_kg'), 3),
            'by_type' => $this->summarizeByType($records),
        ];
    }

    /**
     * @param  Collection<int, Pesada>  $records
     * @return list<array<string, mixed>>
     */
    private function summarizeByType(Collection $records): array
    {
        return $records
            ->groupBy(fn (Pesada $record) => (string) ($record->tipoPollo?->codigo ?? 'SIN_TIPO'))
            ->map(function (Collection $items): array {
                /** @var Pesada|null $first */
                $first = $items->first();

                return [
                    'chicken_type' => [
                        'code' => $first?->tipoPollo?->codigo,
                        'name' => $first?->tipoPollo?->nombre ?? 'Sin tipo registrado',
                    ],
                    'records' => $items->count(),
                    'cages' => (int) $items->sum('cantidad_javas'),
                    'birds' => (int) $items->sum('cantidad_aves'),
                    'gross_weight_kg' => round((float) $items->sum('peso_bruto_kg'), 3),
                    'tare_weight_kg' => round((float) $items->sum('tara_total_kg'), 3),
                    'net_weight_kg' => round((float) $items->sum('peso_neto_kg'), 3),
                ];
            })
            ->sortBy('chicken_type.name')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TicketDespacho>  $tickets
     * @return list<array<string, mixed>>
     */
    private function summarizeByOperation(Collection $tickets): array
    {
        return collect([
            TicketDespacho::OPERATION_DISPATCH => 'Despachos',
            TicketDespacho::OPERATION_RETURN => 'Devoluciones',
        ])->map(function (string $label, string $operationType) use ($tickets): array {
            $operationTickets = $tickets
                ->filter(fn (TicketDespacho $ticket) => $ticket->tipo_operacion === $operationType)
                ->values();
            $records = $operationTickets
                ->flatMap(fn (TicketDespacho $ticket) => $ticket->pesadas)
                ->filter(fn (Pesada $record) => $record->estado === Pesada::STATUS_ACTIVE)
                ->values();

            return [
                'operation_type' => $operationType,
                'label' => $label,
                ...$this->summarizeRecords($records, $operationTickets->count()),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTicket(TicketDespacho $ticket): array
    {
        $activeRecords = $ticket->pesadas
            ->filter(fn (Pesada $record) => $record->estado === Pesada::STATUS_ACTIVE)
            ->values();

        return [
            'id' => $ticket->id,
            'code' => $ticket->codigo,
            'operating_date' => $ticket->jornada->fecha_operativa?->format('Y-m-d'),
            'channel' => $ticket->canal,
            'operation_type' => $ticket->tipo_operacion,
            'status' => $ticket->estado,
            'created_at' => $ticket->created_at?->toISOString(),
            'closed_at' => $ticket->cerrado_at?->toISOString(),
            'destination' => $this->formatDestination($ticket),
            'summary' => $this->summarizeRecords($activeRecords, 1),
            'records' => $ticket->pesadas
                ->map(fn (Pesada $record) => $this->formatRecord($record, $ticket))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRecord(Pesada $record, TicketDespacho $ticket): array
    {
        return [
            'id' => $record->id,
            'number' => $record->numero,
            'chicken_type' => [
                'code' => $record->tipoPollo?->codigo,
                'name' => $record->tipoPollo?->nombre,
            ],
            'chicken_condition' => $record->condicion_pollo,
            'cage_type' => [
                'code' => $record->tipoJava?->codigo,
                'name' => $record->tipoJava?->nombre,
                'weight_kg' => $record->tipoJava ? (float) $record->tipoJava->peso_kg : null,
            ],
            'origin' => $this->formatOrigin($record, $ticket),
            'plate' => $record->placa_snapshot ?: $record->vehiculo?->placa,
            'weight_source' => $record->origen_peso,
            'birds_per_cage' => (int) $record->aves_por_java,
            'cages' => (int) $record->cantidad_javas,
            'birds' => (int) $record->cantidad_aves,
            'gross_weight_kg' => (float) $record->peso_bruto_kg,
            'tare_weight_kg' => (float) $record->tara_total_kg,
            'net_weight_kg' => (float) $record->peso_neto_kg,
            'weighed_at' => $record->pesada_at?->toISOString(),
            'status' => $record->estado,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDestination(TicketDespacho $ticket): array
    {
        if ($ticket->clienteDestino) {
            return [
                'type' => 'CLIENTE',
                'id' => $ticket->clienteDestino->id,
                'name' => $ticket->clienteDestino->nombre_razon_social,
            ];
        }

        return [
            'type' => 'ALMACEN',
            'id' => $ticket->almacenDestino?->id,
            'name' => $ticket->almacenDestino?->nombre ?? 'Sin destino registrado',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrigin(Pesada $record, TicketDespacho $ticket): array
    {
        if ($ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN) {
            return [
                'type' => 'DEVOLUCION',
                'id' => null,
                'name' => 'Devolucion de cliente',
            ];
        }

        if ($record->proveedorOrigen) {
            return [
                'type' => 'PROVEEDOR',
                'id' => $record->proveedorOrigen->id,
                'name' => $record->proveedorOrigen->nombre_razon_social,
            ];
        }

        if ($record->almacenOrigen) {
            return [
                'type' => 'ALMACEN',
                'id' => $record->almacenOrigen->id,
                'name' => $record->almacenOrigen->nombre,
            ];
        }

        return [
            'type' => 'SIN_ORIGEN',
            'id' => null,
            'name' => 'Sin origen registrado',
        ];
    }
}
