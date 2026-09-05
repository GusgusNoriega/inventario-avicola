<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\ListProductDispatchGeneralReportRequest;
use App\Services\OperationContextService;
use App\Services\ProductDispatchGeneralReportService;
use Illuminate\Http\JsonResponse;

class ProductDispatchGeneralReportController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProductDispatchGeneralReportService $reports,
    ) {}

    public function show(ListProductDispatchGeneralReportRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $validated = $request->validated();

        return response()->json([
            'data' => $this->reports->report(
                $companyId,
                $branch,
                $validated['date_from'],
                $validated['date_to'],
            ),
        ]);
    }
}
