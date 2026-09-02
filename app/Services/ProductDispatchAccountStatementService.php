<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoDespacho;
use App\Models\Tercero;
use App\Models\TicketDespachoProducto;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductDispatchAccountStatementService
{
    /**
     * @param  object{id: int, codigo: string, nombre: string, zona_horaria: string}  $branch
     * @return array<string, mixed>
     */
    public function catalog(int $companyId, object $branch): array
    {
        $eligible = $this->eligibleDocumentsQuery($companyId, (int) $branch->id);
        $clients = (clone $eligible)
            ->join('terceros as client', function ($join) use ($companyId): void {
                $join->on('client.id', '=', 'ticket.cliente_id')
                    ->where('client.empresa_id', '=', $companyId);
            })
            ->select([
                'client.id',
                'client.nombre_razon_social',
                'client.tipo_documento',
                'client.numero_documento',
            ])
            ->distinct()
            ->orderBy('client.nombre_razon_social')
            ->orderBy('client.id')
            ->get()
            ->map(fn (object $client): array => [
                'id' => (int) $client->id,
                'name' => (string) $client->nombre_razon_social,
                'document_type' => $client->tipo_documento,
                'document' => $client->numero_documento,
            ])
            ->values();

        $companyCurrency = strtoupper(trim((string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('moneda')));
        $defaultCurrency = preg_match('/\A[A-Z]{3}\z/', $companyCurrency) === 1
            ? $companyCurrency
            : 'PEN';
        $currencies = collect([$defaultCurrency])
            ->merge((clone $eligible)
                ->distinct()
                ->pluck('document.moneda'))
            ->map(fn (mixed $currency): string => strtoupper(trim((string) $currency)))
            ->filter(fn (string $currency): bool => preg_match('/\A[A-Z]{3}\z/', $currency) === 1)
            ->unique()
            ->sortBy(fn (string $currency): string => $currency === $defaultCurrency
                ? '0-'.$currency
                : '1-'.$currency)
            ->values();

        return [
            'clients' => $clients,
            'currencies' => $currencies,
            'default_currency' => $defaultCurrency,
            'branch' => $this->branchPayload($branch),
        ];
    }

    /**
     * @param  object{id: int, codigo: string, nombre: string, zona_horaria: string}  $branch
     * @return array<string, mixed>
     */
    public function statement(
        int $companyId,
        object $branch,
        int $clientId,
        string $dateFrom,
        string $dateTo,
        string $currency,
    ): array {
        $currency = strtoupper($currency);
        $client = $this->eligibleClient($companyId, (int) $branch->id, $clientId);
        $timezone = (string) ($branch->zona_horaria ?: config('app.timezone'));
        $databaseTimezone = $this->databaseTimezone();

        $linkedDocuments = $this->eligibleDocumentsQuery($companyId, (int) $branch->id)
            ->where('ticket.cliente_id', $clientId)
            ->where('document.moneda', $currency)
            ->whereDate('document.fecha_emision', '<=', $dateTo)
            ->select([
                'document.id as document_id',
                'document.fecha_emision as document_date',
                'document.total as document_total',
                'document.origen_clave as document_origin_key',
                'ticket.id as ticket_id',
                'ticket.codigo as ticket_code',
                'ticket.total as ticket_total',
                'ticket.registrado_at as ticket_registered_at',
                'link.importe_aplicado as linked_amount',
            ])
            ->orderBy('document.fecha_emision')
            ->orderBy('ticket.id')
            ->orderBy('document.id')
            ->get();

        $this->assertConsistentFinancialLinks($linkedDocuments);

        $documents = $linkedDocuments->values();
        $periodTicketIds = $documents
            ->filter(fn (object $document): bool => CarbonImmutable::parse(
                (string) $document->document_date,
            )->format('Y-m-d') >= $dateFrom)
            ->pluck('ticket_id')
            ->all();

        $weighings = DB::table('pesadas_despacho_productos')
            ->whereIn('ticket_despacho_producto_id', $periodTicketIds)
            ->orderBy('ticket_despacho_producto_id')
            ->orderBy('numero')
            ->orderBy('id')
            ->get([
                'id',
                'ticket_despacho_producto_id',
                'numero',
                'producto_nombre_snapshot',
                'variacion_nombre_snapshot',
                'modo_precio_snapshot',
                'precio_venta_snapshot',
                'cantidad',
                'peso_neto_kg',
                'importe',
            ])
            ->groupBy('ticket_despacho_producto_id');

        $openingSales = '0.00';
        $salesTotal = '0.00';
        $saleRows = collect();
        $ticketCount = 0;

        foreach ($documents as $document) {
            $amount = FinancialMoney::normalize((string) $document->document_total);
            $date = CarbonImmutable::parse((string) $document->document_date)->format('Y-m-d');

            if ($date < $dateFrom) {
                $openingSales = FinancialMoney::add($openingSales, $amount);

                continue;
            }

            $ticketCount++;
            $salesTotal = FinancialMoney::add($salesTotal, $amount);
            $registeredAt = CarbonImmutable::parse(
                (string) $document->ticket_registered_at,
                $databaseTimezone,
            )->setTimezone($timezone);
            $lines = $weighings->get((int) $document->ticket_id, collect())->values();
            $saleRows->push(...$this->saleRows(
                $document,
                $lines,
                $amount,
                $date,
                $registeredAt,
            ));
        }

        $payments = $this->paymentsForDocuments(
            $companyId,
            $clientId,
            $currency,
            $documents->pluck('document_id'),
            $dateTo,
            $timezone,
        );
        $openingPayments = '0.00';
        $paymentsTotal = '0.00';
        $paymentRows = collect();
        $paymentCount = 0;

        foreach ($payments as $payment) {
            $localDateTime = CarbonImmutable::parse(
                (string) $payment->fecha_hora,
                $databaseTimezone,
            )->setTimezone($timezone);
            $routeReceivedDate = trim((string) $payment->route_received_date);
            $date = $routeReceivedDate !== ''
                ? $routeReceivedDate
                : $localDateTime->format('Y-m-d');
            $amount = FinancialMoney::normalize((string) $payment->applied_amount);

            if ($date < $dateFrom) {
                $openingPayments = FinancialMoney::add($openingPayments, $amount);

                continue;
            }

            $paymentCount++;
            $paymentsTotal = FinancialMoney::add($paymentsTotal, $amount);
            $method = trim((string) ($payment->payment_method_name ?: $payment->metodo));
            $reference = trim((string) $payment->referencia);
            $observations = trim((string) $payment->observaciones);
            $paymentType = (string) $payment->tipo;
            $documentCode = collect([
                $payment->route_collection_reference,
                $payment->route_collection_code,
                $payment->codigo,
                'PG-'.$payment->id,
            ])->first(fn (mixed $value): bool => trim((string) $value) !== '');
            $detail = collect($paymentType === Pago::TYPE_CUSTOMER_DISCOUNT
                ? [$observations, $reference]
                : [$method, $reference])
                ->filter(fn (string $value): bool => $value !== '')
                ->unique()
                ->implode(' · ');

            $paymentRows->push([
                'kind' => 'PAYMENT',
                'payment_type' => $paymentType,
                'movement_label' => $this->receivableApplicationLabel($paymentType),
                'date' => $date,
                'document' => (string) $documentCode,
                'product' => null,
                'variation' => null,
                'quantity' => null,
                'net_weight_kg' => null,
                'detail' => $detail !== '' ? $detail : 'Abono aplicado',
                'price' => null,
                'price_mode' => null,
                'sale' => '0.00',
                'payment' => $amount,
                'balance' => '0.00',
                'show_balance' => true,
                '_effect' => FinancialMoney::subtract('0.00', $amount),
                '_sort' => $date.' '.$localDateTime->format('H:i:s').'-2-'
                    .str_pad((string) $payment->id, 12, '0', STR_PAD_LEFT),
            ]);
        }

        $openingBalance = FinancialMoney::subtract($openingSales, $openingPayments);
        $balance = $openingBalance;
        $rows = $saleRows
            ->concat($paymentRows)
            ->sortBy('_sort', SORT_STRING)
            ->values()
            ->map(function (array $row) use (&$balance): array {
                $balance = FinancialMoney::add($balance, $row['_effect']);
                $row['balance'] = $balance;
                unset($row['_effect'], $row['_sort']);

                return $row;
            });

        return [
            'client' => [
                'id' => (int) $client->id,
                'name' => (string) $client->nombre_razon_social,
                'document_type' => $client->tipo_documento,
                'document' => $client->numero_documento,
            ],
            'period' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'branch' => $this->branchPayload($branch),
            'currency' => $currency,
            'opening_balance' => $openingBalance,
            'sales_total' => $salesTotal,
            'payments_total' => $paymentsTotal,
            'ending_balance' => $balance,
            'ticket_count' => $ticketCount,
            'payment_count' => $paymentCount,
            'rows' => $rows,
            'generated_at' => now($timezone)->toIso8601String(),
        ];
    }

    private function eligibleDocumentsQuery(int $companyId, int $branchId): Builder
    {
        return DB::table('tickets_despacho_productos as ticket')
            ->join(
                'comprobante_tickets_despacho_productos as link',
                'link.ticket_despacho_producto_id',
                '=',
                'ticket.id',
            )
            ->join('comprobantes as document', function ($join) use ($companyId): void {
                $join->on('document.id', '=', 'link.comprobante_id')
                    ->where('document.empresa_id', '=', $companyId);
            })
            ->where('ticket.empresa_id', $companyId)
            ->where('ticket.sucursal_id', $branchId)
            ->whereNotNull('ticket.cliente_id')
            ->where('ticket.estado', TicketDespachoProducto::STATUS_REGISTERED)
            ->where('document.operacion', Comprobante::OPERATION_SALE)
            ->where('document.naturaleza', Comprobante::NATURE_CHARGE)
            ->where('document.estado', '<>', Comprobante::STATUS_VOIDED)
            ->whereColumn('document.tercero_id', 'ticket.cliente_id')
            ->whereColumn('document.moneda', 'ticket.moneda');
    }

    /** @param Collection<int, object> $documents */
    private function assertConsistentFinancialLinks(Collection $documents): void
    {
        if ($documents->isEmpty()) {
            return;
        }

        $documentIds = $documents->pluck('document_id')->unique()->values()->all();
        $ticketIds = $documents->pluck('ticket_id')->unique()->values()->all();
        $hasSharedDocument = DB::table('comprobante_tickets_despacho_productos')
            ->whereIn('comprobante_id', $documentIds)
            ->groupBy('comprobante_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasSharedTicket = DB::table('comprobante_tickets_despacho_productos')
            ->whereIn('ticket_despacho_producto_id', $ticketIds)
            ->groupBy('ticket_despacho_producto_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasOtherModuleLink = DB::table('comprobante_tickets')
            ->whereIn('comprobante_id', $documentIds)
            ->exists()
            || DB::table('comprobante_pesadas')
                ->whereIn('comprobante_id', $documentIds)
                ->exists()
            || DB::table('compras')
                ->whereIn('comprobante_id', $documentIds)
                ->exists();
        $hasOwnershipMismatch = $documents->contains(function (object $document): bool {
            $expectedOrigin = 'VENTA:TICKET_PRODUCTOS:'.(int) $document->ticket_id;

            return (string) $document->document_origin_key !== $expectedOrigin
                || FinancialMoney::compare(
                    (string) $document->linked_amount,
                    (string) $document->document_total,
                ) !== 0
                || FinancialMoney::compare(
                    (string) $document->ticket_total,
                    (string) $document->document_total,
                ) !== 0;
        });

        if ($hasSharedDocument
            || $hasSharedTicket
            || $hasOtherModuleLink
            || $hasOwnershipMismatch) {
            abort(
                409,
                'No se puede generar el estado de cuenta porque existen vínculos financieros inconsistentes en los tickets de Despacho de productos.',
            );
        }
    }

    private function eligibleClient(int $companyId, int $branchId, int $clientId): Tercero
    {
        $client = Tercero::query()
            ->where('empresa_id', $companyId)
            ->find($clientId);
        $hasHistory = $client && $this->eligibleDocumentsQuery($companyId, $branchId)
            ->where('ticket.cliente_id', $clientId)
            ->exists();

        if (! $hasHistory) {
            throw ValidationException::withMessages([
                'client_id' => 'El cliente no tiene despachos de productos vigentes en esta sucursal.',
            ]);
        }

        return $client;
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function saleRows(
        object $document,
        Collection $lines,
        string $documentAmount,
        string $date,
        CarbonImmutable $registeredAt,
    ): array {
        if ($lines->isEmpty()) {
            return [[
                'kind' => 'SALE',
                'date' => $date,
                'document' => (string) $document->ticket_code,
                'product' => 'Venta de productos',
                'variation' => null,
                'quantity' => null,
                'net_weight_kg' => null,
                'detail' => 'Detalle no disponible',
                'price' => null,
                'price_mode' => null,
                'sale' => $documentAmount,
                'payment' => '0.00',
                'balance' => '0.00',
                'show_balance' => true,
                '_effect' => $documentAmount,
                '_sort' => $date.' '.$registeredAt->format('H:i:s').'-1-'
                    .str_pad((string) $document->ticket_id, 12, '0', STR_PAD_LEFT).'-000000',
            ]];
        }

        $remaining = $documentAmount;
        $lastIndex = $lines->count() - 1;

        return $lines->map(function (object $line, int $index) use (
            $document,
            $date,
            $lastIndex,
            &$remaining,
            $registeredAt,
        ): array {
            $amount = $index === $lastIndex
                ? $remaining
                : FinancialMoney::normalize((string) $line->importe);
            if ($index !== $lastIndex) {
                $remaining = FinancialMoney::subtract($remaining, $amount);
            }

            $priceMode = (string) $line->modo_precio_snapshot;

            return [
                'kind' => 'SALE',
                'date' => $date,
                'document' => (string) $document->ticket_code,
                'product' => (string) $line->producto_nombre_snapshot,
                'variation' => filled($line->variacion_nombre_snapshot)
                    ? (string) $line->variacion_nombre_snapshot
                    : null,
                'quantity' => (int) $line->cantidad,
                'net_weight_kg' => $this->decimal((string) $line->peso_neto_kg, 3),
                'detail' => $priceMode === ProductoDespacho::PRICE_MODE_UNIT
                    ? 'Precio por unidad'
                    : 'Precio por kilogramo',
                'price' => $this->decimal((string) $line->precio_venta_snapshot, 4),
                'price_mode' => $priceMode,
                'sale' => $amount,
                'payment' => '0.00',
                'balance' => '0.00',
                'show_balance' => $index === $lastIndex,
                '_effect' => $amount,
                '_sort' => $date.' '.$registeredAt->format('H:i:s').'-1-'
                    .str_pad((string) $document->ticket_id, 12, '0', STR_PAD_LEFT).'-'
                    .str_pad((string) $line->numero, 6, '0', STR_PAD_LEFT),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, mixed>  $documentIds
     * @return Collection<int, object>
     */
    private function paymentsForDocuments(
        int $companyId,
        int $clientId,
        string $currency,
        Collection $documentIds,
        string $dateTo,
        string $timezone,
    ): Collection {
        if ($documentIds->isEmpty()) {
            return collect();
        }

        $toExclusive = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, $timezone)
            ->addDay()
            ->startOfDay()
            ->setTimezone($this->databaseTimezone())
            ->format('Y-m-d H:i:s');

        return DB::table('pago_aplicaciones as application')
            ->join('pagos as payment', 'payment.id', '=', 'application.pago_id')
            ->leftJoin('metodos_pago as payment_method', 'payment_method.id', '=', 'payment.metodo_pago_id')
            ->leftJoinSub(
                $this->collectionPaymentDetails($companyId),
                'collection_route',
                function ($join): void {
                    $join->on('collection_route.pago_id', '=', 'payment.id')
                        ->on('collection_route.cliente_id', '=', 'payment.cliente_id');
                },
            )
            ->whereIn('application.comprobante_id', $documentIds->unique()->values()->all())
            ->where('application.lado', PagoAplicacion::SIDE_RECEIVABLE)
            ->where('payment.empresa_id', $companyId)
            ->where('payment.cliente_id', $clientId)
            ->where('payment.moneda', $currency)
            ->where('payment.estado', Pago::STATUS_REGISTERED)
            ->whereNull('payment.reversa_de_pago_id')
            ->where(function (Builder $dates) use ($dateTo, $toExclusive): void {
                $dates->where(function (Builder $received) use ($dateTo): void {
                    $received->whereNotNull('collection_route.fecha_recepcion')
                        ->where('collection_route.fecha_recepcion', '<=', $dateTo);
                })->orWhere(function (Builder $direct) use ($toExclusive): void {
                    $direct->whereNull('collection_route.fecha_recepcion')
                        ->where('payment.fecha_hora', '<', $toExclusive);
                });
            })
            ->groupBy([
                'payment.id',
                'payment.codigo',
                'payment.fecha_hora',
                'payment.tipo',
                'payment.direccion',
                'payment.metodo',
                'payment.referencia',
                'payment.observaciones',
                'payment_method.nombre',
                'collection_route.fecha_recepcion',
                'collection_route.referencia',
                'collection_route.codigo',
            ])
            ->orderBy('payment.fecha_hora')
            ->orderBy('payment.id')
            ->get([
                'payment.id',
                'payment.codigo',
                'payment.fecha_hora',
                'payment.tipo',
                'payment.direccion',
                'payment.metodo',
                'payment.referencia',
                'payment.observaciones',
                'payment_method.nombre as payment_method_name',
                'collection_route.fecha_recepcion as route_received_date',
                'collection_route.referencia as route_collection_reference',
                'collection_route.codigo as route_collection_code',
                DB::raw('SUM(application.importe_aplicado) as applied_amount'),
            ]);
    }

    private function collectionPaymentDetails(int $companyId): Builder
    {
        return DB::table('cobranza_detalles as detail')
            ->join('cobranzas as collection', 'collection.id', '=', 'detail.cobranza_id')
            ->where('collection.empresa_id', $companyId)
            ->select([
                'detail.pago_id',
                'detail.cliente_id',
                'detail.fecha_recepcion',
                'collection.referencia',
                'collection.codigo',
            ]);
    }

    private function receivableApplicationLabel(string $type): string
    {
        return match ($type) {
            Pago::TYPE_CUSTOMER_COLLECTION,
            Pago::TYPE_RETAIL_COLLECTION => 'Pago recibido',
            Pago::TYPE_DIRECT_PAYMENT => 'Pago directo',
            Pago::TYPE_CUSTOMER_DISCOUNT => 'Descuento',
            default => 'Abono aplicado',
        };
    }

    /** @param object{id: int, codigo: string, nombre: string, zona_horaria: string} $branch */
    private function branchPayload(object $branch): array
    {
        return [
            'id' => (int) $branch->id,
            'code' => (string) $branch->codigo,
            'name' => (string) $branch->nombre,
            'timezone' => (string) $branch->zona_horaria,
        ];
    }

    private function databaseTimezone(): string
    {
        $connection = DB::connection()->getName();

        return (string) (
            config("database.connections.{$connection}.timezone")
            ?: config('app.timezone', 'UTC')
        );
    }

    private function decimal(string $value, int $scale): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
