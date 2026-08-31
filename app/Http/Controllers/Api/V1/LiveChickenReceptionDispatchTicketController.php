<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LiveChickenReception\StoreLiveChickenReceptionDispatchTicketRequest;
use App\Http\Requests\LiveChickenReception\UpdateLiveChickenReceptionDispatchTicketRequest;
use App\Http\Requests\LiveChickenReception\VoidLiveChickenReceptionDispatchTicketWeighingRequest;
use App\Models\JornadaOperativa;
use App\Models\TicketDespacho;
use App\Services\LiveChickenReceptionDispatchTicketService;
use App\Services\LiveChickenReceptionService;
use App\Services\OperationContextService;
use App\Services\TicketMessageService;
use App\Services\TicketTitleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveChickenReceptionDispatchTicketController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly LiveChickenReceptionDispatchTicketService $tickets,
        private readonly LiveChickenReceptionService $reception,
        private readonly TicketMessageService $ticketMessages,
        private readonly TicketTitleService $ticketTitles,
    ) {}

    public function store(StoreLiveChickenReceptionDispatchTicketRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $result = $this->tickets->registerReception(
            $companyId,
            $branch,
            $this->context->actor($request, (int) $branch->id),
            $request->validated(),
        );

        return response()->json([
            'message' => $result['already_registered']
                ? 'El ticket de recepción ya estaba registrado.'
                : 'Ticket, pesadas e ingreso de recepción registrados correctamente.',
            'already_registered' => $result['already_registered'],
            'data' => $this->reception->overview($companyId, $branch),
            'ticket' => $this->formatTicket(
                $result['ticket'],
                (int) $result['reception_lane'],
                (int) $result['link']->revision,
                $companyId,
                $branch,
                $this->ticketTitles->current($companyId),
                $this->ticketMessages->current($companyId),
            ),
        ], $result['already_registered'] ? 200 : 201);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $result = $this->tickets->receptionTicket($companyId, $branch, $ticket);
        $usedCageTypeIds = $result['ticket']->pesadas
            ->pluck('tipo_java_id')
            ->filter()
            ->unique()
            ->values();
        $formatted = $this->formatTicket(
            $result['ticket'],
            (int) $result['link']->columna,
            (int) $result['link']->revision,
            $companyId,
            $branch,
            $this->ticketTitles->current($companyId),
            $this->ticketMessages->current($companyId),
        );

        return response()->json([
            'data' => [
                'ticket' => $formatted,
                'catalog' => [
                    'cage_types' => DB::table('tipos_java')
                        ->where(function ($query) use ($usedCageTypeIds): void {
                            $query->where('estado', 'ACTIVO');
                            if ($usedCageTypeIds->isNotEmpty()) {
                                $query->orWhereIn('id', $usedCageTypeIds);
                            }
                        })
                        ->orderByDesc('peso_kg')
                        ->orderBy('id')
                        ->get(['id', 'codigo', 'nombre', 'peso_kg', 'estado'])
                        ->map(fn (object $type): array => [
                            'id' => (int) $type->id,
                            'code' => $type->codigo,
                            'name' => $type->nombre,
                            'weight_kg' => (float) $type->peso_kg,
                            'active' => $type->estado === 'ACTIVO',
                        ])->values(),
                ],
            ],
            'ticket' => $formatted,
            'message' => 'Ticket de recepción cargado.',
        ]);
    }

    public function update(
        UpdateLiveChickenReceptionDispatchTicketRequest $request,
        int $ticket,
    ): JsonResponse {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $result = $this->tickets->updateReceptionTicket(
            $companyId,
            $branch,
            $this->context->actor($request, (int) $branch->id),
            $ticket,
            $request->validated(),
            $request->ip(),
        );
        $formatted = $this->formatTicket(
            $result['ticket'],
            (int) $result['link']->columna,
            (int) $result['link']->revision,
            $companyId,
            $branch,
            $this->ticketTitles->current($companyId),
            $this->ticketMessages->current($companyId),
        );

        return response()->json([
            'message' => 'Ticket de recepción y todas sus pesadas actualizados correctamente.',
            'data' => $this->reception->overview($companyId, $branch),
            'ticket' => $formatted,
        ]);
    }

    public function destroyWeighing(
        VoidLiveChickenReceptionDispatchTicketWeighingRequest $request,
        int $ticket,
        int $weighing,
    ): JsonResponse {
        $branch = $this->context->branch($request);
        $companyId = $this->context->companyId($request);
        $result = $this->tickets->voidReceptionTicketWeighing(
            $companyId,
            $branch,
            $this->context->actor($request, (int) $branch->id),
            $ticket,
            $weighing,
            $request->validated(),
            $request->ip(),
        );
        $ticketVoided = $result['ticket']->estado === TicketDespacho::STATUS_VOIDED;

        return response()->json([
            'message' => $ticketVoided
                ? 'Última pesada anulada. El ticket quedó anulado y sus totales fueron actualizados.'
                : 'Pesada anulada. Los totales de recepción, despacho y saldo del cliente fueron actualizados.',
            'voided_weighing_id' => $weighing,
            'ticket_voided' => $ticketVoided,
            'data' => $this->reception->overview($companyId, $branch),
            'ticket' => $this->formatTicket(
                $result['ticket'],
                (int) $result['link']->columna,
                (int) $result['link']->revision,
                $companyId,
                $branch,
                $this->ticketTitles->current($companyId),
                $this->ticketMessages->current($companyId),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function formatTicket(
        TicketDespacho $ticket,
        int $receptionLane,
        int $linkRevision,
        int $companyId,
        object $branch,
        string $ticketTitle,
        ?string $ticketMessage,
    ): array {
        $editability = $this->ticketEditability($ticket, $companyId, $branch);

        return [
            'id' => (int) $ticket->id,
            'draft_id' => $ticket->referencia_externa,
            'code' => $ticket->codigo,
            'operation_type' => $ticket->tipo_operacion,
            'source_module' => $ticket->modulo_origen,
            'status' => $ticket->estado,
            'reception_lane' => $receptionLane,
            'link_revision' => $linkRevision,
            'editable' => $editability['editable'],
            'can_void_last_weighing' => $editability['editable']
                && auth()->user()?->isAdministrator() === true,
            'edit_restriction' => $editability['edit_restriction'],
            'operating_date' => $ticket->jornada?->fecha_operativa?->format('Y-m-d'),
            'registered_at' => $ticket->cerrado_at?->toISOString(),
            'ticket_title' => $ticketTitle,
            'ticket_message' => $ticketMessage,
            'destination' => [
                'type' => 'CLIENTE',
                'id' => $ticket->clienteDestino?->id,
                'name' => $ticket->clienteDestino?->nombre_razon_social,
                'internal_client' => (bool) $ticket->clienteDestino?->es_cliente_interno,
            ],
            'delivery' => $ticket->vehiculoEntrega && $ticket->conductorEntrega
                ? [
                    'vehicle' => [
                        'id' => (int) $ticket->vehiculoEntrega->id,
                        'plate' => $ticket->vehiculoEntrega->placa,
                    ],
                    'driver' => [
                        'id' => (int) $ticket->conductorEntrega->id,
                        'name' => $ticket->conductorEntrega->nombre_completo,
                    ],
                ]
                : null,
            'weighing_count' => $ticket->pesadas->count(),
            'weighings' => $ticket->pesadas
                ->map(fn ($weighing): array => [
                    'id' => (int) $weighing->id,
                    'number' => (int) $weighing->numero,
                    'chicken_condition' => $weighing->condicion_pollo,
                    'chicken_sex' => $weighing->sexo,
                    'sex' => $weighing->sexo,
                    'cage_type' => [
                        'id' => (int) $weighing->tipo_java_id,
                        'code' => $weighing->tipoJava?->codigo,
                        'name' => $weighing->tipoJava?->nombre,
                        'weight_kg' => (float) $weighing->peso_java_kg_snapshot,
                    ],
                    'cage_type_id' => (int) $weighing->tipo_java_id,
                    'birds_per_cage' => (int) $weighing->aves_por_java,
                    'cages' => (int) $weighing->cantidad_javas,
                    'cage_count' => (int) $weighing->cantidad_javas,
                    'birds' => (int) $weighing->cantidad_aves,
                    'read_weight_kg' => (float) $weighing->peso_leido_kg,
                    'gross_weight_kg' => (float) $weighing->peso_bruto_kg,
                    'tare_weight_kg' => (float) $weighing->tara_total_kg,
                    'net_weight_kg' => (float) $weighing->peso_neto_kg,
                    'weight_source' => $weighing->origen_peso === 'MANUAL'
                        ? 'MANUAL'
                        : (string) ($weighing->lecturaBalanza?->balanza?->codigo ?: $weighing->origen_peso),
                    'weighed_at' => $weighing->pesada_at?->toISOString(),
                    'updated_at' => $weighing->updated_at?->toISOString(),
                ])
                ->values(),
        ];
    }

    /** @return array{editable: bool, edit_restriction: string|null} */
    private function ticketEditability(
        TicketDespacho $ticket,
        int $companyId,
        object $branch,
    ): array {
        if ($ticket->estado !== TicketDespacho::STATUS_CLOSED) {
            return [
                'editable' => false,
                'edit_restriction' => 'El ticket ya no está cerrado y vigente; queda disponible solo para consulta.',
            ];
        }

        if ($ticket->jornada?->estado !== JornadaOperativa::STATUS_OPEN) {
            return [
                'editable' => false,
                'edit_restriction' => 'La jornada operativa del ticket está cerrada; queda disponible solo para consulta.',
            ];
        }

        if ((int) $ticket->jornada?->sucursal_id !== (int) $branch->id) {
            return [
                'editable' => false,
                'edit_restriction' => 'El ticket no está habilitado para corrección desde esta sucursal.',
            ];
        }

        $now = CarbonImmutable::now((string) $branch->zona_horaria);
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $cutoffAt = $now->startOfDay()->setTimeFromTimeString($cutoff);
        $currentOperatingDate = $now->greaterThanOrEqualTo($cutoffAt)
            ? $now->addDay()->startOfDay()
            : $now->startOfDay();

        if ($ticket->jornada?->fecha_operativa?->format('Y-m-d')
            !== $currentOperatingDate->format('Y-m-d')) {
            return [
                'editable' => false,
                'edit_restriction' => 'El ticket pertenece a una jornada anterior; queda disponible solo para consulta.',
            ];
        }

        if ($this->hasAppliedFinancialMovement((int) $ticket->id)) {
            return [
                'editable' => false,
                'edit_restriction' => 'El ticket tiene cobros o pagos aplicados; anula primero esos movimientos financieros para corregirlo.',
            ];
        }

        return [
            'editable' => true,
            'edit_restriction' => null,
        ];
    }

    private function hasAppliedFinancialMovement(int $ticketId): bool
    {
        return DB::table('pago_aplicaciones as aplicacion')
            ->join('pagos as pago', 'pago.id', '=', 'aplicacion.pago_id')
            ->where('pago.estado', 'REGISTRADO')
            ->where(function ($query) use ($ticketId): void {
                $query->whereExists(function ($pivot) use ($ticketId): void {
                    $pivot->selectRaw('1')
                        ->from('comprobante_tickets as comprobante_ticket')
                        ->whereColumn('comprobante_ticket.comprobante_id', 'aplicacion.comprobante_id')
                        ->where('comprobante_ticket.ticket_id', $ticketId);
                })->orWhereExists(function ($pivot) use ($ticketId): void {
                    $pivot->selectRaw('1')
                        ->from('comprobante_pesadas as comprobante_pesada')
                        ->join('pesadas as pesada_financiera', 'pesada_financiera.id', '=', 'comprobante_pesada.pesada_id')
                        ->whereColumn('comprobante_pesada.comprobante_id', 'aplicacion.comprobante_id')
                        ->where('pesada_financiera.ticket_id', $ticketId);
                });
            })
            ->exists();
    }
}
