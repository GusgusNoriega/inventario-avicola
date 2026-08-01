<?php

namespace App\Services;

use App\Models\Cobrador;
use App\Models\Cobranza;
use App\Models\CobranzaDetalle;
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

            if ($collection->estado === Cobranza::STATUS_VOIDED) {
                $reverseIds = DB::table('pagos')
                    ->whereIn('reversa_de_pago_id', $details->pluck('pago_id')->all())
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
            if ($details->isEmpty()) {
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

        $collection = (array) DB::table('cobranzas')->where('id', $collectionId)->first();
        $collection['detalles'] = $registeredDetails;
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

        $method = DB::table('metodos_pago')
            ->where('codigo', MetodoPago::CODE_DEPOSIT)
            ->where('estado', MetodoPago::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
        if (! $method) {
            throw ValidationException::withMessages([
                'metodo_pago' => 'El método de pago DEPÓSITO no está disponible.',
            ]);
        }

        return [
            'cobrador' => $collector,
            'cuenta' => $account,
            'proveedor_id' => $providerId,
            'metodo' => $method,
        ];
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
        if (FinancialMoney::compare($detailTotal, $total) !== 0) {
            throw ValidationException::withMessages([
                'importe_total' => "El total del voucher ({$total}) debe coincidir exactamente con la suma de clientes ({$detailTotal}).",
            ]);
        }

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

    private function movementNotes(string $code, string $collector, ?string $notes): string
    {
        return mb_substr(implode(' ', array_filter([
            "Cobranza {$code}. Efectivo recibido por {$collector}.",
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
