<?php

namespace App\Services;

use App\Models\MovimientoJava;
use App\Models\Pesada;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TicketPrecio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialTicketService
{
    public const PER_PAGE = 30;

    private const CLIENT_SEARCH_LIMIT = 8;

    private const MAX_PRICE = '99999999.9999';

    public function __construct(
        private readonly FinancialAuditService $audit,
        private readonly FinancialObligationService $financialObligations,
        private readonly JavaControlService $javaControl,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(int $companyId, array $filters): array
    {
        $query = $this->filteredQuery($companyId, $filters);
        $priceTypes = $this->priceTypesForQuery(clone $query);

        /** @var LengthAwarePaginator<int, TicketDespacho> $paginator */
        $paginator = $query
            ->with([
                'clienteDestino:id,nombre_razon_social,numero_documento,estado',
                'precios.tipoPollo:id,codigo,nombre',
                'pesadas' => fn ($query) => $query
                    ->where('estado', Pesada::STATUS_ACTIVE)
                    ->select(['id', 'ticket_id', 'tipo_pollo_id', 'peso_neto_kg', 'estado']),
            ])
            ->orderByRaw(
                'COALESCE(tickets_despacho.cerrado_at, tickets_despacho.created_at) DESC',
            )
            ->orderByDesc('tickets_despacho.id')
            ->paginate(self::PER_PAGE);
        $currency = $this->companyCurrency($companyId);
        $timezone = $this->companyTimezone($companyId);

        return [
            'data' => collect($paginator->items())
                ->map(fn (TicketDespacho $ticket): array => $this->formatTicket(
                    $ticket,
                    $currency,
                    $timezone,
                ))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => self::PER_PAGE,
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'price_types' => $priceTypes,
                'timezone' => $timezone,
            ],
        ];
    }

    /**
     * @return array<int, array{id: int, nombre: string, numero_documento: ?string, estado: string}>
     */
    public function searchClients(int $companyId, ?string $search): array
    {
        $search = trim((string) $search);

        return DB::table('terceros as cliente')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'cliente.id')
            ->where('cliente.empresa_id', $companyId)
            ->whereIn('cliente.estado', [
                Tercero::STATUS_ACTIVE,
                Tercero::STATUS_INACTIVE,
            ])
            ->where('rol.rol', TerceroRole::CLIENT)
            ->when($search !== '', function ($query) use ($search): void {
                $pattern = $this->escapedLikePattern($search);
                $query->where(function ($query) use ($pattern): void {
                    $query->whereRaw(
                        "cliente.nombre_razon_social LIKE ? ESCAPE '!'",
                        [$pattern],
                    )->orWhereRaw(
                        "cliente.numero_documento LIKE ? ESCAPE '!'",
                        [$pattern],
                    );
                });
            })
            ->orderByRaw(
                'CASE WHEN cliente.estado = ? THEN 0 ELSE 1 END',
                [Tercero::STATUS_ACTIVE],
            )
            ->orderBy('cliente.nombre_razon_social')
            ->orderBy('cliente.id')
            ->limit(self::CLIENT_SEARCH_LIMIT)
            ->get([
                'cliente.id',
                'cliente.nombre_razon_social',
                'cliente.numero_documento',
                'cliente.estado',
            ])
            ->map(fn (object $client): array => [
                'id' => (int) $client->id,
                'nombre' => (string) $client->nombre_razon_social,
                'numero_documento' => $client->numero_documento === null
                    ? null
                    : (string) $client->numero_documento,
                'estado' => (string) $client->estado,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updatePrices(
        int $companyId,
        User $actor,
        int $ticketId,
        array $data,
        ?string $ip,
    ): array {
        return DB::transaction(function () use ($companyId, $actor, $ticketId, $data, $ip): array {
            $ticket = $this->editableTicket($companyId, $ticketId);
            $requested = collect($data['precios'])->keyBy(fn (array $price): int => (int) $price['id']);
            $prices = TicketPrecio::query()
                ->where('ticket_id', $ticket->id)
                ->whereIn('id', $requested->keys())
                ->lockForUpdate()
                ->get();

            if ($prices->count() !== $requested->count()) {
                throw ValidationException::withMessages([
                    'precios' => 'Uno o más precios no pertenecen al ticket seleccionado.',
                ]);
            }

            foreach ($prices as $price) {
                $newPrice = bcadd((string) $requested->get((int) $price->id)['precio_kg'], '0', 4);
                $before = $this->priceAuditValues($price);
                $after = [
                    ...$before,
                    'precio_kg' => $newPrice,
                    'origen_precio' => 'MANUAL',
                    'congelado_por' => (int) $actor->id,
                ];

                if (
                    $before['precio_kg'] === $after['precio_kg']
                    && $before['origen_precio'] === $after['origen_precio']
                    && $before['congelado_por'] === $after['congelado_por']
                ) {
                    continue;
                }

                $price->update([
                    'precio_kg' => $after['precio_kg'],
                    'origen_precio' => $after['origen_precio'],
                    'congelado_por' => $after['congelado_por'],
                ]);
                $this->audit->record(
                    $companyId,
                    (int) $actor->id,
                    'ticket_precios',
                    (int) $price->id,
                    'EDITAR_PRECIO',
                    $before,
                    $after,
                    $ip,
                );
            }

            $this->syncFinancialDocument($companyId, $ticket, $actor);

            return $this->freshFormattedTicket($companyId, (int) $ticket->id);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateClient(
        int $companyId,
        User $actor,
        int $ticketId,
        int $clientId,
        ?string $ip,
    ): array {
        return DB::transaction(function () use ($companyId, $actor, $ticketId, $clientId, $ip): array {
            $ticket = $this->editableTicket($companyId, $ticketId);
            $isInternalClient = (bool) DB::table('terceros')
                ->where('id', $clientId)
                ->value('es_cliente_interno');
            $before = [
                'cliente_destino_id' => $ticket->cliente_destino_id === null
                    ? null
                    : (int) $ticket->cliente_destino_id,
                'almacen_destino_id' => $ticket->almacen_destino_id === null
                    ? null
                    : (int) $ticket->almacen_destino_id,
                'vehiculo_entrega_id' => $ticket->vehiculo_entrega_id === null
                    ? null
                    : (int) $ticket->vehiculo_entrega_id,
                'conductor_entrega_id' => $ticket->conductor_entrega_id === null
                    ? null
                    : (int) $ticket->conductor_entrega_id,
                'asignacion_transporte_posterior' => (bool) $ticket->asignacion_transporte_posterior,
            ];
            $after = [
                'cliente_destino_id' => $clientId,
                'almacen_destino_id' => null,
                'vehiculo_entrega_id' => $isInternalClient
                    ? null
                    : $before['vehiculo_entrega_id'],
                'conductor_entrega_id' => $isInternalClient
                    ? null
                    : $before['conductor_entrega_id'],
                'asignacion_transporte_posterior' => $isInternalClient
                    ? false
                    : $before['asignacion_transporte_posterior'],
            ];

            if ($before !== $after) {
                if (! $ticket->precios()->exists()) {
                    throw ValidationException::withMessages([
                        'cliente_id' => 'Este ticket no tiene precios de venta y no puede convertirse en una venta desde Finanzas.',
                    ]);
                }

                $financialDocumentIds = $this->lockFinancialDocuments(
                    $companyId,
                    (int) $ticket->id,
                );
                $this->assertClientChangeHasNoAppliedPayments($financialDocumentIds);
                $this->assertOldClientCanLoseJavaMovement(
                    $companyId,
                    (int) $ticket->id,
                    $ticket->cliente_destino_id === null ? null : (int) $ticket->cliente_destino_id,
                );
                $this->markTicketPricesAsManual($companyId, $ticket, $actor, $ip);
                $ticket->update($after);
                $this->audit->record(
                    $companyId,
                    (int) $actor->id,
                    'tickets_despacho',
                    (int) $ticket->id,
                    'CAMBIAR_CLIENTE',
                    $before,
                    $after,
                    $ip,
                );
                $ticket = $ticket->fresh();
                $ticket->loadMissing('jornada:id,sucursal_id');
                $this->javaControl->syncDispatchMovement(
                    $ticket,
                    $companyId,
                    (int) $ticket->jornada->sucursal_id,
                );
                $this->syncFinancialDocument($companyId, $ticket, $actor, true);
            }

            return $this->freshFormattedTicket($companyId, (int) $ticket->id);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{matched_tickets: int, updated_tickets: int, updated_prices: int, tickets_without_type: int, idempotent: bool}
     */
    public function bulkAdjustPrices(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip,
    ): array {
        $idempotencyKey = (string) $data['idempotency_key'];
        $payloadHash = $this->bulkAdjustmentPayloadHash($data);

        return DB::transaction(function () use (
            $companyId,
            $actor,
            $data,
            $ip,
            $idempotencyKey,
            $payloadHash,
        ): array {
            DB::table('ticket_precio_ajuste_operaciones')->insertOrIgnore([
                'empresa_id' => $companyId,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'resultado' => null,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
            $operation = DB::table('ticket_precio_ajuste_operaciones')
                ->where('empresa_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals((string) $operation->payload_hash, $payloadHash)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clave de idempotencia ya fue utilizada con otros filtros o valores.',
                ]);
            }

            if ($operation->resultado !== null) {
                /** @var array{matched_tickets: int, updated_tickets: int, updated_prices: int, tickets_without_type: int} $storedResult */
                $storedResult = json_decode(
                    (string) $operation->resultado,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                return [
                    ...$storedResult,
                    'idempotent' => true,
                ];
            }

            $ticketIds = $this->filteredQuery($companyId, $data)
                ->orderBy('tickets_despacho.id')
                ->lockForUpdate()
                ->pluck('tickets_despacho.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($ticketIds === []) {
                throw ValidationException::withMessages([
                    'filtros' => 'No hay tickets que coincidan con los filtros aplicados.',
                ]);
            }

            $typeId = (int) $data['tipo_pollo_id'];
            $prices = TicketPrecio::query()
                ->whereIn('ticket_id', $ticketIds)
                ->where('tipo_pollo_id', $typeId)
                ->orderBy('ticket_id')
                ->lockForUpdate()
                ->get();

            if ($prices->isEmpty()) {
                throw ValidationException::withMessages([
                    'tipo_pollo_id' => 'Ningún ticket filtrado tiene un precio asignado para ese tipo de pollo.',
                ]);
            }

            $adjustment = bcadd((string) $data['monto'], '0', 4);
            $increase = $data['operacion'] === 'AUMENTAR';
            $newPrices = $prices->mapWithKeys(function (TicketPrecio $price) use (
                $adjustment,
                $increase,
            ): array {
                $newPrice = $increase
                    ? bcadd((string) $price->precio_kg, $adjustment, 4)
                    : bcsub((string) $price->precio_kg, $adjustment, 4);

                if (bccomp($newPrice, '0.0000', 4) <= 0) {
                    throw ValidationException::withMessages([
                        'monto' => 'El ajuste dejaría al menos un precio en cero o con un valor negativo. No se modificó ningún ticket.',
                    ]);
                }

                if (bccomp($newPrice, self::MAX_PRICE, 4) > 0) {
                    throw ValidationException::withMessages([
                        'monto' => 'El ajuste superaría el precio máximo permitido. No se modificó ningún ticket.',
                    ]);
                }

                return [(int) $price->id => $newPrice];
            });
            $updatedTicketIds = [];

            foreach ($prices as $price) {
                $before = $this->priceAuditValues($price);
                $after = [
                    ...$before,
                    'precio_kg' => $newPrices->get((int) $price->id),
                    'origen_precio' => 'MANUAL',
                    'congelado_por' => (int) $actor->id,
                    'ajuste' => [
                        'operacion' => $data['operacion'],
                        'monto' => $adjustment,
                        'tipo_pollo_id' => $typeId,
                    ],
                ];
                $price->update([
                    'precio_kg' => $after['precio_kg'],
                    'origen_precio' => $after['origen_precio'],
                    'congelado_por' => $after['congelado_por'],
                ]);
                $this->audit->record(
                    $companyId,
                    (int) $actor->id,
                    'ticket_precios',
                    (int) $price->id,
                    'AJUSTE_MASIVO',
                    $before,
                    $after,
                    $ip,
                );
                $updatedTicketIds[(int) $price->ticket_id] = true;
            }

            TicketDespacho::query()
                ->whereIn('id', array_keys($updatedTicketIds))
                ->orderBy('id')
                ->get()
                ->each(fn (TicketDespacho $ticket) => $this->syncFinancialDocument(
                    $companyId,
                    $ticket,
                    $actor,
                ));

            $result = [
                'matched_tickets' => count($ticketIds),
                'updated_tickets' => count($updatedTicketIds),
                'updated_prices' => $prices->count(),
                'tickets_without_type' => count($ticketIds) - count($updatedTicketIds),
            ];
            DB::table('ticket_precio_ajuste_operaciones')
                ->where('id', $operation->id)
                ->update([
                    'resultado' => json_encode($result, JSON_THROW_ON_ERROR),
                ]);

            return [
                ...$result,
                'idempotent' => false,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<TicketDespacho>
     */
    private function filteredQuery(int $companyId, array $filters): Builder
    {
        $query = $this->companyTicketQuery($companyId)
            ->where('tickets_despacho.estado', '!=', TicketDespacho::STATUS_VOIDED);
        $ticket = trim((string) ($filters['ticket'] ?? ''));
        $client = trim((string) ($filters['cliente'] ?? ''));
        $clientId = (int) ($filters['cliente_id'] ?? 0);
        $companyTimezone = $this->companyTimezone($companyId);

        return $query
            ->when(
                $ticket !== '',
                fn (Builder $query) => $query->whereRaw(
                    "tickets_despacho.codigo LIKE ? ESCAPE '!'",
                    [$this->escapedLikePattern($ticket)],
                ),
            )
            ->when($client !== '', function (Builder $query) use ($client): void {
                $pattern = $this->escapedLikePattern($client);
                $query->where(function (Builder $nested) use ($pattern): void {
                    $nested->whereRaw(
                        "cliente.nombre_razon_social LIKE ? ESCAPE '!'",
                        [$pattern],
                    )->orWhereRaw(
                        "cliente.numero_documento LIKE ? ESCAPE '!'",
                        [$pattern],
                    );
                });
            })
            ->when(
                $clientId > 0,
                fn (Builder $query) => $query->where(
                    'tickets_despacho.cliente_destino_id',
                    $clientId,
                ),
            )
            ->when(
                $filters['desde'] ?? null,
                fn (Builder $query, string $from) => $query->whereRaw(
                    'COALESCE(tickets_despacho.cerrado_at, tickets_despacho.created_at) >= ?',
                    [$this->databaseDateTime($from, $companyTimezone)],
                ),
            )
            ->when(
                $filters['hasta'] ?? null,
                fn (Builder $query, string $until) => $query->whereRaw(
                    'COALESCE(tickets_despacho.cerrado_at, tickets_despacho.created_at) < ?',
                    [$this->exclusiveEndDateTime($until, $companyTimezone)],
                ),
            );
    }

    /** @return Builder<TicketDespacho> */
    private function companyTicketQuery(int $companyId): Builder
    {
        return TicketDespacho::query()
            ->join('jornadas_operativas as jornada_finanzas', 'jornada_finanzas.id', '=', 'tickets_despacho.jornada_id')
            ->join('sucursales as sucursal_finanzas', 'sucursal_finanzas.id', '=', 'jornada_finanzas.sucursal_id')
            ->leftJoin('terceros as cliente', 'cliente.id', '=', 'tickets_despacho.cliente_destino_id')
            ->where('sucursal_finanzas.empresa_id', $companyId)
            ->select('tickets_despacho.*');
    }

    private function editableTicket(int $companyId, int $ticketId): TicketDespacho
    {
        $ticket = $this->companyTicketQuery($companyId)
            ->where('tickets_despacho.id', $ticketId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($ticket->estado === TicketDespacho::STATUS_VOIDED) {
            throw ValidationException::withMessages([
                'ticket' => 'Un ticket anulado no puede modificarse.',
            ]);
        }

        return $ticket;
    }

    /**
     * @param  Builder<TicketDespacho>  $ticketQuery
     * @return array<int, array{id: int, code: string, name: string, ticket_count: int}>
     */
    private function priceTypesForQuery(Builder $ticketQuery): array
    {
        return DB::table('ticket_precios as precio')
            ->join('tipos_pollo as tipo', 'tipo.id', '=', 'precio.tipo_pollo_id')
            ->whereIn('precio.ticket_id', $ticketQuery->select('tickets_despacho.id'))
            ->where('tipo.estado', 'ACTIVO')
            ->groupBy('tipo.id', 'tipo.codigo', 'tipo.nombre')
            ->orderBy('tipo.nombre')
            ->get([
                'tipo.id',
                'tipo.codigo',
                'tipo.nombre',
                DB::raw('COUNT(DISTINCT precio.ticket_id) as ticket_count'),
            ])
            ->map(fn (object $type): array => [
                'id' => (int) $type->id,
                'code' => (string) $type->codigo,
                'name' => (string) $type->nombre,
                'ticket_count' => (int) $type->ticket_count,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function freshFormattedTicket(int $companyId, int $ticketId): array
    {
        $ticket = $this->companyTicketQuery($companyId)
            ->where('tickets_despacho.id', $ticketId)
            ->with([
                'clienteDestino:id,nombre_razon_social,numero_documento,estado',
                'precios.tipoPollo:id,codigo,nombre',
                'pesadas' => fn ($query) => $query
                    ->where('estado', Pesada::STATUS_ACTIVE)
                    ->select(['id', 'ticket_id', 'tipo_pollo_id', 'peso_neto_kg', 'estado']),
            ])
            ->firstOrFail();

        return $this->formatTicket(
            $ticket,
            $this->companyCurrency($companyId),
            $this->companyTimezone($companyId),
        );
    }

    /** @return array<string, mixed> */
    private function formatTicket(
        TicketDespacho $ticket,
        string $currency,
        string $timezone,
    ): array {
        $recordsByType = $ticket->pesadas->groupBy('tipo_pollo_id');
        $isReturn = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN;
        $amount = '0.00';
        $prices = $ticket->precios
            ->sortBy(fn (TicketPrecio $price): string => $price->tipoPollo?->nombre ?? '')
            ->map(function (TicketPrecio $price) use ($recordsByType, $isReturn, &$amount): array {
                /** @var Collection<int, Pesada> $records */
                $records = $recordsByType->get($price->tipo_pollo_id, collect());
                $weight = $records->reduce(
                    fn (string $sum, Pesada $record): string => bcadd(
                        $sum,
                        (string) $record->peso_neto_kg,
                        3,
                    ),
                    '0.000',
                );
                $subtotal = $records->reduce(
                    fn (string $sum, Pesada $record): string => bcadd(
                        $sum,
                        $this->moneyProduct(
                            (string) $record->peso_neto_kg,
                            (string) $price->precio_kg,
                        ),
                        2,
                    ),
                    '0.00',
                );

                if ($isReturn) {
                    $subtotal = bcsub('0.00', $subtotal, 2);
                }
                $amount = bcadd($amount, $subtotal, 2);

                return [
                    'id' => (int) $price->id,
                    'chicken_type' => [
                        'id' => (int) $price->tipo_pollo_id,
                        'code' => $price->tipoPollo?->codigo,
                        'name' => $price->tipoPollo?->nombre ?? 'Tipo sin nombre',
                    ],
                    'price_kg' => bcadd((string) $price->precio_kg, '0', 4),
                    'weight_kg' => $weight,
                    'subtotal' => $subtotal,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => (int) $ticket->id,
            'code' => (string) $ticket->codigo,
            'client' => $ticket->clienteDestino
                ? [
                    'id' => (int) $ticket->clienteDestino->id,
                    'name' => (string) $ticket->clienteDestino->nombre_razon_social,
                    'document_number' => $ticket->clienteDestino->numero_documento,
                ]
                : null,
            'channel' => (string) $ticket->canal,
            'operation_type' => (string) $ticket->tipo_operacion,
            'status' => (string) $ticket->estado,
            'registered_at' => $this->formattedRegisteredAt($ticket, $timezone),
            'currency' => $currency,
            'amount' => $amount,
            'prices' => $prices,
            'can_edit_prices' => $prices !== [],
            'can_change_client' => $prices !== [],
        ];
    }

    private function syncFinancialDocument(
        int $companyId,
        TicketDespacho $ticket,
        User $actor,
        bool $refreshCounterpartySnapshot = false,
    ): void {
        if ($ticket->estado !== TicketDespacho::STATUS_CLOSED) {
            return;
        }

        $this->financialObligations->syncTicket(
            $companyId,
            $ticket,
            $actor,
            $refreshCounterpartySnapshot,
        );
    }

    /** @return array<int, int> */
    private function lockFinancialDocuments(int $companyId, int $ticketId): array
    {
        $documentIds = DB::table('comprobante_tickets')
            ->where('ticket_id', $ticketId)
            ->pluck('comprobante_id');

        if ($documentIds->isEmpty()) {
            return [];
        }

        return DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->whereIn('id', $documentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @param array<int, int> $documentIds */
    private function assertClientChangeHasNoAppliedPayments(array $documentIds): void
    {
        if ($documentIds === []) {
            return;
        }

        $hasAppliedPayments = DB::table('pago_aplicaciones as aplicacion')
            ->join('pagos as pago', 'pago.id', '=', 'aplicacion.pago_id')
            ->where('pago.estado', 'REGISTRADO')
            ->whereIn('aplicacion.comprobante_id', $documentIds)
            ->exists();

        if ($hasAppliedPayments) {
            throw ValidationException::withMessages([
                'cliente_id' => 'No se puede cambiar el cliente porque el ticket ya tiene cobros aplicados. Anula primero los movimientos financieros relacionados.',
            ]);
        }
    }

    private function assertOldClientCanLoseJavaMovement(
        int $companyId,
        int $ticketId,
        ?int $oldClientId,
    ): void {
        if ($oldClientId === null) {
            return;
        }

        $ticketMovement = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $oldClientId)
            ->where('ticket_despacho_id', $ticketId)
            ->lockForUpdate()
            ->first();

        if (! $ticketMovement) {
            return;
        }

        $movements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $oldClientId)
            ->lockForUpdate()
            ->get();
        $otherJavaDispatches = (int) $movements
            ->where('tipo', MovimientoJava::TYPE_DISPATCH)
            ->where('ticket_despacho_id', '!=', $ticketId)
            ->sum('cantidad');
        $javaReceipts = (int) $movements
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->sum('cantidad');
        $otherTrayDispatches = (int) $movements
            ->where('tipo', MovimientoJava::TYPE_DISPATCH)
            ->where('ticket_despacho_id', '!=', $ticketId)
            ->sum('cantidad_bandejas');
        $trayReceipts = (int) $movements
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->sum('cantidad_bandejas');

        if ($otherJavaDispatches < $javaReceipts) {
            throw ValidationException::withMessages([
                'cliente_id' => 'No se puede cambiar el cliente porque el cliente actual ya devolvió javas asociadas a este ticket.',
            ]);
        }

        if ($otherTrayDispatches < $trayReceipts) {
            throw ValidationException::withMessages([
                'cliente_id' => 'No se puede cambiar el cliente porque el cliente actual ya devolvió bandejas asociadas a este ticket.',
            ]);
        }
    }

    private function markTicketPricesAsManual(
        int $companyId,
        TicketDespacho $ticket,
        User $actor,
        ?string $ip,
    ): void {
        $prices = TicketPrecio::query()
            ->where('ticket_id', $ticket->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($prices as $price) {
            $before = $this->priceAuditValues($price);
            $after = [
                ...$before,
                'origen_precio' => 'MANUAL',
                'congelado_por' => (int) $actor->id,
            ];

            if (
                $before['origen_precio'] === $after['origen_precio']
                && $before['congelado_por'] === $after['congelado_por']
            ) {
                continue;
            }

            $price->update([
                'origen_precio' => $after['origen_precio'],
                'congelado_por' => $after['congelado_por'],
            ]);
            $this->audit->record(
                $companyId,
                (int) $actor->id,
                'ticket_precios',
                (int) $price->id,
                'CONGELAR_POR_CLIENTE',
                $before,
                $after,
                $ip,
            );
        }
    }

    /** @return array<string, int|string> */
    private function priceAuditValues(TicketPrecio $price): array
    {
        return [
            'ticket_id' => (int) $price->ticket_id,
            'tipo_pollo_id' => (int) $price->tipo_pollo_id,
            'precio_historial_id' => (int) $price->precio_historial_id,
            'precio_kg' => bcadd((string) $price->precio_kg, '0', 4),
            'origen_precio' => (string) $price->origen_precio,
            'congelado_por' => (int) $price->congelado_por,
        ];
    }

    private function moneyProduct(string $quantity, string $unitPrice): string
    {
        return bcadd(bcmul($quantity, $unitPrice, 6), '0.005', 2);
    }

    private function companyCurrency(int $companyId): string
    {
        return (string) (
            DB::table('empresas')->where('id', $companyId)->value('moneda')
            ?: 'PEN'
        );
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (
            DB::table('empresas')->where('id', $companyId)->value('zona_horaria')
            ?: config('app.timezone', 'UTC')
        );
    }

    private function databaseTimezone(): string
    {
        $connection = DB::connection()->getName();

        return (string) (
            config("database.connections.{$connection}.timezone")
            ?: config('app.timezone', 'UTC')
        );
    }

    private function databaseDateTime(string $value, string $companyTimezone): string
    {
        return $this->localDateTime($value, $companyTimezone)
            ->setTimezone($this->databaseTimezone())
            ->format('Y-m-d H:i:s');
    }

    private function exclusiveEndDateTime(string $value, string $companyTimezone): string
    {
        return $this->localDateTime($value, $companyTimezone)
            ->addMinute()
            ->setTimezone($this->databaseTimezone())
            ->format('Y-m-d H:i:s');
    }

    private function localDateTime(string $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
    }

    private function escapedLikePattern(string $value): string
    {
        return '%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value,
        ).'%';
    }

    private function formattedRegisteredAt(
        TicketDespacho $ticket,
        string $companyTimezone,
    ): ?string {
        $rawRegisteredAt = $ticket->getRawOriginal('cerrado_at')
            ?: $ticket->getRawOriginal('created_at');

        if ($rawRegisteredAt === null) {
            return null;
        }

        return CarbonImmutable::parse(
            (string) $rawRegisteredAt,
            $this->databaseTimezone(),
        )
            ->setTimezone($companyTimezone)
            ->toIso8601String();
    }

    /** @param array<string, mixed> $data */
    private function bulkAdjustmentPayloadHash(array $data): string
    {
        $payload = [
            'ticket' => $data['ticket'] ?? null,
            'cliente' => $data['cliente'] ?? null,
            'desde' => $data['desde'] ?? null,
            'hasta' => $data['hasta'] ?? null,
            'operacion' => (string) $data['operacion'],
            'tipo_pollo_id' => (int) $data['tipo_pollo_id'],
            'monto' => bcadd((string) $data['monto'], '0', 4),
        ];
        if (isset($data['cliente_id'])) {
            $payload['cliente_id'] = (int) $data['cliente_id'];
        }

        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
