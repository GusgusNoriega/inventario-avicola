<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListCashRegisterMovementsRequest;
use App\Http\Requests\Finance\StoreCashRegisterMovementRequest;
use App\Http\Requests\Finance\UpdateCashRegisterMovementRequest;
use App\Services\CashRegisterMovementService;
use App\Services\CashRegisterQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    public function __construct(
        private readonly CashRegisterMovementService $movements,
        private readonly CashRegisterQueryService $queries,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->catalog((int) $request->user()->empresa_id),
        ]);
    }

    public function index(ListCashRegisterMovementsRequest $request): JsonResponse
    {
        return response()->json($this->queries->daily(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function store(StoreCashRegisterMovementRequest $request): JsonResponse
    {
        $result = $this->movements->register(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'El movimiento de caja ya habia sido registrado.'
                : 'Movimiento de efectivo registrado correctamente.',
            'data' => $this->queries->movement(
                (int) $request->user()->empresa_id,
                $result['movimiento_caja_id'],
                (int) $request->validated('caja_id'),
            ),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function update(
        UpdateCashRegisterMovementRequest $request,
        int $movimientoCaja,
    ): JsonResponse {
        $this->movements->update(
            (int) $request->user()->empresa_id,
            $request->user(),
            $movimientoCaja,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Movimiento de efectivo actualizado con trazabilidad.',
            'data' => $this->queries->movement(
                (int) $request->user()->empresa_id,
                $movimientoCaja,
                (int) $request->validated('caja_id'),
            ),
        ]);
    }
}
