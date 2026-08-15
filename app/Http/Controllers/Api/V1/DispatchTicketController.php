<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\StoreDispatchTicketRequest;
use App\Models\TicketDespacho;
use App\Services\DispatchTicketService;
use App\Services\OperationContextService;
use App\Services\TicketMessageService;
use App\Services\TicketTitleService;
use Illuminate\Http\JsonResponse;

class DispatchTicketController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly DispatchTicketService $tickets,
        private readonly TicketMessageService $ticketMessages,
        private readonly TicketTitleService $ticketTitles,
    ) {}

    public function store(StoreDispatchTicketRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $result = $this->tickets->register(
            $companyId,
            $branch,
            $this->context->actor($request, (int) $branch->id),
            $request->validated()
        );

        return response()->json([
            'message' => $result['already_registered']
                ? 'El ticket ya estaba registrado.'
                : 'Ticket y pesadas registrados correctamente.',
            'already_registered' => $result['already_registered'],
            'data' => $this->formatTicket(
                $result['ticket'],
                $this->ticketTitles->current($companyId),
                $this->ticketMessages->current($companyId)
            ),
        ], $result['already_registered'] ? 200 : 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTicket(
        TicketDespacho $ticket,
        string $ticketTitle,
        ?string $ticketMessage
    ): array
    {
        return [
            'id' => $ticket->id,
            'draft_id' => $ticket->referencia_externa,
            'code' => $ticket->codigo,
            'operation_type' => $ticket->tipo_operacion,
            'status' => $ticket->estado,
            'operating_date' => $ticket->jornada->fecha_operativa?->format('Y-m-d'),
            'registered_at' => $ticket->cerrado_at?->toISOString(),
            'ticket_title' => $ticketTitle,
            'ticket_message' => $ticketMessage,
            'destination' => $ticket->clienteDestino
                ? [
                    'type' => 'CLIENTE',
                    'id' => $ticket->clienteDestino->id,
                    'name' => $ticket->clienteDestino->nombre_razon_social,
                    'internal_client' => (bool) $ticket->clienteDestino->es_cliente_interno,
                ]
                : [
                    'type' => 'ALMACEN',
                    'id' => $ticket->almacenDestino?->id,
                    'name' => $ticket->almacenDestino?->nombre,
                ],
            'delivery' => $ticket->vehiculoEntrega && $ticket->conductorEntrega
                ? [
                    'vehicle' => [
                        'id' => $ticket->vehiculoEntrega->id,
                        'plate' => $ticket->vehiculoEntrega->placa,
                    ],
                    'driver' => [
                        'id' => $ticket->conductorEntrega->id,
                        'name' => $ticket->conductorEntrega->nombre_completo,
                    ],
                ]
                : null,
            'weighing_count' => $ticket->pesadas->count(),
            'weighings' => $ticket->pesadas
                ->map(fn ($weighing) => [
                    'id' => $weighing->id,
                    'number' => $weighing->numero,
                    'chicken_condition' => $weighing->condicion_pollo,
                    'chicken_sex' => $weighing->sexo,
                ])
                ->values(),
        ];
    }
}
