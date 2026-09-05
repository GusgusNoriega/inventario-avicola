<!doctype html>
<html lang="es" class="product-dispatch-payments-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Pagos de clientes | Despacho de productos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-pagos.css') }}?v={{ filemtime(public_path('css/despacho-productos-pagos.css')) }}">
  @include('partials.product-dispatch-keyboards-styles')
</head>
<body class="product-dispatch-payments-page">
  <main id="productDispatchPayments" class="pdpy-shell" data-api-base="/despacho-productos/pagos">
    <header class="pdpy-header card">
      <div>
        <p class="eyebrow">Despacho de productos</p>
        <h1>Pagos de clientes</h1>
        <p>Consulta el saldo del cliente y administra sus pagos, deudas anteriores y saldos a favor.</p>
        <p id="pdpyBranch" class="pdpy-branch"></p>
      </div>
      <a class="menu-return-btn pdpy-back" href="{{ route('despacho-productos.menu') }}" aria-label="Volver a las opciones de Despacho de productos">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path></svg>
        <span>Módulo</span>
      </a>
    </header>

    <section class="pdpy-client-panel card" aria-labelledby="pdpyClientTitle">
      <div class="pdpy-client-control">
        <div><p class="eyebrow">Cuenta del cliente</p><h2 id="pdpyClientTitle">Selecciona un cliente</h2></div>
        <button id="pdpyChooseClient" class="pdpy-client-button" type="button" aria-haspopup="dialog" aria-controls="pdpyClientDialog" aria-describedby="pdpyClientHelp" disabled>
          <span class="pdpy-client-button-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><circle cx="9" cy="8" r="3.5"></circle><path d="M3.5 19c.6-3.5 2.4-5.2 5.5-5.2s4.9 1.7 5.5 5.2M17 8v6M14 11h6"></path></svg>
          </span>
          <span><strong id="pdpyClientButtonTitle">Elegir cliente</strong><small id="pdpyClientButtonDetail">Busca por nombre o documento</small></span>
          <span class="pdpy-client-button-action" aria-hidden="true">Cambiar</span>
        </button>
        <input id="pdpyClient" name="cliente_id" type="hidden" form="pdpyForm">
        <p id="pdpyClientHelp" class="pdpy-message" role="status" aria-live="polite">Cargando clientes…</p>
      </div>
      <div class="pdpy-summary" aria-label="Saldo actual del cliente" aria-live="polite" aria-atomic="true">
        <article id="pdpyBalancePanel" class="pdpy-summary-card pdpy-balance-card">
          <span id="pdpyBalanceLabel">Saldo actual</span>
          <strong id="pdpyBalanceAmount">—</strong>
          <small id="pdpyBalanceHelp">Selecciona un cliente para ver su deuda o saldo a favor.</small>
        </article>
        <article class="pdpy-summary-card is-charges"><span>Cargos acumulados</span><strong id="pdpyTotalCharges">—</strong><small>Ventas y deudas anteriores</small></article>
        <article class="pdpy-summary-card is-credits"><span>Abonos acumulados</span><strong id="pdpyTotalCredits">—</strong><small>Pagos y saldos a favor</small></article>
      </div>
    </section>

    <section class="pdpy-workspace" aria-label="Registro y consulta de movimientos del cliente">
      <article class="pdpy-form-panel card" aria-labelledby="pdpyFormTitle">
        <header class="pdpy-heading">
          <div><p class="eyebrow">Registro de movimientos</p><h2 id="pdpyFormTitle">Nuevo pago</h2></div>
          <span id="pdpyEditBadge" class="pdpy-badge" hidden>Editando</span>
        </header>
        <p class="pdpy-intro">Registra un pago, una deuda anterior o un saldo a favor para el cliente seleccionado.</p>
        <form id="pdpyForm" class="pdpy-form">
          <fieldset id="pdpyFields" class="pdpy-fields" disabled>
            <label class="pdpy-field pdpy-full" for="pdpyMovementType">
              <span>Tipo de movimiento <b aria-hidden="true">*</b></span>
              <select id="pdpyMovementType" name="tipo_movimiento" required aria-describedby="pdpyMovementHelp">
                <option value="PAYMENT">Pago recibido</option>
                <option value="PRIOR_DEBT">Deuda anterior</option>
                <option value="CREDIT">Saldo a favor</option>
              </select>
              <small id="pdpyMovementHelp">El pago reduce la deuda. Si supera lo pendiente, el excedente queda a favor del cliente.</small>
            </label>
            <label class="pdpy-field" for="pdpyAmount">
              <span>Importe <b aria-hidden="true">*</b></span>
              <input id="pdpyAmount" name="importe" type="number" min="0.01" step="0.01" inputmode="none" placeholder="0.00" required data-pdd-keyboard="numeric" readonly virtualkeyboardpolicy="manual">
            </label>
            <label class="pdpy-field" for="pdpyCurrency">
              <span>Moneda <b aria-hidden="true">*</b></span>
              <select id="pdpyCurrency" name="moneda" required><option value="PEN">Soles (PEN)</option></select>
            </label>
            <label id="pdpyMethodField" class="pdpy-field pdpy-full" for="pdpyMethod">
              <span>Método de pago <b aria-hidden="true">*</b></span>
              <select id="pdpyMethod" name="metodo_pago_id" required><option value="">Selecciona un método</option></select>
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyDateTime">
              <span>Fecha y hora <b aria-hidden="true">*</b></span>
              <input id="pdpyDateTime" name="fecha_hora" type="datetime-local" required inputmode="none" virtualkeyboardpolicy="manual">
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyReference">
              <span>Número de transacción <small>(opcional)</small></span>
              <input id="pdpyReference" name="referencia" type="text" maxlength="100" autocomplete="off" placeholder="Puedes dejarlo vacío" data-pdd-keyboard="text" readonly inputmode="none" virtualkeyboardpolicy="manual">
            </label>
            <label id="pdpyAccountField" class="pdpy-field pdpy-full" for="pdpyAccount">
              <span>Cuenta de destino <small>(opcional)</small></span>
              <select id="pdpyAccount" name="cuenta_destino_id"><option value="">Sin asignar cuenta</option></select>
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyNotes">
              <span>Observaciones <small>(opcional)</small></span>
              <textarea id="pdpyNotes" name="observaciones" rows="2" maxlength="2000" placeholder="Motivo o detalle del movimiento" data-pdd-keyboard="text" readonly inputmode="none" virtualkeyboardpolicy="manual"></textarea>
            </label>
          </fieldset>
          <p id="pdpyBalancePreview" class="pdpy-balance-preview" role="status" aria-live="polite" hidden></p>
          <div class="pdpy-form-actions">
            <button id="pdpySave" class="btn btn-primary" type="submit" disabled>Guardar pago</button>
            <button id="pdpyReset" class="btn btn-ghost" type="button" disabled>Limpiar</button>
          </div>
          <p id="pdpyFormMessage" class="pdpy-message" role="status" aria-live="polite">Cargando métodos de pago…</p>
          <button id="pdpyRetryCatalog" class="btn btn-ghost" type="button" hidden>Reintentar carga</button>
        </form>
      </article>

      <section class="pdpy-list-panel" aria-labelledby="pdpyListTitle">
        <header class="pdpy-heading">
          <div><p class="eyebrow">Historial del cliente</p><h2 id="pdpyListTitle">Todas las transacciones</h2><p id="pdpyListSubtitle" class="pdpy-list-subtitle">Selecciona un cliente para consultar sus ventas, deudas y abonos.</p></div>
          <span id="pdpyCount" class="pdpy-badge pdpy-count" aria-live="polite">— movimientos</span>
        </header>
        <form id="pdpyFilters" class="pdpy-filters card">
          <label class="pdpy-field pdpy-search" for="pdpySearch"><span>Buscar transacciones</span><input id="pdpySearch" type="search" maxlength="120" placeholder="Documento, detalle o número de transacción" autocomplete="off" data-pdd-keyboard="text" readonly inputmode="none" virtualkeyboardpolicy="manual"></label>
          <label class="pdpy-field" for="pdpyDateFrom"><span>Desde</span><input id="pdpyDateFrom" type="date" inputmode="none" virtualkeyboardpolicy="manual"></label>
          <label class="pdpy-field" for="pdpyDateTo"><span>Hasta</span><input id="pdpyDateTo" type="date" inputmode="none" virtualkeyboardpolicy="manual"></label>
          <div class="pdpy-filter-actions"><button class="btn btn-primary" type="submit">Buscar</button><button id="pdpyClearFilters" class="btn btn-ghost" type="button">Limpiar filtros</button></div>
        </form>
        <div class="pdpy-list-status"><p id="pdpyListMessage" class="pdpy-message" role="status" aria-live="polite"></p><button id="pdpyReload" class="btn btn-ghost" type="button">Actualizar</button></div>
        <div id="pdpyTableContainer" class="pdpy-table-container card" tabindex="0" role="region" aria-label="Transacciones del cliente; desplázate horizontalmente para ver todas las columnas" aria-busy="false">
          <table class="pdpy-table">
            <caption class="pdpy-sr-only">Movimientos del cliente en la moneda seleccionada</caption>
            <thead><tr><th scope="col">Fecha / tipo</th><th scope="col">Detalle / documento</th><th scope="col" class="pdpy-money">Cargo</th><th scope="col" class="pdpy-money">Abono</th><th scope="col" class="pdpy-money">Saldo</th><th scope="col">Acciones</th></tr></thead>
            <tbody id="pdpyRows"><tr><td colspan="6" class="pdpy-empty"><strong>Selecciona un cliente</strong><small>Aquí aparecerán todas sus transacciones.</small></td></tr></tbody>
          </table>
        </div>
        <nav class="pdpy-pagination" aria-label="Páginas de transacciones"><button id="pdpyPrevious" class="btn btn-ghost" type="button" disabled>Anterior</button><span id="pdpyPage" aria-live="polite">Página 1 de 1</span><button id="pdpyNext" class="btn btn-ghost" type="button" disabled>Siguiente</button></nav>
      </section>
    </section>
    @include('partials.system-credit')
  </main>
  <dialog id="pdpyClientDialog" class="pdpy-dialog pdpy-client-dialog" aria-labelledby="pdpyClientDialogTitle">
    <div class="pdpy-dialog-card">
      <header class="pdpy-dialog-header">
        <div><p class="eyebrow">Directorio de clientes</p><h2 id="pdpyClientDialogTitle">Elegir cliente</h2><p>Busca por nombre o número de documento.</p></div>
        <button id="pdpyCloseClientDialog" class="pdpy-dialog-close" type="button" aria-label="Cerrar selector de clientes">×</button>
      </header>
      <label class="pdpy-client-search" for="pdpyClientSearch">
        <span class="pdpy-sr-only">Buscar cliente por nombre o documento</span>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m15.5 15.5 5 5"></path></svg>
        <input id="pdpyClientSearch" type="search" maxlength="120" autocomplete="off" placeholder="Nombre o documento" aria-describedby="pdpyClientCount" data-pdd-keyboard="text" readonly inputmode="none" virtualkeyboardpolicy="manual">
      </label>
      <p id="pdpyClientCount" class="pdpy-client-count" role="status" aria-live="polite">Cargando clientes…</p>
      <div id="pdpyClientList" class="pdpy-client-list" role="group" aria-label="Clientes disponibles"></div>
    </div>
  </dialog>
  @include('partials.product-dispatch-keyboards')
  <script type="module" src="{{ asset('js/despacho-productos-pagos.js') }}?v={{ filemtime(public_path('js/despacho-productos-pagos.js')) }}"></script>
</body>
</html>
