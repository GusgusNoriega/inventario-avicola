<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    @page { margin: 25px 30px 38px; }
    * { box-sizing: border-box; }
    body { margin: 0; color: #17202a; font-family: "DejaVu Sans", sans-serif; font-size: 9px; }
    .brand { color: #59636e; font-size: 9px; text-align: center; }
    h1 { margin: 5px 0 2px; color: #14261b; font-size: 17px; text-align: center; text-transform: uppercase; }
    .period { margin: 0 0 12px; color: #4f5b66; text-align: center; }
    .subject { margin: 8px 0 10px; border: 1px solid #ccd4cf; background: #f7faf8; padding: 7px 9px; }
    .subject strong { color: #14261b; font-size: 11px; }
    .summary { width: 100%; margin: 0 0 10px; border-collapse: separate; border-spacing: 4px 0; }
    .summary td { border: 1px solid #d8dfda; background: #f5f8f6; padding: 6px 8px; text-align: right; }
    .summary span { display: block; color: #67716b; font-size: 7px; text-transform: uppercase; }
    .summary strong { color: #172b1e; font-size: 11px; }
    table.report { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.report thead { display: table-header-group; }
    table.report tr { page-break-inside: avoid; }
    table.report th { border: 1px solid #1f2a24; background: #b8d8c3; color: #14261b; padding: 5px 3px; font-size: 7px; text-transform: uppercase; }
    table.report td { border-bottom: 1px solid #d7ded9; padding: 4px 3px; vertical-align: top; }
    table.report tbody tr:nth-child(even) td { background: #f8faf9; }
    table.report tfoot td { border-top: 1.5px solid #1f2a24; border-bottom: 0; background: #e7f1ea; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .muted { color: #68726d; }
    .credit { color: #b42318; font-weight: bold; }
    .debit { color: #175cd3; font-weight: bold; }
    .balance { color: #172b1e; font-weight: bold; }
    .empty { padding: 24px !important; color: #66706a; text-align: center; }
    .section-title { margin: 12px 0 5px; border-left: 4px solid #2f7d4b; padding-left: 6px; color: #173c25; font-size: 11px; }
    .footer-note { margin-top: 9px; color: #707a74; font-size: 7px; }
  </style>
</head>
<body>
  <div class="brand">{{ $company->nombre_comercial ?: $company->razon_social }} @if($company->ruc) - RUC {{ $company->ruc }} @endif</div>
  <h1>{{ $title }}</h1>
  <p class="period">Periodo: {{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} al {{ \Carbon\CarbonImmutable::parse($to)->format('d/m/Y') }}</p>

  @if(in_array($type, ['estado-cliente', 'estado-proveedor'], true))
    <div class="subject">
      {{ $type === 'estado-cliente' ? 'Cliente' : 'Proveedor' }}:
      <strong>{{ $data['counterparty']->nombre_razon_social }}</strong>
      @if($data['counterparty']->numero_documento)
        <span class="muted"> - {{ $data['counterparty']->tipo_documento }} {{ $data['counterparty']->numero_documento }}</span>
      @endif
    </div>
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
        @forelse($data['rows'] as $row)
          <tr>
            <td>{{ $row['customer'] }}</td><td class="center">{{ \Carbon\CarbonImmutable::parse($row['date_time'])->format('d/m/Y H:i') }}</td><td class="center">{{ $row['channel'] }}</td><td>{{ $row['product'] }}</td>
            <td class="num">{{ number_format($row['containers']) }}</td><td class="num">{{ number_format($row['birds']) }}</td>
            <td class="num">{{ number_format($row['gross_weight'], 3) }}</td><td class="num">{{ number_format($row['tare'], 3) }}</td>
            <td class="num credit">{{ number_format($row['returns'], 3) }}</td><td class="num">{{ number_format($row['net_weight'], 3) }}</td>
            <td class="num">{{ $row['net_weight'] != 0 ? number_format($row['amount'] / $row['net_weight'], 2) : '-' }}</td>
            <td class="num balance">{{ number_format($row['amount'], 2) }}</td>
          </tr>
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

  <p class="footer-note">Generado el {{ $generatedAt->format('d/m/Y H:i') }}. Solo se incluyen registros vigentes; los movimientos anulados no forman parte de los totales.</p>
</body>
</html>
