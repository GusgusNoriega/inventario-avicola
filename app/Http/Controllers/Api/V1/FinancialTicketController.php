<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BulkAdjustFinancialTicketPricesRequest;
use App\Http\Requests\Finance\ListFinancialTicketsRequest;
use App\Http\Requests\Finance\SearchFinancialTicketClientsRequest;
use App\Http\Requests\Finance\UpdateFinancialTicketClientRequest;
use App\Http\Requests\Finance\UpdateFinancialTicketPricesRequest;
use App\Services\FinancialTicketService;
use Illuminate\Http\JsonResponse;

class FinancialTicketController extends Controller
{
    public function __construct(private readonly FinancialTicketService $tickets) {}

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
}
