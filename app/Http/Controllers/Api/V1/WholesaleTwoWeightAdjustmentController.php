<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\UpdateWholesaleTwoWeightAdjustmentsRequest;
use App\Services\OperationContextService;
use App\Services\WholesaleTwoWeightAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WholesaleTwoWeightAdjustmentController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly WholesaleTwoWeightAdjustmentService $adjustments,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'adjustments' => $this->adjustments->configuration(
                    $this->context->companyId($request)
                ),
            ],
        ]);
    }

    public function update(UpdateWholesaleTwoWeightAdjustmentsRequest $request): JsonResponse
    {
        $configuration = $this->adjustments->update(
            $this->context->companyId($request),
            $request->validated('adjustments'),
        );

        return response()->json([
            'message' => 'Mermas de Despacho mayorista 2 actualizadas correctamente.',
            'data' => [
                'adjustments' => $configuration,
            ],
        ]);
    }
}
