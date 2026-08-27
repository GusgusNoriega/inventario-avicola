<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LiveChickenReception\ListLiveChickenReceptionHistoryRequest;
use App\Services\LiveChickenReceptionHistoryService;
use App\Services\OperationContextService;
use Illuminate\Http\JsonResponse;

class LiveChickenReceptionHistoryController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly LiveChickenReceptionHistoryService $history,
    ) {}

    public function __invoke(ListLiveChickenReceptionHistoryRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);

        return response()->json([
            'data' => $this->history->history(
                $this->context->companyId($request),
                $branch,
                $request->validated(),
            ),
        ]);
    }
}
