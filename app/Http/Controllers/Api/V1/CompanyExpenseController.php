<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\ListCompanyExpensesRequest;
use App\Http\Requests\Expense\StoreCompanyExpenseRequest;
use App\Http\Requests\Expense\UpdateCompanyExpenseRequest;
use App\Http\Requests\Finance\VoidFinancialMovementRequest;
use App\Services\CompanyExpenseQueryService;
use App\Services\CompanyExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyExpenseController extends Controller
{
    public function __construct(
        private readonly CompanyExpenseService $expenses,
        private readonly CompanyExpenseQueryService $queries,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->catalog((int) $request->user()->empresa_id),
        ]);
    }

    public function index(ListCompanyExpensesRequest $request): JsonResponse
    {
        return response()->json($this->queries->expenses(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function show(Request $request, int $gasto): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->expense((int) $request->user()->empresa_id, $gasto),
        ]);
    }

    public function store(StoreCompanyExpenseRequest $request): JsonResponse
    {
        $result = $this->expenses->register(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'El gasto ya había sido registrado.'
                : 'Gasto de empresa registrado correctamente.',
            'data' => $this->queries->expense(
                (int) $request->user()->empresa_id,
                $result['gasto_id'],
            ),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function update(UpdateCompanyExpenseRequest $request, int $gasto): JsonResponse
    {
        $this->expenses->update(
            (int) $request->user()->empresa_id,
            $request->user(),
            $gasto,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Gasto actualizado correctamente.',
            'data' => $this->queries->expense((int) $request->user()->empresa_id, $gasto),
        ]);
    }

    public function void(VoidFinancialMovementRequest $request, int $gasto): JsonResponse
    {
        $result = $this->expenses->void(
            (int) $request->user()->empresa_id,
            $request->user(),
            $gasto,
            $request->validated('motivo'),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'El gasto ya estaba anulado.'
                : 'Gasto anulado y dinero reintegrado mediante una reversa.',
            'data' => $this->queries->expense((int) $request->user()->empresa_id, $gasto),
            'reversa_id' => $result['reversa_id'],
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }
}
