<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListFinancialEntitiesRequest;
use App\Http\Requests\Finance\StoreFinancialEntityRequest;
use App\Http\Requests\Finance\UpdateFinancialEntityRequest;
use App\Services\FinancialDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialEntityController extends Controller
{
    public function __construct(private readonly FinancialDirectoryService $directory) {}

    public function index(ListFinancialEntitiesRequest $request): JsonResponse
    {
        return response()->json($this->directory->entities(
            (int) $request->user()->empresa_id,
            $request->validated()
        ));
    }

    public function store(StoreFinancialEntityRequest $request): JsonResponse
    {
        $entity = $this->directory->createEntity(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Entidad financiera creada correctamente.',
            'data' => $entity,
        ], 201);
    }

    public function update(UpdateFinancialEntityRequest $request, int $entidad): JsonResponse
    {
        $entity = $this->directory->updateEntity(
            (int) $request->user()->empresa_id,
            $request->user(),
            $entidad,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Entidad financiera actualizada correctamente.',
            'data' => $entity,
        ]);
    }

    public function destroy(Request $request, int $entidad): JsonResponse
    {
        $this->directory->deactivateEntity(
            (int) $request->user()->empresa_id,
            $request->user(),
            $entidad,
            $request->ip(),
        );

        return response()->json(['message' => 'Entidad financiera desactivada correctamente.']);
    }
}
