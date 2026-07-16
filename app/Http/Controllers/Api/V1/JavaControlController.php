<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\JavaControl\StoreDailyJavaCountRequest;
use App\Http\Requests\JavaControl\StoreJavaInventoryRequest;
use App\Http\Requests\JavaControl\StoreJavaReceiptRequest;
use App\Models\JornadaOperativa;
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
            'journey_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $journeys = JornadaOperativa::query()
            ->where('sucursal_id', $branch->id)
            ->orderByDesc('fecha_operativa')
            ->orderByDesc('id')
            ->get();
        $now = CarbonImmutable::now($branch->zona_horaria);
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $cutoffAt = $now->startOfDay()->setTimeFromTimeString($cutoff);
        $currentOperatingDate = $now->greaterThanOrEqualTo($cutoffAt)
            ? $now->addDay()->format('Y-m-d')
            : $now->format('Y-m-d');
        $activeJourneyId = (int) ($journeys
            ->first(fn (JornadaOperativa $journey): bool => $journey->fecha_operativa?->format('Y-m-d') === $currentOperatingDate)
            ?->id ?? 0);
        $selectedJourneyId = isset($filters['journey_id'])
            ? (int) $filters['journey_id']
            : (int) ($journeys->first()?->id ?? 0);
        $currentJourneyId = (int) ($journeys->first()?->id ?? 0);

        if ($selectedJourneyId && ! $journeys->contains('id', $selectedJourneyId)) {
            abort(404, 'La jornada seleccionada no pertenece a esta sucursal.');
        }

        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));
        $page = (int) ($filters['page'] ?? 1);
        $perPage = 12;
        $balanceQuery = DB::table('movimientos_javas')
            ->where('empresa_id', $companyId)
            ->groupBy('cliente_id')
            ->selectRaw(
                "cliente_id, SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad ELSE -cantidad END) AS saldo"
            )
            ->selectRaw(
                "SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad_bandejas ELSE -cantidad_bandejas END) AS saldo_bandejas"
            );

        $clientBaseQuery = DB::table('terceros')
            ->join('tercero_roles', function ($join): void {
                $join->on('tercero_roles.tercero_id', '=', 'terceros.id')
                    ->where('tercero_roles.rol', TerceroRole::CLIENT);
            })
            ->leftJoinSub($balanceQuery, 'saldos_javas', function ($join): void {
                $join->on('saldos_javas.cliente_id', '=', 'terceros.id');
            })
            ->where('terceros.empresa_id', $companyId)
            ->where('terceros.estado', 'ACTIVO');
        $clientPaginator = (clone $clientBaseQuery)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('terceros.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhere('terceros.numero_documento', 'like', "%{$search}%");
                });
            })
            ->orderByDesc(DB::raw('COALESCE(saldos_javas.saldo, 0) + COALESCE(saldos_javas.saldo_bandejas, 0)'))
            ->orderBy('terceros.nombre_razon_social')
            ->paginate($perPage, [
                'terceros.id',
                'terceros.nombre_razon_social',
                'terceros.numero_documento',
                'terceros.es_cliente_interno',
                DB::raw('COALESCE(saldos_javas.saldo, 0) AS saldo'),
                DB::raw('COALESCE(saldos_javas.saldo_bandejas, 0) AS saldo_bandejas'),
            ], 'page', $page);
        $clients = $clientPaginator->getCollection()
            ->map(fn (object $client): array => [
                'id' => (int) $client->id,
                'name' => $client->nombre_razon_social,
                'document_number' => $client->numero_documento,
                'is_internal_client' => (bool) $client->es_cliente_interno,
                'balance' => max(0, (int) $client->saldo),
                'java_balance' => max(0, (int) $client->saldo),
                'tray_balance' => max(0, (int) $client->saldo_bandejas),
            ])
            ->values();
        $clientOptions = (clone $clientBaseQuery)
            ->orderBy('terceros.nombre_razon_social')
            ->get([
                'terceros.id',
                'terceros.nombre_razon_social',
                'terceros.numero_documento',
                'terceros.es_cliente_interno',
                DB::raw('COALESCE(saldos_javas.saldo, 0) AS saldo'),
                DB::raw('COALESCE(saldos_javas.saldo_bandejas, 0) AS saldo_bandejas'),
            ])
            ->map(fn (object $client): array => [
                'id' => (int) $client->id,
                'name' => $client->nombre_razon_social,
                'document_number' => $client->numero_documento,
                'is_internal_client' => (bool) $client->es_cliente_interno,
                'balance' => max(0, (int) $client->saldo),
                'java_balance' => max(0, (int) $client->saldo),
                'tray_balance' => max(0, (int) $client->saldo_bandejas),
            ])
            ->values();

        $movements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branch->id)
            ->when($selectedJourneyId, fn ($query) => $query->where('jornada_id', $selectedJourneyId))
            ->when($clientId, fn ($query) => $query->where('cliente_id', $clientId))
            ->with([
                'jornada:id,fecha_operativa,estado',
                'cliente:id,nombre_razon_social',
                'ticketDespacho:id,codigo',
                'vehiculo:id,placa',
                'conductor:id,nombre_completo',
            ])
            ->orderByDesc('fecha_movimiento')
            ->orderByDesc('id')
            ->limit(250)
            ->get()
            ->map(fn (MovimientoJava $movement): array => $this->formatMovement($movement))
            ->values();
        $journeyTotals = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branch->id)
            ->when($selectedJourneyId, fn ($query) => $query->where('jornada_id', $selectedJourneyId))
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad ELSE 0 END), 0) AS dispatched"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN tipo = 'RECEPCION' THEN cantidad ELSE 0 END), 0) AS received"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad_bandejas ELSE 0 END), 0) AS trays_dispatched"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN tipo = 'RECEPCION' THEN cantidad_bandejas ELSE 0 END), 0) AS trays_received"
            )
            ->selectRaw('COUNT(DISTINCT vehiculo_id) AS trucks_count')
            ->selectRaw('COUNT(*) AS movements_count')
            ->first();
        $dispatched = (int) ($journeyTotals->dispatched ?? 0);
        $received = (int) ($journeyTotals->received ?? 0);
        $traysDispatched = (int) ($journeyTotals->trays_dispatched ?? 0);
        $traysReceived = (int) ($journeyTotals->trays_received ?? 0);
        $currentJourneyTotals = ! $currentJourneyId
            ? (object) [
                'dispatched' => 0,
                'received' => 0,
                'trays_dispatched' => 0,
                'trays_received' => 0,
                'trucks_count' => 0,
            ]
            : ($currentJourneyId === $selectedJourneyId
                ? $journeyTotals
                : MovimientoJava::query()
                    ->where('empresa_id', $companyId)
                    ->where('sucursal_id', $branch->id)
                    ->where('jornada_id', $currentJourneyId)
                    ->selectRaw(
                        "COALESCE(SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad ELSE 0 END), 0) AS dispatched"
                    )
                    ->selectRaw(
                        "COALESCE(SUM(CASE WHEN tipo = 'RECEPCION' THEN cantidad ELSE 0 END), 0) AS received"
                    )
                    ->selectRaw(
                        "COALESCE(SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad_bandejas ELSE 0 END), 0) AS trays_dispatched"
                    )
                    ->selectRaw(
                        "COALESCE(SUM(CASE WHEN tipo = 'RECEPCION' THEN cantidad_bandejas ELSE 0 END), 0) AS trays_received"
                    )
                    ->selectRaw('COUNT(DISTINCT vehiculo_id) AS trucks_count')
                    ->first());
        $currentDispatched = (int) ($currentJourneyTotals->dispatched ?? 0);
        $currentReceived = (int) ($currentJourneyTotals->received ?? 0);
        $currentTraysDispatched = (int) ($currentJourneyTotals->trays_dispatched ?? 0);
        $currentTraysReceived = (int) ($currentJourneyTotals->trays_received ?? 0);
        $truckActivity = DB::table('movimientos_javas')
            ->join('terceros', 'terceros.id', '=', 'movimientos_javas.cliente_id')
            ->leftJoin('vehiculos', 'vehiculos.id', '=', 'movimientos_javas.vehiculo_id')
            ->leftJoin('conductores', 'conductores.id', '=', 'movimientos_javas.conductor_id')
            ->where('movimientos_javas.empresa_id', $companyId)
            ->where('movimientos_javas.sucursal_id', $branch->id)
            ->when(
                $selectedJourneyId,
                fn ($query) => $query->where('movimientos_javas.jornada_id', $selectedJourneyId)
            )
            ->groupBy(
                'movimientos_javas.vehiculo_id',
                'vehiculos.placa',
                'movimientos_javas.conductor_id',
                'conductores.nombre_completo',
                'movimientos_javas.cliente_id',
                'terceros.nombre_razon_social'
            )
            ->orderBy('vehiculos.placa')
            ->orderBy('terceros.nombre_razon_social')
            ->get([
                'movimientos_javas.vehiculo_id',
                'vehiculos.placa',
                'movimientos_javas.conductor_id',
                'conductores.nombre_completo',
                'movimientos_javas.cliente_id',
                'terceros.nombre_razon_social',
                DB::raw("SUM(CASE WHEN movimientos_javas.tipo = 'DESPACHO' THEN movimientos_javas.cantidad ELSE 0 END) AS dispatched"),
                DB::raw("SUM(CASE WHEN movimientos_javas.tipo = 'RECEPCION' THEN movimientos_javas.cantidad ELSE 0 END) AS received"),
                DB::raw("SUM(CASE WHEN movimientos_javas.tipo = 'DESPACHO' THEN movimientos_javas.cantidad_bandejas ELSE 0 END) AS trays_dispatched"),
                DB::raw("SUM(CASE WHEN movimientos_javas.tipo = 'RECEPCION' THEN movimientos_javas.cantidad_bandejas ELSE 0 END) AS trays_received"),
            ])
            ->map(fn (object $activity): array => [
                'truck' => [
                    'id' => $activity->vehiculo_id ? (int) $activity->vehiculo_id : null,
                    'plate' => $activity->placa ?: 'Sin camión registrado',
                ],
                'driver' => [
                    'id' => $activity->conductor_id ? (int) $activity->conductor_id : null,
                    'name' => $activity->nombre_completo ?: 'Sin chofer registrado',
                ],
                'client' => [
                    'id' => (int) $activity->cliente_id,
                    'name' => $activity->nombre_razon_social,
                ],
                'dispatched' => (int) $activity->dispatched,
                'received' => (int) $activity->received,
                'net' => (int) $activity->dispatched - (int) $activity->received,
                'java_dispatched' => (int) $activity->dispatched,
                'java_received' => (int) $activity->received,
                'java_net' => (int) $activity->dispatched - (int) $activity->received,
                'tray_dispatched' => (int) $activity->trays_dispatched,
                'tray_received' => (int) $activity->trays_received,
                'tray_net' => (int) $activity->trays_dispatched - (int) $activity->trays_received,
            ])
            ->values();
        $today = CarbonImmutable::now($branch->zona_horaria);
        $receivedToday = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->whereBetween('fecha_movimiento', [
                $today->startOfDay()->format('Y-m-d H:i:s'),
                $today->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->selectRaw('COALESCE(SUM(cantidad), 0) AS javas')
            ->selectRaw('COALESCE(SUM(cantidad_bandejas), 0) AS trays')
            ->first();
        $javasReceivedToday = (int) ($receivedToday?->javas ?? 0);
        $traysReceivedToday = (int) ($receivedToday?->trays ?? 0);
        $inventory = $this->javaControl->currentInventory(
            $companyId,
            $activeJourneyId ?: null
        );
        $clientHolders = $inventory['client_holders'];
        $holderTotals = $clientHolders['totals'];

        return response()->json(['data' => [
            'branch' => [
                'id' => (int) $branch->id,
                'name' => $branch->nombre,
                'timezone' => $branch->zona_horaria,
            ],
            'summary' => [
                'total_pending' => (int) $holderTotals['all_clients_javas'],
                'clients_with_balance' => (int) $holderTotals['all_clients_count'],
                'received_today' => $javasReceivedToday,
                'dispatched' => $dispatched,
                'received' => $received,
                'net' => $dispatched - $received,
                'java_total_pending' => (int) $holderTotals['all_clients_javas'],
                'java_clients_with_balance' => (int) $holderTotals['all_java_clients_count'],
                'java_received_today' => $javasReceivedToday,
                'java_dispatched' => $dispatched,
                'java_received' => $received,
                'java_net' => $dispatched - $received,
                'tray_total_pending' => (int) $holderTotals['all_clients_trays'],
                'tray_clients_with_balance' => (int) $holderTotals['all_tray_clients_count'],
                'tray_received_today' => $traysReceivedToday,
                'tray_dispatched' => $traysDispatched,
                'tray_received' => $traysReceived,
                'tray_net' => $traysDispatched - $traysReceived,
                'trucks_count' => (int) ($journeyTotals->trucks_count ?? 0),
                'movements_count' => (int) ($journeyTotals->movements_count ?? 0),
                'external_clients_count' => (int) $holderTotals['external_clients_count'],
                'external_java_pending' => (int) $holderTotals['external_javas'],
                'external_tray_pending' => (int) $holderTotals['external_trays'],
                'internal_clients_count' => (int) $holderTotals['internal_clients_count'],
                'internal_java_pending' => (int) $holderTotals['internal_javas'],
                'internal_tray_pending' => (int) $holderTotals['internal_trays'],
            ],
            'current_summary' => [
                'journey_id' => $currentJourneyId ?: null,
                'dispatched' => $currentDispatched,
                'received' => $currentReceived,
                'net' => $currentDispatched - $currentReceived,
                'java_dispatched' => $currentDispatched,
                'java_received' => $currentReceived,
                'java_net' => $currentDispatched - $currentReceived,
                'tray_dispatched' => $currentTraysDispatched,
                'tray_received' => $currentTraysReceived,
                'tray_net' => $currentTraysDispatched - $currentTraysReceived,
                'trucks_count' => (int) ($currentJourneyTotals->trucks_count ?? 0),
            ],
            'inventory' => $inventory,
            'client_holders' => $clientHolders,
            'journeys' => $journeys->map(fn (JornadaOperativa $journey): array => [
                'id' => (int) $journey->id,
                'operating_date' => $journey->fecha_operativa?->format('Y-m-d'),
                'status' => $journey->estado,
                'starts_at' => $journey->inicio_at?->toISOString(),
                'ends_at' => ($journey->cerrada_at ?: $journey->cierre_programado_at)?->toISOString(),
            ])->values(),
            'active_journey_id' => $activeJourneyId ?: null,
            'selected_journey_id' => $selectedJourneyId ?: null,
            'clients' => $clients,
            'client_options' => $clientOptions,
            'clients_pagination' => [
                'current_page' => $clientPaginator->currentPage(),
                'last_page' => $clientPaginator->lastPage(),
                'per_page' => $clientPaginator->perPage(),
                'total' => $clientPaginator->total(),
                'from' => $clientPaginator->firstItem(),
                'to' => $clientPaginator->lastItem(),
            ],
            'trucks' => DB::table('vehiculos')
                ->where('empresa_id', $companyId)
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
            'truck_activity' => $truckActivity,
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
        $movement->load([
            'jornada:id,fecha_operativa,estado',
            'cliente:id,nombre_razon_social',
            'vehiculo:id,placa',
            'conductor:id,nombre_completo',
        ]);

        return response()->json([
            'message' => 'La entrada de envases fue registrada correctamente.',
            'data' => $this->formatMovement($movement),
        ], 201);
    }

    public function storeInventory(StoreJavaInventoryRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $inventory = $this->javaControl->saveInventoryTotal(
            $this->context->companyId($request),
            $this->context->actor($request, (int) $branch->id),
            $request->validated()
        );

        return response()->json([
            'message' => 'El inventario general de envases fue actualizado correctamente.',
            'data' => $inventory,
        ], 201);
    }

    public function storeDailyCount(StoreDailyJavaCountRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $inventory = $this->javaControl->saveDailyCount(
            $this->context->companyId($request),
            (int) $branch->id,
            $branch->zona_horaria,
            $this->context->actor($request, (int) $branch->id),
            $request->validated()
        );

        return response()->json([
            'message' => 'El conteo diario de envases fue registrado correctamente.',
            'data' => $inventory,
        ], 201);
    }

    /** @return array<string, mixed> */
    private function formatMovement(MovimientoJava $movement): array
    {
        return [
            'id' => (int) $movement->id,
            'type' => $movement->tipo,
            'quantity' => (int) $movement->cantidad,
            'java_quantity' => (int) $movement->cantidad,
            'tray_quantity' => (int) $movement->cantidad_bandejas,
            'occurred_at' => $movement->fecha_movimiento?->toISOString(),
            'journey' => $movement->jornada
                ? [
                    'id' => (int) $movement->jornada->id,
                    'operating_date' => $movement->jornada->fecha_operativa?->format('Y-m-d'),
                    'status' => $movement->jornada->estado,
                ]
                : null,
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
