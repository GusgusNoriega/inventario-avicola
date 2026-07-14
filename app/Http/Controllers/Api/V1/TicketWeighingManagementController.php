<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pesada;
use App\Models\ProgramacionRecepcionDetalle;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoJava;
use App\Models\TipoPollo;
use App\Services\FinancialObligationService;
use App\Services\JavaControlService;
use App\Services\OperationContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketWeighingManagementController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly JavaControlService $javaControl,
        private readonly FinancialObligationService $financialObligations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $branch = $this->context->branch($request);
        $currentOperatingDate = $this->currentOperatingDate(
            (int) $branch->empresa_id,
            $branch->zona_horaria
        );
        $search = trim((string) ($filters['search'] ?? ''));

        $tickets = TicketDespacho::query()
            ->whereHas('jornada', fn (Builder $query) => $query->where('sucursal_id', $branch->id))
            ->whereHas('pesadas', fn (Builder $query) => $query->where('estado', Pesada::STATUS_ACTIVE))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('codigo', 'like', "%{$search}%")
                        ->orWhereHas('clienteDestino', function (Builder $clientQuery) use ($search): void {
                            $clientQuery->where('nombre_razon_social', 'like', "%{$search}%");
                        });
                });
            })
            ->with(['jornada', 'clienteDestino', 'almacenDestino'])
            ->withCount([
                'pesadas as active_weighings_count' => fn (Builder $query) => $query
                    ->where('estado', Pesada::STATUS_ACTIVE),
            ])
            ->orderByDesc('cerrado_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (TicketDespacho $ticket) => [
                'id' => $ticket->id,
                'code' => $ticket->codigo,
                'channel' => $ticket->canal,
                'operation_type' => $ticket->tipo_operacion,
                'operating_date' => $ticket->jornada?->fecha_operativa?->format('Y-m-d'),
                'editable' => $this->isEditable($ticket, $currentOperatingDate),
                'customer_type' => $this->customerType($ticket),
                'client' => $this->formatClient($ticket),
                'destination' => $this->formatDestination($ticket),
                'weighings_count' => (int) $ticket->active_weighings_count,
                'closed_at' => $ticket->cerrado_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'branch' => [
                    'id' => $branch->id,
                    'name' => $branch->nombre,
                    'timezone' => $branch->zona_horaria,
                ],
                'current_operating_date' => $currentOperatingDate,
                'tickets' => $tickets,
            ],
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $branch = $this->context->branch($request);
        $selected = $this->ticketForBranch($request, $ticket);
        $this->loadTicket($selected);
        $currentOperatingDate = $this->currentOperatingDate(
            (int) $branch->empresa_id,
            $branch->zona_horaria
        );

        return response()->json([
            'data' => [
                'ticket' => $this->formatTicket(
                    $selected,
                    $branch->zona_horaria,
                    $currentOperatingDate
                ),
                'catalogs' => $this->catalogsFor($selected, (int) $branch->empresa_id),
            ],
        ]);
    }

    public function updateDelivery(Request $request, int $ticket): JsonResponse
    {
        $selected = $this->ticketForBranch($request, $ticket);
        $branch = $this->context->branch($request);
        $companyId = (int) $branch->empresa_id;
        $currentOperatingDate = $this->currentOperatingDate(
            $companyId,
            $branch->zona_horaria
        );
        $this->assertEditable(
            $selected,
            $currentOperatingDate,
            'Solo se puede modificar el transporte de tickets de la jornada operativa actual.'
        );
        abort_unless(
            $selected->tipo_operacion === TicketDespacho::OPERATION_DISPATCH,
            422,
            'Las devoluciones no tienen camión ni chofer de entrega.'
        );
        abort_if(
            (bool) $selected->clienteDestino?->es_cliente_interno,
            422,
            'Los clientes internos de la avícola no requieren camión ni chofer de entrega.'
        );

        $validated = $request->validate([
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehiculos', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $companyId)
                    ->where('estado', 'ACTIVO')),
            ],
            'driver_id' => [
                'required',
                'integer',
                Rule::exists('conductores', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $companyId)
                    ->where('estado', 'ACTIVO')),
            ],
        ], [
            'vehicle_id.required' => 'Selecciona un camión de la flota para la entrega.',
            'vehicle_id.exists' => 'El camión seleccionado no pertenece a la flota activa de la empresa.',
            'driver_id.required' => 'Selecciona un chofer de la flota para la entrega.',
            'driver_id.exists' => 'El chofer seleccionado no pertenece a la flota activa de la empresa.',
        ]);

        DB::transaction(function () use ($request, $selected, $validated, $companyId): void {
            $before = $this->deliveryAuditValues($selected);
            $selected->update([
                'vehiculo_entrega_id' => $validated['vehicle_id'],
                'conductor_entrega_id' => $validated['driver_id'],
            ]);
            $this->javaControl->syncDispatchMovement(
                $selected->refresh(),
                $companyId,
                (int) $selected->jornada->sucursal_id
            );
            $this->writeTicketAudit(
                $companyId,
                $this->context->actor($request, (int) $selected->jornada->sucursal_id)->id,
                $selected->id,
                $before,
                $this->deliveryAuditValues($selected->refresh()),
                $request->ip()
            );
        });

        $this->loadTicket($selected);

        return response()->json([
            'message' => 'Camión y chofer del ticket actualizados correctamente.',
            'data' => ['ticket' => $this->formatTicket(
                $selected,
                $branch->zona_horaria,
                $currentOperatingDate
            )],
        ]);
    }

    public function update(Request $request, int $ticket, int $weighing): JsonResponse
    {
        $selected = $this->ticketForBranch($request, $ticket);
        $branch = $this->context->branch($request);
        $companyId = (int) $branch->empresa_id;
        $currentOperatingDate = $this->currentOperatingDate(
            $companyId,
            $branch->zona_horaria
        );
        $this->assertEditable($selected, $currentOperatingDate);
        $validated = $request->validate([
            'chicken_type_code' => ['required', 'string', 'max:40'],
            'chicken_condition' => ['required', Rule::in([
                Pesada::CHICKEN_CONDITION_LIVE,
                Pesada::CHICKEN_CONDITION_DEAD,
            ])],
            'chicken_sex' => ['required', Rule::in([
                Pesada::SEX_MALE,
                Pesada::SEX_FEMALE,
            ])],
            'cage_type_code' => ['required', 'string', 'max:40'],
            'weight_source' => ['required', Rule::in(['MANUAL', 'BALANZA_1', 'BALANZA_2', 'BALANZA'])],
            'birds_per_cage' => ['required', 'integer', 'min:1', 'max:1000'],
            'cages' => ['required', 'integer', 'min:0', 'max:10000'],
            'gross_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighed_at' => ['required', 'date'],
            'origin_program_detail_id' => [
                Rule::prohibitedIf(
                    $selected->tipo_operacion === TicketDespacho::OPERATION_RETURN
                ),
                'sometimes',
                'integer',
                'min:1',
            ],
        ]);
        $actor = $this->context->actor($request, (int) $branch->id);
        $typeCode = mb_strtoupper(trim($validated['chicken_type_code']), 'UTF-8');
        $condition = $validated['chicken_condition'];

        if ($selected->tipo_operacion === TicketDespacho::OPERATION_RETURN) {
            $typeCode = $condition === Pesada::CHICKEN_CONDITION_DEAD
                ? TipoPollo::CHICKEN_DEAD
                : TipoPollo::CHICKEN_LIVE;
        } elseif ($typeCode === TipoPollo::CHICKEN_DEAD) {
            throw ValidationException::withMessages([
                'chicken_type_code' => 'El pollo muerto solo puede usarse en tickets de devolución.',
            ]);
        } else {
            $condition = Pesada::CHICKEN_CONDITION_LIVE;
        }

        $type = TipoPollo::query()
            ->where('codigo', $typeCode)
            ->where('estado', TipoPollo::STATUS_ACTIVE)
            ->where('permite_despacho', true)
            ->first();
        $cageType = TipoJava::query()
            ->where('codigo', mb_strtoupper(trim($validated['cage_type_code']), 'UTF-8'))
            ->where('estado', 'ACTIVO')
            ->first();

        if (! $type) {
            throw ValidationException::withMessages(['chicken_type_code' => 'El tipo de pollo seleccionado no está disponible.']);
        }
        if (! $cageType) {
            throw ValidationException::withMessages(['cage_type_code' => 'El tipo de java seleccionado no está disponible.']);
        }

        $cages = (int) $validated['cages'];
        $birdsPerCage = (int) $validated['birds_per_cage'];
        $grossWeight = round((float) $validated['gross_weight_kg'], 3);
        $cageWeight = round((float) $cageType->peso_kg, 3);
        $tareWeight = round($cages * $cageWeight, 3);
        $netWeight = round($grossWeight - $tareWeight, 3);

        if ($netWeight <= 0) {
            throw ValidationException::withMessages([
                'gross_weight_kg' => 'El peso bruto debe ser mayor que la tara total de las javas.',
            ]);
        }

        DB::transaction(function () use (
            $request,
            $selected,
            $weighing,
            $validated,
            $type,
            $condition,
            $cageType,
            $cages,
            $birdsPerCage,
            $cageWeight,
            $grossWeight,
            $tareWeight,
            $netWeight,
            $branch,
            $actor,
            $companyId
        ): void {
            $record = Pesada::query()
                ->where('ticket_id', $selected->id)
                ->whereKey($weighing)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($record->estado === Pesada::STATUS_ACTIVE, 409, 'La pesada ya fue anulada.');
            $this->assertFinancialDocumentsAreEditable((int) $selected->id);
            $before = $this->auditValues($record);
            $origin = array_key_exists('origin_program_detail_id', $validated)
                ? $this->journeyOrigin(
                    $selected,
                    (int) $validated['origin_program_detail_id'],
                    $companyId
                )
                : null;

            $changes = [
                'tipo_pollo_id' => $type->id,
                'condicion_pollo' => $condition,
                'sexo' => $validated['chicken_sex'],
                'tipo_java_id' => $cageType->id,
                'origen_peso' => $validated['weight_source'],
                'aves_por_java' => $birdsPerCage,
                'cantidad_javas' => $cages,
                'cantidad_aves' => $birdsPerCage * max($cages, 1),
                'peso_java_kg_snapshot' => $cageWeight,
                'peso_leido_kg' => $grossWeight,
                'peso_bruto_kg' => $grossWeight,
                'tara_total_kg' => $tareWeight,
                'peso_neto_kg' => $netWeight,
                'pesada_at' => CarbonImmutable::parse(
                    $validated['weighed_at'],
                    $branch->zona_horaria
                )->format('Y-m-d H:i:s'),
            ];

            if ($origin) {
                $changes = [
                    ...$changes,
                    'proveedor_origen_id' => $origin->proveedorVehiculo->proveedor_id,
                    'almacen_origen_id' => null,
                    'vehiculo_id' => $origin->proveedorVehiculo->vehiculo_id,
                    'programacion_recepcion_detalle_id' => $origin->id,
                    'placa_snapshot' => $origin->proveedorVehiculo->vehiculo->placa,
                ];
            }

            $record->update($changes);

            $this->javaControl->syncDispatchMovement(
                $selected,
                $companyId,
                (int) $branch->id
            );
            $this->financialObligations->syncTicket(
                $companyId,
                $selected->fresh(),
                $actor,
            );

            $this->writeAudit(
                $companyId,
                $actor->id,
                $record->id,
                'ACTUALIZAR',
                $before,
                $this->auditValues($record->fresh()),
                $request->ip()
            );
        });

        $this->loadTicket($selected);

        return response()->json([
            'message' => 'Pesada actualizada correctamente.',
            'data' => ['ticket' => $this->formatTicket(
                $selected,
                $branch->zona_horaria,
                $currentOperatingDate
            )],
        ]);
    }

    public function destroy(Request $request, int $ticket, int $weighing): JsonResponse
    {
        $selected = $this->ticketForBranch($request, $ticket);
        $branch = $this->context->branch($request);
        $currentOperatingDate = $this->currentOperatingDate(
            (int) $branch->empresa_id,
            $branch->zona_horaria
        );
        $this->assertEditable($selected, $currentOperatingDate);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ]);
        $actor = $this->context->actor($request, (int) $branch->id);

        DB::transaction(function () use ($request, $selected, $weighing, $validated, $branch, $actor): void {
            $record = Pesada::query()
                ->where('ticket_id', $selected->id)
                ->whereKey($weighing)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($record->estado === Pesada::STATUS_ACTIVE, 409, 'La pesada ya fue anulada.');
            $this->assertFinancialDocumentsAreEditable((int) $selected->id);
            $before = $this->auditValues($record);

            $record->update([
                'estado' => Pesada::STATUS_VOIDED,
                'anulada_por' => $actor->id,
                'anulada_at' => now(),
                'motivo_anulacion' => trim($validated['reason']),
            ]);

            $this->javaControl->syncDispatchMovement(
                $selected,
                (int) $branch->empresa_id,
                (int) $branch->id
            );
            $this->financialObligations->syncTicket(
                (int) $branch->empresa_id,
                $selected->fresh(),
                $actor,
            );

            $this->writeAudit(
                (int) $branch->empresa_id,
                $actor->id,
                $record->id,
                'ANULAR',
                $before,
                $this->auditValues($record->fresh()),
                $request->ip()
            );
        });

        $this->loadTicket($selected);

        return response()->json([
            'message' => 'Pesada eliminada correctamente.',
            'data' => ['ticket' => $this->formatTicket(
                $selected,
                $branch->zona_horaria,
                $currentOperatingDate
            )],
        ]);
    }

    private function ticketForBranch(Request $request, int $ticketId): TicketDespacho
    {
        $branch = $this->context->branch($request);

        return TicketDespacho::query()
            ->whereKey($ticketId)
            ->whereHas('jornada', fn (Builder $query) => $query->where('sucursal_id', $branch->id))
            ->firstOrFail();
    }

    private function loadTicket(TicketDespacho $ticket): void
    {
        $ticket->load([
            'jornada',
            'clienteDestino',
            'almacenDestino',
            'vehiculoEntrega',
            'conductorEntrega',
            'precios.tipoPollo',
            'pesadas' => fn ($query) => $query
                ->where('estado', Pesada::STATUS_ACTIVE)
                ->orderBy('numero'),
            'pesadas.tipoPollo',
            'pesadas.tipoJava',
            'pesadas.tipoBandeja',
            'pesadas.ajustePesoMinorista',
            'pesadas.proveedorOrigen',
            'pesadas.almacenOrigen',
            'pesadas.vehiculo',
        ]);
    }

    /** @return array<string, mixed> */
    private function formatTicket(
        TicketDespacho $ticket,
        string $timezone,
        string $currentOperatingDate
    ): array {
        $records = $ticket->pesadas->where('estado', Pesada::STATUS_ACTIVE)->values();
        $editable = $this->isEditable($ticket, $currentOperatingDate);
        $isRetail = $ticket->canal === TicketDespacho::CHANNEL_RETAIL;
        $pricesByType = $ticket->precios->keyBy('tipo_pollo_id');
        $amount = $records->sum(function (Pesada $record) use ($ticket, $pricesByType): float {
            $price = $pricesByType->get($record->tipo_pollo_id);

            return $price
                ? $this->signedAmount($ticket, (float) $record->peso_neto_kg, (float) $price->precio_kg)
                : 0;
        });

        return [
            'id' => $ticket->id,
            'code' => $ticket->codigo,
            'channel' => $ticket->canal,
            'operation_type' => $ticket->tipo_operacion,
            'operating_date' => $ticket->jornada?->fecha_operativa?->format('Y-m-d'),
            'editable' => $editable,
            'edit_restriction' => $editable
                ? null
                : ($isRetail
                    ? 'Los tickets de despacho minorista solo pueden consultarse y reimprimirse en esta vista.'
                    : 'Este ticket pertenece a una jornada anterior y solo puede consultarse en esta vista.'),
            'customer_type' => $this->customerType($ticket),
            'client' => $this->formatClient($ticket),
            'destination' => $this->formatDestination($ticket),
            'delivery' => $this->formatDelivery($ticket),
            'internal_client' => (bool) $ticket->clienteDestino?->es_cliente_interno,
            'closed_at' => $ticket->cerrado_at?->toISOString(),
            'prices' => $isRetail
                ? $ticket->precios
                    ->mapWithKeys(fn ($price) => [
                        (string) ($price->tipoPollo?->codigo ?? $price->tipo_pollo_id) => [
                            'chicken_type' => [
                                'code' => $price->tipoPollo?->codigo,
                                'name' => $price->tipoPollo?->nombre,
                            ],
                            'price_kg' => (float) $price->precio_kg,
                            'source' => $price->origen_precio,
                            'history_id' => $price->precio_historial_id,
                        ],
                    ])
                    ->all()
                : [],
            'summary' => [
                'weighings' => $records->count(),
                'cages' => (int) $records->sum('cantidad_javas'),
                'trays' => (int) $records->sum('cantidad_bandejas'),
                'birds' => (int) $records->sum('cantidad_aves'),
                'gross_weight_kg' => round((float) $records->sum('peso_bruto_kg'), 3),
                'tare_weight_kg' => round((float) $records->sum('tara_total_kg'), 3),
                'net_weight_kg' => round((float) $records->sum('peso_neto_kg'), 3),
                'amount' => $isRetail ? round((float) $amount, 2) : null,
            ],
            'weighings' => $records->map(function (Pesada $record) use ($ticket, $isRetail, $pricesByType, $timezone): array {
                $frozenPrice = $pricesByType->get($record->tipo_pollo_id);

                return [
                    'id' => $record->id,
                    'number' => (int) $record->numero,
                    'chicken_type' => [
                        'code' => $record->tipoPollo?->codigo,
                        'name' => $record->tipoPollo?->nombre,
                    ],
                    'chicken_condition' => $record->condicion_pollo,
                    'chicken_sex' => $record->sexo,
                    'presentation' => $record->presentacion_pollo,
                    'adjustment' => $record->ajustePesoMinorista
                        ? [
                            'code' => $record->ajustePesoMinorista->codigo,
                            'name' => $record->ajustePesoMinorista->nombre,
                            'additional_grams' => (int) $record->ajuste_peso_gramos,
                        ]
                        : null,
                    'cage_type' => [
                        'code' => $record->tipoJava?->codigo,
                        'name' => $record->tipoJava?->nombre,
                        'weight_kg' => (float) $record->peso_java_kg_snapshot,
                    ],
                    'tray_type' => [
                        'code' => $record->tipoBandeja?->codigo,
                        'name' => $record->tipoBandeja?->nombre,
                        'weight_kg' => $record->peso_bandeja_kg_snapshot !== null
                            ? (float) $record->peso_bandeja_kg_snapshot
                            : null,
                    ],
                    'origin' => $record->proveedorOrigen?->nombre_razon_social
                        ?? $record->almacenOrigen?->nombre
                        ?? ($ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN ? 'Devolución de cliente' : 'Sin origen'),
                    'plate' => $record->placa_snapshot ?: $record->vehiculo?->placa,
                    'origin_program_detail_id' => $record->programacion_recepcion_detalle_id,
                    'weight_source' => $record->origen_peso,
                    'birds_per_cage' => (int) $record->aves_por_java,
                    'cages' => (int) $record->cantidad_javas,
                    'birds_per_tray' => (int) $record->aves_por_bandeja,
                    'trays' => (int) $record->cantidad_bandejas,
                    'birds' => (int) $record->cantidad_aves,
                    'read_weight_kg' => (float) $record->peso_leido_kg,
                    'gross_weight_kg' => (float) $record->peso_bruto_kg,
                    'tare_weight_kg' => (float) $record->tara_total_kg,
                    'net_weight_kg' => (float) $record->peso_neto_kg,
                    'price_kg' => $isRetail && $frozenPrice ? (float) $frozenPrice->precio_kg : null,
                    'price_origin' => $isRetail && $frozenPrice ? $frozenPrice->origen_precio : null,
                    'amount' => $isRetail && $frozenPrice
                        ? $this->signedAmount(
                            $ticket,
                            (float) $record->peso_neto_kg,
                            (float) $frozenPrice->precio_kg
                        )
                        : null,
                    'weighed_at' => $record->pesada_at
                        ? CarbonImmutable::createFromFormat(
                            'Y-m-d H:i:s',
                            $record->pesada_at->format('Y-m-d H:i:s'),
                            $timezone
                        )->toIso8601String()
                        : null,
                ];
            })->values(),
        ];
    }

    private function currentOperatingDate(int $companyId, string $timezone): string
    {
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $now = CarbonImmutable::now($timezone);
        $cutoffAt = $now->startOfDay()->setTimeFromTimeString($cutoff);

        return $now->greaterThanOrEqualTo($cutoffAt)
            ? $now->addDay()->toDateString()
            : $now->toDateString();
    }

    private function isFromOperatingDate(TicketDespacho $ticket, string $operatingDate): bool
    {
        return $ticket->jornada?->fecha_operativa?->format('Y-m-d') === $operatingDate;
    }

    private function isEditable(TicketDespacho $ticket, string $operatingDate): bool
    {
        return $ticket->canal === TicketDespacho::CHANNEL_WHOLESALE
            && $this->isFromOperatingDate($ticket, $operatingDate);
    }

    private function assertEditable(
        TicketDespacho $ticket,
        string $operatingDate,
        string $message = 'Solo se pueden modificar pesadas de la jornada operativa actual.'
    ): void {
        $ticket->loadMissing('jornada');

        abort_unless(
            $ticket->canal === TicketDespacho::CHANNEL_WHOLESALE,
            409,
            'Los tickets de despacho minorista solo pueden consultarse y reimprimirse en esta vista.'
        );

        abort_unless(
            $this->isFromOperatingDate($ticket, $operatingDate),
            409,
            $message
        );
    }

    private function assertFinancialDocumentsAreEditable(int $ticketId): void
    {
        $hasAppliedMovement = DB::table('pago_aplicaciones as aplicacion')
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

        abort_if(
            $hasAppliedMovement,
            409,
            'No se puede modificar la pesada porque el ticket ya tiene cobros o pagos aplicados. Anula primero los movimientos financieros relacionados.'
        );
    }

    /** @return array<string, mixed>|null */
    private function formatDelivery(TicketDespacho $ticket): ?array
    {
        if ($ticket->tipo_operacion !== TicketDespacho::OPERATION_DISPATCH) {
            return null;
        }

        return [
            'vehicle' => $ticket->vehiculoEntrega
                ? [
                    'id' => $ticket->vehiculoEntrega->id,
                    'plate' => $ticket->vehiculoEntrega->placa,
                    'description' => $ticket->vehiculoEntrega->descripcion,
                ]
                : null,
            'driver' => $ticket->conductorEntrega
                ? [
                    'id' => $ticket->conductorEntrega->id,
                    'name' => $ticket->conductorEntrega->nombre_completo,
                    'document_number' => $ticket->conductorEntrega->numero_documento,
                ]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function formatDestination(TicketDespacho $ticket): array
    {
        if ($ticket->clienteDestino) {
            return [
                'type' => 'CLIENTE',
                'id' => $ticket->clienteDestino->id,
                'name' => $ticket->clienteDestino->nombre_razon_social,
            ];
        }

        if ($ticket->canal === TicketDespacho::CHANNEL_RETAIL) {
            return [
                'type' => 'VENTA_EXTERNA',
                'id' => null,
                'name' => 'Venta externa (sin cliente)',
            ];
        }

        return [
            'type' => 'ALMACEN',
            'id' => $ticket->almacenDestino?->id,
            'name' => $ticket->almacenDestino?->nombre ?? 'Sin destino registrado',
        ];
    }

    /** @return array<string, mixed>|null */
    private function formatClient(TicketDespacho $ticket): ?array
    {
        return $ticket->clienteDestino
            ? [
                'id' => $ticket->clienteDestino->id,
                'name' => $ticket->clienteDestino->nombre_razon_social,
            ]
            : null;
    }

    private function customerType(TicketDespacho $ticket): string
    {
        if ($ticket->cliente_destino_id !== null) {
            return 'CLIENTE_REGISTRADO';
        }

        return $ticket->canal === TicketDespacho::CHANNEL_RETAIL
            ? 'EXTERNO_SIN_REGISTRO'
            : 'DESTINO_ALMACEN';
    }

    private function signedAmount(TicketDespacho $ticket, float $weight, float $price): float
    {
        $amount = round($weight * $price, 2);

        return $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN
            ? -$amount
            : $amount;
    }

    /** @return array<string, mixed> */
    private function catalogsFor(TicketDespacho $ticket, int $companyId): array
    {
        $typeCodes = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN
            ? [TipoPollo::CHICKEN_LIVE, TipoPollo::CHICKEN_DEAD]
            : [TipoPollo::CHICKEN_LIVE, TipoPollo::CHICKEN_DRESSED, TipoPollo::CHICKEN_PROCESSED];

        return [
            'delivery_trucks' => DB::table('vehiculos')
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO')
                ->orderBy('placa')
                ->get(['id', 'placa', 'marca', 'modelo', 'descripcion'])
                ->map(fn (object $truck) => [
                    'id' => $truck->id,
                    'plate' => $truck->placa,
                    'detail' => collect([$truck->marca, $truck->modelo, $truck->descripcion])
                        ->filter()
                        ->implode(' · '),
                ])
                ->values(),
            'delivery_drivers' => DB::table('conductores')
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre_completo')
                ->get(['id', 'nombre_completo', 'tipo_documento', 'numero_documento'])
                ->map(fn (object $driver) => [
                    'id' => $driver->id,
                    'name' => $driver->nombre_completo,
                    'document' => collect([$driver->tipo_documento, $driver->numero_documento])
                        ->filter()
                        ->implode(' '),
                ])
                ->values(),
            'chicken_types' => TipoPollo::query()
                ->whereIn('codigo', $typeCodes)
                ->where('estado', TipoPollo::STATUS_ACTIVE)
                ->where('permite_despacho', true)
                ->orderBy('id')
                ->get(['codigo', 'nombre'])
                ->map(fn (TipoPollo $type) => ['code' => $type->codigo, 'name' => $type->nombre])
                ->values(),
            'cage_types' => TipoJava::query()
                ->where('estado', 'ACTIVO')
                ->orderBy('id')
                ->get(['codigo', 'nombre', 'peso_kg'])
                ->map(fn (TipoJava $type) => [
                    'code' => $type->codigo,
                    'name' => $type->nombre,
                    'weight_kg' => (float) $type->peso_kg,
                ])
                ->values(),
            'origin_trucks' => $ticket->tipo_operacion === TicketDespacho::OPERATION_DISPATCH
                ? $this->journeyOriginQuery($ticket, $companyId)
                    ->get()
                    ->map(fn (ProgramacionRecepcionDetalle $detail) => [
                        'program_detail_id' => $detail->id,
                        'provider_vehicle_id' => $detail->proveedor_vehiculo_id,
                        'provider_id' => $detail->proveedorVehiculo->proveedor_id,
                        'provider_name' => $detail->proveedorVehiculo->proveedor->nombre_razon_social,
                        'vehicle_id' => $detail->proveedorVehiculo->vehiculo_id,
                        'plate' => $detail->proveedorVehiculo->vehiculo->placa,
                    ])
                    ->values()
                : collect(),
        ];
    }

    /** @return Builder<ProgramacionRecepcionDetalle> */
    private function journeyOriginQuery(TicketDespacho $ticket, int $companyId): Builder
    {
        $ticket->loadMissing('jornada');

        return ProgramacionRecepcionDetalle::query()
            ->where('estado', '!=', ProgramacionRecepcionDetalle::STATUS_CANCELLED)
            ->whereHas('programacion', fn (Builder $query) => $query
                ->where('sucursal_id', $ticket->jornada->sucursal_id)
                ->whereDate('fecha_operativa', $ticket->jornada->fecha_operativa->format('Y-m-d')))
            ->whereHas('proveedorVehiculo', fn (Builder $query) => $query
                ->vigente()
                ->whereHas('proveedor', fn (Builder $providerQuery) => $providerQuery
                    ->where('empresa_id', $companyId)
                    ->where('estado', Tercero::STATUS_ACTIVE)
                    ->conRol(TerceroRole::PROVIDER))
                ->whereHas('vehiculo', fn (Builder $vehicleQuery) => $vehicleQuery
                    ->where('empresa_id', $companyId)
                    ->where('estado', 'ACTIVO')))
            ->with(['proveedorVehiculo.proveedor', 'proveedorVehiculo.vehiculo'])
            ->orderBy('orden_llegada')
            ->orderBy('id');
    }

    private function journeyOrigin(
        TicketDespacho $ticket,
        int $programDetailId,
        int $companyId
    ): ProgramacionRecepcionDetalle {
        $origin = $this->journeyOriginQuery($ticket, $companyId)
            ->whereKey($programDetailId)
            ->lockForUpdate()
            ->first();

        if (! $origin) {
            throw ValidationException::withMessages([
                'origin_program_detail_id' => 'El camión seleccionado no pertenece a la jornada del ticket.',
            ]);
        }

        return $origin;
    }

    /** @return array<string, ?int> */
    private function deliveryAuditValues(TicketDespacho $ticket): array
    {
        return [
            'vehiculo_entrega_id' => $ticket->vehiculo_entrega_id,
            'conductor_entrega_id' => $ticket->conductor_entrega_id,
        ];
    }

    /** @param array<string, ?int> $before @param array<string, ?int> $after */
    private function writeTicketAudit(
        int $companyId,
        int $actorId,
        int $ticketId,
        array $before,
        array $after,
        ?string $ip
    ): void {
        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $actorId,
            'entidad' => 'tickets_despacho',
            'entidad_id' => (string) $ticketId,
            'accion' => 'ACTUALIZAR_TRANSPORTE',
            'datos_antes' => json_encode($before, JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode($after, JSON_THROW_ON_ERROR),
            'direccion_ip' => $ip,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function auditValues(Pesada $record): array
    {
        return [
            'ticket_id' => $record->ticket_id,
            'numero' => $record->numero,
            'tipo_pollo_id' => $record->tipo_pollo_id,
            'condicion_pollo' => $record->condicion_pollo,
            'sexo' => $record->sexo,
            'tipo_java_id' => $record->tipo_java_id,
            'proveedor_origen_id' => $record->proveedor_origen_id,
            'almacen_origen_id' => $record->almacen_origen_id,
            'vehiculo_id' => $record->vehiculo_id,
            'programacion_recepcion_detalle_id' => $record->programacion_recepcion_detalle_id,
            'placa_snapshot' => $record->placa_snapshot,
            'origen_peso' => $record->origen_peso,
            'aves_por_java' => $record->aves_por_java,
            'cantidad_javas' => $record->cantidad_javas,
            'cantidad_aves' => $record->cantidad_aves,
            'peso_java_kg_snapshot' => $record->peso_java_kg_snapshot,
            'peso_bruto_kg' => $record->peso_bruto_kg,
            'tara_total_kg' => $record->tara_total_kg,
            'peso_neto_kg' => $record->peso_neto_kg,
            'pesada_at' => $record->pesada_at?->format('Y-m-d H:i:s'),
            'estado' => $record->estado,
            'anulada_por' => $record->anulada_por,
            'anulada_at' => $record->anulada_at?->format('Y-m-d H:i:s'),
            'motivo_anulacion' => $record->motivo_anulacion,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function writeAudit(
        int $companyId,
        int $actorId,
        int $recordId,
        string $action,
        array $before,
        array $after,
        ?string $ip
    ): void {
        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $actorId,
            'entidad' => 'pesadas',
            'entidad_id' => (string) $recordId,
            'accion' => $action,
            'datos_antes' => json_encode($before, JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode($after, JSON_THROW_ON_ERROR),
            'direccion_ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
