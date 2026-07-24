<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Gestionar movimientos financieros | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body
  class="fin-page fin-management-page"
  data-can-edit-movements="{{ auth()->user()->hasPermission('PAGOS_REGISTRAR') ? '1' : '0' }}"
  data-can-void-movements="{{ auth()->user()->hasPermission('PAGOS_ANULAR') ? '1' : '0' }}"
  data-can-adjust-balances="{{ auth()->user()->hasPermission('SALDOS_AJUSTAR') ? '1' : '0' }}"
>
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'gestion',
      'eyebrow' => 'Control y correcciones',
      'title' => 'Movimientos, saldos y deudas',
      'description' => 'Consulta lo registrado, corrige sus datos descriptivos o anula con trazabilidad completa.'
    ])

    <section class="fin-card fin-management-notice" aria-label="Política de correcciones">
      <div class="fin-management-notice-mark" aria-hidden="true">i</div>
      <div>
        <strong>Las anulaciones no borran información.</strong>
        <p>El sistema conserva el movimiento original y genera su reversa. Si necesitas cambiar importe, cuentas o distribución, anula el registro y crea uno nuevo.</p>
      </div>
    </section>

    <section class="fin-card fin-management-panel" aria-labelledby="financeManagementTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Historial operativo</p>
          <h2 id="financeManagementTitle">¿Qué deseas revisar?</h2>
        </div>
        <a class="fin-btn fin-btn-primary fin-btn-small" href="{{ route('finanzas.movimientos.nuevo') }}">Registrar nuevo</a>
      </div>

      <div class="fin-management-tabs" role="tablist" aria-label="Tipo de registro">
        <button id="financeMovementsTab" class="fin-management-tab is-active" type="button" role="tab" aria-selected="true" aria-controls="financeMovementsPanel">Transacciones y saldos a favor</button>
        <button id="financeDebtsTab" class="fin-management-tab" type="button" role="tab" aria-selected="false" aria-controls="financeDebtsPanel">Deudas anteriores de clientes</button>
      </div>

      <form id="financeManagementFilters" class="fin-filter-grid fin-management-filters" novalidate>
        <label class="fin-field fin-filter-search">
          <span>Buscar</span>
          <input id="financeManagementSearch" type="search" placeholder="Código, referencia, cliente o proveedor">
        </label>
        <label class="fin-field">
          <span>Estado</span>
          <select id="financeManagementStatus">
            <option value="">Todos</option>
            <option value="REGISTRADO">Vigentes</option>
            <option value="ANULADO">Anulados</option>
          </select>
        </label>
        <label id="financeManagementTypeField" class="fin-field">
          <span>Tipo</span>
          <select id="financeManagementType">
            <option value="">Todos</option>
            <option value="COBRO_CLIENTE">Cobro de cliente</option>
            <option value="PAGO_DIRECTO">Pago directo</option>
            <option value="PAGO_PROVEEDOR">Pago a proveedor</option>
            <option value="SALDO_FAVOR_PROVEEDOR">Saldo anterior con proveedor</option>
            <option value="COBRO_MINORISTA">Cobro minorista</option>
            <option value="REEMBOLSO_CLIENTE">Reembolso a cliente</option>
            <option value="DESCUENTO_CLIENTE">Descuento a cliente</option>
            <option value="GASTO_EMPRESA">Gasto de empresa</option>
          </select>
        </label>
        <label class="fin-field">
          <span>Desde</span>
          <input id="financeManagementFrom" type="date">
        </label>
        <label class="fin-field">
          <span>Hasta</span>
          <input id="financeManagementTo" type="date">
        </label>
        <div class="fin-filter-actions">
          <button class="fin-btn fin-btn-primary" type="submit">Aplicar filtros</button>
          <button id="financeManagementClear" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
        </div>
      </form>

      <div id="financeMovementsPanel" role="tabpanel" aria-labelledby="financeMovementsTab">
        <p id="financeMovementsMessage" class="fin-message" role="status" aria-live="polite">Cargando movimientos...</p>
        <div class="fin-table-wrap">
          <table class="fin-table fin-management-table">
            <thead><tr><th>Fecha / código</th><th>Tipo</th><th>Contraparte</th><th>Referencia</th><th>Estado</th><th class="fin-text-right">Importe</th><th>Acciones</th></tr></thead>
            <tbody id="financeMovementsRows"></tbody>
          </table>
        </div>
      </div>

      <div id="financeDebtsPanel" role="tabpanel" aria-labelledby="financeDebtsTab" hidden>
        <p id="financeDebtsMessage" class="fin-message" role="status" aria-live="polite">Cargando deudas anteriores...</p>
        <div class="fin-table-wrap">
          <table class="fin-table fin-management-table">
            <thead><tr><th>Fecha / código</th><th>Cliente</th><th>Detalle</th><th>Estado</th><th class="fin-text-right">Total</th><th class="fin-text-right">Pendiente</th><th>Acciones</th></tr></thead>
            <tbody id="financeDebtsRows"></tbody>
          </table>
        </div>
      </div>

      <div class="fin-pagination">
        <button id="financeManagementPrevious" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Anterior</button>
        <span id="financeManagementPage">Página 1</span>
        <button id="financeManagementNext" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Siguiente</button>
      </div>
    </section>
  </main>

  <dialog id="financeEditMovementDialog" class="fin-purchase-dialog" aria-labelledby="financeEditMovementTitle">
    <form id="financeEditMovementForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head"><div><p class="fin-eyebrow">Corrección descriptiva</p><h2 id="financeEditMovementTitle">Editar movimiento</h2></div><button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header>
      <p class="fin-section-copy">Puedes corregir fecha, referencia y observación. El importe, las cuentas y las aplicaciones requieren anular y registrar nuevamente.</p>
      <input id="financeEditMovementId" type="hidden">
      <div class="fin-form-grid">
        <label class="fin-field"><span>Fecha y hora <b>*</b></span><input id="financeEditMovementDate" type="datetime-local" required></label>
        <label class="fin-field"><span>Referencia</span><input id="financeEditMovementReference" type="text" maxlength="100"></label>
        <label class="fin-field fin-management-full-field"><span>Observaciones</span><textarea id="financeEditMovementNotes" rows="3" maxlength="2000"></textarea></label>
      </div>
      <p id="financeEditMovementMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions"><button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button><button class="fin-btn fin-btn-primary" type="submit">Guardar cambios</button></footer>
    </form>
  </dialog>

  <dialog id="financeEditDebtDialog" class="fin-purchase-dialog" aria-labelledby="financeEditDebtTitle">
    <form id="financeEditDebtForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head"><div><p class="fin-eyebrow">Saldo por cobrar</p><h2 id="financeEditDebtTitle">Editar deuda anterior</h2></div><button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header>
      <p class="fin-section-copy">Si ya tiene cobros, podrás corregir fecha, total y detalle. El nuevo total nunca puede ser menor que lo cobrado.</p>
      <input id="financeEditDebtId" type="hidden">
      <div class="fin-form-grid">
        <label class="fin-field"><span>Cliente <b>*</b></span><select id="financeEditDebtClient" required><option value="">Selecciona un cliente</option></select></label>
        <label class="fin-field"><span>Fecha <b>*</b></span><input id="financeEditDebtDate" type="date" required></label>
        <label class="fin-field"><span>Importe <b>*</b></span><input id="financeEditDebtAmount" type="number" min="0.01" step="0.01" required></label>
        <label class="fin-field"><span>Moneda</span><select id="financeEditDebtCurrency"><option value="PEN">Soles (PEN)</option><option value="USD">Dólares (USD)</option></select></label>
        <label class="fin-field fin-management-full-field"><span>Detalle <b>*</b></span><textarea id="financeEditDebtDetail" rows="3" maxlength="250" required></textarea></label>
      </div>
      <p id="financeEditDebtMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions"><button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button><button class="fin-btn fin-btn-primary" type="submit">Guardar deuda</button></footer>
    </form>
  </dialog>

  <dialog id="financeVoidDialog" class="fin-purchase-dialog fin-void-dialog" aria-labelledby="financeVoidTitle">
    <form id="financeVoidForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head"><div><p class="fin-eyebrow">Acción con trazabilidad</p><h2 id="financeVoidTitle">Anular registro</h2></div><button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button></header>
      <p id="financeVoidDescription" class="fin-section-copy"></p>
      <label class="fin-field"><span>Motivo de anulación <b>*</b></span><textarea id="financeVoidReason" rows="4" minlength="5" maxlength="250" required placeholder="Explica por qué se anula este registro"></textarea></label>
      <p id="financeVoidMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions"><button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button><button class="fin-btn fin-btn-danger" type="submit">Confirmar anulación</button></footer>
    </form>
  </dialog>

  <script type="module" src="{{ asset('js/finanzas-movimientos.js') }}"></script>
</body>
</html>
