<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte de jornada - Recepción de pollo vivo</title>
  @php
    $reportPalette = $reportPalette ?? app(\App\Services\ReportPaletteService::class)->defaults();
    $branch = data_get($report, 'branch', []);
    $journey = data_get($report, 'journey', []);
    $records = collect(data_get($report, 'records', []))
        ->filter(fn ($record) => data_get($record, 'status') === 'ACTIVA')
        ->values();
    $summaries = data_get($report, 'summary', []);
    $generatedAt = data_get($report, 'generated_at', now());
    $timezone = data_get($branch, 'timezone') ?: config('app.timezone');
    $generatedAt = \Carbon\CarbonImmutable::parse($generatedAt)->setTimezone($timezone);
    $operatingDate = data_get($journey, 'operating_date');
    $journeyDate = $operatingDate
        ? \Carbon\CarbonImmutable::parse($operatingDate)->format('d/m/Y')
        : '-';
    $formatDateTime = static function ($value) use ($timezone): string {
        if (! filled($value)) {
            return '-';
        }

        return \Carbon\CarbonImmutable::parse($value)->setTimezone($timezone)->format('d/m/Y H:i');
    };
    $formatKg = static fn ($value): string => number_format((float) ($value ?? 0), 3, '.', ',').' kg';
    $summaryGroups = [
        ['key' => 'own', 'title' => 'Mi empresa', 'class' => 'summary-own'],
        ['key' => 'external', 'title' => 'Empresa externa', 'class' => 'summary-external'],
        ['key' => 'total', 'title' => 'Total general', 'class' => 'summary-total'],
    ];
    $companyName = $company->nombre_comercial ?: $company->razon_social;
  @endphp
  <style>
    @page { size: A4 landscape; margin: 26px 24px 38px; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: {{ $reportPalette['page_background'] }};
      color: {{ $reportPalette['body_text'] }};
      font-family: "DejaVu Sans", sans-serif;
      font-size: 7px;
      line-height: 1.3;
    }
    .report-header {
      width: 100%;
      margin-bottom: 9px;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .report-header td { vertical-align: middle; }
    .brand-block { width: 38%; }
    .title-block { width: 62%; text-align: right; }
    .brand {
      margin: 0 0 2px;
      color: {{ $reportPalette['primary'] }};
      font-size: 12px;
      font-weight: bold;
    }
    .company-meta { color: {{ $reportPalette['muted_text'] }}; font-size: 6.5px; }
    h1 {
      margin: 0 0 2px;
      color: {{ $reportPalette['primary'] }};
      font-size: 16px;
      letter-spacing: .2px;
      text-transform: uppercase;
    }
    .report-kicker {
      color: {{ $reportPalette['muted_text'] }};
      font-size: 7px;
      text-transform: uppercase;
    }
    .journey-strip {
      width: 100%;
      margin: 0 0 10px;
      border: 1px solid {{ $reportPalette['border'] }};
      border-collapse: collapse;
      table-layout: fixed;
    }
    .journey-strip td {
      border-right: 1px solid {{ $reportPalette['border'] }};
      background: {{ $reportPalette['accent'] }};
      padding: 6px 8px;
      vertical-align: top;
    }
    .journey-strip td:last-child { border-right: 0; }
    .journey-strip span {
      display: block;
      margin-bottom: 1px;
      color: {{ $reportPalette['muted_text'] }};
      font-size: 5.8px;
      letter-spacing: .25px;
      text-transform: uppercase;
    }
    .journey-strip strong { color: {{ $reportPalette['primary'] }}; font-size: 8px; }
    .section-heading {
      margin: 0 0 5px;
      color: {{ $reportPalette['primary'] }};
      font-size: 10px;
      letter-spacing: .15px;
      text-transform: uppercase;
    }
    .section-note {
      margin: -3px 0 7px;
      color: {{ $reportPalette['muted_text'] }};
      font-size: 6.3px;
    }
    table.weighings {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    table.weighings thead { display: table-header-group; }
    table.weighings tfoot { display: table-row-group; }
    table.weighings tr { page-break-inside: avoid; }
    table.weighings th {
      border: 1px solid {{ $reportPalette['primary'] }};
      background: {{ $reportPalette['secondary'] }};
      color: {{ $reportPalette['secondary_text'] }};
      padding: 4px 2px;
      font-size: 5.5px;
      line-height: 1.15;
      text-align: center;
      text-transform: uppercase;
      vertical-align: middle;
    }
    table.weighings td {
      border: 1px solid {{ $reportPalette['border'] }};
      padding: 3.5px 2px;
      font-size: 5.9px;
      line-height: 1.2;
      vertical-align: middle;
      overflow-wrap: break-word;
    }
    table.weighings tbody tr:nth-child(even) td { background: {{ $reportPalette['accent'] }}; }
    .center { text-align: center; }
    .num { text-align: right; white-space: nowrap; }
    .primary-text { color: {{ $reportPalette['primary'] }}; font-weight: bold; }
    .muted { color: {{ $reportPalette['muted_text'] }}; }
    .record-code { display: block; color: {{ $reportPalette['primary'] }}; font-weight: bold; }
    .record-origin { display: block; margin-top: 1px; color: {{ $reportPalette['muted_text'] }}; font-size: 5.1px; }
    .empty {
      padding: 22px 8px !important;
      color: {{ $reportPalette['muted_text'] }};
      font-size: 8px !important;
      text-align: center;
    }
    .summary-section {
      margin-top: 12px;
      page-break-inside: avoid;
    }
    table.summary-layout {
      width: 100%;
      border-collapse: separate;
      border-spacing: 5px 0;
      table-layout: fixed;
    }
    table.summary-layout > tbody > tr > td { width: 33.333%; vertical-align: top; }
    .summary-card {
      width: 100%;
      border: 1px solid {{ $reportPalette['border'] }};
      border-collapse: collapse;
      table-layout: fixed;
    }
    .summary-card th {
      border-bottom: 1px solid {{ $reportPalette['primary'] }};
      background: {{ $reportPalette['accent'] }};
      color: {{ $reportPalette['primary'] }};
      padding: 6px;
      font-size: 8px;
      letter-spacing: .2px;
      text-align: left;
      text-transform: uppercase;
    }
    .summary-external th { color: {{ $reportPalette['debit'] }}; }
    .summary-total th {
      background: {{ $reportPalette['primary'] }};
      color: {{ $reportPalette['primary_text'] }};
    }
    .summary-card td {
      border-bottom: 1px solid {{ $reportPalette['border'] }};
      padding: 3.5px 6px;
      font-size: 6.4px;
    }
    .summary-card tr:last-child td { border-bottom: 0; }
    .summary-label { color: {{ $reportPalette['muted_text'] }}; }
    .summary-value { color: {{ $reportPalette['body_text'] }}; font-weight: bold; text-align: right; white-space: nowrap; }
    .summary-net .summary-label,
    .summary-net .summary-value { color: {{ $reportPalette['primary'] }}; font-size: 7px; }
    .report-footer {
      position: fixed;
      right: 0;
      bottom: -27px;
      left: 0;
      border-top: 1px solid {{ $reportPalette['border'] }};
      padding-top: 5px;
      color: {{ $reportPalette['muted_text'] }};
      font-size: 5.8px;
      text-align: right;
    }
    .page-number:after { content: counter(page); }
  </style>
</head>
<body>
  <footer class="report-footer">
    Recepción de pollo vivo · Solo pesadas activas · Generado {{ $generatedAt->format('d/m/Y H:i') }} · Página <span class="page-number"></span>
  </footer>

  <table class="report-header">
    <tr>
      <td class="brand-block">
        <p class="brand">{{ $companyName }}</p>
        <div class="company-meta">
          @if($company->ruc)RUC {{ $company->ruc }} · @endif{{ data_get($branch, 'name', 'Sucursal no disponible') }}
        </div>
      </td>
      <td class="title-block">
        <h1>Reporte completo de jornada</h1>
        <div class="report-kicker">Recepción de pollo vivo · Detalle por pesada</div>
      </td>
    </tr>
  </table>

  <table class="journey-strip">
    <tr>
      <td style="width: 24%"><span>Jornada operativa</span><strong>{{ $journeyDate }}</strong></td>
      <td style="width: 12%"><span>Número</span><strong>#{{ data_get($journey, 'id', '-') }}</strong></td>
      <td style="width: 14%"><span>Estado</span><strong>{{ data_get($journey, 'status', '-') }}</strong></td>
      <td style="width: 20%"><span>Inicio</span><strong>{{ $formatDateTime(data_get($journey, 'starts_at')) }}</strong></td>
      <td style="width: 20%"><span>Cierre programado</span><strong>{{ $formatDateTime(data_get($journey, 'ends_at')) }}</strong></td>
      <td style="width: 10%"><span>Pesadas</span><strong>{{ number_format($records->count()) }}</strong></td>
    </tr>
  </table>

  <h2 class="section-heading">Pesadas activas</h2>
  <p class="section-note">Cada fila corresponde a una pesada vigente registrada exclusivamente desde el módulo Recepción de pollo vivo.</p>

  <table class="weighings">
    <colgroup>
      <col style="width: 9%"><col style="width: 8%"><col style="width: 9%"><col style="width: 5%">
      <col style="width: 5%"><col style="width: 6%"><col style="width: 5%"><col style="width: 10%">
      <col style="width: 10%"><col style="width: 9%"><col style="width: 9%"><col style="width: 6%"><col style="width: 9%">
    </colgroup>
    <thead>
      <tr>
        <th>Peso bruto</th>
        <th>Tara</th>
        <th>Peso neto</th>
        <th>Javas</th>
        <th>Pollos</th>
        <th>Aves / java</th>
        <th>Sexo</th>
        <th>Propietario</th>
        <th>Destino</th>
        <th>Tipo de java</th>
        <th>Fecha y hora</th>
        <th>Origen</th>
        <th>Registro</th>
      </tr>
    </thead>
    <tbody>
      @forelse($records as $record)
        @php
          $isTicket = data_get($record, 'source') === 'TICKET';
          $recordCode = $isTicket
              ? (data_get($record, 'ticket.code') ?: 'Ticket #'.data_get($record, 'number', '-'))
              : 'Pesada #'.data_get($record, 'number', '-');
          $originLabel = $isTicket ? 'Ticket' : 'Recepción';
          $cageType = data_get($record, 'cage_type.name') ?: data_get($record, 'cage_type.code') ?: '-';
        @endphp
        <tr>
          <td class="num">{{ $formatKg(data_get($record, 'gross_weight_kg')) }}</td>
          <td class="num">{{ $formatKg(data_get($record, 'tare_weight_kg')) }}</td>
          <td class="num primary-text">{{ $formatKg(data_get($record, 'net_weight_kg')) }}</td>
          <td class="num">{{ number_format((int) data_get($record, 'cages', 0)) }}</td>
          <td class="num">{{ number_format((int) data_get($record, 'birds', 0)) }}</td>
          <td class="num">{{ number_format((int) data_get($record, 'birds_per_cage', 0)) }}</td>
          <td class="center">{{ ucfirst(mb_strtolower((string) data_get($record, 'sex', '-'))) }}</td>
          <td>{{ data_get($record, 'owner.name', '-') }}</td>
          <td>
            {{ data_get($record, 'destination.name', '-') ?: '-' }}
            @if(data_get($record, 'destination.type'))
              <span class="record-origin">{{ ucfirst(mb_strtolower((string) data_get($record, 'destination.type'))) }}</span>
            @endif
          </td>
          <td>{{ $cageType }}</td>
          <td class="center">{{ $formatDateTime(data_get($record, 'weighed_at')) }}</td>
          <td class="center muted">{{ $originLabel }}</td>
          <td><span class="record-code">{{ $recordCode }}</span></td>
        </tr>
      @empty
        <tr><td colspan="13" class="empty">No hay pesadas activas en la jornada seleccionada.</td></tr>
      @endforelse
    </tbody>
  </table>

  <section class="summary-section">
    <h2 class="section-heading">Resumen detallado de totales</h2>
    <p class="section-note">Los totales se calculan únicamente con las pesadas activas mostradas en este reporte.</p>
    <table class="summary-layout">
      <tr>
        @foreach($summaryGroups as $group)
          @php($summary = data_get($summaries, $group['key'], []))
          <td>
            <table class="summary-card {{ $group['class'] }}">
              <tr><th colspan="2">{{ $group['title'] }}</th></tr>
              <tr><td class="summary-label">Pesadas activas</td><td class="summary-value">{{ number_format((int) data_get($summary, 'weighings', 0)) }}</td></tr>
              <tr><td class="summary-label">Javas</td><td class="summary-value">{{ number_format((int) data_get($summary, 'cages', 0)) }}</td></tr>
              <tr><td class="summary-label">Pollos</td><td class="summary-value">{{ number_format((int) data_get($summary, 'birds', 0)) }}</td></tr>
              <tr><td class="summary-label">Pollos macho</td><td class="summary-value">{{ number_format((int) data_get($summary, 'male_birds', 0)) }}</td></tr>
              <tr><td class="summary-label">Pollos hembra</td><td class="summary-value">{{ number_format((int) data_get($summary, 'female_birds', 0)) }}</td></tr>
              <tr><td class="summary-label">Peso bruto</td><td class="summary-value">{{ $formatKg(data_get($summary, 'gross_weight_kg')) }}</td></tr>
              <tr><td class="summary-label">Tara total</td><td class="summary-value">{{ $formatKg(data_get($summary, 'tare_weight_kg')) }}</td></tr>
              <tr class="summary-net"><td class="summary-label">Peso neto</td><td class="summary-value">{{ $formatKg(data_get($summary, 'net_weight_kg')) }}</td></tr>
              <tr><td class="summary-label">Promedio por pollo</td><td class="summary-value">{{ $formatKg(data_get($summary, 'average_weight_per_bird_kg')) }}</td></tr>
            </table>
          </td>
        @endforeach
      </tr>
    </table>
  </section>
</body>
</html>
