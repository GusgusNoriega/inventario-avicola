<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\User;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProductDispatchCustomerAccountService
{
    public function __construct(
        private readonly ProductDispatchAccountStatementService $statements,
        private readonly ProductDispatchCustomerPaymentService $payments,
        private readonly ProductDispatchCustomerAdjustmentService $adjustments,
    ) {}

    public function account(int $companyId, object $branch, User $actor, array $filters): array
    {
        // A date/search filter never changes the client's current debt or available credit.
        $statement = $this->statements->statement($companyId, $branch, (int) $filters['cliente_id'],
            '0001-01-01', '9998-12-31', $filters['moneda']);
        $paymentIds = $statement['rows']->pluck('payment_id')->filter()->unique()->all();
        $documentIds = $statement['rows']->pluck('document_id')->filter()->unique()->all();
        $modulePayments = DB::table('pagos_despacho_productos')->where('empresa_id', $companyId)
            ->where('sucursal_id', $branch->id)->whereIn('pago_id', $paymentIds)->pluck('id', 'pago_id');
        $wrappers = DB::table('ajustes_despacho_productos')->where('empresa_id', $companyId)
            ->where('sucursal_id', $branch->id)->where(function ($query) use ($paymentIds, $documentIds): void {
                $query->whereIn('pago_id', $paymentIds)->orWhereIn('comprobante_id', $documentIds);
            })->get(['id', 'comprobante_id', 'pago_id', 'fecha_hora']);
        $creditIds = $wrappers->whereNotNull('pago_id')->pluck('id', 'pago_id');
        $debtIds = $wrappers->whereNotNull('comprobante_id')->pluck('id', 'comprobante_id');
        $adjustmentDates = $wrappers->pluck('fecha_hora', 'id');
        $collectionPayments = DB::table('cobranza_detalles')->whereIn('pago_id', $paymentIds)->pluck('pago_id')->flip();
        $otherBranchPayments = DB::table('pagos_despacho_productos')->where('empresa_id', $companyId)
            ->where('sucursal_id', '<>', $branch->id)->whereIn('pago_id', $paymentIds)->pluck('pago_id')
            ->merge(DB::table('ajustes_despacho_productos')->where('empresa_id', $companyId)
                ->where('sucursal_id', '<>', $branch->id)->whereIn('pago_id', $paymentIds)->pluck('pago_id'))->flip();
        $ticketVersions = DB::table('tickets_despacho_productos')->where('empresa_id', $companyId)
            ->where('sucursal_id', $branch->id)->where('cliente_id', $filters['cliente_id'])->pluck('updated_at', 'id');
        $appliedTicketIds = DB::table('comprobante_tickets_despacho_productos as link')
            ->join('pago_aplicaciones as application', 'application.comprobante_id', '=', 'link.comprobante_id')
            ->join('pagos as payment', 'payment.id', '=', 'application.pago_id')
            ->where('payment.empresa_id', $companyId)->where('payment.estado', Pago::STATUS_REGISTERED)
            ->whereIn('link.ticket_despacho_producto_id', $ticketVersions->keys()->all())
            ->whereNull('payment.reversa_de_pago_id')->distinct()->pluck('link.ticket_despacho_producto_id')->all();
        $rows = [];
        $mayManageTickets = $actor->hasPermission('PRODUCTOS_DESPACHO_TICKETS_GESTIONAR');
        $mayViewFinance = $actor->hasModule('MODULO_FINANZAS');

        foreach ($statement['rows'] as $source) {
            $kind = $source['kind'];
            if ($kind === 'SALE') {
                $id = $source['ticket_id'];
                $key = 'SALE:'.$id;
                if (isset($rows[$key])) {
                    $rows[$key]['amount'] = FinancialMoney::add($rows[$key]['amount'], $source['sale']);
                    $rows[$key]['sale'] = $rows[$key]['amount'];
                    $rows[$key]['notes'] .= ' · '.$source['product'].($source['variation'] ? ' / '.$source['variation'] : '');

                    continue;
                }
                $manageUrl = '/despacho-productos/tickets?'.http_build_query([
                    'edit_ticket' => $id, 'return_client' => $filters['cliente_id'], 'moneda' => $filters['moneda'],
                ]);
                $hasApplications = in_array($id, $appliedTicketIds, true);
                $row = [
                    'id' => $id, 'ticket_id' => $id, 'kind' => 'SALE', 'code' => $source['document'],
                    'client' => $statement['client'], 'currency' => $statement['currency'],
                    'amount' => $source['sale'], 'date_time' => $source['date_time'],
                    'notes' => $source['product'].($source['variation'] ? ' / '.$source['variation'] : ''), 'reference' => null,
                    'can_edit' => $mayManageTickets, 'can_delete' => $mayManageTickets && ! $hasApplications,
                    'version' => $ticketVersions->get($id) ? CarbonImmutable::parse($ticketVersions->get($id), config('app.timezone'))->toISOString() : null,
                    'edit_url' => null, 'delete_url' => $mayManageTickets ? '/despacho-productos/tickets/'.$id : null,
                    'manage_url' => $mayManageTickets ? $manageUrl : null,
                    'action_reason' => $mayManageTickets
                        ? ($hasApplications ? 'La venta tiene abonos aplicados. Elimina primero los abonos relacionados para poder eliminarla.'
                            : 'Edita la venta desde su ticket para actualizar productos y deuda juntos.')
                        : 'Se requiere permiso para gestionar tickets de Despacho de productos.',
                ];
            } elseif ($kind === 'PRIOR_DEBT') {
                $wrapperId = $debtIds->get($source['document_id']);
                $row = [
                    'id' => (int) ($wrapperId ?: $source['document_id']), 'kind' => 'PRIOR_DEBT',
                    'code' => $source['document'], 'amount' => $source['sale'], 'notes' => $source['detail'], 'reference' => null,
                    'date_time' => $wrapperId ? CarbonImmutable::parse($adjustmentDates->get($wrapperId), config('app.timezone'))
                        ->setTimezone($branch->zona_horaria ?: config('app.timezone'))->format('Y-m-d\TH:i') : $source['date'].'T00:00',
                    '_hydrate' => $wrapperId ? 'ADJUSTMENT' : 'LEGACY_DEBT',
                ];
                $key = 'PRIOR_DEBT:'.($wrapperId ? 'adjustment-' : 'document-').$row['id'];
            } else {
                $moduleId = $modulePayments->get($source['payment_id']);
                $creditId = $creditIds->get($source['payment_id']);
                if ($moduleId) {
                    $row = ['id' => (int) $moduleId, 'kind' => 'PAYMENT', 'can_edit' => true, 'can_delete' => true,
                        'code' => 'PCL-'.str_pad((string) $moduleId, 10, '0', STR_PAD_LEFT),
                        'amount' => $source['payment'], 'notes' => $source['notes'], 'reference' => $source['reference'],
                        'date_time' => $source['date_time'], '_hydrate' => 'PAYMENT',
                        'edit_url' => '/despacho-productos/pagos/'.$moduleId,
                        'delete_url' => '/despacho-productos/pagos/'.$moduleId];
                } elseif ($creditId) {
                    $row = ['id' => (int) $creditId, 'kind' => 'CREDIT',
                        'code' => 'SAF-'.str_pad((string) $creditId, 8, '0', STR_PAD_LEFT),
                        'amount' => $source['payment'], 'notes' => $source['notes'], 'reference' => null,
                        'date_time' => $source['date_time'], '_hydrate' => 'ADJUSTMENT'];
                } else {
                    $collection = $collectionPayments->has($source['payment_id']);
                    $otherBranch = $otherBranchPayments->has($source['payment_id']);
                    $origin = $collection ? '/finanzas/cobranzas'
                        : ($source['payment_type'] === Pago::TYPE_CUSTOMER_DISCOUNT
                            ? '/finanzas/descuentos-clientes' : '/finanzas/movimientos');
                    $row = [
                        'id' => $source['payment_id'], 'payment_id' => $source['payment_id'], 'kind' => 'APPLIED_PAYMENT',
                        'code' => $source['document'], 'client' => $statement['client'], 'currency' => $statement['currency'],
                        'amount' => $source['payment'], 'date_time' => $source['date'].'T'.substr($source['date_time'], 11),
                        'notes' => $source['detail'], 'reference' => null,
                        'can_edit' => false, 'can_delete' => false, 'edit_url' => null, 'delete_url' => null,
                        'origin_url' => ! $otherBranch && $mayViewFinance ? $origin : null,
                        'action_reason' => $otherBranch
                            ? 'Abono aplicado desde Pagos de clientes de otra sucursal. Se corrige en la sucursal donde fue registrado.'
                            : 'Abono aplicado desde otra sección. Se corrige desde su registro de origen para conservar sus aplicaciones.',
                    ];
                }
                $key = $row['kind'].':'.$row['id'];
            }
            $row['key'] = $key;
            $row['sale'] = $source['sale'];
            $row['payment'] = $source['payment'];
            $row['_search_detail'] = $source['detail'];
            $row['movement_label'] = match ($row['kind']) {
                'SALE' => 'Venta', 'PRIOR_DEBT' => 'Deuda anterior', 'CREDIT' => 'Saldo a favor',
                'PAYMENT' => 'Pago recibido', default => $source['movement_label'] ?? 'Abono aplicado',
            };
            $rows[$key] = $row;
        }

        $balance = '0.00';
        $transactions = collect($rows)->sortBy(fn (array $row): string => $row['date_time'].'-'.$row['key'], SORT_STRING)
            ->values()->map(function (array $row) use (&$balance): array {
                $balance = FinancialMoney::add($balance, FinancialMoney::subtract($row['sale'], $row['payment']));
                $row['balance'] = $balance;

                return $row;
            });
        $summary = [
            'client' => $statement['client'], 'currency' => $statement['currency'], 'balance' => $balance,
            'debt' => FinancialMoney::compare($balance, '0.00') > 0 ? $balance : '0.00',
            'credit' => FinancialMoney::compare($balance, '0.00') < 0 ? FinancialMoney::subtract('0.00', $balance) : '0.00',
            'charges_total' => $statement['charges_total'], 'payments_total' => $statement['payments_total'],
            'transaction_count' => $transactions->count(),
        ];
        $search = mb_strtolower(trim((string) ($filters['buscar'] ?? '')));
        $filtered = $transactions->filter(function (array $row) use ($filters, $search): bool {
            $date = substr($row['date_time'], 0, 10);

            return (empty($filters['date_from']) || $date >= $filters['date_from'])
                && (empty($filters['date_to']) || $date <= $filters['date_to'])
                && ($search === '' || str_contains(mb_strtolower(implode(' ', [
                    $row['code'], $row['notes'], $row['reference'], $row['movement_label'], $row['amount'],
                    $row['_search_detail'],
                ])), $search));
        })->reverse()->values();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($filtered->count() / $perPage));
        $page = min((int) ($filters['page'] ?? 1), $lastPage);
        $pageRows = $filtered->slice(($page - 1) * $perPage, $perPage)->values()->map(function (array $row) use ($companyId, $branch): array {
            // Fetch editable fields only for the displayed page, keeping query count bounded by page size.
            $details = match ($row['_hydrate'] ?? null) {
                'PAYMENT' => $this->payments->show($companyId, $branch, $row['id']),
                'ADJUSTMENT' => $this->adjustments->show($companyId, $branch, $row['id']),
                'LEGACY_DEBT' => $this->adjustments->legacyDebt($companyId, $branch, $row['id']),
                default => [],
            };
            unset($row['_hydrate'], $row['_search_detail']);

            return [...$row, ...$details];
        });

        return [
            'data' => $pageRows->all(),
            'summary' => $summary,
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $filtered->count()],
        ];
    }
}
