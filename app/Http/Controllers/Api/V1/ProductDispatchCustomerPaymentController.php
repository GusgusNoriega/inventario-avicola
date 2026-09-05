<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\ListProductDispatchCustomerPaymentsRequest;
use App\Http\Requests\ProductDispatch\GetProductDispatchCustomerAccountRequest;
use App\Http\Requests\ProductDispatch\SaveProductDispatchCustomerAdjustmentRequest;
use App\Http\Requests\ProductDispatch\SaveProductDispatchCustomerPaymentRequest;
use App\Services\OperationContextService;
use App\Services\ProductDispatchCustomerPaymentService;
use App\Services\ProductDispatchCustomerAccountService;
use App\Services\ProductDispatchCustomerAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductDispatchCustomerPaymentController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProductDispatchCustomerPaymentService $payments,
        private readonly ProductDispatchCustomerAccountService $accounts,
        private readonly ProductDispatchCustomerAdjustmentService $adjustments,
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

    public function account(GetProductDispatchCustomerAccountRequest $request): JsonResponse
    {
        return response()->json($this->accounts->account($this->context->companyId($request),
            $this->context->branch($request), $request->user(), $request->validated()));
    }

    public function storeAdjustment(SaveProductDispatchCustomerAdjustmentRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $result = $this->adjustments->register($companyId, $branch, $request->user(), $request->validated(), $request->ip());

        return response()->json([
            'message' => $result['idempotent'] ? 'Este ajuste ya había sido registrado.' : 'Ajuste del cliente registrado correctamente.',
            'data' => $this->adjustments->show($companyId, $branch, $result['id']),
            'meta' => ['idempotent' => $result['idempotent']],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function updateAdjustment(SaveProductDispatchCustomerAdjustmentRequest $request, int $ajuste): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $this->adjustments->update($companyId, $branch, $request->user(), $ajuste, $request->validated(), $request->ip());

        return response()->json(['message' => 'Ajuste actualizado correctamente.',
            'data' => $this->adjustments->show($companyId, $branch, $ajuste)]);
    }

    public function destroyAdjustment(Request $request, int $ajuste): JsonResponse
    {
        $this->adjustments->delete($this->context->companyId($request), $this->context->branch($request),
            $request->user(), $ajuste, $request->ip());

        return response()->json(['message' => 'Ajuste eliminado correctamente.']);
    }

    public function updateDebt(SaveProductDispatchCustomerAdjustmentRequest $request, int $deuda): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $this->adjustments->updateLegacyDebt($companyId, $branch, $request->user(), $deuda, $request->validated(), $request->ip());

        return response()->json(['message' => 'Deuda anterior actualizada correctamente.',
            'data' => $this->adjustments->legacyDebt($companyId, $branch, $deuda)]);
    }

    public function destroyDebt(Request $request, int $deuda): JsonResponse
    {
        $this->adjustments->deleteLegacyDebt($this->context->companyId($request), $this->context->branch($request),
            $request->user(), $deuda, $request->ip());

        return response()->json(['message' => 'Deuda anterior eliminada correctamente.']);
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
