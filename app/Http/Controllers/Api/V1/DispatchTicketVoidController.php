<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\VoidDispatchTicketRequest;
use App\Services\DispatchTicketVoidService;
use App\Services\OperationContextService;
use Illuminate\Http\JsonResponse;

class DispatchTicketVoidController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly DispatchTicketVoidService $tickets,
    ) {}

    public function __invoke(VoidDispatchTicketRequest $request, int $ticket): JsonResponse
    {
        $branch = $this->context->branch($request);
        $result = $this->tickets->void(
            (int) $this->context->companyId($request),
            (int) $branch->id,
            $request->user(),
            $ticket,
            $request->validated('motivo'),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'El ticket ya estaba anulado.'
                : 'Ticket anulado correctamente.',
            'data' => [
                'id' => (int) $result['ticket']->id,
                'code' => $result['ticket']->codigo,
                'status' => $result['ticket']->estado,
                'voided_at' => $result['ticket']->anulado_at?->toISOString(),
                'void_reason' => $result['ticket']->motivo_anulacion,
                'reversed_payment_ids' => $result['reversed_payment_ids'],
            ],
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }
}
