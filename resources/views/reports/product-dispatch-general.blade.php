@php
  $number = static function (mixed $value, int $precision = 0): string {
      [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
      $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer);
      return $grouped.($precision > 0 ? '.'.str_pad(substr($fraction, 0, $precision), $precision, '0') : '');
  };
  $date = static fn (string $value): string => \Carbon\CarbonImmutable::parse($value)->format('d/m/Y');
  $from = $date($report['period']['from']);
  $to = $date($report['period']['to']);
  $summary = $report['summary'];
@endphp
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte general - Despacho de productos</title>
  <style>
    @page { margin: 13mm 12mm 15mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.4; color: {{ $reportPalette['body_text'] }}; background: {{ $reportPalette['page_background'] }}; }
    table { width: 100%; border-collapse: collapse; }
    .masthead { margin-bottom: 14px; background: {{ $reportPalette['primary'] }}; color: {{ $reportPalette['primary_text'] }}; }
    .masthead td { padding: 15px; vertical-align: middle; }
    .masthead .company { width: 55%; }
    .masthead .report-title { width: 45%; text-align: right; }
    .masthead strong { display: block; font-size: 20px; line-height: 1.2; }
    .masthead .company strong { font-size: 15px; }
    .masthead span { display: block; margin-top: 5px; font-size: 9px; }
    .metadata { margin-bottom: 15px; }
    .metadata td { vertical-align: top; width: 50%; }
    .metadata .right { text-align: right; }
    .muted { color: {{ $reportPalette['muted_text'] }}; }
    .metrics { table-layout: fixed; margin-bottom: 17px; }
    .metrics td { width: 25%; padding: 11px 12px; vertical-align: top; background: {{ $reportPalette['accent'] }}; border: 1px solid {{ $reportPalette['border'] }}; }
    .metrics small { display: block; margin-bottom: 5px; color: {{ $reportPalette['muted_text'] }}; font-size: 8px; }
    .metrics strong { display: block; color: {{ $reportPalette['primary'] }}; font-size: 18px; line-height: 1.3; }
    .metrics .amount strong { font-size: 15px; }
    .metrics span { display: block; margin-top: 4px; font-size: 8px; color: {{ $reportPalette['muted_text'] }}; }
    .daily { margin-bottom: 16px; }
    .daily thead { display: table-header-group; }
    .daily tr { page-break-inside: avoid; }
    .daily th { padding: 7px 6px; font-size: 8px; font-weight: bold; text-align: right; background: {{ $reportPalette['accent'] }}; border-bottom: 1px solid {{ $reportPalette['border'] }}; }
    .daily th:first-child, .daily td:first-child { text-align: left; }
    .daily .day-heading th { padding: 10px; background: {{ $reportPalette['primary'] }}; color: {{ $reportPalette['primary_text'] }}; text-align: left; font-size: 13px; }
    .day-heading span { font-size: 8px; font-weight: normal; }
    .daily td { padding: 8px 6px; border-bottom: 1px solid {{ $reportPalette['border'] }}; text-align: right; vertical-align: middle; font-size: 9px; overflow-wrap: break-word; }
    .daily .product { font-size: 10px; font-weight: bold; }
    .daily .product small { display: block; margin-top: 2px; font-size: 7px; font-weight: normal; color: {{ $reportPalette['muted_text'] }}; }
    .daily .net { font-weight: bold; color: {{ $reportPalette['primary'] }}; }
    .daily .money { display: block; white-space: nowrap; }
    .daily .last-product { page-break-after: avoid; }
    .daily .day-total td { padding: 9px 6px; font-weight: bold; border-top: 2px solid {{ $reportPalette['primary'] }}; background: {{ $reportPalette['secondary'] }}; color: {{ $reportPalette['secondary_text'] }}; }
    .empty { padding: 35px 20px; border: 1px solid {{ $reportPalette['border'] }}; background: {{ $reportPalette['accent'] }}; text-align: center; }
    .empty strong { display: block; font-size: 15px; margin-bottom: 6px; }
    .notes { padding-top: 6px; font-size: 8px; color: {{ $reportPalette['muted_text'] }}; }
  </style>
