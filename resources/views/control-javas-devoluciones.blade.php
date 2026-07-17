<!doctype html>
<html lang="es" class="java-control-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Devoluciones de Javas y Bandejas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="java-control-page" data-java-page="returns">
  <main class="java-control-shell java-subview-shell">
    @include('partials.java-control-header', [
      'eyebrow' => 'Control de activos',
      'title' => 'Pendientes y devoluciones',
      'description' => 'Consulta los saldos por cliente y registra las javas y bandejas que regresan a la empresa.',
    ])

    <section class="java-summary java-returns-summary" aria-label="Resumen de devoluciones">
      <article class="java-summary-card card">
        <span>Activos pendientes</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaTotalPending">0</strong></span>
          <span><small>Bandejas</small><strong id="trayTotalPending">0</strong></span>
        </div>
        <small>En poder de clientes</small>
      </article>
      <article class="java-summary-card card"><span>Clientes con saldo</span><strong id="javaClientsPending">0</strong><small>Deben realizar devolución</small></article>
      <article class="java-summary-card card">
        <span>Recibidas hoy</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaReceivedToday">0</strong></span>
          <span><small>Bandejas</small><strong id="trayReceivedToday">0</strong></span>
        </div>
        <small>Entradas registradas</small>
      </article>
    </section>

    <section class="java-control-grid java-returns-grid">
      <section class="java-balance-panel card" aria-labelledby="javaBalanceTitle">
        <div class="java-section-head">
          <div><p class="eyebrow">Saldos por cliente</p><h2 id="javaBalanceTitle">Javas y bandejas por devolver</h2></div>
          <label class="java-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="7.5"></circle><path d="M16 16l5 5"></path></svg>
            <input id="javaClientSearch" type="search" placeholder="Buscar cliente o documento">
          </label>
        </div>
        <p id="javaBalanceMessage" class="java-message" role="status" aria-live="polite"></p>
        <div class="java-table-wrap java-table-viewport">
          <table class="java-table">
            <thead><tr><th>Cliente</th><th>Documento</th><th>Javas</th><th>Bandejas</th><th></th></tr></thead>
            <tbody id="javaClientRows"></tbody>
          </table>
        </div>
        <nav id="javaClientPagination" class="java-pagination" aria-label="Paginación de clientes"></nav>
      </section>

      <section class="java-receipt-panel card" aria-labelledby="javaReceiptTitle">
        <div class="java-section-head">
          <div><p class="eyebrow">Entrada a la empresa</p><h2 id="javaReceiptTitle">Registrar devolución</h2></div>
          <span class="java-entry-badge">Reduce el saldo</span>
        </div>
        <form id="javaReceiptForm" class="java-receipt-form java-compact-form" novalidate>
          <label class="field">Cliente <span class="java-required">*</span>
            <select id="javaReceiptClient" required><option value="">Seleccionar cliente</option></select>
          </label>
          <p id="javaClientBalanceHint" class="java-balance-hint">Selecciona un cliente para consultar ambos saldos.</p>
          <div class="java-form-row">
            <label class="field">Camión <span class="java-required">*</span>
              <select id="javaReceiptTruck" required><option value="">Seleccionar camión</option></select>
            </label>
            <label class="field">Chofer <span class="java-required">*</span>
              <select id="javaReceiptDriver" required><option value="">Seleccionar chofer</option></select>
            </label>
          </div>
          <p class="java-resource-hint">Solo se muestran recursos propios activos. <a href="{{ route('flota') }}">Administrar flota</a>.</p>
          <div class="java-form-row java-asset-quantity-fields">
            <label class="field">Javas recibidas
              <input id="javaReceiptQuantity" type="number" min="0" step="1" inputmode="numeric" value="0" required>
            </label>
            <label class="field">Bandejas recibidas
              <input id="trayReceiptQuantity" type="number" min="0" step="1" inputmode="numeric" value="0" required>
            </label>
          </div>
          <p class="java-resource-hint">Registra al menos una java o una bandeja; la otra cantidad puede quedar en cero.</p>
          <label class="field">Observaciones
            <textarea id="javaReceiptObservations" rows="2" maxlength="500" placeholder="Opcional"></textarea>
          </label>
          <button id="javaReceiptSubmit" class="btn btn-success java-submit-btn" type="submit">Registrar entrada</button>
          <p id="javaReceiptMessage" class="java-message" role="status" aria-live="polite"></p>
        </form>
      </section>
    </section>
  </main>

  <script type="module" src="{{ asset('js/control-javas.js') }}"></script>
</body>
</html>
