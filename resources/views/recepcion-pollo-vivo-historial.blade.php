<!doctype html>
<html lang="es" class="live-reception-history-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Historial de recepción de pollo vivo | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/recepcion-pollo-vivo-historial.css') }}?v={{ filemtime(public_path('css/recepcion-pollo-vivo-historial.css')) }}">
</head>
<body class="live-reception-history-page">
  <main class="live-reception-history-view" data-live-reception-history>
    <header class="live-history-header card">
      <div>
        <p class="eyebrow">Recepción de pollo vivo</p>
        <h1>Historial de pesadas</h1>
        <p>Consulta por jornada únicamente las pesadas creadas desde Recepción de pollo vivo.</p>
      </div>
      <a class="menu-return-btn live-history-back" href="{{ route('recepcion-pollo-vivo.menu') }}" aria-label="Volver a las opciones de recepción de pollo vivo">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M19 12H5M11 6l-6 6 6 6"></path>
        </svg>
        <span>Opciones de recepción</span>
      </a>
    </header>

    <section class="live-history-context card" aria-labelledby="liveHistoryJourneyTitle">
      <span class="live-history-context-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
          <path d="M5 5h14v15H5z"></path>
          <path d="M8 3v4M16 3v4M8 11h3M13 11h3M8 15h3M13 15h3"></path>
        </svg>
      </span>
      <div class="live-history-context-copy">
        <span>Jornada seleccionada</span>
        <strong id="liveHistoryJourneyTitle">Consultando jornada…</strong>
        <small id="liveHistoryJourneyWindow">El horario y estado aparecerán al cargar.</small>
      </div>
      <div class="live-history-context-branch">
        <span>Sucursal</span>
        <strong id="liveHistoryBranch">--</strong>
      </div>
      <span id="liveHistoryJourneyBadge" class="live-history-journey-badge">Cargando</span>
      <button id="liveHistoryReturnCurrent" class="btn btn-primary" type="button" hidden>Volver a la jornada actual</button>
    </section>

    <form id="liveHistoryFilters" class="live-history-filters card">
      <div class="live-history-filter-heading">
        <div>
          <p class="eyebrow">Filtros del historial</p>
          <h2>Pesadas por jornada</h2>
        </div>
        <p>Los totales representan toda la jornada; los filtros se aplican a la tabla detallada.</p>
      </div>
      <div class="live-history-filter-grid">
        <label class="field">
          <span>Jornada</span>
          <select id="liveHistoryJourneyFilter" name="journey_id" required>
            <option value="">Cargando jornadas…</option>
          </select>
        </label>
        <label class="field">
          <span>Estado</span>
          <select id="liveHistoryStatusFilter" name="status">
            <option value="">Activas y anuladas</option>
            <option value="ACTIVA">Solo activas</option>
            <option value="ANULADA">Solo anuladas</option>
          </select>
        </label>
        <label class="field">
          <span>Origen del registro</span>
          <select id="liveHistorySourceFilter" name="source">
            <option value="">Todos los orígenes</option>
            <option value="RECEPCION">Entradas de recepción</option>
            <option value="TICKET">Tickets de despacho</option>
          </select>
        </label>
        <div class="live-history-filter-actions">
          <button id="liveHistoryFilterSubmit" class="btn btn-primary" type="submit">Aplicar filtros</button>
          <button id="liveHistoryFilterReset" class="btn btn-ghost" type="button">Restablecer</button>
        </div>
      </div>
      <div class="live-history-report-bar" aria-labelledby="liveHistoryReportTitle">
        <div class="live-history-report-copy">
          <span class="live-history-report-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M7 3h7l4 4v14H7z"></path>
              <path d="M14 3v5h5M9.5 13h5M9.5 16h5"></path>
            </svg>
          </span>
          <div>
            <h3 id="liveHistoryReportTitle">Reporte completo de la jornada</h3>
            <p id="liveHistoryReportHelp">Incluye todas las pesadas activas y los totales de Mi empresa, Empresa externa y Total general.</p>
          </div>
        </div>
        <div class="live-history-report-actions">
          <a id="liveHistoryReportPreview" class="btn live-history-report-btn is-preview" target="_blank" rel="noopener" aria-describedby="liveHistoryReportHelp" aria-disabled="true" tabindex="-1">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5"></path>
              <circle cx="12" cy="12" r="2.5"></circle>
            </svg>
            <span>Previsualizar PDF</span>
          </a>
          <a id="liveHistoryReportPdf" class="btn live-history-report-btn is-pdf" aria-describedby="liveHistoryReportHelp" aria-disabled="true" tabindex="-1">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M7 3h7l4 4v14H7zM14 3v5h5"></path>
              <path d="M9 13h6M9 16h4"></path>
            </svg>
            <span>Descargar PDF</span>
          </a>
          <a id="liveHistoryReportImages" class="btn live-history-report-btn is-images" aria-describedby="liveHistoryReportHelp" aria-disabled="true" tabindex="-1">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <rect x="3" y="5" width="18" height="14" rx="2"></rect>
              <circle cx="9" cy="10" r="1.5"></circle>
              <path d="m5 17 4.5-4 3.2 2.7 2.6-2.3L19 17"></path>
            </svg>
            <span>Descargar imágenes</span>
          </a>
        </div>
      </div>
    </form>

    <p id="liveHistoryMessage" class="live-history-message" role="status" aria-live="polite"></p>

    <section class="live-history-summary-layout" aria-label="Totales de la jornada y filtros seleccionados">
      <article class="live-history-summary-group is-active card">
        <div class="live-history-summary-head">
          <div>
            <p class="eyebrow">Registros vigentes</p>
            <h2>Totales activos</h2>
          </div>
          <span class="live-history-status-pill is-active">Activas</span>
        </div>
        <div class="live-history-metric-grid">
          <div class="live-history-metric"><span>Pesadas</span><strong id="liveHistoryActiveWeighings">0</strong></div>
          <div class="live-history-metric"><span>Javas</span><strong id="liveHistoryActiveCages">0</strong></div>
          <div class="live-history-metric"><span>Pollos</span><strong id="liveHistoryActiveBirds">0</strong></div>
          <div class="live-history-metric"><span>Peso bruto</span><strong id="liveHistoryActiveGross">0.000 kg</strong></div>
          <div class="live-history-metric"><span>Tara</span><strong id="liveHistoryActiveTare">0.000 kg</strong></div>
          <div class="live-history-metric is-accent"><span>Peso neto</span><strong id="liveHistoryActiveNet">0.000 kg</strong></div>
        </div>
      </article>

      <article class="live-history-summary-group is-voided card">
        <div class="live-history-summary-head">
          <div>
            <p class="eyebrow">Trazabilidad</p>
            <h2>Totales anulados</h2>
          </div>
          <span class="live-history-status-pill is-voided">Anuladas</span>
        </div>
        <div class="live-history-metric-grid">
          <div class="live-history-metric"><span>Pesadas</span><strong id="liveHistoryVoidedWeighings">0</strong></div>
          <div class="live-history-metric"><span>Javas</span><strong id="liveHistoryVoidedCages">0</strong></div>
          <div class="live-history-metric"><span>Pollos</span><strong id="liveHistoryVoidedBirds">0</strong></div>
          <div class="live-history-metric"><span>Peso bruto</span><strong id="liveHistoryVoidedGross">0.000 kg</strong></div>
          <div class="live-history-metric"><span>Tara</span><strong id="liveHistoryVoidedTare">0.000 kg</strong></div>
          <div class="live-history-metric is-accent"><span>Peso neto</span><strong id="liveHistoryVoidedNet">0.000 kg</strong></div>
        </div>
      </article>
    </section>

    <section class="live-history-detail" aria-labelledby="liveHistoryDetailTitle">
      <div class="live-history-section-head">
        <div>
          <p class="eyebrow">Una fila por pesada</p>
          <h2 id="liveHistoryDetailTitle">Detalle completo</h2>
        </div>
        <span id="liveHistoryRecordRange" class="live-history-record-count">0 pesadas</span>
      </div>
      <p class="live-history-table-hint">En una pantalla táctil, desliza la tabla hacia los lados para consultar todas las columnas.</p>
      <div class="live-history-table-wrap card" role="region" tabindex="0" aria-label="Tabla desplazable del historial de recepción de pollo vivo">
        <table class="live-history-table">
          <caption class="sr-only">Pesadas registradas desde Recepción de pollo vivo</caption>
          <thead>
            <tr>
              <th scope="col">Peso bruto</th>
              <th scope="col">Tara</th>
              <th scope="col">Peso neto</th>
              <th scope="col">Javas</th>
              <th scope="col">Pollos</th>
              <th scope="col">Sexo</th>
              <th scope="col">Propietario</th>
              <th scope="col">Destino</th>
              <th scope="col">Tipo de java</th>
              <th scope="col">Origen</th>
              <th scope="col">Estado</th>
              <th scope="col">Registro</th>
            </tr>
          </thead>
          <tbody id="liveHistoryRows">
            <tr class="live-history-state-row"><td colspan="12">Cargando pesadas de la jornada…</td></tr>
          </tbody>
        </table>
      </div>
      <nav id="liveHistoryPagination" class="live-history-pagination" aria-label="Páginas del historial" hidden>
        <button id="liveHistoryPagePrevious" class="btn btn-ghost" type="button">Anterior</button>
        <span id="liveHistoryPageStatus">Página 1 de 1</span>
        <button id="liveHistoryPageNext" class="btn btn-ghost" type="button">Siguiente</button>
      </nav>
    </section>
  </main>

  <script type="module" src="{{ asset('js/recepcion-pollo-vivo-historial.js') }}?v={{ filemtime(public_path('js/recepcion-pollo-vivo-historial.js')) }}"></script>
</body>
</html>
