<?php

namespace App\Services;

use App\Models\Pago;
use App\Support\FinancialMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialQueryService
{
    public function __construct(private readonly FinancialAccountBalanceService $balances) {}

    /** @return array<string, mixed> */
    public function catalog(int $companyId): array
    {
        $entities = DB::table('entidades_financieras as entidad')
            ->leftJoin('terceros as proveedor', 'proveedor.id', '=', 'entidad.proveedor_id')
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.estado', 'ACTIVO')
            ->select([
                'entidad.id', 'entidad.tipo', 'entidad.proveedor_id', 'entidad.razon_social',
                'entidad.nombre_comercial', 'proveedor.nombre_razon_social as proveedor_nombre',
            ])
            ->orderBy('entidad.tipo')
            ->orderBy('entidad.razon_social')
            ->get();
        $entityIds = $entities->pluck('id')->all();
        $accounts = DB::table('cuentas_financieras')
            ->whereIn('entidad_financiera_id', $entityIds)
            ->where('estado', 'ACTIVO')
            ->orderBy('alias')
            ->get()
            ->groupBy('entidad_financiera_id');

        $thirdParties = function (string $role) use ($companyId): array {
            return DB::table('terceros as tercero')
                ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
                ->where('tercero.empresa_id', $companyId)
                ->where('tercero.estado', 'ACTIVO')
                ->where('rol.rol', $role)
                ->orderBy('tercero.nombre_razon_social')
                ->get(['tercero.id', 'tercero.numero_documento', 'tercero.nombre_razon_social'])
                ->map(fn (object $party): array => [
                    'id' => (int) $party->id,
                    'numero_documento' => $party->numero_documento,
                    'nombre' => $party->nombre_razon_social,
                ])->all();
        };

        return [
            'entidades' => $entities->map(function (object $entity) use ($accounts): array {
                return [
                    'id' => (int) $entity->id,
                    'tipo' => $entity->tipo,
                    'proveedor_id' => $entity->proveedor_id === null ? null : (int) $entity->proveedor_id,
                    'razon_social' => $entity->razon_social,
                    'nombre_comercial' => $entity->nombre_comercial,
                    'proveedor_nombre' => $entity->proveedor_nombre,
                    'cuentas' => collect($accounts->get($entity->id, collect()))
                        ->map(fn (object $account): array => $this->accountSummary($account))
                        ->values()->all(),
                ];
            })->all(),
            'metodos_pago' => DB::table('metodos_pago')
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get()
                ->map(fn (object $method): array => [
                    'id' => (int) $method->id,
                    'codigo' => $method->codigo,
                    'nombre' => $method->nombre,
                    'requiere_referencia' => (bool) $method->requiere_referencia,
                ])->all(),
            'clientes' => $thirdParties('CLIENTE'),
            'proveedores' => $thirdParties('PROVEEDOR'),
            'tipos_movimiento' => Pago::TYPES,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function portfolio(int $companyId, array $filters): array
    {
        $side = $filters['lado'];
        $currency = $filters['moneda'] ?? $this->companyCurrency($companyId);
        $operation = $side === 'CXC' ? 'VENTA' : 'COMPRA';
        $thirdPartyId = $side === 'CXC'
            ? ($filters['cliente_id'] ?? $filters['tercero_id'] ?? null)
            : ($filters['proveedor_id'] ?? $filters['tercero_id'] ?? null);

        $query = DB::table('comprobantes as comprobante')
            ->leftJoin('terceros as tercero', 'tercero.id', '=', 'comprobante.tercero_id')
            ->where('comprobante.empresa_id', $companyId)
            ->where('comprobante.operacion', $operation)
            ->where('comprobante.moneda', $currency)
            ->when($thirdPartyId, fn (Builder $query, int|string $id) => $query->where('comprobante.tercero_id', $id))
            ->when($filters['estado'] ?? null, fn (Builder $query, string $status) => $query->where('comprobante.estado', $status))
            ->when($filters['naturaleza'] ?? null, fn (Builder $query, string $nature) => $query->where('comprobante.naturaleza', $nature))
            ->when(($filters['solo_pendientes'] ?? true), fn (Builder $query) => $query
                ->where('comprobante.saldo_pendiente', '>', 0)
                ->where('comprobante.estado', '!=', 'ANULADO'))
            ->when($filters['desde'] ?? null, fn (Builder $query, string $date) => $query->whereDate('comprobante.fecha_emision', '>=', $date))
            ->when($filters['hasta'] ?? null, fn (Builder $query, string $date) => $query->whereDate('comprobante.fecha_emision', '<=', $date))
            ->when($filters['ticket_id'] ?? null, fn (Builder $query, int|string $ticketId) => $query->whereExists(
                fn (Builder $pivot) => $pivot->selectRaw('1')
                    ->from('comprobante_tickets as ct')
                    ->whereColumn('ct.comprobante_id', 'comprobante.id')
                    ->where('ct.ticket_id', $ticketId)
            ))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('comprobante.codigo', 'like', "%{$search}%")
                        ->orWhere('comprobante.origen_clave', 'like', "%{$search}%")
                        ->orWhere('tercero.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhere('tercero.numero_documento', 'like', "%{$search}%")
                        ->orWhere('comprobante.contraparte_nombre_snapshot', 'like', "%{$search}%")
                        ->orWhere('comprobante.contraparte_numero_documento_snapshot', 'like', "%{$search}%");
                });
            })
            ->select([
                'comprobante.*',
                'tercero.nombre_razon_social as tercero_nombre',
                'tercero.numero_documento as tercero_documento',
            ]);

        $summaryRows = (clone $query)->get(['comprobante.naturaleza', 'comprobante.saldo_pendiente']);
        $charges = '0.00';
        $credits = '0.00';
        foreach ($summaryRows as $row) {
            if ($row->naturaleza === 'ABONO') {
                $credits = FinancialMoney::add($credits, (string) $row->saldo_pendiente);
            } else {
                $charges = FinancialMoney::add($charges, (string) $row->saldo_pendiente);
            }
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderBy('comprobante.fecha_emision')
            ->orderBy('comprobante.id')
            ->paginate((int) ($filters['per_page'] ?? 50));
        $documentIds = collect($paginator->items())->pluck('id')->all();
        $tickets = $this->ticketsForDocuments($documentIds);

        return [
            'data' => collect($paginator->items())->map(function (object $document) use ($tickets, $side): array {
                return [
                    'id' => (int) $document->id,
                    'lado' => $side,
                    'operacion' => $document->operacion,
                    'naturaleza' => $document->naturaleza,
                    'codigo' => $document->codigo,
                    'origen_clave' => $document->origen_clave,
                    'fecha_emision' => $document->fecha_emision,
                    'fecha_vencimiento' => $document->fecha_vencimiento,
                    'moneda' => $document->moneda,
                    'total' => FinancialMoney::normalize((string) $document->total),
                    'saldo_pendiente' => FinancialMoney::normalize((string) $document->saldo_pendiente),
                    'estado' => $document->estado,
                    'tercero' => $this->documentThirdParty($document),
                    'tickets' => $tickets[(int) $document->id] ?? [],
                ];
            })->all(),
            'resumen' => [
                'lado' => $side,
                'moneda' => $currency,
                'cargos_pendientes' => $charges,
                'abonos_pendientes' => $credits,
                'saldo_neto' => FinancialMoney::subtract($charges, $credits),
            ],
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function balances(int $companyId, array $filters): array
    {
        $summaryCurrency = $filters['moneda'] ?? $this->companyCurrency($companyId);
        $accounts = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.tipo', 'PROPIA')
            ->when(! ($filters['incluir_inactivas'] ?? false), fn (Builder $query) => $query
                ->where('entidad.estado', 'ACTIVO')
                ->where('cuenta.estado', 'ACTIVO'))
            ->when($filters['moneda'] ?? null, fn (Builder $query, string $currency) => $query->where('cuenta.moneda', strtoupper($currency)))
            ->orderBy('entidad.razon_social')
            ->orderBy('cuenta.alias')
            ->get([
                'cuenta.*',
                'entidad.razon_social as entidad_razon_social',
                'entidad.nombre_comercial as entidad_nombre_comercial',
                'entidad.estado as entidad_estado',
            ]);

        $totals = [];
        $rows = $accounts->map(function (object $account) use (&$totals): array {
            $balance = $this->balances->forAccount((int) $account->id);
            $currency = $account->moneda;
            $totals[$currency] ??= ['entradas' => '0.00', 'salidas' => '0.00', 'saldo' => '0.00'];
            foreach (['entradas', 'salidas', 'saldo'] as $field) {
                $totals[$currency][$field] = FinancialMoney::add($totals[$currency][$field], $balance[$field]);
            }

            return [
                ...$this->accountSummary($account),
                'entidad' => [
                    'id' => (int) $account->entidad_financiera_id,
                    'razon_social' => $account->entidad_razon_social,
                    'nombre_comercial' => $account->entidad_nombre_comercial,
                    'estado' => $account->entidad_estado,
                ],
                ...$balance,
            ];
        })->all();

        $portfolio = $this->portfolioSummary($companyId, $summaryCurrency);
        $providerCredit = $this->providerCreditSummary($companyId, $summaryCurrency);

        return [
            'data' => $rows,
            'totales_por_moneda' => collect($totals)->map(fn (array $amounts, string $currency): array => [
                'moneda' => $currency,
                ...$amounts,
            ])->values()->all(),
            'cartera' => $portfolio,
            'pagos_proveedores' => [
                'moneda' => $summaryCurrency,
                'directos_clientes' => $this->paymentTotal($companyId, 'PAGO_DIRECTO', $summaryCurrency),
                'realizados_por_empresa' => $this->paymentTotal(
                    $companyId,
                    Pago::TYPE_PROVIDER_PAYMENT,
                    $summaryCurrency,
                ),
            ],
            'saldo_favor_proveedores' => [
                'moneda' => $summaryCurrency,
                'disponible' => $providerCredit['disponible'],
                'originado_en_pagos' => $providerCredit[Pago::TYPE_PROVIDER_PAYMENT],
                'registrado_manualmente' => $providerCredit[Pago::TYPE_PROVIDER_CREDIT],
            ],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function movements(int $companyId, array $filters, bool $trace = false): array
    {
        $query = $this->movementQuery($companyId, $filters, $trace);
        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderByDesc('pago.fecha_hora')
            ->orderByDesc('pago.id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return [
            'data' => $this->formatMovements(collect($paginator->items()), $trace),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /** @return array<string, mixed> */
    public function movement(int $companyId, int $paymentId): array
    {
        $row = $this->movementQuery($companyId, [])->where('pago.id', $paymentId)->first();
        abort_unless($row, 404, 'Movimiento financiero no encontrado.');

        return $this->formatMovements(collect([$row]), true)[0];
    }

    /** @param array<string, mixed> $filters */
    private function movementQuery(int $companyId, array $filters, bool $trace = false): Builder
    {
        return DB::table('pagos as pago')
            ->leftJoin('terceros as cliente', 'cliente.id', '=', 'pago.cliente_id')
            ->leftJoin('terceros as proveedor', 'proveedor.id', '=', 'pago.proveedor_id')
            ->leftJoin('metodos_pago as metodo_pago', 'metodo_pago.id', '=', 'pago.metodo_pago_id')
            ->leftJoin('cuentas_financieras as cuenta_origen', 'cuenta_origen.id', '=', 'pago.cuenta_origen_id')
            ->leftJoin('entidades_financieras as entidad_origen', 'entidad_origen.id', '=', 'cuenta_origen.entidad_financiera_id')
            ->leftJoin('cuentas_financieras as cuenta_destino', 'cuenta_destino.id', '=', 'pago.cuenta_destino_id')
            ->leftJoin('entidades_financieras as entidad_destino', 'entidad_destino.id', '=', 'cuenta_destino.entidad_financiera_id')
            ->where('pago.empresa_id', $companyId)
            ->when($filters['tipo'] ?? null, fn (Builder $query, string $type) => $query->where('pago.tipo', $type))
            ->when($filters['estado'] ?? null, fn (Builder $query, string $status) => $query->where('pago.estado', $status))
            ->when($filters['aplicacion_estado'] ?? null, function (Builder $query, string $status): void {
                $appliedSql = "(SELECT COALESCE(SUM(pa.importe_aplicado), 0) FROM pago_aplicaciones pa WHERE pa.pago_id = pago.id AND pa.lado = 'CXP')";
                $query->whereIn('pago.tipo', Pago::PROVIDER_CREDIT_SOURCE_TYPES)
                    ->where('pago.estado', 'REGISTRADO')
                    ->whereNull('pago.reversa_de_pago_id');

                match ($status) {
                    'SIN_APLICAR' => $query->whereRaw("{$appliedSql} = 0"),
                    'PARCIAL' => $query->whereRaw("{$appliedSql} > 0")
                        ->whereRaw("pago.importe > {$appliedSql}"),
                    'APLICADO' => $query->whereRaw("pago.importe <= {$appliedSql}"),
                    'CON_SALDO' => $query->whereRaw("pago.importe > {$appliedSql}"),
                };
            })
            ->when($filters['cliente_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('pago.cliente_id', $id))
            ->when($filters['proveedor_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('pago.proveedor_id', $id))
            ->when($filters['metodo_pago_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('pago.metodo_pago_id', $id))
            ->when($filters['moneda'] ?? null, fn (Builder $query, string $currency) => $query->where('pago.moneda', $currency))
            ->when($filters['cuenta_id'] ?? null, fn (Builder $query, int|string $id) => $query->where(
                fn (Builder $nested) => $nested->where('pago.cuenta_origen_id', $id)->orWhere('pago.cuenta_destino_id', $id)
            ))
            ->when($filters['entidad_financiera_id'] ?? null, fn (Builder $query, int|string $id) => $query->where(
                fn (Builder $nested) => $nested->where('cuenta_origen.entidad_financiera_id', $id)->orWhere('cuenta_destino.entidad_financiera_id', $id)
            ))
            ->when($filters['desde'] ?? null, fn (Builder $query, string $date) => $query->whereDate('pago.fecha_hora', '>=', $date))
            ->when($filters['hasta'] ?? null, fn (Builder $query, string $date) => $query->whereDate('pago.fecha_hora', '<=', $date))
            ->when($filters['comprobante_id'] ?? null, fn (Builder $query, int|string $id) => $query->whereExists(
                fn (Builder $application) => $application->selectRaw('1')->from('pago_aplicaciones as pa')
                    ->whereColumn('pa.pago_id', 'pago.id')->where('pa.comprobante_id', $id)
            ))
            ->when($filters['ticket_id'] ?? null, fn (Builder $query, int|string $id) => $query->whereExists(
                fn (Builder $application) => $application->selectRaw('1')
                    ->from('pago_aplicaciones as pa')
                    ->join('comprobante_tickets as ct', 'ct.comprobante_id', '=', 'pa.comprobante_id')
                    ->whereColumn('pa.pago_id', 'pago.id')
                    ->where('ct.ticket_id', $id)
            ))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('pago.codigo', 'like', "%{$search}%")
                        ->orWhere('pago.referencia', 'like', "%{$search}%")
                        ->orWhere('cliente.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhere('proveedor.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhere('entidad_origen.razon_social', 'like', "%{$search}%")
                        ->orWhere('entidad_destino.razon_social', 'like', "%{$search}%");
                });
            })
            ->select([
                'pago.*',
                'cliente.nombre_razon_social as cliente_nombre',
                'cliente.numero_documento as cliente_documento',
                'proveedor.nombre_razon_social as proveedor_nombre',
                'proveedor.numero_documento as proveedor_documento',
                'metodo_pago.codigo as metodo_codigo',
                'metodo_pago.nombre as metodo_nombre',
                'cuenta_origen.alias as cuenta_origen_alias',
                'cuenta_origen.tipo as cuenta_origen_tipo',
                'cuenta_origen.moneda as cuenta_origen_moneda',
                'entidad_origen.id as entidad_origen_id',
                'entidad_origen.razon_social as entidad_origen_nombre',
                'entidad_origen.tipo as entidad_origen_tipo',
                'cuenta_destino.alias as cuenta_destino_alias',
                'cuenta_destino.tipo as cuenta_destino_tipo',
                'cuenta_destino.moneda as cuenta_destino_moneda',
                'entidad_destino.id as entidad_destino_id',
                'entidad_destino.razon_social as entidad_destino_nombre',
                'entidad_destino.tipo as entidad_destino_tipo',
            ]);
    }

    /** @param Collection<int, object> $payments @return list<array<string, mixed>> */
    private function formatMovements(Collection $payments, bool $trace): array
    {
        if ($payments->isEmpty()) {
            return [];
        }
        $paymentIds = $payments->pluck('id')->all();
        $applications = DB::table('pago_aplicaciones as aplicacion')
            ->join('comprobantes as comprobante', 'comprobante.id', '=', 'aplicacion.comprobante_id')
            ->whereIn('aplicacion.pago_id', $paymentIds)
            ->orderBy('aplicacion.comprobante_id')
            ->get([
                'aplicacion.pago_id', 'aplicacion.comprobante_id', 'aplicacion.lado',
                'aplicacion.importe_aplicado', 'comprobante.codigo', 'comprobante.operacion',
                'comprobante.naturaleza', 'comprobante.total', 'comprobante.saldo_pendiente',
                'comprobante.estado', 'comprobante.origen_clave',
            ]);
        $documentIds = $applications->pluck('comprobante_id')->unique()->all();
        $tickets = $this->ticketsForDocuments($documentIds);
        $weighings = $trace ? $this->weighingsForDocuments($documentIds) : [];
        $applicationGroups = $applications->groupBy('pago_id');

        return $payments->map(function (object $payment) use ($applicationGroups, $tickets, $weighings): array {
            $items = collect($applicationGroups->get($payment->id, collect()))->map(function (object $application) use ($tickets, $weighings): array {
                return [
                    'lado' => $application->lado,
                    'comprobante_id' => (int) $application->comprobante_id,
                    'importe_aplicado' => FinancialMoney::normalize((string) $application->importe_aplicado),
                    'comprobante' => [
                        'codigo' => $application->codigo,
                        'operacion' => $application->operacion,
                        'naturaleza' => $application->naturaleza,
                        'total' => FinancialMoney::normalize((string) $application->total),
                        'saldo_pendiente' => FinancialMoney::normalize((string) $application->saldo_pendiente),
                        'estado' => $application->estado,
                        'origen_clave' => $application->origen_clave,
                        'tickets' => $tickets[(int) $application->comprobante_id] ?? [],
                        'pesadas' => $weighings[(int) $application->comprobante_id] ?? [],
                    ],
                ];
            })->all();

            $providerApplication = null;
            if (in_array($payment->tipo, Pago::PROVIDER_CREDIT_SOURCE_TYPES, true)) {
                $applied = collect($items)
                    ->where('lado', 'CXP')
                    ->reduce(
                        fn (string $sum, array $application): string => FinancialMoney::add(
                            $sum,
                            $application['importe_aplicado'],
                        ),
                        '0.00',
                    );
                $unapplied = FinancialMoney::subtract((string) $payment->importe, $applied);
                if (FinancialMoney::compare($unapplied, '0.00') < 0) {
                    $unapplied = '0.00';
                }
                $applicationStatus = match (true) {
                    $payment->estado === 'ANULADO' => 'ANULADO',
                    $payment->reversa_de_pago_id !== null => 'REVERSA',
                    FinancialMoney::compare($applied, '0.00') === 0 => 'SIN_APLICAR',
                    FinancialMoney::compare($unapplied, '0.00') > 0 => 'PARCIAL',
                    default => 'APLICADO',
                };
                $providerApplication = [
                    'lado' => 'CXP',
                    'importe_aplicado' => $applied,
                    'importe_sin_aplicar' => $unapplied,
                    'estado' => $applicationStatus,
                    'puede_aplicar' => $applicationStatus === 'SIN_APLICAR'
                        || $applicationStatus === 'PARCIAL',
                ];
            }

            return [
                'id' => (int) $payment->id,
                'codigo' => $payment->codigo,
                'tipo' => $payment->tipo,
                'fecha_hora' => $payment->fecha_hora,
                'cliente' => $payment->cliente_id === null ? null : [
                    'id' => (int) $payment->cliente_id,
                    'numero_documento' => $payment->cliente_documento,
                    'nombre' => $payment->cliente_nombre,
                ],
                'proveedor' => $payment->proveedor_id === null ? null : [
                    'id' => (int) $payment->proveedor_id,
                    'numero_documento' => $payment->proveedor_documento,
                    'nombre' => $payment->proveedor_nombre,
                ],
                'cuenta_origen' => $this->movementAccount($payment, 'origen'),
                'cuenta_destino' => $this->movementAccount($payment, 'destino'),
                'metodo_pago' => $payment->metodo_pago_id === null ? null : [
                    'id' => (int) $payment->metodo_pago_id,
                    'codigo' => $payment->metodo_codigo,
                    'nombre' => $payment->metodo_nombre,
                ],
                'metodo_snapshot' => $payment->metodo,
                'direccion' => $payment->direccion,
                'referencia' => $payment->referencia,
                'moneda' => $payment->moneda,
                'importe' => FinancialMoney::normalize((string) $payment->importe),
                'observaciones' => $payment->observaciones,
                'estado' => $payment->estado,
                'idempotency_key' => $payment->idempotency_key,
                'reversa_de_pago_id' => $payment->reversa_de_pago_id === null ? null : (int) $payment->reversa_de_pago_id,
                'anulada_por' => $payment->anulada_por === null ? null : (int) $payment->anulada_por,
                'anulada_at' => $payment->anulada_at,
                'motivo_anulacion' => $payment->motivo_anulacion,
                'aplicaciones' => $items,
                'aplicacion' => $providerApplication,
                'created_by' => (int) $payment->created_by,
                'created_at' => $payment->created_at,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function movementAccount(object $payment, string $direction): ?array
    {
        $accountId = $payment->{"cuenta_{$direction}_id"};
        if ($accountId === null) {
            return null;
        }

        return [
            'id' => (int) $accountId,
            'alias' => $payment->{"cuenta_{$direction}_alias"},
            'tipo' => $payment->{"cuenta_{$direction}_tipo"},
            'moneda' => $payment->{"cuenta_{$direction}_moneda"},
            'entidad' => [
                'id' => (int) $payment->{"entidad_{$direction}_id"},
                'razon_social' => $payment->{"entidad_{$direction}_nombre"},
                'tipo' => $payment->{"entidad_{$direction}_tipo"},
            ],
        ];
    }

    /** @param list<int> $documentIds @return array<int, list<array<string, mixed>>> */
    private function ticketsForDocuments(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return DB::table('comprobante_tickets as pivot')
            ->join('tickets_despacho as ticket', 'ticket.id', '=', 'pivot.ticket_id')
            ->whereIn('pivot.comprobante_id', $documentIds)
            ->get(['pivot.comprobante_id', 'ticket.id', 'ticket.codigo', 'ticket.canal', 'ticket.estado'])
            ->groupBy('comprobante_id')
            ->map(fn ($rows) => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'codigo' => $row->codigo,
                'canal' => $row->canal,
                'estado' => $row->estado,
            ])->all())->all();
    }

    /** @param list<int> $documentIds @return array<int, list<array<string, mixed>>> */
    private function weighingsForDocuments(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return DB::table('comprobante_pesadas as pivot')
            ->join('pesadas as pesada', 'pesada.id', '=', 'pivot.pesada_id')
            ->leftJoin('terceros as proveedor', 'proveedor.id', '=', 'pesada.proveedor_origen_id')
            ->whereIn('pivot.comprobante_id', $documentIds)
            ->get([
                'pivot.comprobante_id', 'pesada.id', 'pesada.ticket_id', 'pesada.numero',
                'pesada.peso_neto_kg', 'pesada.proveedor_origen_id',
                'proveedor.nombre_razon_social as proveedor_nombre',
            ])
            ->groupBy('comprobante_id')
            ->map(fn ($rows) => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'ticket_id' => (int) $row->ticket_id,
                'numero' => (int) $row->numero,
                'peso_neto_kg' => (string) $row->peso_neto_kg,
                'proveedor' => $row->proveedor_origen_id === null ? null : [
                    'id' => (int) $row->proveedor_origen_id,
                    'nombre' => $row->proveedor_nombre,
                ],
            ])->all())->all();
    }

    /** @return array<string, string> */
    private function portfolioSummary(int $companyId, string $currency): array
    {
        $totals = ['CXC' => '0.00', 'CXP' => '0.00'];
        $rows = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('moneda', $currency)
            ->where('estado', '!=', 'ANULADO')
            ->whereIn('operacion', ['VENTA', 'COMPRA'])
            ->get(['operacion', 'naturaleza', 'saldo_pendiente']);
        foreach ($rows as $row) {
            $side = $row->operacion === 'VENTA' ? 'CXC' : 'CXP';
            $amount = FinancialMoney::normalize((string) $row->saldo_pendiente);
            $totals[$side] = $row->naturaleza === 'ABONO'
                ? FinancialMoney::subtract($totals[$side], $amount)
                : FinancialMoney::add($totals[$side], $amount);
        }

        return ['moneda' => $currency, 'por_cobrar' => $totals['CXC'], 'por_pagar' => $totals['CXP']];
    }

    /**
     * @return array{disponible: string, PAGO_PROVEEDOR: string, SALDO_FAVOR_PROVEEDOR: string}
     */
    private function providerCreditSummary(int $companyId, string $currency): array
    {
        $rows = DB::table('pagos as pago')
            ->leftJoin('pago_aplicaciones as aplicacion', function ($join): void {
                $join->on('aplicacion.pago_id', '=', 'pago.id')
                    ->where('aplicacion.lado', 'CXP');
            })
            ->where('pago.empresa_id', $companyId)
            ->whereIn('pago.tipo', Pago::PROVIDER_CREDIT_SOURCE_TYPES)
            ->where('pago.moneda', $currency)
            ->where('pago.estado', Pago::STATUS_REGISTERED)
            ->whereNull('pago.reversa_de_pago_id')
            ->groupBy('pago.id', 'pago.tipo', 'pago.importe')
            ->get([
                'pago.id',
                'pago.tipo',
                'pago.importe',
                DB::raw('COALESCE(SUM(aplicacion.importe_aplicado), 0) as importe_aplicado'),
            ]);

        $totals = [
            'disponible' => '0.00',
            Pago::TYPE_PROVIDER_PAYMENT => '0.00',
            Pago::TYPE_PROVIDER_CREDIT => '0.00',
        ];
        foreach ($rows as $row) {
            $available = FinancialMoney::subtract(
                (string) $row->importe,
                (string) $row->importe_aplicado,
            );
            if (FinancialMoney::compare($available, '0.00') < 0) {
                $available = '0.00';
            }
            $totals['disponible'] = FinancialMoney::add($totals['disponible'], $available);
            $totals[$row->tipo] = FinancialMoney::add($totals[$row->tipo], $available);
        }

        return $totals;
    }

    private function paymentTotal(int $companyId, string $type, string $currency): string
    {
        // Original and reversal ledger rows are both included, producing a net total.
        $entries = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where('tipo', $type)
            ->where('moneda', $currency)
            ->get();
        $total = '0.00';
        foreach ($entries as $payment) {
            $amount = FinancialMoney::normalize((string) $payment->importe);
            $total = $payment->reversa_de_pago_id === null
                ? FinancialMoney::add($total, $amount)
                : FinancialMoney::subtract($total, $amount);
        }

        return $total;
    }

    private function companyCurrency(int $companyId): string
    {
        return (string) (DB::table('empresas')->where('id', $companyId)->value('moneda') ?: 'PEN');
    }

    private function documentThirdParty(object $document): ?array
    {
        $name = $document->tercero_nombre ?? $document->contraparte_nombre_snapshot;
        $documentNumber = $document->tercero_documento ?? $document->contraparte_numero_documento_snapshot;
        if ($document->tercero_id === null && $name === null) {
            return null;
        }

        return [
            'id' => $document->tercero_id === null ? null : (int) $document->tercero_id,
            'numero_documento' => $documentNumber,
            'nombre' => $name,
        ];
    }

    /** @return array<string, mixed> */
    private function accountSummary(object $account): array
    {
        return [
            'id' => (int) $account->id,
            'entidad_financiera_id' => (int) $account->entidad_financiera_id,
            'tipo' => $account->tipo,
            'alias' => $account->alias,
            'banco' => $account->banco,
            'numero_cuenta' => $account->numero_cuenta,
            'cci' => $account->cci,
            'moneda' => $account->moneda,
            'estado' => $account->estado,
        ];
    }

    /** @return array<string, int> */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
