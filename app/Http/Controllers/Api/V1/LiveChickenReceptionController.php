<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LiveChickenReception\StoreLiveChickenReceptionWeighingRequest;
use App\Http\Requests\LiveChickenReception\UpdateLiveChickenReceptionConfigurationRequest;
use App\Http\Requests\LiveChickenReception\UpdateLiveChickenReceptionWeighingRequest;
use App\Services\LiveChickenReceptionService;
use App\Services\OperationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveChickenReceptionController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly LiveChickenReceptionService $reception,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);

        return response()->json([
            'data' => $this->reception->overview($companyId, $branch),
        ]);
    }

    public function updateConfiguration(
        UpdateLiveChickenReceptionConfigurationRequest $request,
    ): JsonResponse {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $actor = $this->context->actor($request, (int) $branch->id);
        $this->reception->saveConfiguration(
            $companyId,
            (int) $branch->id,
            $actor,
            $request->validated(),
        );

        return response()->json([
            'data' => $this->reception->overview($companyId, $branch),
            'message' => 'Configuración de recepción guardada.',
        ]);
    }

    public function store(StoreLiveChickenReceptionWeighingRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $actor = $this->context->actor($request, (int) $branch->id);
        $result = $this->reception->register(
            $companyId,
            $branch,
            $actor,
            $request->validated(),
        );

        return response()->json([
            'data' => $this->reception->overview($companyId, $branch),
            'weighing_id' => $result['weighing_id'],
            'already_registered' => $result['already_registered'],
            'message' => $result['already_registered']
                ? 'La pesada ya estaba registrada.'
                : 'Pesada registrada correctamente.',
        ], $result['already_registered'] ? 200 : 201);
    }

    public function destroy(
        Request $request,
        int $weighing,
    ): JsonResponse {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $actor = $this->context->actor($request, (int) $branch->id);
        $this->reception->void(
            $companyId,
            $branch,
            $actor,
            $weighing,
        );

        return response()->json([
            'data' => $this->reception->overview($companyId, $branch),
            'message' => 'Pesada anulada y totales corregidos.',
        ]);
    }

    public function update(
        UpdateLiveChickenReceptionWeighingRequest $request,
        int $weighing,
    ): JsonResponse {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $actor = $this->context->actor($request, (int) $branch->id);
        $this->reception->update(
            $companyId,
            $branch,
            $actor,
            $weighing,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'data' => $this->reception->overview($companyId, $branch),
            'message' => 'Pesada de recepción actualizada y totales corregidos.',
        ]);
    }
}
