<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreManualCustomerDebtRequest;
use App\Services\ManualCustomerDebtService;
use Illuminate\Http\JsonResponse;

class ManualCustomerDebtController extends Controller
{
    public function __construct(
        private readonly ManualCustomerDebtService $debts,
    ) {}

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
}
