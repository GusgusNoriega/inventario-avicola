<!doctype html>
<html lang="es" class="java-control-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Control de Javas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="java-control-page">
  <main class="java-control-shell">
    <header class="java-control-header card">
      <div>
        <p class="eyebrow">Control de activos</p>
        <h1>Control de javas por cliente</h1>
        <p>Consulta cuántas javas de la empresa tiene cada cliente y registra las devoluciones recogidas por nuestros camiones.</p>
      </div>
      <a class="menu-return-btn" href="{{ route('menu') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M4 6h7v7H4z"></path><path d="M13 6h7v7h-7z"></path>
          <path d="M4 15h7v3H4z"></path><path d="M13 15h7v3h-7z"></path>
        </svg>
        <span>Menú</span>
      </a>
    </header>

    <section class="java-summary" aria-label="Resumen del control de javas">
      <article class="java-summary-card card">
        <span>Javas de la empresa en clientes</span>
        <strong id="javaTotalPending" class="java-summary-debt is-clear">0</strong>
        <small>Pendientes de devolución</small>
      </article>
      <article class="java-summary-card card">
        <span>Clientes con javas pendientes</span>
        <strong id="javaClientsPending">0</strong>
        <small>Deben devolver javas de la empresa</small>
      </article>
      <article class="java-summary-card card">
        <span>Devueltas hoy</span>
        <strong id="javaReceivedToday">0</strong>
        <small>Javas recuperadas por la empresa</small>
      </article>
    </section>

    <section class="java-control-grid">
      <section class="java-balance-panel card" aria-labelledby="javaBalanceTitle">
        <div class="java-section-head">
          <div>
            <p class="eyebrow">Pendientes de devolución</p>
            <h2 id="javaBalanceTitle">Javas en poder de cada cliente</h2>
          </div>
          <label class="java-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="7.5"></circle><path d="M16 16l5 5"></path></svg>
            <input id="javaClientSearch" type="search" placeholder="Buscar cliente o documento">
          </label>
        </div>
        <p id="javaBalanceMessage" class="java-message" role="status" aria-live="polite"></p>
        <div class="java-table-wrap">
          <table class="java-table">
            <thead>
              <tr><th>Cliente</th><th>Documento</th><th>Javas que debe devolver</th><th></th></tr>
            </thead>
            <tbody id="javaClientRows"></tbody>
          </table>
        </div>
      </section>

      <section class="java-receipt-panel card" aria-labelledby="javaReceiptTitle">
        <div class="java-section-head">
          <div>
            <p class="eyebrow">Devolución a planta</p>
            <h2 id="javaReceiptTitle">Registrar devolución de javas</h2>
          </div>
          <span class="java-entry-badge">Reduce las pendientes</span>
        </div>

        <form id="javaReceiptForm" class="java-receipt-form" novalidate>
          <label class="field">
            Cliente que devuelve las javas <span class="java-required">*</span>
            <select id="javaReceiptClient" required>
              <option value="">Seleccionar cliente</option>
            </select>
          </label>
          <p id="javaClientBalanceHint" class="java-balance-hint">Selecciona un cliente para consultar cuántas javas debe devolver.</p>
          <div class="java-form-row">
            <label class="field">
              Camión de la empresa que las recogió <span class="java-required">*</span>
              <select id="javaReceiptTruck" required><option value="">Seleccionar camión</option></select>
            </label>
            <label class="field">
              Chofer <span class="java-required">*</span>
              <select id="javaReceiptDriver" required><option value="">Seleccionar chofer</option></select>
            </label>
          </div>
          <p class="java-resource-hint">Solo aparecen recursos propios activos. Si falta alguno, agrégalo en <a href="{{ route('flota') }}">Mi flota y choferes</a>.</p>
          <label class="field">
            Cantidad devuelta <span class="java-required">*</span>
            <input id="javaReceiptQuantity" type="number" min="1" step="1" inputmode="numeric" placeholder="0" required>
          </label>
          <p class="java-balance-hint">La fecha y hora de la devolución se registrarán automáticamente al confirmar.</p>
          <label class="field">
            Observaciones
            <textarea id="javaReceiptObservations" rows="3" maxlength="500" placeholder="Información opcional de la recepción"></textarea>
          </label>
          <button id="javaReceiptSubmit" class="btn btn-success java-submit-btn" type="submit">
            Registrar devolución de javas
          </button>
          <p id="javaReceiptMessage" class="java-message" role="status" aria-live="polite"></p>
        </form>
      </section>
    </section>

    <section class="java-history-panel card" aria-labelledby="javaHistoryTitle">
      <div class="java-section-head">
        <div>
          <p class="eyebrow">Trazabilidad</p>
          <h2 id="javaHistoryTitle">Historial de movimientos</h2>
        </div>
        <label class="java-history-filter">
          Ver cliente
          <select id="javaHistoryClient"><option value="">Todos los clientes</option></select>
        </label>
      </div>
      <p id="javaHistoryMessage" class="java-message" role="status" aria-live="polite"></p>
      <div class="java-table-wrap">
        <table class="java-table java-history-table">
          <thead>
            <tr><th>Fecha</th><th>Cliente</th><th>Movimiento</th><th>Cantidad</th><th>Camión / chofer</th><th>Referencia</th></tr>
          </thead>
          <tbody id="javaMovementRows"></tbody>
        </table>
      </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/control-javas.js') }}"></script>
</body>
</html>
