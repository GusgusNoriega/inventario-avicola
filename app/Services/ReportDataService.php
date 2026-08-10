<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\CuentaFinanciera;
use App\Models\MovimientoCajaEfectivo;
use App\Models\Pago;
use App\Models\Pesada;
use App\Models\Tercero;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportDataService
{
    /** @return array<string, mixed> */
    public function customerStatement(int $companyId, int $customerId, string $from, string $to): array
    {
        $customer = Tercero::query()
            ->where('empresa_id', $companyId)
            ->findOrFail($customerId);

        return $this->statement($companyId, $customer, 'VENTA', 'cliente_id', $from, $to, true);
    }

    /** @return array<string, mixed> */
    public function providerStatement(int $companyId, int $providerId, string $from, string $to): array
    {
        $provider = Tercero::query()
            ->where('empresa_id', $companyId)
            ->findOrFail($providerId);

        return $this->statement($companyId, $provider, 'COMPRA', 'proveedor_id', $from, $to);
    }

    /** @return array<string, mixed> */
    public function customerDebtSummary(
        int $companyId,
        string $from,
        string $to,
        string $currency = 'PEN',
    ): array {
        $toExclusive = CarbonImmutable::parse($to)->addDay()->startOfDay();
        $documentEffect = 'CASE WHEN naturaleza = ? THEN -ABS(total) ELSE ABS(total) END';
        $documentBalances = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('operacion', Comprobante::OPERATION_SALE)
            ->where('moneda', $currency)
            ->where(function ($query): void {
                $query->whereIn('estado', [
                    Comprobante::STATUS_PENDING,
                    Comprobante::STATUS_PARTIAL,
                    Comprobante::STATUS_PAID,
                ])->orWhere(function ($voided): void {
                    $voided->where('estado', Comprobante::STATUS_VOIDED)
                        ->whereNotNull('anulada_at');
                });
            })
            ->where('fecha_emision', '<=', $to)
            ->groupBy('tercero_id')
            ->select('tercero_id')
            ->selectRaw(
                "SUM(CASE WHEN fecha_emision < ? THEN {$documentEffect} ELSE 0 END)
                    + SUM(CASE WHEN estado = ? AND DATE(anulada_at) < ? THEN -({$documentEffect}) ELSE 0 END)
                    AS opening_documents",
                [
                    $from,
                    Comprobante::NATURE_CREDIT,
                    Comprobante::STATUS_VOIDED,
                    $from,
                    Comprobante::NATURE_CREDIT,
                ],
            )
            ->selectRaw(
                "SUM(CASE WHEN fecha_emision BETWEEN ? AND ? THEN {$documentEffect} ELSE 0 END)
                    + SUM(CASE WHEN estado = ? AND DATE(anulada_at) BETWEEN ? AND ? THEN -({$documentEffect}) ELSE 0 END)
                    AS period_debt",
                [
                    $from,
                    $to,
                    Comprobante::NATURE_CREDIT,
                    Comprobante::STATUS_VOIDED,
                    $from,
                    $to,
                    Comprobante::NATURE_CREDIT,
                ],
            )
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->tercero_id);
        $this->applyHistoricalDocumentTransitions(
            $documentBalances,
            $companyId,
            $from,
            $to,
            $toExclusive,
            $currency,
        );

        $negativePaymentTypes = [
            Pago::TYPE_CUSTOMER_COLLECTION,
            Pago::TYPE_RETAIL_COLLECTION,
            Pago::TYPE_DIRECT_PAYMENT,
            Pago::TYPE_CUSTOMER_DISCOUNT,
            Pago::TYPE_OPENING_BALANCE,
        ];
        $paymentTypePlaceholders = implode(', ', array_fill(0, count($negativePaymentTypes), '?'));
        $paymentEffect = "CASE
            WHEN payment.tipo = ? THEN ABS(payment.importe)
            WHEN payment.tipo IN ({$paymentTypePlaceholders}) THEN -ABS(payment.importe)
            WHEN payment.direccion = ? THEN -ABS(payment.importe)
            ELSE ABS(payment.importe)
        END";
        $paymentEffectBindings = [
            Pago::TYPE_CUSTOMER_REFUND,
            ...$negativePaymentTypes,
            Pago::DIRECTION_INCOME,
        ];
        $effectivePaymentDate = 'COALESCE(collection_detail.fecha_recepcion, DATE(payment.fecha_hora))';
        $paymentBalances = DB::table('pagos as payment')
            ->leftJoin('cobranza_detalles as collection_detail', 'collection_detail.pago_id', '=', 'payment.id')
            ->where('payment.empresa_id', $companyId)
            ->where(function ($query): void {
                $query->where('payment.estado', Pago::STATUS_REGISTERED)
                    ->orWhere(function ($voided): void {
                        $voided->where('payment.estado', Pago::STATUS_VOIDED)
                            ->whereNotNull('payment.anulada_at');
                    });
            })
            ->whereNull('payment.reversa_de_pago_id')
            ->whereNotNull('payment.cliente_id')
            ->where('payment.moneda', $currency)
            ->where(function ($query) use ($to, $toExclusive): void {
                $query->where(function ($received) use ($to): void {
                    $received->whereNotNull('collection_detail.fecha_recepcion')
                        ->where('collection_detail.fecha_recepcion', '<=', $to);
                })->orWhere(function ($deposited) use ($toExclusive): void {
                    $deposited->whereNull('collection_detail.fecha_recepcion')
                        ->where('payment.fecha_hora', '<', $toExclusive);
                });
            })
            ->groupBy('payment.cliente_id')
            ->select('payment.cliente_id')
            ->selectRaw(
                "SUM(CASE WHEN {$effectivePaymentDate} < ? THEN {$paymentEffect} ELSE 0 END)
                    + SUM(CASE WHEN payment.estado = ? AND DATE(payment.anulada_at) < ? THEN -({$paymentEffect}) ELSE 0 END)
                    AS opening_payments",
                [
                    $from,
                    ...$paymentEffectBindings,
                    Pago::STATUS_VOIDED,
                    $from,
                    ...$paymentEffectBindings,
                ],
            )
            ->selectRaw(
                "SUM(CASE WHEN {$effectivePaymentDate} BETWEEN ? AND ? THEN {$paymentEffect} ELSE 0 END)
                    + SUM(CASE WHEN payment.estado = ? AND DATE(payment.anulada_at) BETWEEN ? AND ? THEN -({$paymentEffect}) ELSE 0 END)
                    AS period_payment_effect",
                [
                    $from,
                    $to,
                    ...$paymentEffectBindings,
                    Pago::STATUS_VOIDED,
                    $from,
                    $to,
                    ...$paymentEffectBindings,
                ],
            )
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->cliente_id);

        $customerIds = $documentBalances->keys()
            ->merge($paymentBalances->keys())
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $rows = DB::table('terceros as tercero')
            ->where('tercero.empresa_id', $companyId)
            ->whereIn('tercero.id', $customerIds)
            ->orderBy('tercero.nombre_razon_social')
            ->orderBy('tercero.id')
            ->get(['tercero.id', 'tercero.nombre_razon_social'])
            ->map(function (object $customer) use ($documentBalances, $paymentBalances): array {
                $documents = $documentBalances->get((int) $customer->id);
                $payments = $paymentBalances->get((int) $customer->id);
                $opening = FinancialMoney::add(
                    FinancialMoney::normalize($documents?->opening_documents ?? '0'),
                    FinancialMoney::normalize($payments?->opening_payments ?? '0'),
                );
                $periodDebt = FinancialMoney::normalize($documents?->period_debt ?? '0');
                $debtToDate = FinancialMoney::add($opening, $periodDebt);
                $periodPayments = FinancialMoney::subtract(
                    '0.00',
                    FinancialMoney::normalize($payments?->period_payment_effect ?? '0'),
                );

                return [
                    'customer_id' => (int) $customer->id,
                    'customer' => (string) $customer->nombre_razon_social,
                    'opening' => $opening,
                    'period_debt' => $periodDebt,
                    'debt_to_date' => $debtToDate,
                    'payments' => $periodPayments,
                    'balance' => FinancialMoney::subtract($debtToDate, $periodPayments),
                ];
            })
            ->filter(fn (array $row): bool => collect([
                'opening',
                'period_debt',
                'debt_to_date',
                'payments',
                'balance',
            ])->contains(fn (string $field): bool => FinancialMoney::compare($row[$field], '0.00') !== 0))
            ->values();

        $totals = collect([
            'opening',
            'period_debt',
            'debt_to_date',
            'payments',
            'balance',
        ])->mapWithKeys(fn (string $field): array => [
            $field => $rows->reduce(
                fn (string $total, array $row): string => FinancialMoney::add($total, $row[$field]),
                '0.00',
            ),
        ])->all();

        return [
            'rows' => $rows,
            'totals' => $totals,
            'currency' => $currency,
        ];
    }

    /**
     * Replays prior void/restore cycles kept in the immutable audit log. The
     * current document row only retains the latest state and cannot represent
     * the days during which a restored ticket remained voided.
     *
     * @param  Collection<int, object>  $balances
     */
    private function applyHistoricalDocumentTransitions(
        Collection $balances,
        int $companyId,
        string $from,
        string $to,
        CarbonImmutable $toExclusive,
        string $currency,
    ): void {
        $documents = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('operacion', Comprobante::OPERATION_SALE)
            ->where('origen_clave', 'like', 'VENTA:TICKET:%')
            ->where('moneda', $currency)
            ->where('fecha_emision', '<=', $to)
            ->where(function ($query): void {
                $query->whereIn('estado', [
                    Comprobante::STATUS_PENDING,
                    Comprobante::STATUS_PARTIAL,
                    Comprobante::STATUS_PAID,
                ])->orWhere(function ($voided): void {
                    $voided->where('estado', Comprobante::STATUS_VOIDED)
                        ->whereNotNull('anulada_at');
                });
            })
            ->get([
                'id',
                'tercero_id',
                'operacion',
                'naturaleza',
                'moneda',
                'total',
                'estado',
                'anulada_at',
            ])
            ->keyBy(fn (object $document): int => (int) $document->id);

        if ($documents->isEmpty()) {
            return;
        }

        $auditEvents = collect();
        foreach ($documents->keys()->chunk(500) as $documentIds) {
            $auditEvents = $auditEvents->concat(
                DB::table('auditoria_eventos')
                    ->where('empresa_id', $companyId)
                    ->where('entidad', 'comprobantes')
                    ->whereIn('entidad_id', $documentIds->map(fn (mixed $id): string => (string) $id)->all())
                    ->where('created_at', '<', $toExclusive)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get(['id', 'entidad_id', 'datos_antes', 'datos_despues', 'created_at']),
            );
        }

        foreach ($auditEvents->groupBy(fn (object $event): int => (int) $event->entidad_id) as $documentId => $events) {
            $document = $documents->get((int) $documentId);
            if (! $document) {
                continue;
            }

            $transitions = $events
                ->map(function (object $event): ?array {
                    $before = $this->auditPayload($event->datos_antes);
                    $after = $this->auditPayload($event->datos_despues);
                    $beforeStatus = (string) ($before['estado'] ?? '');
                    $afterStatus = (string) ($after['estado'] ?? '');

                    if ($beforeStatus !== Comprobante::STATUS_VOIDED
                        && $afterStatus === Comprobante::STATUS_VOIDED) {
                        return [
                            'audit_id' => (int) $event->id,
                            'kind' => 'void',
                            'date' => substr((string) $event->created_at, 0, 10),
                        ];
                    }
                    if ($beforeStatus === Comprobante::STATUS_VOIDED
                        && in_array($afterStatus, [
                            Comprobante::STATUS_PENDING,
                            Comprobante::STATUS_PARTIAL,
                            Comprobante::STATUS_PAID,
                        ], true)) {
                        return [
                            'audit_id' => (int) $event->id,
                            'kind' => 'restore',
                            'date' => substr((string) $event->created_at, 0, 10),
                        ];
                    }

                    return null;
                })
                ->filter()
                ->values();

            $currentVoidAuditId = null;
            if ($document->estado === Comprobante::STATUS_VOIDED && $document->anulada_at !== null) {
                $currentVoidDate = substr((string) $document->anulada_at, 0, 10);
                $currentVoidAuditId = $transitions
                    ->filter(fn (array $transition): bool => $transition['kind'] === 'void'
                        && $transition['date'] === $currentVoidDate)
                    ->pluck('audit_id')
                    ->last();
            }

            foreach ($transitions as $transition) {
                if ($transition['audit_id'] === $currentVoidAuditId) {
                    continue;
                }
                $eventDate = $transition['date'];
                if ($eventDate === '' || $eventDate > $to) {
                    continue;
                }
                $effect = $this->documentSnapshotEffect((array) $document);
                $adjustment = $transition['kind'] === 'void'
                    ? FinancialMoney::subtract('0.00', $effect)
                    : $effect;
                $field = $eventDate < $from ? 'opening_documents' : 'period_debt';
                $customerId = (int) $document->tercero_id;
                $row = $balances->get($customerId) ?? (object) [
                    'tercero_id' => $customerId,
                    'opening_documents' => '0.00',
                    'period_debt' => '0.00',
                ];
                $row->{$field} = FinancialMoney::add(
                    FinancialMoney::normalize($row->{$field} ?? '0'),
                    $adjustment,
                );
                $balances->put($customerId, $row);
            }
        }
    }

    /** @return array<string, mixed> */
    private function auditPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $snapshot */
    private function documentSnapshotEffect(array $snapshot): string
    {
        $total = FinancialMoney::normalize($snapshot['total']);

        return ($snapshot['naturaleza'] ?? null) === Comprobante::NATURE_CREDIT
            ? FinancialMoney::subtract('0.00', $total)
            : $total;
    }

    /** @return array<string, mixed> */
    private function statement(
        int $companyId,
        Tercero $counterparty,
        string $operation,
        string $paymentPartyColumn,
        string $from,
        string $to,
        bool $abbreviateChickenTypes = false,
    ): array {
        $openingDocuments = Comprobante::query()
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $counterparty->id)
            ->where('operacion', $operation)
            ->where('estado', '<>', Comprobante::STATUS_VOIDED)
            ->whereDate('fecha_emision', '<', $from)
            ->get(['naturaleza', 'total'])
            ->sum(fn (Comprobante $document): float => $this->documentEffect($document));

        $openingPayments = $this->paymentQuery($companyId)
            ->where($paymentPartyColumn, $counterparty->id)
            ->where('fecha_hora', '<', CarbonImmutable::parse($from)->startOfDay())
            ->get()
            ->sum(fn (Pago $payment): float => $this->paymentEffect($payment, $operation));
        $opening = round($openingDocuments + $openingPayments, 2);

        $documents = Comprobante::query()
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $counterparty->id)
            ->where('operacion', $operation)
            ->where('estado', '<>', Comprobante::STATUS_VOIDED)
            ->whereBetween('fecha_emision', [$from, $to])
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->get();
        $details = DB::table('comprobante_detalles as detalle')
            ->leftJoin('tipos_pollo as tipo_pollo', 'tipo_pollo.id', '=', 'detalle.tipo_pollo_id')
            ->whereIn('detalle.comprobante_id', $documents->pluck('id'))
            ->get([
                'detalle.*',
                'tipo_pollo.codigo as tipo_pollo_codigo',
            ])
            ->groupBy('comprobante_id');

        $transactions = $documents->map(function (Comprobante $document) use ($details, $operation, $abbreviateChickenTypes): array {
            $lines = $details->get($document->id, collect());
            $effect = $this->documentEffect($document);

            return [
                'date' => $document->fecha_emision->format('Y-m-d'),
                'sort' => $document->fecha_emision->format('Y-m-d').' 00:00:00-D-'.$document->id,
                'code' => $document->codigo,
                'type' => $document->naturaleza === Comprobante::NATURE_CREDIT
                    ? ($operation === Comprobante::OPERATION_SALE ? 'DEV' : 'NOTA / DEVOLUCION')
                    : $document->tipo_documento,
                'detail' => $lines
                    ->map(fn (object $line): string => $abbreviateChickenTypes
                        ? $this->customerChickenTypeLabel($line->tipo_pollo_codigo, $line->descripcion)
                        : $line->descripcion)
                    ->unique()
                    ->implode(', '),
                'weight' => (float) $lines->sum('peso_neto_kg'),
                'price' => $lines->count() === 1 ? (float) ($lines->first()->precio_kg ?? 0) : null,
                'debit' => $effect > 0 ? abs($effect) : 0,
                'credit' => $effect < 0 ? abs($effect) : 0,
                'effect' => $effect,
            ];
        });

        $payments = $this->paymentQuery($companyId)
            ->where($paymentPartyColumn, $counterparty->id)
            ->whereBetween('fecha_hora', [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ])
            ->with([
                'metodoPago',
                'cuentaOrigen.entidadFinanciera',
                'cuentaDestino.entidadFinanciera',
                ...($operation === Comprobante::OPERATION_PURCHASE ? [
                    'cobranzaDetalle:id,cobranza_id,pago_id',
                    'cobranzaPendiente:id,cobranza_id,pago_id',
                ] : []),
            ])
            ->orderBy('fecha_hora')
            ->orderBy('id')
            ->get()
            ->map(function (Pago $payment) use ($operation): array {
                $effect = $this->paymentEffect($payment, $operation);
                $account = $payment->cuentaDestino ?: $payment->cuentaOrigen;
                $destination = collect([
                    $account?->entidadFinanciera?->nombre_comercial ?: $account?->entidadFinanciera?->razon_social,
                    $account?->alias,
                ])->filter()->implode(' - ');
                $detail = $operation === Comprobante::OPERATION_SALE
                    ? collect([
                        $payment->metodoPago?->nombre ?: $payment->metodo,
                        $this->customerStatementAccountLabel($account),
                    ])->filter()->implode(' - ')
                    : collect([
                        $payment->metodoPago?->nombre ?: $payment->metodo,
                        $destination,
                        $payment->referencia,
                    ])->filter()->implode(' - ');

                $row = [
                    'date' => $payment->fecha_hora->format('Y-m-d'),
                    'sort' => $payment->fecha_hora->format('Y-m-d H:i:s').'-P-'.$payment->id,
                    'code' => $payment->codigo ?: 'PG-'.$payment->id,
                    'type' => str_replace('_', ' ', $payment->tipo ?: $payment->direccion),
                    'detail' => $detail,
                    'weight' => null,
                    'price' => null,
                    'debit' => $effect > 0 ? abs($effect) : 0,
                    'credit' => $effect < 0 ? abs($effect) : 0,
                    'effect' => $effect,
                ];

                if ($operation === Comprobante::OPERATION_PURCHASE) {
                    $row['payment_id'] = (int) $payment->id;
                    $row['collection_id'] = $payment->cobranzaDetalle?->cobranza_id
                        ?? $payment->cobranzaPendiente?->cobranza_id;
                }

                return $row;
            });

        if ($operation === Comprobante::OPERATION_PURCHASE) {
            $payments = $this->consolidateProviderCollectionRows($payments);
        }

        $balance = $opening;
        $rows = $transactions->concat($payments)
            ->sortBy('sort')
            ->values()
            ->map(function (array $row) use (&$balance): array {
                $balance = round($balance + $row['effect'], 2);
                $row['balance'] = $balance;

                return $row;
            });

        return [
            'counterparty' => $counterparty,
            'opening' => $opening,
            'rows' => $rows,
            'charges' => $rows->sum('debit'),
            'credits' => $rows->sum('credit'),
            'balance' => $balance,
        ];
    }

    /** @return array<string, mixed> */
    public function salesByCustomer(int $companyId, string $from, string $to): array
    {
        $tickets = TicketDespacho::query()
            ->where('estado', TicketDespacho::STATUS_CLOSED)
            ->whereHas('jornada', fn (Builder $query) => $query
                ->whereBetween('fecha_operativa', [$from, $to])
                ->whereHas('sucursal', fn (Builder $branch) => $branch->where('empresa_id', $companyId)))
            ->with(['jornada', 'clienteDestino', 'pesadas.tipoPollo', 'precios'])
            ->orderBy('id')
            ->get();

        $rows = collect();
        foreach ($tickets as $ticket) {
            $sign = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN ? -1 : 1;
            $prices = $ticket->precios->keyBy('tipo_pollo_id');

            foreach ($ticket->pesadas->where('estado', Pesada::STATUS_ACTIVE)->groupBy('tipo_pollo_id') as $typeId => $weighings) {
                $recordedAt = $ticket->cerrado_at
                    ?: $weighings->sortByDesc('pesada_at')->first()?->pesada_at
                    ?: $ticket->created_at;
                $price = (float) ($prices->get((int) $typeId)?->precio_kg ?? 0);
                $net = (float) $weighings->sum('peso_neto_kg') * $sign;
                $key = implode(':', [
                    $ticket->id,
                    $typeId,
                ]);
                $existing = $rows->get($key, [
                    'date_time' => $recordedAt?->format('Y-m-d H:i:s') ?? $ticket->jornada?->fecha_operativa?->startOfDay()->format('Y-m-d H:i:s'),
                    'customer' => $ticket->clienteDestino?->nombre_razon_social ?? 'VENTA MINORISTA SIN CLIENTE',
                    'channel' => $ticket->canal,
                    'product' => $weighings->first()?->tipoPollo?->nombre ?? 'Pollo',
                    'containers' => 0,
                    'birds' => 0,
                    'gross_weight' => 0,
                    'tare' => 0,
                    'returns' => 0,
                    'net_weight' => 0,
                    'amount' => 0,
                ]);
                if ($sign > 0) {
                    $existing['containers'] += (int) $weighings->sum('cantidad_javas') + (int) $weighings->sum('cantidad_bandejas');
                    $existing['birds'] += (int) $weighings->sum('cantidad_aves');
                    $existing['gross_weight'] += (float) $weighings->sum('peso_bruto_kg');
                    $existing['tare'] += (float) $weighings->sum('tara_total_kg');
                }
                $existing['returns'] += $sign < 0 ? abs((float) $weighings->sum('peso_neto_kg')) : 0;
                $existing['net_weight'] += $net;
                $existing['amount'] += $net * $price;
                $rows->put($key, $existing);
            }
        }

        $rows = $rows->sortBy(fn (array $row): string => $row['date_time'].'-'.$row['customer'].'-'.$row['product'])->values();

        return [
            'rows' => $rows,
            'totals' => [
                'containers' => $rows->sum('containers'),
                'birds' => $rows->sum('birds'),
                'gross_weight' => $rows->sum('gross_weight'),
                'tare' => $rows->sum('tare'),
                'returns' => $rows->sum('returns'),
                'net_weight' => $rows->sum('net_weight'),
                'amount' => $rows->sum('amount'),
            ],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function payments(int $companyId, string $from, string $to, array $filters = []): array
    {
        $query = $this->paymentQuery($companyId)
            ->whereBetween('fecha_hora', [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ])
            ->when($filters['tipo'] ?? null, fn (Builder $builder, string $type) => $builder->where('tipo', $type))
            ->when($filters['metodo_pago_id'] ?? null, fn (Builder $builder, int|string $id) => $builder->where('metodo_pago_id', $id))
            ->when($filters['cuenta_id'] ?? null, fn (Builder $builder, int|string $id) => $builder->where(
                fn (Builder $accounts) => $accounts
                    ->where('cuenta_origen_id', $id)
                    ->orWhere('cuenta_destino_id', $id)
            ))
            ->where(function (Builder $builder): void {
                $builder->whereDoesntHave('movimientoCajaEfectivo')
                    ->orWhereHas(
                        'movimientoCajaEfectivo',
                        fn (Builder $cashMovement) => $cashMovement
                            ->where('estado', MovimientoCajaEfectivo::STATUS_REGISTERED),
                    );
            })
            ->when($filters['usuario_id'] ?? null, function (Builder $builder, int|string $id): void {
                $builder->where(function (Builder $responsible) use ($id): void {
                    $responsible->whereHas(
                        'movimientoCajaEfectivo',
                        fn (Builder $cashMovement) => $cashMovement
                            ->where('estado', MovimientoCajaEfectivo::STATUS_REGISTERED)
                            ->where('created_by', $id),
                    )->orWhere(function (Builder $regularPayment) use ($id): void {
                        $regularPayment->whereDoesntHave('movimientoCajaEfectivo')
                            ->where('created_by', $id);
                    });
                });
            })
            ->with([
                'tercero', 'cliente', 'proveedor', 'metodoPago', 'creador',
                'cuentaOrigen.entidadFinanciera', 'cuentaDestino.entidadFinanciera',
                'movimientoCajaEfectivo.cliente',
                'movimientoCajaEfectivo.caja.entidadFinanciera',
                'movimientoCajaEfectivo.otraCaja.entidadFinanciera',
                'movimientoCajaEfectivo.creador',
            ])
            ->orderBy('fecha_hora')
            ->orderBy('id');

        $payments = $query->get();
        $rows = $payments->map(function (Pago $payment): array {
            $cashMovement = $payment->movimientoCajaEfectivo;
            if ($cashMovement?->estado === MovimientoCajaEfectivo::STATUS_REGISTERED) {
                return $this->cashPaymentRow($payment, $cashMovement);
            }

            $party = $payment->cliente ?: $payment->proveedor ?: $payment->tercero;
            $account = $payment->cuentaDestino ?: $payment->cuentaOrigen;

            return [
                'date' => $payment->fecha_hora,
                'code' => $payment->codigo ?: 'PG-'.$payment->id,
                'counterparty' => $party?->nombre_razon_social ?? 'MOVIMIENTO INTERNO',
                'type' => str_replace('_', ' ', $payment->tipo ?: $payment->direccion),
                'method' => $payment->metodoPago?->nombre ?: $payment->metodo,
                'detail' => collect([
                    $account?->entidadFinanciera?->nombre_comercial ?: $account?->entidadFinanciera?->razon_social,
                    $account?->alias,
                    $payment->referencia,
                ])->filter()->implode(' - '),
                'user' => $payment->creador?->nombre ?? 'Sin usuario',
                'amount' => (float) $payment->importe,
                'flow' => $this->flow($payment),
            ];
        });

        return [
            'rows' => $rows,
            'income' => $rows->where('flow', 'INGRESO')->sum('amount'),
            'expense' => $rows->where('flow', 'EGRESO')->sum('amount'),
            'total' => $rows->sum('amount'),
        ];
    }

    /** @return array<string, mixed> */
    public function responsibleMovements(
        int $companyId,
        int $userId,
        string $from,
        string $to,
        ?int $accountId = null,
    ): array {
        $data = $this->payments($companyId, $from, $to, [
            'usuario_id' => $userId,
            'cuenta_id' => $accountId,
        ]);

        $data['collections'] = $data['rows']->filter(fn (array $row) => $row['flow'] === 'INGRESO')->values();
        $data['expenses'] = $data['rows']->filter(fn (array $row) => $row['flow'] === 'EGRESO')->values();
        $data['other'] = $data['rows']->filter(fn (array $row) => $row['flow'] === 'SIN_FLUJO')->values();
        $data['user_name'] = DB::table('usuarios')
            ->where('empresa_id', $companyId)
            ->where('id', $userId)
            ->value('nombre') ?: 'Usuario';

        return $data;
    }

    /** @return array<string, mixed> */
    private function cashPaymentRow(Pago $payment, MovimientoCajaEfectivo $cashMovement): array
    {
        $isTransfer = $cashMovement->contraparte_tipo === MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER;

        return [
            'date' => $payment->fecha_hora,
            'code' => $cashMovement->codigo ?: $payment->codigo ?: 'PG-'.$payment->id,
            'counterparty' => $this->cashCounterparty($payment, $cashMovement),
            'type' => $isTransfer
                ? 'TRANSFERENCIA ENTRE CAJAS'
                : ($cashMovement->direccion === MovimientoCajaEfectivo::DIRECTION_INCOME
                    ? 'INGRESO DE CAJA'
                    : 'GASTO DE CAJA'),
            'method' => $payment->metodoPago?->nombre ?: $payment->metodo,
            'detail' => $cashMovement->detalle,
            'user' => $cashMovement->creador?->nombre ?? $payment->creador?->nombre ?? 'Sin usuario',
            'amount' => (float) $payment->importe,
            'flow' => $isTransfer ? Pago::DIRECTION_NO_FLOW : $cashMovement->direccion,
        ];
    }

    private function cashCounterparty(Pago $payment, MovimientoCajaEfectivo $cashMovement): string
    {
        return match ($cashMovement->contraparte_tipo) {
            MovimientoCajaEfectivo::COUNTERPART_CUSTOMER => $cashMovement->cliente?->nombre_razon_social
                ?? $payment->cliente?->nombre_razon_social
                ?? 'Cliente',
            MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER => $this->cashRegisterLabel($cashMovement->otraCaja),
            MovimientoCajaEfectivo::COUNTERPART_ADMINISTRATIVE => 'Administrativo',
            MovimientoCajaEfectivo::COUNTERPART_TRANSPORT => 'Transporte',
            MovimientoCajaEfectivo::COUNTERPART_DEPOSIT => 'Depósito',
            MovimientoCajaEfectivo::COUNTERPART_OTHER => $cashMovement->direccion === MovimientoCajaEfectivo::DIRECTION_INCOME
                ? 'Otro origen'
                : 'Otro destino',
            default => str_replace('_', ' ', ucfirst(strtolower($cashMovement->contraparte_tipo))),
        };
    }

    private function cashRegisterLabel(?CuentaFinanciera $cashRegister): string
    {
        return $cashRegister?->alias
            ?: $cashRegister?->entidadFinanciera?->nombre_comercial
            ?: $cashRegister?->entidadFinanciera?->razon_social
            ?: 'Otra caja';
    }

    /** @return Builder<Pago> */
    private function paymentQuery(int $companyId): Builder
    {
        return Pago::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Pago::STATUS_REGISTERED)
            ->whereNull('reversa_de_pago_id');
    }

    private function documentEffect(Comprobante $document): float
    {
        return (float) $document->total
            * ($document->naturaleza === Comprobante::NATURE_CREDIT ? -1 : 1);
    }

    private function customerChickenTypeLabel(?string $code, string $description): string
    {
        return match ($code) {
            TipoPollo::CHICKEN_LIVE => 'PV',
            TipoPollo::CHICKEN_DEAD => 'PM',
            TipoPollo::CHICKEN_DRESSED => 'PP',
            TipoPollo::CHICKEN_PROCESSED => 'PB',
            default => $description,
        };
    }

    private function customerStatementAccountLabel(?CuentaFinanciera $account): ?string
    {
        if (! $account) {
            return null;
        }

        $type = match ($account->tipo) {
            CuentaFinanciera::TYPE_CASH => 'Caja',
            CuentaFinanciera::TYPE_WALLET => 'Billetera',
            CuentaFinanciera::TYPE_BANK => 'Banco',
            default => 'Cuenta',
        };

        return $type.': '.$account->alias;
    }

    private function paymentEffect(Pago $payment, string $operation): float
    {
        if ($operation === Comprobante::OPERATION_SALE) {
            return match ($payment->tipo) {
                Pago::TYPE_CUSTOMER_REFUND => abs((float) $payment->importe),
                Pago::TYPE_CUSTOMER_COLLECTION,
                Pago::TYPE_RETAIL_COLLECTION,
                Pago::TYPE_DIRECT_PAYMENT,
                Pago::TYPE_CUSTOMER_DISCOUNT => -abs((float) $payment->importe),
                default => $this->flow($payment) === Pago::DIRECTION_INCOME
                    ? -abs((float) $payment->importe)
                    : abs((float) $payment->importe),
            };
        }

        return match ($payment->tipo) {
            Pago::TYPE_DIRECT_PAYMENT,
            Pago::TYPE_UNASSIGNED_DEPOSIT,
            Pago::TYPE_PROVIDER_PAYMENT,
            Pago::TYPE_PROVIDER_CREDIT => -abs((float) $payment->importe),
            default => $this->flow($payment) === Pago::DIRECTION_EXPENSE
                ? -abs((float) $payment->importe)
                : abs((float) $payment->importe),
        };
    }

    /**
     * Una cobranza dirigida a un proveedor conserva un Pago por cliente para
     * trazabilidad. En el estado de cuenta se representa como el deposito unico
     * que realmente recibio el proveedor.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function consolidateProviderCollectionRows(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row): string => $row['collection_id'] === null
                ? 'payment:'.$row['payment_id']
                : 'collection:'.$row['collection_id'])
            ->map(function (Collection $group): array {
                $row = $group->first();
                $effect = round((float) $group->sum('effect'), 2);

                $row['debit'] = $effect > 0 ? abs($effect) : 0;
                $row['credit'] = $effect < 0 ? abs($effect) : 0;
                $row['effect'] = $effect;
                unset($row['payment_id'], $row['collection_id']);

                return $row;
            })
            ->values();
    }

    private function flow(Pago $payment): string
    {
        if (in_array($payment->tipo, [
            Pago::TYPE_CUSTOMER_COLLECTION,
            Pago::TYPE_RETAIL_COLLECTION,
            Pago::TYPE_OPENING_BALANCE,
        ], true)) {
            return Pago::DIRECTION_INCOME;
        }

        if ($payment->tipo === Pago::TYPE_CUSTOMER_DISCOUNT) {
            return Pago::DIRECTION_NO_FLOW;
        }

        if (in_array($payment->tipo, [
            Pago::TYPE_PROVIDER_PAYMENT,
            Pago::TYPE_CUSTOMER_REFUND,
        ], true)) {
            return Pago::DIRECTION_EXPENSE;
        }

        return in_array($payment->direccion, [Pago::DIRECTION_INCOME, Pago::DIRECTION_EXPENSE], true)
            ? $payment->direccion
            : Pago::DIRECTION_NO_FLOW;
    }
}
