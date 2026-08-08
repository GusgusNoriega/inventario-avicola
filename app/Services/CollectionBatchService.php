<?php

namespace App\Services;

use App\Models\Cobrador;
use App\Models\Cobranza;
use App\Models\CobranzaDetalle;
use App\Models\CuentaFinanciera;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\User;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

class CollectionBatchService
{
    public function __construct(
        private readonly FinancialMovementService $movements,
        private readonly FinancialAuditService $audit,
        private readonly FinancialAccountBalanceService $balances,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{cobranza_id: int, idempotent: bool}
     */
    public function register(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor, 'PAGOS_REGISTRAR');
        $payload = $this->canonicalPayload($companyId, $data);
        $payloadHash = $this->payloadHash($payload);

        try {
            return DB::transaction(
                fn (): array => $this->registerInTransaction(
                    $companyId,
                    $actor,
                    $payload,
                    $payloadHash,
                    $ip,
                ),
                3,
            );
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($companyId, $payload, $payloadHash, $exception): array {
                $existing = DB::table('cobranzas')
                    ->where('empresa_id', $companyId)
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->assertSameIdempotentRequest($existing, $payloadHash);

                    return ['cobranza_id' => (int) $existing->id, 'idempotent' => true];
                }

                if (DB::table('cobranzas')
                    ->where('empresa_id', $companyId)
                    ->where('cuenta_destino_id', $payload['cuenta_destino_id'])
                    ->where('referencia', $payload['referencia'])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'referencia' => 'Ya existe una cobranza con este voucher en la cuenta seleccionada.',
                    ]);
                }

