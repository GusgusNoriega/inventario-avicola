<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\ListPurchasesRequest;
use App\Http\Requests\Purchase\StorePurchaseRequest;
use App\Http\Requests\Purchase\VoidPurchaseRequest;
use App\Services\PurchaseQueryService;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly PurchaseQueryService $queries,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->catalog(
                (int) $request->user()->empresa_id,
                $request->user()->hasPermission('PAGOS_REGISTRAR'),
            ),
        ]);
    }

    public function index(ListPurchasesRequest $request): JsonResponse
    {
        return response()->json($this->queries->purchases(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $result = $this->purchases->register(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'La compra ya habia sido registrada con esta clave de idempotencia.'
                : 'Compra registrada correctamente.',
            'data' => $this->queries->purchase(
                (int) $request->user()->empresa_id,
                $result['compra_id'],
            ),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function show(Request $request, int $compra): JsonResponse
    {
        return response()->json([
            'data' => $this->queries->purchase((int) $request->user()->empresa_id, $compra),
        ]);
    }

    public function void(VoidPurchaseRequest $request, int $compra): JsonResponse
    {
        $result = $this->purchases->void(
            (int) $request->user()->empresa_id,
            $request->user(),
            $compra,
            $request->validated('motivo'),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'La compra ya estaba anulada.'
                : 'Compra anulada correctamente.',
            'data' => $this->queries->purchase(
                (int) $request->user()->empresa_id,
                $result['compra_id'],
            ),
            'reversa_id' => $result['reversa_id'],
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }
}
