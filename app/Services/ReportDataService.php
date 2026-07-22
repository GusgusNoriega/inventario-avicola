<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\Pesada;
use App\Models\Tercero;
use App\Models\TicketDespacho;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportDataService
{
    /** @return array<string, mixed> */
    public function customerStatement(int $companyId, int $customerId, string $from, string $to): array
    {
        $customer = Tercero::query()
            ->where('empresa_id', $companyId)
            ->findOrFail($customerId);

        return $this->statement($companyId, $customer, 'VENTA', 'cliente_id', $from, $to);
    }

    /** @return array<string, mixed> */
    public function providerStatement(int $companyId, int $providerId, string $from, string $to): array
    {
        $provider = Tercero::query()
            ->where('empresa_id', $companyId)
            ->findOrFail($providerId);

        return $this->statement($companyId, $provider, 'COMPRA', 'proveedor_id', $from, $to);
    }

    /** @return array<string, mixed> */
    private function statement(
        int $companyId,
        Tercero $counterparty,
        string $operation,
        string $paymentPartyColumn,
        string $from,
        string $to,
    ): array {
        $openingDocuments = Comprobante::query()
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $counterparty->id)
            ->where('operacion', $operation)
            ->where('estado', '<>', Comprobante::STATUS_VOIDED)
            ->whereDate('fecha_emision', '<', $from)
            ->get(['naturaleza', 'total'])
            ->sum(fn (Comprobante $document): float => $this->documentEffect($document));

        $openingPayments = $this->paymentQuery($companyId)
            ->where($paymentPartyColumn, $counterparty->id)
            ->where('fecha_hora', '<', CarbonImmutable::parse($from)->startOfDay())
            ->get()
            ->sum(fn (Pago $payment): float => $this->paymentEffect($payment, $operation));
        $opening = round($openingDocuments + $openingPayments, 2);

        $documents = Comprobante::query()
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $counterparty->id)
            ->where('operacion', $operation)
            ->where('estado', '<>', Comprobante::STATUS_VOIDED)
            ->whereBetween('fecha_emision', [$from, $to])
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->get();
        $details = DB::table('comprobante_detalles')
            ->whereIn('comprobante_id', $documents->pluck('id'))
            ->get()
            ->groupBy('comprobante_id');

        $transactions = $documents->map(function (Comprobante $document) use ($details): array {
            $lines = $details->get($document->id, collect());
            $effect = $this->documentEffect($document);

            return [
                'date' => $document->fecha_emision->format('Y-m-d'),
                'sort' => $document->fecha_emision->format('Y-m-d').' 00:00:00-D-'.$document->id,
                'code' => $document->codigo,
                'type' => $document->naturaleza === Comprobante::NATURE_CREDIT ? 'NOTA / DEVOLUCION' : $document->tipo_documento,
                'detail' => $lines->pluck('descripcion')->unique()->implode(', '),
                'weight' => (float) $lines->sum('peso_neto_kg'),
                'price' => $lines->count() === 1 ? (float) ($lines->first()->precio_kg ?? 0) : null,
                'debit' => $effect > 0 ? abs($effect) : 0,
                'credit' => $effect < 0 ? abs($effect) : 0,
                'effect' => $effect,
            ];
        });

        $payments = $this->paymentQuery($companyId)
            ->where($paymentPartyColumn, $counterparty->id)
            ->whereBetween('fecha_hora', [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ])
            ->with(['metodoPago', 'cuentaOrigen.entidadFinanciera', 'cuentaDestino.entidadFinanciera'])
            ->orderBy('fecha_hora')
            ->orderBy('id')
            ->get()
            ->map(function (Pago $payment) use ($operation): array {
                $effect = $this->paymentEffect($payment, $operation);
                $account = $payment->cuentaDestino ?: $payment->cuentaOrigen;
                $destination = collect([
                    $account?->entidadFinanciera?->nombre_comercial ?: $account?->entidadFinanciera?->razon_social,
                    $account?->alias,
                ])->filter()->implode(' - ');

                return [
                    'date' => $payment->fecha_hora->format('Y-m-d'),
                    'sort' => $payment->fecha_hora->format('Y-m-d H:i:s').'-P-'.$payment->id,
                    'code' => $payment->codigo ?: 'PG-'.$payment->id,
                    'type' => str_replace('_', ' ', $payment->tipo ?: $payment->direccion),
                    'detail' => collect([
                        $payment->metodoPago?->nombre ?: $payment->metodo,
                        $destination,
                        $payment->referencia,
                    ])->filter()->implode(' - '),
                    'weight' => null,
                    'price' => null,
                    'debit' => $effect > 0 ? abs($effect) : 0,
                    'credit' => $effect < 0 ? abs($effect) : 0,
                    'effect' => $effect,
                ];
            });

        $balance = $opening;
        $rows = $transactions->concat($payments)
            ->sortBy('sort')
            ->values()
            ->map(function (array $row) use (&$balance): array {
                $balance = round($balance + $row['effect'], 2);
                $row['balance'] = $balance;

                return $row;
            });

        return [
            'counterparty' => $counterparty,
            'opening' => $opening,
            'rows' => $rows,
            'charges' => $rows->sum('debit'),
            'credits' => $rows->sum('credit'),
            'balance' => $balance,
        ];
    }

    /** @return array<string, mixed> */
    public function salesByCustomer(int $companyId, string $from, string $to): array
    {
        $tickets = TicketDespacho::query()
            ->where('estado', TicketDespacho::STATUS_CLOSED)
            ->whereHas('jornada', fn (Builder $query) => $query
                ->whereBetween('fecha_operativa', [$from, $to])
                ->whereHas('sucursal', fn (Builder $branch) => $branch->where('empresa_id', $companyId)))
            ->with(['jornada', 'clienteDestino', 'pesadas.tipoPollo', 'precios'])
            ->orderBy('id')
            ->get();

        $rows = collect();
        foreach ($tickets as $ticket) {
            $sign = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN ? -1 : 1;
            $prices = $ticket->precios->keyBy('tipo_pollo_id');

            foreach ($ticket->pesadas->where('estado', Pesada::STATUS_ACTIVE)->groupBy('tipo_pollo_id') as $typeId => $weighings) {
                $recordedAt = $ticket->cerrado_at
                    ?: $weighings->sortByDesc('pesada_at')->first()?->pesada_at
                    ?: $ticket->created_at;
                $price = (float) ($prices->get((int) $typeId)?->precio_kg ?? 0);
                $net = (float) $weighings->sum('peso_neto_kg') * $sign;
                $key = implode(':', [
                    $ticket->id,
                    $typeId,
                ]);
                $existing = $rows->get($key, [
                    'date_time' => $recordedAt?->format('Y-m-d H:i:s') ?? $ticket->jornada?->fecha_operativa?->startOfDay()->format('Y-m-d H:i:s'),
                    'customer' => $ticket->clienteDestino?->nombre_razon_social ?? 'VENTA MINORISTA SIN CLIENTE',
                    'channel' => $ticket->canal,
                    'product' => $weighings->first()?->tipoPollo?->nombre ?? 'Pollo',
                    'containers' => 0,
                    'birds' => 0,
                    'gross_weight' => 0,
                    'tare' => 0,
                    'returns' => 0,
                    'net_weight' => 0,
                    'amount' => 0,
                ]);
                if ($sign > 0) {
                    $existing['containers'] += (int) $weighings->sum('cantidad_javas') + (int) $weighings->sum('cantidad_bandejas');
                    $existing['birds'] += (int) $weighings->sum('cantidad_aves');
                    $existing['gross_weight'] += (float) $weighings->sum('peso_bruto_kg');
                    $existing['tare'] += (float) $weighings->sum('tara_total_kg');
                }
                $existing['returns'] += $sign < 0 ? abs((float) $weighings->sum('peso_neto_kg')) : 0;
                $existing['net_weight'] += $net;
                $existing['amount'] += $net * $price;
                $rows->put($key, $existing);
            }
        }

        $rows = $rows->sortBy(fn (array $row): string => $row['date_time'].'-'.$row['customer'].'-'.$row['product'])->values();

        return [
            'rows' => $rows,
            'totals' => [
                'containers' => $rows->sum('containers'),
                'birds' => $rows->sum('birds'),
                'gross_weight' => $rows->sum('gross_weight'),
                'tare' => $rows->sum('tare'),
                'returns' => $rows->sum('returns'),
                'net_weight' => $rows->sum('net_weight'),
                'amount' => $rows->sum('amount'),
            ],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function payments(int $companyId, string $from, string $to, array $filters = []): array
    {
        $query = $this->paymentQuery($companyId)
            ->whereBetween('fecha_hora', [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ])
            ->when($filters['tipo'] ?? null, fn (Builder $builder, string $type) => $builder->where('tipo', $type))
            ->when($filters['metodo_pago_id'] ?? null, fn (Builder $builder, int|string $id) => $builder->where('metodo_pago_id', $id))
            ->when($filters['usuario_id'] ?? null, fn (Builder $builder, int|string $id) => $builder->where('created_by', $id))
            ->with([
                'tercero', 'cliente', 'proveedor', 'metodoPago', 'creador',
                'cuentaOrigen.entidadFinanciera', 'cuentaDestino.entidadFinanciera',
            ])
            ->orderBy('fecha_hora')
            ->orderBy('id');

        $payments = $query->get();
        $rows = $payments->map(function (Pago $payment): array {
            $party = $payment->cliente ?: $payment->proveedor ?: $payment->tercero;
            $account = $payment->cuentaDestino ?: $payment->cuentaOrigen;

            return [
                'date' => $payment->fecha_hora,
                'code' => $payment->codigo ?: 'PG-'.$payment->id,
                'counterparty' => $party?->nombre_razon_social ?? 'MOVIMIENTO INTERNO',
                'type' => str_replace('_', ' ', $payment->tipo ?: $payment->direccion),
                'method' => $payment->metodoPago?->nombre ?: $payment->metodo,
                'detail' => collect([
                    $account?->entidadFinanciera?->nombre_comercial ?: $account?->entidadFinanciera?->razon_social,
                    $account?->alias,
                    $payment->referencia,
                ])->filter()->implode(' - '),
                'user' => $payment->creador?->nombre ?? 'Sin usuario',
                'amount' => (float) $payment->importe,
                'flow' => $this->flow($payment),
            ];
        });

        return [
            'rows' => $rows,
            'income' => $rows->where('flow', 'INGRESO')->sum('amount'),
            'expense' => $rows->where('flow', 'EGRESO')->sum('amount'),
            'total' => $rows->sum('amount'),
        ];
    }

    /** @return array<string, mixed> */
    public function responsibleMovements(int $companyId, int $userId, string $from, string $to): array
    {
        $data = $this->payments($companyId, $from, $to, ['usuario_id' => $userId]);

        $data['collections'] = $data['rows']->filter(fn (array $row) => $row['flow'] === 'INGRESO')->values();
        $data['expenses'] = $data['rows']->filter(fn (array $row) => $row['flow'] === 'EGRESO')->values();
        $data['other'] = $data['rows']->filter(fn (array $row) => $row['flow'] === 'SIN_FLUJO')->values();
        $data['user_name'] = DB::table('usuarios')
            ->where('empresa_id', $companyId)
            ->where('id', $userId)
            ->value('nombre') ?: 'Usuario';

        return $data;
    }

    /** @return Builder<Pago> */
    private function paymentQuery(int $companyId): Builder
    {
        return Pago::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Pago::STATUS_REGISTERED)
            ->whereNull('reversa_de_pago_id');
    }

    private function documentEffect(Comprobante $document): float
    {
        return (float) $document->total
            * ($document->naturaleza === Comprobante::NATURE_CREDIT ? -1 : 1);
    }

    private function paymentEffect(Pago $payment, string $operation): float
    {
        if ($operation === Comprobante::OPERATION_SALE) {
            return match ($payment->tipo) {
                Pago::TYPE_CUSTOMER_REFUND => abs((float) $payment->importe),
                Pago::TYPE_CUSTOMER_COLLECTION, Pago::TYPE_RETAIL_COLLECTION, Pago::TYPE_DIRECT_PAYMENT => -abs((float) $payment->importe),
                default => $this->flow($payment) === Pago::DIRECTION_INCOME
                    ? -abs((float) $payment->importe)
                    : abs((float) $payment->importe),
            };
        }

        return match ($payment->tipo) {
            Pago::TYPE_DIRECT_PAYMENT, Pago::TYPE_PROVIDER_PAYMENT, Pago::TYPE_PROVIDER_CREDIT => -abs((float) $payment->importe),
            default => $this->flow($payment) === Pago::DIRECTION_EXPENSE
                ? -abs((float) $payment->importe)
                : abs((float) $payment->importe),
        };
    }

    private function flow(Pago $payment): string
    {
        if (in_array($payment->tipo, [
            Pago::TYPE_CUSTOMER_COLLECTION,
            Pago::TYPE_RETAIL_COLLECTION,
            Pago::TYPE_OPENING_BALANCE,
        ], true)) {
            return Pago::DIRECTION_INCOME;
        }

        if (in_array($payment->tipo, [
            Pago::TYPE_PROVIDER_PAYMENT,
            Pago::TYPE_CUSTOMER_REFUND,
        ], true)) {
            return Pago::DIRECTION_EXPENSE;
        }

        return in_array($payment->direccion, [Pago::DIRECTION_INCOME, Pago::DIRECTION_EXPENSE], true)
            ? $payment->direccion
            : Pago::DIRECTION_NO_FLOW;
    }
}
