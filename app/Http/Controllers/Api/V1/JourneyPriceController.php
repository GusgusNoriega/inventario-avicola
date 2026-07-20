<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\UpdateJourneyPricesRequest;
use App\Services\GlobalPriceService;
use App\Services\JourneyPlanService;
use App\Services\OperationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JourneyPriceController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly JourneyPlanService $journeys,
        private readonly GlobalPriceService $prices
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->data($request),
        ]);
    }

    public function update(UpdateJourneyPricesRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $actor = $this->context->actor($request, (int) $branch->id);

        DB::transaction(fn () => $this->prices->save(
            $companyId,
            (int) $actor->id,
            $request->validated('global_prices')
        ), 3);

        return response()->json([
            'message' => 'Precios de la jornada actualizados correctamente.',
            'data' => $this->data($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Request $request): array
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $window = $this->journeys->currentWindow($companyId, $branch);

        return [
            'operating_date' => $window['operating_date']->format('Y-m-d'),
            'starts_at' => $window['starts_at']->toIso8601String(),
            'ends_at' => $window['ends_at']->toIso8601String(),
            'timezone' => $branch->zona_horaria,
            'global_prices' => $this->prices->current($companyId),
        ];
    }
}
