<?php

namespace App\Services;

use App\Models\TicketDespachoProducto;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProductDispatchGeneralReportService
{
    /**
     * @param  object{id: int, nombre: string, codigo: string, zona_horaria: string}  $branch
     * @return array<string, mixed>
     */
    public function report(int $companyId, object $branch, string $from, string $to): array
    {
        $timezone = (string) ($branch->zona_horaria ?: config('app.timezone'));
        $storageTimezone = (string) config('app.timezone');
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)
            ->startOfDay()->setTimezone($storageTimezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)
            ->addDay()->startOfDay()->setTimezone($storageTimezone);

        // Historical snapshots remain authoritative after a catalog rename or retirement.
        $rows = DB::table('tickets_despacho_productos as tickets')
            ->join('pesadas_despacho_productos as weighings', 'weighings.ticket_despacho_producto_id', '=', 'tickets.id')
            ->where('tickets.empresa_id', $companyId)
            ->where('tickets.sucursal_id', (int) $branch->id)
            ->where('tickets.estado', TicketDespachoProducto::STATUS_REGISTERED)
            ->where('tickets.registrado_at', '>=', $start)
            ->where('tickets.registrado_at', '<', $end)
            ->orderBy('tickets.registrado_at')
            ->orderBy('tickets.id')
            ->orderBy('weighings.numero')
            ->select([
                'tickets.id as ticket_id', 'tickets.registrado_at', 'tickets.moneda',
                'weighings.producto_despacho_id', 'weighings.producto_nombre_snapshot',
                'weighings.variacion_producto_despacho_id', 'weighings.variacion_nombre_snapshot',
                'weighings.cantidad', 'weighings.peso_leido_kg', 'weighings.merma_total_gramos',
                'weighings.tara_gramos', 'weighings.peso_neto_kg', 'weighings.importe',
            ])->cursor();

        $days = [];
        $summary = $this->emptyTotals();
        $allProducts = [];
        $allTickets = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->registrado_at, $storageTimezone)
                ->setTimezone($timezone)->toDateString();
            $productId = $row->producto_despacho_id === null ? null : (int) $row->producto_despacho_id;
            $productName = (string) $row->producto_nombre_snapshot;
            $variationId = $row->variacion_producto_despacho_id === null ? null : (int) $row->variacion_producto_despacho_id;
            $variationName = trim((string) $row->variacion_nombre_snapshot);
            $variationName = $variationName !== '' ? $variationName : ($variationId === null ? null : 'Subproducto #'.$variationId);
            $productKey = json_encode([
                $productId === null ? ['snapshot', $productName] : ['id', $productId],
                $variationId !== null ? ['id', $variationId] : ($variationName === null ? ['base'] : ['snapshot', $variationName]),
            ], JSON_THROW_ON_ERROR);

            if (! isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    ...$this->emptyTotals(),
                    'products' => [],
                    '_tickets' => [],
                ];
            }
            if (! isset($days[$date]['products'][$productKey])) {
                $days[$date]['products'][$productKey] = [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'variation_id' => $variationId,
                    'variation_name' => $variationName,
                    'display_name' => $productName.($variationName === null ? '' : ' · '.$variationName),
                    ...$this->emptyTotals(),
                ];
            }

            $this->addWeighing($days[$date]['products'][$productKey], $row);
            $this->addWeighing($days[$date], $row);
            $this->addWeighing($summary, $row);
            $days[$date]['_tickets'][(int) $row->ticket_id] = true;
            $allTickets[(int) $row->ticket_id] = true;
            $allProducts[$productKey] = true;
        }

        ksort($days);
        foreach ($days as &$day) {
            $day['product_count'] = count($day['products']);
            $day['ticket_count'] = count($day['_tickets']);
            unset($day['_tickets']);
            $day['amounts'] = $this->amounts($day['amounts']);
            foreach ($day['products'] as &$product) {
                $product['amounts'] = $this->amounts($product['amounts']);
            }
            unset($product);
            $day['products'] = array_values($day['products']);
            usort($day['products'], static function (array $left, array $right): int {
                return strnatcasecmp($left['display_name'], $right['display_name'])
                    ?: ($left['product_id'] <=> $right['product_id'])
                    ?: ($left['variation_id'] <=> $right['variation_id']);
            });
        }
        unset($day);

        $summary['day_count'] = count($days);
        $summary['product_count'] = count($allProducts);
        $summary['ticket_count'] = count($allTickets);
        $summary['amounts'] = $this->amounts($summary['amounts']);
        $now = CarbonImmutable::now($timezone);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'today' => $now->toDateString(),
            'branch' => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->nombre,
                'code' => (string) $branch->codigo,
                'timezone' => $timezone,
            ],
            'generated_at' => $now->toIso8601String(),
            'summary' => $summary,
            'days' => array_values($days),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyTotals(): array
    {
        return [
            'weighing_count' => 0,
            'quantity' => '0',
            'read_weight_kg' => '0.000',
            'waste_weight_kg' => '0.000',
            'tare_weight_kg' => '0.000',
            'net_weight_kg' => '0.000',
            'amounts' => [],
        ];
    }

    /** @param array<string, mixed> $totals */
    private function addWeighing(array &$totals, object $row): void
    {
        $totals['weighing_count']++;
        $totals['quantity'] = bcadd($totals['quantity'], (string) $row->cantidad, 0);
        $totals['read_weight_kg'] = bcadd($totals['read_weight_kg'], (string) $row->peso_leido_kg, 3);
        $totals['waste_weight_kg'] = bcadd($totals['waste_weight_kg'], bcdiv((string) $row->merma_total_gramos, '1000', 3), 3);
        $totals['tare_weight_kg'] = bcadd($totals['tare_weight_kg'], bcdiv((string) $row->tara_gramos, '1000', 3), 3);
        $totals['net_weight_kg'] = bcadd($totals['net_weight_kg'], (string) $row->peso_neto_kg, 3);
        $currency = strtoupper((string) $row->moneda);
        $totals['amounts'][$currency] = FinancialMoney::add(
            $totals['amounts'][$currency] ?? '0.00',
            FinancialMoney::normalize($row->importe),
        );
    }

    /**
     * @param  array<string, string>  $amounts
     * @return list<array{currency: string, amount: string}>
     */
    private function amounts(array $amounts): array
    {
        ksort($amounts);

        return array_map(
            static fn (string $currency, string $amount): array => ['currency' => $currency, 'amount' => $amount],
            array_keys($amounts),
            array_values($amounts),
        );
    }
}
