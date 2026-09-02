<?php

namespace App\Services;

use App\Models\Balanza;
use App\Models\PesadaDespachoProducto;
use App\Models\ProductoDespacho;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use App\Models\VariacionProductoDespacho;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductDispatchOperationService
{
    private const MAX_TOTAL_WEIGHT_KG = 999999999.999;

    private const MAX_TOTAL_AMOUNT = 999999999999.99;

    public function __construct(
        private readonly ScaleReadingService $scaleReadings,
        private readonly ProductDispatchSaleDocumentService $saleDocuments,
        private readonly AccessAuditService $audit,
    ) {}

    /**
     * @param  object{id: int, zona_horaria: string}  $branch
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function tickets(int $companyId, object $branch, array $filters): array
    {
        $search = filled($filters['search'] ?? null)
            ? trim((string) $filters['search'])
            : null;
        $dateFrom = filled($filters['date_from'] ?? null)
            ? (string) $filters['date_from']
            : null;
        $dateTo = filled($filters['date_to'] ?? null)
            ? (string) $filters['date_to']
            : null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $timezone = (string) ($branch->zona_horaria ?: config('app.timezone'));
        $storageTimezone = (string) config('app.timezone');

        $query = TicketDespachoProducto::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', (int) $branch->id);

        if ($search !== null) {
            $escaped = str_replace(
                ['!', '%', '_', '\\'],
                ['!!', '!%', '!_', '!\\'],
                mb_strtolower($search),
            );
            $pattern = "%{$escaped}%";

            $query->where(function (Builder $ticketQuery) use ($pattern): void {
                $ticketQuery
                    ->whereRaw("LOWER(tickets_despacho_productos.codigo) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(tickets_despacho_productos.cliente_nombre_snapshot) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(tickets_despacho_productos.cliente_numero_documento_snapshot, '')) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('pesadas', function (Builder $weighingQuery) use ($pattern): void {
                        $weighingQuery
                            ->whereRaw("LOWER(pesadas_despacho_productos.producto_nombre_snapshot) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(COALESCE(pesadas_despacho_productos.variacion_nombre_snapshot, '')) LIKE ? ESCAPE '!'", [$pattern]);
                    });
            });
        }

        if ($dateFrom !== null) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $dateFrom, $timezone)
                ->startOfDay()
                ->setTimezone($storageTimezone);
            $query->where('registrado_at', '>=', $from);
        }

        if ($dateTo !== null) {
            $to = CarbonImmutable::createFromFormat('Y-m-d', $dateTo, $timezone)
                ->endOfDay()
                ->setTimezone($storageTimezone);
            $query->where('registrado_at', '<=', $to);
        }

        $summaryRows = (clone $query)
            ->select('moneda')
            ->selectRaw('COUNT(*) as tickets')
            ->selectRaw('COALESCE(SUM(cantidad_total), 0) as quantity')
            ->selectRaw('COALESCE(SUM(peso_neto_total_kg), 0) as net_weight_kg')
            ->selectRaw('COALESCE(SUM(total), 0) as amount')
            ->groupBy('moneda')
            ->orderBy('moneda')
            ->get();
        $summaryAmounts = $summaryRows
            ->map(fn (object $row): array => [
                'currency' => strtoupper((string) $row->moneda),
                'amount' => round((float) $row->amount, 2),
            ])
            ->values();
        $totalTickets = (int) $summaryRows->sum(
            fn (object $row): int => (int) $row->tickets,
        );
        $lastPage = max(1, (int) ceil($totalTickets / $perPage));
        $page = min($page, $lastPage);
        $paginator = $query
            ->with([
                'sucursal',
                'cliente',
                'creador',
                'pesadas.producto',
                'pesadas.variacion',
            ])
            ->orderByDesc('registrado_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'tickets' => collect($paginator->items()),
            'summary' => [
                'tickets' => $totalTickets,
                'quantity' => (int) $summaryRows->sum(
                    fn (object $row): int => (int) $row->quantity,
                ),
                'net_weight_kg' => round((float) $summaryRows->sum(
                    fn (object $row): float => (float) $row->net_weight_kg,
                ), 3),
                'currency' => $summaryAmounts->count() === 1
                    ? $summaryAmounts->first()['currency']
                    : null,
                'amount' => $summaryAmounts->count() <= 1
                    ? (float) ($summaryAmounts->first()['amount'] ?? 0)
                    : null,
                'amounts' => $summaryAmounts,
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'applied_filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ];
    }

    /**
     * @param  object{id: int, empresa_id: int, codigo: string, nombre: string, zona_horaria: string}  $branch
     * @param  array<string, mixed>  $data
     * @return array{ticket: TicketDespachoProducto, already_registered: bool}
     */
    public function register(
        int $companyId,
        object $branch,
        User $actor,
        array $data,
        string $ticketTitle,
    ): array {
        return DB::transaction(function () use (
            $companyId,
            $branch,
            $actor,
            $data,
            $ticketTitle,
        ): array {
            $company = DB::table('empresas')
                ->where('id', $companyId)
                ->lockForUpdate()
                ->first(['id', 'moneda', 'hora_corte_operativo', 'mensaje_ticket']);

            if (! $company) {
                throw ValidationException::withMessages([
                    'draft_id' => 'La empresa de esta operación ya no se encuentra disponible.',
                ]);
            }

            $lockedBranch = DB::table('sucursales')
                ->where('id', $branch->id)
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO')
                ->lockForUpdate()
                ->first(['id', 'zona_horaria']);

            if (! $lockedBranch) {
                throw ValidationException::withMessages([
                    'draft_id' => 'La sucursal de esta operación ya no se encuentra disponible.',
                ]);
            }

            $existing = TicketDespachoProducto::query()
                ->where('empresa_id', $companyId)
                ->where('referencia_externa', $data['draft_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->sucursal_id !== (int) $branch->id) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este identificador ya pertenece a otra sucursal.',
                    ]);
                }

                return [
                    'ticket' => $this->loadTicket($existing),
                    'already_registered' => true,
                ];
            }

            $client = $this->resolveClient($companyId, $data['client_id'] ?? null);
            $weighings = collect($data['weighings'])->values();
            $weighedAt = $this->weighedTimes($weighings, (string) $lockedBranch->zona_horaria);
            $operatingDate = $this->resolveOperatingDate(
                $weighedAt,
                (string) $lockedBranch->zona_horaria,
                (string) ($company->hora_corte_operativo ?: '21:00:00'),
            );
            $products = $this->activeProducts($companyId, $weighings);
            $variations = $this->activeVariations($companyId, $weighings);
            $prepared = $this->prepareWeighings(
                $weighings,
                $weighedAt,
                $products,
                $variations,
            );
            $totals = $this->totals($prepared);
            $now = now();
            $ticket = TicketDespachoProducto::query()->create([
                'empresa_id' => $companyId,
                'sucursal_id' => $branch->id,
                'referencia_externa' => $data['draft_id'],
                'numero_lista' => (int) ($data['list_number'] ?? 1),
                'codigo' => $this->nextTicketCode($companyId, $operatingDate),
                'titulo_ticket_snapshot' => $ticketTitle,
                'mensaje_ticket_snapshot' => filled($company->mensaje_ticket)
                    ? trim((string) $company->mensaje_ticket)
                    : null,
                'fecha_operativa' => $operatingDate->format('Y-m-d'),
                'cliente_id' => $client?->id,
                'tipo_cliente' => $client
                    ? TicketDespachoProducto::CUSTOMER_REGISTERED
                    : TicketDespachoProducto::CUSTOMER_PUBLIC,
                'cliente_tipo_documento_snapshot' => $client?->tipo_documento,
                'cliente_numero_documento_snapshot' => $client?->numero_documento,
                'cliente_nombre_snapshot' => $client?->nombre_razon_social
                    ?? TicketDespachoProducto::PUBLIC_SALE_LABEL,
                'moneda' => strtoupper((string) ($company->moneda ?: 'PEN')),
                'cantidad_total' => $totals['quantity'],
                'peso_leido_total_kg' => $totals['read_weight_kg'],
                'merma_total_gramos' => $totals['waste_grams'],
                'tara_total_gramos' => $totals['tare_grams'],
                'peso_neto_total_kg' => $totals['net_weight_kg'],
                'subtotal' => $totals['amount'],
                'total' => $totals['amount'],
                'estado' => TicketDespachoProducto::STATUS_REGISTERED,
                'registrado_at' => $now,
                'created_by' => $actor->id,
            ]);

            foreach ($prepared as $index => $weighing) {
                $scaleReading = $this->scaleReadings->record(
                    (int) $branch->id,
                    $actor,
                    $weighing['input'],
                    $weighing['weighed_at'],
                    "weighings.{$index}",
                );

                PesadaDespachoProducto::query()->create([
                    'ticket_despacho_producto_id' => $ticket->id,
                    'numero' => $index + 1,
                    'producto_despacho_id' => $weighing['product']->id,
                    'variacion_producto_despacho_id' => $weighing['variation']?->id,
                    'lectura_balanza_id' => $scaleReading?->id,
                    'producto_nombre_snapshot' => $weighing['product']->nombre,
                    'variacion_nombre_snapshot' => $weighing['variation']?->nombre,
                    'modo_precio_snapshot' => $weighing['price_mode'],
                    'precio_catalogo_snapshot' => $weighing['catalog_price'],
                    'precio_venta_snapshot' => $weighing['unit_price'],
                    'origen_precio' => $weighing['price_origin'],
                    'cantidad' => $weighing['quantity'],
                    'origen_peso' => $weighing['weight_source'],
                    'peso_leido_kg' => $weighing['read_weight_kg'],
                    'merma_catalogo_gramos_unidad' => $weighing['catalog_waste_grams_per_unit'],
                    'merma_aplicada_gramos_unidad' => $weighing['applied_waste_grams_per_unit'],
                    'merma_total_gramos' => $weighing['waste_total_grams'],
                    'tara_gramos' => $weighing['tare_grams'],
                    'peso_neto_kg' => $weighing['net_weight_kg'],
                    'importe' => $weighing['amount'],
                    'pesada_at' => $weighing['weighed_at'],
                    'created_by' => $actor->id,
                ]);
            }

            $ticket = $this->loadTicket($ticket);
            $this->saleDocuments->create($companyId, $ticket, $actor);
            $this->auditTicket($companyId, $ticket, $actor);

            return [
                'ticket' => $ticket,
                'already_registered' => false,
            ];
        }, 3);
    }

    /**
     * @param  object{id: int, zona_horaria: string}  $branch
     * @param  array<string, mixed>  $data
     */
    public function updateTicket(
        int $companyId,
        object $branch,
        User $actor,
        int $ticketId,
        array $data,
        ?string $ip = null,
    ): TicketDespachoProducto {
        return DB::transaction(function () use (
            $companyId,
            $branch,
            $actor,
            $ticketId,
            $data,
            $ip,
        ): TicketDespachoProducto {
            // All product-dispatch writes acquire these shared resources in the
            // same order to avoid deadlocks with a simultaneous registration.
            $company = DB::table('empresas')
                ->where('id', $companyId)
                ->lockForUpdate()
                ->first(['id', 'hora_corte_operativo']);

            if (! $company) {
                throw ValidationException::withMessages([
                    'version' => 'La empresa de este ticket ya no se encuentra disponible.',
                ]);
            }

            $lockedBranch = DB::table('sucursales')
                ->where('id', $branch->id)
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO')
                ->lockForUpdate()
                ->first(['id', 'zona_horaria']);

            if (! $lockedBranch) {
                throw ValidationException::withMessages([
                    'version' => 'La sucursal de este ticket ya no se encuentra disponible.',
                ]);
            }

            $ticket = TicketDespachoProducto::query()
                ->where('empresa_id', $companyId)
                ->where('sucursal_id', (int) $branch->id)
                ->lockForUpdate()
                ->findOrFail($ticketId);

            $this->assertCurrentVersion($ticket, (string) $data['version']);

            $existingWeighings = PesadaDespachoProducto::query()
                ->where('ticket_despacho_producto_id', $ticket->id)
                ->orderBy('numero')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $ticket->load(['sucursal', 'cliente', 'creador']);
            $ticket->setRelation('pesadas', $existingWeighings->values());
            $before = $this->ticketAuditValues($ticket);
            $requestedClientId = filled($data['client_id'] ?? null)
                ? (int) $data['client_id']
                : null;
            $keepsCurrentClient = $requestedClientId === ($ticket->cliente_id === null
                ? null
                : (int) $ticket->cliente_id);
            $client = $this->resolveClientForUpdate(
                $companyId,
                $data['client_id'] ?? null,
                $ticket->cliente_id === null ? null : (int) $ticket->cliente_id,
            );
            $timezone = (string) ($lockedBranch->zona_horaria ?: config('app.timezone'));
            $storageTimezone = (string) config('app.timezone');
            $registeredAtLocal = $this->editableLocalDateTime(
                (string) $data['registered_at'],
                $timezone,
            );

            if ($registeredAtLocal->greaterThan(CarbonImmutable::now($timezone)->addMinutes(5))) {
                throw ValidationException::withMessages([
                    'registered_at' => 'La fecha y hora del ticket no puede estar en el futuro.',
                ]);
            }

            $registeredAt = $registeredAtLocal->setTimezone($storageTimezone);
            $previousRegisteredAt = CarbonImmutable::instance($ticket->registrado_at)
                ->setTimezone($timezone);
            $timeDeltaSeconds = $registeredAt->getTimestamp() - $previousRegisteredAt->getTimestamp();
            $submittedWeighings = collect($data['weighings'])->values();
            /** @var PesadaDespachoProducto|null $historicalReference */
            $historicalReference = $existingWeighings->first();
            $newWeighingAt = $historicalReference
                ? CarbonImmutable::parse(
                    (string) $historicalReference->getRawOriginal('pesada_at'),
                    $timezone,
                )->addSeconds($timeDeltaSeconds)
                : $registeredAtLocal;

            foreach ($submittedWeighings as $index => $input) {
                if (! filled($input['id'] ?? null)) {
                    continue;
                }

                if (! $existingWeighings->has((int) $input['id'])) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.id" => 'La pesada seleccionada no pertenece a este ticket.',
                    ]);
                }
            }

            $normalizedInputs = $submittedWeighings->map(function (
                array $input,
                int $index,
            ) use (
                $existingWeighings,
                $newWeighingAt,
                $timeDeltaSeconds,
                $timezone,
            ): array {
                /** @var PesadaDespachoProducto|null $existing */
                $existing = filled($input['id'] ?? null)
                    ? $existingWeighings->get((int) $input['id'])
                    : null;
                $readWeight = round((float) $input['read_weight_kg'], 3, PHP_ROUND_HALF_UP);
                $weightChanged = $existing
                    && abs($readWeight - (float) $existing->peso_leido_kg) > 0.0005;
                $timeChanged = $existing && $timeDeltaSeconds !== 0;
                $weightSource = $existing && ! $weightChanged && ! $timeChanged
                    ? (string) $existing->origen_peso
                    : 'MANUAL';
                $sameVariation = $existing
                    && (($existing->variacion_producto_despacho_id === null
                            && ! filled($input['variation_id'] ?? null))
                        || (filled($input['variation_id'] ?? null)
                            && (int) $existing->variacion_producto_despacho_id
                                === (int) $input['variation_id']));
                $keepsHistoricalSelection = $existing
                    && (int) $existing->producto_despacho_id === (int) $input['product_id']
                    && $sameVariation;
                $weighedAt = $existing
                    ? CarbonImmutable::parse(
                        (string) $existing->getRawOriginal('pesada_at'),
                        $timezone,
                    )
                        ->addSeconds($timeDeltaSeconds)
                    : $newWeighingAt;

                return [
                    ...$input,
                    'weight_source' => $weightSource,
                    'weighed_at' => $weighedAt,
                    '_weight_changed' => (bool) $weightChanged,
                    '_time_changed' => (bool) $timeChanged,
                    '_historical_selection_unchanged' => (bool) $keepsHistoricalSelection,
                    '_historical_weighing' => $existing,
                    '_request_index' => $index,
                ];
            });
            $weighedAt = $this->weighedTimes($normalizedInputs, $timezone);
            $operatingDate = $timeDeltaSeconds === 0 && $ticket->fecha_operativa
                ? CarbonImmutable::createFromFormat(
                    'Y-m-d',
                    $ticket->fecha_operativa->format('Y-m-d'),
                    $timezone,
                )->startOfDay()
                : $this->resolveOperatingDate(
                    $weighedAt,
                    $timezone,
                    (string) ($company->hora_corte_operativo ?: '21:00:00'),
                );
            $products = $this->activeProducts($companyId, $normalizedInputs, $existingWeighings);
            $variations = $this->activeVariations($companyId, $normalizedInputs, $existingWeighings);
            $prepared = $this->prepareWeighings(
                $normalizedInputs,
                $weighedAt,
                $products,
                $variations,
            );
            $totals = $this->totals($prepared);
            $nextUpdatedAt = now()->startOfSecond();

            if ($ticket->updated_at && $nextUpdatedAt->getTimestamp() <= $ticket->updated_at->getTimestamp()) {
                $nextUpdatedAt = $ticket->updated_at->copy()->addSecond();
            }

            $ticket->fill([
                'numero_lista' => (int) $data['list_number'],
                'titulo_ticket_snapshot' => trim((string) $data['ticket_title']),
                'fecha_operativa' => $operatingDate->format('Y-m-d'),
                'cliente_id' => $client?->id,
                'tipo_cliente' => $client
                    ? TicketDespachoProducto::CUSTOMER_REGISTERED
                    : TicketDespachoProducto::CUSTOMER_PUBLIC,
                'cliente_tipo_documento_snapshot' => $keepsCurrentClient
                    ? $ticket->cliente_tipo_documento_snapshot
                    : $client?->tipo_documento,
                'cliente_numero_documento_snapshot' => $keepsCurrentClient
                    ? $ticket->cliente_numero_documento_snapshot
                    : $client?->numero_documento,
                'cliente_nombre_snapshot' => $keepsCurrentClient
                    ? $ticket->cliente_nombre_snapshot
                    : ($client?->nombre_razon_social ?? TicketDespachoProducto::PUBLIC_SALE_LABEL),
                'cantidad_total' => $totals['quantity'],
                'peso_leido_total_kg' => $totals['read_weight_kg'],
                'merma_total_gramos' => $totals['waste_grams'],
                'tara_total_gramos' => $totals['tare_grams'],
                'peso_neto_total_kg' => $totals['net_weight_kg'],
                'subtotal' => $totals['amount'],
                'total' => $totals['amount'],
                'registrado_at' => $registeredAt,
            ]);
            $ticket->setUpdatedAt($nextUpdatedAt);
            $ticket->save();
            DB::table('tickets_despacho_productos')
                ->where('id', $ticket->id)
                ->update(['fecha_operativa' => $operatingDate->format('Y-m-d')]);

            $requestedIds = $normalizedInputs
                ->pluck('id')
                ->filter(fn (mixed $id): bool => filled($id))
                ->map(fn (mixed $id): int => (int) $id);
            $removedIds = $existingWeighings->keys()->diff($requestedIds);

            if ($removedIds->isNotEmpty()) {
                PesadaDespachoProducto::query()
                    ->where('ticket_despacho_producto_id', $ticket->id)
                    ->whereIn('id', $removedIds)
                    ->delete();
            }

            if ($requestedIds->isNotEmpty()) {
                PesadaDespachoProducto::query()
                    ->where('ticket_despacho_producto_id', $ticket->id)
                    ->whereIn('id', $requestedIds)
                    ->update(['numero' => DB::raw('numero + 1000')]);
            }

            foreach ($prepared as $index => $weighing) {
                $input = $weighing['input'];
                /** @var PesadaDespachoProducto|null $record */
                $record = filled($input['id'] ?? null)
                    ? $existingWeighings->get((int) $input['id'])
                    : null;
                $values = [
                    'numero' => $index + 1,
                    'producto_despacho_id' => $weighing['product']->id,
                    'variacion_producto_despacho_id' => $weighing['variation']?->id,
                    'producto_nombre_snapshot' => $weighing['product_name_snapshot'],
                    'variacion_nombre_snapshot' => $weighing['variation_name_snapshot'],
                    'modo_precio_snapshot' => $weighing['price_mode'],
                    'precio_catalogo_snapshot' => $weighing['catalog_price'],
                    'precio_venta_snapshot' => $weighing['unit_price'],
                    'origen_precio' => $weighing['price_origin'],
                    'cantidad' => $weighing['quantity'],
                    'origen_peso' => $weighing['weight_source'],
                    'peso_leido_kg' => $weighing['read_weight_kg'],
                    'merma_catalogo_gramos_unidad' => $weighing['catalog_waste_grams_per_unit'],
                    'merma_aplicada_gramos_unidad' => $weighing['applied_waste_grams_per_unit'],
                    'merma_total_gramos' => $weighing['waste_total_grams'],
                    'tara_gramos' => $weighing['tare_grams'],
                    'peso_neto_kg' => $weighing['net_weight_kg'],
                    'importe' => $weighing['amount'],
                    'pesada_at' => $weighing['weighed_at'],
                ];

                if ($record) {
                    if ((bool) ($input['_weight_changed'] ?? false)
                        || (bool) ($input['_time_changed'] ?? false)) {
                        $values['lectura_balanza_id'] = null;
                        $values['origen_peso'] = 'MANUAL';
                    }

                    PesadaDespachoProducto::query()
                        ->whereKey($record->id)
                        ->update($values);
                } else {
                    PesadaDespachoProducto::query()->create([
                        'ticket_despacho_producto_id' => $ticket->id,
                        'lectura_balanza_id' => null,
                        'created_by' => $actor->id,
                        ...$values,
                    ]);
                }
            }

            $updatedTicket = $this->loadTicket($ticket->fresh());
            $this->saleDocuments->sync(
                $companyId,
                $updatedTicket,
                $actor,
                $ip,
                trim((string) $data['correction_reason']),
            );
            $this->audit->record(
                $companyId,
                (int) $actor->id,
                'tickets_despacho_productos',
                (int) $updatedTicket->id,
                'CORREGIR',
                $before,
                [
                    ...$this->ticketAuditValues($updatedTicket),
                    'correction_reason' => trim((string) $data['correction_reason']),
                    'motivo_correccion' => trim((string) $data['correction_reason']),
                ],
                $ip,
            );

            return $updatedTicket;
        }, 3);
    }

    private function assertCurrentVersion(TicketDespachoProducto $ticket, string $version): void
    {
        $expected = CarbonImmutable::parse($version);

        if (! $ticket->updated_at
            || $expected->getTimestamp() !== $ticket->updated_at->getTimestamp()) {
            abort(409, 'El ticket fue modificado por otro usuario. Actualiza el detalle antes de guardar.');
        }
    }

    private function editableLocalDateTime(string $value, string $timezone): CarbonImmutable
    {
        $registeredAt = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            $value,
            $timezone,
        );

        if ($registeredAt->format('Y-m-d\TH:i:s') !== $value) {
            throw ValidationException::withMessages([
                'registered_at' => 'La fecha y hora no existe en la zona horaria de la sucursal.',
            ]);
        }

        $localTimestamp = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            $value,
            'UTC',
        )->getTimestamp();
        $transitions = (new DateTimeZone($timezone))->getTransitions(
            $localTimestamp - 172800,
            $localTimestamp + 172800,
        );
        $previousOffset = null;

        foreach ($transitions ?: [] as $transition) {
            $offset = (int) $transition['offset'];

            if ($previousOffset !== null && $offset < $previousOffset) {
                $ambiguousFrom = (int) $transition['ts'] + $offset;
                $ambiguousUntil = (int) $transition['ts'] + $previousOffset;

                if ($localTimestamp >= $ambiguousFrom && $localTimestamp < $ambiguousUntil) {
                    throw ValidationException::withMessages([
                        'registered_at' => 'La fecha y hora es ambigua por el cambio de horario de la sucursal.',
                    ]);
                }
            }

            $previousOffset = $offset;
        }

        return $registeredAt;
    }

    private function resolveClientForUpdate(
        int $companyId,
        mixed $clientId,
        ?int $currentClientId,
    ): ?Tercero {
        if (! filled($clientId)) {
            return null;
        }

        $requestedId = (int) $clientId;
        $keepsCurrentClient = $currentClientId !== null && $requestedId === $currentClientId;
        $client = Tercero::query()
            ->where('empresa_id', $companyId)
            ->when(! $keepsCurrentClient, fn (Builder $query) => $query
                ->where('estado', Tercero::STATUS_ACTIVE)
                ->conRol(TerceroRole::CLIENT))
            ->lockForUpdate()
            ->find($requestedId);

        if (! $client) {
            throw ValidationException::withMessages([
                'client_id' => 'El cliente seleccionado no está disponible.',
            ]);
        }

        return $client;
    }

    private function resolveClient(int $companyId, mixed $clientId): ?Tercero
    {
        if (! filled($clientId)) {
            return null;
        }

        $client = Tercero::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::CLIENT)
            ->lockForUpdate()
            ->find((int) $clientId);

        if (! $client) {
            throw ValidationException::withMessages([
                'client_id' => 'El cliente seleccionado no está disponible.',
            ]);
        }

        return $client;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, CarbonImmutable>
     */
    private function weighedTimes(Collection $weighings, string $timezone): Collection
    {
        $now = CarbonImmutable::now($timezone);

        return $weighings->map(function (array $weighing, int $index) use ($timezone, $now): CarbonImmutable {
            $submittedTime = $weighing['weighed_at'];
            $time = $submittedTime instanceof \DateTimeInterface
                ? CarbonImmutable::instance($submittedTime)->setTimezone($timezone)
                : CarbonImmutable::parse((string) $submittedTime, $timezone)->setTimezone($timezone);

            if ($time->greaterThan($now->addMinutes(5))) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.weighed_at" => 'La fecha de la pesada no puede estar en el futuro.',
                ]);
            }

            return $time;
        });
    }

    /** @param Collection<int, CarbonImmutable> $weighedAt */
    private function resolveOperatingDate(
        Collection $weighedAt,
        string $timezone,
        string $cutoff,
    ): CarbonImmutable {
        $dates = $weighedAt->map(function (CarbonImmutable $time) use ($cutoff): string {
            $cutoffAt = $time->startOfDay()->setTimeFromTimeString($cutoff);

            return ($time->greaterThanOrEqualTo($cutoffAt) ? $time->addDay() : $time)
                ->format('Y-m-d');
        })->unique()->values();

        if ($dates->count() !== 1) {
            throw ValidationException::withMessages([
                'weighings' => 'Todas las pesadas deben pertenecer a la misma fecha operativa.',
            ]);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', (string) $dates->first(), $timezone)
            ->startOfDay();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, ProductoDespacho>
     */
    private function activeProducts(
        int $companyId,
        Collection $weighings,
        ?Collection $historicalWeighings = null,
    ): Collection {
        $ids = $weighings->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique();
        $products = ProductoDespacho::query()
            ->where('empresa_id', $companyId)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($weighings as $index => $weighing) {
            /** @var ProductoDespacho|null $product */
            $product = $products->get((int) $weighing['product_id']);
            /** @var PesadaDespachoProducto|null $historical */
            $historical = $historicalWeighings && filled($weighing['id'] ?? null)
                ? $historicalWeighings->get((int) $weighing['id'])
                : null;
            $keepsHistoricalProduct = $historical
                && (bool) ($weighing['_historical_selection_unchanged'] ?? false);

            if (! $product
                || ($product->estado !== ProductoDespacho::STATUS_ACTIVE && ! $keepsHistoricalProduct)) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.product_id" => 'El producto seleccionado no está disponible.',
                ]);
            }
        }

        return $products;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, VariacionProductoDespacho>
     */
    private function activeVariations(
        int $companyId,
        Collection $weighings,
        ?Collection $historicalWeighings = null,
    ): Collection {
        $ids = $weighings
            ->pluck('variation_id')
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        $variations = VariacionProductoDespacho::query()
            ->whereIn('id', $ids)
            ->whereHas('producto', fn ($query) => $query
                ->where('empresa_id', $companyId))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($weighings as $index => $weighing) {
            if (! filled($weighing['variation_id'] ?? null)) {
                continue;
            }

            $variation = $variations->get((int) $weighing['variation_id']);
            /** @var PesadaDespachoProducto|null $historical */
            $historical = $historicalWeighings && filled($weighing['id'] ?? null)
                ? $historicalWeighings->get((int) $weighing['id'])
                : null;
            $keepsHistoricalVariation = $historical
                && (int) $historical->variacion_producto_despacho_id === (int) $weighing['variation_id'];

            if (! $variation
                || (int) $variation->producto_despacho_id !== (int) $weighing['product_id']
                || ($variation->estado !== VariacionProductoDespacho::STATUS_ACTIVE
                    && ! $keepsHistoricalVariation)) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.variation_id" => 'La variación seleccionada no está disponible para este producto.',
                ]);
            }
        }

        return $variations;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @param  Collection<int, CarbonImmutable>  $weighedAt
     * @param  Collection<int, ProductoDespacho>  $products
     * @param  Collection<int, VariacionProductoDespacho>  $variations
     * @return Collection<int, array<string, mixed>>
     */
    private function prepareWeighings(
        Collection $weighings,
        Collection $weighedAt,
        Collection $products,
        Collection $variations,
    ): Collection {
        return $weighings->map(function (array $input, int $index) use (
            $weighedAt,
            $products,
            $variations,
        ): array {
            /** @var ProductoDespacho $product */
            $product = $products->get((int) $input['product_id']);
            /** @var VariacionProductoDespacho|null $variation */
            $variation = filled($input['variation_id'] ?? null)
                ? $variations->get((int) $input['variation_id'])
                : null;
            /** @var PesadaDespachoProducto|null $historical */
            $historical = ($input['_historical_weighing'] ?? null) instanceof PesadaDespachoProducto
                ? $input['_historical_weighing']
                : null;
            $keepsHistoricalSelection = (bool) ($input['_historical_selection_unchanged'] ?? false)
                && $historical;
            $priceMode = $keepsHistoricalSelection
                ? (string) $historical->modo_precio_snapshot
                : (string) ($variation?->modo_precio ?? $product->modo_precio);

            if (! in_array($priceMode, [
                ProductoDespacho::PRICE_MODE_KG,
                ProductoDespacho::PRICE_MODE_UNIT,
            ], true)) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.product_id" => 'El producto no tiene un modo de precio válido.',
                ]);
            }

            if ($priceMode !== (string) ($input['price_mode'] ?? '')) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.price_mode" => 'El modo de precio del producto cambió. Actualiza la vista antes de guardar el ticket.',
                ]);
            }

            $weightSource = (string) $input['weight_source'];

            if (! in_array($weightSource, ['MANUAL', Balanza::CODE_PRODUCT_DISPATCH], true)) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.weight_source" => 'La procedencia del peso no corresponde a este despacho.',
                ]);
            }

            $quantity = (int) $input['quantity'];
            $readWeightKg = round((float) $input['read_weight_kg'], 3, PHP_ROUND_HALF_UP);
            $readWeightGrams = (int) round($readWeightKg * 1000, 0, PHP_ROUND_HALF_UP);
            $submittedWasteTotalGrams = (int) $input['waste_total_grams'];
            if (array_key_exists('waste_grams_per_unit', $input)) {
                $appliedWasteGramsPerUnit = (int) $input['waste_grams_per_unit'];
                $wasteTotalGrams = $appliedWasteGramsPerUnit * $quantity;

                if ($wasteTotalGrams > 1_000_000_000) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.waste_grams_per_unit" => 'La merma por unidad multiplicada por la cantidad supera el máximo permitido.',
                    ]);
                }

                if ($submittedWasteTotalGrams !== $wasteTotalGrams) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.waste_total_grams" => 'La merma total no coincide con la merma por unidad multiplicada por la cantidad.',
                    ]);
                }
            } else {
                $wasteTotalGrams = $submittedWasteTotalGrams;
                $appliedWasteGramsPerUnit = (int) round(
                    $wasteTotalGrams / max(1, $quantity),
                    0,
                    PHP_ROUND_HALF_UP,
                );
            }
            $tareGrams = (int) ($input['tare_grams'] ?? 0);
            $netWeightGrams = $readWeightGrams + $wasteTotalGrams - $tareGrams;

            if ($netWeightGrams <= 0) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.tare_grams" => 'La tara debe ser menor que la suma del peso leído y la merma.',
                ]);
            }

            $netWeightKg = round($netWeightGrams / 1000, 3, PHP_ROUND_HALF_UP);
            $catalogPrice = $keepsHistoricalSelection
                ? round((float) $historical->precio_catalogo_snapshot, 4, PHP_ROUND_HALF_UP)
                : round(
                    (float) ($variation?->precio_venta ?? $product->precio_venta),
                    4,
                    PHP_ROUND_HALF_UP,
                );
            $unitPrice = round((float) $input['unit_price'], 4, PHP_ROUND_HALF_UP);
            $catalogWasteGramsPerUnit = $keepsHistoricalSelection
                ? (int) $historical->merma_catalogo_gramos_unidad
                : (int) ($variation?->merma_gramos_unidad ?? $product->merma_gramos_unidad);
            $amountBase = $priceMode === ProductoDespacho::PRICE_MODE_KG
                ? $netWeightKg
                : $quantity;
            $amount = round($amountBase * $unitPrice, 2, PHP_ROUND_HALF_UP);

            if ($amount < 0.01) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.unit_price" => 'El precio y la base de cobro deben producir un importe mínimo de 0.01.',
                ]);
            }

            if ($amount > self::MAX_TOTAL_AMOUNT) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.unit_price" => 'El importe de la pesada excede el máximo permitido.',
                ]);
            }

            return [
                'input' => $input,
                'product' => $product,
                'variation' => $variation,
                'product_name_snapshot' => $keepsHistoricalSelection
                    ? $historical->producto_nombre_snapshot
                    : $product->nombre,
                'variation_name_snapshot' => $keepsHistoricalSelection
                    ? $historical->variacion_nombre_snapshot
                    : $variation?->nombre,
                'price_mode' => $priceMode,
                'catalog_price' => $catalogPrice,
                'unit_price' => $unitPrice,
                'price_origin' => number_format($catalogPrice, 4, '.', '') === number_format($unitPrice, 4, '.', '')
                    ? PesadaDespachoProducto::PRICE_CATALOG
                    : PesadaDespachoProducto::PRICE_MANUAL,
                'quantity' => $quantity,
                'weight_source' => $weightSource,
                'read_weight_kg' => $readWeightKg,
                'catalog_waste_grams_per_unit' => $catalogWasteGramsPerUnit,
                'applied_waste_grams_per_unit' => $appliedWasteGramsPerUnit,
                'waste_total_grams' => $wasteTotalGrams,
                'tare_grams' => $tareGrams,
                'net_weight_kg' => $netWeightKg,
                'amount' => $amount,
                'weighed_at' => $weighedAt->get($index),
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return array{quantity: int, read_weight_kg: float, waste_grams: int, tare_grams: int, net_weight_kg: float, amount: float}
     */
    private function totals(Collection $weighings): array
    {
        $readWeight = round((float) $weighings->sum('read_weight_kg'), 3, PHP_ROUND_HALF_UP);
        $netWeight = round((float) $weighings->sum('net_weight_kg'), 3, PHP_ROUND_HALF_UP);
        $amount = round((float) $weighings->sum('amount'), 2, PHP_ROUND_HALF_UP);

        if ($readWeight > self::MAX_TOTAL_WEIGHT_KG || $netWeight > self::MAX_TOTAL_WEIGHT_KG) {
            throw ValidationException::withMessages([
                'weighings' => 'El peso acumulado del ticket excede el máximo permitido.',
            ]);
        }

        if ($amount > self::MAX_TOTAL_AMOUNT) {
            throw ValidationException::withMessages([
                'weighings' => 'El importe acumulado del ticket excede el máximo permitido.',
            ]);
        }

        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'weighings' => 'El total del ticket debe ser mayor o igual a 0.01.',
            ]);
        }

        return [
            'quantity' => (int) $weighings->sum('quantity'),
            'read_weight_kg' => $readWeight,
            'waste_grams' => (int) $weighings->sum('waste_total_grams'),
            'tare_grams' => (int) $weighings->sum('tare_grams'),
            'net_weight_kg' => $netWeight,
            'amount' => $amount,
        ];
    }

    private function nextTicketCode(int $companyId, CarbonImmutable $operatingDate): string
    {
        $prefix = 'PD-'.$operatingDate->format('Ymd').'-';
        $next = TicketDespachoProducto::query()
            ->where('empresa_id', $companyId)
            ->where('codigo', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('codigo')
            ->map(fn (string $code): int => ctype_digit(substr($code, strlen($prefix)))
                ? (int) substr($code, strlen($prefix))
                : 0)
            ->max() + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function loadTicket(TicketDespachoProducto $ticket): TicketDespachoProducto
    {
        return $ticket->load([
            'sucursal',
            'cliente',
            'creador',
            'pesadas.producto',
            'pesadas.variacion',
        ]);
    }

    /** @return array<string, mixed> */
    private function ticketAuditValues(TicketDespachoProducto $ticket): array
    {
        return [
            ...$ticket->attributesToArray(),
            'pesadas' => $ticket->pesadas
                ->sortBy('numero')
                ->values()
                ->map(fn (PesadaDespachoProducto $weighing): array => $weighing->attributesToArray())
                ->all(),
        ];
    }

    private function auditTicket(
        int $companyId,
        TicketDespachoProducto $ticket,
        User $actor,
    ): void {
        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $actor->id,
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticket->id,
            'accion' => 'REGISTRAR',
            'datos_despues' => json_encode([
                'codigo' => $ticket->codigo,
                'referencia_externa' => $ticket->referencia_externa,
                'numero_lista' => $ticket->numero_lista,
                'titulo_ticket_snapshot' => $ticket->titulo_ticket_snapshot,
                'cliente_id' => $ticket->cliente_id,
                'tipo_cliente' => $ticket->tipo_cliente,
                'total' => $ticket->total,
                'pesadas' => $ticket->pesadas->map(fn (PesadaDespachoProducto $weighing): array => [
                    'numero' => $weighing->numero,
                    'producto_id' => $weighing->producto_despacho_id,
                    'variacion_id' => $weighing->variacion_producto_despacho_id,
                    'precio_catalogo' => $weighing->precio_catalogo_snapshot,
                    'precio_aplicado' => $weighing->precio_venta_snapshot,
                    'origen_precio' => $weighing->origen_precio,
                    'merma_catalogo_total_gramos' => (int) $weighing->merma_catalogo_gramos_unidad
                        * (int) $weighing->cantidad,
                    'merma_aplicada_gramos_unidad' => $weighing->merma_aplicada_gramos_unidad,
                    'merma_aplicada_total_gramos' => $weighing->merma_total_gramos,
                    'tara_gramos' => $weighing->tara_gramos,
                    'origen_peso' => $weighing->origen_peso,
                    'peso_leido_kg' => $weighing->peso_leido_kg,
                    'peso_neto_kg' => $weighing->peso_neto_kg,
                    'importe' => $weighing->importe,
                ])->values()->all(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
