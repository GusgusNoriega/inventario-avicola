<?php

namespace App\Services;

use App\Models\CuentaFinanciera;
use App\Models\MovimientoCajaEfectivo;
use App\Models\Pago;
use App\Models\User;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashRegisterMovementService
{
    public function __construct(
        private readonly FinancialMovementService $movements,
        private readonly FinancialAuditService $audit,
    ) {}

    /** @param array<string, mixed> $data @return array{movimiento_caja_id: int, idempotent: bool} */
    public function register(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertCanRegister($companyId, $actor);
        $this->assertSupportedCounterpart($data);

        return DB::transaction(function () use ($companyId, $actor, $data, $ip): array {
            $context = $this->context($companyId, $data);
            $payload = $this->movementPayload($companyId, $data, $context);
            $existing = DB::table('movimientos_caja_efectivo')
                ->where('empresa_id', $companyId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertSameRequest($existing, $data, $payload);

                return [
                    'movimiento_caja_id' => (int) $existing->id,
                    'idempotent' => true,
                ];
            }

            $movement = $this->movements->register(
                $companyId,
                $actor,
                $payload,
                $ip,
            );
            $now = now();
            $cashMovementId = DB::table('movimientos_caja_efectivo')->insertGetId([
                'empresa_id' => $companyId,
                'pago_id' => $movement['pago_id'],
                'codigo' => null,
                'idempotency_key' => $data['idempotency_key'],
                'caja_id' => (int) $data['caja_id'],
                'direccion' => $data['direccion'],
                'contraparte_tipo' => $data['contraparte_tipo'],
                'cliente_id' => $data['cliente_id'] ?? null,
                'otra_caja_id' => $data['otra_caja_id'] ?? null,
                'detalle' => $data['detalle'],
                'estado' => MovimientoCajaEfectivo::STATUS_REGISTERED,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $code = 'CAJ-'.str_pad((string) $cashMovementId, 10, '0', STR_PAD_LEFT);
            DB::table('movimientos_caja_efectivo')
                ->where('id', $cashMovementId)
                ->update(['codigo' => $code]);

            $cashMovement = (array) DB::table('movimientos_caja_efectivo')
                ->where('id', $cashMovementId)
                ->first();
            $this->audit->record(
                $companyId,
                $actor->id,
                'movimientos_caja_efectivo',
                $cashMovementId,
                'REGISTRAR',
                null,
                $cashMovement,
                $ip,
            );

            return [
                'movimiento_caja_id' => $cashMovementId,
                'idempotent' => false,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(
        int $companyId,
        User $actor,
        int $cashMovementId,
        array $data,
        ?string $ip = null,
    ): void {
        $this->assertCanRegister($companyId, $actor);

        DB::transaction(function () use ($companyId, $actor, $cashMovementId, $data, $ip): void {
            $cashMovement = DB::table('movimientos_caja_efectivo')
                ->where('empresa_id', $companyId)
                ->where('id', $cashMovementId)
                ->lockForUpdate()
                ->first();
            abort_unless($cashMovement, 404, 'Movimiento de caja no encontrado.');
            if ($cashMovement->estado !== MovimientoCajaEfectivo::STATUS_REGISTERED) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Solo se puede editar un movimiento de caja vigente.',
                ]);
            }
            $this->assertSupportedCounterpart($data, $cashMovement);

            $payment = DB::table('pagos')
                ->where('empresa_id', $companyId)
                ->where('id', $cashMovement->pago_id)
                ->lockForUpdate()
                ->first();
            abort_unless($payment, 409, 'El movimiento de caja no tiene un asiento financiero valido.');
            if ($payment->estado !== Pago::STATUS_REGISTERED || $payment->reversa_de_pago_id !== null) {
                throw ValidationException::withMessages([
                    'movimiento' => 'El asiento financiero de este movimiento ya no esta vigente.',
                ]);
            }

            $context = $this->context($companyId, $data);
            $payload = $this->movementPayload(
                $companyId,
                [...$data, 'idempotency_key' => (string) Str::uuid()],
                $context,
            );
            $before = [
                ...(array) $cashMovement,
                'pago' => (array) $payment,
            ];
            $financialChange = ! $this->sameFinancialEntry($payment, $payload);
            $paymentId = (int) $payment->id;

            if ($financialChange) {
                abort_unless(
                    $actor->hasPermission('PAGOS_ANULAR'),
                    403,
                    'Para cambiar importe, caja, direccion o contraparte tambien se requiere PAGOS_ANULAR.',
                );
                $this->movements->void(
                    $companyId,
                    $actor,
                    $paymentId,
                    'Correccion del movimiento de caja '.$cashMovement->codigo,
                    $ip,
                    null,
                    null,
                    $cashMovementId,
                );
                $replacement = $this->movements->register(
                    $companyId,
                    $actor,
                    $payload,
                    $ip,
                );
                $paymentId = $replacement['pago_id'];
            } else {
                $this->movements->updateMetadata(
                    $companyId,
                    $actor,
                    $paymentId,
                    [
                        'fecha_hora' => $payload['fecha_hora'],
                        'referencia' => null,
                        'observaciones' => $data['detalle'],
                    ],
                    $ip,
                    $cashMovementId,
                );
            }

            DB::table('movimientos_caja_efectivo')
                ->where('id', $cashMovementId)
                ->update([
                    'pago_id' => $paymentId,
                    'caja_id' => (int) $data['caja_id'],
                    'direccion' => $data['direccion'],
                    'contraparte_tipo' => $data['contraparte_tipo'],
                    'cliente_id' => $data['cliente_id'] ?? null,
                    'otra_caja_id' => $data['otra_caja_id'] ?? null,
                    'detalle' => $data['detalle'],
                    'updated_at' => now(),
                ]);

            $after = (array) DB::table('movimientos_caja_efectivo')
                ->where('id', $cashMovementId)
                ->first();
            $after['pago'] = (array) DB::table('pagos')->where('id', $paymentId)->first();
            $this->audit->record(
                $companyId,
                $actor->id,
                'movimientos_caja_efectivo',
                $cashMovementId,
                $financialChange ? 'CORREGIR_CON_REVERSA' : 'EDITAR',
                $before,
                $after,
                $ip,
            );
        }, 3);
    }

    /** @return array{movimiento_caja_id: int, caja_id: int, reversa_id: int, idempotent: bool} */
    public function void(
        int $companyId,
        User $actor,
        int $cashMovementId,
        ?string $ip = null,
    ): array {
        $this->assertCanVoid($companyId, $actor);

        return DB::transaction(function () use ($companyId, $actor, $cashMovementId, $ip): array {
            $cashMovement = DB::table('movimientos_caja_efectivo')
                ->where('empresa_id', $companyId)
                ->where('id', $cashMovementId)
                ->lockForUpdate()
                ->first();
            abort_unless($cashMovement, 404, 'Movimiento de caja no encontrado.');

            $payment = DB::table('pagos')
                ->where('empresa_id', $companyId)
                ->where('id', $cashMovement->pago_id)
                ->lockForUpdate()
                ->first();
            abort_unless($payment, 409, 'El movimiento de caja no tiene un asiento financiero valido.');

            if ($cashMovement->estado === MovimientoCajaEfectivo::STATUS_VOIDED) {
                $reverseId = DB::table('pagos')
                    ->where('empresa_id', $companyId)
                    ->where('reversa_de_pago_id', $payment->id)
                    ->value('id');
                abort_unless($reverseId, 409, 'El movimiento de caja esta anulado, pero no se encontro su reversa.');

                return [
                    'movimiento_caja_id' => $cashMovementId,
                    'caja_id' => (int) $cashMovement->caja_id,
                    'reversa_id' => (int) $reverseId,
                    'idempotent' => true,
                ];
            }
            if ($cashMovement->estado !== MovimientoCajaEfectivo::STATUS_REGISTERED) {
                throw ValidationException::withMessages([
                    'movimiento' => 'El movimiento de caja no esta vigente y no puede anularse.',
                ]);
            }

            $reason = 'Eliminación del movimiento de caja '.($cashMovement->codigo ?: '#'.$cashMovementId);
            $before = [
                ...(array) $cashMovement,
                'pago' => (array) $payment,
            ];
            $result = $this->movements->void(
                $companyId,
                $actor,
                (int) $payment->id,
                $reason,
                $ip,
                null,
                null,
                $cashMovementId,
            );

            $voidedAt = now();
            DB::table('movimientos_caja_efectivo')
                ->where('id', $cashMovementId)
                ->update([
                    'estado' => MovimientoCajaEfectivo::STATUS_VOIDED,
                    'updated_at' => $voidedAt,
                ]);

            $after = (array) DB::table('movimientos_caja_efectivo')
                ->where('id', $cashMovementId)
                ->first();
            $after['pago'] = (array) DB::table('pagos')
                ->where('id', $payment->id)
                ->first();
            $after['reversa_id'] = $result['reversa_id'];
            $after['motivo_anulacion'] = $reason;
            $this->audit->record(
                $companyId,
                $actor->id,
                'movimientos_caja_efectivo',
                $cashMovementId,
                'ANULAR',
                $before,
                $after,
                $ip,
            );

            return [
                'movimiento_caja_id' => $cashMovementId,
                'caja_id' => (int) $cashMovement->caja_id,
                'reversa_id' => $result['reversa_id'],
                'idempotent' => false,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $data @return array{caja: object, otra_caja: ?object, metodo_efectivo_id: int} */
    private function context(int $companyId, array $data): array
    {
        $cashRegister = $this->cashRegister($companyId, (int) $data['caja_id'], 'caja_id');
        $otherCashRegister = null;
        if ($data['contraparte_tipo'] === MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER) {
            $otherCashRegister = $this->cashRegister(
                $companyId,
                (int) $data['otra_caja_id'],
                'otra_caja_id',
            );
            if ((int) $cashRegister->id === (int) $otherCashRegister->id) {
                throw ValidationException::withMessages([
                    'otra_caja_id' => 'La otra caja debe ser diferente de la caja seleccionada.',
                ]);
            }
            if ($cashRegister->moneda !== $otherCashRegister->moneda) {
                throw ValidationException::withMessages([
                    'otra_caja_id' => 'Las dos cajas deben usar la misma moneda.',
                ]);
            }
        }

        $cashMethodId = DB::table('metodos_pago')
            ->where('codigo', 'EFECTIVO')
            ->where('estado', 'ACTIVO')
            ->value('id');
        if (! $cashMethodId) {
            throw ValidationException::withMessages([
                'metodo_pago' => 'El metodo de pago EFECTIVO no esta disponible.',
            ]);
        }

        return [
            'caja' => $cashRegister,
            'otra_caja' => $otherCashRegister,
            'metodo_efectivo_id' => (int) $cashMethodId,
        ];
    }

    private function cashRegister(int $companyId, int $cashRegisterId, string $field): object
    {
        $cashRegister = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('cuenta.id', $cashRegisterId)
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.tipo', 'PROPIA')
            ->where('entidad.estado', 'ACTIVO')
            ->where('cuenta.tipo', CuentaFinanciera::TYPE_CASH)
            ->where('cuenta.estado', CuentaFinanciera::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first(['cuenta.id', 'cuenta.moneda']);

        if (! $cashRegister) {
            throw ValidationException::withMessages([
                $field => 'Selecciona una caja propia y activa de esta empresa.',
            ]);
        }

        return $cashRegister;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{caja: object, otra_caja: ?object, metodo_efectivo_id: int}  $context
     * @return array<string, mixed>
     */
    private function movementPayload(int $companyId, array $data, array $context): array
    {
        $isIncome = $data['direccion'] === MovimientoCajaEfectivo::DIRECTION_INCOME;
        $counterpart = $data['contraparte_tipo'];
        $type = Pago::TYPE_ADJUSTMENT;
        $customerId = null;
        $originId = null;
        $destinationId = null;

        if ($counterpart === MovimientoCajaEfectivo::COUNTERPART_CUSTOMER) {
            $type = Pago::TYPE_CUSTOMER_COLLECTION;
            $customerId = (int) $data['cliente_id'];
            $destinationId = (int) $context['caja']->id;
        } elseif ($counterpart === MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER) {
            $type = Pago::TYPE_INTERNAL_TRANSFER;
            $originId = $isIncome
                ? (int) $context['otra_caja']->id
                : (int) $context['caja']->id;
            $destinationId = $isIncome
                ? (int) $context['caja']->id
                : (int) $context['otra_caja']->id;
        } elseif ($isIncome) {
            $destinationId = (int) $context['caja']->id;
        } else {
            $originId = (int) $context['caja']->id;
        }

        return [
            'idempotency_key' => $data['idempotency_key'],
            'tipo' => $type,
            'fecha_hora' => $this->databaseDateTime(
                (string) $data['fecha_hora'],
                $this->companyTimezone($companyId),
            ),
            'cliente_id' => $customerId,
            'proveedor_id' => null,
            'cuenta_origen_id' => $originId,
            'cuenta_destino_id' => $destinationId,
            'metodo_pago_id' => $context['metodo_efectivo_id'],
            'moneda' => $context['caja']->moneda,
            'importe' => $data['importe'],
            'referencia' => null,
            'observaciones' => $data['detalle'],
            'aplicaciones' => [],
        ];
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $payload */
    private function assertSameRequest(object $cashMovement, array $data, array $payload): void
    {
        $payment = DB::table('pagos')->where('id', $cashMovement->pago_id)->first();
        $same = $payment
            && (int) $cashMovement->caja_id === (int) $data['caja_id']
            && $cashMovement->direccion === $data['direccion']
            && $cashMovement->contraparte_tipo === $data['contraparte_tipo']
            && (int) ($cashMovement->cliente_id ?? 0) === (int) ($data['cliente_id'] ?? 0)
            && (int) ($cashMovement->otra_caja_id ?? 0) === (int) ($data['otra_caja_id'] ?? 0)
            && $cashMovement->detalle === $data['detalle']
            && $this->sameFinancialEntry($payment, $payload)
            && CarbonImmutable::parse((string) $payment->fecha_hora)->toDateTimeString()
                === CarbonImmutable::parse((string) $payload['fecha_hora'])->toDateTimeString()
            && ($payment->observaciones ?? null) === $data['detalle'];

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con otro movimiento de caja.',
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function sameFinancialEntry(object $payment, array $payload): bool
    {
        return $payment->tipo === $payload['tipo']
            && (int) ($payment->cliente_id ?? 0) === (int) ($payload['cliente_id'] ?? 0)
            && (int) ($payment->proveedor_id ?? 0) === (int) ($payload['proveedor_id'] ?? 0)
            && (int) ($payment->cuenta_origen_id ?? 0) === (int) ($payload['cuenta_origen_id'] ?? 0)
            && (int) ($payment->cuenta_destino_id ?? 0) === (int) ($payload['cuenta_destino_id'] ?? 0)
            && (int) ($payment->metodo_pago_id ?? 0) === (int) ($payload['metodo_pago_id'] ?? 0)
            && $payment->moneda === $payload['moneda']
            && FinancialMoney::compare((string) $payment->importe, (string) $payload['importe']) === 0;
    }

    private function assertCanRegister(int $companyId, User $actor): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId
                && $actor->isActive()
                && $actor->hasPermission('PAGOS_REGISTRAR')
                && $actor->hasPermission('SALDOS_AJUSTAR'),
            403,
            'Se requieren permisos para registrar y ajustar movimientos de caja.',
        );
    }

    private function assertCanVoid(int $companyId, User $actor): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId
                && $actor->isActive()
                && $actor->hasPermission('PAGOS_ANULAR'),
            403,
            'Se requiere permiso para anular movimientos de caja.',
        );
    }

    /** @param array<string, mixed> $data */
    private function assertSupportedCounterpart(array $data, ?object $existing = null): void
    {
        $direction = (string) ($data['direccion'] ?? '');
        $counterpart = (string) ($data['contraparte_tipo'] ?? '');
        if (! in_array($direction, MovimientoCajaEfectivo::DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'direccion' => 'La direccion del movimiento de caja no es valida.',
            ]);
        }

        $allowed = $direction === MovimientoCajaEfectivo::DIRECTION_INCOME
            ? MovimientoCajaEfectivo::INCOME_COUNTERPARTS
            : MovimientoCajaEfectivo::EXPENSE_COUNTERPARTS;
        $keepsLegacyExpenseOther = $direction === MovimientoCajaEfectivo::DIRECTION_EXPENSE
            && $counterpart === MovimientoCajaEfectivo::COUNTERPART_OTHER
            && $existing?->direccion === MovimientoCajaEfectivo::DIRECTION_EXPENSE
            && $existing?->contraparte_tipo === MovimientoCajaEfectivo::COUNTERPART_OTHER;

        if (! in_array($counterpart, $allowed, true) && ! $keepsLegacyExpenseOther) {
            throw ValidationException::withMessages([
                'contraparte_tipo' => 'La contraparte no corresponde a la direccion del movimiento.',
            ]);
        }
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
        return CarbonImmutable::parse($value, $companyTimezone)
            ->setTimezone($this->databaseTimezone())
            ->format('Y-m-d H:i:s');
    }
}
