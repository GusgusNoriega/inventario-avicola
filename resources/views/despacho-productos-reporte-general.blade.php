<!doctype html>
<html lang="es" class="product-dispatch-general-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Reporte general | Despacho de productos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-reporte-general.css') }}?v={{ filemtime(public_path('css/despacho-productos-reporte-general.css')) }}">
  @include('partials.product-dispatch-keyboards-styles')
</head>
<body class="product-dispatch-general-page">
  <main id="productDispatchGeneralReport" class="pdgr-shell"
    data-api-base="/despacho-productos/reporte-general"
    data-pdf-url="{{ route('despacho-productos.reporte-general.pdf') }}">
    <header class="pdgr-header">
      <div class="pdgr-heading-copy">
        <p class="pdgr-eyebrow">Despacho de productos <span aria-hidden="true">/</span> Reportes</p>
        <h1>Reporte general</h1>
        <p>Todo lo despachado, producto por producto y día por día.</p>
      </div>
      <a class="pdgr-button pdgr-back" href="{{ route('despacho-productos.menu') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 5-7 7 7 7M3 12h19"></path></svg>
        Volver al módulo
      </a>
    </header>

    <section class="pdgr-query pdgr-panel" aria-labelledby="pdgrFilterTitle">
      <div class="pdgr-query-heading">
        <div>
          <p class="pdgr-eyebrow">Periodo de consulta</p>
          <h2 id="pdgrFilterTitle">Elige una fecha o un rango</h2>
        </div>
        <span id="pdgrBranch" class="pdgr-branch">Cargando sucursal…</span>
      </div>
      <form id="pdgrFilters" class="pdgr-filter-grid" novalidate>
        <label class="pdgr-field" for="pdgrDateFrom">
          <span>Desde <b aria-hidden="true">*</b></span>
          <input id="pdgrDateFrom" name="date_from" type="date" required inputmode="none" virtualkeyboardpolicy="manual" aria-describedby="pdgrDateHint" disabled>
        </label>
        <label class="pdgr-field" for="pdgrDateTo">
          <span>Hasta <small>Opcional</small></span>
          <input id="pdgrDateTo" name="date_to" type="date" inputmode="none" virtualkeyboardpolicy="manual" aria-describedby="pdgrDateHint" disabled>
        </label>
        <button id="pdgrToday" class="pdgr-button pdgr-button-soft" type="button" disabled>Hoy</button>
        <button id="pdgrConsult" class="pdgr-button pdgr-button-primary" type="submit" disabled>
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m15.5 15.5 5 5"></path></svg>
          <span>Consultar</span>
        </button>
      </form>
      <p id="pdgrDateHint" class="pdgr-hint">Para consultar un solo día, selecciona Desde y deja Hasta en blanco.</p>
    </section>

    <div id="pdgrMessage" class="pdgr-message" role="status" aria-live="polite" hidden></div>

    <section id="pdgrReport" class="pdgr-report" aria-labelledby="pdgrReportTitle" aria-busy="true">
      <header class="pdgr-report-heading">
        <div>
          <p class="pdgr-eyebrow">Resumen del periodo</p>
          <h2 id="pdgrReportTitle">Los despachos de hoy</h2>
          <p id="pdgrReportPeriod">Preparando el reporte de la sucursal…</p>
        </div>
        <button id="pdgrDownloadPdf" class="pdgr-button pdgr-button-primary" type="button" disabled aria-describedby="pdgrPdfHint">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"></path></svg>
          <span>Descargar PDF</span>
        </button>
      </header>
      <p id="pdgrPdfHint" class="pdgr-pdf-hint" hidden>Los filtros cambiaron. Pulsa Consultar para actualizar el reporte y descargar su PDF.</p>

      <div id="pdgrSummary" class="pdgr-summary" aria-label="Totales del periodo">
        <article class="pdgr-summary-card pdgr-panel">
          <span>Cantidad despachada</span>
          <strong id="pdgrQuantity">—</strong>
          <small id="pdgrProductCount">Productos del periodo</small>
        </article>
        <article class="pdgr-summary-card pdgr-panel">
          <span>Peso neto total</span>
          <strong><span id="pdgrNetWeight">—</span> <em>kg</em></strong>
          <small id="pdgrWeighingCount">Peso final despachado</small>
        </article>
        <article class="pdgr-summary-card pdgr-panel is-amount">
          <span>Importe total</span>
          <strong id="pdgrAmounts">—</strong>
          <small>Importes separados por moneda</small>
        </article>
        <article class="pdgr-summary-card pdgr-panel">
          <span>Tickets registrados</span>
          <strong id="pdgrTicketCount">—</strong>
          <small id="pdgrDayCount">Días con despachos</small>
        </article>
      </div>

      <div class="pdgr-detail-heading">
        <h3>Detalle por día</h3>
        <p>Las variantes se agrupan en su producto.</p>
      </div>
      <div id="pdgrDays" class="pdgr-days"></div>
      <div id="pdgrStatus" class="pdgr-status pdgr-panel" role="status">
        <span id="pdgrSpinner" class="pdgr-spinner" aria-hidden="true"></span>
        <strong id="pdgrStatusTitle">Preparando el reporte…</strong>
        <p id="pdgrStatusDetail">Estamos reuniendo los productos y sus totales.</p>
        <button id="pdgrRetry" class="pdgr-button pdgr-button-soft" type="button" hidden>Volver a intentar</button>
      </div>
      <footer class="pdgr-report-footer">
        <p>Los días corresponden a la fecha de registro del ticket en la sucursal.</p>
        <p id="pdgrGeneratedAt"></p>
      </footer>
    </section>
    @include('partials.system-credit')
  </main>
  @include('partials.product-dispatch-keyboards')
  <script type="module" src="{{ asset('js/despacho-productos-reporte-general.js') }}?v={{ filemtime(public_path('js/despacho-productos-reporte-general.js')) }}"></script>
</body>
</html>
