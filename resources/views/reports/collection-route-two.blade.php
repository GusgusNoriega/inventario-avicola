<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  @php
    $routeTahomaRegular = 'C:/Windows/Fonts/tahoma.ttf';
    $routeTahomaBold = 'C:/Windows/Fonts/tahomabd.ttf';
    $routeHasTahoma = is_file($routeTahomaRegular) && is_file($routeTahomaBold);
    $routeMicrosoftSans = 'C:/Windows/Fonts/micross.ttf';
    $routeHasMicrosoftSans = is_file($routeMicrosoftSans);
  @endphp
  <style>
    @if($routeHasTahoma)
      @font-face {
        font-family: "Route Tahoma";
        font-style: normal;
        font-weight: normal;
        src: url("file://C:/Windows/Fonts/tahoma.ttf") format("truetype");
      }
      @font-face {
        font-family: "Route Tahoma";
        font-style: normal;
        font-weight: bold;
        src: url("file://C:/Windows/Fonts/tahomabd.ttf") format("truetype");
      }
    @endif
    @if($routeHasMicrosoftSans)
      @font-face {
        font-family: "Route MS Sans";
        font-style: normal;
        font-weight: normal;
        src: url("file://C:/Windows/Fonts/micross.ttf") format("truetype");
      }
    @endif
    @page { margin: 75.62pt 0 30pt; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: #000;
      font-family: "{{ $routeHasTahoma ? 'Route Tahoma' : 'Helvetica' }}", Arial, sans-serif;
      font-size: 7.2pt;
    }
    .route-sheet {
      width: 367.75pt;
      margin: 0 0 0 125.18pt;
      page-break-after: always;
    }
    .route-sheet.route-last-page { page-break-after: auto; }
    table.route-client {
      width: 367.75pt;
      margin: 0 0 40.58pt;
      border: 0;
      border-collapse: collapse;
      table-layout: fixed;
      page-break-inside: avoid;
    }
    table.route-client tr { page-break-inside: avoid; }
    table.route-client col.route-date { width: 44.82pt; }
    table.route-client col.route-detail { width: 56.45pt; }
    table.route-client col.route-weight { width: 44.68pt; }
    table.route-client col.route-marker { width: 64pt; }
    table.route-client col.route-price { width: 22.98pt; }
    table.route-client col.route-outflow { width: 45.21pt; }
    table.route-client col.route-inflow { width: 44.41pt; }
    table.route-client col.route-balance { width: 45.20pt; }
    table.route-client td {
      height: 7.78pt;
      border: 0;
      padding: 0 1.55pt;
      font-size: 7.2pt;
      font-weight: normal;
      line-height: 7.78pt;
      vertical-align: middle;
      white-space: nowrap;
    }
    table.route-client td:nth-child(1) { width: 44.82pt; }
    table.route-client td:nth-child(2) { width: 56.45pt; }
    table.route-client td:nth-child(3) { width: 44.68pt; }
    table.route-client td:nth-child(4) { width: 64pt; }
    table.route-client td:nth-child(5) { width: 22.98pt; }
    table.route-client td:nth-child(6) { width: 45.21pt; }
    table.route-client td:nth-child(7) { width: 44.41pt; }
    table.route-client td:nth-child(8) { width: 45.20pt; }
    .route-client-title td {
      overflow: hidden;
      border-right: .2pt solid #000;
      background: #000;
      color: #fff;
      font-weight: bold !important;
    }
    .route-client-name-clip {
      width: 53.35pt;
      height: 7.78pt;
      overflow: hidden;
      line-height: 7.78pt;
      text-overflow: clip;
      white-space: nowrap;
      position: relative;
      left: -.93pt;
      top: -1.28pt;
    }
    .route-columns td {
      border-right: .2pt solid #ffff00;
      border-bottom: .72pt solid #000;
      background: #ffff00;
      color: #0000ff;
      font-weight: bold !important;
      text-align: center;
    }
    .route-opening td { border-bottom: .72pt solid #000; }
    .route-opening-label {
      overflow: visible;
      padding-left: 0 !important;
      text-align: left;
    }
    .route-shift-opening { position: relative; left: 9.6pt; }
    .route-shift-detail { position: relative; left: 4.2pt; }
    .route-shift-detail-data { position: relative; left: -1.05pt; }
    .route-shift-weight { position: relative; left: 8pt; }
    .route-shift-date-header { position: relative; left: -.5pt; }
    .route-shift-weight-header { position: relative; left: 8.69pt; }
    .route-shift-marker-header { position: relative; left: 16.97pt; }
    .route-shift-price-header { position: relative; left: 14.58pt; }
    .route-shift-price { position: relative; left: 3pt; }
    .route-shift-outflow { position: relative; left: 2pt; }
    .route-shift-outflow-header { position: relative; left: 2.76pt; }
    .route-shift-inflow-header { position: relative; left: 1.53pt; }
    .route-shift-balance-header { position: relative; left: .62pt; }
    .route-shift-balance { position: relative; left: -.17pt; }
    .route-shift-income { position: relative; left: .8pt; }
    .route-row.route-new-day td { border-top: .72pt solid #000; }
    .route-row.route-last td { border-bottom: .72pt solid #000; }
    .route-left { text-align: left; }
    .route-num { text-align: right; }
    .route-return {
      color: #0000ff;
      font-weight: bold !important;
    }
    .route-return-date {
      color: #0000ff;
      font-weight: normal !important;
    }
    .route-income {
      color: #ff0000;
      font-weight: bold !important;
    }
    .route-empty {
      width: 367.75pt;
      border-top: 9.84pt solid #000;
      border-bottom: .72pt solid #000;
      padding: 10pt 2pt;
      text-align: center;
    }
  </style>
</head>
<body>
  @php
    $routePages = [];
    $routePage = [];
    $routeCursor = 75.62;
    foreach ($data['customers'] as $routeCustomer) {
        $routeTableHeight = (3 + count($routeCustomer['rows'])) * 10.32;
        if ($routePage !== [] && $routeCursor + $routeTableHeight > 680.00) {
            $routePages[] = $routePage;
            $routePage = [];
            $routeCursor = 75.62;
        }
        $routePage[] = $routeCustomer;
        $routeCursor += $routeTableHeight + 41.30;
    }
    if ($routePage !== []) {
        $routePages[] = $routePage;
    }
  @endphp
  @forelse($routePages as $routePage)
    <main class="route-sheet{{ $loop->last ? ' route-last-page' : '' }}">
      @foreach($routePage as $customer)
        <table class="route-client">
          <colgroup>
            <col style="width: 44.82pt"><col style="width: 56.45pt"><col style="width: 44.68pt"><col style="width: 64pt">
            <col style="width: 22.98pt"><col style="width: 45.21pt"><col style="width: 44.41pt"><col style="width: 45.20pt">
          </colgroup>
          <tbody>
            <tr class="route-client-title">
              <td>CLIENTE</td>
              <td><div class="route-client-name-clip">{{ mb_substr($customer['name'], 0, 13) }}</div></td>
              <td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <tr class="route-columns">
              <td><span class="route-shift-date-header">FECHA</span></td><td><span class="route-shift-detail">DETALLE</span></td><td><span class="route-shift-weight route-shift-weight-header">K</span></td><td><span class="route-shift-marker-header">-</span></td><td><span class="route-shift-price-header">PRE</span></td><td><span class="route-shift-outflow-header">SALIDA</span></td><td><span class="route-shift-inflow-header">INGRESO</span></td><td><span class="route-shift-balance-header">SALDO</span></td>
            </tr>
            <tr class="route-opening">
              <td></td><td></td><td></td><td class="route-opening-label"><span class="route-shift-opening">SALDO ANTERIOR</span></td><td></td><td></td><td></td>
              <td class="route-num"><span class="route-shift-balance">{{ number_format((float) $customer['opening'], 2) }}</span></td>
            </tr>
            @foreach($customer['rows'] as $row)
              @php($returnClass = $row['kind'] === 'return' ? ' route-return' : '')
              @php($returnDateClass = $row['kind'] === 'return' ? ' route-return-date' : '')
              <tr class="route-row{{ $row['starts_new_day'] ? ' route-new-day' : '' }}{{ $row['is_last'] ? ' route-last' : '' }}">
                <td class="route-left{{ $returnDateClass }}">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
                <td class="route-left{{ $returnClass }}"><span class="route-shift-detail-data">{{ $row['detail'] }}</span></td>
                <td class="route-num{{ $returnClass }}"><span class="route-shift-weight">{{ $row['weight'] !== null ? number_format((float) $row['weight'], 2) : '' }}</span></td>
                <td class="route-left"><span class="route-shift-weight">{{ $row['marker'] }}</span></td>
                <td class="route-num{{ $returnClass }}"><span class="route-shift-price">{{ $row['price'] !== null ? number_format((float) $row['price'], 2) : '' }}</span></td>
                <td class="route-num{{ $returnClass }}"><span class="route-shift-outflow">{{ $row['outflow'] !== null ? number_format((float) $row['outflow'], 2) : '' }}</span></td>
                <td class="route-num{{ $row['inflow'] !== null ? ' route-income' : '' }}"><span class="route-shift-income">{{ $row['inflow'] !== null ? number_format((float) $row['inflow'], 2) : '' }}</span></td>
                <td class="route-num"><span class="route-shift-balance">{{ number_format((float) $row['balance'], 2) }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endforeach
    </main>
  @empty
    <main class="route-sheet route-last-page">
      <div class="route-empty">No hay clientes disponibles para la ruta de cobranza.</div>
    </main>
  @endforelse
</body>
</html>
