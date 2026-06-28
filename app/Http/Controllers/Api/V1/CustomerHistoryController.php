<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\ListaPrecio;
use App\Models\Pesada;
use App\Models\PrecioHistorial;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerHistoryController extends Controller
{
    public function show(Request $request, int $tercero): JsonResponse
    {
        $filters = $request->validate([
            'ticket' => ['nullable', 'string', 'max:40'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $customer = Tercero::query()
            ->where('empresa_id', $this->companyId($request))
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::CLIENT)
            ->findOrFail($tercero);
        $ticketSearch = trim($filters['ticket'] ?? '');
        $ticketQuery = TicketDespacho::query()
            ->where('cliente_destino_id', $customer->id)
            ->when(
                $ticketSearch !== '',
                fn (Builder $query) => $query->where('codigo', 'like', "%{$ticketSearch}%")
            )
            ->when(
                $filters['fecha_desde'] ?? null,
                fn (Builder $query, string $date) => $query->whereHas(
                    'jornada',
                    fn (Builder $jornada) => $jornada->whereDate('fecha_operativa', '>=', $date)
                )
            )
            ->when(
                $filters['fecha_hasta'] ?? null,
                fn (Builder $query, string $date) => $query->whereHas(
                    'jornada',
                    fn (Builder $jornada) => $jornada->whereDate('fecha_operativa', '<=', $date)
                )
            );

        $ticketCount = (clone $ticketQuery)->count();
        $ticketIds = (clone $ticketQuery)->select('tickets_despacho.id');
        $totals = DB::table('pesadas as p')
            ->join('tickets_despacho as td', 'td.id', '=', 'p.ticket_id')
            ->leftJoin('ticket_precios as tp', function ($join): void {
                $join->on('tp.ticket_id', '=', 'p.ticket_id')
                    ->on('tp.tipo_pollo_id', '=', 'p.tipo_pollo_id');
            })
            ->whereIn('p.ticket_id', $ticketIds)
            ->where('p.estado', Pesada::STATUS_ACTIVE)
            ->selectRaw('COUNT(p.id) as registros')
            ->selectRaw('COALESCE(SUM(p.cantidad_javas), 0) as javas')
            ->selectRaw('COALESCE(SUM(p.cantidad_aves), 0) as aves')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN td.tipo_operacion = ? THEN -p.peso_neto_kg ELSE p.peso_neto_kg END), 0) as peso_neto_kg',
                [TicketDespacho::OPERATION_RETURN]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN td.tipo_operacion = ? THEN -(p.peso_neto_kg * tp.precio_kg) ELSE p.peso_neto_kg * tp.precio_kg END), 0) as importe',
                [TicketDespacho::OPERATION_RETURN]
            )
            ->first();

        $tickets = $ticketQuery
            ->with([
                'jornada',
                'precios.tipoPollo',
                'pesadas' => fn ($query) => $query->orderBy('numero'),
                'pesadas.tipoPollo',
                'pesadas.tipoJava',
            ])
            ->orderByDesc(
                DB::table('jornadas_operativas')
                    ->select('fecha_operativa')
                    ->whereColumn('jornadas_operativas.id', 'tickets_despacho.jornada_id')
                    ->limit(1)
            )
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return response()->json([
            'data' => [
                'client' => [
                    'id' => $customer->id,
                    'name' => $customer->nombre_razon_social,
                    'document_type' => $customer->tipo_documento,
                    'document_number' => $customer->numero_documento,
                    'address' => $customer->direccion,
                ],
                'summary' => [
                    'tickets' => $ticketCount,
                    'records' => (int) ($totals->registros ?? 0),
                    'cages' => (int) ($totals->javas ?? 0),
                    'birds' => (int) ($totals->aves ?? 0),
                    'net_weight_kg' => round((float) ($totals->peso_neto_kg ?? 0), 3),
                    'amount' => round((float) ($totals->importe ?? 0), 2),
                ],
                'tickets' => $tickets->getCollection()
                    ->map(fn (TicketDespacho $ticket) => $this->formatTicket($ticket))
                    ->values(),
                'price_history' => $this->priceHistory($customer),
            ],
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
            'links' => [
                'first' => $tickets->url(1),
                'last' => $tickets->url($tickets->lastPage()),
                'prev' => $tickets->previousPageUrl(),
                'next' => $tickets->nextPageUrl(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTicket(TicketDespacho $ticket): array
    {
        $movementSign = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN ? -1 : 1;
        $prices = $ticket->precios->keyBy('tipo_pollo_id');
        $records = $ticket->pesadas->map(function (Pesada $record) use ($movementSign, $prices): array {
            $price = $prices->get($record->tipo_pollo_id);
            $amount = $record->estado === Pesada::STATUS_ACTIVE && $price
                ? $movementSign * (float) $record->peso_neto_kg * (float) $price->precio_kg
                : 0;

            return [
                'id' => $record->id,
                'number' => $record->numero,
                'chicken_type' => [
                    'code' => $record->tipoPollo->codigo,
                    'name' => $record->tipoPollo->nombre,
                ],
                'chicken_condition' => $record->condicion_pollo,
                'cage_type' => $record->tipoJava?->nombre,
                'birds_per_cage' => $record->aves_por_java,
                'cages' => $record->cantidad_javas,
                'birds' => $record->cantidad_aves,
                'gross_weight_kg' => (float) $record->peso_bruto_kg,
                'tare_weight_kg' => (float) $record->tara_total_kg,
                'net_weight_kg' => (float) $record->peso_neto_kg,
                'movement_net_weight_kg' => round($movementSign * (float) $record->peso_neto_kg, 3),
                'price_kg' => $price ? (float) $price->precio_kg : null,
                'amount' => round($amount, 2),
                'weighed_at' => $record->pesada_at?->toISOString(),
                'status' => $record->estado,
            ];
        });
        $activeRecords = $records->where('status', Pesada::STATUS_ACTIVE);

        return [
            'id' => $ticket->id,
            'code' => $ticket->codigo,
            'operating_date' => $ticket->jornada->fecha_operativa?->format('Y-m-d'),
            'channel' => $ticket->canal,
            'operation_type' => $ticket->tipo_operacion,
            'status' => $ticket->estado,
            'created_at' => $ticket->created_at?->toISOString(),
            'closed_at' => $ticket->cerrado_at?->toISOString(),
            'summary' => [
                'records' => $activeRecords->count(),
                'cages' => $activeRecords->sum('cages'),
                'birds' => $activeRecords->sum('birds'),
                'net_weight_kg' => round((float) $activeRecords->sum('movement_net_weight_kg'), 3),
                'amount' => round((float) $activeRecords->sum('amount'), 2),
            ],
            'prices' => $ticket->precios->map(fn ($price) => [
                'chicken_type' => $price->tipoPollo->nombre,
                'price_kg' => (float) $price->precio_kg,
                'origin' => $price->origen_precio,
            ])->values(),
            'records' => $records->values(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function priceHistory(Tercero $customer): array
    {
        return PrecioHistorial::query()
            ->whereHas('listaPrecio', fn (Builder $query) => $query
                ->where('empresa_id', $customer->empresa_id)
                ->where('tercero_id', $customer->id)
                ->where('operacion', ListaPrecio::OPERATION_SALE))
            ->with('tipoPollo')
            ->orderByDesc('vigente_desde')
            ->get()
            ->map(fn (PrecioHistorial $price) => [
                'id' => $price->id,
                'chicken_type' => [
                    'code' => $price->tipoPollo->codigo,
                    'name' => $price->tipoPollo->nombre,
                ],
                'price_kg' => (float) $price->precio_kg,
                'valid_from' => $price->vigente_desde?->toISOString(),
                'valid_until' => $price->vigente_hasta?->toISOString(),
                'is_current' => $price->vigente_hasta === null,
                'reason' => $price->motivo_cambio,
            ])
            ->values()
            ->all();
    }

    private function companyId(Request $request): int
    {
        if ($request->user()) {
            return (int) $request->user()->empresa_id;
        }

        abort_unless(config('directory.public_access'), 401);

        $companyId = Empresa::query()
            ->where('estado', Empresa::STATUS_ACTIVE)
            ->orderBy('id')
            ->value('id');

        abort_unless($companyId, 503, 'No existe una empresa activa configurada.');

        return (int) $companyId;
    }
}
