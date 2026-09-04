<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\ListProductDispatchCustomerPaymentsRequest;
use App\Http\Requests\ProductDispatch\SaveProductDispatchCustomerPaymentRequest;
use App\Services\OperationContextService;
use App\Services\ProductDispatchCustomerPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductDispatchCustomerPaymentController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProductDispatchCustomerPaymentService $payments,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payments->catalog(
            $this->context->companyId($request),
            $this->context->branch($request),
        )]);
    }

    public function index(ListProductDispatchCustomerPaymentsRequest $request): JsonResponse
    {
        return response()->json($this->payments->listing(
            $this->context->companyId($request),
            $this->context->branch($request),
            $request->validated(),
        ));
    }

    public function store(SaveProductDispatchCustomerPaymentRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $result = $this->payments->register($companyId, $branch, $request->user(), $request->validated(), $request->ip());

        return response()->json([
            'message' => $result['idempotent'] ? 'Este pago ya había sido registrado.' : 'Pago del cliente registrado correctamente.',
            'data' => $this->payments->show($companyId, $branch, $result['id']),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function update(SaveProductDispatchCustomerPaymentRequest $request, int $pago): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $this->payments->update($companyId, $branch, $request->user(), $pago, $request->validated(), $request->ip());

        return response()->json([
            'message' => 'Pago del cliente actualizado correctamente.',
            'data' => $this->payments->show($companyId, $branch, $pago),
        ]);
    }

    public function destroy(Request $request, int $pago): JsonResponse
    {
        $this->payments->delete(
            $this->context->companyId($request),
            $this->context->branch($request),
            $request->user(),
            $pago,
            $request->ip(),
        );

        return response()->json(['message' => 'Pago eliminado correctamente.']);
    }
}
