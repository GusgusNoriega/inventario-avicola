<?php

namespace App\Services;

use App\Models\Cobranza;
use App\Models\CuentaFinanciera;
use App\Models\MovimientoCajaEfectivo;
use App\Models\Pago;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterQueryService
{
    public function __construct(private readonly FinancialQueryService $finances) {}

    /** @return array<string, mixed> */
    public function catalog(int $companyId): array
    {
        $catalog = $this->finances->catalog($companyId);
        $cashRegisters = collect($catalog['entidades'])
            ->where('tipo', 'PROPIA')
            ->flatMap(function (array $entity): array {
                return collect($entity['cuentas'])
                    ->where('tipo', CuentaFinanciera::TYPE_CASH)
                    ->map(fn (array $account): array => [
                        ...$account,
                        'entidad' => [
                            'id' => (int) $entity['id'],
                            'razon_social' => $entity['razon_social'],
                            'nombre_comercial' => $entity['nombre_comercial'],
                        ],
                    ])->values()->all();
            })
            ->sortBy('alias', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'empresa_id' => $companyId,
            'timezone' => $this->companyTimezone($companyId),
            'cajas' => $cashRegisters,
            'clientes' => $catalog['clientes'],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function daily(int $companyId, array $filters): array
    {
        $cashRegister = $this->cashRegister($companyId, (int) $filters['caja_id']);
        $timezone = $this->companyTimezone($companyId);
        [$from, $to] = $this->databaseDayRange((string) $filters['fecha'], $timezone);
        $rows = $this->query($companyId, (int) $cashRegister->id)
            ->where('pago.fecha_hora', '>=', $from)
            ->where('pago.fecha_hora', '<', $to)
            ->orderBy('pago.created_at')
            ->orderBy('pago.id')
            ->get();
        $formatted = $this->format($rows, (int) $cashRegister->id, $timezone);
        $accountIncome = $this->accountIncome(
            $companyId,
            $from,
            $to,
        );
        $collectionsByCollector = $this->collectionsByCollector(
            $companyId,
            (int) $cashRegister->id,
            $to,
            $timezone,
        );
        $income = '0.00';
        $expense = '0.00';
        foreach ($formatted as $movement) {
            if ($movement['direccion'] === MovimientoCajaEfectivo::DIRECTION_INCOME) {
                $income = FinancialMoney::add($income, $movement['importe']);
            } else {
                $expense = FinancialMoney::add($expense, $movement['importe']);
            }
        }

        return [
            'data' => $formatted,
            'resumen' => [
                'ingresos' => $income,
                'ingresos_cuentas' => $accountIncome,
                'egresos' => $expense,
                'total' => FinancialMoney::subtract($income, $expense),
                'neto' => FinancialMoney::subtract($income, $expense),
                'cobranzas_por_cobrador' => $collectionsByCollector,
                'moneda' => $cashRegister->moneda,
                'fecha' => $filters['fecha'],
                'timezone' => $timezone,
            ],
            'meta' => [
                'total' => count($formatted),
                'actualizado_en' => CarbonImmutable::now($timezone)->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function movement(
        int $companyId,
        int $cashMovementId,
        int $cashRegisterId,
        bool $includeVoided = false,
    ): array {
        $this->cashRegister($companyId, $cashRegisterId);
        $row = $this->query($companyId, $cashRegisterId, $includeVoided)
            ->where('movimiento.id', $cashMovementId)
            ->first();
        abort_unless($row, 404, 'Movimiento de caja no encontrado para la caja seleccionada.');

        return $this->format(
            collect([$row]),
            $cashRegisterId,
            $this->companyTimezone($companyId),
        )[0];
    }

    /** @return list<array<string, mixed>> */
    private function collectionsByCollector(
        int $companyId,
        int $cashRegisterId,
        string $to,
        string $timezone,
    ): array {
        return DB::table('cobranzas as cobranza')
            ->where('cobranza.empresa_id', $companyId)
            ->where('cobranza.cuenta_destino_id', $cashRegisterId)
            ->where('cobranza.estado', Cobranza::STATUS_REGISTERED)
            ->where('cobranza.fecha_hora', '<', $to)
            ->where(function (Builder $query): void {
                $query->where('cobranza.recibido_en_caja', false)
                    ->orWhereNull('cobranza.recibido_en_caja');
            })
            ->groupBy(
                'cobranza.cobrador_id',
                'cobranza.moneda',
            )
            ->orderBy('cobrador_nombre')
            ->get([
                'cobranza.cobrador_id',
                DB::raw('MAX(cobranza.cobrador_nombre_snapshot) as cobrador_nombre'),
                'cobranza.moneda',
                DB::raw('COUNT(*) as cobranzas_count'),
                DB::raw('COALESCE(SUM(cobranza.importe_total), 0) as importe_total'),
                DB::raw('COALESCE(SUM(CASE WHEN cobranza.recibido_en_caja = 0 THEN cobranza.importe_total ELSE 0 END), 0) as importe_pendiente'),
                DB::raw('COALESCE(SUM(CASE WHEN cobranza.recibido_en_caja IS NULL THEN cobranza.importe_total ELSE 0 END), 0) as importe_sin_confirmar'),
                DB::raw('SUM(CASE WHEN cobranza.recibido_en_caja = 0 THEN 1 ELSE 0 END) as pendientes_count'),
                DB::raw('SUM(CASE WHEN cobranza.recibido_en_caja IS NULL THEN 1 ELSE 0 END) as sin_confirmar_count'),
                DB::raw('MIN(cobranza.fecha_hora) as fecha_pendiente_mas_antigua'),
            ])
            ->map(fn (object $row): array => [
                'cobrador' => [
                    'id' => (int) $row->cobrador_id,
                    'nombre' => $row->cobrador_nombre,
                ],
                'moneda' => $row->moneda,
                'cobranzas_count' => (int) $row->cobranzas_count,
                'pendientes_count' => (int) $row->pendientes_count,
                'sin_confirmar_count' => (int) $row->sin_confirmar_count,
                'importe_total' => FinancialMoney::normalize((string) $row->importe_total),
                'importe_adeudado' => FinancialMoney::normalize((string) $row->importe_total),
                'importe_pendiente' => FinancialMoney::normalize((string) $row->importe_pendiente),
                'importe_sin_confirmar' => FinancialMoney::normalize((string) $row->importe_sin_confirmar),
                'fecha_pendiente_mas_antigua' => CarbonImmutable::parse(
                    (string) $row->fecha_pendiente_mas_antigua,
                    $this->databaseTimezone(),
                )->setTimezone($timezone)->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{moneda: string, importe: string}> */
    private function accountIncome(int $companyId, string $from, string $to): array
    {
        return DB::table('pagos as pago')
            ->join('cuentas_financieras as cuenta_destino', 'cuenta_destino.id', '=', 'pago.cuenta_destino_id')
            ->join('entidades_financieras as entidad_destino', 'entidad_destino.id', '=', 'cuenta_destino.entidad_financiera_id')
            ->where('pago.empresa_id', $companyId)
            ->where('entidad_destino.empresa_id', $companyId)
            ->where('entidad_destino.tipo', 'PROPIA')
            ->whereIn('cuenta_destino.tipo', [
                CuentaFinanciera::TYPE_BANK,
                CuentaFinanciera::TYPE_WALLET,
            ])
            ->whereNotIn('pago.tipo', [
                Pago::TYPE_OPENING_BALANCE,
                Pago::TYPE_INTERNAL_TRANSFER,
            ])
            ->where('pago.estado', Pago::STATUS_REGISTERED)
            ->whereNull('pago.reversa_de_pago_id')
            ->where('pago.fecha_hora', '>=', $from)
            ->where('pago.fecha_hora', '<', $to)
            ->selectRaw('pago.moneda, COALESCE(SUM(pago.importe), 0) as importe')
            ->groupBy('pago.moneda')
            ->orderBy('pago.moneda')
            ->get()
            ->map(fn (object $row): array => [
                'moneda' => (string) $row->moneda,
                'importe' => FinancialMoney::normalize((string) $row->importe),
            ])
            ->values()
            ->all();
    }

    private function query(
        int $companyId,
        int $cashRegisterId,
        bool $includeVoided = false,
    ): Builder {
        return DB::table('pagos as pago')
            ->leftJoin('movimientos_caja_efectivo as movimiento', function ($join) use ($companyId): void {
                $join->on('movimiento.pago_id', '=', 'pago.id')
                    ->where('movimiento.empresa_id', $companyId);
            })
            ->leftJoin('cuentas_financieras as cuenta_origen', 'cuenta_origen.id', '=', 'pago.cuenta_origen_id')
            ->leftJoin('entidades_financieras as entidad_origen', function ($join) use ($companyId): void {
                $join->on('entidad_origen.id', '=', 'cuenta_origen.entidad_financiera_id')
                    ->where('entidad_origen.empresa_id', $companyId);
            })
            ->leftJoin('cuentas_financieras as cuenta_destino', 'cuenta_destino.id', '=', 'pago.cuenta_destino_id')
            ->leftJoin('entidades_financieras as entidad_destino', function ($join) use ($companyId): void {
                $join->on('entidad_destino.id', '=', 'cuenta_destino.entidad_financiera_id')
                    ->where('entidad_destino.empresa_id', $companyId);
            })
            ->leftJoin('terceros as cliente', function ($join) use ($companyId): void {
                $join->on('cliente.id', '=', 'pago.cliente_id')
                    ->where('cliente.empresa_id', $companyId);
            })
            ->leftJoin('terceros as proveedor', function ($join) use ($companyId): void {
                $join->on('proveedor.id', '=', 'pago.proveedor_id')
                    ->where('proveedor.empresa_id', $companyId);
            })
            ->leftJoin('metodos_pago as metodo_pago', 'metodo_pago.id', '=', 'pago.metodo_pago_id')
            ->leftJoin('gastos_empresa as gasto', function ($join) use ($companyId): void {
                $join->on('gasto.pago_id', '=', 'pago.id')
                    ->where('gasto.empresa_id', $companyId);
            })
            ->leftJoin('compras as compra', function ($join) use ($companyId): void {
                $join->on('compra.pago_inicial_id', '=', 'pago.id')
                    ->where('compra.empresa_id', $companyId);
            })
            ->leftJoinSub(
                $this->collectionPaymentLinks($companyId),
                'enlace_cobranza',
                'enlace_cobranza.pago_id',
                '=',
                'pago.id',
            )
            ->leftJoin('cobranzas as cobranza', function ($join) use ($companyId): void {
                $join->on('cobranza.id', '=', 'enlace_cobranza.cobranza_id')
                    ->where('cobranza.empresa_id', $companyId);
            })
            ->leftJoin('usuarios as receptor_caja', function ($join) use ($companyId): void {
                $join->on('receptor_caja.id', '=', 'cobranza.recepcion_caja_actualizada_por')
                    ->where('receptor_caja.empresa_id', $companyId);
            })
            ->leftJoin('usuarios as pago_creador', function ($join) use ($companyId): void {
                $join->on('pago_creador.id', '=', 'pago.created_by')
                    ->where('pago_creador.empresa_id', $companyId);
            })
            ->leftJoin('usuarios as movimiento_creador', function ($join) use ($companyId): void {
                $join->on('movimiento_creador.id', '=', 'movimiento.created_by')
                    ->where('movimiento_creador.empresa_id', $companyId);
            })
            ->leftJoin('usuarios as anulador', function ($join) use ($companyId): void {
                $join->on('anulador.id', '=', 'pago.anulada_por')
                    ->where('anulador.empresa_id', $companyId);
            })
            ->where('pago.empresa_id', $companyId)
            ->when(! $includeVoided, fn (Builder $query) => $query
                ->where('pago.estado', Pago::STATUS_REGISTERED)
                ->whereNull('pago.reversa_de_pago_id'))
            ->where(function (Builder $query) use ($cashRegisterId): void {
                $query->where('pago.cuenta_origen_id', $cashRegisterId)
                    ->orWhere('pago.cuenta_destino_id', $cashRegisterId);
            })
            ->select([
                'pago.id as pago_id',
                'pago.codigo as movimiento_codigo',
                'pago.tipo as pago_tipo',
                'pago.fecha_hora',
                'pago.moneda',
                'pago.importe',
                'pago.metodo as metodo_snapshot',
                'pago.metodo_pago_id',
                'metodo_pago.codigo as metodo_codigo',
                'metodo_pago.nombre as metodo_nombre',
                'pago.referencia',
                'pago.observaciones',
                'pago.cliente_id as pago_cliente_id',
                'pago.proveedor_id as pago_proveedor_id',
                'pago.cuenta_origen_id',
                'pago.cuenta_destino_id',
                'pago.estado as pago_estado',
                'pago.reversa_de_pago_id',
                'pago.anulada_at',
                'pago.motivo_anulacion',
                'pago.created_at as pago_created_at',
                'pago.updated_at as pago_updated_at',
                'movimiento.id as movimiento_caja_id',
                'movimiento.codigo as movimiento_caja_codigo',
                'movimiento.caja_id as movimiento_caja_principal_id',
                'movimiento.contraparte_tipo as movimiento_contraparte_tipo',
                'movimiento.detalle as movimiento_detalle',
                'movimiento.estado as movimiento_caja_estado',
                'cuenta_origen.alias as cuenta_origen_alias',
                'cuenta_origen.tipo as cuenta_origen_tipo',
                'entidad_origen.id as entidad_origen_id',
                'entidad_origen.tipo as entidad_origen_tipo',
                'entidad_origen.razon_social as entidad_origen_nombre',
                'entidad_origen.nombre_comercial as entidad_origen_nombre_comercial',
                'cuenta_destino.alias as cuenta_destino_alias',
                'cuenta_destino.tipo as cuenta_destino_tipo',
                'entidad_destino.id as entidad_destino_id',
                'entidad_destino.tipo as entidad_destino_tipo',
                'entidad_destino.razon_social as entidad_destino_nombre',
                'entidad_destino.nombre_comercial as entidad_destino_nombre_comercial',
                'cliente.nombre_razon_social as cliente_nombre',
                'cliente.numero_documento as cliente_documento',
                'proveedor.nombre_razon_social as proveedor_nombre',
                'proveedor.numero_documento as proveedor_documento',
                'gasto.id as gasto_id',
                'gasto.codigo as gasto_codigo',
                'gasto.categoria as gasto_categoria',
                'gasto.concepto as gasto_concepto',
                'gasto.destino as gasto_destino',
                'gasto.numero_documento as gasto_numero_documento',
                'compra.id as compra_id',
                'compra.codigo as compra_codigo',
                'compra.tipo_documento as compra_tipo_documento',
                'compra.numero_documento as compra_numero_documento',
                'compra.fecha_compra as compra_fecha',
                'cobranza.id as cobranza_id',
                'cobranza.codigo as cobranza_codigo',
                'cobranza.estado as cobranza_estado',
                'cobranza.referencia as cobranza_referencia',
                'cobranza.cobrador_id as cobranza_cobrador_id',
                'cobranza.cobrador_nombre_snapshot as cobranza_cobrador_nombre',
                'cobranza.recibido_en_caja as cobranza_recibido_en_caja',
                'cobranza.recepcion_caja_actualizada_at as cobranza_recepcion_caja_actualizada_at',
                'cobranza.recepcion_caja_actualizada_por as cobranza_recepcion_caja_actualizada_por',
                'cobranza.recepcion_caja_actualizada_por_nombre as cobranza_recepcion_caja_actualizada_por_nombre',
                'receptor_caja.nombre as cobranza_receptor_caja_nombre',
                'enlace_cobranza.fecha_recepcion as cobranza_fecha_recepcion',
                'enlace_cobranza.asignacion_id as cobranza_asignacion_id',
                'enlace_cobranza.rol_pago as cobranza_rol_pago',
                'pago_creador.nombre as pago_creador_nombre',
                'movimiento_creador.nombre as movimiento_creador_nombre',
                'anulador.nombre as anulador_nombre',
            ])
            ->selectSub(
                DB::table('pago_aplicaciones as aplicacion')
                    ->whereColumn('aplicacion.pago_id', 'pago.id')
                    ->selectRaw('COUNT(*)'),
                'aplicaciones_count',
            );
    }

    private function collectionPaymentLinks(int $companyId): Builder
    {
        $details = DB::table('cobranza_detalles as detalle')
            ->join('cobranzas as cobranza_detalle_empresa', function ($join) use ($companyId): void {
                $join->on('cobranza_detalle_empresa.id', '=', 'detalle.cobranza_id')
                    ->where('cobranza_detalle_empresa.empresa_id', $companyId);
            })
            ->select([
                'detalle.pago_id',
                'detalle.cobranza_id',
                'detalle.fecha_recepcion',
                'detalle.asignacion_id',
            ])
            ->selectRaw(
                "CASE WHEN detalle.asignacion_id IS NULL THEN 'DETALLE_INICIAL' ELSE 'DETALLE_REASIGNADO' END as rol_pago"
            );
        $pending = DB::table('cobranza_pendientes as pendiente')
            ->join('cobranzas as cobranza_pendiente_empresa', function ($join) use ($companyId): void {
                $join->on('cobranza_pendiente_empresa.id', '=', 'pendiente.cobranza_id')
                    ->where('cobranza_pendiente_empresa.empresa_id', $companyId);
            })
            ->leftJoin('cobranza_asignaciones as asignacion', function ($join) use ($companyId): void {
                $join->on('asignacion.pago_pendiente_nuevo_id', '=', 'pendiente.pago_id')
                    ->on('asignacion.cobranza_id', '=', 'pendiente.cobranza_id')
                    ->where('asignacion.empresa_id', $companyId);
            })
            ->select([
                'pendiente.pago_id',
                'pendiente.cobranza_id',
            ])
            ->selectRaw('NULL as fecha_recepcion')
            ->addSelect('asignacion.id as asignacion_id')
            ->selectRaw(
                "CASE WHEN asignacion.id IS NULL THEN 'PENDIENTE_INICIAL' ELSE 'PENDIENTE_REASIGNADO' END as rol_pago"
            );

        return $details->unionAll($pending);
    }

    /** @param Collection<int, object> $rows @return list<array<string, mixed>> */
    private function format(Collection $rows, int $cashRegisterId, string $timezone): array
    {
        return $rows->map(function (object $movement) use ($cashRegisterId, $timezone): array {
            $isIncome = (int) ($movement->cuenta_destino_id ?? 0) === $cashRegisterId;
            $direction = $isIncome
                ? MovimientoCajaEfectivo::DIRECTION_INCOME
                : MovimientoCajaEfectivo::DIRECTION_EXPENSE;
            $cashRegister = $this->paymentAccount(
                $movement,
                $isIncome ? 'destino' : 'origen',
            );
            $counterpartAccount = $this->paymentAccount(
                $movement,
                $isIncome ? 'origen' : 'destino',
            );
            $counterpart = $this->counterpart($movement, $direction, $counterpartAccount);
            $otherCashRegister = $counterpart['tipo'] === MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER
                ? [
                    'id' => (int) $counterpart['id'],
                    'alias' => $counterpart['nombre'],
                    'entidad' => $counterpart['entidad'] ?? null,
                ]
                : null;
            $customer = $movement->pago_cliente_id === null
                ? null
                : [
                    'id' => (int) $movement->pago_cliente_id,
                    'nombre' => $movement->cliente_nombre,
                    'numero_documento' => $movement->cliente_documento,
                ];
            $provider = $movement->pago_proveedor_id === null
                ? null
                : [
                    'id' => (int) $movement->pago_proveedor_id,
                    'nombre' => $movement->proveedor_nombre,
                    'numero_documento' => $movement->proveedor_documento,
                ];
            $collectionCashReceipt = $movement->cobranza_id === null
                || $movement->cobranza_recibido_en_caja === null
                    ? null
                    : (bool) $movement->cobranza_recibido_en_caja;
            $collection = $movement->cobranza_id === null
                ? null
                : [
                    'id' => (int) $movement->cobranza_id,
                    'codigo' => $movement->cobranza_codigo,
                    'estado' => $movement->cobranza_estado,
                    'referencia' => $movement->cobranza_referencia,
                    'fecha_recepcion' => $movement->cobranza_fecha_recepcion,
                    'rol_pago' => $movement->cobranza_rol_pago,
                    'recibido_en_caja' => $collectionCashReceipt,
                    'recepcion_caja' => [
                        'estado' => $collectionCashReceipt === true
                            ? 'RECIBIDO'
                            : ($collectionCashReceipt === false ? 'PENDIENTE' : 'SIN_CONFIRMAR'),
                        'recibido' => $collectionCashReceipt,
                        'fecha_hora' => $movement->cobranza_recepcion_caja_actualizada_at === null
                            ? null
                            : CarbonImmutable::parse(
                                (string) $movement->cobranza_recepcion_caja_actualizada_at,
                                $this->databaseTimezone(),
                            )->setTimezone($timezone)->toIso8601String(),
                        'usuario' => $movement->cobranza_recepcion_caja_actualizada_por === null
                            && $movement->cobranza_recepcion_caja_actualizada_por_nombre === null
                            ? null
                            : [
                                'id' => $movement->cobranza_recepcion_caja_actualizada_por === null
                                    ? null
                                    : (int) $movement->cobranza_recepcion_caja_actualizada_por,
                                'nombre' => $movement->cobranza_recepcion_caja_actualizada_por_nombre
                                    ?: $movement->cobranza_receptor_caja_nombre,
                            ],
                        'puede_actualizar' => $movement->cobranza_estado === Cobranza::STATUS_REGISTERED,
                    ],
                    'asignacion' => $movement->cobranza_asignacion_id === null
                        ? null
                        : [
                            'id' => (int) $movement->cobranza_asignacion_id,
                            'rol_pago' => $movement->cobranza_rol_pago,
                        ],
                    'cobrador' => [
                        'id' => (int) $movement->cobranza_cobrador_id,
                        'nombre' => $movement->cobranza_cobrador_nombre,
                    ],
                ];
            $expenseOperation = $movement->gasto_id === null
                ? null
                : [
                    'id' => (int) $movement->gasto_id,
                    'codigo' => $movement->gasto_codigo,
                    'categoria' => $movement->gasto_categoria,
                    'concepto' => $movement->gasto_concepto,
                    'destino' => $movement->gasto_destino,
                    'numero_documento' => $movement->gasto_numero_documento,
                ];
            $purchase = $movement->compra_id === null
                ? null
                : [
                    'id' => (int) $movement->compra_id,
                    'codigo' => $movement->compra_codigo,
                    'tipo_documento' => $movement->compra_tipo_documento,
                    'numero_documento' => $movement->compra_numero_documento,
                    'fecha' => $movement->compra_fecha,
                ];
            $cashMovementId = $movement->movimiento_caja_id === null
                ? null
                : (int) $movement->movimiento_caja_id;
            $origin = $this->movementOrigin(
                $movement,
                $collection,
                $expenseOperation,
                $purchase,
                $cashMovementId,
            );
            $canManage = $cashMovementId !== null
                && $movement->movimiento_caja_estado === MovimientoCajaEfectivo::STATUS_REGISTERED
                && $movement->pago_estado === Pago::STATUS_REGISTERED
                && $movement->reversa_de_pago_id === null;

            return [
                'id' => $cashMovementId,
                'row_key' => $cashMovementId === null
                    ? 'pago:'.(int) $movement->pago_id
                    : 'caja:'.$cashMovementId,
                'pago_id' => (int) $movement->pago_id,
                'movimiento_caja_id' => $cashMovementId,
                'codigo' => $movement->movimiento_caja_codigo,
                'movimiento_codigo' => $movement->movimiento_codigo,
                'tipo' => $movement->pago_tipo,
                'fecha_hora' => CarbonImmutable::parse(
                    (string) $movement->fecha_hora,
                    $this->databaseTimezone(),
                )->setTimezone($timezone)->toIso8601String(),
                'direccion' => $direction,
                'contraparte_tipo' => $counterpart['tipo'],
                'contraparte' => $counterpart,
                'cliente' => $customer,
                'proveedor' => $provider,
                'caja' => $cashRegister,
                'otra_caja' => $otherCashRegister,
                'cuenta_origen' => $this->paymentAccount($movement, 'origen'),
                'cuenta_destino' => $this->paymentAccount($movement, 'destino'),
                'detalle' => $this->movementDetail($movement),
                'referencia' => $movement->referencia,
                'metodo_pago' => [
                    'id' => $movement->metodo_pago_id === null
                        ? null
                        : (int) $movement->metodo_pago_id,
                    'codigo' => $movement->metodo_codigo ?: $movement->metodo_snapshot,
                    'nombre' => $movement->metodo_nombre ?: $movement->metodo_snapshot,
                ],
                'moneda' => $movement->moneda,
                'importe' => FinancialMoney::normalize((string) $movement->importe),
                'estado' => $movement->movimiento_caja_estado ?: $movement->pago_estado,
                'caja_contexto_id' => $cashRegisterId,
                'caja_principal_id' => $movement->movimiento_caja_principal_id === null
                    ? $cashRegisterId
                    : (int) $movement->movimiento_caja_principal_id,
                'creado_por' => $movement->movimiento_creador_nombre
                    ?: $movement->pago_creador_nombre,
                'origen' => $origin,
                'cobranza' => $collection,
                'gasto_empresa' => $expenseOperation,
                'compra' => $purchase,
                'trazabilidad' => [
                    'origen' => $origin,
                    'pago' => [
                        'id' => (int) $movement->pago_id,
                        'codigo' => $movement->movimiento_codigo,
                        'tipo' => $movement->pago_tipo,
                        'referencia' => $movement->referencia,
                        'aplicaciones_count' => (int) $movement->aplicaciones_count,
                    ],
                    'movimiento_caja' => $cashMovementId === null ? null : [
                        'id' => $cashMovementId,
                        'codigo' => $movement->movimiento_caja_codigo,
                    ],
                    'cobranza' => $collection,
                    'gasto_empresa' => $expenseOperation,
                    'compra' => $purchase,
                ],
                'updated_at' => CarbonImmutable::parse(
                    (string) ($movement->pago_updated_at ?: $movement->pago_created_at),
                    $this->databaseTimezone(),
                )->setTimezone($timezone)->toIso8601String(),
                'anulada_por' => $movement->anulador_nombre,
                'anulada_at' => $movement->anulada_at === null
                    ? null
                    : CarbonImmutable::parse(
                        (string) $movement->anulada_at,
                        $this->databaseTimezone(),
                    )->setTimezone($timezone)->toIso8601String(),
                'motivo_anulacion' => $movement->motivo_anulacion,
                'puede_editar' => $canManage,
                'puede_anular' => $canManage,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $collection
     * @param  array<string, mixed>|null  $expense
     * @param  array<string, mixed>|null  $purchase
     * @return array<string, mixed>
     */
    private function movementOrigin(
        object $payment,
        ?array $collection,
        ?array $expense,
        ?array $purchase,
        ?int $cashMovementId,
    ): array {
        if ($collection !== null) {
            return [
                'tipo' => 'COBRANZA',
                'id' => $collection['id'],
                'codigo' => $collection['codigo'],
                'referencia' => $collection['referencia'],
                'asignacion_id' => $collection['asignacion']['id'] ?? null,
                'rol_pago' => $collection['rol_pago'],
                'url' => '/finanzas/cobranzas',
            ];
        }
        if ($cashMovementId !== null) {
            return [
                'tipo' => 'CAJA_EFECTIVO',
                'id' => $cashMovementId,
                'codigo' => $payment->movimiento_caja_codigo,
                'referencia' => $payment->referencia,
                'url' => null,
            ];
        }
        if ($expense !== null) {
            return [
                'tipo' => 'GASTO_EMPRESA',
                'id' => $expense['id'],
                'codigo' => $expense['codigo'],
                'referencia' => $expense['numero_documento'],
                'url' => '/finanzas/gastos',
            ];
        }
        if ($purchase !== null) {
            return [
                'tipo' => 'COMPRA',
                'id' => $purchase['id'],
                'codigo' => $purchase['codigo'],
                'referencia' => $purchase['numero_documento'],
                'url' => '/compras?compra='.$purchase['id'],
            ];
        }

        return [
            'tipo' => 'MOVIMIENTO_FINANCIERO',
            'id' => (int) $payment->pago_id,
            'codigo' => $payment->movimiento_codigo,
            'referencia' => $payment->referencia,
            'url' => '/finanzas/movimientos',
        ];
    }

    /** @return array<string, mixed>|null */
    private function paymentAccount(object $payment, string $side): ?array
    {
        $accountId = $payment->{"cuenta_{$side}_id"};
        $entityId = $payment->{"entidad_{$side}_id"};
        if ($accountId === null || $entityId === null) {
            return null;
        }

        return [
            'id' => (int) $accountId,
            'alias' => $payment->{"cuenta_{$side}_alias"},
            'tipo' => $payment->{"cuenta_{$side}_tipo"},
            'entidad' => [
                'id' => (int) $entityId,
                'tipo' => $payment->{"entidad_{$side}_tipo"},
                'razon_social' => $payment->{"entidad_{$side}_nombre"},
                'nombre_comercial' => $payment->{"entidad_{$side}_nombre_comercial"},
            ],
        ];
    }

    /** @param array<string, mixed>|null $account @return array<string, mixed> */
    private function counterpart(object $payment, string $direction, ?array $account): array
    {
        if ($payment->movimiento_caja_id !== null) {
            return match ($payment->movimiento_contraparte_tipo) {
                MovimientoCajaEfectivo::COUNTERPART_CUSTOMER => [
                    'tipo' => MovimientoCajaEfectivo::COUNTERPART_CUSTOMER,
                    'id' => (int) $payment->pago_cliente_id,
                    'nombre' => $payment->cliente_nombre,
                    'documento' => $payment->cliente_documento,
                ],
                MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER => [
                    'tipo' => MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER,
                    'id' => $account['id'] ?? null,
                    'nombre' => $account['alias'] ?? 'Otra caja',
                    'entidad' => $this->accountEntityName($account),
                ],
                MovimientoCajaEfectivo::COUNTERPART_ADMINISTRATIVE => [
                    'tipo' => MovimientoCajaEfectivo::COUNTERPART_ADMINISTRATIVE,
                    'id' => null,
                    'nombre' => 'Administrativo',
                ],
                MovimientoCajaEfectivo::COUNTERPART_TRANSPORT => [
                    'tipo' => MovimientoCajaEfectivo::COUNTERPART_TRANSPORT,
                    'id' => null,
                    'nombre' => 'Transporte',
                ],
                MovimientoCajaEfectivo::COUNTERPART_DEPOSIT => [
                    'tipo' => MovimientoCajaEfectivo::COUNTERPART_DEPOSIT,
                    'id' => null,
                    'nombre' => 'Depósito',
                ],
                default => [
                    'tipo' => $payment->movimiento_contraparte_tipo,
                    'id' => null,
                    'nombre' => $direction === MovimientoCajaEfectivo::DIRECTION_INCOME
                        ? 'Otro origen'
                        : 'Otro destino',
                ],
            };
        }

        if ($account !== null) {
            $isOwnCashRegister = $account['tipo'] === CuentaFinanciera::TYPE_CASH
                && $account['entidad']['tipo'] === 'PROPIA';

            return [
                'tipo' => $isOwnCashRegister
                    ? MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER
                    : 'CUENTA',
                'id' => $account['id'],
                'nombre' => $account['alias'],
                'entidad' => $this->accountEntityName($account),
                'tipo_cuenta' => $account['tipo'],
            ];
        }

        if ($payment->pago_cliente_id !== null) {
            return [
                'tipo' => MovimientoCajaEfectivo::COUNTERPART_CUSTOMER,
                'id' => (int) $payment->pago_cliente_id,
                'nombre' => $payment->cliente_nombre,
                'documento' => $payment->cliente_documento,
            ];
        }
        if ($payment->pago_proveedor_id !== null) {
            return [
                'tipo' => 'PROVEEDOR',
                'id' => (int) $payment->pago_proveedor_id,
                'nombre' => $payment->proveedor_nombre,
                'documento' => $payment->proveedor_documento,
            ];
        }
        if ($payment->cobranza_id !== null) {
            return [
                'tipo' => 'COBRANZA',
                'id' => (int) $payment->cobranza_id,
                'nombre' => $payment->cobranza_codigo ?: 'Cobranza',
                'documento' => $payment->cobranza_referencia,
            ];
        }
        if ($payment->gasto_id !== null) {
            return [
                'tipo' => 'GASTO_EMPRESA',
                'id' => (int) $payment->gasto_id,
                'nombre' => $payment->gasto_destino,
                'documento' => $payment->gasto_numero_documento,
            ];
        }

        return [
            'tipo' => MovimientoCajaEfectivo::COUNTERPART_OTHER,
            'id' => null,
            'nombre' => $direction === MovimientoCajaEfectivo::DIRECTION_INCOME
                ? 'Otro origen'
                : 'Otro destino',
        ];
    }

    private function movementDetail(object $payment): string
    {
        if ($payment->movimiento_detalle !== null) {
            return (string) $payment->movimiento_detalle;
        }
        if ($payment->cobranza_id !== null) {
            return 'Cobranza '.($payment->cobranza_codigo ?: '#'.$payment->cobranza_id);
        }
        if ($payment->gasto_id !== null) {
            return (string) $payment->gasto_concepto;
        }
        if ($payment->compra_id !== null) {
            return 'Compra '.($payment->compra_codigo ?: '#'.$payment->compra_id);
        }
        if (trim((string) ($payment->observaciones ?? '')) !== '') {
            return (string) $payment->observaciones;
        }

        return match ($payment->pago_tipo) {
            Pago::TYPE_CUSTOMER_COLLECTION => 'Cobro de cliente',
            Pago::TYPE_DIRECT_PAYMENT => 'Pago directo',
            Pago::TYPE_UNASSIGNED_DEPOSIT => 'Depósito pendiente de identificar',
            Pago::TYPE_PROVIDER_PAYMENT => 'Pago a proveedor',
            Pago::TYPE_RETAIL_COLLECTION => 'Cobro minorista',
            Pago::TYPE_CUSTOMER_REFUND => 'Reembolso a cliente',
            Pago::TYPE_COMPANY_EXPENSE => 'Gasto de empresa',
            Pago::TYPE_OPENING_BALANCE => 'Saldo inicial de caja',
            Pago::TYPE_INTERNAL_TRANSFER => 'Transferencia entre cuentas',
            default => 'Movimiento financiero de caja',
        };
    }

    /** @param array<string, mixed>|null $account */
    private function accountEntityName(?array $account): ?string
    {
        return $account['entidad']['nombre_comercial']
            ?? $account['entidad']['razon_social']
            ?? null;
    }

    private function cashRegister(int $companyId, int $cashRegisterId): object
    {
        $cashRegister = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('cuenta.id', $cashRegisterId)
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.tipo', 'PROPIA')
            ->where('entidad.estado', 'ACTIVO')
            ->where('cuenta.tipo', CuentaFinanciera::TYPE_CASH)
            ->where('cuenta.estado', CuentaFinanciera::STATUS_ACTIVE)
            ->first(['cuenta.id', 'cuenta.alias', 'cuenta.moneda']);

        if (! $cashRegister) {
            throw ValidationException::withMessages([
                'caja_id' => 'Selecciona una caja propia y activa de esta empresa.',
            ]);
        }

        return $cashRegister;
    }

    /** @return array{string, string} */
    private function databaseDayRange(string $date, string $timezone): array
    {
        $localStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)
            ->startOfDay();

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

    private function databaseTimezone(): string
    {
        $connection = DB::connection()->getName();

        return (string) (
            config("database.connections.{$connection}.timezone")
            ?: config('app.timezone', 'UTC')
        );
    }
}
