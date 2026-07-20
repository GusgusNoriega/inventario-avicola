<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\User;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinancialMovementService
{
    public function __construct(
        private readonly FinancialAuditService $audit,
        private readonly FinancialAccountBalanceService $balances,
    ) {}

    /**
     * Register one immutable financial movement and apply it to receivables/payables.
     *
     * @param  array<string, mixed>  $data
     * @return array{pago_id: int, idempotent: bool}
     */
    public function register(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip = null,
    ): array {
        $data = $this->normalizePayload($data);
        $this->assertActor($companyId, $actor, $data['tipo']);

        try {
            return DB::transaction(
                fn (): array => $this->registerInTransaction($companyId, $actor, $data, $ip),
                3
            );
        } catch (QueryException $exception) {
            // A concurrent request may have won the unique idempotency-key race.
            return DB::transaction(function () use ($companyId, $data, $exception): array {
                $existing = DB::table('pagos')
                    ->where('empresa_id', $companyId)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if (! $existing) {
                    throw $exception;
                }

                $this->assertSameIdempotentRequest($existing, $data);

                return ['pago_id' => (int) $existing->id, 'idempotent' => true];
            }, 3);
        }
    }

    /**
     * Apply an available provider-credit source to payables.
     * Any original cash movement remains immutable; only its accounting allocation changes.
     *
     * @param  array<string, mixed>  $data
     * @return array{pago_id: int, operacion_id: int, idempotent: bool}
     */
    public function applyProviderPayment(
        int $companyId,
        User $actor,
        int $paymentId,
        array $data,
        ?string $ip = null,
    ): array {
        abort_unless(
            (int) $actor->empresa_id === $companyId && $actor->isActive(),
            403,
            'Usuario no autorizado para esta empresa.'
        );
        $data = $this->normalizeProviderApplicationPayload($data);

        try {
            return DB::transaction(function () use (
                $companyId,
                $actor,
                $paymentId,
                $data,
                $ip,
            ): array {
                $payment = DB::table('pagos')
                    ->where('empresa_id', $companyId)
                    ->where('id', $paymentId)
                    ->lockForUpdate()
                    ->first();
                abort_unless($payment, 404, 'Movimiento financiero no encontrado.');

                $payloadHash = $this->providerApplicationPayloadHash($paymentId, $data);
                $existingOperation = DB::table('pago_aplicacion_operaciones')
                    ->where('empresa_id', $companyId)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existingOperation) {
                    $this->assertSameProviderApplicationOperation(
                        $existingOperation,
                        $paymentId,
                        $payloadHash,
                    );

                    return [
                        'pago_id' => $paymentId,
                        'operacion_id' => (int) $existingOperation->id,
                        'idempotent' => true,
                    ];
                }

                if (! in_array($payment->tipo, Pago::PROVIDER_CREDIT_SOURCE_TYPES, true)) {
                    throw ValidationException::withMessages([
                        'movimiento' => 'Solo los pagos o saldos a favor registrados para un proveedor admiten una aplicación posterior.',
                    ]);
                }
                if ($payment->estado !== 'REGISTRADO' || $payment->reversa_de_pago_id !== null) {
                    throw ValidationException::withMessages([
                        'movimiento' => 'El movimiento está anulado, es una reversa o ya no admite aplicaciones.',
                    ]);
                }
                if ($payment->proveedor_id === null) {
                    throw ValidationException::withMessages([
                        'movimiento' => 'El pago no tiene un proveedor asociado.',
                    ]);
                }

                $currentApplications = DB::table('pago_aplicaciones')
                    ->where('pago_id', $paymentId)
                    ->where('lado', 'CXP')
                    ->orderBy('comprobante_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('comprobante_id');
                $currentlyApplied = $currentApplications->reduce(
                    fn (string $sum, object $application): string => FinancialMoney::add(
                        $sum,
                        (string) $application->importe_aplicado,
                    ),
                    '0.00',
                );
                $available = FinancialMoney::subtract(
                    (string) $payment->importe,
                    $currentlyApplied,
                );
                if (FinancialMoney::compare($available, '0.00') <= 0) {
                    throw ValidationException::withMessages([
                        'aplicaciones' => 'Esta fuente de saldo ya está aplicada por completo.',
                    ]);
                }

                $requestedTotal = collect($data['aplicaciones'])->reduce(
                    fn (string $sum, array $application): string => FinancialMoney::add(
                        $sum,
                        $application['importe_aplicado'],
                    ),
                    '0.00',
                );
                if (FinancialMoney::compare($requestedTotal, $available) > 0) {
                    throw ValidationException::withMessages([
                        'aplicaciones' => "El importe seleccionado supera el saldo disponible de la fuente ({$available} {$payment->moneda}).",
                    ]);
                }

                $documentIds = collect($data['aplicaciones'])
                    ->pluck('comprobante_id')
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->values();
                $documents = DB::table('comprobantes')
                    ->where('empresa_id', $companyId)
                    ->whereIn('id', $documentIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                if ($documents->count() !== $documentIds->count()) {
                    throw ValidationException::withMessages([
                        'aplicaciones' => 'Una o más deudas no existen o pertenecen a otra empresa.',
                    ]);
                }

                $before = [
                    'importe' => FinancialMoney::normalize((string) $payment->importe),
                    'importe_aplicado' => $currentlyApplied,
                    'importe_sin_aplicar' => $available,
                    'aplicaciones' => $this->applicationSnapshot($currentApplications),
                ];
                $now = now();

                foreach ($data['aplicaciones'] as $index => $application) {
                    $document = $documents->get((int) $application['comprobante_id']);
                    $amount = $application['importe_aplicado'];

                    if (! in_array($document->estado, ['PENDIENTE', 'PARCIAL'], true)
                        || FinancialMoney::compare((string) $document->saldo_pendiente, '0.00') <= 0) {
                        throw ValidationException::withMessages([
                            "aplicaciones.{$index}.comprobante_id" => 'La deuda todavía no está emitida, fue anulada o ya fue pagada.',
                        ]);
                    }
                    if ($document->operacion !== 'COMPRA' || $document->naturaleza !== 'CARGO') {
                        throw ValidationException::withMessages([
                            "aplicaciones.{$index}.comprobante_id" => 'Solo se puede aplicar el pago a una cuenta por pagar.',
                        ]);
                    }
                    if ((int) $document->tercero_id !== (int) $payment->proveedor_id) {
                        throw ValidationException::withMessages([
                            "aplicaciones.{$index}.comprobante_id" => 'La deuda pertenece a otro proveedor.',
                        ]);
                    }
                    if ($document->moneda !== $payment->moneda) {
                        throw ValidationException::withMessages([
                            "aplicaciones.{$index}.comprobante_id" => 'La moneda de la deuda no coincide con la del pago.',
                        ]);
                    }
                    if (FinancialMoney::compare($amount, (string) $document->saldo_pendiente) > 0) {
                        throw ValidationException::withMessages([
                            "aplicaciones.{$index}.importe_aplicado" => 'El importe excede el saldo pendiente de la deuda.',
                        ]);
                    }

                    $storedApplication = $currentApplications->get((int) $document->id);
                    if ($storedApplication) {
                        DB::table('pago_aplicaciones')
                            ->where('pago_id', $paymentId)
                            ->where('comprobante_id', $document->id)
                            ->update([
                                'importe_aplicado' => FinancialMoney::add(
                                    (string) $storedApplication->importe_aplicado,
                                    $amount,
                                ),
                            ]);
                    } else {
                        DB::table('pago_aplicaciones')->insert([
                            'pago_id' => $paymentId,
                            'comprobante_id' => $document->id,
                            'lado' => 'CXP',
                            'importe_aplicado' => $amount,
                            'created_by' => $actor->id,
                            'created_at' => $now,
                        ]);
                    }

                    $newBalance = FinancialMoney::subtract(
                        (string) $document->saldo_pendiente,
                        $amount,
                    );
                    DB::table('comprobantes')->where('id', $document->id)->update([
                        'saldo_pendiente' => $newBalance,
                        'estado' => FinancialMoney::compare($newBalance, '0.00') === 0
                            ? 'PAGADO'
                            : 'PARCIAL',
                        'updated_at' => $now,
                    ]);
                }

                $operationId = DB::table('pago_aplicacion_operaciones')->insertGetId([
                    'empresa_id' => $companyId,
                    'pago_id' => $paymentId,
                    'idempotency_key' => $data['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'importe_total' => $requestedTotal,
                    'aplicaciones' => json_encode(
                        $data['aplicaciones'],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                    'observaciones' => $data['observaciones'],
                    'created_by' => $actor->id,
                    'created_at' => $now,
                ]);

                $updatedApplications = DB::table('pago_aplicaciones')
                    ->where('pago_id', $paymentId)
                    ->where('lado', 'CXP')
                    ->orderBy('comprobante_id')
                    ->get();
                $updatedApplied = FinancialMoney::add($currentlyApplied, $requestedTotal);
                $after = [
                    'importe' => FinancialMoney::normalize((string) $payment->importe),
                    'importe_aplicado' => $updatedApplied,
                    'importe_sin_aplicar' => FinancialMoney::subtract(
                        (string) $payment->importe,
                        $updatedApplied,
                    ),
                    'aplicaciones' => $this->applicationSnapshot($updatedApplications),
                    'operacion_id' => $operationId,
                    'observaciones' => $data['observaciones'],
                ];
                $this->audit->record(
                    $companyId,
                    $actor->id,
                    'pagos',
                    $paymentId,
                    $payment->tipo === Pago::TYPE_PROVIDER_CREDIT
                        ? 'APLICAR_SALDO_PROVEEDOR'
                        : 'APLICAR_ANTICIPO',
                    $before,
                    $after,
                    $ip,
                );

                return [
                    'pago_id' => $paymentId,
                    'operacion_id' => $operationId,
                    'idempotent' => false,
                ];
            }, 3);
        } catch (QueryException $exception) {
            $operation = DB::table('pago_aplicacion_operaciones')
                ->where('empresa_id', $companyId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if (! $operation) {
                throw $exception;
            }

            $this->assertSameProviderApplicationOperation(
                $operation,
                $paymentId,
                $this->providerApplicationPayloadHash($paymentId, $data),
            );

            return [
                'pago_id' => $paymentId,
                'operacion_id' => (int) $operation->id,
                'idempotent' => true,
            ];
        }
    }

    /**
     * @return array{pago_id: int, reversa_id: int, idempotent: bool}
     */
    public function void(
        int $companyId,
        User $actor,
        int $paymentId,
        string $reason,
        ?string $ip = null,
        ?int $purchaseContextId = null,
    ): array {
        abort_unless(
            (int) $actor->empresa_id === $companyId && $actor->isActive(),
            403,
            'Usuario no autorizado para esta empresa.'
        );

        return DB::transaction(function () use (
            $companyId,
            $actor,
            $paymentId,
            $reason,
            $ip,
            $purchaseContextId,
        ): array {
            $payment = DB::table('pagos')
                ->where('empresa_id', $companyId)
                ->where('id', $paymentId)
                ->lockForUpdate()
                ->first();
            abort_unless($payment, 404, 'Movimiento financiero no encontrado.');

            $cashPurchase = DB::table('compras')
                ->where('empresa_id', $companyId)
                ->where('pago_inicial_id', $paymentId)
                ->lockForUpdate()
                ->first();
            if ($cashPurchase && (int) $cashPurchase->id !== $purchaseContextId) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este pago pertenece a una compra al contado. Anula la compra completa desde el modulo de compras.',
                ]);
            }

            if ($payment->reversa_de_pago_id !== null) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Una reversa no puede anularse. Anula mediante un ajuste autorizado.',
                ]);
            }

            $existingReverse = DB::table('pagos')
                ->where('empresa_id', $companyId)
                ->where('reversa_de_pago_id', $paymentId)
                ->lockForUpdate()
                ->first();
            if ($payment->estado === 'ANULADO' && $existingReverse) {
                return [
                    'pago_id' => $paymentId,
                    'reversa_id' => (int) $existingReverse->id,
                    'idempotent' => true,
                ];
            }
            if ($payment->estado === 'ANULADO') {
                throw ValidationException::withMessages(['movimiento' => 'El movimiento ya fue anulado.']);
            }

            $accountIds = collect([$payment->cuenta_origen_id, $payment->cuenta_destino_id])
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            if ($accountIds->isNotEmpty()) {
                $accounts = DB::table('cuentas_financieras as cuenta')
                    ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
                    ->where('entidad.empresa_id', $companyId)
                    ->whereIn('cuenta.id', $accountIds->all())
                    ->orderBy('cuenta.id')
                    ->lockForUpdate()
                    ->get([
                        'cuenta.id',
                        'cuenta.estado as cuenta_estado',
                        'entidad.tipo as entidad_tipo',
                        'entidad.estado as entidad_estado',
                    ])
                    ->keyBy('id');
                if ($accounts->count() !== $accountIds->count()
                    || $accounts->contains(fn (object $account): bool => $account->cuenta_estado !== 'ACTIVO'
                        || $account->entidad_estado !== 'ACTIVO')) {
                    throw ValidationException::withMessages([
                        'movimiento' => 'Activa las cuentas y entidades involucradas antes de anular este movimiento.',
                    ]);
                }

                $reverseOriginId = $payment->cuenta_destino_id === null
                    ? null
                    : (int) $payment->cuenta_destino_id;
                $reverseOrigin = $reverseOriginId === null ? null : $accounts->get($reverseOriginId);
                if ($reverseOrigin?->entidad_tipo === 'PROPIA') {
                    $available = $this->balances->forAccount($reverseOriginId)['saldo'];
                    if (FinancialMoney::compare($available, (string) $payment->importe) < 0) {
                        throw ValidationException::withMessages([
                            'importe' => "No se puede anular porque el saldo que ingresó ya fue utilizado. Disponible: {$available} {$payment->moneda}.",
                        ]);
                    }
                }
            }

            $applications = DB::table('pago_aplicaciones')
                ->where('pago_id', $paymentId)
                ->orderBy('comprobante_id')
                ->lockForUpdate()
                ->get();

            foreach ($applications as $application) {
                $document = DB::table('comprobantes')
                    ->where('empresa_id', $companyId)
                    ->where('id', $application->comprobante_id)
                    ->lockForUpdate()
                    ->first();
                if (! $document) {
                    throw ValidationException::withMessages([
                        'movimiento' => 'No se pudo restaurar uno de los comprobantes asociados.',
                    ]);
                }

                $newBalance = FinancialMoney::add(
                    (string) $document->saldo_pendiente,
                    (string) $application->importe_aplicado
                );
                if (FinancialMoney::compare($newBalance, (string) $document->total) > 0) {
                    $newBalance = FinancialMoney::normalize((string) $document->total);
                }
                DB::table('comprobantes')->where('id', $document->id)->update([
                    'saldo_pendiente' => $newBalance,
                    'estado' => FinancialMoney::compare($newBalance, (string) $document->total) >= 0
                        ? 'PENDIENTE'
                        : 'PARCIAL',
                    'updated_at' => now(),
                ]);
            }

            $voidedAt = now();
            DB::table('pagos')->where('id', $paymentId)->update([
                'estado' => 'ANULADO',
                'anulada_por' => $actor->id,
                'anulada_at' => $voidedAt,
                'motivo_anulacion' => $reason,
                'updated_at' => $voidedAt,
            ]);

            $reverseId = DB::table('pagos')->insertGetId([
                'empresa_id' => $companyId,
                'tercero_id' => $payment->tercero_id,
                'codigo' => null,
                'tipo' => $payment->tipo,
                'cliente_id' => $payment->cliente_id,
                'proveedor_id' => $payment->proveedor_id,
                'cuenta_origen_id' => $payment->cuenta_destino_id,
                'cuenta_destino_id' => $payment->cuenta_origen_id,
                'metodo_pago_id' => $payment->metodo_pago_id,
                'direccion' => $this->reverseDirection((string) $payment->direccion),
                'fecha_hora' => $voidedAt,
                'metodo' => $payment->metodo,
                'referencia' => mb_substr('REVERSA '.($payment->codigo ?: '#'.$payment->id), 0, 100),
                'moneda' => $payment->moneda,
                'importe' => FinancialMoney::normalize((string) $payment->importe),
                'observaciones' => $reason,
                'estado' => 'REGISTRADO',
                'idempotency_key' => (string) Str::uuid(),
                'reversa_de_pago_id' => $paymentId,
                'anulada_por' => null,
                'anulada_at' => null,
                'motivo_anulacion' => null,
                'created_by' => $actor->id,
                'created_at' => $voidedAt,
                'updated_at' => $voidedAt,
            ]);
            $reverseCode = $this->movementCode($reverseId);
            DB::table('pagos')->where('id', $reverseId)->update(['codigo' => $reverseCode]);

            $after = (array) $payment;
            $after['estado'] = 'ANULADO';
            $after['anulada_por'] = $actor->id;
            $after['anulada_at'] = $voidedAt->toDateTimeString();
            $after['motivo_anulacion'] = $reason;
            $this->audit->record($companyId, $actor->id, 'pagos', $paymentId, 'ANULAR', (array) $payment, $after, $ip);
            $reverse = (array) DB::table('pagos')->where('id', $reverseId)->first();
            $this->audit->record($companyId, $actor->id, 'pagos', $reverseId, 'REVERSAR', null, $reverse, $ip);

            return ['pago_id' => $paymentId, 'reversa_id' => $reverseId, 'idempotent' => false];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{pago_id: int, idempotent: bool}
     */
    private function registerInTransaction(int $companyId, User $actor, array $data, ?string $ip): array
    {
        $existing = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where('idempotency_key', $data['idempotency_key'])
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $this->assertSameIdempotentRequest($existing, $data);

            return ['pago_id' => (int) $existing->id, 'idempotent' => true];
        }

        $accounts = $this->lockedAccounts($companyId, $data);
        $method = $this->activeMethod($data['metodo_pago_id'] ?? null);
        $this->assertThirdParties($companyId, $data);
        $this->assertFlow($companyId, $data, $accounts, $method);
        $this->assertSufficientBalance($data, $accounts);
        $documents = $this->validateApplications($companyId, $data);

        $now = now();
        $paymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => $data['cliente_id'] ?? $data['proveedor_id'] ?? null,
            'codigo' => null,
            'tipo' => $data['tipo'],
            'cliente_id' => $data['cliente_id'] ?? null,
            'proveedor_id' => $data['proveedor_id'] ?? null,
            'cuenta_origen_id' => $data['cuenta_origen_id'] ?? null,
            'cuenta_destino_id' => $data['cuenta_destino_id'] ?? null,
            'metodo_pago_id' => $data['metodo_pago_id'] ?? null,
            'direccion' => $this->direction($data),
            'fecha_hora' => $data['fecha_hora'],
            'metodo' => $method?->codigo ?? $this->fallbackMethod($data['tipo']),
            'referencia' => $data['referencia'] ?? null,
            'moneda' => $data['moneda'],
            'importe' => $data['importe'],
            'observaciones' => $data['observaciones'] ?? null,
            'estado' => 'REGISTRADO',
            'idempotency_key' => $data['idempotency_key'],
            'reversa_de_pago_id' => null,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
            'created_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $code = $this->movementCode($paymentId);
        DB::table('pagos')->where('id', $paymentId)->update(['codigo' => $code]);

        foreach ($data['aplicaciones'] as $application) {
            $document = $documents->get((int) $application['comprobante_id']);
            $newBalance = FinancialMoney::subtract(
                (string) $document->saldo_pendiente,
                $application['importe_aplicado']
            );

            DB::table('pago_aplicaciones')->insert([
                'pago_id' => $paymentId,
                'comprobante_id' => $document->id,
                'lado' => $application['lado'],
                'importe_aplicado' => $application['importe_aplicado'],
                'created_by' => $actor->id,
                'created_at' => $now,
            ]);
            DB::table('comprobantes')->where('id', $document->id)->update([
                'saldo_pendiente' => $newBalance,
                'estado' => FinancialMoney::compare($newBalance, '0.00') === 0 ? 'PAGADO' : 'PARCIAL',
                'updated_at' => $now,
            ]);
        }

        $payment = (array) DB::table('pagos')->where('id', $paymentId)->first();
        $payment['aplicaciones'] = $data['aplicaciones'];
        $this->audit->record($companyId, $actor->id, 'pagos', $paymentId, 'REGISTRAR', null, $payment, $ip);

        return ['pago_id' => $paymentId, 'idempotent' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, object>
     */
    private function lockedAccounts(int $companyId, array $data): Collection
    {
        $ids = collect([$data['cuenta_origen_id'] ?? null, $data['cuenta_destino_id'] ?? null])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $accounts = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('entidad.empresa_id', $companyId)
            ->whereIn('cuenta.id', $ids->all())
            ->select([
                'cuenta.*',
                'entidad.tipo as entidad_tipo',
                'entidad.proveedor_id as entidad_proveedor_id',
                'entidad.estado as entidad_estado',
            ])
            ->orderBy('cuenta.id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== $ids->count()) {
            throw ValidationException::withMessages(['cuenta' => 'Una cuenta no existe o pertenece a otra empresa.']);
        }
        foreach ($accounts as $account) {
            if ($account->estado !== 'ACTIVO' || $account->entidad_estado !== 'ACTIVO') {
                throw ValidationException::withMessages(['cuenta' => 'No se puede usar una cuenta o entidad inactiva.']);
            }
            if ($account->moneda !== $data['moneda']) {
                throw ValidationException::withMessages(['moneda' => 'La moneda debe coincidir con todas las cuentas del movimiento.']);
            }
        }

        return $accounts;
    }

    private function activeMethod(mixed $methodId): ?object
    {
        if ($methodId === null) {
            return null;
        }

        $method = DB::table('metodos_pago')->where('id', $methodId)->where('estado', 'ACTIVO')->first();
        if (! $method) {
            throw ValidationException::withMessages(['metodo_pago_id' => 'El metodo de pago no existe o esta inactivo.']);
        }

        return $method;
    }

    /** @param array<string, mixed> $data */
    private function assertThirdParties(int $companyId, array $data): void
    {
        foreach (['cliente_id' => 'CLIENTE', 'proveedor_id' => 'PROVEEDOR'] as $field => $role) {
            $id = $data[$field] ?? null;
            if ($id === null) {
                continue;
            }

            $valid = DB::table('terceros as tercero')
                ->join('tercero_roles as tercero_rol', 'tercero_rol.tercero_id', '=', 'tercero.id')
                ->where('tercero.id', $id)
                ->where('tercero.empresa_id', $companyId)
                ->where('tercero.estado', 'ACTIVO')
                ->where('tercero_rol.rol', $role)
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages([$field => "El {$role} no existe, esta inactivo o pertenece a otra empresa."]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, object>  $accounts
     */
    private function assertFlow(int $companyId, array $data, Collection $accounts, ?object $method): void
    {
        $type = $data['tipo'];
        $origin = isset($data['cuenta_origen_id']) ? $accounts->get((int) $data['cuenta_origen_id']) : null;
        $destination = isset($data['cuenta_destino_id']) ? $accounts->get((int) $data['cuenta_destino_id']) : null;
        $hasApplications = $data['aplicaciones'] !== [];

        if ($method?->requiere_referencia && empty($data['referencia'])) {
            throw ValidationException::withMessages(['referencia' => 'El metodo seleccionado requiere una referencia.']);
        }

        switch ($type) {
            case 'COBRO_CLIENTE':
                $this->required($data, ['cliente_id', 'cuenta_destino_id', 'metodo_pago_id']);
                $this->assertEmpty($data, ['proveedor_id', 'cuenta_origen_id']);
                $this->assertOwn($destination, 'cuenta_destino_id');
                $this->assertOnlySides($data, ['CXC']);
                break;

            case 'PAGO_DIRECTO':
                $this->required($data, ['cliente_id', 'proveedor_id', 'cuenta_destino_id', 'metodo_pago_id']);
                $this->assertEmpty($data, ['cuenta_origen_id']);
                $this->assertExternalForProvider($destination, (int) $data['proveedor_id'], 'cuenta_destino_id');
                $this->assertOnlySides($data, ['CXC', 'CXP']);
                if (! $hasApplications
                    || ! collect($data['aplicaciones'])->contains('lado', 'CXC')
                    || ! collect($data['aplicaciones'])->contains('lado', 'CXP')) {
                    throw ValidationException::withMessages([
                        'aplicaciones' => 'Un pago directo debe aplicar al menos una cuenta por cobrar y una cuenta por pagar.',
                    ]);
                }
                foreach (['CXC', 'CXP'] as $side) {
                    $applied = collect($data['aplicaciones'])
                        ->where('lado', $side)
                        ->reduce(
                            fn (string $sum, array $application): string => FinancialMoney::add(
                                $sum,
                                $application['importe_aplicado']
                            ),
                            '0.00'
                        );
                    if (FinancialMoney::compare($applied, $data['importe']) !== 0) {
                        throw ValidationException::withMessages([
                            'aplicaciones' => "Un pago directo debe aplicar el importe completo tanto en {$side} como en la otra cartera.",
                        ]);
                    }
                }
                break;

            case Pago::TYPE_PROVIDER_PAYMENT:
                $this->required($data, ['proveedor_id', 'cuenta_origen_id', 'cuenta_destino_id', 'metodo_pago_id']);
                $this->assertEmpty($data, ['cliente_id']);
                $this->assertOwn($origin, 'cuenta_origen_id');
                $this->assertExternalForProvider($destination, (int) $data['proveedor_id'], 'cuenta_destino_id');
                $this->assertOnlySides($data, ['CXP']);
                break;

            case Pago::TYPE_PROVIDER_CREDIT:
                $this->required($data, ['proveedor_id', 'observaciones']);
                $this->assertEmpty($data, [
                    'cliente_id',
                    'cuenta_origen_id',
                    'cuenta_destino_id',
                    'metodo_pago_id',
                ]);
                $this->assertNoApplications($data);
                break;

            case 'COBRO_MINORISTA':
                $this->required($data, ['cuenta_destino_id', 'metodo_pago_id']);
                $this->assertEmpty($data, ['proveedor_id', 'cuenta_origen_id']);
                $this->assertOwn($destination, 'cuenta_destino_id');
                $this->assertOnlySides($data, ['CXC']);
                break;

            case 'REEMBOLSO_CLIENTE':
                $this->required($data, ['cliente_id', 'cuenta_origen_id', 'metodo_pago_id']);
                $this->assertEmpty($data, ['proveedor_id', 'cuenta_destino_id']);
                $this->assertOwn($origin, 'cuenta_origen_id');
                $this->assertOnlySides($data, ['CXC']);
                $refunded = collect($data['aplicaciones'])
                    ->reduce(
                        fn (string $sum, array $application): string => FinancialMoney::add(
                            $sum,
                            $application['importe_aplicado']
                        ),
                        '0.00'
                    );
                if (! $hasApplications || FinancialMoney::compare($refunded, $data['importe']) !== 0) {
                    throw ValidationException::withMessages([
                        'aplicaciones' => 'El reembolso debe aplicarse completamente a uno o mas abonos del cliente.',
                    ]);
                }
                break;

            case 'SALDO_INICIAL':
                $this->required($data, ['cuenta_destino_id']);
                $this->assertOwn($destination, 'cuenta_destino_id');
                $this->assertEmpty($data, ['cuenta_origen_id', 'cliente_id', 'proveedor_id']);
                $this->assertNoApplications($data);
                if (DB::table('pagos')
                    ->where('empresa_id', $companyId)
                    ->where('tipo', 'SALDO_INICIAL')
                    ->where('cuenta_destino_id', $data['cuenta_destino_id'])
                    ->whereNull('reversa_de_pago_id')
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'cuenta_destino_id' => 'Esta cuenta ya tiene un saldo inicial registrado.',
                    ]);
                }
                break;

            case 'AJUSTE':
                $this->assertEmpty($data, ['cliente_id', 'proveedor_id']);
                $this->assertNoApplications($data);
                if (($origin === null) === ($destination === null)) {
                    throw ValidationException::withMessages([
                        'cuenta' => 'Un ajuste debe indicar solo una cuenta origen o solo una cuenta destino.',
                    ]);
                }
                $this->assertOwn($origin ?? $destination, $origin ? 'cuenta_origen_id' : 'cuenta_destino_id');
                break;

            case 'TRANSFERENCIA_INTERNA':
                $this->required($data, ['cuenta_origen_id', 'cuenta_destino_id']);
                $this->assertEmpty($data, ['cliente_id', 'proveedor_id']);
                $this->assertNoApplications($data);
                $this->assertOwn($origin, 'cuenta_origen_id');
                $this->assertOwn($destination, 'cuenta_destino_id');
                if ((int) $data['cuenta_origen_id'] === (int) $data['cuenta_destino_id']) {
                    throw ValidationException::withMessages([
                        'cuenta_destino_id' => 'La cuenta destino debe ser diferente de la cuenta origen.',
                    ]);
                }
                break;
        }
    }

    /** @param array<string, mixed> $data @param Collection<int, object> $accounts */
    private function assertSufficientBalance(array $data, Collection $accounts): void
    {
        $originId = $data['cuenta_origen_id'] ?? null;
        if ($originId === null) {
            return;
        }

        $origin = $accounts->get((int) $originId);
        if (! $origin || $origin->entidad_tipo !== 'PROPIA') {
            return;
        }

        $available = $this->balances->forAccount((int) $originId)['saldo'];
        if (FinancialMoney::compare($available, $data['importe']) < 0) {
            throw ValidationException::withMessages([
                'importe' => "Saldo insuficiente en la cuenta origen. Disponible: {$available} {$data['moneda']}.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, object>
     */
    private function validateApplications(int $companyId, array $data): Collection
    {
        $applications = collect($data['aplicaciones']);
        if ($applications->isEmpty()) {
            return collect();
        }

        $ids = $applications->pluck('comprobante_id')->map(fn ($id): int => (int) $id);
        if ($ids->unique()->count() !== $ids->count()) {
            throw ValidationException::withMessages(['aplicaciones' => 'Un comprobante no puede aparecer mas de una vez.']);
        }

        $documents = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->whereIn('id', $ids->sort()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($documents->count() !== $ids->count()) {
            throw ValidationException::withMessages(['aplicaciones' => 'Uno o mas comprobantes no existen o pertenecen a otra empresa.']);
        }

        $sums = ['CXC' => '0.00', 'CXP' => '0.00'];
        foreach ($applications as $index => $application) {
            $document = $documents->get((int) $application['comprobante_id']);
            $side = $application['lado'];
            $amount = $application['importe_aplicado'];

            if (! in_array($document->estado, ['PENDIENTE', 'PARCIAL'], true)) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.comprobante_id" => 'Solo se puede aplicar a un comprobante pendiente o parcialmente pagado.']);
            }
            $expectedNature = $data['tipo'] === 'REEMBOLSO_CLIENTE' ? 'ABONO' : 'CARGO';
            if ($document->naturaleza !== $expectedNature) {
                throw ValidationException::withMessages([
                    "aplicaciones.{$index}.comprobante_id" => $expectedNature === 'ABONO'
                        ? 'Un reembolso solo puede aplicarse a un abono pendiente del cliente.'
                        : 'Este movimiento solo puede aplicarse a comprobantes de naturaleza CARGO.',
                ]);
            }
            $expectedOperation = $side === 'CXC' ? 'VENTA' : 'COMPRA';
            if ($document->operacion !== $expectedOperation) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.lado" => 'El lado no coincide con la operacion del comprobante.']);
            }
            if ($document->moneda !== $data['moneda']) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.comprobante_id" => 'La moneda del comprobante no coincide con el movimiento.']);
            }
            if (FinancialMoney::compare($amount, (string) $document->saldo_pendiente) > 0) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.importe_aplicado" => 'El importe excede el saldo pendiente del comprobante.']);
            }

            $expectedThirdParty = $side === 'CXC' ? ($data['cliente_id'] ?? null) : ($data['proveedor_id'] ?? null);
            if ($side === 'CXC' && $data['tipo'] === 'COBRO_MINORISTA' && $expectedThirdParty === null) {
                if ($document->tercero_id !== null) {
                    throw ValidationException::withMessages(["aplicaciones.{$index}.comprobante_id" => 'El comprobante minorista pertenece a un cliente identificado.']);
                }
            } elseif ($expectedThirdParty === null || (int) $document->tercero_id !== (int) $expectedThirdParty) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.comprobante_id" => 'El comprobante no pertenece al cliente o proveedor del movimiento.']);
            }

            $sums[$side] = FinancialMoney::add($sums[$side], $amount);
        }

        foreach ($sums as $side => $sum) {
            if (FinancialMoney::compare($sum, $data['importe']) > 0) {
                throw ValidationException::withMessages([
                    'aplicaciones' => "La suma aplicada en {$side} no puede exceder el importe del movimiento.",
                ]);
            }
        }

        return $documents;
    }

    /** @param array<string, mixed> $data */
    private function assertActor(int $companyId, User $actor, string $type): void
    {
        abort_unless((int) $actor->empresa_id === $companyId && $actor->isActive(), 403, 'Usuario no autorizado para esta empresa.');
        if (in_array($type, ['SALDO_INICIAL', 'AJUSTE', Pago::TYPE_PROVIDER_CREDIT], true)) {
            abort_unless($actor->hasPermission('SALDOS_AJUSTAR'), 403, 'Se requiere el permiso SALDOS_AJUSTAR.');
        }
    }

    /** @param array<string, mixed> $data */
    private function normalizePayload(array $data): array
    {
        $type = strtoupper(trim((string) ($data['tipo'] ?? '')));
        if (! in_array($type, Pago::TYPES, true)) {
            throw ValidationException::withMessages(['tipo' => 'El tipo de movimiento no es valido.']);
        }
        if (empty($data['idempotency_key']) || ! Str::isUuid((string) $data['idempotency_key'])) {
            throw ValidationException::withMessages(['idempotency_key' => 'Debe enviarse una clave UUID de idempotencia valida.']);
        }

        try {
            $amount = FinancialMoney::normalize($data['importe'] ?? '');
        } catch (Throwable) {
            throw ValidationException::withMessages(['importe' => 'El importe no es valido.']);
        }
        if (FinancialMoney::compare($amount, '0.00') <= 0) {
            throw ValidationException::withMessages(['importe' => 'El importe debe ser mayor que cero.']);
        }

        $applications = [];
        foreach (($data['aplicaciones'] ?? []) as $index => $application) {
            try {
                $applicationAmount = FinancialMoney::normalize($application['importe_aplicado'] ?? $application['importe'] ?? '');
            } catch (Throwable) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.importe_aplicado" => 'El importe aplicado no es valido.']);
            }
            if (FinancialMoney::compare($applicationAmount, '0.00') <= 0) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.importe_aplicado" => 'El importe aplicado debe ser mayor que cero.']);
            }
            $applications[] = [
                'lado' => strtoupper(trim((string) ($application['lado'] ?? ''))),
                'comprobante_id' => (int) ($application['comprobante_id'] ?? 0),
                'importe_aplicado' => $applicationAmount,
            ];
        }

        $hasExplicitDate = isset($data['fecha_hora']) && trim((string) $data['fecha_hora']) !== '';

        return [
            ...$data,
            'idempotency_key' => strtolower((string) $data['idempotency_key']),
            'tipo' => $type,
            'fecha_hora' => CarbonImmutable::parse($data['fecha_hora'] ?? now())->toDateTimeString(),
            '_fecha_hora_explicit' => $hasExplicitDate,
            'moneda' => strtoupper(trim((string) ($data['moneda'] ?? 'PEN'))),
            'importe' => $amount,
            'referencia' => isset($data['referencia']) && trim((string) $data['referencia']) !== '' ? trim((string) $data['referencia']) : null,
            'observaciones' => isset($data['observaciones']) && trim((string) $data['observaciones']) !== '' ? trim((string) $data['observaciones']) : null,
            'aplicaciones' => $applications,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeProviderApplicationPayload(array $data): array
    {
        $key = strtolower(trim((string) ($data['idempotency_key'] ?? '')));
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Debe enviarse una clave UUID de idempotencia válida.',
            ]);
        }

        $applications = [];
        foreach (($data['aplicaciones'] ?? []) as $index => $application) {
            $documentId = (int) ($application['comprobante_id'] ?? 0);
            if ($documentId <= 0) {
                throw ValidationException::withMessages([
                    "aplicaciones.{$index}.comprobante_id" => 'El comprobante no es válido.',
                ]);
            }

            try {
                $amount = FinancialMoney::normalize(
                    $application['importe_aplicado'] ?? $application['importe'] ?? '',
                );
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    "aplicaciones.{$index}.importe_aplicado" => 'El importe aplicado no es válido.',
                ]);
            }
            if (FinancialMoney::compare($amount, '0.00') <= 0) {
                throw ValidationException::withMessages([
                    "aplicaciones.{$index}.importe_aplicado" => 'El importe aplicado debe ser mayor que cero.',
                ]);
            }

            $applications[] = [
                'comprobante_id' => $documentId,
                'importe_aplicado' => $amount,
            ];
        }
        if ($applications === []) {
            throw ValidationException::withMessages([
                'aplicaciones' => 'Selecciona al menos una deuda para aplicar el pago.',
            ]);
        }
        if (collect($applications)->pluck('comprobante_id')->unique()->count() !== count($applications)) {
            throw ValidationException::withMessages([
                'aplicaciones' => 'Una deuda no puede aparecer más de una vez.',
            ]);
        }

        return [
            'idempotency_key' => $key,
            'aplicaciones' => collect($applications)->sortBy('comprobante_id')->values()->all(),
            'observaciones' => isset($data['observaciones'])
                && trim((string) $data['observaciones']) !== ''
                    ? trim((string) $data['observaciones'])
                    : null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function providerApplicationPayloadHash(int $paymentId, array $data): string
    {
        return hash('sha256', json_encode([
            'pago_id' => $paymentId,
            'aplicaciones' => $data['aplicaciones'],
            'observaciones' => $data['observaciones'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertSameProviderApplicationOperation(
        object $operation,
        int $paymentId,
        string $payloadHash,
    ): void {
        if ((int) $operation->pago_id !== $paymentId
            || ! hash_equals((string) $operation->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con una aplicación diferente.',
            ]);
        }
    }

    /** @param Collection<int|string, object> $applications @return list<array<string, mixed>> */
    private function applicationSnapshot(Collection $applications): array
    {
        return $applications->map(fn (object $application): array => [
            'lado' => $application->lado,
            'comprobante_id' => (int) $application->comprobante_id,
            'importe_aplicado' => FinancialMoney::normalize(
                (string) $application->importe_aplicado,
            ),
        ])->values()->all();
    }

    /** @param array<string, mixed> $data */
    private function assertSameIdempotentRequest(object $payment, array $data): void
    {
        $same = $payment->tipo === $data['tipo']
            && (int) ($payment->cliente_id ?? 0) === (int) ($data['cliente_id'] ?? 0)
            && (int) ($payment->proveedor_id ?? 0) === (int) ($data['proveedor_id'] ?? 0)
            && (int) ($payment->cuenta_origen_id ?? 0) === (int) ($data['cuenta_origen_id'] ?? 0)
            && (int) ($payment->cuenta_destino_id ?? 0) === (int) ($data['cuenta_destino_id'] ?? 0)
            && (int) ($payment->metodo_pago_id ?? 0) === (int) ($data['metodo_pago_id'] ?? 0)
            && $payment->moneda === $data['moneda']
            && FinancialMoney::compare((string) $payment->importe, $data['importe']) === 0
            && ($data['_fecha_hora_explicit'] === false
                || CarbonImmutable::parse($payment->fecha_hora)->toDateTimeString() === $data['fecha_hora'])
            && ($payment->referencia ?? null) === $data['referencia']
            && ($payment->observaciones ?? null) === $data['observaciones'];

        $posteriorAmounts = [];
        DB::table('pago_aplicacion_operaciones')
            ->where('pago_id', $payment->id)
            ->orderBy('id')
            ->pluck('aplicaciones')
            ->each(function (string $encodedApplications) use (&$posteriorAmounts): void {
                foreach (json_decode($encodedApplications, true, 512, JSON_THROW_ON_ERROR) as $application) {
                    $key = 'CXP:'.(int) $application['comprobante_id'];
                    $posteriorAmounts[$key] = FinancialMoney::add(
                        $posteriorAmounts[$key] ?? '0.00',
                        (string) $application['importe_aplicado'],
                    );
                }
            });

        $storedApplications = DB::table('pago_aplicaciones')
            ->where('pago_id', $payment->id)
            ->orderBy('comprobante_id')
            ->get(['lado', 'comprobante_id', 'importe_aplicado'])
            ->map(function (object $application) use ($posteriorAmounts): array {
                $key = $application->lado.':'.(int) $application->comprobante_id;

                return [
                    'lado' => $application->lado,
                    'comprobante_id' => (int) $application->comprobante_id,
                    'importe_aplicado' => FinancialMoney::subtract(
                        (string) $application->importe_aplicado,
                        $posteriorAmounts[$key] ?? '0.00',
                    ),
                ];
            })
            ->filter(fn (array $application): bool => FinancialMoney::compare(
                $application['importe_aplicado'],
                '0.00',
            ) > 0)
            ->values()
            ->all();
        $requestedApplications = collect($data['aplicaciones'])->sortBy('comprobante_id')->values()->all();

        if (! $same || $storedApplications !== $requestedApplications) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con un movimiento diferente.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function direction(array $data): string
    {
        if ($data['tipo'] === Pago::TYPE_PROVIDER_CREDIT) {
            return Pago::DIRECTION_NO_FLOW;
        }
        if ($data['tipo'] === 'PAGO_DIRECTO') {
            return 'DIRECTO';
        }
        if ($data['tipo'] === 'TRANSFERENCIA_INTERNA') {
            return 'TRANSFERENCIA';
        }
        if (in_array($data['tipo'], ['PAGO_PROVEEDOR', 'REEMBOLSO_CLIENTE'], true)) {
            return 'EGRESO';
        }
        if (in_array($data['tipo'], ['COBRO_CLIENTE', 'COBRO_MINORISTA', 'SALDO_INICIAL'], true)) {
            return 'INGRESO';
        }

        return ! empty($data['cuenta_destino_id']) ? 'INGRESO' : 'EGRESO';
    }

    private function reverseDirection(string $direction): string
    {
        return match ($direction) {
            'INGRESO' => 'EGRESO',
            'EGRESO' => 'INGRESO',
            default => 'REVERSA',
        };
    }

    private function fallbackMethod(string $type): string
    {
        return match ($type) {
            'SALDO_INICIAL' => 'SALDO_INICIAL',
            Pago::TYPE_PROVIDER_CREDIT => 'SALDO_MANUAL',
            'TRANSFERENCIA_INTERNA' => 'TRANSFERENCIA',
            default => 'AJUSTE',
        };
    }

    private function movementCode(int $id): string
    {
        return 'MOV-'.str_pad((string) $id, 10, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data @param list<string> $fields */
    private function required(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                throw ValidationException::withMessages([$field => 'Este campo es obligatorio para el tipo de movimiento.']);
            }
        }
    }

    /** @param array<string, mixed> $data @param list<string> $fields */
    private function assertEmpty(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (! empty($data[$field])) {
                throw ValidationException::withMessages([$field => 'Este campo no corresponde al tipo de movimiento.']);
            }
        }
    }

    private function assertOwn(?object $account, string $field): void
    {
        if (! $account || $account->entidad_tipo !== 'PROPIA') {
            throw ValidationException::withMessages([$field => 'Debe seleccionar una cuenta de una entidad propia.']);
        }
    }

    private function assertExternalForProvider(?object $account, int $providerId, string $field): void
    {
        if (! $account || $account->entidad_tipo !== 'EXTERNA' || (int) $account->entidad_proveedor_id !== $providerId) {
            throw ValidationException::withMessages([$field => 'Debe seleccionar una cuenta externa vinculada al proveedor.']);
        }
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function assertOnlySides(array $data, array $allowed): void
    {
        foreach ($data['aplicaciones'] as $index => $application) {
            if (! in_array($application['lado'], $allowed, true)) {
                throw ValidationException::withMessages(["aplicaciones.{$index}.lado" => 'El lado no corresponde al tipo de movimiento.']);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function assertNoApplications(array $data): void
    {
        if ($data['aplicaciones'] !== []) {
            throw ValidationException::withMessages(['aplicaciones' => 'Este tipo de movimiento no admite aplicaciones.']);
        }
    }
}
