<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListFinancialMovementsRequest;
use App\Http\Requests\Finance\StoreFinancialMovementRequest;
use App\Http\Requests\Finance\VoidFinancialMovementRequest;
use App\Services\FinancialMovementService;
use App\Services\FinancialQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialMovementController extends Controller
{
    public function __construct(
        private readonly FinancialMovementService $movements,
        private readonly FinancialQueryService $queries,
    ) {}

    public function index(ListFinancialMovementsRequest $request): JsonResponse
    {
        return response()->json($this->queries->movements(
            (int) $request->user()->empresa_id,
            $request->validated()
        ));
    }

    public function store(StoreFinancialMovementRequest $request): JsonResponse
    {
        $result = $this->movements->register(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );
        $payment = $this->queries->movement((int) $request->user()->empresa_id, $result['pago_id']);

        return response()->json([
            'message' => $result['idempotent']
                ? 'El movimiento ya habia sido registrado con esta clave de idempotencia.'
                : 'Movimiento financiero registrado correctamente.',
            'data' => $payment,
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function show(Request $request, int $movimiento): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->movement((int) $request->user()->empresa_id, $movimiento),
        ]);
    }

    public function void(VoidFinancialMovementRequest $request, int $movimiento): JsonResponse
    {
        $result = $this->movements->void(
            (int) $request->user()->empresa_id,
            $request->user(),
            $movimiento,
            $request->validated('motivo'),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'El movimiento ya estaba anulado.'
                : 'Movimiento anulado mediante una reversa inmutable.',
            'data' => $this->queries->movement((int) $request->user()->empresa_id, $result['pago_id']),
            'reversa' => $this->queries->movement((int) $request->user()->empresa_id, $result['reversa_id']),
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }
}
