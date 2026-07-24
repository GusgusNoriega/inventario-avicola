<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListCustomerDiscountsRequest;
use App\Http\Requests\Finance\StoreCustomerDiscountRequest;
use App\Http\Requests\Finance\UpdateCustomerDiscountRequest;
use App\Http\Requests\Finance\VoidCustomerDiscountRequest;
use App\Services\CustomerDiscountService;
use Illuminate\Http\JsonResponse;

class CustomerDiscountController extends Controller
{
    public function __construct(
        private readonly CustomerDiscountService $discounts,
    ) {}

    public function index(ListCustomerDiscountsRequest $request): JsonResponse
    {
        return response()->json($this->discounts->list(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function store(StoreCustomerDiscountRequest $request): JsonResponse
    {
        $companyId = (int) $request->user()->empresa_id;
        $result = $this->discounts->register(
            $companyId,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'Este descuento ya había sido registrado.'
                : 'Descuento registrado correctamente.',
            'data' => $this->discounts->document($companyId, $result['payment_id']),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function update(UpdateCustomerDiscountRequest $request, int $descuento): JsonResponse
    {
        $companyId = (int) $request->user()->empresa_id;
        $result = $this->discounts->update(
            $companyId,
            $request->user(),
            $descuento,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'Esta corrección ya había sido procesada.'
                : 'Descuento actualizado correctamente.',
            'data' => $this->discounts->document($companyId, $result['payment_id']),
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }

    public function void(VoidCustomerDiscountRequest $request, int $descuento): JsonResponse
    {
        $companyId = (int) $request->user()->empresa_id;
        $idempotent = $this->discounts->void(
            $companyId,
            $request->user(),
            $descuento,
            $request->validated('motivo'),
            $request->ip(),
        );

        return response()->json([
            'message' => $idempotent
                ? 'El descuento ya estaba anulado.'
                : 'Descuento anulado y saldos restaurados correctamente.',
            'data' => $this->discounts->document($companyId, $descuento),
            'meta' => ['idempotent' => $idempotent],
        ]);
    }
}
