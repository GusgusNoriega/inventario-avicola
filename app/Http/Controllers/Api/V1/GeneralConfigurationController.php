<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\UpdateGeneralConfigurationRequest;
use App\Services\GeneralConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralConfigurationController extends Controller
{
    public function __construct(
        private readonly GeneralConfigurationService $configuration,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->configuration->current((int) $request->user()->empresa_id),
        ]);
    }

    public function update(UpdateGeneralConfigurationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'message' => 'Horario guardado y jornadas recalculadas, incluidos los tickets anteriores.',
            'data' => $this->configuration->update(
                (int) $request->user()->empresa_id,
                $data['cutoff'],
                $data['expected_cutoff'],
                (int) $request->user()->id,
            ),
        ]);
    }
}