</head>
<body>
  <table class="masthead">
    <tr>
      <td class="company"><strong>{{ $company->nombre_comercial ?: $company->razon_social }}</strong><span>{{ $company->razon_social }}@if ($company->ruc) · RUC {{ $company->ruc }}@endif</span></td>
      <td class="report-title"><strong>Reporte general</strong><span>Despacho de productos · Resumen diario por producto</span></td>
    </tr>
  </table>
  <table class="metadata">
    <tr>
      <td><strong>{{ $report['branch']['name'] }}</strong><br><span class="muted">Periodo: {{ $from === $to ? $from : $from.' al '.$to }}</span></td>
      <td class="right"><strong>{{ $summary['day_count'] }} {{ $summary['day_count'] === 1 ? 'día con despachos' : 'días con despachos' }}</strong><br><span class="muted">Generado: {{ \Carbon\CarbonImmutable::parse($report['generated_at'])->format('d/m/Y H:i') }}</span></td>
    </tr>
  </table>
  <table class="metrics">
    <tr>
      <td><small>PRODUCTOS DISTINTOS</small><strong>{{ $number($summary['product_count']) }}</strong><span>{{ $number($summary['ticket_count']) }} tickets · {{ $number($summary['weighing_count']) }} pesadas</span></td>
      <td><small>CANTIDAD TOTAL</small><strong>{{ $number($summary['quantity']) }}</strong><span>Aves o elementos registrados</span></td>
      <td><small>PESO NETO TOTAL</small><strong>{{ $number($summary['net_weight_kg'], 3) }}</strong><span>Kilogramos</span></td>
      <td class="amount"><small>IMPORTE TOTAL DEL PERIODO</small>@forelse ($summary['amounts'] as $amount)<strong>{{ $amount['currency'] }} {{ $number($amount['amount'], 2) }}</strong>@empty<strong>0.00</strong>@endforelse<span>Importes separados por moneda</span></td>
    </tr>
  </table>
  @forelse ($report['days'] as $day)
    <table class="daily">
      <colgroup><col style="width:28%"><col style="width:8%"><col style="width:12%"><col style="width:11%"><col style="width:11%"><col style="width:13%"><col style="width:17%"></colgroup>
      <thead>
        <tr class="day-heading"><th colspan="7">{{ $date($day['date']) }} <span> · {{ $day['product_count'] }} productos · {{ $day['ticket_count'] }} tickets</span></th></tr>
        <tr><th style="width:28%">Producto</th><th style="width:8%">Cantidad</th><th style="width:12%">Peso leído (kg)</th><th style="width:11%">Merma (kg)</th><th style="width:11%">Tara (kg)</th><th style="width:13%">Peso neto (kg)</th><th style="width:17%">Importe</th></tr>
      </thead>
      <tbody>
        @foreach ($day['products'] as $product)
          <tr @class(['last-product' => $loop->last])>
            <td class="product">{{ $product['product_name'] }}<small>{{ $product['weighing_count'] }} {{ $product['weighing_count'] === 1 ? 'pesada' : 'pesadas' }}</small></td>
            <td>{{ $number($product['quantity']) }}</td><td>{{ $number($product['read_weight_kg'], 3) }}</td><td>{{ $number($product['waste_weight_kg'], 3) }}</td><td>{{ $number($product['tare_weight_kg'], 3) }}</td><td class="net">{{ $number($product['net_weight_kg'], 3) }}</td>
            <td>@foreach ($product['amounts'] as $amount)<span class="money">{{ $amount['currency'] }} {{ $number($amount['amount'], 2) }}</span>@endforeach</td>
          </tr>
        @endforeach
        <tr class="day-total"><td>TOTAL DEL DÍA</td><td>{{ $number($day['quantity']) }}</td><td>{{ $number($day['read_weight_kg'], 3) }}</td><td>{{ $number($day['waste_weight_kg'], 3) }}</td><td>{{ $number($day['tare_weight_kg'], 3) }}</td><td>{{ $number($day['net_weight_kg'], 3) }}</td><td>@foreach ($day['amounts'] as $amount)<span class="money">{{ $amount['currency'] }} {{ $number($amount['amount'], 2) }}</span>@endforeach</td></tr>
      </tbody>
    </table>
  @empty
    <div class="empty"><strong>Sin despachos en este periodo</strong>No hay tickets registrados para las fechas seleccionadas en esta sucursal.</div>
  @endforelse
  <p class="notes">Agrupado por fecha de registro del ticket en {{ $report['branch']['timezone'] }}. Incluye ventas al público y a clientes registrados. Las variaciones se suman en su producto. Se excluyen tickets eliminados. Los importes de monedas diferentes se presentan por separado.</p>
</body>
</html>
