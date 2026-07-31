<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Registrar cobro o pago | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body class="fin-page fin-movement-page" data-finance-time-zone="{{ config('app.timezone') }}">
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'movimiento',
      'eyebrow' => 'Cobros, deudas y pagos',
      'title' => 'Registrar movimiento',
      'description' => 'Registra el recorrido del dinero o incorpora una deuda anterior de un cliente sin perder trazabilidad.'
    ])

    <form id="financeMovementForm" class="fin-movement-form" novalidate>
      <section class="fin-card fin-mode-panel" aria-labelledby="financeMovementModeTitle">
        <div class="fin-section-head">
          <div>
            <p class="fin-eyebrow">Paso 1</p>
            <h2 id="financeMovementModeTitle">¿Qué operación deseas registrar?</h2>
          </div>
        </div>
        <div class="fin-mode-grid" role="radiogroup" aria-label="Tipo de movimiento">
          <label class="fin-mode-option">
            <input type="radio" name="financeMovementType" value="COBRO_CLIENTE" checked>
            <span class="fin-mode-number">01</span>
            <span><strong>Cliente → nuestra empresa</strong><small>Aumenta el saldo de una cuenta propia y reduce la deuda del cliente.</small></span>
          </label>
          <label class="fin-mode-option">
            <input type="radio" name="financeMovementType" value="PAGO_DIRECTO">
            <span class="fin-mode-number">02</span>
            <span><strong>Cliente → proveedor</strong><small>Registra el pago aunque las deudas todavía no estén cargadas; puedes conciliarlas si ya existen.</small></span>
          </label>
          <label class="fin-mode-option">
            <input type="radio" name="financeMovementType" value="PAGO_PROVEEDOR">
            <span class="fin-mode-number">03</span>
            <span><strong>Nuestra empresa → proveedor</strong><small>Descuenta saldo propio y cancela una deuda con el proveedor.</small></span>
          </label>
          <label class="fin-mode-option">
            <input type="radio" name="financeMovementType" value="COBRO_MINORISTA">
            <span class="fin-mode-number">04</span>
            <span><strong>Cobro minorista</strong><small>Registra cómo se recibió el dinero, incluso sin cliente asignado.</small></span>
          </label>
          <label class="fin-mode-option">
            <input type="radio" name="financeMovementType" value="REEMBOLSO_CLIENTE">
            <span class="fin-mode-number">05</span>
            <span><strong>Nuestra empresa → cliente</strong><small>Reembolsa una devolución y cancela el abono pendiente del cliente.</small></span>
          </label>
          <label class="fin-mode-option">
            <input type="radio" name="financeMovementType" value="DEUDA_ANTERIOR_CLIENTE">
            <span class="fin-mode-number">06</span>
            <span><strong>Deuda anterior de cliente</strong><small>Agrega una deuda pendiente que existía antes y no estaba registrada en el sistema.</small></span>
          </label>
          <label class="fin-mode-option">
            <input type="radio" name="financeMovementType" value="SALDO_FAVOR_PROVEEDOR">
            <span class="fin-mode-number">07</span>
            <span><strong>Saldo anterior con proveedor</strong><small>Registra un saldo que ya existía antes del sistema, sin mover dinero de ninguna cuenta.</small></span>
          </label>
        </div>
      </section>

      <div class="fin-movement-columns">
        <section class="fin-card fin-movement-details" aria-labelledby="financeMovementDetailsTitle">
          <div class="fin-section-head">
            <div>
              <p class="fin-eyebrow">Paso 2</p>
              <h2 id="financeMovementDetailsTitle">Datos del movimiento</h2>
            </div>
            <span id="financeMovementFlowBadge" class="fin-badge">Ingreso propio</span>
          </div>

          <div class="fin-form-grid fin-movement-context-grid">
            <label id="financeMovementClientField" class="fin-field">
              <span id="financeMovementClientLabel">Cliente que paga <b>*</b></span>
              <select id="financeMovementClient"><option value="">Selecciona un cliente</option></select>
              <small id="financeMovementClientHelp">Se mostrarán sus documentos pendientes.</small>
            </label>
            <label id="financeMovementProviderField" class="fin-field" hidden>
              <span>Proveedor beneficiario <b>*</b></span>
              <select id="financeMovementProvider"><option value="">Selecciona un proveedor</option></select>
              <small>Se mostrarán las compras pendientes con este proveedor.</small>
            </label>
            <label id="financeMovementOriginField" class="fin-field" hidden>
              <span>Cuenta propia de origen <b>*</b></span>
              <select id="financeMovementOrigin"><option value="">Selecciona una cuenta</option></select>
              <small>El movimiento puede registrarse aunque el saldo inicial de la cuenta todavía no esté cargado.</small>
            </label>
            <label id="financeMovementDestinationField" class="fin-field">
              <span id="financeMovementDestinationLabel">Cuenta propia receptora <b>*</b></span>
              <select id="financeMovementDestination"><option value="">Selecciona una cuenta</option></select>
              <small id="financeMovementDestinationHelp">El importe aumentará el saldo disponible de esta cuenta.</small>
            </label>
          </div>

          <section id="financeProviderPaymentSourcePanel" class="fin-provider-payment-source" aria-labelledby="financeProviderPaymentSourceTitle" hidden>
            <div>
              <p class="fin-eyebrow">Fuente del pago</p>
              <h3 id="financeProviderPaymentSourceTitle">¿De dónde se tomará el importe?</h3>
            </div>
            <div class="fin-segmented" role="radiogroup" aria-label="Fuente del pago al proveedor">
              <label>
                <input type="radio" name="financeProviderPaymentSource" value="CUENTA" checked>
                <span><strong>Desde cuenta propia</strong><small>Registra una nueva salida por depósito, transferencia u otro método. Lo no aplicado quedará a favor.</small></span>
              </label>
              <label>
                <input type="radio" name="financeProviderPaymentSource" value="SALDO_FAVOR">
                <span><strong>Usar saldo a favor</strong><small>Aplica una fuente disponible a compras pendientes sin volver a mover dinero.</small></span>
              </label>
            </div>
            <label id="financeProviderCreditSourceField" class="fin-field" hidden>
              <span>Fuente de saldo disponible <b>*</b></span>
              <select id="financeProviderCreditSource"><option value="">Selecciona una fuente</option></select>
              <small id="financeProviderCreditSourceHelp" role="status" aria-live="polite">Selecciona un proveedor para consultar su saldo a favor.</small>
            </label>
          </section>

          <div class="fin-divider"></div>

          <div class="fin-form-grid">
            <label id="financeMovementDateField" class="fin-field">
              <span id="financeMovementDateLabel">Fecha y hora <b>*</b></span>
              <input id="financeMovementDate" type="datetime-local" required>
            </label>
            <label id="financeMovementMethodField" class="fin-field">
              <span>Método de pago <b>*</b></span>
              <select id="financeMovementMethod" required><option value="">Selecciona un método</option></select>
            </label>
            <label class="fin-field">
              <span id="financeMovementAmountLabel">Importe <b>*</b></span>
              <div class="fin-money-input"><span id="financeMovementCurrencyPrefix">S/</span><input id="financeMovementAmount" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required></div>
            </label>
            <label class="fin-field">
              <span>Moneda</span>
              <select id="financeMovementCurrency"><option value="PEN">Soles (PEN)</option><option value="USD">Dólares (USD)</option></select>
            </label>
            <label id="financeMovementReferenceField" class="fin-field">
              <span id="financeMovementReferenceLabel">Número de operación / referencia</span>
              <input id="financeMovementReference" type="text" maxlength="100" autocomplete="off" placeholder="Ej: OP-384729">
            </label>
            <label id="financeMovementNotesField" class="fin-field">
              <span id="financeMovementNotesLabel">Observaciones</span>
              <textarea id="financeMovementNotes" rows="2" maxlength="500" placeholder="Detalle adicional del pago"></textarea>
            </label>
          </div>
        </section>

        <aside class="fin-card fin-application-summary" aria-labelledby="financeApplicationSummaryTitle">
          <p class="fin-eyebrow">Resumen</p>
          <h2 id="financeApplicationSummaryTitle">Distribución del importe</h2>
          <div id="financeProviderCreditAvailableLine" class="fin-summary-line" hidden><span>Saldo disponible de la fuente</span><strong id="financeProviderCreditAvailable">S/ 0.00</strong></div>
          <div class="fin-summary-line"><span id="financeMovementTotalLabel">Importe del movimiento</span><strong id="financeMovementTotal">S/ 0.00</strong></div>
          <div id="financeCxcSummaryLine" class="fin-summary-line"><span>Aplicado a clientes</span><strong id="financeMovementCxcApplied">S/ 0.00</strong></div>
          <div id="financeCxpSummaryLine" class="fin-summary-line" hidden><span>Aplicado a proveedores</span><strong id="financeMovementCxpApplied">S/ 0.00</strong></div>
          <div class="fin-summary-line fin-summary-line-total"><span id="financeMovementUnappliedLabel">Sin aplicar</span><strong id="financeMovementUnapplied">S/ 0.00</strong></div>
          <p id="financeApplicationHint" class="fin-summary-hint">Puedes dejar parte del cobro sin aplicar como saldo a favor del cliente.</p>
          <p id="financeMovementMessage" class="fin-message" role="status" aria-live="polite"></p>
          <button id="financeMovementSave" class="fin-btn fin-btn-primary fin-btn-block" type="submit">Registrar movimiento</button>
          <button id="financeMovementReset" class="fin-btn fin-btn-ghost fin-btn-block" type="button">Limpiar formulario</button>
        </aside>
      </div>

      <section id="financeApplicationsPanel" class="fin-card fin-applications-panel" aria-labelledby="financeApplicationsTitle">
        <div class="fin-section-head">
          <div>
            <p class="fin-eyebrow">Paso 3</p>
            <h2 id="financeApplicationsTitle">Aplicar a documentos pendientes</h2>
            <p id="financeApplicationsInstructions">Primero ingresa el importe del movimiento. Luego, si los documentos ya existen, marca las ventas o compras que cancela.</p>
          </div>
          <button id="financeApplicationsRefresh" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Actualizar cartera</button>
        </div>

        <div class="fin-application-columns">
          <section id="financeCxcPanel" class="fin-debt-panel" aria-labelledby="financeCxcTitle">
            <div class="fin-debt-head">
              <div><span class="fin-debt-side fin-debt-side-cxc">CXC</span><h3 id="financeCxcTitle">Deudas del cliente</h3></div>
              <span id="financeCxcAvailable" class="fin-debt-total">Pendiente S/ 0.00</span>
            </div>
            <p id="financeCxcMessage" class="fin-message">Selecciona un cliente para consultar su cartera.</p>
            <div id="financeCxcList" class="fin-debt-list"></div>
          </section>

          <section id="financeCxpPanel" class="fin-debt-panel" aria-labelledby="financeCxpTitle" hidden>
            <div class="fin-debt-head">
              <div><span class="fin-debt-side fin-debt-side-cxp">CXP</span><h3 id="financeCxpTitle">Deudas con el proveedor</h3></div>
              <span id="financeCxpAvailable" class="fin-debt-total">Pendiente S/ 0.00</span>
            </div>
            <p id="financeCxpMessage" class="fin-message">Selecciona un proveedor para consultar su cartera.</p>
            <div id="financeCxpList" class="fin-debt-list"></div>
          </section>
        </div>
      </section>

    </form>

    <section id="financeDailyPanel" class="fin-card fin-daily-panel" aria-labelledby="financeDailyTitle" aria-busy="true">
        <div class="fin-section-head fin-section-head-wrap">
          <div>
            <p class="fin-eyebrow">Historial por fechas</p>
            <h2 id="financeDailyTitle">Transacciones por fecha</h2>
            <p id="financeDailyDescription">Elige un rango para consultar la operación seleccionada.</p>
          </div>
          <div class="fin-daily-actions">
            <span id="financeDailyLive" class="fin-live-status"><span aria-hidden="true"></span><span id="financeDailyLiveText">Actualización automática cada 10 s</span></span>
            <button id="financeDailyRefresh" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Actualizar ahora</button>
          </div>
        </div>

        <form id="financeDailyFilters" class="fin-daily-filters" novalidate>
          <label class="fin-field fin-field-compact">
            <span>Desde</span>
            <input id="financeDailyFrom" type="date" autocomplete="off" required>
          </label>
          <label class="fin-field fin-field-compact">
            <span>Hasta</span>
            <input id="financeDailyTo" type="date" autocomplete="off" required>
          </label>
          <div class="fin-daily-filter-actions">
            <button id="financeDailyApply" class="fin-btn fin-btn-primary fin-btn-small" type="submit">Aplicar filtro</button>
            <button id="financeDailyToday" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Hoy</button>
          </div>
        </form>

        <p id="financeDailyMessage" class="fin-message" role="status" aria-live="polite">Cargando el rango seleccionado...</p>
        <div class="fin-table-wrap">
          <table class="fin-table fin-daily-table">
            <thead>
              <tr>
                <th>Fecha / código</th>
                <th>Contraparte</th>
                <th>Cuenta / método</th>
                <th>Referencia / detalle</th>
                <th>Estado</th>
                <th class="fin-text-right">Importe</th>
              </tr>
            </thead>
            <tbody id="financeDailyRows">
              <tr><td class="fin-empty-cell" colspan="6">Cargando el rango seleccionado...</td></tr>
            </tbody>
          </table>
        </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/finanzas-movimiento.js') }}"></script>
</body>
</html>
