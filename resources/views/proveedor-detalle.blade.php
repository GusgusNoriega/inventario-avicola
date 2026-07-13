<!doctype html>
<html lang="es" class="customer-history-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Detalle del proveedor | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="customer-history-page">
  <main
    class="customer-history-view"
    data-provider-history
    data-provider-id="{{ request()->route('tercero') }}"
  >
    <header class="customer-history-header card">
      <div>
        <p class="eyebrow">Cuenta del proveedor</p>
        <h1 id="providerName">Cargando proveedor...</h1>
        <p id="providerMeta" class="customer-history-meta"></p>
      </div>
      <a class="menu-return-btn" href="{{ route('directorio') }}#proveedores">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M19 12H5"></path>
          <path d="M12 19l-7-7 7-7"></path>
        </svg>
        <span>Volver a proveedores</span>
      </a>
    </header>

    <p id="providerHistoryMessage" class="form-message customer-history-message" role="status" aria-live="polite"></p>

    <section class="customer-history-stats provider-history-stats" aria-label="Resumen filtrado">
      <article class="directory-stat card"><span>Pesadas</span><strong id="providerRecordCount">0</strong></article>
      <article class="directory-stat card"><span>Tickets destino</span><strong id="providerTicketCount">0</strong></article>
      <article class="directory-stat card"><span>Javas</span><strong id="providerCageCount">0</strong></article>
      <article class="directory-stat card"><span>Aves</span><strong id="providerBirdCount">0</strong></article>
      <article class="directory-stat card directory-stat-accent"><span>Peso neto</span><strong id="providerNetWeight">0.000 kg</strong></article>
    </section>

    <section id="providerFinanceSection" class="customer-history-section" aria-labelledby="providerFinanceTitle" hidden>
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Cuenta por pagar</p>
          <h2 id="providerFinanceTitle">Estado financiero</h2>
        </div>
        <a
          class="btn btn-success directory-btn"
          href="{{ route('finanzas.movimientos.nuevo') }}?tipo=PAGO_PROVEEDOR&amp;proveedor_id={{ request()->route('tercero') }}"
        >Registrar pago</a>
      </div>
      <div class="customer-history-stats" aria-label="Resumen financiero del proveedor">
        <article class="directory-stat card"><span>Compras valorizadas</span><strong id="providerFinanceDocumented">S/ 0.00</strong></article>
        <article class="directory-stat card"><span>Depositado por clientes</span><strong id="providerFinanceDirect">S/ 0.00</strong></article>
        <article class="directory-stat card"><span>Pagado por nosotros</span><strong id="providerFinanceOwn">S/ 0.00</strong></article>
        <article class="directory-stat card directory-stat-accent"><span>Saldo neto pendiente</span><strong id="providerFinancePending">S/ 0.00</strong></article>
        <article class="directory-stat card"><span>Costos sin precio</span><strong id="providerFinancePendingCosts">0</strong></article>
      </div>
      <p id="providerFinanceHelp" class="customer-history-meta"></p>
    </section>

    <section id="providerDirectDepositsSection" class="customer-history-section" aria-labelledby="providerDirectDepositsTitle" hidden>
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Trazabilidad</p>
          <h2 id="providerDirectDepositsTitle">Depositos directos de clientes</h2>
        </div>
      </div>
      <div class="customer-history-table-wrap card">
        <table class="customer-history-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Movimiento</th>
              <th>Cliente</th>
              <th>Destino</th>
              <th>Metodo / referencia</th>
              <th>Importe</th>
            </tr>
          </thead>
          <tbody id="providerDirectDepositList"></tbody>
        </table>
      </div>
    </section>

    <section class="provider-vehicle-panel card" aria-labelledby="providerVehiclesTitle">
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Transporte</p>
          <h2 id="providerVehiclesTitle">Camiones de mi empresa asignados</h2>
        </div>
        <span id="providerVehicleCount" class="directory-record-tag">0 camiones</span>
      </div>
      <p class="customer-history-meta">Cada placa que agregues pertenecerá a Mi flota y quedará asignada operativamente a este proveedor. Si ya existe en la flota, solo se creará la asignación.</p>
      <form id="providerVehicleForm" class="provider-vehicle-form">
        <label class="field">
          Placa del camión
          <input id="providerVehiclePlate" type="text" maxlength="20" autocomplete="off" placeholder="Ej: ABC-123" required>
        </label>
        <button id="saveProviderVehicle" class="btn btn-success directory-btn" type="submit">Crear y asignar camión</button>
      </form>
      <p id="providerVehicleMessage" class="form-message" role="status" aria-live="polite"></p>
      <div id="providerVehicleList" class="provider-vehicle-list" aria-live="polite"></div>
    </section>

    <section class="customer-history-filters card" aria-labelledby="providerFiltersTitle">
      <div class="section-head">
        <div>
          <p class="eyebrow">Consulta</p>
          <h2 id="providerFiltersTitle">Filtrar pesadas</h2>
        </div>
      </div>
      <form id="providerHistoryFilters" class="provider-history-filter-grid">
        <label class="field">
          Código de ticket
          <input id="providerTicketSearch" type="search" placeholder="Ej: T-20260620-001">
        </label>
        <label class="field">
          Placa
          <input id="providerPlateSearch" type="search" placeholder="Ej: ABC-123">
        </label>
        <label class="field">
          Fecha desde
          <input id="providerDateFrom" type="date">
        </label>
        <label class="field">
          Fecha hasta
          <input id="providerDateTo" type="date">
        </label>
        <div class="customer-history-filter-actions">
          <button class="btn btn-secondary directory-btn" type="submit">Buscar</button>
          <button id="clearProviderFilters" class="btn btn-ghost directory-btn" type="button">Limpiar</button>
        </div>
      </form>
    </section>

    <section class="customer-history-section" aria-labelledby="providerRecordsTitle">
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Recepciones y destinos</p>
          <h2 id="providerRecordsTitle">Pesadas del proveedor</h2>
        </div>
        <span id="providerResultCount" class="directory-record-tag">0 resultados</span>
      </div>
      <div class="customer-history-table-wrap card">
        <table class="customer-history-table provider-history-table">
          <thead>
            <tr>
              <th>Fecha/hora</th>
              <th>Ticket</th>
              <th>Destino</th>
              <th>Tipo destino</th>
              <th>Placa</th>
              <th>Tipo pollo</th>
              <th>Javas</th>
              <th>Aves</th>
              <th>Bruto</th>
              <th>Tara</th>
              <th>Neto</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody id="providerRecordList"></tbody>
        </table>
      </div>
      <nav id="providerRecordPagination" class="customer-history-pagination" aria-label="Paginación de pesadas"></nav>
    </section>
  </main>

  <script type="module" src="{{ asset('js/proveedor-detalle.js') }}"></script>
</body>
</html>
