<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\ListProductDispatchAccountStatementRequest;
use App\Services\OperationContextService;
use App\Services\ProductDispatchAccountStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductDispatchAccountStatementController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProductDispatchAccountStatementService $statements,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);

        return response()->json([
            'data' => $this->statements->catalog($companyId, $branch),
        ]);
    }

    public function show(ListProductDispatchAccountStatementRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $validated = $request->validated();

        return response()->json([
            'data' => DB::transaction(fn (): array => $this->statements->statement(
                $companyId,
                $branch,
                (int) $validated['client_id'],
                $validated['date_from'],
                $validated['date_to'],
                $validated['currency'],
            )),
        ]);
    }
}