                throw $exception;
            }, 3);
        }
    }

    /**
     * Reclassify an unidentified collection remainder without changing the
     * amount that entered the financial account.
     *
     * @param  array<string, mixed>  $data
     * @return array{cobranza_id: int, asignacion_id: int, idempotent: bool}
     */
    public function assignPending(
        int $companyId,
        User $actor,
        int $collectionId,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor, 'PAGOS_REGISTRAR');
        $payload = $this->canonicalAssignmentPayload($companyId, $collectionId, $data);
        $payloadHash = $this->assignmentPayloadHash($payload);

        try {
            return DB::transaction(
                fn (): array => $this->assignPendingInTransaction(
                    $companyId,
                    $actor,
                    $collectionId,
                    $payload,
                    $payloadHash,
                    $ip,
                ),
                3,
            );
        } catch (QueryException $exception) {
            return DB::transaction(function () use (
                $companyId,
                $collectionId,
                $payload,
                $payloadHash,
                $exception,
            ): array {
                $existing = DB::table('cobranza_asignaciones')
                    ->where('empresa_id', $companyId)
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if (! $existing) {
                    throw $exception;
                }

                $this->assertSameAssignmentRequest($existing, $collectionId, $payloadHash);

                return [
                    'cobranza_id' => $collectionId,
                    'asignacion_id' => (int) $existing->id,
                    'idempotent' => true,
                ];
            }, 3);
        }
    }

    /**
     * @return array{cobranza_id: int, reversa_ids: list<int>, idempotent: bool}
     */
    public function void(
        int $companyId,
        User $actor,
        int $collectionId,
        string $reason,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor, 'PAGOS_ANULAR');

        return DB::transaction(function () use (
            $companyId,
            $actor,
            $collectionId,
            $reason,
            $ip,
        ): array {
            $collection = DB::table('cobranzas')
                ->where('empresa_id', $companyId)
                ->where('id', $collectionId)
                ->lockForUpdate()
                ->first();
            abort_unless($collection, 404, 'Cobranza no encontrada.');

            $details = DB::table('cobranza_detalles')
                ->where('cobranza_id', $collectionId)
                ->orderBy('orden')
                ->lockForUpdate()
                ->get();
            $pending = DB::table('cobranza_pendientes')
                ->where('cobranza_id', $collectionId)
                ->lockForUpdate()
                ->first();

            if ($collection->estado === Cobranza::STATUS_VOIDED) {
                $paymentIds = $details->pluck('pago_id');
                if ($pending) {
                    $paymentIds->push($pending->pago_id);
                }
                $reverseIds = DB::table('pagos')
                    ->whereIn('reversa_de_pago_id', $paymentIds->all())
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                return [
                    'cobranza_id' => $collectionId,
                    'reversa_ids' => $reverseIds,
                    'idempotent' => true,
                ];
            }
            if ($collection->estado !== Cobranza::STATUS_REGISTERED) {
                throw ValidationException::withMessages([
                    'cobranza' => 'La cobranza no está vigente y no puede anularse.',
                ]);
            }
            if ($details->isEmpty() && ! $pending) {
                throw ValidationException::withMessages([
                    'cobranza' => 'La cobranza no tiene movimientos financieros para revertir.',
                ]);
            }

            $reverseIds = [];
            $fullReason = 'Anulación de cobranza '.($collection->codigo ?: '#'.$collectionId).': '.$reason;
            foreach ($details as $detail) {
                $result = $this->movements->void(
                    $companyId,
                    $actor,
                    (int) $detail->pago_id,
                    $fullReason,
                    $ip,
                    null,
                    $collectionId,
                );
                $reverseIds[] = (int) $result['reversa_id'];
            }
            if ($pending) {
                $result = $this->movements->void(
                    $companyId,
                    $actor,
                    (int) $pending->pago_id,
                    $fullReason,
                    $ip,
                    null,
                    $collectionId,
                );
                $reverseIds[] = (int) $result['reversa_id'];
            }

            $voidedAt = now();
            DB::table('cobranzas')->where('id', $collectionId)->update([
                'estado' => Cobranza::STATUS_VOIDED,
                'anulada_por' => $actor->id,
                'anulada_at' => $voidedAt,
                'motivo_anulacion' => $reason,
                'updated_at' => $voidedAt,
            ]);
            $after = (array) DB::table('cobranzas')->where('id', $collectionId)->first();
            $after['reversa_ids'] = $reverseIds;
            $this->audit->record(
                $companyId,
                $actor->id,
                'cobranzas',
                $collectionId,
                'ANULAR',
                (array) $collection,
                $after,
                $ip,
            );

            return [
                'cobranza_id' => $collectionId,
                'reversa_ids' => $reverseIds,
                'idempotent' => false,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{cobranza_id: int, idempotent: bool}
     */
    private function registerInTransaction(
        int $companyId,
        User $actor,
        array $payload,
        string $payloadHash,
        ?string $ip,
    ): array {
        $existing = DB::table('cobranzas')
            ->where('empresa_id', $companyId)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $this->assertSameIdempotentRequest($existing, $payloadHash);

            return ['cobranza_id' => (int) $existing->id, 'idempotent' => true];
        }

        if (DB::table('cobranzas')
            ->where('empresa_id', $companyId)
            ->where('cuenta_destino_id', $payload['cuenta_destino_id'])
            ->where('referencia', $payload['referencia'])
            ->exists()) {
            throw ValidationException::withMessages([
                'referencia' => 'Ya existe una cobranza con este voucher en la cuenta seleccionada.',
            ]);
        }

        $context = $this->lockedContext($companyId, $payload);
        $clientIds = collect($payload['detalles'])
            ->pluck('cliente_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->assertClients($companyId, $clientIds);

        $receivablePools = $this->receivablePools($companyId, $clientIds, $payload['moneda']);
        $payablePool = $context['proveedor_id'] === null
            ? []
            : $this->payablePool($companyId, $context['proveedor_id'], $payload['moneda']);

        $now = now();
        $collectionId = DB::table('cobranzas')->insertGetId([
            'empresa_id' => $companyId,
            'cobrador_id' => $context['cobrador']->id,
            'cobrador_nombre_snapshot' => $context['cobrador']->nombre,
            'codigo' => null,
            'idempotency_key' => $payload['idempotency_key'],
            'payload_hash' => $payloadHash,
            'cuenta_destino_id' => $context['cuenta']->id,
            'proveedor_id' => $context['proveedor_id'],
            'metodo_pago_id' => $context['metodo']->id,
            'fecha_hora' => $payload['fecha_hora'],
            'referencia' => $payload['referencia'],
            'moneda' => $payload['moneda'],
            'importe_total' => $payload['importe_total'],
            'observaciones' => $payload['observaciones'],
            'estado' => Cobranza::STATUS_REGISTERED,
            'created_by' => $actor->id,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $code = $this->collectionCode($collectionId);
        DB::table('cobranzas')->where('id', $collectionId)->update(['codigo' => $code]);

        $registeredDetails = [];
        foreach ($payload['detalles'] as $index => $detail) {
            $clientId = (int) $detail['cliente_id'];
            $receivablePools[$clientId] ??= [];
            $applications = $this->allocateApplications(
                $receivablePools[$clientId],
                $detail['importe'],
                'CXC',
            );
            if ($context['proveedor_id'] !== null) {
                $applications = [
                    ...$applications,
                    ...$this->allocateApplications($payablePool, $detail['importe'], 'CXP'),
                ];
            }

            $movement = $this->movements->register(
                $companyId,
                $actor,
                [
                    'idempotency_key' => $this->detailIdempotencyKey(
                        $payload['idempotency_key'],
                        $index + 1,
                    ),
                    'tipo' => $context['proveedor_id'] === null
                        ? Pago::TYPE_CUSTOMER_COLLECTION
                        : Pago::TYPE_DIRECT_PAYMENT,
                    'fecha_hora' => $payload['fecha_hora'],
                    'cliente_id' => $clientId,
                    'proveedor_id' => $context['proveedor_id'],
                    'cuenta_origen_id' => null,
                    'cuenta_destino_id' => (int) $context['cuenta']->id,
                    'metodo_pago_id' => (int) $context['metodo']->id,
                    'moneda' => $payload['moneda'],
                    'importe' => $detail['importe'],
                    'referencia' => $payload['referencia'],
                    'observaciones' => $this->movementNotes(
                        $code,
                        (string) $context['cobrador']->nombre,
                        $payload['observaciones'],
                    ),
                    'aplicaciones' => $applications,
                ],
                $ip,
            );

            $detailId = DB::table('cobranza_detalles')->insertGetId([
                'cobranza_id' => $collectionId,
                'pago_id' => $movement['pago_id'],
                'cliente_id' => $clientId,
                'fecha_recepcion' => $detail['fecha_recepcion'],
                'medio_recepcion' => CobranzaDetalle::RECEIPT_METHOD_CASH,
                'importe' => $detail['importe'],
                'orden' => $index + 1,
                'created_at' => $now,
            ]);
            $registeredDetails[] = [
                'id' => $detailId,
                'pago_id' => $movement['pago_id'],
                ...$detail,
                'aplicaciones' => $applications,
            ];
        }

        $pendingRecord = null;
        if (FinancialMoney::compare($payload['importe_pendiente'], '0.00') > 0) {
            $applications = $context['proveedor_id'] === null
                ? []
                : $this->allocateApplications(
                    $payablePool,
                    $payload['importe_pendiente'],
                    'CXP',
                );
            $movement = $this->movements->register(
                $companyId,
                $actor,
                [
                    'idempotency_key' => $this->pendingIdempotencyKey(
                        $payload['idempotency_key'],
                    ),
                    'tipo' => Pago::TYPE_UNASSIGNED_DEPOSIT,
                    'fecha_hora' => $payload['fecha_hora'],
                    'cliente_id' => null,
                    'proveedor_id' => $context['proveedor_id'],
                    'cuenta_origen_id' => null,
                    'cuenta_destino_id' => (int) $context['cuenta']->id,
                    'metodo_pago_id' => (int) $context['metodo']->id,
                    'moneda' => $payload['moneda'],
                    'importe' => $payload['importe_pendiente'],
                    'referencia' => $payload['referencia'],
                    'observaciones' => $this->pendingMovementNotes(
                        $code,
                        (string) $context['cobrador']->nombre,
                        $payload['observaciones'],
                    ),
                    'aplicaciones' => $applications,
                ],
                $ip,
            );
            $pendingId = DB::table('cobranza_pendientes')->insertGetId([
                'cobranza_id' => $collectionId,
                'pago_id' => $movement['pago_id'],
                'importe' => $payload['importe_pendiente'],
                'created_at' => $now,
            ]);
            $pendingRecord = [
                'id' => $pendingId,
                'pago_id' => $movement['pago_id'],
                'importe' => $payload['importe_pendiente'],
                'aplicaciones' => $applications,
            ];
        }

        $collection = (array) DB::table('cobranzas')->where('id', $collectionId)->first();
        $collection['detalles'] = $registeredDetails;
        $collection['importe_asignado'] = $payload['importe_asignado'];
        $collection['importe_pendiente'] = $payload['importe_pendiente'];
        $collection['pendiente'] = $pendingRecord;
        $this->audit->record(
            $companyId,
            $actor->id,
            'cobranzas',
            $collectionId,
            'REGISTRAR',
            null,
            $collection,
            $ip,
        );

        return ['cobranza_id' => $collectionId, 'idempotent' => false];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{cobranza_id: int, asignacion_id: int, idempotent: bool}
     */
    private function assignPendingInTransaction(
        int $companyId,
        User $actor,
        int $collectionId,
        array $payload,
        string $payloadHash,
        ?string $ip,
    ): array {
        $collection = DB::table('cobranzas')
            ->where('empresa_id', $companyId)
            ->where('id', $collectionId)
            ->lockForUpdate()
            ->first();
        abort_unless($collection, 404, 'Cobranza no encontrada.');

        $existing = DB::table('cobranza_asignaciones')
            ->where('empresa_id', $companyId)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $this->assertSameAssignmentRequest($existing, $collectionId, $payloadHash);

            return [
                'cobranza_id' => $collectionId,
                'asignacion_id' => (int) $existing->id,
                'idempotent' => true,
            ];
        }

        if ($collection->estado !== Cobranza::STATUS_REGISTERED) {
            throw ValidationException::withMessages([
                'cobranza' => 'Solo una cobranza vigente admite nuevas asignaciones.',
            ]);
        }

        $pending = DB::table('cobranza_pendientes')
            ->where('cobranza_id', $collectionId)
            ->lockForUpdate()
            ->first();
        if (! $pending) {
            throw ValidationException::withMessages([
                'cobranza' => 'La cobranza no tiene saldo pendiente por identificar.',
            ]);
        }

        $pendingAmount = FinancialMoney::normalize((string) $pending->importe);
        if (FinancialMoney::compare($pendingAmount, '0.00') <= 0) {
            throw ValidationException::withMessages([
                'cobranza' => 'La cobranza no tiene saldo pendiente por identificar.',
            ]);
        }
        if (FinancialMoney::compare($payload['importe_asignado'], $pendingAmount) > 0) {
            throw ValidationException::withMessages([
                'detalles' => "La asignación ({$payload['importe_asignado']}) supera el saldo pendiente ({$pendingAmount}).",
            ]);
        }

        $storedDetails = DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->orderBy('orden')
            ->lockForUpdate()
            ->get(['id', 'importe', 'orden']);
        $assignedBefore = $storedDetails->reduce(
            fn (string $sum, object $detail): string => FinancialMoney::add(
                $sum,
                (string) $detail->importe,
            ),
            '0.00',
        );
        $collectionTotal = FinancialMoney::normalize((string) $collection->importe_total);
        if (FinancialMoney::compare(
            FinancialMoney::add($assignedBefore, $pendingAmount),
            $collectionTotal,
        ) !== 0) {
            throw ValidationException::withMessages([
                'cobranza' => 'La cobranza tiene un desglose inconsistente y no puede reclasificarse.',
            ]);
        }

        $context = $this->lockedAssignmentContext($companyId, $collection);
        $pendingPayment = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where('id', $pending->pago_id)
            ->lockForUpdate()
            ->first();
        $this->assertPendingPaymentMatchesCollection(
            $collection,
            $pending,
            $pendingPayment,
            $context,
        );

        $this->assertAssignmentDates($collection, $payload['detalles'], $companyId);
        $clientIds = collect($payload['detalles'])
            ->pluck('cliente_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->assertClients($companyId, $clientIds);
        $receivablePools = $this->receivablePools(
            $companyId,
            $clientIds,
            (string) $collection->moneda,
        );
        $payableSnapshot = $this->payableApplicationSnapshot(
            $companyId,
            $pendingPayment,
            $context['proveedor_id'],
            (string) $collection->moneda,
        );
        $accountBalanceBefore = $this->balances->forAccount(
            (int) $collection->cuenta_destino_id,
        )['saldo'];

        $nextOrder = ((int) ($storedDetails->max('orden') ?? 0)) + 1;
        $newDetails = [];
        $replacementPayments = [];
        foreach ($payload['detalles'] as $index => $detail) {
            $clientId = (int) $detail['cliente_id'];
            $receivablePools[$clientId] ??= [];
            $applications = $this->allocateApplications(
                $receivablePools[$clientId],
                $detail['importe'],
                'CXC',
            );
            $movement = $this->movements->register(
                $companyId,
                $actor,
                [
                    'idempotency_key' => $this->assignmentDetailIdempotencyKey(
                        $payload['idempotency_key'],
                        $index + 1,
                    ),
                    'tipo' => $context['proveedor_id'] === null
                        ? Pago::TYPE_CUSTOMER_COLLECTION
                        : Pago::TYPE_DIRECT_PAYMENT,
                    'fecha_hora' => $collection->fecha_hora,
                    'cliente_id' => $clientId,
                    'proveedor_id' => $context['proveedor_id'],
                    'cuenta_origen_id' => null,
                    'cuenta_destino_id' => (int) $collection->cuenta_destino_id,
                    'metodo_pago_id' => (int) $collection->metodo_pago_id,
                    'moneda' => (string) $collection->moneda,
                    'importe' => $detail['importe'],
                    'referencia' => (string) $collection->referencia,
                    'observaciones' => $this->assignmentMovementNotes(
                        (string) ($collection->codigo ?: '#'.$collectionId),
                        (string) $collection->cobrador_nombre_snapshot,
                        (string) $payload['idempotency_key'],
                    ),
                    'aplicaciones' => $applications,
                ],
                $ip,
            );
            $newDetails[] = [
                'pago_id' => (int) $movement['pago_id'],
                'cliente_id' => $clientId,
                'fecha_recepcion' => $detail['fecha_recepcion'],
                'importe' => $detail['importe'],
                'orden' => $nextOrder + $index,
                'aplicaciones' => $applications,
            ];
            $replacementPayments[] = [
                'pago_id' => (int) $movement['pago_id'],
                'importe' => $detail['importe'],
            ];
        }

        $residualAmount = FinancialMoney::subtract(
            $pendingAmount,
            $payload['importe_asignado'],
        );
        $newPendingPaymentId = null;
        if (FinancialMoney::compare($residualAmount, '0.00') > 0) {
            $movement = $this->movements->register(
                $companyId,
                $actor,
                [
                    'idempotency_key' => $this->assignmentPendingIdempotencyKey(
                        $payload['idempotency_key'],
                    ),
                    'tipo' => Pago::TYPE_UNASSIGNED_DEPOSIT,
                    'fecha_hora' => $collection->fecha_hora,
                    'cliente_id' => null,
                    'proveedor_id' => $context['proveedor_id'],
                    'cuenta_origen_id' => null,
                    'cuenta_destino_id' => (int) $collection->cuenta_destino_id,
                    'metodo_pago_id' => (int) $collection->metodo_pago_id,
                    'moneda' => (string) $collection->moneda,
                    'importe' => $residualAmount,
                    'referencia' => (string) $collection->referencia,
                    'observaciones' => $this->pendingReplacementNotes(
                        (string) ($collection->codigo ?: '#'.$collectionId),
                        (string) $collection->cobrador_nombre_snapshot,
                    ),
                    'aplicaciones' => [],
                ],
                $ip,
            );
            $newPendingPaymentId = (int) $movement['pago_id'];
            $replacementPayments[] = [
                'pago_id' => $newPendingPaymentId,
                'importe' => $residualAmount,
            ];
        }

        $replacementTotal = collect($replacementPayments)->reduce(
            fn (string $sum, array $payment): string => FinancialMoney::add(
                $sum,
                $payment['importe'],
            ),
            '0.00',
        );
        if (FinancialMoney::compare($replacementTotal, $pendingAmount) !== 0) {
            throw ValidationException::withMessages([
                'cobranza' => 'La reclasificación no conserva el importe pendiente original.',
            ]);
        }

        $reverse = $this->movements->void(
            $companyId,
            $actor,
            (int) $pending->pago_id,
            'Reclasificación del saldo pendiente de la cobranza '.($collection->codigo ?: '#'.$collectionId).'.',
            $ip,
            null,
            $collectionId,
        );
        $payableDistribution = $this->reapplyPayableSnapshot(
            $companyId,
            $actor,
            (string) $payload['idempotency_key'],
            (string) ($collection->codigo ?: '#'.$collectionId),
            $replacementPayments,
            $payableSnapshot,
            $ip,
        );
        $this->assertPayableSnapshotTransferred($replacementPayments, $payableSnapshot);

        $now = now();
        $assignmentId = DB::table('cobranza_asignaciones')->insertGetId([
            'empresa_id' => $companyId,
            'cobranza_id' => $collectionId,
            'idempotency_key' => $payload['idempotency_key'],
            'payload_hash' => $payloadHash,
            'importe_pendiente_antes' => $pendingAmount,
            'importe_asignado' => $payload['importe_asignado'],
            'importe_pendiente_despues' => $residualAmount,
            'pago_pendiente_anterior_id' => $pending->pago_id,
            'pago_reversa_id' => $reverse['reversa_id'],
            'pago_pendiente_nuevo_id' => $newPendingPaymentId,
            'created_by' => $actor->id,
            'created_at' => $now,
        ]);

        DB::table('cobranza_detalles')->insert(collect($newDetails)
            ->map(fn (array $detail): array => [
                'cobranza_id' => $collectionId,
                'asignacion_id' => $assignmentId,
                'pago_id' => $detail['pago_id'],
                'cliente_id' => $detail['cliente_id'],
                'fecha_recepcion' => $detail['fecha_recepcion'],
                'medio_recepcion' => CobranzaDetalle::RECEIPT_METHOD_CASH,
                'importe' => $detail['importe'],
                'orden' => $detail['orden'],
                'created_at' => $now,
            ])->all());

        if ($newPendingPaymentId === null) {
            DB::table('cobranza_pendientes')->where('id', $pending->id)->delete();
        } else {
            DB::table('cobranza_pendientes')->where('id', $pending->id)->update([
                'pago_id' => $newPendingPaymentId,
                'importe' => $residualAmount,
                'created_at' => $now,
            ]);
        }
        DB::table('cobranzas')->where('id', $collectionId)->update(['updated_at' => $now]);

        if ($newPendingPaymentId !== null) {
            $updatedPending = DB::table('cobranza_pendientes')
                ->where('cobranza_id', $collectionId)
                ->lockForUpdate()
                ->first();
            $updatedPendingPayment = DB::table('pagos')
                ->where('empresa_id', $companyId)
                ->where('id', $newPendingPaymentId)
                ->lockForUpdate()
                ->first();
            if (! $updatedPending) {
                throw ValidationException::withMessages([
                    'cobranza' => 'No se pudo conservar el saldo residual de la cobranza.',
                ]);
            }
            $this->assertPendingPaymentMatchesCollection(
                $collection,
                $updatedPending,
                $updatedPendingPayment,
                $context,
            );
        }
        $this->assertCollectionReconciles($collectionId, $collectionTotal);
        $accountBalanceAfter = $this->balances->forAccount(
            (int) $collection->cuenta_destino_id,
        )['saldo'];
        if (FinancialMoney::compare($accountBalanceBefore, $accountBalanceAfter) !== 0) {
            throw ValidationException::withMessages([
                'cobranza' => 'La reclasificación alteró el saldo de la cuenta financiera y fue revertida.',
            ]);
        }

        $assignment = (array) DB::table('cobranza_asignaciones')
            ->where('id', $assignmentId)
            ->first();
        $assignment['detalles'] = $newDetails;
        $assignment['distribucion_cxp'] = $payableDistribution;
        $assignment['saldo_cuenta_antes'] = $accountBalanceBefore;
        $assignment['saldo_cuenta_despues'] = $accountBalanceAfter;
        $this->audit->record(
            $companyId,
            $actor->id,
            'cobranzas',
            $collectionId,
            'ASIGNAR_PENDIENTE',
            [
                'importe_pendiente' => $pendingAmount,
                'pago_pendiente_id' => (int) $pending->pago_id,
                'aplicaciones_cxp' => $payableSnapshot,
                'saldo_cuenta' => $accountBalanceBefore,
            ],
            $assignment,
            $ip,
        );

        return [
            'cobranza_id' => $collectionId,
            'asignacion_id' => $assignmentId,
            'idempotent' => false,
        ];
    }

    /** @return array{cuenta: object, proveedor_id: ?int, metodo: object} */
    private function lockedAssignmentContext(int $companyId, object $collection): array
    {
        $account = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('cuenta.id', $collection->cuenta_destino_id)
            ->where('entidad.empresa_id', $companyId)
            ->where('cuenta.estado', 'ACTIVO')
            ->where('entidad.estado', 'ACTIVO')
            ->whereIn('entidad.tipo', ['PROPIA', 'EXTERNA'])
            ->lockForUpdate()
            ->first([
                'cuenta.id',
                'cuenta.tipo',
                'cuenta.moneda',
                'entidad.tipo as entidad_tipo',
                'entidad.proveedor_id as entidad_proveedor_id',
            ]);
        if (! $account || $account->moneda !== $collection->moneda) {
            throw ValidationException::withMessages([
                'cobranza' => 'La cuenta de destino de la cobranza no está activa o cambió de moneda.',
            ]);
        }

        $collectionProviderId = $collection->proveedor_id === null
            ? null
            : (int) $collection->proveedor_id;
        $accountProviderId = $account->entidad_proveedor_id === null
            ? null
            : (int) $account->entidad_proveedor_id;
        if (($account->entidad_tipo === 'PROPIA' && $collectionProviderId !== null)
            || ($account->entidad_tipo === 'EXTERNA'
                && ($collectionProviderId === null || $accountProviderId !== $collectionProviderId))) {
            throw ValidationException::withMessages([
                'cobranza' => 'La relación entre la cuenta y el proveedor de la cobranza es inconsistente.',
            ]);
        }

        if ($collectionProviderId !== null) {
            $validProvider = DB::table('terceros as tercero')
                ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
                ->where('tercero.id', $collectionProviderId)
                ->where('tercero.empresa_id', $companyId)
                ->where('tercero.estado', 'ACTIVO')
                ->where('rol.rol', 'PROVEEDOR')
                ->lockForUpdate()
                ->first(['tercero.id']);
            if (! $validProvider) {
                throw ValidationException::withMessages([
                    'cobranza' => 'El proveedor vinculado a la cobranza ya no está activo.',
                ]);
            }
        }

        $method = DB::table('metodos_pago')
            ->where('id', $collection->metodo_pago_id)
            ->whereIn('codigo', $this->allowedCollectionMethodCodes($account->tipo))
            ->where('estado', MetodoPago::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
        if (! $method) {
            throw ValidationException::withMessages([
                'cobranza' => 'El método de pago original ya no está disponible para la cuenta de destino.',
            ]);
        }

        return [
            'cuenta' => $account,
            'proveedor_id' => $collectionProviderId,
            'metodo' => $method,
        ];
    }

    /** @param array{cuenta: object, proveedor_id: ?int, metodo: object} $context */
    private function assertPendingPaymentMatchesCollection(
        object $collection,
        object $pending,
        ?object $payment,
        array $context,
    ): void {
        $paymentProviderId = $payment?->proveedor_id === null
            ? null
            : (int) $payment->proveedor_id;
        if (! $payment
            || $payment->estado !== Pago::STATUS_REGISTERED
            || $payment->reversa_de_pago_id !== null
            || $payment->tipo !== Pago::TYPE_UNASSIGNED_DEPOSIT
            || $payment->cliente_id !== null
            || $payment->cuenta_origen_id !== null
            || (int) $payment->cuenta_destino_id !== (int) $collection->cuenta_destino_id
            || (int) $payment->metodo_pago_id !== (int) $context['metodo']->id
            || $paymentProviderId !== $context['proveedor_id']
            || $payment->direccion !== ($context['proveedor_id'] === null
                ? Pago::DIRECTION_INCOME
                : 'DIRECTO')
            || $payment->moneda !== $collection->moneda
            || (string) $payment->referencia !== (string) $collection->referencia
            || FinancialMoney::compare((string) $payment->importe, (string) $pending->importe) !== 0) {
            throw ValidationException::withMessages([
                'cobranza' => 'El movimiento pendiente no coincide con la cobranza o ya no está vigente.',
            ]);
        }
    }

    /** @param list<array<string, mixed>> $details */
    private function assertAssignmentDates(object $collection, array $details, int $companyId): void
    {
        $collectionDate = CarbonImmutable::parse(
            (string) $collection->fecha_hora,
            $this->databaseTimezone(),
        )->setTimezone($this->companyTimezone($companyId))->format('Y-m-d');

        foreach ($details as $index => $detail) {
            if ($detail['fecha_recepcion'] > $collectionDate) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.fecha_recepcion" => 'La recepción del efectivo no puede ser posterior al depósito.',
                ]);
            }
        }
    }

    /**
     * @return list<array{comprobante_id: int, importe_aplicado: string}>
     */
    private function payableApplicationSnapshot(
        int $companyId,
        object $pendingPayment,
        ?int $providerId,
        string $currency,
    ): array {
        $applications = DB::table('pago_aplicaciones')
            ->where('pago_id', $pendingPayment->id)
            ->orderBy('comprobante_id')
            ->lockForUpdate()
            ->get(['comprobante_id', 'lado', 'importe_aplicado']);
        if ($applications->contains(fn (object $application): bool => $application->lado !== 'CXP')) {
            throw ValidationException::withMessages([
                'cobranza' => 'El movimiento pendiente contiene aplicaciones contables no permitidas.',
            ]);
        }
        if ($providerId === null && $applications->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cobranza' => 'Un depósito pendiente en cuenta propia no puede tener aplicaciones CXP.',
            ]);
        }
        if ($applications->isEmpty()) {
            return [];
        }

        $documentIds = $applications->pluck('comprobante_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values();
        $documents = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->whereIn('id', $documentIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'tercero_id', 'operacion', 'naturaleza', 'moneda'])
            ->keyBy('id');
        if ($documents->count() !== $documentIds->count()) {
            throw ValidationException::withMessages([
                'cobranza' => 'No se pudieron bloquear todas las CXP del saldo pendiente.',
            ]);
        }

        $snapshot = [];
        $appliedTotal = '0.00';
        foreach ($applications as $application) {
            $document = $documents->get((int) $application->comprobante_id);
            $amount = FinancialMoney::normalize((string) $application->importe_aplicado);
            if (! $document
                || $document->operacion !== 'COMPRA'
                || $document->naturaleza !== 'CARGO'
                || (int) $document->tercero_id !== $providerId
                || $document->moneda !== $currency
                || FinancialMoney::compare($amount, '0.00') <= 0) {
                throw ValidationException::withMessages([
                    'cobranza' => 'Una aplicación CXP del saldo pendiente es inconsistente.',
                ]);
            }
            $snapshot[] = [
                'comprobante_id' => (int) $application->comprobante_id,
                'importe_aplicado' => $amount,
            ];
            $appliedTotal = FinancialMoney::add($appliedTotal, $amount);
        }
        if (FinancialMoney::compare($appliedTotal, (string) $pendingPayment->importe) > 0) {
            throw ValidationException::withMessages([
                'cobranza' => 'Las aplicaciones CXP superan el importe del saldo pendiente.',
            ]);
        }

        return $snapshot;
    }

    /**
     * @param  list<array{pago_id: int, importe: string}>  $replacementPayments
     * @param  list<array{comprobante_id: int, importe_aplicado: string}>  $snapshot
     * @return list<array{pago_id: int, aplicaciones: list<array{comprobante_id: int, importe_aplicado: string}>}>
     */
    private function reapplyPayableSnapshot(
        int $companyId,
        User $actor,
        string $assignmentKey,
        string $collectionCode,
        array $replacementPayments,
        array $snapshot,
        ?string $ip,
    ): array {
        if ($snapshot === []) {
            return [];
        }

        $distributions = collect($replacementPayments)
            ->map(fn (array $payment): array => [
                'pago_id' => $payment['pago_id'],
                'capacidad' => FinancialMoney::normalize($payment['importe']),
                'aplicaciones' => [],
            ])->all();
        $paymentIndex = 0;

        foreach ($snapshot as $application) {
            $remaining = $application['importe_aplicado'];
            while (FinancialMoney::compare($remaining, '0.00') > 0) {
                while (isset($distributions[$paymentIndex])
                    && FinancialMoney::compare($distributions[$paymentIndex]['capacidad'], '0.00') <= 0) {
                    $paymentIndex++;
                }
                if (! isset($distributions[$paymentIndex])) {
                    throw ValidationException::withMessages([
                        'cobranza' => 'Los pagos reemplazo no cubren las aplicaciones CXP originales.',
                    ]);
                }

                $chunk = FinancialMoney::compare(
                    $remaining,
                    $distributions[$paymentIndex]['capacidad'],
                ) <= 0
                    ? $remaining
                    : $distributions[$paymentIndex]['capacidad'];
                $distributions[$paymentIndex]['aplicaciones'][] = [
                    'comprobante_id' => $application['comprobante_id'],
                    'importe_aplicado' => $chunk,
                ];
                $distributions[$paymentIndex]['capacidad'] = FinancialMoney::subtract(
                    $distributions[$paymentIndex]['capacidad'],
                    $chunk,
                );
                $remaining = FinancialMoney::subtract($remaining, $chunk);
            }
        }

        $result = [];
        foreach ($distributions as $index => $distribution) {
            if ($distribution['aplicaciones'] === []) {
                continue;
            }
            $this->movements->applyProviderPayment(
                $companyId,
                $actor,
                (int) $distribution['pago_id'],
                [
                    'idempotency_key' => $this->assignmentPayableIdempotencyKey(
                        $assignmentKey,
                        $index + 1,
                    ),
                    'aplicaciones' => $distribution['aplicaciones'],
                    'observaciones' => "Conservación de CXP al asignar saldo pendiente de {$collectionCode}.",
                ],
                $ip,
            );
            $result[] = [
                'pago_id' => (int) $distribution['pago_id'],
                'aplicaciones' => $distribution['aplicaciones'],
            ];
        }

        return $result;
    }

    /**
     * @param  list<array{pago_id: int, importe: string}>  $replacementPayments
     * @param  list<array{comprobante_id: int, importe_aplicado: string}>  $snapshot
     */
    private function assertPayableSnapshotTransferred(array $replacementPayments, array $snapshot): void
    {
        $paymentIds = collect($replacementPayments)->pluck('pago_id')->all();
        $stored = DB::table('pago_aplicaciones')
            ->whereIn('pago_id', $paymentIds)
            ->where('lado', 'CXP')
            ->orderBy('comprobante_id')
            ->lockForUpdate()
            ->get(['comprobante_id', 'importe_aplicado']);

        $expected = [];
        foreach ($snapshot as $application) {
            $documentId = (int) $application['comprobante_id'];
            $expected[$documentId] = FinancialMoney::add(
                $expected[$documentId] ?? '0.00',
                $application['importe_aplicado'],
            );
        }
        $actual = [];
        foreach ($stored as $application) {
            $documentId = (int) $application->comprobante_id;
            $actual[$documentId] = FinancialMoney::add(
                $actual[$documentId] ?? '0.00',
                (string) $application->importe_aplicado,
            );
        }
        ksort($expected);
        ksort($actual);

        if (array_keys($expected) !== array_keys($actual)) {
            throw ValidationException::withMessages([
                'cobranza' => 'La reclasificación cambió los comprobantes CXP aplicados.',
            ]);
        }
        foreach ($expected as $documentId => $amount) {
            if (FinancialMoney::compare($amount, $actual[$documentId]) !== 0) {
                throw ValidationException::withMessages([
                    'cobranza' => 'La reclasificación cambió los importes aplicados a CXP.',
                ]);
            }
        }
    }

    private function assertCollectionReconciles(int $collectionId, string $collectionTotal): void
    {
        $assigned = FinancialMoney::normalize((string) DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->sum('importe'));
        $pending = FinancialMoney::normalize((string) (
            DB::table('cobranza_pendientes')
                ->where('cobranza_id', $collectionId)
                ->value('importe') ?? 0
        ));
        if (FinancialMoney::compare(
            FinancialMoney::add($assigned, $pending),
            $collectionTotal,
        ) !== 0) {
            throw ValidationException::withMessages([
                'cobranza' => 'La suma asignada y pendiente dejó de coincidir con el voucher.',
            ]);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function canonicalAssignmentPayload(int $companyId, int $collectionId, array $data): array
    {
        if (empty($data['idempotency_key']) || ! Uuid::isValid((string) $data['idempotency_key'])) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Debe enviarse una clave UUID de idempotencia válida.',
            ]);
        }

        $timezone = $this->companyTimezone($companyId);
        $details = [];
        $assigned = '0.00';
        foreach (($data['detalles'] ?? []) as $index => $detail) {
            $clientId = (int) ($detail['cliente_id'] ?? 0);
            if ($clientId <= 0) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.cliente_id" => 'Selecciona un cliente válido.',
                ]);
            }
            try {
                $amount = FinancialMoney::normalize($detail['importe'] ?? '');
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.importe" => 'El importe del cliente no es válido.',
                ]);
            }
            if (FinancialMoney::compare($amount, '0.00') <= 0) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.importe" => 'El importe del cliente debe ser mayor que cero.',
                ]);
            }

            $receiptDate = trim((string) ($detail['fecha_recepcion'] ?? ''));
            try {
                $parsedReceiptDate = CarbonImmutable::createFromFormat('!Y-m-d', $receiptDate, $timezone);
            } catch (Throwable) {
                $parsedReceiptDate = false;
            }
            if (! $parsedReceiptDate || $parsedReceiptDate->format('Y-m-d') !== $receiptDate) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.fecha_recepcion" => 'La fecha de recepción debe tener el formato YYYY-MM-DD.',
                ]);
            }

            $details[] = [
                'cliente_id' => $clientId,
                'fecha_recepcion' => $receiptDate,
                'importe' => $amount,
            ];
            $assigned = FinancialMoney::add($assigned, $amount);
        }
        if ($details === []) {
            throw ValidationException::withMessages([
                'detalles' => 'Agrega al menos un abono de cliente.',
            ]);
        }

        return [
            'idempotency_key' => strtolower((string) $data['idempotency_key']),
            'cobranza_id' => $collectionId,
            'importe_asignado' => $assigned,
            'detalles' => $details,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function assignmentPayloadHash(array $payload): string
    {
        return hash('sha256', json_encode([
            'cobranza_id' => $payload['cobranza_id'],
            'detalles' => $payload['detalles'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertSameAssignmentRequest(
        object $assignment,
        int $collectionId,
        string $payloadHash,
    ): void {
        if ((int) $assignment->cobranza_id !== $collectionId
            || ! hash_equals((string) $assignment->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con una asignación diferente.',
            ]);
        }
    }

    private function assignmentDetailIdempotencyKey(string $assignmentKey, int $order): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "sistema-pollos:cobranza-asignacion:{$assignmentKey}:detalle:{$order}",
        )->toString();
    }

    private function assignmentPendingIdempotencyKey(string $assignmentKey): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "sistema-pollos:cobranza-asignacion:{$assignmentKey}:pendiente",
        )->toString();
    }

    private function assignmentPayableIdempotencyKey(string $assignmentKey, int $order): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "sistema-pollos:cobranza-asignacion:{$assignmentKey}:cxp:{$order}",
        )->toString();
    }

    private function assignmentMovementNotes(string $code, string $collector, string $assignmentKey): string
    {
        return mb_substr(
            "Cobranza {$code}. Saldo pendiente asignado a cliente. Efectivo recibido por {$collector}. Operación {$assignmentKey}.",
            0,
            2000,
        );
    }

    private function pendingReplacementNotes(string $code, string $collector): string
    {
        return mb_substr(
            "Cobranza {$code}. Remanente aún pendiente de identificar. Efectivo recibido por {$collector}.",
            0,
            2000,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{cobrador: object, cuenta: object, proveedor_id: ?int, metodo: object}
     */
    private function lockedContext(int $companyId, array $payload): array
    {
        $collector = DB::table('cobradores')
            ->where('empresa_id', $companyId)
            ->where('id', $payload['cobrador_id'])
            ->where('estado', Cobrador::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
        if (! $collector) {
            throw ValidationException::withMessages([
                'cobrador_id' => 'El cobrador no existe, está inactivo o pertenece a otra empresa.',
            ]);
        }

        $account = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('cuenta.id', $payload['cuenta_destino_id'])
            ->where('entidad.empresa_id', $companyId)
            ->where('cuenta.estado', 'ACTIVO')
            ->where('entidad.estado', 'ACTIVO')
            ->whereIn('entidad.tipo', ['PROPIA', 'EXTERNA'])
            ->lockForUpdate()
            ->first([
                'cuenta.id',
                'cuenta.tipo',
                'cuenta.moneda',
                'entidad.tipo as entidad_tipo',
                'entidad.proveedor_id as entidad_proveedor_id',
            ]);
        if (! $account) {
            throw ValidationException::withMessages([
                'cuenta_destino_id' => 'Selecciona una cuenta propia o externa activa de esta empresa.',
            ]);
        }
        if ($account->moneda !== $payload['moneda']) {
            throw ValidationException::withMessages([
                'moneda' => 'La moneda debe coincidir con la cuenta de destino.',
            ]);
        }

        $providerId = null;
        if ($account->entidad_tipo === 'EXTERNA') {
            $providerId = $account->entidad_proveedor_id === null
                ? null
                : (int) $account->entidad_proveedor_id;
            $validProvider = $providerId !== null && DB::table('terceros as tercero')
                ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
                ->where('tercero.id', $providerId)
                ->where('tercero.empresa_id', $companyId)
                ->where('tercero.estado', 'ACTIVO')
                ->where('rol.rol', 'PROVEEDOR')
                ->exists();
            if (! $validProvider) {
                throw ValidationException::withMessages([
                    'cuenta_destino_id' => 'La cuenta externa debe estar vinculada a un proveedor activo.',
                ]);
            }
        }

        $methodCode = $this->preferredCollectionMethodCode($account->tipo);
        $method = DB::table('metodos_pago')
            ->where('codigo', $methodCode)
            ->where('estado', MetodoPago::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
        if (! $method) {
            throw ValidationException::withMessages([
                'metodo_pago' => "El método de pago {$methodCode} no está disponible.",
            ]);
        }

        return [
            'cobrador' => $collector,
            'cuenta' => $account,
            'proveedor_id' => $providerId,
            'metodo' => $method,
        ];
    }

    private function preferredCollectionMethodCode(string $accountType): string
    {
        return $accountType === CuentaFinanciera::TYPE_CASH
            ? MetodoPago::CODE_CASH
            : MetodoPago::CODE_DEPOSIT;
    }

    /** @return list<string> */
    private function allowedCollectionMethodCodes(string $accountType): array
    {
        if ($accountType !== CuentaFinanciera::TYPE_CASH) {
            return [MetodoPago::CODE_DEPOSIT];
        }

        // DEPÓSITO se conserva para cobranzas históricas creadas antes de
        // distinguir entre una caja física y una cuenta bancaria.
        return [MetodoPago::CODE_CASH, MetodoPago::CODE_DEPOSIT];
    }

    /** @param list<int> $clientIds */
    private function assertClients(int $companyId, array $clientIds): void
    {
        $clients = DB::table('terceros as tercero')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
            ->where('tercero.empresa_id', $companyId)
            ->where('tercero.estado', 'ACTIVO')
            ->where('rol.rol', 'CLIENTE')
            ->whereIn('tercero.id', $clientIds)
            ->orderBy('tercero.id')
            ->lockForUpdate()
            ->pluck('tercero.id')
            ->map(fn ($id): int => (int) $id)
            ->unique();
        if ($clients->count() !== count($clientIds)) {
            throw ValidationException::withMessages([
                'detalles' => 'Uno o más clientes no existen, están inactivos o pertenecen a otra empresa.',
            ]);
        }
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, list<array{id: int, saldo: string}>>
     */
    private function receivablePools(int $companyId, array $clientIds, string $currency): array
    {
        $rows = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->whereIn('tercero_id', $clientIds)
            ->where('operacion', 'VENTA')
            ->where('naturaleza', 'CARGO')
            ->where('moneda', $currency)
            ->whereIn('estado', ['PENDIENTE', 'PARCIAL'])
            ->where('saldo_pendiente', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'tercero_id', 'fecha_emision', 'saldo_pendiente']);

        return $rows
            ->groupBy('tercero_id')
            ->map(fn ($documents): array => $documents
                ->sortBy(fn (object $document): string => $document->fecha_emision.'-'.str_pad((string) $document->id, 20, '0', STR_PAD_LEFT))
                ->map(fn (object $document): array => [
                    'id' => (int) $document->id,
                    'saldo' => FinancialMoney::normalize((string) $document->saldo_pendiente),
                ])
                ->values()
                ->all())
            ->all();
    }

    /** @return list<array{id: int, saldo: string}> */
    private function payablePool(int $companyId, int $providerId, string $currency): array
    {
        return DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $providerId)
            ->where('operacion', 'COMPRA')
            ->where('naturaleza', 'CARGO')
            ->where('moneda', $currency)
            ->whereIn('estado', ['PENDIENTE', 'PARCIAL'])
            ->where('saldo_pendiente', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'fecha_emision', 'saldo_pendiente'])
            ->sortBy(fn (object $document): string => $document->fecha_emision.'-'.str_pad((string) $document->id, 20, '0', STR_PAD_LEFT))
            ->map(fn (object $document): array => [
                'id' => (int) $document->id,
                'saldo' => FinancialMoney::normalize((string) $document->saldo_pendiente),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: int, saldo: string}>  $pool
     * @return list<array{lado: string, comprobante_id: int, importe_aplicado: string}>
     */
    private function allocateApplications(array &$pool, string $amount, string $side): array
    {
        $available = FinancialMoney::normalize($amount);
        $applications = [];

        foreach ($pool as &$document) {
            if (FinancialMoney::compare($available, '0.00') <= 0) {
                break;
            }
            if (FinancialMoney::compare($document['saldo'], '0.00') <= 0) {
                continue;
            }

            $applied = FinancialMoney::compare($available, $document['saldo']) <= 0
                ? $available
                : $document['saldo'];
            $applications[] = [
                'lado' => $side,
                'comprobante_id' => $document['id'],
                'importe_aplicado' => $applied,
            ];
            $document['saldo'] = FinancialMoney::subtract($document['saldo'], $applied);
            $available = FinancialMoney::subtract($available, $applied);
        }
        unset($document);

        return $applications;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function canonicalPayload(int $companyId, array $data): array
    {
        if (empty($data['idempotency_key']) || ! Uuid::isValid((string) $data['idempotency_key'])) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Debe enviarse una clave UUID de idempotencia válida.',
            ]);
        }

        try {
            $total = FinancialMoney::normalize($data['importe_total'] ?? '');
        } catch (Throwable) {
            throw ValidationException::withMessages(['importe_total' => 'El importe total no es válido.']);
        }
        if (FinancialMoney::compare($total, '0.00') <= 0) {
            throw ValidationException::withMessages(['importe_total' => 'El importe total debe ser mayor que cero.']);
        }

        $timezone = $this->companyTimezone($companyId);
        try {
            $localDateTime = CarbonImmutable::parse((string) ($data['fecha_hora'] ?? ''), $timezone);
        } catch (Throwable) {
            throw ValidationException::withMessages(['fecha_hora' => 'La fecha y hora del depósito no es válida.']);
        }
        $collectionDate = $localDateTime->format('Y-m-d');

        $details = [];
        $detailTotal = '0.00';
        foreach (($data['detalles'] ?? []) as $index => $detail) {
            $clientId = (int) ($detail['cliente_id'] ?? 0);
            if ($clientId <= 0) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.cliente_id" => 'Selecciona un cliente válido.',
                ]);
            }
            try {
                $amount = FinancialMoney::normalize($detail['importe'] ?? '');
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.importe" => 'El importe del cliente no es válido.',
                ]);
            }
            if (FinancialMoney::compare($amount, '0.00') <= 0) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.importe" => 'El importe del cliente debe ser mayor que cero.',
                ]);
            }

            $receiptDate = trim((string) ($detail['fecha_recepcion'] ?? ''));
            try {
                $parsedReceiptDate = CarbonImmutable::createFromFormat('!Y-m-d', $receiptDate, $timezone);
            } catch (Throwable) {
                $parsedReceiptDate = false;
            }
            if (! $parsedReceiptDate || $parsedReceiptDate->format('Y-m-d') !== $receiptDate) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.fecha_recepcion" => 'La fecha de recepción debe tener el formato YYYY-MM-DD.',
                ]);
            }
            if ($receiptDate > $collectionDate) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.fecha_recepcion" => 'La recepción del efectivo no puede ser posterior al depósito.',
                ]);
            }

            $details[] = [
                'cliente_id' => $clientId,
                'fecha_recepcion' => $receiptDate,
                'importe' => $amount,
            ];
            $detailTotal = FinancialMoney::add($detailTotal, $amount);
        }
        if ($details === []) {
            throw ValidationException::withMessages([
                'detalles' => 'Agrega al menos un abono de cliente.',
            ]);
        }
        if (FinancialMoney::compare($detailTotal, $total) > 0) {
            throw ValidationException::withMessages([
                'importe_total' => "La suma de clientes ({$detailTotal}) no puede superar el total del voucher ({$total}).",
            ]);
        }
        $pendingAmount = FinancialMoney::subtract($total, $detailTotal);

        $reference = trim((string) ($data['referencia'] ?? ''));
        if ($reference === '') {
            throw ValidationException::withMessages([
                'referencia' => 'Ingresa el número de operación o referencia del voucher.',
            ]);
        }

        return [
            'idempotency_key' => strtolower((string) $data['idempotency_key']),
            'cobrador_id' => (int) ($data['cobrador_id'] ?? 0),
            'fecha_hora' => $localDateTime
                ->setTimezone($this->databaseTimezone())
                ->format('Y-m-d H:i:s'),
            'cuenta_destino_id' => (int) ($data['cuenta_destino_id'] ?? 0),
            'moneda' => strtoupper(trim((string) ($data['moneda'] ?? 'PEN'))),
            'importe_total' => $total,
            'importe_asignado' => $detailTotal,
            'importe_pendiente' => $pendingAmount,
            'referencia' => $reference,
            'observaciones' => isset($data['observaciones']) && trim((string) $data['observaciones']) !== ''
                ? trim((string) $data['observaciones'])
                : null,
            'detalles' => $details,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode([
            'cobrador_id' => $payload['cobrador_id'],
            'fecha_hora' => $payload['fecha_hora'],
            'cuenta_destino_id' => $payload['cuenta_destino_id'],
            'moneda' => $payload['moneda'],
            'importe_total' => $payload['importe_total'],
            'referencia' => $payload['referencia'],
            'observaciones' => $payload['observaciones'],
            'detalles' => $payload['detalles'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertSameIdempotentRequest(object $collection, string $payloadHash): void
    {
        if (! hash_equals((string) $collection->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con una cobranza diferente.',
            ]);
        }
    }

    private function detailIdempotencyKey(string $collectionKey, int $order): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "sistema-pollos:cobranza:{$collectionKey}:detalle:{$order}",
        )->toString();
    }

    private function pendingIdempotencyKey(string $collectionKey): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "sistema-pollos:cobranza:{$collectionKey}:pendiente-identificar",
        )->toString();
    }

    private function movementNotes(string $code, string $collector, ?string $notes): string
    {
        return mb_substr(implode(' ', array_filter([
            "Cobranza {$code}. Efectivo recibido por {$collector}.",
            $notes,
        ])), 0, 2000);
    }

    private function pendingMovementNotes(string $code, string $collector, ?string $notes): string
    {
        return mb_substr(implode(' ', array_filter([
            "Cobranza {$code}. Importe del voucher pendiente de identificar. Efectivo recibido por {$collector}.",
            $notes,
        ])), 0, 2000);
    }

    private function collectionCode(int $id): string
    {
        return 'COB-'.str_pad((string) $id, 10, '0', STR_PAD_LEFT);
    }

    private function assertActor(int $companyId, User $actor, string $permission): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId
                && $actor->isActive()
                && $actor->hasPermission($permission),
            403,
            "Se requiere el permiso {$permission}.",
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
}
