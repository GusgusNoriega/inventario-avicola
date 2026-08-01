<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BulkAdjustFinancialTicketPricesRequest;
use App\Http\Requests\Finance\ListFinancialTicketsRequest;
use App\Http\Requests\Finance\RestoreFinancialTicketRequest;
use App\Http\Requests\Finance\SearchFinancialTicketClientsRequest;
use App\Http\Requests\Finance\UpdateFinancialTicketClientRequest;
use App\Http\Requests\Finance\UpdateFinancialTicketPricesRequest;
use App\Http\Requests\Finance\VoidFinancialTicketRequest;
use App\Services\FinancialTicketLifecycleService;
use App\Services\FinancialTicketService;
use Illuminate\Http\JsonResponse;

class FinancialTicketController extends Controller
{
    public function __construct(
        private readonly FinancialTicketService $tickets,
        private readonly FinancialTicketLifecycleService $lifecycle,
    ) {}

    public function index(ListFinancialTicketsRequest $request): JsonResponse
    {
        return response()->json($this->tickets->paginate(
            (int) $request->user()->empresa_id,
            $request->validated(),
        ));
    }

    public function clients(SearchFinancialTicketClientsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->tickets->searchClients(
                (int) $request->user()->empresa_id,
                $request->validated('buscar'),
            ),
        ]);
    }

    public function updatePrices(
        UpdateFinancialTicketPricesRequest $request,
        int $ticket,
    ): JsonResponse {
        return response()->json([
            'message' => 'Los precios del ticket fueron actualizados correctamente.',
            'data' => $this->tickets->updatePrices(
                (int) $request->user()->empresa_id,
                $request->user(),
                $ticket,
                $request->validated(),
                $request->ip(),
            ),
        ]);
    }

    public function updateClient(
        UpdateFinancialTicketClientRequest $request,
        int $ticket,
    ): JsonResponse {
        return response()->json([
            'message' => 'El cliente del ticket fue actualizado correctamente.',
            'data' => $this->tickets->updateClient(
                (int) $request->user()->empresa_id,
                $request->user(),
                $ticket,
                (int) $request->validated('cliente_id'),
                $request->ip(),
            ),
        ]);
    }

    public function bulkAdjust(BulkAdjustFinancialTicketPricesRequest $request): JsonResponse
    {
        $result = $this->tickets->bulkAdjustPrices(
            (int) $request->user()->empresa_id,
            $request->user(),
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'Este ajuste masivo ya había sido procesado.'
                : "Se actualizaron {$result['updated_prices']} precios en {$result['updated_tickets']} tickets.",
            'data' => $result,
        ]);
    }

    public function void(VoidFinancialTicketRequest $request, int $ticket): JsonResponse
    {
        $result = $this->lifecycle->void(
            (int) $request->user()->empresa_id,
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
                'code' => (string) $result['ticket']->codigo,
                'status' => (string) $result['ticket']->estado,
                'voided_at' => $result['ticket']->anulado_at?->toISOString(),
                'void_reason' => $result['ticket']->motivo_anulacion,
                'reversed_payment_ids' => $result['reversed_payment_ids'],
            ],
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }

    public function restore(RestoreFinancialTicketRequest $request, int $ticket): JsonResponse
    {
        $result = $this->lifecycle->restore(
            (int) $request->user()->empresa_id,
            $request->user(),
            $ticket,
            $request->ip(),
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'El ticket ya estaba restablecido.'
                : 'Ticket restablecido correctamente.',
            'data' => [
                'id' => (int) $result['ticket']->id,
                'code' => (string) $result['ticket']->codigo,
                'status' => (string) $result['ticket']->estado,
                'restored_weighing_ids' => $result['restored_weighing_ids'],
            ],
            'meta' => ['idempotent' => $result['idempotent']],
        ]);
    }
}
