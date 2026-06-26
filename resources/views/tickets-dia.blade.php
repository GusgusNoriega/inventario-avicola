<!doctype html>
<html lang="es" class="customer-history-root daily-tickets-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tickets del dia | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="customer-history-page daily-tickets-page">
  <main class="customer-history-view daily-tickets-view" data-daily-tickets>
    <header class="customer-history-header card">
      <div>
        <p class="eyebrow">Despacho</p>
        <h1>Tickets del dia</h1>
        <p id="dailyTicketsMeta" class="customer-history-meta">Cargando jornada...</p>
      </div>
      <div class="daily-tickets-header-actions">
        <a class="menu-return-btn" href="{{ route('menu') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 6h7v7H4z"></path>
            <path d="M13 6h7v7h-7z"></path>
            <path d="M4 15h7v3H4z"></path>
            <path d="M13 15h7v3h-7z"></path>
          </svg>
          <span>Menu</span>
        </a>
        <a class="btn btn-primary directory-btn" href="{{ route('operacion') }}#despacho">Ir a despacho</a>
      </div>
    </header>

    <p id="dailyTicketsMessage" class="form-message customer-history-message" role="status" aria-live="polite"></p>

    <section class="customer-history-filters card" aria-labelledby="dailyTicketsFiltersTitle">
      <div class="section-head">
        <div>
          <p class="eyebrow">Consulta</p>
          <h2 id="dailyTicketsFiltersTitle">Filtrar resumen</h2>
        </div>
      </div>
      <form id="dailyTicketsFilters" class="daily-tickets-filter-grid">
        <label class="field">
          Desde fecha
          <input id="dailyTicketsFromDate" type="date">
        </label>
        <label class="field">
          Desde hora
          <input id="dailyTicketsFromTime" type="time">
        </label>
        <label class="field">
          Hasta fecha
          <input id="dailyTicketsToDate" type="date">
        </label>
        <label class="field">
          Hasta hora
          <input id="dailyTicketsToTime" type="time">
        </label>
        <label class="field">
          Codigo de ticket
          <input id="dailyTicketsSearch" type="search" placeholder="Ej: T-20260626-001">
        </label>
        <div class="customer-history-filter-actions">
          <button class="btn btn-secondary directory-btn" type="submit">Buscar</button>
          <button id="dailyTicketsRefresh" class="btn btn-ghost directory-btn" type="button">Actualizar</button>
        </div>
      </form>
    </section>

    <section class="customer-history-stats daily-tickets-stats" aria-label="Resumen diario">
      <article class="directory-stat card"><span>Tickets</span><strong id="dailyTicketCount">0</strong></article>
      <article class="directory-stat card"><span>Pesadas</span><strong id="dailyRecordCount">0</strong></article>
      <article class="directory-stat card"><span>Javas</span><strong id="dailyCageCount">0</strong></article>
      <article class="directory-stat card"><span>Aves</span><strong id="dailyBirdCount">0</strong></article>
      <article class="directory-stat card"><span>Peso bruto</span><strong id="dailyGrossWeight">0.000 kg</strong></article>
      <article class="directory-stat card"><span>Javas kg</span><strong id="dailyTareWeight">0.000 kg</strong></article>
      <article class="directory-stat card directory-stat-accent"><span>Peso neto</span><strong id="dailyNetWeight">0.000 kg</strong></article>
    </section>

    <section id="dailyOperationSummary" class="daily-operation-summary" aria-label="Resumen por operacion"></section>

    <section class="customer-history-section" aria-labelledby="dailyTypeTotalsTitle">
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Resumen</p>
          <h2 id="dailyTypeTotalsTitle">Totales por tipo</h2>
        </div>
      </div>
      <div class="customer-history-table-wrap card">
        <table class="customer-history-table daily-type-table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>Pesadas</th>
              <th>Javas</th>
              <th>Aves</th>
              <th>Bruto</th>
              <th>Javas kg</th>
              <th>Neto</th>
            </tr>
          </thead>
          <tbody id="dailyTypeTotals"></tbody>
        </table>
      </div>
    </section>

    <section class="customer-history-section" aria-labelledby="dailyTicketsTitle">
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Tickets registrados</p>
          <h2 id="dailyTicketsTitle">Detalle del dia</h2>
        </div>
        <span id="dailyTicketsResultCount" class="directory-record-tag">0 resultados</span>
      </div>
      <div id="dailyTicketList" class="customer-ticket-list daily-ticket-list" aria-live="polite"></div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/tickets-dia.js') }}"></script>
</body>
</html>
