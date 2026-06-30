<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\JavaControl\StoreJavaReceiptRequest;
use App\Models\MovimientoJava;
use App\Models\TerceroRole;
use App\Services\JavaControlService;
use App\Services\OperationContextService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JavaControlController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly JavaControlService $javaControl
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
        ]);
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $balanceQuery = DB::table('movimientos_javas')
            ->where('empresa_id', $companyId)
            ->groupBy('cliente_id')
            ->selectRaw(
                "cliente_id, SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad ELSE -cantidad END) AS saldo"
            );

        $clients = DB::table('terceros')
            ->join('tercero_roles', function ($join): void {
                $join->on('tercero_roles.tercero_id', '=', 'terceros.id')
                    ->where('tercero_roles.rol', TerceroRole::CLIENT);
            })
            ->leftJoinSub($balanceQuery, 'saldos_javas', function ($join): void {
                $join->on('saldos_javas.cliente_id', '=', 'terceros.id');
            })
            ->where('terceros.empresa_id', $companyId)
            ->where('terceros.estado', 'ACTIVO')
            ->orderByDesc(DB::raw('COALESCE(saldos_javas.saldo, 0)'))
            ->orderBy('terceros.nombre_razon_social')
            ->get([
                'terceros.id',
                'terceros.nombre_razon_social',
                'terceros.numero_documento',
                DB::raw('COALESCE(saldos_javas.saldo, 0) AS saldo'),
            ])
            ->map(fn (object $client): array => [
                'id' => (int) $client->id,
                'name' => $client->nombre_razon_social,
                'document_number' => $client->numero_documento,
                'balance' => max(0, (int) $client->saldo),
            ])
            ->values();

        $movements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->when($clientId, fn ($query) => $query->where('cliente_id', $clientId))
            ->with(['cliente:id,nombre_razon_social', 'ticketDespacho:id,codigo', 'vehiculo:id,placa', 'conductor:id,nombre_completo'])
            ->orderByDesc('fecha_movimiento')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (MovimientoJava $movement): array => $this->formatMovement($movement))
            ->values();
        $today = CarbonImmutable::now($branch->zona_horaria);
        $receivedToday = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->whereBetween('fecha_movimiento', [
                $today->startOfDay()->format('Y-m-d H:i:s'),
                $today->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->sum('cantidad');

        return response()->json(['data' => [
            'branch' => [
                'id' => (int) $branch->id,
                'name' => $branch->nombre,
                'timezone' => $branch->zona_horaria,
            ],
            'summary' => [
                'total_pending' => (int) $clients->sum('balance'),
                'clients_with_balance' => $clients->where('balance', '>', 0)->count(),
                'received_today' => (int) $receivedToday,
            ],
            'clients' => $clients,
            'trucks' => DB::table('vehiculos')
                ->where('empresa_id', $companyId)
                ->where('es_propio', true)
                ->where('estado', 'ACTIVO')
                ->orderBy('placa')
                ->get(['id', 'placa'])
                ->map(fn (object $truck): array => ['id' => (int) $truck->id, 'plate' => $truck->placa])
                ->values(),
            'drivers' => DB::table('conductores')
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre_completo')
                ->get(['id', 'nombre_completo'])
                ->map(fn (object $driver): array => ['id' => (int) $driver->id, 'name' => $driver->nombre_completo])
                ->values(),
            'movements' => $movements,
        ]]);
    }

    public function store(StoreJavaReceiptRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $movement = $this->javaControl->registerReceipt(
            $this->context->companyId($request),
            (int) $branch->id,
            $branch->zona_horaria,
            $this->context->actor($request, (int) $branch->id),
            $request->validated()
        );
        $movement->load(['cliente:id,nombre_razon_social', 'vehiculo:id,placa', 'conductor:id,nombre_completo']);

        return response()->json([
            'message' => 'La devolución de javas fue registrada correctamente.',
            'data' => $this->formatMovement($movement),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function formatMovement(MovimientoJava $movement): array
    {
        return [
            'id' => (int) $movement->id,
            'type' => $movement->tipo,
            'quantity' => (int) $movement->cantidad,
            'occurred_at' => $movement->fecha_movimiento?->toISOString(),
            'client' => [
                'id' => (int) $movement->cliente_id,
                'name' => $movement->cliente?->nombre_razon_social,
            ],
            'ticket' => $movement->ticketDespacho
                ? ['id' => (int) $movement->ticketDespacho->id, 'code' => $movement->ticketDespacho->codigo]
                : null,
            'truck' => $movement->vehiculo
                ? ['id' => (int) $movement->vehiculo->id, 'plate' => $movement->vehiculo->placa]
                : null,
            'driver' => $movement->conductor
                ? ['id' => (int) $movement->conductor->id, 'name' => $movement->conductor->nombre_completo]
                : null,
            'observations' => $movement->observaciones,
        ];
    }
}
