<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListCollectorsRequest;
use App\Http\Requests\Finance\StoreCollectorRequest;
use App\Http\Requests\Finance\UpdateCollectorRequest;
use App\Services\CollectorService;
use Illuminate\Http\JsonResponse;

class CollectorController extends Controller
{
    public function __construct(private readonly CollectorService $collectors) {}

    public function index(ListCollectorsRequest $request): JsonResponse
    {
        return response()->json($this->collectors->list(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function store(StoreCollectorRequest $request): JsonResponse
    {
        $collector = $this->collectors->create(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Cobrador creado correctamente.',
            'data' => $collector,
        ], 201);
    }

    public function update(UpdateCollectorRequest $request, int $cobrador): JsonResponse
    {
        $collector = $this->collectors->update(
            (int) $request->user()->empresa_id,
            $request->user(),
            $cobrador,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $collector['estado'] === 'ACTIVO'
                ? 'Cobrador actualizado correctamente.'
                : 'Cobrador desactivado correctamente.',
            'data' => $collector,
        ]);
    }
}
