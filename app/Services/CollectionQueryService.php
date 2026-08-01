<?php

namespace App\Services;

use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CollectionQueryService
{
    /** @return array<string, mixed> */
    public function catalog(int $companyId): array
    {
        return [
            'timezone' => $this->companyTimezone($companyId),
            'moneda' => $this->companyCurrency($companyId),
            'cobradores' => DB::table('cobradores')
                ->where('empresa_id', $companyId)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'estado'])
                ->map(fn (object $collector): array => [
                    'id' => (int) $collector->id,
                    'nombre' => $collector->nombre,
                    'estado' => $collector->estado,
                ])->all(),
            'clientes' => DB::table('terceros as cliente')
                ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'cliente.id')
                ->where('cliente.empresa_id', $companyId)
                ->where('cliente.estado', 'ACTIVO')
                ->where('rol.rol', 'CLIENTE')
                ->orderBy('cliente.nombre_razon_social')
                ->get(['cliente.id', 'cliente.numero_documento', 'cliente.nombre_razon_social'])
                ->map(fn (object $client): array => [
                    'id' => (int) $client->id,
                    'numero_documento' => $client->numero_documento,
                    'nombre' => $client->nombre_razon_social,
                ])->all(),
            'cuentas_destino' => $this->destinationAccounts($companyId),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginate(int $companyId, array $filters): array
    {
        $query = $this->headerQuery($companyId);
        $timezone = $this->companyTimezone($companyId);

        if ($filters['desde'] ?? null) {
            [$from] = $this->databaseDayRange((string) $filters['desde'], $timezone);
            $query->where('cobranza.fecha_hora', '>=', $from);
        }
        if ($filters['hasta'] ?? null) {
            [, $to] = $this->databaseDayRange((string) $filters['hasta'], $timezone);
            $query->where('cobranza.fecha_hora', '<', $to);
        }

        $query
            ->when($filters['cobrador_id'] ?? null, fn (Builder $builder, int|string $id) => $builder
                ->where('cobranza.cobrador_id', $id))
            ->when($filters['estado'] ?? null, fn (Builder $builder, string $status) => $builder
                ->where('cobranza.estado', $status))
            ->when($filters['conciliacion'] ?? null, function (Builder $builder, string $status): void {
                if ($status === 'PENDIENTE') {
                    $builder->whereNotNull('pendiente.id');
                } elseif ($status === 'COMPLETA') {
                    $builder->whereNull('pendiente.id');
                }
            })
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $builder) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested->where('cobranza.codigo', 'like', "%{$search}%")
                        ->orWhere('cobranza.referencia', 'like', "%{$search}%")
                        ->orWhere('cobranza.cobrador_nombre_snapshot', 'like', "%{$search}%")
                        ->orWhere('cuenta.alias', 'like', "%{$search}%")
                        ->orWhere('entidad.razon_social', 'like', "%{$search}%")
                        ->orWhere('proveedor.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhereExists(fn (Builder $details) => $details
                            ->selectRaw('1')
                            ->from('cobranza_detalles as detalle_busqueda')
                            ->join('terceros as cliente_busqueda', 'cliente_busqueda.id', '=', 'detalle_busqueda.cliente_id')
                            ->whereColumn('detalle_busqueda.cobranza_id', 'cobranza.id')
                            ->where(function (Builder $client) use ($search): void {
                                $client->where('cliente_busqueda.nombre_razon_social', 'like', "%{$search}%")
                                    ->orWhere('cliente_busqueda.numero_documento', 'like', "%{$search}%");
                            }));
                });
            });

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByDesc('cobranza.fecha_hora')
            ->orderByDesc('cobranza.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $row): array => $this->formatHeader($row, $timezone))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function find(int $companyId, int $collectionId): array
    {
        $row = $this->headerQuery($companyId)
            ->where('cobranza.id', $collectionId)
            ->first();
        abort_unless($row, 404, 'Cobranza no encontrada.');

        $timezone = $this->companyTimezone($companyId);
        $result = $this->formatHeader($row, $timezone);
        $result['detalles'] = $this->details($collectionId, $timezone);

        return $result;
    }

    private function headerQuery(int $companyId): Builder
    {
        return DB::table('cobranzas as cobranza')
            ->join('cobradores as cobrador', 'cobrador.id', '=', 'cobranza.cobrador_id')
            ->join('cuentas_financieras as cuenta', 'cuenta.id', '=', 'cobranza.cuenta_destino_id')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->leftJoin('terceros as proveedor', 'proveedor.id', '=', 'cobranza.proveedor_id')
            ->leftJoin('cobranza_pendientes as pendiente', 'pendiente.cobranza_id', '=', 'cobranza.id')
            ->leftJoin('pagos as pago_pendiente', 'pago_pendiente.id', '=', 'pendiente.pago_id')
            ->leftJoin('pagos as reversa_pendiente', 'reversa_pendiente.reversa_de_pago_id', '=', 'pago_pendiente.id')
            ->leftJoin('usuarios as creador', 'creador.id', '=', 'cobranza.created_by')
            ->leftJoin('usuarios as anulador', 'anulador.id', '=', 'cobranza.anulada_por')
            ->where('cobranza.empresa_id', $companyId)
            ->select([
                'cobranza.*',
                'cobrador.nombre as cobrador_nombre_actual',
                'cobrador.estado as cobrador_estado_actual',
                'cuenta.alias as cuenta_alias',
                'cuenta.tipo as cuenta_tipo',
                'cuenta.banco as cuenta_banco',
                'cuenta.numero_cuenta as cuenta_numero',
                'cuenta.cci as cuenta_cci',
                'cuenta.estado as cuenta_estado',
                'entidad.id as entidad_id',
                'entidad.tipo as entidad_tipo',
                'entidad.razon_social as entidad_nombre',
                'entidad.nombre_comercial as entidad_nombre_comercial',
                'proveedor.numero_documento as proveedor_documento',
                'proveedor.nombre_razon_social as proveedor_nombre',
                'pendiente.id as pendiente_id',
                'pendiente.importe as pendiente_importe',
                'pendiente.pago_id as pendiente_pago_id',
                'pago_pendiente.codigo as pendiente_pago_codigo',
                'pago_pendiente.tipo as pendiente_pago_tipo',
                'pago_pendiente.estado as pendiente_pago_estado',
                'pago_pendiente.idempotency_key as pendiente_pago_idempotency_key',
                'reversa_pendiente.id as pendiente_reversa_id',
                'reversa_pendiente.codigo as pendiente_reversa_codigo',
                'creador.nombre as creador_nombre',
                'anulador.nombre as anulador_nombre',
            ])
            ->selectSub(
                DB::table('cobranza_detalles as detalle_conteo')
                    ->whereColumn('detalle_conteo.cobranza_id', 'cobranza.id')
                    ->selectRaw('COUNT(*)'),
                'detalles_count',
            )
            ->selectSub(
                DB::table('cobranza_detalles as detalle_importe')
                    ->whereColumn('detalle_importe.cobranza_id', 'cobranza.id')
                    ->selectRaw('COALESCE(SUM(detalle_importe.importe), 0)'),
                'importe_asignado',
            )
            ->selectSub(
                DB::table('cobranza_detalles as detalle_cxp')
                    ->join('pago_aplicaciones as aplicacion_cxp', function ($join): void {
                        $join->on('aplicacion_cxp.pago_id', '=', 'detalle_cxp.pago_id')
                            ->where('aplicacion_cxp.lado', 'CXP');
                    })
                    ->whereColumn('detalle_cxp.cobranza_id', 'cobranza.id')
                    ->selectRaw('COALESCE(SUM(aplicacion_cxp.importe_aplicado), 0)'),
                'importe_aplicado_cxp_detalles',
            )
            ->selectSub(
                DB::table('pago_aplicaciones as aplicacion_pendiente_cxp')
                    ->whereColumn('aplicacion_pendiente_cxp.pago_id', 'pendiente.pago_id')
                    ->where('aplicacion_pendiente_cxp.lado', 'CXP')
                    ->selectRaw('COALESCE(SUM(aplicacion_pendiente_cxp.importe_aplicado), 0)'),
                'importe_aplicado_cxp_pendiente',
            );
    }

    /** @return list<array<string, mixed>> */
    private function details(int $collectionId, string $timezone): array
    {
        $rows = DB::table('cobranza_detalles as detalle')
            ->join('terceros as cliente', 'cliente.id', '=', 'detalle.cliente_id')
            ->join('pagos as pago', 'pago.id', '=', 'detalle.pago_id')
            ->leftJoin('pagos as reversa', 'reversa.reversa_de_pago_id', '=', 'pago.id')
            ->where('detalle.cobranza_id', $collectionId)
            ->orderBy('detalle.orden')
            ->get([
                'detalle.*',
                'cliente.numero_documento as cliente_documento',
                'cliente.nombre_razon_social as cliente_nombre',
                'pago.codigo as movimiento_codigo',
                'pago.tipo as pago_tipo',
                'pago.fecha_hora as pago_fecha_hora',
                'pago.referencia as pago_referencia',
                'pago.estado as pago_estado',
                'pago.idempotency_key as pago_idempotency_key',
                'reversa.id as reversa_id',
                'reversa.codigo as reversa_codigo',
            ]);

        $applications = $this->applications($rows->pluck('pago_id')->all());

        return $rows->map(function (object $detail) use ($applications, $timezone): array {
            $groups = ['CXC' => [], 'CXP' => []];
            foreach ($applications->get($detail->pago_id, collect()) as $application) {
                $groups[$application->lado][] = [
                    'comprobante_id' => (int) $application->comprobante_id,
                    'codigo' => $application->codigo,
                    'fecha_emision' => $application->fecha_emision,
                    'importe_aplicado' => FinancialMoney::normalize((string) $application->importe_aplicado),
                    'saldo_pendiente' => FinancialMoney::normalize((string) $application->saldo_pendiente),
                    'estado' => $application->estado,
                ];
            }
            $appliedReceivable = collect($groups['CXC'])->reduce(
                fn (string $sum, array $application): string => FinancialMoney::add(
                    $sum,
                    $application['importe_aplicado'],
                ),
                '0.00',
            );
            $clientCredit = FinancialMoney::subtract(
                FinancialMoney::normalize((string) $detail->importe),
                $appliedReceivable,
            );
            if (FinancialMoney::compare($clientCredit, '0.00') < 0) {
                $clientCredit = '0.00';
            }

            return [
                'id' => (int) $detail->id,
                'fecha_recepcion' => $detail->fecha_recepcion,
                'medio_recepcion' => $detail->medio_recepcion,
                'importe' => FinancialMoney::normalize((string) $detail->importe),
                'cliente' => [
                    'id' => (int) $detail->cliente_id,
                    'numero_documento' => $detail->cliente_documento,
                    'nombre' => $detail->cliente_nombre,
                ],
                'movimiento_codigo' => $detail->movimiento_codigo,
                'importe_aplicado_cxc' => $appliedReceivable,
                'saldo_favor_cliente' => $clientCredit,
                'pago' => [
                    'id' => (int) $detail->pago_id,
                    'codigo' => $detail->movimiento_codigo,
                    'tipo' => $detail->pago_tipo,
                    'fecha_hora' => $this->localDateTime($detail->pago_fecha_hora, $timezone),
                    'referencia' => $detail->pago_referencia,
                    'estado' => $detail->pago_estado,
                    'idempotency_key' => $detail->pago_idempotency_key,
                    'reversa' => $detail->reversa_id === null ? null : [
                        'id' => (int) $detail->reversa_id,
                        'codigo' => $detail->reversa_codigo,
                    ],
                ],
                'aplicaciones' => $groups,
            ];
        })->all();
    }

    /** @param list<int> $paymentIds @return Collection<int|string, Collection<int, object>> */
    private function applications(array $paymentIds): Collection
    {
        if ($paymentIds === []) {
            return collect();
        }

        return DB::table('pago_aplicaciones as aplicacion')
            ->join('comprobantes as comprobante', 'comprobante.id', '=', 'aplicacion.comprobante_id')
            ->whereIn('aplicacion.pago_id', $paymentIds)
            ->orderBy('comprobante.fecha_emision')
            ->orderBy('comprobante.id')
            ->get([
                'aplicacion.pago_id',
                'aplicacion.comprobante_id',
                'aplicacion.lado',
                'aplicacion.importe_aplicado',
                'comprobante.codigo',
                'comprobante.fecha_emision',
                'comprobante.saldo_pendiente',
                'comprobante.estado',
            ])
            ->groupBy('pago_id');
    }

    /** @return array<string, mixed> */
    private function formatHeader(object $collection, string $timezone): array
    {
        $destination = [
            'id' => (int) $collection->cuenta_destino_id,
            'alias' => $collection->cuenta_alias,
            'tipo' => $collection->cuenta_tipo,
            'banco' => $collection->cuenta_banco,
            'numero_cuenta' => $collection->cuenta_numero,
            'cci' => $collection->cuenta_cci,
            'moneda' => $collection->moneda,
            'estado' => $collection->cuenta_estado,
            'entidad' => [
                'id' => (int) $collection->entidad_id,
                'tipo' => $collection->entidad_tipo,
                'razon_social' => $collection->entidad_nombre,
                'nombre_comercial' => $collection->entidad_nombre_comercial,
            ],
        ];
        $assignedAmount = FinancialMoney::normalize(
            (string) ($collection->importe_asignado ?? '0'),
        );
        $pendingAmount = FinancialMoney::normalize(
            (string) ($collection->pendiente_importe ?? '0'),
        );
        $appliedPayable = FinancialMoney::add(
            (string) ($collection->importe_aplicado_cxp_detalles ?? '0'),
            (string) ($collection->importe_aplicado_cxp_pendiente ?? '0'),
        );
        $providerCredit = $collection->proveedor_id === null
            ? '0.00'
            : FinancialMoney::subtract(
                FinancialMoney::normalize((string) $collection->importe_total),
                $appliedPayable,
            );
        if (FinancialMoney::compare($providerCredit, '0.00') < 0) {
            $providerCredit = '0.00';
        }
        $reconciliation = FinancialMoney::compare($pendingAmount, '0.00') > 0
            ? 'PENDIENTE'
            : 'COMPLETA';
        $pending = $collection->pendiente_id === null ? null : [
            'id' => (int) $collection->pendiente_id,
            'importe' => $pendingAmount,
            'pago' => [
                'id' => (int) $collection->pendiente_pago_id,
                'codigo' => $collection->pendiente_pago_codigo,
                'tipo' => $collection->pendiente_pago_tipo,
                'estado' => $collection->pendiente_pago_estado,
                'cliente' => null,
                'proveedor' => $collection->proveedor_id === null ? null : [
                    'id' => (int) $collection->proveedor_id,
                    'numero_documento' => $collection->proveedor_documento,
                    'nombre' => $collection->proveedor_nombre,
                ],
                'idempotency_key' => $collection->pendiente_pago_idempotency_key,
                'reversa' => $collection->pendiente_reversa_id === null ? null : [
                    'id' => (int) $collection->pendiente_reversa_id,
                    'codigo' => $collection->pendiente_reversa_codigo,
                ],
            ],
        ];

        return [
            'id' => (int) $collection->id,
            'codigo' => $collection->codigo,
            'fecha_hora' => $this->localDateTime($collection->fecha_hora, $timezone),
            'referencia' => $collection->referencia,
            'moneda' => $collection->moneda,
            'importe_total' => FinancialMoney::normalize((string) $collection->importe_total),
            'importe_asignado' => $assignedAmount,
            'importe_desglosado' => $assignedAmount,
            'importe_pendiente' => $pendingAmount,
            'conciliacion' => $reconciliation,
            'estado_conciliacion' => $reconciliation,
            'pendiente' => $pending,
            'pendiente_identificar' => $pending,
            'importe_aplicado_cxp' => $appliedPayable,
            'saldo_favor_proveedor' => $providerCredit,
            'observaciones' => $collection->observaciones,
            'estado' => $collection->estado,
            'cobrador' => [
                'id' => (int) $collection->cobrador_id,
                'nombre' => $collection->cobrador_nombre_snapshot,
                'nombre_actual' => $collection->cobrador_nombre_actual,
                'estado' => $collection->cobrador_estado_actual,
            ],
            'destino' => $destination,
            'cuenta_destino' => $destination,
            'proveedor' => $collection->proveedor_id === null ? null : [
                'id' => (int) $collection->proveedor_id,
                'numero_documento' => $collection->proveedor_documento,
                'nombre' => $collection->proveedor_nombre,
            ],
            'detalle_count' => (int) $collection->detalles_count,
            'detalles_count' => (int) $collection->detalles_count,
            'creado_por' => [
                'id' => (int) $collection->created_by,
                'nombre' => $collection->creador_nombre,
            ],
            'created_at' => $this->localDateTime($collection->created_at, $timezone),
            'anulada_at' => $collection->anulada_at === null
                ? null
                : $this->localDateTime($collection->anulada_at, $timezone),
            'motivo_anulacion' => $collection->motivo_anulacion,
            'anulacion' => $collection->anulada_at === null ? null : [
                'fecha_hora' => $this->localDateTime($collection->anulada_at, $timezone),
                'motivo' => $collection->motivo_anulacion,
                'usuario' => $collection->anulada_por === null ? null : [
                    'id' => (int) $collection->anulada_por,
                    'nombre' => $collection->anulador_nombre,
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function destinationAccounts(int $companyId): array
    {
        return DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->leftJoin('terceros as proveedor', 'proveedor.id', '=', 'entidad.proveedor_id')
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.estado', 'ACTIVO')
            ->where('cuenta.estado', 'ACTIVO')
            ->where(function (Builder $query): void {
                $query->where('entidad.tipo', 'PROPIA')
                    ->orWhere(function (Builder $external): void {
                        $external->where('entidad.tipo', 'EXTERNA')
                            ->whereNotNull('entidad.proveedor_id')
                            ->where('proveedor.estado', 'ACTIVO')
                            ->whereExists(fn (Builder $role) => $role
                                ->selectRaw('1')
                                ->from('tercero_roles as rol_proveedor')
                                ->whereColumn('rol_proveedor.tercero_id', 'proveedor.id')
                                ->where('rol_proveedor.rol', 'PROVEEDOR'));
                    });
            })
            ->orderBy('entidad.tipo')
            ->orderBy('entidad.razon_social')
            ->orderBy('cuenta.alias')
            ->get([
                'cuenta.id',
                'cuenta.tipo',
                'cuenta.alias',
                'cuenta.banco',
                'cuenta.numero_cuenta',
                'cuenta.cci',
                'cuenta.moneda',
                'entidad.id as entidad_id',
                'entidad.tipo as entidad_tipo',
                'entidad.razon_social as entidad_nombre',
                'entidad.nombre_comercial as entidad_nombre_comercial',
                'entidad.proveedor_id',
                'proveedor.numero_documento as proveedor_documento',
                'proveedor.nombre_razon_social as proveedor_nombre',
            ])
            ->map(fn (object $account): array => [
                'id' => (int) $account->id,
                'tipo' => $account->tipo,
                'alias' => $account->alias,
                'banco' => $account->banco,
                'numero_cuenta' => $account->numero_cuenta,
                'cci' => $account->cci,
                'moneda' => $account->moneda,
                'entidad' => [
                    'id' => (int) $account->entidad_id,
                    'tipo' => $account->entidad_tipo,
                    'razon_social' => $account->entidad_nombre,
                    'nombre_comercial' => $account->entidad_nombre_comercial,
                ],
                'proveedor' => $account->proveedor_id === null ? null : [
                    'id' => (int) $account->proveedor_id,
                    'numero_documento' => $account->proveedor_documento,
                    'nombre' => $account->proveedor_nombre,
                ],
            ])->all();
    }

    private function localDateTime(string $value, string $timezone): string
    {
        return CarbonImmutable::parse($value, $this->databaseTimezone())
            ->setTimezone($timezone)
            ->toIso8601String();
    }

    /** @return array{string, string} */
    private function databaseDayRange(string $date, string $timezone): array
    {
        $localStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->startOfDay();

        return [
            $localStart->setTimezone($this->databaseTimezone())->format('Y-m-d H:i:s'),
            $localStart->addDay()->setTimezone($this->databaseTimezone())->format('Y-m-d H:i:s'),
        ];
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (
            DB::table('empresas')->where('id', $companyId)->value('zona_horaria')
            ?: config('app.timezone', 'UTC')
        );
    }

    private function companyCurrency(int $companyId): string
    {
        return (string) (
            DB::table('empresas')->where('id', $companyId)->value('moneda')
            ?: 'PEN'
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
}
