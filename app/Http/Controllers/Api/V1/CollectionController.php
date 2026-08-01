<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListCollectionsRequest;
use App\Http\Requests\Finance\StoreCollectionRequest;
use App\Http\Requests\Finance\VoidCollectionRequest;
use App\Services\CollectionBatchService;
use App\Services\CollectionQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function __construct(
        private readonly CollectionBatchService $collections,
        private readonly CollectionQueryService $queries,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->catalog((int) $request->user()->empresa_id),
        ]);
    }

    public function index(ListCollectionsRequest $request): JsonResponse
    {
        return response()->json($this->queries->paginate(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function show(Request $request, int $cobranza): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->find(
                (int) $request->user()->empresa_id,
                $cobranza,
            ),
        ]);
    }

    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $result = $this->collections->register(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'La cobranza ya había sido registrada con esta clave de idempotencia.'
                : 'Cobranza consolidada registrada correctamente.',
            'data' => $this->queries->find(
                (int) $request->user()->empresa_id,
                $result['cobranza_id'],
            ),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function void(VoidCollectionRequest $request, int $cobranza): JsonResponse
    {
        $result = $this->collections->void(
            (int) $request->user()->empresa_id,
            $request->user(),
            $cobranza,
            $request->validated('motivo'),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'La cobranza ya estaba anulada.'
                : 'Cobranza anulada mediante reversas financieras.',
            'data' => $this->queries->find(
                (int) $request->user()->empresa_id,
                $result['cobranza_id'],
            ),
            'reversa_ids' => $result['reversa_ids'],
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }
}
