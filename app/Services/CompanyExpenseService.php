<?php

namespace App\Services;

use App\Models\GastoEmpresa;
use App\Models\Pago;
use App\Models\User;
use App\Support\FinancialMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyExpenseService
{
    public function __construct(
        private readonly FinancialMovementService $movements,
        private readonly FinancialAuditService $audit,
    ) {}

    /** @param array<string, mixed> $data @return array{gasto_id: int, idempotent: bool} */
    public function register(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip = null,
    ): array {
        return DB::transaction(function () use ($companyId, $actor, $data, $ip): array {
            $existing = DB::table('gastos_empresa')
                ->where('empresa_id', $companyId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->assertSameRequest($existing, $data);

                return ['gasto_id' => (int) $existing->id, 'idempotent' => true];
            }

            $movement = $this->movements->register(
                $companyId,
                $actor,
                $this->movementPayload($data, (string) $data['idempotency_key']),
                $ip,
            );
            $now = now();
            $expenseId = DB::table('gastos_empresa')->insertGetId([
                'empresa_id' => $companyId,
                'pago_id' => $movement['pago_id'],
                'codigo' => null,
                'idempotency_key' => $data['idempotency_key'],
                'categoria' => $data['categoria'],
                'concepto' => $data['concepto'],
                'destino' => $data['destino'],
                'numero_documento' => $data['numero_documento'] ?? null,
                'estado' => GastoEmpresa::STATUS_REGISTERED,
                'created_by' => $actor->id,
                'anulada_por' => null,
                'anulada_at' => null,
                'motivo_anulacion' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $code = 'GAS-'.str_pad((string) $expenseId, 10, '0', STR_PAD_LEFT);
            DB::table('gastos_empresa')->where('id', $expenseId)->update(['codigo' => $code]);

            $expense = (array) DB::table('gastos_empresa')->where('id', $expenseId)->first();
            $this->audit->record(
                $companyId,
                $actor->id,
                'gastos_empresa',
                $expenseId,
                'REGISTRAR',
                null,
                $expense,
                $ip,
            );

            return ['gasto_id' => $expenseId, 'idempotent' => false];
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(
        int $companyId,
        User $actor,
        int $expenseId,
        array $data,
        ?string $ip = null,
    ): void {
        DB::transaction(function () use ($companyId, $actor, $expenseId, $data, $ip): void {
            $expense = DB::table('gastos_empresa')
                ->where('empresa_id', $companyId)
                ->where('id', $expenseId)
                ->lockForUpdate()
                ->first();
            abort_unless($expense, 404, 'Gasto de empresa no encontrado.');
            if ($expense->estado !== GastoEmpresa::STATUS_REGISTERED) {
                throw ValidationException::withMessages([
                    'gasto' => 'Un gasto anulado no puede editarse.',
                ]);
            }

            $payment = DB::table('pagos')
                ->where('empresa_id', $companyId)
                ->where('id', $expense->pago_id)
                ->lockForUpdate()
                ->first();
            abort_unless($payment, 409, 'El gasto no tiene un movimiento financiero válido.');

            $before = [
                ...(array) $expense,
                'pago' => (array) $payment,
            ];
            $financialChange = (int) $payment->cuenta_origen_id !== (int) $data['cuenta_origen_id']
                || (int) $payment->metodo_pago_id !== (int) $data['metodo_pago_id']
                || $payment->moneda !== $data['moneda']
                || FinancialMoney::compare((string) $payment->importe, (string) $data['importe']) !== 0;

            $paymentId = (int) $payment->id;
            if ($financialChange) {
                abort_unless(
                    $actor->hasPermission('PAGOS_ANULAR'),
                    403,
                    'Para cambiar caja, método, moneda o importe también se requiere PAGOS_ANULAR.',
                );
                $this->movements->void(
                    $companyId,
                    $actor,
                    $paymentId,
                    'Corrección del gasto '.$expense->codigo,
                    $ip,
                    null,
                    null,
                    null,
                    $expenseId,
                );
                $replacement = $this->movements->register(
                    $companyId,
                    $actor,
                    $this->movementPayload($data, (string) Str::uuid()),
                    $ip,
                );
                $paymentId = $replacement['pago_id'];
            } else {
                $this->movements->updateMetadata(
                    $companyId,
                    $actor,
                    $paymentId,
                    [
                        'fecha_hora' => $data['fecha_hora'],
                        'referencia' => $data['referencia'] ?? null,
                        'observaciones' => $data['observaciones'] ?? null,
                    ],
                    $ip,
                    null,
                    $expenseId,
                );
            }

            DB::table('gastos_empresa')->where('id', $expenseId)->update([
                'pago_id' => $paymentId,
                'categoria' => $data['categoria'],
                'concepto' => $data['concepto'],
                'destino' => $data['destino'],
                'numero_documento' => $data['numero_documento'] ?? null,
                'updated_at' => now(),
            ]);
            $after = (array) DB::table('gastos_empresa')->where('id', $expenseId)->first();
            $after['pago'] = (array) DB::table('pagos')->where('id', $paymentId)->first();
            $this->audit->record(
                $companyId,
                $actor->id,
                'gastos_empresa',
                $expenseId,
                $financialChange ? 'CORREGIR_CON_REVERSA' : 'EDITAR',
                $before,
                $after,
                $ip,
            );
        }, 3);
    }

    /** @return array{gasto_id: int, reversa_id: int, idempotent: bool} */
    public function void(
        int $companyId,
        User $actor,
        int $expenseId,
        string $reason,
        ?string $ip = null,
    ): array {
        return DB::transaction(function () use ($companyId, $actor, $expenseId, $reason, $ip): array {
            $expense = DB::table('gastos_empresa')
                ->where('empresa_id', $companyId)
                ->where('id', $expenseId)
                ->lockForUpdate()
                ->first();
            abort_unless($expense, 404, 'Gasto de empresa no encontrado.');

            if ($expense->estado === GastoEmpresa::STATUS_VOIDED) {
                $reverseId = DB::table('pagos')
                    ->where('empresa_id', $companyId)
                    ->where('reversa_de_pago_id', $expense->pago_id)
                    ->value('id');
                abort_unless($reverseId, 409, 'El gasto está anulado, pero no se encontró su reversa.');

                return [
                    'gasto_id' => $expenseId,
                    'reversa_id' => (int) $reverseId,
                    'idempotent' => true,
                ];
            }

            $movement = $this->movements->void(
                $companyId,
                $actor,
                (int) $expense->pago_id,
                $reason,
                $ip,
                null,
                null,
                null,
                $expenseId,
            );
            $voidedAt = now();
            DB::table('gastos_empresa')->where('id', $expenseId)->update([
                'estado' => GastoEmpresa::STATUS_VOIDED,
                'anulada_por' => $actor->id,
                'anulada_at' => $voidedAt,
                'motivo_anulacion' => $reason,
                'updated_at' => $voidedAt,
            ]);
            $after = [
                ...(array) $expense,
                'estado' => GastoEmpresa::STATUS_VOIDED,
                'anulada_por' => $actor->id,
                'anulada_at' => $voidedAt->toDateTimeString(),
                'motivo_anulacion' => $reason,
            ];
            $this->audit->record(
                $companyId,
                $actor->id,
                'gastos_empresa',
                $expenseId,
                'ANULAR',
                (array) $expense,
                $after,
                $ip,
            );

            return [
                'gasto_id' => $expenseId,
                'reversa_id' => $movement['reversa_id'],
                'idempotent' => false,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function movementPayload(array $data, string $idempotencyKey): array
    {
        return [
            'idempotency_key' => $idempotencyKey,
            'tipo' => Pago::TYPE_COMPANY_EXPENSE,
            'fecha_hora' => $data['fecha_hora'],
            'cliente_id' => null,
            'proveedor_id' => null,
            'cuenta_origen_id' => (int) $data['cuenta_origen_id'],
            'cuenta_destino_id' => null,
            'metodo_pago_id' => (int) $data['metodo_pago_id'],
            'moneda' => $data['moneda'],
            'importe' => $data['importe'],
            'referencia' => $data['referencia'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'aplicaciones' => [],
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertSameRequest(object $expense, array $data): void
    {
        $payment = DB::table('pagos')->where('id', $expense->pago_id)->first();
        $same = $payment
            && $expense->categoria === $data['categoria']
            && $expense->concepto === $data['concepto']
            && $expense->destino === $data['destino']
            && ($expense->numero_documento ?? null) === ($data['numero_documento'] ?? null)
            && (int) $payment->cuenta_origen_id === (int) $data['cuenta_origen_id']
            && (int) $payment->metodo_pago_id === (int) $data['metodo_pago_id']
            && $payment->moneda === $data['moneda']
            && FinancialMoney::compare((string) $payment->importe, (string) $data['importe']) === 0
            && ($payment->referencia ?? null) === ($data['referencia'] ?? null)
            && ($payment->observaciones ?? null) === ($data['observaciones'] ?? null);
        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con un gasto diferente.',
            ]);
        }
    }
}
