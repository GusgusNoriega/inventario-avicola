<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Caja de efectivo | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body
  class="fin-page fin-cash-page"
  data-can-manage-cash="{{ auth()->user()->hasPermission('PAGOS_REGISTRAR') && auth()->user()->hasPermission('SALDOS_AJUSTAR') ? '1' : '0' }}"
  data-can-reverse-cash="{{ auth()->user()->hasPermission('PAGOS_ANULAR') ? '1' : '0' }}"
>
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'caja-efectivo',
      'eyebrow' => 'Control de efectivo',
      'title' => 'Caja de efectivo',
      'description' => 'Registra ingresos y gastos en efectivo, revisa el movimiento del día y trabaja siempre con tu caja habitual.'
    ])

    <section class="fin-card fin-cash-config" aria-labelledby="cashRegisterConfigTitle">
      <div class="fin-cash-config-copy">
        <p class="fin-eyebrow">Configuración de este equipo</p>
        <h2 id="cashRegisterConfigTitle">Caja que estás manejando</h2>
        <p>La caja predeterminada se guarda solamente en este navegador y se valida cada vez que abres la pantalla.</p>
      </div>
      <div class="fin-cash-config-controls">
        <label class="fin-field">
          <span>Caja registradora <b>*</b></span>
          <select id="cashRegisterAccount" required>
            <option value="">Cargando cajas...</option>
          </select>
        </label>
        <button id="cashRegisterSaveDefault" class="fin-btn fin-btn-ghost" type="button">Guardar como predeterminada</button>
        <label class="fin-field fin-cash-date-field">
          <span>Día a consultar</span>
          <input id="cashRegisterDate" type="date">
        </label>
      </div>
      <div class="fin-cash-live" aria-live="polite">
        <span class="fin-cash-live-dot" aria-hidden="true"></span>
        <span id="cashRegisterLiveStatus">Preparando actualización automática…</span>
      </div>
      <p id="cashRegisterConfigMessage" class="fin-message" role="status" aria-live="polite"></p>
    </section>

    <section class="fin-summary-grid fin-cash-summary" aria-label="Resumen financiero del día">
      <article class="fin-summary-card fin-cash-summary-income">
        <span>Ingresos del día</span>
        <small>Dinero que entró a la caja</small>
        <strong id="cashRegisterIncome">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-cash-summary-accounts">
        <span>Ingresado a cuentas</span>
        <small>Bancos y billeteras; no incluye cajas</small>
        <strong id="cashRegisterAccountIncome">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-cash-summary-expense">
        <span>Gastos del día</span>
        <small>Dinero que salió de la caja</small>
        <strong id="cashRegisterExpense">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-cash-summary-net">
        <span>Total neto del día</span>
        <small>Ingresos menos gastos</small>
        <strong id="cashRegisterNet">S/ 0.00</strong>
      </article>
    </section>

    <section class="fin-card fin-cash-ledger" aria-labelledby="cashRegisterLedgerTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Movimientos en efectivo</p>
          <h2 id="cashRegisterLedgerTitle">Lista del día</h2>
          <p class="fin-section-copy">Los cambios aparecen automáticamente y las transferencias se reflejan en las dos cajas involucradas.</p>
        </div>
        <button id="cashRegisterRefresh" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Actualizar ahora</button>
      </div>

      <p id="cashRegisterListMessage" class="fin-message" role="status" aria-live="polite">Selecciona una caja para ver sus movimientos.</p>
      <ol id="cashRegisterList" class="fin-cash-list" aria-label="Movimientos de caja"></ol>

      <footer class="fin-cash-ledger-footer">
        <div>
          <strong>¿Necesitas registrar efectivo?</strong>
          <span>El formulario no solicita número de operación porque esta vista maneja únicamente dinero en efectivo.</span>
        </div>
        <button id="cashRegisterAdd" class="fin-btn fin-btn-primary" type="button" aria-haspopup="dialog" aria-controls="cashRegisterDialog">
          Nuevo ingreso o gasto
        </button>
      </footer>
    </section>
  </main>

  <dialog id="cashRegisterDialog" class="fin-purchase-dialog fin-cash-dialog" aria-labelledby="cashRegisterDialogTitle">
    <form id="cashRegisterForm" class="fin-purchase-dialog-card fin-cash-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div>
          <p id="cashRegisterDialogEyebrow" class="fin-eyebrow">Nuevo movimiento</p>
          <h2 id="cashRegisterDialogTitle">Registrar efectivo</h2>
        </div>
        <button class="fin-dialog-close" type="button" data-cash-dialog-close aria-label="Cerrar">×</button>
      </header>

      <input id="cashRegisterMovementId" type="hidden">
      <div class="fin-form-grid fin-cash-form-grid">
        <label class="fin-field">
          <span>Tipo de movimiento <b>*</b></span>
          <select id="cashRegisterDirection" required>
            <option value="INGRESO">Ingreso</option>
            <option value="EGRESO">Gasto</option>
          </select>
        </label>
        <label class="fin-field">
          <span>Fecha y hora <b>*</b></span>
          <input id="cashRegisterMovementDate" type="datetime-local" required>
        </label>
        <label class="fin-field">
          <span>Importe en efectivo <b>*</b></span>
          <div class="fin-money-input">
            <span id="cashRegisterCurrencyPrefix">S/</span>
            <input id="cashRegisterAmount" type="number" min="0.01" step="0.01" inputmode="decimal" required placeholder="0.00">
          </div>
        </label>
        <label class="fin-field">
          <span id="cashRegisterCounterpartLabel">¿De dónde viene el dinero? <b>*</b></span>
          <select id="cashRegisterCounterpartType" required>
            <option value="CLIENTE">De un cliente</option>
            <option value="OTRA_CAJA">De otra caja</option>
            <option value="OTRO">Otro origen</option>
          </select>
        </label>

        <div id="cashRegisterClientField" class="fin-field fin-grid-span-2 fin-cash-client-field">
          <label for="cashRegisterClientSearch">Buscar cliente <b>*</b></label>
          <div class="fin-cash-combobox">
            <input
              id="cashRegisterClientSearch"
              type="search"
              role="combobox"
              aria-autocomplete="list"
              aria-expanded="false"
              aria-controls="cashRegisterClientSuggestions"
              autocomplete="off"
              placeholder="Escribe nombre o documento"
            >
            <input id="cashRegisterClientId" type="hidden">
            <div id="cashRegisterClientSuggestions" class="fin-cash-suggestions" role="listbox" hidden></div>
          </div>
          <small id="cashRegisterSelectedClient">Selecciona el cliente que entregó el efectivo.</small>
        </div>

        <label id="cashRegisterOtherCashField" class="fin-field fin-grid-span-2" hidden>
          <span id="cashRegisterOtherCashLabel">Caja de origen <b>*</b></span>
          <select id="cashRegisterOtherCash"></select>
          <small>La transferencia también aparecerá en la lista de la otra caja.</small>
        </label>

        <label class="fin-field fin-grid-span-2">
          <span>Detalle del movimiento <b>*</b></span>
          <textarea id="cashRegisterDetail" rows="3" maxlength="500" required placeholder="Ej: Pago en efectivo del pedido, cambio de sencillo o retiro para compra menor"></textarea>
        </label>
      </div>

      <p class="fin-cash-audit-note">Si cambias importe, tipo, caja o contraparte, el sistema conserva el movimiento anterior mediante una reversa y registra la corrección.</p>
      <p id="cashRegisterFormMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-cash-dialog-close>Cancelar</button>
        <button id="cashRegisterSubmit" class="fin-btn fin-btn-primary" type="submit">Guardar movimiento</button>
      </footer>
    </form>
  </dialog>

  <script type="module" src="{{ asset('js/finanzas-caja-efectivo.js') }}?v={{ filemtime(public_path('js/finanzas-caja-efectivo.js')) }}"></script>
</body>
</html>
