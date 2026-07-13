<?php

namespace App\Services;

use App\Support\FinancialMoney;
use Illuminate\Support\Facades\DB;

class FinancialCounterpartySummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function forCustomer(int $companyId, int $customerId): array
    {
        $currency = $this->companyCurrency($companyId);
        $documents = $this->documentTotals($companyId, $customerId, 'VENTA', $currency);
        $payments = $this->paymentTotals(
            $companyId,
            'cliente_id',
            $customerId,
            ['COBRO_CLIENTE', 'COBRO_MINORISTA', 'PAGO_DIRECTO'],
            'CXC',
            $currency,
        );
        $portfolio = FinancialMoney::subtract($documents['cargo_pendiente'], $documents['abono_pendiente']);
        $unapplied = $this->positiveDifference($payments['pagado'], $payments['aplicado']);

        return [
            'currency' => $currency,
            'documented' => $this->signedDocumentTotal($documents),
            'charges' => $documents['cargos'],
            'credits' => $documents['abonos'],
            'payments' => $payments['pagado'],
            'applied' => $payments['aplicado'],
            'unapplied' => $unapplied,
            'pending' => FinancialMoney::subtract($portfolio, $unapplied),
            'direct_to_providers' => $this->sumPayments(
                $companyId,
                'cliente_id',
                $customerId,
                ['PAGO_DIRECTO'],
                $currency,
            ),
            'document_count' => $documents['cantidad'],
            'payment_count' => $payments['cantidad'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forProvider(int $companyId, int $providerId): array
    {
        $currency = $this->companyCurrency($companyId);
        $documents = $this->documentTotals($companyId, $providerId, 'COMPRA', $currency);
        $payments = $this->paymentTotals(
            $companyId,
            'proveedor_id',
            $providerId,
            ['PAGO_DIRECTO', 'PAGO_PROVEEDOR'],
            'CXP',
            $currency,
        );
        $portfolio = FinancialMoney::subtract($documents['cargo_pendiente'], $documents['abono_pendiente']);
        $unapplied = $this->positiveDifference($payments['pagado'], $payments['aplicado']);
        $pendingCosts = DB::table('costos_compra_pesadas')
            ->where('proveedor_id', $providerId)
            ->where('estado', 'PENDIENTE')
            ->selectRaw('COUNT(*) as cantidad')
            ->selectRaw('COALESCE(SUM(peso_kg), 0) as peso_kg')
            ->first();

        return [
            'currency' => $currency,
            'documented' => $this->signedDocumentTotal($documents),
            'charges' => $documents['cargos'],
            'credits' => $documents['abonos'],
            'payments' => $payments['pagado'],
            'applied' => $payments['aplicado'],
            'unapplied' => $unapplied,
            'pending' => FinancialMoney::subtract($portfolio, $unapplied),
            'paid_directly_by_clients' => $this->sumPayments(
                $companyId,
                'proveedor_id',
                $providerId,
                ['PAGO_DIRECTO'],
                $currency,
            ),
            'paid_by_company' => $this->sumPayments(
                $companyId,
                'proveedor_id',
                $providerId,
                ['PAGO_PROVEEDOR'],
                $currency,
            ),
            'document_count' => $documents['cantidad'],
            'payment_count' => $payments['cantidad'],
            'pending_costs' => [
                'count' => (int) ($pendingCosts->cantidad ?? 0),
                'weight_kg' => bcadd((string) ($pendingCosts->peso_kg ?? 0), '0', 3),
            ],
            'recent_direct_deposits' => $this->recentDirectDeposits($companyId, $providerId, $currency),
        ];
    }

    /**
     * @return array{cargos: string, abonos: string, cargo_pendiente: string, abono_pendiente: string, cantidad: int}
     */
    private function documentTotals(
        int $companyId,
        int $thirdPartyId,
        string $operation,
        string $currency,
    ): array {
        $row = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $thirdPartyId)
            ->where('operacion', $operation)
            ->where('moneda', $currency)
            ->where('estado', '<>', 'ANULADO')
            ->selectRaw("COALESCE(SUM(CASE WHEN naturaleza = 'CARGO' THEN total ELSE 0 END), 0) as cargos")
            ->selectRaw("COALESCE(SUM(CASE WHEN naturaleza = 'ABONO' THEN total ELSE 0 END), 0) as abonos")
            ->selectRaw("COALESCE(SUM(CASE WHEN naturaleza = 'CARGO' THEN saldo_pendiente ELSE 0 END), 0) as cargo_pendiente")
            ->selectRaw("COALESCE(SUM(CASE WHEN naturaleza = 'ABONO' THEN saldo_pendiente ELSE 0 END), 0) as abono_pendiente")
            ->selectRaw('COUNT(*) as cantidad')
            ->first();

        return [
            'cargos' => FinancialMoney::normalize((string) ($row->cargos ?? 0)),
            'abonos' => FinancialMoney::normalize((string) ($row->abonos ?? 0)),
            'cargo_pendiente' => FinancialMoney::normalize((string) ($row->cargo_pendiente ?? 0)),
            'abono_pendiente' => FinancialMoney::normalize((string) ($row->abono_pendiente ?? 0)),
            'cantidad' => (int) ($row->cantidad ?? 0),
        ];
    }

    /**
     * @param  list<string>  $types
     * @return array{pagado: string, aplicado: string, cantidad: int}
     */
    private function paymentTotals(
        int $companyId,
        string $partyColumn,
        int $partyId,
        array $types,
        string $side,
        string $currency,
    ): array {
        $base = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where($partyColumn, $partyId)
            ->whereIn('tipo', $types)
            ->where('moneda', $currency)
            ->where('estado', 'REGISTRADO')
            ->whereNull('reversa_de_pago_id');
        $paid = (clone $base)->sum('importe');
        $count = (clone $base)->count();
        $applied = DB::table('pago_aplicaciones as aplicacion')
            ->join('pagos as pago', 'pago.id', '=', 'aplicacion.pago_id')
            ->where('pago.empresa_id', $companyId)
            ->where("pago.{$partyColumn}", $partyId)
            ->whereIn('pago.tipo', $types)
            ->where('pago.moneda', $currency)
            ->where('pago.estado', 'REGISTRADO')
            ->whereNull('pago.reversa_de_pago_id')
            ->where('aplicacion.lado', $side)
            ->sum('aplicacion.importe_aplicado');

        return [
            'pagado' => FinancialMoney::normalize((string) $paid),
            'aplicado' => FinancialMoney::normalize((string) $applied),
            'cantidad' => $count,
        ];
    }

    /** @param list<string> $types */
    private function sumPayments(
        int $companyId,
        string $partyColumn,
        int $partyId,
        array $types,
        string $currency,
    ): string {
        $total = DB::table('pagos')
            ->where('empresa_id', $companyId)
            ->where($partyColumn, $partyId)
            ->whereIn('tipo', $types)
            ->where('moneda', $currency)
            ->where('estado', 'REGISTRADO')
            ->whereNull('reversa_de_pago_id')
            ->sum('importe');

        return FinancialMoney::normalize((string) $total);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentDirectDeposits(int $companyId, int $providerId, string $currency): array
    {
        return DB::table('pagos as pago')
            ->join('terceros as cliente', 'cliente.id', '=', 'pago.cliente_id')
            ->leftJoin('cuentas_financieras as cuenta', 'cuenta.id', '=', 'pago.cuenta_destino_id')
            ->leftJoin('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('pago.empresa_id', $companyId)
            ->where('pago.proveedor_id', $providerId)
            ->where('pago.tipo', 'PAGO_DIRECTO')
            ->where('pago.moneda', $currency)
            ->where('pago.estado', 'REGISTRADO')
            ->whereNull('pago.reversa_de_pago_id')
            ->orderByDesc('pago.fecha_hora')
            ->orderByDesc('pago.id')
            ->limit(10)
            ->get([
                'pago.id',
                'pago.codigo',
                'pago.fecha_hora',
                'pago.importe',
                'pago.moneda',
                'pago.metodo',
                'pago.referencia',
                'cliente.id as cliente_id',
                'cliente.nombre_razon_social as cliente_nombre',
                'entidad.razon_social as entidad_nombre',
                'cuenta.alias as cuenta_alias',
            ])
            ->map(fn (object $payment): array => [
                'id' => (int) $payment->id,
                'code' => $payment->codigo,
                'paid_at' => $payment->fecha_hora,
                'amount' => FinancialMoney::normalize((string) $payment->importe),
                'currency' => $payment->moneda,
                'method' => $payment->metodo,
                'reference' => $payment->referencia,
                'client' => [
                    'id' => (int) $payment->cliente_id,
                    'name' => $payment->cliente_nombre,
                ],
                'destination' => trim(implode(' · ', array_filter([
                    $payment->entidad_nombre,
                    $payment->cuenta_alias,
                ]))),
            ])
            ->all();
    }

    /** @param array{cargos: string, abonos: string} $documents */
    private function signedDocumentTotal(array $documents): string
    {
        return FinancialMoney::subtract($documents['cargos'], $documents['abonos']);
    }

    private function positiveDifference(string $left, string $right): string
    {
        $difference = FinancialMoney::subtract($left, $right);

        return FinancialMoney::compare($difference, '0.00') > 0 ? $difference : '0.00';
    }

    private function companyCurrency(int $companyId): string
    {
        return (string) (DB::table('empresas')->where('id', $companyId)->value('moneda') ?: 'PEN');
    }
}
