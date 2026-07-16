<!doctype html>
<html lang="es" class="customer-history-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Historial del cliente | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="customer-history-page">
  <main
    class="customer-history-view"
    data-client-history
    data-client-id="{{ request()->route('tercero') }}"
  >
    <header class="customer-history-header card">
      <div>
        <p class="eyebrow">Cuenta del cliente</p>
        <h1 id="customerName">Cargando cliente...</h1>
        <p id="customerMeta" class="customer-history-meta"></p>
      </div>
      <a class="menu-return-btn" href="{{ route('directorio') }}#clientes">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M19 12H5"></path>
          <path d="M12 19l-7-7 7-7"></path>
        </svg>
        <span>Volver a clientes</span>
      </a>
    </header>

    <p id="customerHistoryMessage" class="form-message customer-history-message" role="status" aria-live="polite"></p>

    <section class="customer-history-stats" aria-label="Resumen filtrado">
      <article class="directory-stat card"><span>Tickets</span><strong id="historyTicketCount">0</strong></article>
      <article class="directory-stat card"><span>Registros</span><strong id="historyRecordCount">0</strong></article>
      <article class="directory-stat card"><span>Javas</span><strong id="historyCageCount">0</strong></article>
      <article class="directory-stat card"><span>Aves</span><strong id="historyBirdCount">0</strong></article>
      <article class="directory-stat card"><span>Peso neto</span><strong id="historyNetWeight">0.000 kg</strong></article>
      <article class="directory-stat card directory-stat-accent"><span>Importe</span><strong id="historyAmount">S/ 0.00</strong></article>
    </section>

    @if (auth()->user()->hasModule('MODULO_FINANZAS'))
    <section id="customerFinanceSection" class="customer-history-section" aria-labelledby="customerFinanceTitle" hidden>
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Cuenta por cobrar</p>
          <h2 id="customerFinanceTitle">Estado financiero</h2>
        </div>
        <a
          class="btn btn-success directory-btn"
          href="{{ route('finanzas.movimientos.nuevo') }}?tipo=COBRO_CLIENTE&amp;cliente_id={{ request()->route('tercero') }}"
        >Registrar abono</a>
      </div>
      <div class="customer-history-stats" aria-label="Resumen financiero del cliente">
        <article class="directory-stat card"><span>Ventas netas</span><strong id="customerFinanceDocumented">S/ 0.00</strong></article>
        <article class="directory-stat card"><span>Pagos registrados</span><strong id="customerFinancePayments">S/ 0.00</strong></article>
        <article class="directory-stat card"><span>Directo a proveedores</span><strong id="customerFinanceDirect">S/ 0.00</strong></article>
        <article class="directory-stat card directory-stat-accent"><span>Saldo neto pendiente</span><strong id="customerFinancePending">S/ 0.00</strong></article>
      </div>
      <p id="customerFinanceHelp" class="customer-history-meta"></p>
    </section>
    @endif

    <section class="customer-history-filters card" aria-labelledby="historyFiltersTitle">
      <div class="section-head">
        <div>
          <p class="eyebrow">Consulta</p>
          <h2 id="historyFiltersTitle">Filtrar tickets</h2>
        </div>
      </div>
      <form id="customerHistoryFilters" class="customer-history-filter-grid">
        <label class="field">
          Código de ticket
          <input id="historyTicketSearch" type="search" placeholder="Ej: T-20260620-001">
        </label>
        <label class="field">
          Fecha desde
          <input id="historyDateFrom" type="date">
        </label>
        <label class="field">
          Fecha hasta
          <input id="historyDateTo" type="date">
        </label>
        <div class="customer-history-filter-actions">
          <button class="btn btn-secondary directory-btn" type="submit">Buscar</button>
          <button id="clearHistoryFilters" class="btn btn-ghost directory-btn" type="button">Limpiar</button>
        </div>
      </form>
    </section>

    <section class="customer-history-section" aria-labelledby="customerTicketsTitle">
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Movimientos</p>
          <h2 id="customerTicketsTitle">Tickets del cliente</h2>
        </div>
        <span id="historyResultCount" class="directory-record-tag">0 resultados</span>
      </div>
      <div id="customerTicketList" class="customer-ticket-list" aria-live="polite"></div>
      <nav id="customerTicketPagination" class="customer-history-pagination" aria-label="Paginación de tickets"></nav>
    </section>

    <section class="customer-history-section" aria-labelledby="customerPricesTitle">
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Tarifas</p>
          <h2 id="customerPricesTitle">Histórico de precios</h2>
        </div>
      </div>
      <div class="customer-history-table-wrap card">
        <table class="customer-history-table">
          <thead>
            <tr>
              <th>Tipo de pollo</th>
              <th>Precio/kg</th>
              <th>Vigente desde</th>
              <th>Vigente hasta</th>
              <th>Motivo</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody id="customerPriceHistory"></tbody>
        </table>
      </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/cliente-detalle.js') }}"></script>
</body>
</html>
