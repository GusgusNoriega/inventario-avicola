<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  @php
    $reportPalette = $reportPalette ?? app(\App\Services\ReportPaletteService::class)->defaults();
  @endphp
  <style>
    @page { margin: 25px 30px 38px; }
    * { box-sizing: border-box; }
    body { margin: 0; background: {{ $reportPalette['page_background'] }}; color: {{ $reportPalette['body_text'] }}; font-family: "DejaVu Sans", sans-serif; font-size: 9px; }
    .brand { color: {{ $reportPalette['muted_text'] }}; font-size: 9px; text-align: center; }
    h1 { margin: 5px 0 2px; color: {{ $reportPalette['primary'] }}; font-size: 17px; text-align: center; text-transform: uppercase; }
    .period { margin: 0 0 12px; color: {{ $reportPalette['muted_text'] }}; text-align: center; }
    .updated { margin: -7px 0 10px; color: {{ $reportPalette['muted_text'] }}; text-align: center; }
    .subject { margin: 8px 0 10px; border: 1px solid {{ $reportPalette['border'] }}; background: {{ $reportPalette['accent'] }}; padding: 7px 9px; }
    .subject strong { color: {{ $reportPalette['primary'] }}; font-size: 11px; }
    .summary { width: 100%; margin: 0 0 10px; border-collapse: separate; border-spacing: 4px 0; }
    .summary td { border: 1px solid {{ $reportPalette['border'] }}; background: {{ $reportPalette['accent'] }}; padding: 6px 8px; text-align: right; }
    .summary span { display: block; color: {{ $reportPalette['muted_text'] }}; font-size: 7px; text-transform: uppercase; }
    .summary strong { color: {{ $reportPalette['primary'] }}; font-size: 11px; }
    table.report { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.report thead { display: table-header-group; }
    table.report tr { page-break-inside: avoid; }
    table.report th { border: 1px solid {{ $reportPalette['primary'] }}; background: {{ $reportPalette['secondary'] }}; color: {{ $reportPalette['secondary_text'] }}; padding: 5px 3px; font-size: 7px; text-transform: uppercase; }
    table.report td { border-bottom: 1px solid {{ $reportPalette['border'] }}; padding: 4px 3px; vertical-align: top; }
    table.report tbody tr:nth-child(even) td { background: {{ $reportPalette['accent'] }}; }
    table.report tfoot td { border-top: 1.5px solid {{ $reportPalette['primary'] }}; border-bottom: 0; background: {{ $reportPalette['accent'] }}; font-weight: bold; }
    table.report tr.customer-group-start td { border-top: 1px solid {{ $reportPalette['primary'] }}; }
    table.report tr.customer-subtotal { page-break-before: avoid; page-break-inside: avoid; }
    table.report tbody tr.customer-subtotal td {
      border-top: 1px solid {{ $reportPalette['primary'] }};
      border-bottom: 1.5px solid {{ $reportPalette['primary'] }};
      background: {{ $reportPalette['accent'] }} !important;
      color: {{ $reportPalette['primary'] }};
      font-weight: bold;
    }
    table.report tr.customer-subtotal td:first-child { letter-spacing: .15px; text-transform: uppercase; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .muted { color: {{ $reportPalette['muted_text'] }}; }
    .credit { color: {{ $reportPalette['credit'] }}; font-weight: bold; }
    .debit { color: {{ $reportPalette['debit'] }}; font-weight: bold; }
    table.report th .credit,
    table.report th .debit { color: inherit; }
    .balance { color: {{ $reportPalette['primary'] }}; font-weight: bold; }
    .empty { padding: 24px !important; color: {{ $reportPalette['muted_text'] }}; text-align: center; }
    table.report tbody tr.day-separator td {
      border-top: 1px solid {{ $reportPalette['primary'] }};
      border-bottom: 1px solid {{ $reportPalette['border'] }};
      background: {{ $reportPalette['accent'] }};
      color: {{ $reportPalette['primary'] }};
      padding: 5px 6px;
      font-size: 8px;
      font-weight: bold;
      letter-spacing: .2px;
      text-transform: uppercase;
    }
    .day-separator { page-break-after: avoid; }
    .section-title { margin: 12px 0 5px; border-left: 4px solid {{ $reportPalette['primary'] }}; padding-left: 6px; color: {{ $reportPalette['primary'] }}; font-size: 11px; }
    .footer-note { margin-top: 9px; color: {{ $reportPalette['muted_text'] }}; font-size: 7px; }
    table.debt-totals { width: 100%; margin: 1px 0 4px; border-collapse: collapse; table-layout: fixed; }
    table.debt-totals .spacer { width: 35%; border: 0; background: transparent; }
    table.debt-totals th { border: 1px solid {{ $reportPalette['primary'] }}; background: {{ $reportPalette['primary'] }}; color: {{ $reportPalette['primary_text'] }}; padding: 2px 3px; font-size: 8px; letter-spacing: .35px; text-align: center; }
    table.debt-totals td:not(.spacer) { width: 13%; border: 1px solid {{ $reportPalette['border'] }}; padding: 3px; font-size: 8px; font-weight: bold; text-align: right; white-space: nowrap; }
    table.report.debt-report th { background: {{ $reportPalette['secondary'] }}; color: {{ $reportPalette['secondary_text'] }}; padding: 4px 2px; font-size: 6.6px; line-height: 1.2; }
    table.report.debt-report td { border: 1px solid {{ $reportPalette['border'] }}; padding: 2px 3px; font-size: 7.2px; line-height: 1.15; }
    table.report.debt-report tbody tr:nth-child(even) td { background: {{ $reportPalette['accent'] }}; }
    table.report.debt-report td:last-child { background: {{ $reportPalette['accent'] }}; font-weight: bold; }
    .debt-date { display: block; margin-top: 2px; font-size: 6px; font-weight: normal; }
  </style>
</head>
<body>
  <div class="brand">{{ $company->nombre_comercial ?: $company->razon_social }} @if($company->ruc) - RUC {{ $company->ruc }} @endif</div>
  <h1>{{ $title }}</h1>
  <p class="period">Periodo: {{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} al {{ \Carbon\CarbonImmutable::parse($to)->format('d/m/Y') }}</p>

  @if($type === 'deuda-clientes')
    <p class="updated">Actualizado hasta el {{ $generatedAt->format('d/m/Y H:i') }} - Moneda: {{ $data['currency'] }}</p>
  @endif

  @if($selectedAccount ?? null)
    <div class="subject">
      Cuenta filtrada:
      <strong>{{ $selectedAccount->entidad_nombre_comercial ?: $selectedAccount->entidad_razon_social }} - {{ $selectedAccount->alias }}</strong>
      <span class="muted"> · {{ $selectedAccount->tipo }} · {{ $selectedAccount->moneda }}</span>
    </div>
  @endif

  @if(($selectedUser ?? null) && $type === 'pagos')
    <div class="subject">
      Usuario filtrado: <strong>{{ $selectedUser->nombre }}</strong>
    </div>
  @endif

  @if($type === 'deuda-clientes')
    @php
      $singleDay = $from === $to;
      $fromLabel = \Carbon\CarbonImmutable::parse($from)->format('d/m/Y');
      $toLabel = \Carbon\CarbonImmutable::parse($to)->format('d/m/Y');
      $previousLabel = \Carbon\CarbonImmutable::parse($from)->subDay()->format('d/m/Y');
    @endphp
    <table class="debt-totals">
      <tr><td class="spacer"></td><th colspan="5">TOTALES</th></tr>
      <tr>
        <td class="spacer"></td>
        <td>{{ number_format((float) $data['totals']['opening'], 2) }}</td>
        <td>{{ number_format((float) $data['totals']['period_debt'], 2) }}</td>
        <td>{{ number_format((float) $data['totals']['debt_to_date'], 2) }}</td>
        <td>{{ number_format((float) $data['totals']['payments'], 2) }}</td>
        <td>{{ number_format((float) $data['totals']['balance'], 2) }}</td>
      </tr>
    </table>
    <table class="report debt-report">
      <thead><tr>
        <th style="width: 35%; text-align: left">Clientes</th>
        <th style="width: 13%">{{ $singleDay ? 'Deuda hasta ayer' : 'Deuda anterior al' }}<span class="debt-date">{{ $singleDay ? $previousLabel : $fromLabel }}</span></th>
        <th style="width: 13%">{{ $singleDay ? 'Deuda' : 'Deuda del periodo' }}<span class="debt-date">{{ $singleDay ? $toLabel : $fromLabel.' al '.$toLabel }}</span></th>
        <th style="width: 13%">Total deuda hasta<span class="debt-date">{{ $toLabel }}</span></th>
        <th style="width: 13%">Pagos realizados<span class="debt-date">{{ $singleDay ? $toLabel : $fromLabel.' al '.$toLabel }}</span></th>
        <th style="width: 13%">{{ $singleDay ? 'Total deuda' : 'Deuda actual' }}<span class="debt-date">{{ $singleDay ? '' : $toLabel }}</span></th>
      </tr></thead>
      <tbody>
        @forelse($data['rows'] as $row)
          <tr>
            <td>{{ $row['customer'] }}</td>
            <td class="num">{{ (float) $row['opening'] !== 0.0 ? number_format((float) $row['opening'], 2) : '' }}</td>
            <td class="num">{{ (float) $row['period_debt'] !== 0.0 ? number_format((float) $row['period_debt'], 2) : '' }}</td>
            <td class="num">{{ (float) $row['debt_to_date'] !== 0.0 ? number_format((float) $row['debt_to_date'], 2) : '' }}</td>
            <td class="num">{{ (float) $row['payments'] !== 0.0 ? number_format((float) $row['payments'], 2) : '' }}</td>
            <td class="num balance">{{ (float) $row['balance'] !== 0.0 ? number_format((float) $row['balance'], 2) : '' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty">No hay deuda ni movimientos de clientes en el periodo seleccionado.</td></tr>
        @endforelse
      </tbody>
    </table>
  @elseif(in_array($type, ['estado-cliente', 'estado-proveedor'], true))
    <div class="subject">
      {{ $type === 'estado-cliente' ? 'Cliente' : 'Proveedor' }}:
      <strong>{{ $data['counterparty']->nombre_razon_social }}</strong>
      @if($type === 'estado-proveedor' && $data['counterparty']->numero_documento)
        <span class="muted"> - {{ $data['counterparty']->tipo_documento }} {{ $data['counterparty']->numero_documento }}</span>
      @endif
    </div>
    @if($type === 'estado-cliente')
      <table class="report">
        <thead><tr>
          <th style="width: 10%">Fec.</th><th style="width: 15%">Cód.</th><th style="width: 14%">Tipo</th>
          <th style="width: 27%">Det.</th><th style="width: 8%">Kg</th><th style="width: 7%">P/Kg</th>
          <th style="width: 9%"><span class="debit">C</span>/<span class="credit">A</span></th>
          <th style="width: 10%">Saldo</th>
        </tr></thead>
        <tbody>
          <tr><td colspan="7" class="muted">Saldo anterior</td><td class="num balance">{{ number_format($data['opening'], 2) }}</td></tr>
          @forelse($data['rows']->groupBy('date') as $date => $rows)
            <tr class="day-separator">
              <td colspan="8">Movimientos del {{ \Carbon\CarbonImmutable::parse($date)->format('d/m/Y') }}</td>
            </tr>
            @foreach($rows as $row)
              <tr>
                <td>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
                <td>{{ $row['code'] }}</td><td>{{ $row['type'] }}</td><td>{{ $row['detail'] ?: '-' }}</td>
                <td class="num">{{ $row['weight'] !== null ? number_format($row['weight'], 3) : '-' }}</td>
                <td class="num">{{ $row['price'] !== null ? number_format($row['price'], 2) : '-' }}</td>
                <td class="num {{ $row['effect'] > 0 ? 'debit' : ($row['effect'] < 0 ? 'credit' : '') }}">
                  {{ $row['effect'] != 0 ? number_format(abs($row['effect']), 2) : '-' }}
                </td>
                <td class="num balance">{{ number_format($row['balance'], 2) }}</td>
              </tr>
            @endforeach
          @empty
            <tr><td colspan="8" class="empty">No hay movimientos en el periodo seleccionado.</td></tr>
          @endforelse
        </tbody>
      </table>
    @else
      <table class="summary">
        <tr>
          <td><span>Saldo anterior</span><strong>S/ {{ number_format($data['opening'], 2) }}</strong></td>
          <td><span>Cargos del periodo</span><strong>S/ {{ number_format($data['charges'], 2) }}</strong></td>
          <td><span>Abonos del periodo</span><strong>S/ {{ number_format($data['credits'], 2) }}</strong></td>
          <td><span>Saldo final</span><strong>S/ {{ number_format($data['balance'], 2) }}</strong></td>
        </tr>
      </table>
      <table class="report">
        <thead><tr>
          <th style="width: 10%">Fecha</th><th style="width: 15%">Codigo</th><th style="width: 14%">Tipo</th>
          <th style="width: 23%">Detalle</th><th style="width: 9%">Kg</th><th style="width: 9%">Precio</th>
          <th style="width: 10%">Cargo</th><th style="width: 10%">Abono</th><th style="width: 11%">Saldo</th>
        </tr></thead>
        <tbody>
          <tr><td colspan="8" class="muted">Saldo anterior al {{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }}</td><td class="num balance">{{ number_format($data['opening'], 2) }}</td></tr>
          @forelse($data['rows'] as $row)
            <tr>
              <td>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
              <td>{{ $row['code'] }}</td><td>{{ $row['type'] }}</td><td>{{ $row['detail'] ?: '-' }}</td>
              <td class="num">{{ $row['weight'] !== null ? number_format($row['weight'], 3) : '-' }}</td>
              <td class="num">{{ $row['price'] !== null ? number_format($row['price'], 2) : '-' }}</td>
              <td class="num debit">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '-' }}</td>
              <td class="num credit">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '-' }}</td>
              <td class="num balance">{{ number_format($row['balance'], 2) }}</td>
            </tr>
          @empty
            <tr><td colspan="9" class="empty">No hay movimientos en el periodo seleccionado.</td></tr>
          @endforelse
        </tbody>
      </table>
    @endif
  @elseif($type === 'ventas-clientes')
    <table class="summary">
      <tr>
        <td><span>Registros de venta</span><strong>{{ $data['rows']->count() }}</strong></td>
        <td><span>Aves netas</span><strong>{{ number_format($data['totals']['birds']) }}</strong></td>
        <td><span>Peso neto</span><strong>{{ number_format($data['totals']['net_weight'], 3) }} kg</strong></td>
        <td><span>Venta total</span><strong>S/ {{ number_format($data['totals']['amount'], 2) }}</strong></td>
      </tr>
    </table>
    <table class="report">
      <thead><tr>
        <th style="width: 16%">Cliente</th><th style="width: 11%">Fecha y hora</th><th style="width: 7%">Canal</th><th style="width: 9%">Producto</th>
        <th>Javas / bandejas</th><th>Aves</th><th>Peso bruto</th><th>Tara</th><th>Devolucion kg</th>
        <th>Peso neto</th><th>Precio prom.</th><th>Total S/</th>
      </tr></thead>
      <tbody>
        @forelse($data['customer_groups'] as $group)
          @foreach($group['rows'] as $row)
            <tr @class([
              'sales-detail',
              'customer-group-start' => $loop->first,
            ])>
              <td>{{ $row['customer'] }}</td><td class="center">{{ \Carbon\CarbonImmutable::parse($row['date_time'])->format('d/m/Y H:i') }}</td><td class="center">{{ $row['channel'] }}</td><td>{{ $row['product'] }}</td>
              <td class="num">{{ number_format($row['containers']) }}</td><td class="num">{{ number_format($row['birds']) }}</td>
              <td class="num">{{ number_format($row['gross_weight'], 3) }}</td><td class="num">{{ number_format($row['tare'], 3) }}</td>
              <td class="num credit">{{ number_format($row['returns'], 3) }}</td><td class="num">{{ number_format($row['net_weight'], 3) }}</td>
              <td class="num">{{ $row['net_weight'] != 0 ? number_format($row['amount'] / $row['net_weight'], 2) : '-' }}</td>
              <td class="num balance">{{ number_format($row['amount'], 2) }}</td>
            </tr>
          @endforeach
          @if($group['rows']->count() > 1)
            <tr class="customer-subtotal">
              <td colspan="4">Total {{ $group['customer'] }}</td>
              <td class="num">{{ number_format($group['subtotal']['containers']) }}</td>
              <td class="num">{{ number_format($group['subtotal']['birds']) }}</td>
              <td class="num">{{ number_format($group['subtotal']['gross_weight'], 3) }}</td>
              <td class="num">{{ number_format($group['subtotal']['tare'], 3) }}</td>
              <td class="num credit">{{ number_format($group['subtotal']['returns'], 3) }}</td>
              <td class="num">{{ number_format($group['subtotal']['net_weight'], 3) }}</td>
              <td class="num">{{ $group['subtotal']['weighted_price'] !== null ? number_format($group['subtotal']['weighted_price'], 2) : '-' }}</td>
              <td class="num balance">{{ number_format($group['subtotal']['amount'], 2) }}</td>
            </tr>
          @endif
        @empty
          <tr><td colspan="12" class="empty">No hay ventas cerradas en el periodo seleccionado.</td></tr>
        @endforelse
      </tbody>
      <tfoot><tr><td colspan="4">TOTAL</td><td class="num">{{ number_format($data['totals']['containers']) }}</td><td class="num">{{ number_format($data['totals']['birds']) }}</td><td class="num">{{ number_format($data['totals']['gross_weight'], 3) }}</td><td class="num">{{ number_format($data['totals']['tare'], 3) }}</td><td class="num">{{ number_format($data['totals']['returns'], 3) }}</td><td class="num">{{ number_format($data['totals']['net_weight'], 3) }}</td><td></td><td class="num">{{ number_format($data['totals']['amount'], 2) }}</td></tr></tfoot>
    </table>
  @elseif($type === 'pagos')
    <table class="summary">
      <tr>
        <td><span>Registros</span><strong>{{ $data['rows']->count() }}</strong></td>
        <td><span>Ingresos</span><strong>S/ {{ number_format($data['income'], 2) }}</strong></td>
        <td><span>Egresos</span><strong>S/ {{ number_format($data['expense'], 2) }}</strong></td>
        <td><span>Importe listado</span><strong>S/ {{ number_format($data['total'], 2) }}</strong></td>
      </tr>
    </table>
    @include('reports.partials.payment-table', ['rows' => $data['rows'], 'showUser' => true])
  @elseif($type === 'responsable')
    <div class="subject">Responsable: <strong>{{ $data['user_name'] }}</strong></div>
    <table class="summary">
      <tr>
        <td><span>Movimientos</span><strong>{{ $data['rows']->count() }}</strong></td>
        <td><span>Ingresos registrados</span><strong>S/ {{ number_format($data['income'], 2) }}</strong></td>
        <td><span>Egresos registrados</span><strong>S/ {{ number_format($data['expense'], 2) }}</strong></td>
        <td><span>Diferencia de flujo</span><strong>S/ {{ number_format($data['income'] - $data['expense'], 2) }}</strong></td>
      </tr>
    </table>
    <h2 class="section-title">Ingresos y cobranzas</h2>
    @include('reports.partials.payment-table', ['rows' => $data['collections'], 'showUser' => false])
    <h2 class="section-title">Egresos y pagos</h2>
    @include('reports.partials.payment-table', ['rows' => $data['expenses'], 'showUser' => false])
    @if($data['other']->isNotEmpty())
      <h2 class="section-title">Transferencias y movimientos sin flujo</h2>
      @include('reports.partials.payment-table', ['rows' => $data['other'], 'showUser' => false])
    @endif
  @endif

  @if($type === 'deuda-clientes')
    <p class="footer-note">Generado el {{ $generatedAt->format('d/m/Y H:i') }}. Las anulaciones se reflejan en la fecha en que fueron registradas para conservar el corte histórico.</p>
  @else
    <p class="footer-note">Generado el {{ $generatedAt->format('d/m/Y H:i') }}. Solo se incluyen registros vigentes; los movimientos anulados no forman parte de los totales.</p>
  @endif
</body>
</html>
