<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\User;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private readonly FinancialMovementService $financialMovements,
        private readonly FinancialAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{compra_id: int, idempotent: bool}
     */
    public function register(int $companyId, User $actor, array $data, ?string $ip = null): array
    {
        $this->assertActor($companyId, $actor, 'COMPRAS_REGISTRAR');
        $data = $this->canonicalPayload($data);

        if ($data['condicion'] === Compra::CONDITION_CASH) {
            $this->assertPermission($actor, 'PAGOS_REGISTRAR');
        }

        try {
            return DB::transaction(
                fn (): array => $this->registerInTransaction($companyId, $actor, $data, $ip),
                3
            );
        } catch (QueryException $exception) {
            $existing = DB::table('compras')
                ->where('empresa_id', $companyId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                $this->assertSameIdempotentRequest($existing, $data);

                return ['compra_id' => (int) $existing->id, 'idempotent' => true];
            }

            if ($data['numero_documento'] !== null && DB::table('compras')
                ->where('empresa_id', $companyId)
                ->where('proveedor_id', $data['proveedor_id'])
                ->where('tipo_documento', $data['tipo_documento'])
                ->where('numero_documento_activo', $data['numero_documento'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'numero_documento' => 'Ya existe una compra de este proveedor con el mismo tipo y numero de documento.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @return array{compra_id: int, idempotent: bool, reversa_id: ?int}
     */
    public function void(
        int $companyId,
        User $actor,
        int $purchaseId,
        string $reason,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor, 'COMPRAS_ANULAR');

        return DB::transaction(function () use ($companyId, $actor, $purchaseId, $reason, $ip): array {
            $purchase = DB::table('compras')
                ->where('empresa_id', $companyId)
                ->where('id', $purchaseId)
                ->lockForUpdate()
                ->first();
            abort_unless($purchase, 404, 'Compra no encontrada.');

            if ($purchase->estado === Compra::STATUS_VOIDED) {
                return [
                    'compra_id' => $purchaseId,
                    'idempotent' => true,
                    'reversa_id' => $this->initialPaymentReverseId($companyId, $purchase),
                ];
            }

            if ($purchase->condicion === Compra::CONDITION_LEGACY) {
                throw ValidationException::withMessages([
                    'compra' => 'Una compra historica conserva su comprobante original y no puede anularse desde el modulo de compras.',
                ]);
            }

            $document = DB::table('comprobantes')
                ->where('empresa_id', $companyId)
                ->where('id', $purchase->comprobante_id)
                ->lockForUpdate()
                ->first();
            if (! $document
                || $document->operacion !== 'COMPRA'
                || $document->origen_clave !== "COMPRA:REGISTRO:{$purchase->id}") {
                throw ValidationException::withMessages([
                    'compra' => 'La obligacion financiera de la compra no esta disponible.',
                ]);
            }

            $activePaymentIds = $this->activeApplicationPaymentIds((int) $document->id);
            $reverseId = null;

            if ($purchase->condicion === Compra::CONDITION_CASH) {
                $this->assertPermission($actor, 'PAGOS_ANULAR');
                if ($purchase->pago_inicial_id === null) {
                    throw ValidationException::withMessages([
                        'compra' => 'La compra al contado no tiene un pago inicial asociado.',
                    ]);
                }

                $unexpectedPayments = $activePaymentIds->reject(
                    fn (int $paymentId): bool => $paymentId === (int) $purchase->pago_inicial_id
                );
                if ($unexpectedPayments->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'compra' => 'La compra tiene movimientos adicionales. Anulalos antes de anular la compra.',
                    ]);
                }

                $result = $this->financialMovements->void(
                    $companyId,
                    $actor,
                    (int) $purchase->pago_inicial_id,
                    "Anulacion de compra {$purchase->codigo}: {$reason}",
                    $ip,
                    (int) $purchase->id,
                );
                $reverseId = $result['reversa_id'];
            } elseif ($activePaymentIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'compra' => 'La compra tiene pagos aplicados. Anula primero los movimientos financieros relacionados.',
                ]);
            }

            if ($this->activeApplicationPaymentIds((int) $document->id)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'compra' => 'No se pudieron liberar todas las aplicaciones financieras de la compra.',
                ]);
            }

            $voidedAt = now();
            $documentAfter = [
                'estado' => 'ANULADO',
                'anulada_por' => $actor->id,
                'anulada_at' => $voidedAt,
                'motivo_anulacion' => $reason,
                'updated_at' => $voidedAt,
            ];
            DB::table('comprobantes')->where('id', $document->id)->update($documentAfter);

            $purchaseAfter = [
                'estado' => Compra::STATUS_VOIDED,
                'numero_documento_activo' => null,
                'anulada_por' => $actor->id,
                'anulada_at' => $voidedAt,
                'motivo_anulacion' => $reason,
                'updated_at' => $voidedAt,
            ];
            DB::table('compras')->where('id', $purchase->id)->update($purchaseAfter);

            $this->audit->record(
                $companyId,
                $actor->id,
                'comprobantes',
                (int) $document->id,
                'ANULAR_COMPRA',
                (array) $document,
                [...(array) $document, ...$documentAfter],
                $ip,
            );
            $this->audit->record(
                $companyId,
                $actor->id,
                'compras',
                (int) $purchase->id,
                'ANULAR',
                (array) $purchase,
                [...(array) $purchase, ...$purchaseAfter],
                $ip,
            );

            return [
                'compra_id' => (int) $purchase->id,
                'idempotent' => false,
                'reversa_id' => $reverseId,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{compra_id: int, idempotent: bool}
     */
    private function registerInTransaction(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip,
    ): array {
        $existing = DB::table('compras')
            ->where('empresa_id', $companyId)
            ->where('idempotency_key', $data['idempotency_key'])
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $this->assertSameIdempotentRequest($existing, $data);

            return ['compra_id' => (int) $existing->id, 'idempotent' => true];
        }

        $provider = $this->lockedProvider($companyId, (int) $data['proveedor_id']);
        $this->assertChickenTypes($data['detalles']);
        $this->assertDocumentNumberAvailable($companyId, $data);

        $now = now();
        $purchaseId = (int) DB::table('compras')->insertGetId([
            'empresa_id' => $companyId,
            'proveedor_id' => $provider->id,
            'comprobante_id' => null,
            'pago_inicial_id' => null,
            'codigo' => null,
            'idempotency_key' => $data['idempotency_key'],
            'tipo_documento' => $data['tipo_documento'],
            'numero_documento' => $data['numero_documento'],
            'numero_documento_activo' => $data['numero_documento'],
            'fecha_compra' => $data['fecha_compra'],
            'fecha_vencimiento' => $data['fecha_vencimiento'],
            'condicion' => $data['condicion'],
            'moneda' => $data['moneda'],
            'subtotal' => $data['subtotal'],
            'impuesto' => $data['impuesto'],
            'total' => $data['total'],
            'estado' => Compra::STATUS_REGISTERED,
            'observaciones' => $data['observaciones'],
            'created_by' => $actor->id,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $code = $this->purchaseCode($purchaseId);
        DB::table('compras')->where('id', $purchaseId)->update(['codigo' => $code]);

        foreach ($data['detalles'] as $detail) {
            DB::table('compra_detalles')->insert([
                'compra_id' => $purchaseId,
                ...$detail,
                'created_at' => $now,
            ]);
        }

        $documentId = $this->createPayable(
            $companyId,
            $purchaseId,
            $code,
            $provider,
            $actor,
            $data,
            $ip,
        );
        DB::table('compras')->where('id', $purchaseId)->update([
            'comprobante_id' => $documentId,
            'updated_at' => now(),
        ]);

        $initialPaymentId = null;
        if ($data['condicion'] === Compra::CONDITION_CASH) {
            $payment = $data['pago'];
            $result = $this->financialMovements->register($companyId, $actor, [
                'idempotency_key' => $data['idempotency_key'],
                'tipo' => 'PAGO_PROVEEDOR',
                'fecha_hora' => $payment['fecha_hora'] ?? null,
                'proveedor_id' => (int) $provider->id,
                'cuenta_origen_id' => $payment['cuenta_origen_id'],
                'cuenta_destino_id' => $payment['cuenta_destino_id'],
                'metodo_pago_id' => $payment['metodo_pago_id'],
                'moneda' => $data['moneda'],
                'importe' => $data['total'],
                'referencia' => $payment['referencia'],
                'observaciones' => $payment['observaciones'],
                'aplicaciones' => [[
                    'lado' => 'CXP',
                    'comprobante_id' => $documentId,
                    'importe_aplicado' => $data['total'],
                ]],
            ], $ip);
            $initialPaymentId = $result['pago_id'];
            DB::table('compras')->where('id', $purchaseId)->update([
                'pago_inicial_id' => $initialPaymentId,
                'updated_at' => now(),
            ]);
        }

        $purchase = (array) DB::table('compras')->where('id', $purchaseId)->first();
        $purchase['detalles'] = $data['detalles'];
        $this->audit->record(
            $companyId,
            $actor->id,
            'compras',
            $purchaseId,
            'REGISTRAR',
            null,
            $purchase,
            $ip,
        );

        return ['compra_id' => $purchaseId, 'idempotent' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPayable(
        int $companyId,
        int $purchaseId,
        string $purchaseCode,
        object $provider,
        User $actor,
        array $data,
        ?string $ip,
    ): int {
        $now = now();
        $values = [
            'empresa_id' => $companyId,
            'tercero_id' => $provider->id,
            'operacion' => 'COMPRA',
            'naturaleza' => 'CARGO',
            'tipo_documento' => $data['tipo_documento'],
            'codigo' => 'CXP-'.$purchaseCode,
            'origen_codigo' => 'COMPRAS',
            'origen_clave' => "COMPRA:REGISTRO:{$purchaseId}",
            'fecha_emision' => $data['fecha_compra'],
            'fecha_vencimiento' => $data['fecha_vencimiento'],
            'moneda' => $data['moneda'],
            'subtotal' => $data['subtotal'],
            'impuesto' => $data['impuesto'],
            'total' => $data['total'],
            'saldo_pendiente' => $data['total'],
            'estado' => 'PENDIENTE',
            'contraparte_tipo_documento_snapshot' => $provider->tipo_documento,
            'contraparte_numero_documento_snapshot' => $provider->numero_documento,
            'contraparte_nombre_snapshot' => $provider->nombre_razon_social,
            'contraparte_direccion_snapshot' => $provider->direccion,
            'created_by' => $actor->id,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $documentId = (int) DB::table('comprobantes')->insertGetId($values);

        foreach ($data['detalles'] as $detail) {
            DB::table('comprobante_detalles')->insert([
                'comprobante_id' => $documentId,
                'tipo_pollo_id' => $detail['tipo_pollo_id'],
                'descripcion' => $detail['descripcion'],
                'cantidad_aves' => $detail['cantidad_aves'],
                'peso_neto_kg' => $detail['peso_kg'],
                'precio_kg' => $detail['precio_kg'],
                'subtotal' => $detail['subtotal'],
                'created_at' => $now,
            ]);
        }

        $this->audit->record(
            $companyId,
            $actor->id,
            'comprobantes',
            $documentId,
            'GENERAR_COMPRA',
            null,
            [...$values, 'id' => $documentId],
            $ip,
        );

        return $documentId;
    }

    /** @param array<string, mixed> $data */
    private function assertDocumentNumberAvailable(int $companyId, array $data): void
    {
        if ($data['numero_documento'] === null) {
            return;
        }

        if (DB::table('compras')
            ->where('empresa_id', $companyId)
            ->where('proveedor_id', $data['proveedor_id'])
            ->where('tipo_documento', $data['tipo_documento'])
            ->where('numero_documento_activo', $data['numero_documento'])
            ->exists()) {
            throw ValidationException::withMessages([
                'numero_documento' => 'Ya existe una compra de este proveedor con el mismo tipo y numero de documento.',
            ]);
        }
    }

    private function lockedProvider(int $companyId, int $providerId): object
    {
        $provider = DB::table('terceros as tercero')
            ->where('tercero.id', $providerId)
            ->where('tercero.empresa_id', $companyId)
            ->where('tercero.estado', 'ACTIVO')
            ->whereExists(fn ($role) => $role
                ->selectRaw('1')
                ->from('tercero_roles as tercero_rol')
                ->whereColumn('tercero_rol.tercero_id', 'tercero.id')
                ->where('tercero_rol.rol', 'PROVEEDOR'))
            ->select('tercero.*')
            ->lockForUpdate()
            ->first();

        if (! $provider) {
            throw ValidationException::withMessages([
                'proveedor_id' => 'El proveedor no existe, esta inactivo o pertenece a otra empresa.',
            ]);
        }

        return $provider;
    }

    /** @param list<array<string, mixed>> $details */
    private function assertChickenTypes(array $details): void
    {
        $ids = collect($details)
            ->pluck('tipo_pollo_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return;
        }

        $existing = DB::table('tipos_pollo')
            ->whereIn('id', $ids->all())
            ->where('estado', 'ACTIVO')
            ->count();
        if ($existing !== $ids->count()) {
            throw ValidationException::withMessages([
                'detalles' => 'Uno o mas tipos de pollo no existen o estan inactivos.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canonicalPayload(array $data): array
    {
        $details = collect($data['detalles'])->map(function (array $detail, int $index): array {
            $weight = bcadd((string) $detail['peso_kg'], '0', 3);
            $unitPrice = bcadd((string) $detail['precio_kg'], '0', 4);
            $lineSubtotal = $this->moneyProduct($weight, $unitPrice);
            if (FinancialMoney::compare($lineSubtotal, '999999999999.99') > 0) {
                throw ValidationException::withMessages([
                    "detalles.{$index}.precio_kg" => 'El subtotal calculado excede el importe maximo permitido.',
                ]);
            }

            return [
                'tipo_pollo_id' => isset($detail['tipo_pollo_id'])
                    ? (int) $detail['tipo_pollo_id']
                    : null,
                'descripcion' => trim((string) $detail['descripcion']),
                'cantidad_aves' => isset($detail['cantidad_aves'])
                    ? (int) $detail['cantidad_aves']
                    : null,
                'peso_kg' => $weight,
                'precio_kg' => $unitPrice,
                'subtotal' => $lineSubtotal,
            ];
        })->values();
        $subtotal = $details->reduce(
            fn (string $sum, array $detail): string => FinancialMoney::add($sum, $detail['subtotal']),
            '0.00'
        );
        $tax = FinancialMoney::normalize($data['impuesto'] ?? '0.00');
        $total = FinancialMoney::add($subtotal, $tax);
        if (FinancialMoney::compare($total, '0.00') <= 0) {
            throw ValidationException::withMessages(['detalles' => 'El total de la compra debe ser mayor que cero.']);
        }
        if (FinancialMoney::compare($subtotal, '999999999999.99') > 0
            || FinancialMoney::compare($total, '999999999999.99') > 0) {
            throw ValidationException::withMessages([
                'detalles' => 'El total calculado excede el importe maximo permitido.',
            ]);
        }

        $payment = $data['pago'] ?? null;

        return [
            'idempotency_key' => strtolower((string) $data['idempotency_key']),
            'proveedor_id' => (int) $data['proveedor_id'],
            'tipo_documento' => strtoupper(trim((string) $data['tipo_documento'])),
            'numero_documento' => isset($data['numero_documento']) && trim((string) $data['numero_documento']) !== ''
                ? mb_strtoupper(trim((string) $data['numero_documento']), 'UTF-8')
                : null,
            'fecha_compra' => (string) $data['fecha_compra'],
            'fecha_vencimiento' => strtoupper((string) $data['condicion']) === Compra::CONDITION_CREDIT
                ? ($data['fecha_vencimiento'] ?? null)
                : null,
            'condicion' => strtoupper((string) $data['condicion']),
            'moneda' => strtoupper((string) $data['moneda']),
            'subtotal' => $subtotal,
            'impuesto' => $tax,
            'total' => $total,
            'observaciones' => $data['observaciones'] ?? null,
            'detalles' => $details->all(),
            'pago' => $payment === null ? null : [
                'cuenta_origen_id' => (int) $payment['cuenta_origen_id'],
                'cuenta_destino_id' => (int) $payment['cuenta_destino_id'],
                'metodo_pago_id' => (int) $payment['metodo_pago_id'],
                'referencia' => $payment['referencia'] ?? null,
                'fecha_hora' => $payment['fecha_hora'] ?? null,
                'observaciones' => $payment['observaciones'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertSameIdempotentRequest(object $purchase, array $data): void
    {
        $same = (int) $purchase->proveedor_id === $data['proveedor_id']
            && $purchase->tipo_documento === $data['tipo_documento']
            && ($purchase->numero_documento ?? null) === $data['numero_documento']
            && (string) $purchase->fecha_compra === $data['fecha_compra']
            && ($purchase->fecha_vencimiento ?? null) === $data['fecha_vencimiento']
            && $purchase->condicion === $data['condicion']
            && $purchase->moneda === $data['moneda']
            && FinancialMoney::compare((string) $purchase->subtotal, $data['subtotal']) === 0
            && FinancialMoney::compare((string) $purchase->impuesto, $data['impuesto']) === 0
            && FinancialMoney::compare((string) $purchase->total, $data['total']) === 0
            && ($purchase->observaciones ?? null) === $data['observaciones'];

        $storedDetails = DB::table('compra_detalles')
            ->where('compra_id', $purchase->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $detail): array => [
                'tipo_pollo_id' => $detail->tipo_pollo_id === null ? null : (int) $detail->tipo_pollo_id,
                'descripcion' => $detail->descripcion,
                'cantidad_aves' => $detail->cantidad_aves === null ? null : (int) $detail->cantidad_aves,
                'peso_kg' => bcadd((string) $detail->peso_kg, '0', 3),
                'precio_kg' => bcadd((string) $detail->precio_kg, '0', 4),
                'subtotal' => FinancialMoney::normalize((string) $detail->subtotal),
            ])->all();

        if (! $same || $storedDetails !== $data['detalles'] || ! $this->sameInitialPayment($purchase, $data)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con una compra diferente.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function sameInitialPayment(object $purchase, array $data): bool
    {
        if ($data['condicion'] === Compra::CONDITION_CREDIT) {
            return $purchase->pago_inicial_id === null && $data['pago'] === null;
        }
        if ($purchase->pago_inicial_id === null || $data['pago'] === null) {
            return false;
        }

        $payment = DB::table('pagos')->where('id', $purchase->pago_inicial_id)->first();
        if (! $payment) {
            return false;
        }
        $requested = $data['pago'];

        return $payment->tipo === 'PAGO_PROVEEDOR'
            && (int) $payment->proveedor_id === $data['proveedor_id']
            && (int) $payment->cuenta_origen_id === $requested['cuenta_origen_id']
            && (int) $payment->cuenta_destino_id === $requested['cuenta_destino_id']
            && (int) $payment->metodo_pago_id === $requested['metodo_pago_id']
            && $payment->moneda === $data['moneda']
            && FinancialMoney::compare((string) $payment->importe, $data['total']) === 0
            && ($payment->referencia ?? null) === $requested['referencia']
            && ($payment->observaciones ?? null) === $requested['observaciones']
            && ($requested['fecha_hora'] === null
                || CarbonImmutable::parse($payment->fecha_hora)->toDateTimeString()
                    === CarbonImmutable::parse($requested['fecha_hora'])->toDateTimeString());
    }

    /** @return Collection<int, int> */
    private function activeApplicationPaymentIds(int $documentId)
    {
        return DB::table('pago_aplicaciones as aplicacion')
            ->join('pagos as pago', 'pago.id', '=', 'aplicacion.pago_id')
            ->where('aplicacion.comprobante_id', $documentId)
            ->where('pago.estado', 'REGISTRADO')
            ->pluck('pago.id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function initialPaymentReverseId(int $companyId, object $purchase): ?int
    {
        if ($purchase->pago_inicial_id === null) {
            return null;
        }

        $id = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where('reversa_de_pago_id', $purchase->pago_inicial_id)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function assertActor(int $companyId, User $actor, string $permission): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId && $actor->isActive(),
            403,
            'Usuario no autorizado para esta empresa.'
        );
        $this->assertPermission($actor, $permission);
    }

    private function assertPermission(User $actor, string $permission): void
    {
        abort_unless($actor->hasPermission($permission), 403, "Se requiere el permiso {$permission}.");
    }

    private function purchaseCode(int $id): string
    {
        return 'COM-'.str_pad((string) $id, 10, '0', STR_PAD_LEFT);
    }

    private function moneyProduct(string $quantity, string $unitPrice): string
    {
        return bcadd(bcmul($quantity, $unitPrice, 7), '0.005', 2);
    }
}
