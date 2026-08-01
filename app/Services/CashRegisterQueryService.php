<?php

namespace App\Services;

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
            ->orderByDesc('movimiento.created_at')
            ->orderByDesc('movimiento.id')
            ->get();
        $formatted = $this->format($rows, (int) $cashRegister->id, $timezone);
        $accountIncome = $this->accountIncome(
            $companyId,
            $from,
            $to,
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
        return DB::table('movimientos_caja_efectivo as movimiento')
            ->join('pagos as pago', 'pago.id', '=', 'movimiento.pago_id')
            ->join('cuentas_financieras as caja_principal', 'caja_principal.id', '=', 'movimiento.caja_id')
            ->join('entidades_financieras as entidad_principal', 'entidad_principal.id', '=', 'caja_principal.entidad_financiera_id')
            ->leftJoin('cuentas_financieras as otra_caja', 'otra_caja.id', '=', 'movimiento.otra_caja_id')
            ->leftJoin('entidades_financieras as otra_entidad', 'otra_entidad.id', '=', 'otra_caja.entidad_financiera_id')
            ->leftJoin('terceros as cliente', 'cliente.id', '=', 'movimiento.cliente_id')
            ->leftJoin('usuarios as creador', 'creador.id', '=', 'movimiento.created_by')
            ->leftJoin('usuarios as anulador', 'anulador.id', '=', 'pago.anulada_por')
            ->where('movimiento.empresa_id', $companyId)
            ->when(! $includeVoided, fn (Builder $query) => $query
                ->where('movimiento.estado', MovimientoCajaEfectivo::STATUS_REGISTERED)
                ->where('pago.estado', Pago::STATUS_REGISTERED)
                ->whereNull('pago.reversa_de_pago_id'))
            ->where(function (Builder $query) use ($cashRegisterId): void {
                $query->where('movimiento.caja_id', $cashRegisterId)
                    ->orWhere('movimiento.otra_caja_id', $cashRegisterId);
            })
            ->select([
                'movimiento.*',
                'pago.codigo as movimiento_codigo',
                'pago.fecha_hora',
                'pago.moneda',
                'pago.importe',
                'pago.cuenta_origen_id',
                'pago.cuenta_destino_id',
                'pago.estado as pago_estado',
                'pago.anulada_at',
                'pago.motivo_anulacion',
                'pago.updated_at as pago_updated_at',
                'caja_principal.alias as caja_principal_alias',
                'entidad_principal.razon_social as entidad_principal_nombre',
                'otra_caja.alias as otra_caja_alias',
                'otra_entidad.razon_social as otra_entidad_nombre',
                'cliente.nombre_razon_social as cliente_nombre',
                'cliente.numero_documento as cliente_documento',
                'creador.nombre as creador_nombre',
                'anulador.nombre as anulador_nombre',
            ]);
    }

    /** @param Collection<int, object> $rows @return list<array<string, mixed>> */
    private function format(Collection $rows, int $cashRegisterId, string $timezone): array
    {
        return $rows->map(function (object $movement) use ($cashRegisterId, $timezone): array {
            $isPrimaryCashRegister = (int) $movement->caja_id === $cashRegisterId;
            $direction = $isPrimaryCashRegister
                ? $movement->direccion
                : $this->oppositeDirection($movement->direccion);
            $counterpart = match ($movement->contraparte_tipo) {
                MovimientoCajaEfectivo::COUNTERPART_CUSTOMER => [
                    'tipo' => MovimientoCajaEfectivo::COUNTERPART_CUSTOMER,
                    'id' => (int) $movement->cliente_id,
                    'nombre' => $movement->cliente_nombre,
                    'documento' => $movement->cliente_documento,
                ],
                MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER => [
                    'tipo' => MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER,
                    'id' => $isPrimaryCashRegister
                        ? (int) $movement->otra_caja_id
                        : (int) $movement->caja_id,
                    'nombre' => $isPrimaryCashRegister
                        ? $movement->otra_caja_alias
                        : $movement->caja_principal_alias,
                    'entidad' => $isPrimaryCashRegister
                        ? $movement->otra_entidad_nombre
                        : $movement->entidad_principal_nombre,
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
                    'tipo' => $movement->contraparte_tipo,
                    'id' => null,
                    'nombre' => $direction === MovimientoCajaEfectivo::DIRECTION_INCOME
                        ? 'Otro origen'
                        : 'Otro destino',
                ],
            };
            $cashRegister = [
                'id' => $cashRegisterId,
                'alias' => $isPrimaryCashRegister
                    ? $movement->caja_principal_alias
                    : $movement->otra_caja_alias,
                'entidad' => $isPrimaryCashRegister
                    ? $movement->entidad_principal_nombre
                    : $movement->otra_entidad_nombre,
            ];
            $otherCashRegister = $movement->contraparte_tipo === MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER
                ? [
                    'id' => (int) $counterpart['id'],
                    'alias' => $counterpart['nombre'],
                    'entidad' => $counterpart['entidad'],
                ]
                : null;
            $customer = $movement->contraparte_tipo === MovimientoCajaEfectivo::COUNTERPART_CUSTOMER
                ? [
                    'id' => (int) $movement->cliente_id,
                    'nombre' => $movement->cliente_nombre,
                    'numero_documento' => $movement->cliente_documento,
                ]
                : null;

            return [
                'id' => (int) $movement->id,
                'codigo' => $movement->codigo,
                'movimiento_codigo' => $movement->movimiento_codigo,
                'fecha_hora' => CarbonImmutable::parse(
                    (string) $movement->fecha_hora,
                    $this->databaseTimezone(),
                )->setTimezone($timezone)->toIso8601String(),
                'direccion' => $direction,
                'contraparte_tipo' => $movement->contraparte_tipo,
                'contraparte' => $counterpart,
                'cliente' => $customer,
                'caja' => $cashRegister,
                'otra_caja' => $otherCashRegister,
                'detalle' => $movement->detalle,
                'moneda' => $movement->moneda,
                'importe' => FinancialMoney::normalize((string) $movement->importe),
                'estado' => $movement->estado,
                'caja_contexto_id' => $cashRegisterId,
                'caja_principal_id' => (int) $movement->caja_id,
                'creado_por' => $movement->creador_nombre,
                'updated_at' => CarbonImmutable::parse(
                    (string) ($movement->pago_updated_at ?: $movement->updated_at),
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
                'puede_editar' => $movement->estado === MovimientoCajaEfectivo::STATUS_REGISTERED,
                'puede_anular' => $movement->estado === MovimientoCajaEfectivo::STATUS_REGISTERED,
            ];
        })->values()->all();
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

    private function oppositeDirection(string $direction): string
    {
        return $direction === MovimientoCajaEfectivo::DIRECTION_INCOME
            ? MovimientoCajaEfectivo::DIRECTION_EXPENSE
            : MovimientoCajaEfectivo::DIRECTION_INCOME;
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
