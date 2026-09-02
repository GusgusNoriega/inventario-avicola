@php
  $currency = $statement['currency'];
  $currencySymbol = match ($currency) {
      'PEN' => 'S/',
      'USD' => 'US$',
      'EUR' => 'EUR',
      default => $currency,
  };
  $money = static fn (mixed $value): string => number_format((float) $value, 2, '.', ',');
  $weight = static fn (mixed $value): string => number_format((float) $value, 3, '.', ',');
  $price = static fn (mixed $value): string => number_format((float) $value, 2, '.', ',');
  $fromLabel = \Carbon\CarbonImmutable::parse($statement['period']['from'])->format('d/m/Y');
  $toLabel = \Carbon\CarbonImmutable::parse($statement['period']['to'])->format('d/m/Y');
  $generatedLabel = \Carbon\CarbonImmutable::parse($statement['generated_at'])->format('d/m/Y H:i');
  $endingBalance = (float) $statement['ending_balance'];
  $balanceLabel = $endingBalance < 0 ? 'Saldo a favor' : 'Deuda';
  $clientDocument = collect([
      $statement['client']['document_type'],
      $statement['client']['document'],
  ])->filter()->implode(' ');
@endphp
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Estado de cuenta - Despacho de productos</title>
  <style>
    @page { margin: 14mm 10mm 13mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: {{ $reportPalette['body_text'] }};
      background: {{ $reportPalette['page_background'] }};
      font-family: "DejaVu Sans", sans-serif;
      font-size: 8px;
      line-height: 1.35;
    }
    .masthead {
      width: 100%;
      margin-bottom: 9px;
      border-collapse: collapse;
      background: {{ $reportPalette['primary'] }};
      color: {{ $reportPalette['primary_text'] }};
    }
    .masthead td { padding: 10px 12px; vertical-align: middle; }
    .masthead .brand { width: 64%; }
    .masthead .brand strong { display: block; font-size: 14px; line-height: 1.15; }
    .masthead .brand span { display: block; margin-top: 3px; font-size: 7px; opacity: .9; }
    .masthead .title { width: 36%; text-align: right; }
    .masthead .title strong { display: block; font-size: 13px; line-height: 1.15; }
    .masthead .title span { display: block; margin-top: 3px; font-size: 7px; opacity: .9; }
    .identity {
      width: 100%;
      margin-bottom: 8px;
      border-collapse: collapse;
    }
    .identity td {
      width: 33.333%;
      border: 1px solid {{ $reportPalette['border'] }};
      background: {{ $reportPalette['accent'] }};
      padding: 6px 8px;
      vertical-align: top;
    }
    .identity span, .summary span {
      display: block;
      margin-bottom: 2px;
      color: {{ $reportPalette['muted_text'] }};
      font-size: 6.5px;
      font-weight: bold;
      letter-spacing: .35px;
      text-transform: uppercase;
    }
    .identity strong { color: {{ $reportPalette['primary'] }}; font-size: 9.5px; }
    .identity small { display: block; margin-top: 2px; color: {{ $reportPalette['muted_text'] }}; }
    .summary {
      width: 100%;
      margin: 0 0 9px;
      border-collapse: separate;
      border-spacing: 4px 0;
    }
    .summary td {
      width: 20%;
      border: 1px solid {{ $reportPalette['border'] }};
      background: {{ $reportPalette['accent'] }};
      padding: 6px 8px;
      text-align: right;
      vertical-align: middle;
    }
    .summary td:first-child { border-left: 3px solid {{ $reportPalette['primary'] }}; }
    .summary .is-sale { border-left: 3px solid {{ $reportPalette['debit'] }}; }
    .summary .is-debt { border-left: 3px solid {{ $reportPalette['primary'] }}; }
    .summary .is-payment { border-left: 3px solid {{ $reportPalette['credit'] }}; }
    .summary .is-balance {
      border: 2px solid {{ $reportPalette['primary'] }};
      background: {{ $reportPalette['secondary'] }};
    }
    .summary strong { color: {{ $reportPalette['primary'] }}; font-size: 11px; }
    .summary .is-sale strong { color: {{ $reportPalette['debit'] }}; }
    .summary .is-debt strong { color: {{ $reportPalette['primary'] }}; }
    .summary .is-payment strong { color: {{ $reportPalette['credit'] }}; }
    .summary small { display: block; color: {{ $reportPalette['muted_text'] }}; font-size: 6px; }
    table.ledger { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.ledger thead { display: table-header-group; }
    table.ledger tr { page-break-inside: avoid; }
    table.ledger th {
      border: 1px solid {{ $reportPalette['primary'] }};
      background: {{ $reportPalette['secondary'] }};
      color: {{ $reportPalette['secondary_text'] }};
      padding: 5px 3px;
      font-size: 6.5px;
      line-height: 1.15;
      text-transform: uppercase;
    }
    table.ledger td {
      border-right: 1px solid {{ $reportPalette['border'] }};
      border-bottom: 1px solid {{ $reportPalette['border'] }};
      padding: 3px;
      vertical-align: top;
      overflow-wrap: break-word;
    }
    table.ledger td:first-child { border-left: 1px solid {{ $reportPalette['border'] }}; }
    table.ledger tbody tr:nth-child(even) td { background: {{ $reportPalette['accent'] }}; }
    table.ledger tr.opening td {
      border-top: 1px solid {{ $reportPalette['border'] }};
      background: {{ $reportPalette['accent'] }} !important;
      color: {{ $reportPalette['muted_text'] }};
      font-weight: bold;
    }
    table.ledger tr.payment-row td { color: {{ $reportPalette['credit'] }}; }
    table.ledger tr.payment-row td.detail { color: {{ $reportPalette['body_text'] }}; }
    table.ledger tr.debt-row td { background: {{ $reportPalette['accent'] }}; }
    table.ledger tfoot td {
      border-top: 1.5px solid {{ $reportPalette['primary'] }};
      background: {{ $reportPalette['accent'] }};
      color: {{ $reportPalette['primary'] }};
      font-weight: bold;
    }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .muted { color: {{ $reportPalette['muted_text'] }}; }
    .sale { color: {{ $reportPalette['debit'] }}; font-weight: bold; }
    .debt { color: {{ $reportPalette['primary'] }}; font-weight: bold; }
    .payment { color: {{ $reportPalette['credit'] }}; font-weight: bold; }
    .balance { color: {{ $reportPalette['primary'] }}; font-weight: bold; }
    .product { font-weight: bold; }
    .variation { display: block; margin-top: 1px; color: {{ $reportPalette['muted_text'] }}; font-size: 6.5px; }
    .mode { display: block; color: {{ $reportPalette['muted_text'] }}; font-size: 6px; }
    .empty { padding: 18px !important; color: {{ $reportPalette['muted_text'] }}; text-align: center; }
    .note {
      margin: 8px 1px 0;
      color: {{ $reportPalette['muted_text'] }};
      font-size: 6.5px;
    }
  </style>
</head>
<body>
  <table class="masthead">
    <tr>
      <td class="brand">
        <strong>{{ $company->nombre_comercial ?: $company->razon_social }}</strong>
        <span>
          @if($company->ruc)RUC {{ $company->ruc }} - @endif
          {{ $statement['branch']['name'] }}
        </span>
      </td>
      <td class="title">
        <strong>Estado de cuenta</strong>
        <span>Despacho de productos</span>
      </td>
    </tr>
  </table>

  <table class="identity">
    <tr>
      <td>
        <span>Cliente</span>
        <strong>{{ $statement['client']['name'] }}</strong>
        @if($clientDocument !== '')<small>{{ $clientDocument }}</small>@endif
      </td>
      <td>
        <span>Periodo consultado</span>
        <strong>{{ $fromLabel }} al {{ $toLabel }}</strong>
        <small>Moneda: {{ $currency }}</small>
      </td>
      <td>
        <span>Reporte exclusivo</span>
        <strong>Despacho de productos</strong>
        <small>Ventas de esta sucursal más la deuda anterior empresarial registrada en Finanzas.</small>
      </td>
    </tr>
  </table>

  <table class="summary">
    <tr>
      <td>
        <span>Saldo anterior</span>
        <strong>{{ $currencySymbol }} {{ $money($statement['opening_balance']) }}</strong>
      </td>
      <td class="is-sale">
        <span>Ventas del periodo</span>
        <strong>{{ $currencySymbol }} {{ $money($statement['sales_total']) }}</strong>
        <small>{{ $statement['ticket_count'] }} ticket(s)</small>
      </td>
      <td class="is-debt">
        <span>Deuda anterior</span>
        <strong>{{ $currencySymbol }} {{ $money($statement['prior_debt_total']) }}</strong>
        <small>{{ $statement['prior_debt_count'] }} registro(s) del periodo</small>
      </td>
      <td class="is-payment">
        <span>Abonos aplicados</span>
        <strong>{{ $currencySymbol }} {{ $money($statement['payments_total']) }}</strong>
        <small>{{ $statement['payment_count'] }} pago(s)</small>
      </td>
      <td class="is-balance">
        <span>{{ $balanceLabel }} al cierre</span>
        <strong>{{ $currencySymbol }} {{ $money(abs($endingBalance)) }}</strong>
      </td>
    </tr>
  </table>

  <table class="ledger">
    <thead>
      <tr>
        <th style="width: 8%">Fecha</th>
        <th style="width: 12%">Nro. doc.</th>
        <th style="width: 18%">Tipo / producto</th>
        <th style="width: 6%">Cant.</th>
        <th style="width: 7%">Kilos</th>
        <th style="width: 16%">Detalle</th>
        <th style="width: 8%">Precio</th>
        <th style="width: 8%">Cargos</th>
        <th style="width: 8%">Abonos</th>
        <th style="width: 9%">Saldo</th>
      </tr>
    </thead>
    <tbody>
      <tr class="opening">
        <td>{{ $fromLabel }}</td>
        <td colspan="8">Saldo anterior al periodo</td>
        <td class="num balance">{{ $money($statement['opening_balance']) }}</td>
      </tr>
      @forelse($statement['rows'] as $row)
        <tr class="{{ $row['kind'] === 'PAYMENT' ? 'payment-row' : ($row['kind'] === 'PRIOR_DEBT' ? 'debt-row' : 'sale-row') }}">
          <td class="center">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
          <td>{{ $row['document'] }}</td>
          <td>
            @if($row['kind'] === 'PRIOR_DEBT')
              <span class="debt">{{ $row['movement_label'] ?? 'Deuda anterior' }}</span>
            @elseif($row['product'])
              <span class="product">{{ $row['product'] }}</span>
              @if($row['variation'])<span class="variation">{{ $row['variation'] }}</span>@endif
            @else
              <span class="payment">{{ $row['movement_label'] ?? 'Abono aplicado' }}</span>
            @endif
          </td>
          <td class="num">{{ $row['quantity'] !== null ? number_format($row['quantity']) : '-' }}</td>
          <td class="num">{{ $row['net_weight_kg'] !== null ? $weight($row['net_weight_kg']) : '-' }}</td>
          <td class="detail">{{ $row['detail'] ?: '-' }}</td>
          <td class="num">
            @if($row['price'] !== null)
              {{ $price($row['price']) }}
              <span class="mode">{{ $row['price_mode'] === 'POR_UNIDAD' ? 'por un.' : 'por kg' }}</span>
            @else
              -
            @endif
          </td>
          <td class="num sale">{{ (float) $row['sale'] !== 0.0 ? $money($row['sale']) : '-' }}</td>
          <td class="num payment">{{ (float) $row['payment'] !== 0.0 ? $money($row['payment']) : '-' }}</td>
          <td class="num balance">{{ $row['show_balance'] ? $money($row['balance']) : '' }}</td>
        </tr>
      @empty
        <tr><td colspan="10" class="empty">No hay ventas, deudas anteriores ni abonos aplicados dentro del periodo seleccionado.</td></tr>
      @endforelse
    </tbody>
    <tfoot>
      <tr>
        <td colspan="7">Totales del periodo</td>
        <td class="num">{{ $money($statement['charges_total']) }}</td>
        <td class="num">{{ $money($statement['payments_total']) }}</td>
        <td class="num">{{ $money($statement['ending_balance']) }}</td>
      </tr>
    </tfoot>
  </table>

  <p class="note">
    Generado el {{ $generatedLabel }}. Las ventas corresponden únicamente a Despacho de productos en {{ $statement['branch']['name'] }}. La deuda anterior proviene de Finanzas y es empresarial; los abonos mostrados corresponden solo a esos cargos incluidos.
  </p>
</body>
</html>
