<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pesada;
use App\Models\ProgramacionRecepcionDetalle;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TicketPrecio;
use App\Models\TipoJava;
use App\Models\TipoPollo;
use App\Services\FinancialAuditService;
use App\Services\FinancialObligationService;
use App\Services\JavaControlService;
use App\Services\LiveChickenReceptionTicketInventoryService;
use App\Services\OperationContextService;
use App\Services\TicketMessageService;
use App\Services\TicketTitleService;
use App\Services\WholesaleTwoWeightAdjustmentService;
use App\Support\WholesaleTwoChickenVariant;
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
        private readonly LiveChickenReceptionTicketInventoryService $receptionTicketInventory,
        private readonly FinancialAuditService $financialAudit,
        private readonly FinancialObligationService $financialObligations,
        private readonly TicketMessageService $ticketMessages,
        private readonly TicketTitleService $ticketTitles,
        private readonly WholesaleTwoWeightAdjustmentService $wholesaleTwoAdjustments,
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
        $isAdministrator = $request->user()?->isAdministrator() === true;
        $search = trim((string) ($filters['search'] ?? ''));

        $tickets = TicketDespacho::query()
            ->whereHas('jornada', fn (Builder $query) => $query->where('sucursal_id', $branch->id))
            ->where('estado', '!=', TicketDespacho::STATUS_VOIDED)
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
                'source_module' => $ticket->modulo_origen,
                'operation_type' => $ticket->tipo_operacion,
                'status' => $ticket->estado,
                'operating_date' => $ticket->jornada?->fecha_operativa?->format('Y-m-d'),
                'editable' => $this->isEditable($ticket, $currentOperatingDate),
                'edit_restriction' => $ticket->modulo_origen === TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION
                    ? 'Las pesadas de este ticket se editan completas desde Recepción de pollo vivo.'
                    : ($this->isEditable($ticket, $currentOperatingDate)
                        ? null
                        : 'Este ticket solo puede consultarse en esta vista.'),
                'delivery_editable' => $this->isDeliveryEditable($ticket, $currentOperatingDate),
                'delivery_mode' => $ticket->asignacion_transporte_posterior
                    ? $ticket->resolvedDeliveryMode()
                    : null,
                'delivery_assignment_deferred' => (bool) $ticket->asignacion_transporte_posterior,
                'can_void' => $isAdministrator
                    && $ticket->estado === TicketDespacho::STATUS_CLOSED,
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
                'access' => [
                    'is_administrator' => $isAdministrator,
                    'can_void_tickets' => $isAdministrator,
                ],
            ],
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return $this->showTicket($request, $ticket, false);
    }

    public function showForFinance(Request $request, int $ticket): JsonResponse
    {
        return $this->showTicket($request, $ticket, true);
    }

    private function showTicket(Request $request, int $ticket, bool $allowHistoricalEditing): JsonResponse
    {
        $selected = $allowHistoricalEditing
            ? $this->ticketForCompany($request, $ticket)
            : $this->ticketForBranch($request, $ticket);
        $this->loadTicket($selected);
        $branch = $allowHistoricalEditing
            ? $this->branchForTicket($selected, $this->context->companyId($request))
            : $this->context->branch($request);
        $currentOperatingDate = $this->currentOperatingDate(
            (int) $branch->empresa_id,
            $branch->zona_horaria
        );
        $isAdministrator = $request->user()?->isAdministrator() === true;

        return response()->json([
            'data' => [
                'ticket_title' => $this->ticketTitles->current((int) $branch->empresa_id),
                'ticket_message' => $this->ticketMessages->current((int) $branch->empresa_id),
                'ticket' => $this->formatTicket(
                    $selected,
                    $branch->zona_horaria,
                    $currentOperatingDate,
                    $isAdministrator,
                    $allowHistoricalEditing
                ),
                'catalogs' => $this->catalogsFor($selected, (int) $branch->empresa_id),
                'access' => [
                    'is_administrator' => $isAdministrator,
                    'can_void_tickets' => $isAdministrator,
                ],
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
        $this->assertDeliveryEditable(
            $selected,
            $currentOperatingDate,
            'Solo se puede modificar el transporte de tickets de la jornada operativa actual.'
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
                $currentOperatingDate,
                $request->user()?->isAdministrator() === true
            )],
        ]);
    }

    public function update(Request $request, int $ticket, int $weighing): JsonResponse
    {
        return $this->updateWeighing($request, $ticket, $weighing, false);
    }

    public function updateForFinance(Request $request, int $ticket, int $weighing): JsonResponse
    {
        return $this->updateWeighing($request, $ticket, $weighing, true);
    }

    private function updateWeighing(
        Request $request,
        int $ticket,
        int $weighing,
        bool $allowHistoricalEditing,
    ): JsonResponse {
        $selected = $allowHistoricalEditing
            ? $this->ticketForCompany($request, $ticket)
            : $this->ticketForBranch($request, $ticket);
        $this->assertNotLiveChickenReceptionTicket($selected);
        $branch = $allowHistoricalEditing
            ? $this->branchForTicket($selected, $this->context->companyId($request))
            : $this->context->branch($request);
        $companyId = (int) $branch->empresa_id;
        $currentOperatingDate = $this->currentOperatingDate(
            $companyId,
            $branch->zona_horaria
        );
        if ($allowHistoricalEditing) {
            $this->assertFinanceEditable($selected);
        } else {
            $this->assertEditable($selected, $currentOperatingDate);
        }
        $usesWholesaleTwoVariants = $this->usesWholesaleTwoVariants($selected);
        $validated = $request->validate([
            'chicken_type_code' => ['required', 'string', 'max:40'],
            'chicken_condition' => ['required', Rule::in([
                Pesada::CHICKEN_CONDITION_LIVE,
                Pesada::CHICKEN_CONDITION_DEAD,
            ])],
            'chicken_variant_code' => $usesWholesaleTwoVariants
                ? ['required', Rule::in(WholesaleTwoChickenVariant::codes())]
                : ['prohibited'],
            'chicken_sex' => $usesWholesaleTwoVariants
                ? ['sometimes', 'nullable', Rule::in([
                    Pesada::SEX_MALE,
                    Pesada::SEX_FEMALE,
                ])]
                : ['required', Rule::in([
                    Pesada::SEX_MALE,
                    Pesada::SEX_FEMALE,
                ])],
            'cage_type_code' => ['required', 'string', 'max:40'],
            'weight_source' => ['required', Rule::in(['MANUAL', 'BALANZA_1', 'BALANZA_2', 'BALANZA'])],
            'birds_per_cage' => ['required', 'integer', 'min:1', 'max:1000'],
            'cages' => ['required', 'integer', 'min:0', 'max:10000'],
            'read_weight_kg' => $usesWholesaleTwoVariants
                ? ['required', 'numeric', 'gt:0', 'max:99999999.999']
                : ['prohibited'],
            'gross_weight_kg' => $usesWholesaleTwoVariants
                ? ['prohibited']
                : ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighed_at' => ['required', 'date'],
            'origin_program_detail_id' => [
                Rule::prohibitedIf(
                    $selected->tipo_operacion === TicketDespacho::OPERATION_RETURN
                ),
                'sometimes',
                'integer',
                'min:1',
            ],
            'correction_reason' => $allowHistoricalEditing
                ? ['required', 'string', 'min:3', 'max:250']
                : ['prohibited'],
            'expected_updated_at' => $allowHistoricalEditing
                ? ['sometimes', 'required', 'date']
                : ['prohibited'],
            'price_kg' => $allowHistoricalEditing
                ? ['sometimes', 'required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999']
                : ['prohibited'],
        ], [
            'price_kg.required' => 'Ingresa el precio por kilogramo del producto seleccionado.',
            'price_kg.numeric' => 'El precio por kilogramo debe ser un número válido.',
            'price_kg.decimal' => 'El precio por kilogramo puede tener hasta cuatro decimales.',
            'price_kg.gt' => 'El precio por kilogramo debe ser mayor que cero.',
            'price_kg.max' => 'El precio por kilogramo supera el máximo permitido.',
        ]);
        if ($allowHistoricalEditing) {
            $this->assertWeighedAtBelongsToTicketJourney(
                $selected,
                (string) $validated['weighed_at'],
                (string) $branch->zona_horaria,
                $companyId,
            );
        }
        $actor = $this->context->actor($request, (int) $branch->id);
        $historicalRecord = $allowHistoricalEditing
            ? Pesada::query()
                ->where('ticket_id', $selected->id)
                ->whereKey($weighing)
                ->firstOrFail(['id', 'tipo_pollo_id', 'tipo_java_id', 'estado'])
            : null;
        if ($historicalRecord) {
            abort_unless(
                $historicalRecord->estado === Pesada::STATUS_ACTIVE,
                409,
                'La pesada ya fue anulada.'
            );
        }
        $typeCode = mb_strtoupper(trim($validated['chicken_type_code']), 'UTF-8');
        $condition = $validated['chicken_condition'];
        $variantDefinition = $usesWholesaleTwoVariants
            ? WholesaleTwoChickenVariant::definition($validated['chicken_variant_code'])
            : null;

        if (
            $usesWholesaleTwoVariants
            && (! $variantDefinition || $variantDefinition['chicken_type_code'] !== $typeCode)
        ) {
            throw ValidationException::withMessages([
                'chicken_variant_code' => 'La clasificación seleccionada no corresponde al tipo de pollo.',
            ]);
        }

        if (
            $usesWholesaleTwoVariants
            && array_key_exists('chicken_sex', $validated)
            && $validated['chicken_sex'] !== null
            && $validated['chicken_sex'] !== $variantDefinition['sex']
        ) {
            throw ValidationException::withMessages([
                'chicken_sex' => 'El sexo enviado no corresponde a la clasificación seleccionada.',
            ]);
        }

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
            ->where(function (Builder $query) use ($historicalRecord): void {
                $query->where(function (Builder $active): void {
                    $active->where('estado', TipoPollo::STATUS_ACTIVE)
                        ->where('permite_despacho', true);
                });
                if ($historicalRecord?->tipo_pollo_id) {
                    $query->orWhere('id', $historicalRecord->tipo_pollo_id);
                }
            })
            ->when(
                ! $usesWholesaleTwoVariants,
                fn ($query) => $query->whereNotIn(
                    'codigo',
                    TipoPollo::wholesaleTwoManualPriceCodes(),
                ),
            )
            ->first();
        $cageType = TipoJava::query()
            ->where('codigo', mb_strtoupper(trim($validated['cage_type_code']), 'UTF-8'))
            ->where(function (Builder $query) use ($historicalRecord): void {
                $query->where('estado', 'ACTIVO');
                if ($historicalRecord?->tipo_java_id) {
                    $query->orWhere('id', $historicalRecord->tipo_java_id);
                }
            })
            ->first();

        if (! $type) {
            throw ValidationException::withMessages(['chicken_type_code' => 'El tipo de pollo seleccionado no está disponible.']);
        }
        if (! $cageType) {
            throw ValidationException::withMessages(['cage_type_code' => 'El tipo de java seleccionado no está disponible.']);
        }

        $cages = (int) $validated['cages'];
        $birdsPerCage = (int) $validated['birds_per_cage'];
        $readWeight = round((float) ($usesWholesaleTwoVariants
            ? $validated['read_weight_kg']
            : $validated['gross_weight_kg']), 3);
        $grossWeight = $usesWholesaleTwoVariants ? null : $readWeight;
        $cageWeight = round((float) $cageType->peso_kg, 3);
        $tareWeight = round($cages * $cageWeight, 3);
        $netWeight = $grossWeight !== null ? round($grossWeight - $tareWeight, 3) : null;

        if (! $allowHistoricalEditing && $netWeight !== null && $netWeight <= 0) {
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
            $readWeight,
            $grossWeight,
            $tareWeight,
            $netWeight,
            $branch,
            $actor,
            $companyId,
            $usesWholesaleTwoVariants,
            $variantDefinition,
            $allowHistoricalEditing,
            $currentOperatingDate
        ): void {
            $lockedTicket = TicketDespacho::query()
                ->whereKey($selected->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($allowHistoricalEditing) {
                $this->assertFinanceEditable($lockedTicket);
            } else {
                $this->assertEditable($lockedTicket, $currentOperatingDate);
            }
            $lockedBranch = $allowHistoricalEditing
                ? $this->branchForTicket($lockedTicket, $companyId)
                : $branch;
            if ($allowHistoricalEditing) {
                $this->assertWeighedAtBelongsToTicketJourney(
                    $lockedTicket,
                    (string) $validated['weighed_at'],
                    (string) $lockedBranch->zona_horaria,
                    $companyId,
                );
            }

            $record = Pesada::query()
                ->with(['tipoPollo', 'ajustePesoMayoristaDos'])
                ->where('ticket_id', $lockedTicket->id)
                ->whereKey($weighing)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($record->estado === Pesada::STATUS_ACTIVE, 409, 'La pesada ya fue anulada.');
            if (
                $allowHistoricalEditing
                && array_key_exists('expected_updated_at', $validated)
                && $record->updated_at
            ) {
                $expectedTimestamp = CarbonImmutable::parse(
                    (string) $validated['expected_updated_at']
                )->getTimestamp();
                abort_if(
                    $expectedTimestamp !== $record->updated_at->getTimestamp(),
                    409,
                    'La pesada fue modificada por otro usuario. Vuelve a abrirla antes de guardar tu corrección.'
                );
            }
            $this->assertFinancialDocumentsAreEditable((int) $lockedTicket->id);
            if (
                $allowHistoricalEditing
                && (int) $record->tipo_java_id === (int) $cageType->id
            ) {
                $cageWeight = round((float) $record->peso_java_kg_snapshot, 3);
            }
            $tareWeight = round($cages * $cageWeight, 3);
            if (! $usesWholesaleTwoVariants) {
                $grossWeight = $readWeight;
                $netWeight = round($grossWeight - $tareWeight, 3);
                if ($netWeight <= 0) {
                    throw ValidationException::withMessages([
                        'gross_weight_kg' => 'El peso bruto debe ser mayor que la tara total de las javas.',
                    ]);
                }
            }
            $currentTypeCode = $record->tipoPollo?->codigo;
            if (
                $usesWholesaleTwoVariants
                && $currentTypeCode !== $type->codigo
                && (
                    TipoPollo::requiresWholesaleTwoManualPrice($currentTypeCode)
                    || TipoPollo::requiresWholesaleTwoManualPrice($type->codigo)
                )
            ) {
                throw ValidationException::withMessages([
                    'chicken_variant_code' => 'No puedes cambiar hacia o desde Gallina roja, Gallina doble u Otros en Gestión de pesadas porque su precio pertenece al ticket. Corrige esa clasificación antes de registrar el ticket en Despacho mayorista 2.',
                ]);
            }

            $ticketPrices = TicketPrecio::query()
                ->where('ticket_id', $lockedTicket->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $ticketPrice = $ticketPrices->first(
                fn (TicketPrecio $price): bool => (int) $price->tipo_pollo_id === (int) $type->id
            );
            $priceWasProvided = array_key_exists('price_kg', $validated);
            $requiresTargetPrice = $ticketPrices->isNotEmpty()
                || $usesWholesaleTwoVariants
                || $lockedTicket->cliente_destino_id !== null;

            if (! $ticketPrice && $ticketPrices->isNotEmpty() && ! $allowHistoricalEditing) {
                $validationField = $usesWholesaleTwoVariants
                    ? 'chicken_variant_code'
                    : 'chicken_type_code';

                throw ValidationException::withMessages([
                    $validationField => "No puedes cambiar esta pesada a {$type->nombre} porque el ticket no tiene un precio asignado para ese producto. La pesada no se modificó para evitar que el total quede en cero.",
                ]);
            }

            if (
                $allowHistoricalEditing
                && ! $ticketPrice
                && $requiresTargetPrice
                && ! $priceWasProvided
            ) {
                throw ValidationException::withMessages([
                    'price_kg' => "Asigna el precio por kilogramo de {$type->nombre} para guardar la pesada sin dejar su monto en cero.",
                ]);
            }

            if ($allowHistoricalEditing && $priceWasProvided) {
                $requestedPrice = bcadd((string) $validated['price_kg'], '0', 4);
                $priceBefore = $ticketPrice
                    ? $this->ticketPriceAuditValues($ticketPrice)
                    : null;

                if (! $ticketPrice) {
                    $ticketPrice = TicketPrecio::query()->create([
                        'ticket_id' => $lockedTicket->id,
                        'tipo_pollo_id' => $type->id,
                        'precio_historial_id' => null,
                        'precio_kg' => $requestedPrice,
                        'origen_precio' => 'MANUAL',
                        'congelado_por' => $actor->id,
                    ]);
                } elseif (bccomp((string) $ticketPrice->precio_kg, $requestedPrice, 4) !== 0) {
                    $ticketPrice->update([
                        'precio_kg' => $requestedPrice,
                        'origen_precio' => 'MANUAL',
                        'congelado_por' => $actor->id,
                    ]);
                }

                if ($priceBefore === null || $priceBefore['precio_kg'] !== $requestedPrice) {
                    $this->financialAudit->record(
                        $companyId,
                        (int) $actor->id,
                        'ticket_precios',
                        (int) $ticketPrice->id,
                        $priceBefore === null ? 'CREAR_PRECIO' : 'EDITAR_PRECIO',
                        $priceBefore,
                        [
                            ...$this->ticketPriceAuditValues($ticketPrice->fresh()),
                            'motivo_correccion' => trim((string) $validated['correction_reason']),
                        ],
                        $request->ip(),
                    );
                }
            }

            $before = $this->auditValues($record);
            $origin = array_key_exists('origin_program_detail_id', $validated)
                ? $this->journeyOrigin(
                    $lockedTicket,
                    (int) $validated['origin_program_detail_id'],
                    $companyId
                )
                : null;
            $adjustmentId = null;
            $adjustmentGrams = null;

            if ($usesWholesaleTwoVariants) {
                $currentVariantCode = WholesaleTwoChickenVariant::fromStored(
                    $record->tipoPollo?->codigo,
                    $record->sexo,
                    $record->presentacion_pollo,
                );

                if ($currentVariantCode === $validated['chicken_variant_code']) {
                    $adjustmentId = $record->ajuste_peso_mayorista_2_id;
                    $adjustmentGrams = $record->ajuste_peso_mayorista_2_gramos;
                } else {
                    $adjustment = $this->wholesaleTwoAdjustments->resolveForVariant(
                        $companyId,
                        $validated['chicken_variant_code'],
                        true,
                    );
                    $adjustmentId = $adjustment->id;
                    $adjustmentGrams = (int) $adjustment->gramos_adicionales;
                }

                $birdCount = $birdsPerCage * max($cages, 1);
                $totalAdjustmentKg = ((int) $adjustmentGrams * $birdCount) / 1000;
                $grossWeight = round($readWeight + $totalAdjustmentKg, 3);
                $netWeight = round($grossWeight - $tareWeight, 3);

                if ($netWeight <= 0) {
                    throw ValidationException::withMessages([
                        'read_weight_kg' => 'El peso leído con la merma aplicada debe ser mayor que la tara total de las javas.',
                    ]);
                }
            }

            $changes = [
                'tipo_pollo_id' => $type->id,
                'condicion_pollo' => $condition,
                'sexo' => $usesWholesaleTwoVariants
                    ? $variantDefinition['sex']
                    : $validated['chicken_sex'],
                'tipo_java_id' => $cageType->id,
                'origen_peso' => $validated['weight_source'],
                'aves_por_java' => $birdsPerCage,
                'cantidad_javas' => $cages,
                'cantidad_aves' => $birdsPerCage * max($cages, 1),
                'peso_java_kg_snapshot' => $cageWeight,
                'peso_leido_kg' => $readWeight,
                'peso_bruto_kg' => $grossWeight,
                'tara_total_kg' => $tareWeight,
                'peso_neto_kg' => $netWeight,
                'pesada_at' => CarbonImmutable::parse(
                    $validated['weighed_at'],
                    $lockedBranch->zona_horaria
                )->format('Y-m-d H:i:s'),
            ];

            if ($usesWholesaleTwoVariants) {
                $changes['presentacion_pollo'] = $variantDefinition['presentation'];
                $changes['ajuste_peso_mayorista_2_id'] = $adjustmentId;
                $changes['ajuste_peso_mayorista_2_gramos'] = $adjustmentGrams;
            }

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
                $lockedTicket,
                $companyId,
                (int) $lockedBranch->id
            );
            $this->receptionTicketInventory->sync(
                $companyId,
                $actor,
                $lockedTicket,
                true,
            );
            $this->financialObligations->syncTicket(
                $companyId,
                $lockedTicket->fresh(),
                $actor,
            );

            $this->writeAudit(
                $companyId,
                $actor->id,
                $record->id,
                $allowHistoricalEditing ? 'ACTUALIZAR_FINANZAS' : 'ACTUALIZAR',
                $before,
                [
                    ...$this->auditValues($record->fresh()),
                    ...($allowHistoricalEditing ? [
                        'motivo_correccion' => trim((string) $validated['correction_reason']),
                    ] : []),
                ],
                $request->ip()
            );
        }, 3);

        $this->loadTicket($selected);

        return response()->json([
            'message' => 'Pesada actualizada correctamente.',
            'data' => ['ticket' => $this->formatTicket(
                $selected,
                $branch->zona_horaria,
                $currentOperatingDate,
                $request->user()?->isAdministrator() === true,
                $allowHistoricalEditing
            )],
        ]);
    }

    public function destroy(Request $request, int $ticket, int $weighing): JsonResponse
    {
        $selected = $this->ticketForBranch($request, $ticket);
        $this->assertNotLiveChickenReceptionTicket($selected);
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
                ->with('tipoPollo')
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

            if (
                TipoPollo::requiresWholesaleTwoManualPrice($record->tipoPollo?->codigo)
                && ! Pesada::query()
                    ->where('ticket_id', $selected->id)
                    ->where('tipo_pollo_id', $record->tipo_pollo_id)
                    ->where('estado', Pesada::STATUS_ACTIVE)
                    ->exists()
            ) {
                DB::table('ticket_precios')
                    ->where('ticket_id', $selected->id)
                    ->where('tipo_pollo_id', $record->tipo_pollo_id)
                    ->delete();
            }

            $this->javaControl->syncDispatchMovement(
                $selected,
                (int) $branch->empresa_id,
                (int) $branch->id
            );
            $this->receptionTicketInventory->sync(
                (int) $branch->empresa_id,
                $actor,
                $selected,
                true,
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
                $currentOperatingDate,
                $request->user()?->isAdministrator() === true
            )],
        ]);
    }

    private function ticketForBranch(Request $request, int $ticketId): TicketDespacho
    {
        $branch = $this->context->branch($request);

        return TicketDespacho::query()
            ->whereKey($ticketId)
            ->where('estado', '!=', TicketDespacho::STATUS_VOIDED)
            ->whereHas('jornada', fn (Builder $query) => $query->where('sucursal_id', $branch->id))
            ->firstOrFail();
    }

    private function ticketForCompany(Request $request, int $ticketId): TicketDespacho
    {
        $companyId = $this->context->companyId($request);

        return TicketDespacho::query()
            ->whereKey($ticketId)
            ->where('estado', '!=', TicketDespacho::STATUS_VOIDED)
            ->whereHas('jornada.sucursal', fn (Builder $query) => $query
                ->where('empresa_id', $companyId))
            ->firstOrFail();
    }

    /** @return object{id: int, empresa_id: int, codigo: string, nombre: string, zona_horaria: string} */
    private function branchForTicket(TicketDespacho $ticket, int $companyId): object
    {
        $ticket->loadMissing('jornada.sucursal');
        $branch = $ticket->jornada?->sucursal;

        abort_unless($branch && (int) $branch->empresa_id === $companyId, 404);

        return (object) [
            'id' => (int) $branch->id,
            'empresa_id' => (int) $branch->empresa_id,
            'codigo' => (string) $branch->codigo,
            'nombre' => (string) $branch->nombre,
            'zona_horaria' => (string) $branch->zona_horaria,
        ];
    }

    private function loadTicket(TicketDespacho $ticket): void
    {
        $ticket->load([
            'jornada.sucursal',
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
            'pesadas.ajustePesoMayoristaDos',
            'pesadas.proveedorOrigen',
            'pesadas.almacenOrigen',
            'pesadas.vehiculo',
        ]);
    }

    /** @return array<string, mixed> */
    private function formatTicket(
        TicketDespacho $ticket,
        string $timezone,
        string $currentOperatingDate,
        bool $isAdministrator = false,
        bool $allowHistoricalEditing = false,
    ): array {
        $records = $ticket->pesadas->where('estado', Pesada::STATUS_ACTIVE)->values();
        $isLiveReception = $ticket->modulo_origen === TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION;
        $editable = $allowHistoricalEditing
            ? $this->isFinanceEditable($ticket)
            : $this->isEditable($ticket, $currentOperatingDate);
        $deliveryEditable = $this->isDeliveryEditable($ticket, $currentOperatingDate);
        $isRetail = $ticket->canal === TicketDespacho::CHANNEL_RETAIL;
        $usesWholesaleTwoVariants = $this->usesWholesaleTwoVariants($ticket);
        $usesSalePrices = $allowHistoricalEditing
            || $isRetail
            || $ticket->modulo_origen === TicketDespacho::SOURCE_WHOLESALE_TWO;
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
            'source_module' => $ticket->modulo_origen,
            'operation_type' => $ticket->tipo_operacion,
            'status' => $ticket->estado,
            'operating_date' => $ticket->jornada?->fecha_operativa?->format('Y-m-d'),
            'editable' => $editable,
            'delivery_editable' => $deliveryEditable,
            'delivery_assignment_deferred' => (bool) $ticket->asignacion_transporte_posterior,
            'can_void' => $isAdministrator
                && $ticket->estado === TicketDespacho::STATUS_CLOSED,
            'edit_restriction' => $editable
                ? null
                : ($isLiveReception
                    ? 'Las pesadas de este ticket se editan completas desde Recepción de pollo vivo.'
                    : ($allowHistoricalEditing
                    ? ($ticket->estado !== TicketDespacho::STATUS_CLOSED
                        ? 'Solo se pueden corregir pesadas de tickets cerrados.'
                        : 'La edición histórica de pesadas está disponible únicamente para tickets mayoristas.')
                    : ($isRetail
                        ? ($deliveryEditable
                            ? 'Las pesadas minoristas son de solo consulta; el camión y el chofer se gestionan por separado.'
                            : 'Los tickets de despacho minorista solo pueden consultarse y reimprimirse en esta vista.')
                        : 'Este ticket pertenece a una jornada anterior y solo puede consultarse en esta vista.'))),
            'customer_type' => $this->customerType($ticket),
            'client' => $this->formatClient($ticket),
            'destination' => $this->formatDestination($ticket),
            'delivery' => $this->formatDelivery($ticket),
            'internal_client' => (bool) $ticket->clienteDestino?->es_cliente_interno,
            'closed_at' => $ticket->cerrado_at?->toISOString(),
            'prices' => $usesSalePrices
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
                ...($usesWholesaleTwoVariants ? [
                    'read_weight_kg' => round((float) $records->sum('peso_leido_kg'), 3),
                    'adjustment_weight_kg' => round(
                        (float) $records->sum(
                            fn (Pesada $record): float => (float) $record->peso_bruto_kg
                                - (float) $record->peso_leido_kg
                        ),
                        3
                    ),
                ] : []),
                'gross_weight_kg' => round((float) $records->sum('peso_bruto_kg'), 3),
                'tare_weight_kg' => round((float) $records->sum('tara_total_kg'), 3),
                'net_weight_kg' => round((float) $records->sum('peso_neto_kg'), 3),
                'amount' => $usesSalePrices ? round((float) $amount, 2) : null,
            ],
            'weighings' => $records->map(function (Pesada $record) use ($ticket, $usesSalePrices, $usesWholesaleTwoVariants, $pricesByType, $timezone): array {
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
                    'chicken_variant_code' => $ticket->modulo_origen === TicketDespacho::SOURCE_WHOLESALE_TWO
                        ? WholesaleTwoChickenVariant::fromStored(
                            $record->tipoPollo?->codigo,
                            $record->sexo,
                            $record->presentacion_pollo,
                        )
                        : null,
                    'adjustment' => $usesWholesaleTwoVariants
                        ? ($record->ajustePesoMayoristaDos
                            ? [
                                'code' => $record->ajustePesoMayoristaDos->codigo,
                                'name' => $record->ajustePesoMayoristaDos->nombre,
                                'additional_grams' => (int) $record->ajuste_peso_mayorista_2_gramos,
                                'total_grams' => (int) $record->ajuste_peso_mayorista_2_gramos
                                    * (int) $record->cantidad_aves,
                                'total_weight_kg' => round(
                                    ((int) $record->ajuste_peso_mayorista_2_gramos
                                        * (int) $record->cantidad_aves) / 1000,
                                    3
                                ),
                            ]
                            : null)
                        : ($record->ajustePesoMinorista
                            ? [
                                'code' => $record->ajustePesoMinorista->codigo,
                                'name' => $record->ajustePesoMinorista->nombre,
                                'additional_grams' => (int) $record->ajuste_peso_gramos,
                            ]
                            : null),
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
                    'price_kg' => $usesSalePrices && $frozenPrice ? (float) $frozenPrice->precio_kg : null,
                    'price_origin' => $usesSalePrices && $frozenPrice ? $frozenPrice->origen_precio : null,
                    'price_history_id' => $usesSalePrices && $frozenPrice
                        ? $frozenPrice->precio_historial_id
                        : null,
                    'amount' => $usesSalePrices && $frozenPrice
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
                    'updated_at' => $record->updated_at?->toISOString(),
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
            && $ticket->modulo_origen !== TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION
            && $ticket->estado !== TicketDespacho::STATUS_VOIDED
            && $this->isFromOperatingDate($ticket, $operatingDate);
    }

    private function isFinanceEditable(TicketDespacho $ticket): bool
    {
        return $ticket->canal === TicketDespacho::CHANNEL_WHOLESALE
            && $ticket->modulo_origen !== TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION
            && $ticket->estado === TicketDespacho::STATUS_CLOSED;
    }

    private function usesWholesaleTwoVariants(TicketDespacho $ticket): bool
    {
        return $ticket->modulo_origen === TicketDespacho::SOURCE_WHOLESALE_TWO
            && $ticket->tipo_operacion === TicketDespacho::OPERATION_DISPATCH;
    }

    private function isDeliveryEditable(TicketDespacho $ticket, string $operatingDate): bool
    {
        $usesDeferredRetailAssignment = $ticket->canal === TicketDespacho::CHANNEL_RETAIL
            && (bool) $ticket->asignacion_transporte_posterior;

        return ($ticket->canal === TicketDespacho::CHANNEL_WHOLESALE || $usesDeferredRetailAssignment)
            && $ticket->tipo_operacion === TicketDespacho::OPERATION_DISPATCH
            && ! (bool) $ticket->clienteDestino?->es_cliente_interno
            && $ticket->estado !== TicketDespacho::STATUS_VOIDED
            && $this->isFromOperatingDate($ticket, $operatingDate);
    }

    private function assertDeliveryEditable(
        TicketDespacho $ticket,
        string $operatingDate,
        string $message
    ): void {
        $ticket->loadMissing(['jornada', 'clienteDestino']);

        abort_unless(
            $ticket->estado !== TicketDespacho::STATUS_VOIDED,
            409,
            'Un ticket anulado es de solo consulta para administradores.'
        );
        abort_unless(
            $ticket->tipo_operacion === TicketDespacho::OPERATION_DISPATCH,
            422,
            'Las devoluciones no tienen camión ni chofer de entrega.'
        );
        abort_if(
            (bool) $ticket->clienteDestino?->es_cliente_interno,
            422,
            'Los clientes internos de la avícola no requieren camión ni chofer de entrega.'
        );

        $supportsDeliveryManagement = $ticket->canal === TicketDespacho::CHANNEL_WHOLESALE
            || (
                $ticket->canal === TicketDespacho::CHANNEL_RETAIL
                && (bool) $ticket->asignacion_transporte_posterior
            );
        abort_unless(
            $supportsDeliveryManagement,
            409,
            'Este ticket minorista no tiene transporte pendiente de asignación.'
        );
        abort_unless($this->isFromOperatingDate($ticket, $operatingDate), 409, $message);
    }

    private function assertEditable(
        TicketDespacho $ticket,
        string $operatingDate,
        string $message = 'Solo se pueden modificar pesadas de la jornada operativa actual.'
    ): void {
        $ticket->loadMissing('jornada');

        abort_unless(
            $ticket->estado !== TicketDespacho::STATUS_VOIDED,
            409,
            'Un ticket anulado es de solo consulta para administradores.'
        );

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

    private function assertFinanceEditable(TicketDespacho $ticket): void
    {
        abort_unless(
            $ticket->estado !== TicketDespacho::STATUS_VOIDED,
            409,
            'Un ticket anulado es de solo consulta.'
        );
        abort_unless(
            $ticket->estado === TicketDespacho::STATUS_CLOSED,
            409,
            'Solo se pueden corregir pesadas de tickets cerrados desde Finanzas.'
        );
        abort_unless(
            $ticket->canal === TicketDespacho::CHANNEL_WHOLESALE,
            409,
            'La edición histórica de pesadas está disponible únicamente para tickets mayoristas.'
        );
    }

    private function assertWeighedAtBelongsToTicketJourney(
        TicketDespacho $ticket,
        string $weighedAt,
        string $timezone,
        int $companyId,
    ): void {
        $ticket->loadMissing('jornada');
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $localTime = CarbonImmutable::parse($weighedAt, $timezone);
        $cutoffAt = $localTime->startOfDay()->setTimeFromTimeString($cutoff);
        $operatingDate = $localTime->greaterThanOrEqualTo($cutoffAt)
            ? $localTime->addDay()->toDateString()
            : $localTime->toDateString();
        $ticketOperatingDate = $ticket->jornada?->fecha_operativa?->format('Y-m-d');

        if ($operatingDate !== $ticketOperatingDate) {
            throw ValidationException::withMessages([
                'weighed_at' => 'La fecha de la pesada debe permanecer dentro de la jornada del ticket. Para mover todo el ticket, usa Cambiar fecha/hora.',
            ]);
        }
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

    private function assertNotLiveChickenReceptionTicket(TicketDespacho $ticket): void
    {
        abort_if(
            $ticket->modulo_origen === TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            409,
            'Las pesadas de este ticket se corrigen completas desde Recepción de pollo vivo.',
        );
    }

    /** @return array<string, mixed>|null */
    private function formatDelivery(TicketDespacho $ticket): ?array
    {
        if ($ticket->tipo_operacion !== TicketDespacho::OPERATION_DISPATCH) {
            return null;
        }

        return [
            'mode' => $ticket->resolvedDeliveryMode(),
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
        $typeCodes = match (true) {
            $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN => [
                TipoPollo::CHICKEN_LIVE,
                TipoPollo::CHICKEN_DEAD,
            ],
            $this->usesWholesaleTwoVariants($ticket) => [
                TipoPollo::CHICKEN_LIVE,
                TipoPollo::CHICKEN_DRESSED,
                TipoPollo::CHICKEN_PROCESSED,
                ...TipoPollo::wholesaleTwoManualPriceCodes(),
            ],
            default => [
                TipoPollo::CHICKEN_LIVE,
                TipoPollo::CHICKEN_DRESSED,
                TipoPollo::CHICKEN_PROCESSED,
            ],
        };

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
                ->orderByDesc('peso_kg')
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
            'weight_adjustments' => $this->usesWholesaleTwoVariants($ticket)
                ? $this->wholesaleTwoAdjustments->configuration($companyId)
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
            'presentacion_pollo' => $record->presentacion_pollo,
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
            'ajuste_peso_mayorista_2_id' => $record->ajuste_peso_mayorista_2_id,
            'ajuste_peso_mayorista_2_gramos' => $record->ajuste_peso_mayorista_2_gramos,
            'peso_leido_kg' => $record->peso_leido_kg,
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

    /** @return array<string, int|string|null> */
    private function ticketPriceAuditValues(TicketPrecio $price): array
    {
        return [
            'ticket_id' => (int) $price->ticket_id,
            'tipo_pollo_id' => (int) $price->tipo_pollo_id,
            'precio_historial_id' => $price->precio_historial_id === null
                ? null
                : (int) $price->precio_historial_id,
            'precio_kg' => bcadd((string) $price->precio_kg, '0', 4),
            'origen_precio' => (string) $price->origen_precio,
            'congelado_por' => (int) $price->congelado_por,
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
