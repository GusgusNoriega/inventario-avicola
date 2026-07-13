<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Pesada;
use App\Models\ProveedorVehiculo;
use App\Models\Tercero;
use App\Models\TerceroRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderHistoryController extends Controller
{
    public function show(Request $request, int $tercero): JsonResponse
    {
        $filters = $request->validate([
            'ticket' => ['nullable', 'string', 'max:40'],
            'placa' => ['nullable', 'string', 'max:20'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $provider = Tercero::query()
            ->where('empresa_id', $this->companyId($request))
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::PROVIDER)
            ->findOrFail($tercero);
        $recordQuery = Pesada::query()
            ->where('proveedor_origen_id', $provider->id)
            ->when(
                trim($filters['ticket'] ?? '') !== '',
                fn (Builder $query) => $query->whereHas(
                    'ticket',
                    fn (Builder $ticket) => $ticket->where(
                        'codigo',
                        'like',
                        '%'.trim($filters['ticket']).'%'
                    )
                )
            )
            ->when(
                trim($filters['placa'] ?? '') !== '',
                fn (Builder $query) => $query->where(
                    'placa_snapshot',
                    'like',
                    '%'.mb_strtoupper(trim($filters['placa']), 'UTF-8').'%'
                )
            )
            ->when(
                $filters['fecha_desde'] ?? null,
                fn (Builder $query, string $date) => $query->whereDate('pesada_at', '>=', $date)
            )
            ->when(
                $filters['fecha_hasta'] ?? null,
                fn (Builder $query, string $date) => $query->whereDate('pesada_at', '<=', $date)
            );

        $summaryQuery = (clone $recordQuery)->where('estado', Pesada::STATUS_ACTIVE);
        $summary = [
            'records' => (clone $summaryQuery)->count(),
            'tickets' => (clone $summaryQuery)->distinct('ticket_id')->count('ticket_id'),
            'cages' => (int) (clone $summaryQuery)->sum('cantidad_javas'),
            'birds' => (int) (clone $summaryQuery)->sum('cantidad_aves'),
            'net_weight_kg' => round((float) (clone $summaryQuery)->sum('peso_neto_kg'), 3),
        ];

        $records = $recordQuery
            ->with([
                'ticket.jornada',
                'ticket.clienteDestino',
                'ticket.almacenDestino',
                'tipoPollo',
                'tipoJava',
                'vehiculo',
            ])
            ->orderByDesc('pesada_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 30))
            ->withQueryString();
        $vehicles = ProveedorVehiculo::query()
            ->where('proveedor_id', $provider->id)
            ->vigente()
            ->with('vehiculo')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProveedorVehiculo $association) => ProviderVehicleController::formatAssociation($association))
            ->values();

        return response()->json([
            'data' => [
                'provider' => [
                    'id' => $provider->id,
                    'name' => $provider->nombre_razon_social,
                    'document_type' => $provider->tipo_documento,
                    'document_number' => $provider->numero_documento,
                    'address' => $provider->direccion,
                ],
                'summary' => $summary,
                'records' => $records->getCollection()
                    ->map(fn (Pesada $record) => $this->formatRecord($record))
                    ->values(),
                'vehicles' => $vehicles,
            ],
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
            'links' => [
                'first' => $records->url(1),
                'last' => $records->url($records->lastPage()),
                'prev' => $records->previousPageUrl(),
                'next' => $records->nextPageUrl(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRecord(Pesada $record): array
    {
        $ticket = $record->ticket;
        $destination = $ticket->clienteDestino
            ? [
                'type' => 'CLIENTE',
                'id' => $ticket->clienteDestino->id,
                'name' => $ticket->clienteDestino->nombre_razon_social,
            ]
            : [
                'type' => 'ALMACEN',
                'id' => $ticket->almacenDestino?->id,
                'name' => $ticket->almacenDestino?->nombre ?? 'Sin destino registrado',
            ];

        return [
            'id' => $record->id,
            'number' => $record->numero,
            'ticket' => [
                'id' => $ticket->id,
                'code' => $ticket->codigo,
                'operating_date' => $ticket->jornada->fecha_operativa?->format('Y-m-d'),
                'status' => $ticket->estado,
            ],
            'destination' => $destination,
            'plate' => $record->placa_snapshot ?: $record->vehiculo?->placa,
            'chicken_type' => $record->tipoPollo->nombre,
            'cage_type' => $record->tipoJava?->nombre,
            'cages' => $record->cantidad_javas,
            'birds' => $record->cantidad_aves,
            'gross_weight_kg' => (float) $record->peso_bruto_kg,
            'tare_weight_kg' => (float) $record->tara_total_kg,
            'net_weight_kg' => (float) $record->peso_neto_kg,
            'weighed_at' => $record->pesada_at?->toISOString(),
            'status' => $record->estado,
        ];
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
