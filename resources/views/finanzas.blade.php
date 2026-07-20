<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Saldos y trazabilidad | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body class="fin-page fin-dashboard-page">
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'dashboard',
      'eyebrow' => 'Tesorería y cartera',
      'title' => 'Saldos y trazabilidad',
      'description' => 'Sigue cada sol desde el cliente hasta la cuenta receptora y el proveedor correspondiente.'
    ])

    <section class="fin-summary-grid fin-dashboard-summary-grid" aria-label="Resumen financiero">
      <article class="fin-summary-card fin-card fin-summary-balance">
        <div>
          <span>Saldo disponible propio</span>
          <small>Dinero en bancos, cajas y billeteras</small>
        </div>
        <strong id="financeAvailableBalance">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card">
        <div>
          <span>Por cobrar a clientes</span>
          <small>Documentos pendientes y parciales</small>
        </div>
        <strong id="financeReceivableBalance">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card">
        <div>
          <span>Por pagar a proveedores</span>
          <small>Compras aún no canceladas</small>
        </div>
        <strong id="financePayableBalance">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card fin-summary-direct">
        <div>
          <span>Pago directo a proveedores</span>
          <small>Acumulado depositado por clientes</small>
        </div>
        <strong id="financeDirectPaid">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card fin-summary-provider-credit">
        <div>
          <span>Saldo a favor con proveedores</span>
          <small>Disponible en soles para futuras compras</small>
        </div>
        <strong id="financeProviderCreditBalance">S/ 0.00</strong>
      </article>
    </section>

    <section class="fin-accounts-panel fin-card" aria-labelledby="financeAccountsTitle">
      <div class="fin-section-head">
        <div>
          <p class="fin-eyebrow">Liquidez actual</p>
          <h2 id="financeAccountsTitle">Saldos por cuenta propia</h2>
        </div>
        <a class="fin-btn fin-btn-ghost fin-btn-small" href="{{ route('finanzas.entidades') }}">Administrar cuentas</a>
      </div>
      <p id="financeBalanceMessage" class="fin-message" role="status" aria-live="polite">Cargando saldos...</p>
      <div id="financeAccountBalances" class="fin-account-balance-grid" aria-live="polite"></div>
    </section>

    <section class="fin-advances-panel fin-card" aria-labelledby="financeAdvancesTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Crédito disponible para compras</p>
          <h2 id="financeAdvancesTitle">Saldo a favor con proveedores</h2>
          <p class="fin-section-copy">Incluye depósitos o transferencias no aplicados y saldos anteriores cargados manualmente.</p>
        </div>
        <div class="fin-section-actions">
          <a class="fin-btn fin-btn-ghost fin-btn-small" href="{{ route('finanzas.movimientos.nuevo') }}?tipo=PAGO_PROVEEDOR">Depositar o transferir</a>
          <a class="fin-btn fin-btn-primary fin-btn-small" href="{{ route('finanzas.movimientos.nuevo') }}?tipo=SALDO_FAVOR_PROVEEDOR">Registrar saldo anterior</a>
        </div>
      </div>
      <p id="financeAdvanceMessage" class="fin-message" role="status" aria-live="polite">Consultando saldos a favor...</p>
      <div id="financeAdvanceList" class="fin-advance-list" aria-live="polite"></div>
    </section>

    <section class="fin-trace-panel fin-card" aria-labelledby="financeTraceTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Recorrido del dinero</p>
          <h2 id="financeTraceTitle">Trazabilidad financiera</h2>
        </div>
        <a class="fin-btn fin-btn-primary fin-btn-small" href="{{ route('finanzas.movimientos.nuevo') }}">Registrar movimiento</a>
      </div>

      <form id="financeTraceFilters" class="fin-filter-grid" novalidate>
        <label class="fin-field">
          <span>Desde</span>
          <input id="financeFilterFrom" type="date" name="desde">
        </label>
        <label class="fin-field">
          <span>Hasta</span>
          <input id="financeFilterTo" type="date" name="hasta">
        </label>
        <label class="fin-field">
          <span>Cliente</span>
          <select id="financeFilterClient" name="cliente_id"><option value="">Todos</option></select>
        </label>
        <label class="fin-field">
          <span>Proveedor</span>
          <select id="financeFilterProvider" name="proveedor_id"><option value="">Todos</option></select>
        </label>
        <label class="fin-field">
          <span>Empresa receptora</span>
          <select id="financeFilterEntity" name="entidad_financiera_id"><option value="">Todas</option></select>
        </label>
        <label class="fin-field">
          <span>Método</span>
          <select id="financeFilterMethod" name="metodo_pago_id"><option value="">Todos</option></select>
        </label>
        <label class="fin-field fin-filter-search">
          <span>Referencia o ticket</span>
          <input id="financeFilterSearch" type="search" name="buscar" placeholder="Ej: operación, ticket o referencia">
        </label>
        <div class="fin-filter-actions">
          <button class="fin-btn fin-btn-primary" type="submit">Aplicar filtros</button>
          <button id="financeClearFilters" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
        </div>
      </form>

      <p id="financeTraceMessage" class="fin-message" role="status" aria-live="polite">Cargando trazabilidad...</p>
      <div class="fin-table-wrap">
        <table class="fin-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Operación</th>
              <th>Cliente / origen</th>
              <th>Empresa receptora</th>
              <th>Proveedor</th>
              <th>Método</th>
              <th>Referencia</th>
              <th class="fin-text-right">Importe</th>
            </tr>
          </thead>
          <tbody id="financeTraceRows"></tbody>
        </table>
      </div>
      <div class="fin-pagination" aria-label="Paginación de trazabilidad">
        <button id="financeTracePrevious" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Anterior</button>
        <span id="financeTracePage">Página 1</span>
        <button id="financeTraceNext" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Siguiente</button>
      </div>
    </section>

    <section class="fin-recent-panel fin-card" aria-labelledby="financeRecentTitle">
      <div class="fin-section-head">
        <div>
          <p class="fin-eyebrow">Actividad reciente</p>
          <h2 id="financeRecentTitle">Últimos cobros y pagos registrados</h2>
        </div>
      </div>
      <p id="financeMovementMessage" class="fin-message" role="status" aria-live="polite"></p>
      <div id="financeRecentMovements" class="fin-recent-list" aria-live="polite"></div>
    </section>
  </main>

  <dialog id="financeAdvanceDialog" class="fin-purchase-dialog fin-advance-dialog" aria-labelledby="financeAdvanceDialogTitle" aria-describedby="financeAdvanceDialogDescription">
    <form id="financeAdvanceForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div>
          <p class="fin-eyebrow">Uso del saldo disponible</p>
          <h2 id="financeAdvanceDialogTitle">Aplicar saldo a favor a una deuda</h2>
        </div>
        <button id="financeAdvanceClose" class="fin-dialog-close" type="button" aria-label="Cerrar aplicación">×</button>
      </header>

      <p id="financeAdvanceDialogDescription" class="fin-advance-dialog-copy">El depósito, transferencia o saldo anterior no se modificará. Solo se vinculará su importe disponible con las deudas que selecciones.</p>

      <dl class="fin-advance-facts">
        <div><dt>Movimiento</dt><dd id="financeAdvanceCode">—</dd></div>
        <div><dt>Proveedor</dt><dd id="financeAdvanceProvider">—</dd></div>
        <div><dt>Referencia</dt><dd id="financeAdvanceReference">—</dd></div>
        <div><dt>Fecha</dt><dd id="financeAdvanceDate">—</dd></div>
      </dl>

      <div class="fin-advance-summary" aria-label="Resumen de aplicación">
        <article><span>Importe de la fuente</span><strong id="financeAdvanceTotal">S/ 0.00</strong></article>
        <article><span>Disponible</span><strong id="financeAdvanceAvailable">S/ 0.00</strong></article>
        <article><span>Seleccionado</span><strong id="financeAdvanceSelected">S/ 0.00</strong></article>
        <article><span>Quedará a favor</span><strong id="financeAdvanceRemaining">S/ 0.00</strong></article>
      </div>

      <section class="fin-debt-panel fin-advance-debts" aria-labelledby="financeAdvanceDebtsTitle">
        <div class="fin-debt-head">
          <div><span class="fin-debt-side fin-debt-side-cxp">CXP</span><h3 id="financeAdvanceDebtsTitle">Deudas del proveedor</h3></div>
          <span id="financeAdvanceDebtTotal" class="fin-debt-total">Pendiente S/ 0.00</span>
        </div>
        <p id="financeAdvanceDebtMessage" class="fin-message" role="status" aria-live="polite"></p>
        <div id="financeAdvanceDebtList" class="fin-debt-list"></div>
      </section>

      <p id="financeAdvanceFormMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button id="financeAdvanceCancel" class="fin-btn fin-btn-ghost" type="button">Cancelar</button>
        <button id="financeAdvanceSubmit" class="fin-btn fin-btn-primary" type="submit">Aplicar a las deudas seleccionadas</button>
      </footer>
    </form>
  </dialog>

  <script type="module" src="{{ asset('js/finanzas-dashboard.js') }}"></script>
</body>
</html>
