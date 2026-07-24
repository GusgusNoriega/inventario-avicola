<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\User;
use App\Support\FinancialMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerDiscountService
{
    public function __construct(
        private readonly FinancialMovementService $movements,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{payment_id: int, idempotent: bool}
     */
    public function register(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor);
        $currency = $this->companyCurrency($companyId);
        $applications = $this->automaticApplications(
            $companyId,
            (int) $data['cliente_id'],
            $currency,
            (string) $data['importe'],
        );
        $result = $this->movements->register(
            $companyId,
            $actor,
            [
                'idempotency_key' => $data['idempotency_key'],
                'tipo' => Pago::TYPE_CUSTOMER_DISCOUNT,
                'fecha_hora' => now()->toDateTimeString(),
                'cliente_id' => (int) $data['cliente_id'],
                'moneda' => $currency,
                'importe' => $data['importe'],
                'observaciones' => $data['motivo'],
                'aplicaciones' => $applications,
            ],
            $ip,
        );

        return [
            'payment_id' => $result['pago_id'],
            'idempotent' => $result['idempotent'],
        ];
    }

    /**
     * La edición deja el movimiento anterior anulado y crea su reemplazo.
     *
     * @param  array<string, mixed>  $data
     * @return array{payment_id: int, idempotent: bool}
     */
    public function update(
        int $companyId,
        User $actor,
        int $paymentId,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor);

        $existing = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where('tipo', Pago::TYPE_CUSTOMER_DISCOUNT)
            ->whereNull('reversa_de_pago_id')
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            $this->assertSameReplacement($existing, $data);

            return ['payment_id' => (int) $existing->id, 'idempotent' => true];
        }

        return DB::transaction(function () use ($companyId, $actor, $paymentId, $data, $ip): array {
            $original = $this->editablePayment($companyId, $paymentId);

            $this->movements->void(
                $companyId,
                $actor,
                $paymentId,
                'Registro reemplazado mediante edición.',
                $ip,
            );

            $currency = $this->companyCurrency($companyId);
            $applications = $this->automaticApplications(
                $companyId,
                (int) $data['cliente_id'],
                $currency,
                (string) $data['importe'],
                true,
            );
            $result = $this->movements->register(
                $companyId,
                $actor,
                [
                    'idempotency_key' => $data['idempotency_key'],
                    'tipo' => Pago::TYPE_CUSTOMER_DISCOUNT,
                    'fecha_hora' => $original->fecha_hora,
                    'cliente_id' => (int) $data['cliente_id'],
                    'moneda' => $currency,
                    'importe' => $data['importe'],
                    'referencia' => mb_substr('EDICIÓN DE '.$original->codigo, 0, 100),
                    'observaciones' => $data['motivo'],
                    'aplicaciones' => $applications,
                ],
                $ip,
            );

            return [
                'payment_id' => $result['pago_id'],
                'idempotent' => $result['idempotent'],
            ];
        }, 3);
    }

    public function void(
        int $companyId,
        User $actor,
        int $paymentId,
        string $reason,
        ?string $ip = null,
    ): bool {
        $this->assertActor($companyId, $actor);
        $this->discountPayment($companyId, $paymentId);

        return $this->movements->void(
            $companyId,
            $actor,
            $paymentId,
            $reason,
            $ip,
        )['idempotent'];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function list(int $companyId, array $filters): array
    {
        $applications = DB::table('pago_aplicaciones')
            ->select('pago_id')
            ->selectRaw('COALESCE(SUM(importe_aplicado), 0) as importe_aplicado')
            ->where('lado', 'CXC')
            ->groupBy('pago_id');

        $query = DB::table('pagos as pago')
            ->join('terceros as cliente', 'cliente.id', '=', 'pago.cliente_id')
            ->leftJoinSub($applications, 'aplicacion', 'aplicacion.pago_id', '=', 'pago.id')
            ->where('pago.empresa_id', $companyId)
            ->where('pago.tipo', Pago::TYPE_CUSTOMER_DISCOUNT)
            ->whereNull('pago.reversa_de_pago_id')
            ->when($filters['estado'] ?? null, fn (Builder $query, string $status) => $query->where('pago.estado', $status))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('pago.codigo', 'like', "%{$search}%")
                        ->orWhere('cliente.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhere('cliente.numero_documento', 'like', "%{$search}%")
                        ->orWhere('pago.observaciones', 'like', "%{$search}%");
                });
            })
            ->select([
                'pago.*',
                'cliente.nombre_razon_social as cliente_nombre',
                'cliente.numero_documento as cliente_documento',
            ])
            ->selectRaw('COALESCE(aplicacion.importe_aplicado, 0) as importe_aplicado');

        $activeQuery = clone $query;
        $activeTotal = $activeQuery->where('pago.estado', Pago::STATUS_REGISTERED)->sum('pago.importe');
        $paginator = $query
            ->orderByDesc('pago.fecha_hora')
            ->orderByDesc('pago.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $payment): array => $this->format($payment))
                ->all(),
            'summary' => [
                'active_total' => FinancialMoney::normalize((string) $activeTotal),
                'active_count' => (clone $query)
                    ->where('pago.estado', Pago::STATUS_REGISTERED)
                    ->count(),
                'currency' => $this->companyCurrency($companyId),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function document(int $companyId, int $paymentId): array
    {
        $payment = DB::table('pagos as pago')
            ->join('terceros as cliente', 'cliente.id', '=', 'pago.cliente_id')
            ->where('pago.empresa_id', $companyId)
            ->where('pago.id', $paymentId)
            ->where('pago.tipo', Pago::TYPE_CUSTOMER_DISCOUNT)
            ->whereNull('pago.reversa_de_pago_id')
            ->select([
                'pago.*',
                'cliente.nombre_razon_social as cliente_nombre',
                'cliente.numero_documento as cliente_documento',
            ])
            ->selectSub(
                DB::table('pago_aplicaciones')
                    ->selectRaw('COALESCE(SUM(importe_aplicado), 0)')
                    ->whereColumn('pago_id', 'pago.id')
                    ->where('lado', 'CXC'),
                'importe_aplicado',
            )
            ->first();

        abort_unless($payment, 404, 'El descuento no fue encontrado.');

        return $this->format($payment);
    }

    /**
     * @return list<array{lado: string, comprobante_id: int, importe_aplicado: string}>
     */
    private function automaticApplications(
        int $companyId,
        int $clientId,
        string $currency,
        string $amount,
        bool $lock = false,
    ): array {
        $query = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $clientId)
            ->where('operacion', 'VENTA')
            ->where('naturaleza', 'CARGO')
            ->where('moneda', $currency)
            ->whereIn('estado', ['PENDIENTE', 'PARCIAL'])
            ->where('saldo_pendiente', '>', 0)
            ->orderBy('fecha_emision')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $remaining = FinancialMoney::normalize($amount);
        $applications = [];
        foreach ($query->get(['id', 'saldo_pendiente']) as $document) {
            if (FinancialMoney::compare($remaining, '0.00') <= 0) {
                break;
            }
            $applied = FinancialMoney::compare($remaining, (string) $document->saldo_pendiente) > 0
                ? FinancialMoney::normalize((string) $document->saldo_pendiente)
                : $remaining;
            $applications[] = [
                'lado' => 'CXC',
                'comprobante_id' => (int) $document->id,
                'importe_aplicado' => $applied,
            ];
            $remaining = FinancialMoney::subtract($remaining, $applied);
        }

        return $applications;
    }

    private function discountPayment(int $companyId, int $paymentId): object
    {
        $payment = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where('id', $paymentId)
            ->where('tipo', Pago::TYPE_CUSTOMER_DISCOUNT)
            ->whereNull('reversa_de_pago_id')
            ->first();
        abort_unless($payment, 404, 'El descuento no fue encontrado.');

        return $payment;
    }

    private function editablePayment(int $companyId, int $paymentId): object
    {
        $payment = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where('id', $paymentId)
            ->where('tipo', Pago::TYPE_CUSTOMER_DISCOUNT)
            ->whereNull('reversa_de_pago_id')
            ->lockForUpdate()
            ->first();
        abort_unless($payment, 404, 'El descuento no fue encontrado.');

        if ($payment->estado !== Pago::STATUS_REGISTERED) {
            throw ValidationException::withMessages([
                'descuento' => 'Solo se puede editar un descuento vigente.',
            ]);
        }

        return $payment;
    }

    /** @param array<string, mixed> $data */
    private function assertSameReplacement(object $payment, array $data): void
    {
        $same = (int) $payment->cliente_id === (int) $data['cliente_id']
            && FinancialMoney::compare((string) $payment->importe, (string) $data['importe']) === 0
            && (string) $payment->observaciones === (string) $data['motivo'];
        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada con otro descuento.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function format(object $payment): array
    {
        $applied = FinancialMoney::normalize((string) ($payment->importe_aplicado ?? '0.00'));
        $credit = FinancialMoney::subtract((string) $payment->importe, $applied);
        if (FinancialMoney::compare($credit, '0.00') < 0) {
            $credit = '0.00';
        }

        return [
            'id' => (int) $payment->id,
            'codigo' => $payment->codigo,
            'fecha_hora' => $payment->fecha_hora,
            'moneda' => $payment->moneda,
            'importe' => FinancialMoney::normalize((string) $payment->importe),
            'importe_aplicado' => $applied,
            'saldo_favor' => $credit,
            'motivo' => $payment->observaciones,
            'estado' => $payment->estado,
            'anulada_at' => $payment->anulada_at,
            'motivo_anulacion' => $payment->motivo_anulacion,
            'cliente' => [
                'id' => (int) $payment->cliente_id,
                'nombre' => $payment->cliente_nombre,
                'numero_documento' => $payment->cliente_documento,
            ],
            'puede_editar' => $payment->estado === Pago::STATUS_REGISTERED,
            'puede_anular' => $payment->estado === Pago::STATUS_REGISTERED,
        ];
    }

    private function companyCurrency(int $companyId): string
    {
        return (string) (DB::table('empresas')->where('id', $companyId)->value('moneda') ?: 'PEN');
    }

    private function assertActor(int $companyId, User $actor): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId
                && $actor->isActive()
                && $actor->hasPermission('SALDOS_AJUSTAR'),
            403,
            'Se requiere el permiso SALDOS_AJUSTAR.',
        );
    }
}
