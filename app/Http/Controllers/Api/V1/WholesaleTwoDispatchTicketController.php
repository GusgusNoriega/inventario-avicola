<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\StoreWholesaleTwoDispatchTicketRequest;
use App\Models\TicketDespacho;
use App\Services\OperationContextService;
use App\Services\TicketMessageService;
use App\Services\TicketTitleService;
use App\Services\WholesaleTwoDispatchTicketService;
use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Http\JsonResponse;

class WholesaleTwoDispatchTicketController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly WholesaleTwoDispatchTicketService $tickets,
        private readonly TicketMessageService $ticketMessages,
        private readonly TicketTitleService $ticketTitles,
    ) {}

    public function store(StoreWholesaleTwoDispatchTicketRequest $request): JsonResponse
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

    /** @return array<string, mixed> */
    private function formatTicket(
        TicketDespacho $ticket,
        string $ticketTitle,
        ?string $ticketMessage
    ): array {
        $ticket->loadMissing([
            'pesadas.tipoPollo',
            'pesadas.tipoJava',
            'pesadas.ajustePesoMayoristaDos',
            'precios.tipoPollo',
        ]);
        $records = $ticket->pesadas->values();
        $prices = $ticket->precios->keyBy('tipo_pollo_id');
        $sign = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN ? -1 : 1;
        $totalAmount = $records->sum(function ($weighing) use ($prices, $sign): float {
            $price = $prices->get($weighing->tipo_pollo_id);

            return $price
                ? $sign * round(
                    (float) $weighing->peso_neto_kg * (float) $price->precio_kg,
                    2,
                    PHP_ROUND_HALF_UP,
                )
                : 0;
        });

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
            'delivery' => $ticket->vehiculoEntrega || $ticket->conductorEntrega
                ? [
                    'vehicle' => $ticket->vehiculoEntrega
                        ? [
                            'id' => $ticket->vehiculoEntrega->id,
                            'plate' => $ticket->vehiculoEntrega->placa,
                        ]
                        : null,
                    'driver' => $ticket->conductorEntrega
                        ? [
                            'id' => $ticket->conductorEntrega->id,
                            'name' => $ticket->conductorEntrega->nombre_completo,
                        ]
                        : null,
                ]
                : null,
            'prices' => $ticket->precios->mapWithKeys(fn ($price): array => [
                $price->tipoPollo?->codigo ?? (string) $price->tipo_pollo_id => [
                    'price_kg' => round((float) $price->precio_kg, 2, PHP_ROUND_HALF_UP),
                    'source' => $price->origen_precio,
                    'history_id' => $price->precio_historial_id === null
                        ? null
                        : (int) $price->precio_historial_id,
                ],
            ]),
            'weighing_count' => $records->count(),
            'totals' => [
                'weighings' => $records->count(),
                'cages' => (int) $records->sum('cantidad_javas'),
                'birds' => (int) $records->sum('cantidad_aves'),
                'read_weight_kg' => round((float) $records->sum('peso_leido_kg'), 3),
                'adjustment_weight_kg' => round(
                    (float) $records->sum(
                        fn ($weighing): float => (float) $weighing->peso_bruto_kg
                            - (float) $weighing->peso_leido_kg
                    ),
                    3
                ),
                'gross_weight_kg' => round((float) $records->sum('peso_bruto_kg'), 3),
                'tare_weight_kg' => round((float) $records->sum('tara_total_kg'), 3),
                'net_weight_kg' => round((float) $records->sum('peso_neto_kg'), 3),
                'amount' => round($totalAmount, 2, PHP_ROUND_HALF_UP),
            ],
            'weighings' => $records
                ->map(function ($weighing) use ($prices, $sign): array {
                    $adjustmentGrams = (int) ($weighing->ajuste_peso_mayorista_2_gramos ?? 0);
                    $totalAdjustmentGrams = $adjustmentGrams * (int) $weighing->cantidad_aves;
                    $frozenPrice = $prices->get($weighing->tipo_pollo_id);
                    $price = $frozenPrice
                        ? round((float) $frozenPrice->precio_kg, 2, PHP_ROUND_HALF_UP)
                        : null;

                    return [
                        'id' => $weighing->id,
                        'number' => $weighing->numero,
                        'chicken_type_code' => $weighing->tipoPollo?->codigo,
                        'chicken_condition' => $weighing->condicion_pollo,
                        'chicken_sex' => $weighing->sexo,
                        'chicken_presentation' => $weighing->presentacion_pollo,
                        'chicken_variant_code' => WholesaleTwoChickenVariant::fromStored(
                            $weighing->tipoPollo?->codigo,
                            $weighing->sexo,
                            $weighing->presentacion_pollo,
                        ),
                        'adjustment' => $weighing->ajustePesoMayoristaDos
                            ? [
                                'id' => (int) $weighing->ajustePesoMayoristaDos->id,
                                'code' => $weighing->ajustePesoMayoristaDos->codigo,
                                'name' => $weighing->ajustePesoMayoristaDos->nombre,
                                'additional_grams' => $adjustmentGrams,
                                'total_grams' => $totalAdjustmentGrams,
                                'total_weight_kg' => round($totalAdjustmentGrams / 1000, 3),
                                'configurable' => $weighing->ajustePesoMayoristaDos->isConfigurable(),
                            ]
                            : null,
                        'cage_type_code' => $weighing->tipoJava?->codigo,
                        'cage_type' => $weighing->tipoJava?->nombre,
                        'cage_weight_kg' => (float) $weighing->peso_java_kg_snapshot,
                        'birds_per_cage' => (int) $weighing->aves_por_java,
                        'cage_count' => (int) $weighing->cantidad_javas,
                        'birds' => (int) $weighing->cantidad_aves,
                        'weight_source' => $weighing->origen_peso,
                        'read_weight_kg' => (float) $weighing->peso_leido_kg,
                        'gross_weight_kg' => (float) $weighing->peso_bruto_kg,
                        'tare_weight_kg' => (float) $weighing->tara_total_kg,
                        'net_weight_kg' => (float) $weighing->peso_neto_kg,
                        'price_kg' => $price,
                        'price_origin' => $frozenPrice?->origen_precio,
                        'price_history_id' => $frozenPrice?->precio_historial_id,
                        'amount' => $price === null
                            ? null
                            : $sign * round(
                                (float) $weighing->peso_neto_kg * $price,
                                2,
                                PHP_ROUND_HALF_UP,
                            ),
                        'weighed_at' => $weighing->pesada_at?->toISOString(),
                    ];
                })
                ->values(),
        ];
    }
}
