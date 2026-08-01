<!doctype html>
<html lang="es" class="customer-history-root provider-report-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Reporte de proveedores | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/reporte-proveedores.css') }}?v={{ filemtime(public_path('css/reporte-proveedores.css')) }}">
</head>
<body class="customer-history-page provider-report-page">
  <main class="customer-history-view provider-report-view" data-provider-report>
    <header class="customer-history-header provider-report-header card">
      <div>
        <p class="eyebrow">Recepciones y destinos</p>
        <h1>Reporte de proveedores</h1>
        <p class="customer-history-meta">Consulta cuántas javas y pollos trajo cada camión, cuánto pesaron y a dónde fueron.</p>
      </div>
      <div class="provider-report-header-actions">
        <a class="menu-return-btn" href="{{ route('menu') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 6h7v7H4z"></path>
            <path d="M13 6h7v7h-7z"></path>
            <path d="M4 15h7v3H4z"></path>
            <path d="M13 15h7v3h-7z"></path>
          </svg>
          <span>Menú</span>
        </a>
      </div>
    </header>

    <section class="provider-current-context card" aria-labelledby="providerCurrentJourneyTitle">
      <div class="provider-current-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
          <path d="M5 5h14v15H5z"></path>
          <path d="M8 3v4M16 3v4M8 11h3M13 11h3M8 15h3M13 15h3"></path>
        </svg>
      </div>
      <div>
        <span class="provider-context-label">Jornada operativa actual</span>
        <strong id="providerCurrentJourneyTitle">Consultando fecha operativa…</strong>
        <small id="providerCurrentJourneyWindow">El horario de la jornada aparecerá al cargar.</small>
      </div>
      <div class="provider-context-branch">
        <span>Sucursal</span>
        <strong id="providerCurrentBranch">--</strong>
      </div>
      <button id="providerReturnCurrent" class="btn btn-primary" type="button" hidden>Volver a la actual</button>
    </section>

    <form id="providerReportFilters" class="customer-history-filters provider-report-filters card">
      <div class="provider-filter-heading">
        <div>
          <p class="eyebrow">Filtros del reporte</p>
          <h2>Consulta de pesadas</h2>
        </div>
        <span id="providerSelectedJourneyBadge" class="provider-journey-badge">Jornada actual</span>
      </div>
      <div class="provider-report-filter-grid">
        <label class="field">
          <span>Jornada</span>
          <select id="providerJourneyFilter" name="jornada" required>
            <option value="">Cargando jornadas…</option>
          </select>
        </label>
        <label class="field">
          <span>Proveedor</span>
          <select id="providerNameFilter" name="proveedor_id">
            <option value="">Todos los proveedores</option>
          </select>
        </label>
        <label class="field">
          <span>Camión del proveedor</span>
          <select id="providerTruckFilter" name="camion">
            <option value="">Todos los camiones</option>
          </select>
        </label>
        <div class="customer-history-filter-actions provider-filter-actions">
          <button id="providerFilterSubmit" class="btn btn-primary" type="submit">Aplicar filtros</button>
          <button id="providerFilterReset" class="btn btn-ghost" type="button">Restablecer</button>
        </div>
      </div>
    </form>

    <p id="providerReportMessage" class="customer-history-message provider-report-message" role="status" aria-live="polite"></p>

    <section class="customer-history-stats provider-report-stats" aria-label="Totales del reporte">
      <article class="directory-stat card">
        <span>Proveedores</span>
        <strong id="providerStatProviders">0</strong>
        <small>con pesadas</small>
      </article>
      <article class="directory-stat card">
        <span>Camiones</span>
        <strong id="providerStatTrucks">0</strong>
        <small>placas registradas</small>
      </article>
      <article class="directory-stat card">
        <span>Pesadas</span>
        <strong id="providerStatRecords">0</strong>
        <small id="providerStatTickets">0 tickets</small>
      </article>
      <article class="directory-stat card">
        <span>Javas pesadas</span>
        <strong id="providerStatCages">0</strong>
        <small>recibidas</small>
      </article>
      <article class="directory-stat card">
        <span>Pollos traídos</span>
        <strong id="providerStatBirds">0</strong>
        <small>registrados</small>
      </article>
      <article class="directory-stat directory-stat-accent card">
        <span>Peso neto</span>
        <strong id="providerStatNetWeight">0.000 kg</strong>
        <small id="providerStatAverage">0.000 kg por pollo</small>
      </article>
    </section>

    <section class="customer-history-section provider-truck-summary" aria-labelledby="providerTruckSummaryTitle">
      <div class="customer-history-section-head provider-section-head">
        <div>
          <p class="eyebrow">Lo que trajo cada camión</p>
          <h2 id="providerTruckSummaryTitle">Resumen por proveedor y camión</h2>
        </div>
        <span id="providerTruckCount" class="provider-record-count">0 camiones</span>
      </div>
      <div class="customer-history-table-wrap provider-summary-table-wrap card">
        <table class="customer-history-table provider-summary-table">
          <caption class="sr-only">Totales por proveedor y camión</caption>
          <thead>
            <tr>
              <th>Proveedor</th>
              <th>Camión</th>
              <th>Pesadas</th>
              <th>Javas</th>
              <th>Pollos</th>
              <th>Peso neto</th>
              <th>Promedio/pollo</th>
              <th>Destinos</th>
            </tr>
          </thead>
          <tbody id="providerTruckRows">
            <tr><td colspan="8" class="customer-history-empty-cell">Cargando resumen por camión…</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="customer-history-section provider-destination-section" aria-labelledby="providerDestinationTitle">
      <div class="customer-history-section-head provider-section-head">
        <div>
          <p class="eyebrow">Distribución de la carga</p>
          <h2 id="providerDestinationTitle">A dónde fueron los pollos</h2>
        </div>
        <span id="providerDestinationCount" class="provider-record-count">0 destinos</span>
      </div>
      <div id="providerDestinationCards" class="provider-destination-grid">
        <article class="provider-destination-empty card">Cargando destinos…</article>
      </div>
    </section>

    <section class="customer-history-section provider-detail-section" aria-labelledby="providerDetailTitle">
      <div class="customer-history-section-head provider-section-head">
        <div>
          <p class="eyebrow">Registro detallado</p>
          <h2 id="providerDetailTitle">Detalle de pesadas</h2>
        </div>
        <span id="providerRecordRange" class="provider-record-count">0 registros</span>
      </div>
      <div class="customer-history-table-wrap provider-detail-table-wrap card">
        <table class="customer-history-table provider-detail-table">
          <caption class="sr-only">Detalle de pesadas por proveedor, camión y destino</caption>
          <thead>
            <tr>
              <th>Hora / ticket</th>
              <th>Proveedor</th>
              <th>Camión</th>
              <th>Destino</th>
              <th>Pollo / java</th>
              <th>Javas</th>
              <th>Pollos</th>
              <th>Bruto</th>
              <th>Tara</th>
              <th>Neto</th>
            </tr>
          </thead>
          <tbody id="providerDetailRows">
            <tr><td colspan="10" class="customer-history-empty-cell">Cargando pesadas de proveedores…</td></tr>
          </tbody>
        </table>
      </div>
      <nav id="providerReportPagination" class="customer-history-pagination provider-report-pagination" aria-label="Páginas del reporte" hidden>
        <button id="providerPagePrevious" class="btn btn-ghost" type="button">Anterior</button>
        <span id="providerPageStatus">Página 1 de 1</span>
        <button id="providerPageNext" class="btn btn-ghost" type="button">Siguiente</button>
      </nav>
    </section>
  </main>

  <script type="module" src="{{ asset('js/reporte-proveedores.js') }}?v={{ filemtime(public_path('js/reporte-proveedores.js')) }}"></script>
</body>
</html>
