<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\UpdateJourneyPlanRequest;
use App\Services\JourneyPlanService;
use App\Services\OperationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyPlanController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly JourneyPlanService $journeys
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->journeys->current(
                $this->context->companyId($request),
                $this->context->branch($request)
            ),
        ]);
    }

    public function update(UpdateJourneyPlanRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);

        return response()->json([
            'message' => 'Jornada actualizada correctamente.',
            'data' => $this->journeys->update(
                $this->context->companyId($request),
                $branch,
                $this->context->actor($request, (int) $branch->id),
                $request->validated()
            ),
        ]);
    }
}
