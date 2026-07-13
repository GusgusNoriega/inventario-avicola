<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinancialBalancesRequest;
use App\Http\Requests\Finance\FinancialTraceRequest;
use App\Http\Requests\Finance\PortfolioRequest;
use App\Services\FinancialQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialQueryController extends Controller
{
    public function __construct(private readonly FinancialQueryService $queries) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->catalog((int) $request->user()->empresa_id),
        ]);
    }

    public function portfolio(PortfolioRequest $request): JsonResponse
    {
        return response()->json($this->queries->portfolio(
            (int) $request->user()->empresa_id,
            $request->validated()
        ));
    }

    public function balances(FinancialBalancesRequest $request): JsonResponse
    {
        return response()->json($this->queries->balances(
            (int) $request->user()->empresa_id,
            $request->validated()
        ));
    }

    public function trace(FinancialTraceRequest $request): JsonResponse
    {
        return response()->json($this->queries->movements(
            (int) $request->user()->empresa_id,
            $request->validated(),
            true,
        ));
    }
}
