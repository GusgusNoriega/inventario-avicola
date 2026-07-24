<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListManualCustomerDebtsRequest;
use App\Http\Requests\Finance\StoreManualCustomerDebtRequest;
use App\Http\Requests\Finance\UpdateManualCustomerDebtRequest;
use App\Http\Requests\Finance\VoidManualCustomerDebtRequest;
use App\Services\ManualCustomerDebtService;
use Illuminate\Http\JsonResponse;

class ManualCustomerDebtController extends Controller
{
    public function __construct(
        private readonly ManualCustomerDebtService $debts,
    ) {}

    public function index(ListManualCustomerDebtsRequest $request): JsonResponse
    {
        return response()->json($this->debts->list(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function store(StoreManualCustomerDebtRequest $request): JsonResponse
    {
        $companyId = (int) $request->user()->empresa_id;
        $result = $this->debts->register(
            $companyId,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'Esta deuda anterior ya había sido registrada.'
                : 'Deuda anterior del cliente registrada correctamente.',
            'data' => $this->debts->document($companyId, $result['document_id']),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function show(ListManualCustomerDebtsRequest $request, int $deuda): JsonResponse
    {
        return response()->json([
            'data' => $this->debts->document((int) $request->user()->empresa_id, $deuda),
        ]);
    }

    public function update(UpdateManualCustomerDebtRequest $request, int $deuda): JsonResponse
    {
        $this->debts->update(
            (int) $request->user()->empresa_id,
            $request->user(),
            $deuda,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Deuda anterior actualizada correctamente.',
            'data' => $this->debts->document((int) $request->user()->empresa_id, $deuda),
        ]);
    }

    public function void(VoidManualCustomerDebtRequest $request, int $deuda): JsonResponse
    {
        $this->debts->void(
            (int) $request->user()->empresa_id,
            $request->user(),
            $deuda,
            $request->validated('motivo'),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Deuda anterior anulada correctamente.',
            'data' => $this->debts->document((int) $request->user()->empresa_id, $deuda),
        ]);
    }
}
