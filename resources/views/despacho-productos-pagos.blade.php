<!doctype html>
<html lang="es" class="product-dispatch-payments-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Pagos de clientes | Despacho de productos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-pagos.css') }}?v={{ filemtime(public_path('css/despacho-productos-pagos.css')) }}">
</head>
<body class="product-dispatch-payments-page">
  <main id="productDispatchPayments" class="pdpy-shell" data-api-base="/despacho-productos/pagos">
    <header class="pdpy-header card">
      <div>
        <p class="eyebrow">Despacho de productos</p>
        <h1>Pagos de clientes</h1>
        <p>Registra el dinero que recibe la empresa de sus clientes y administra cada pago.</p>
        <p id="pdpyBranch" class="pdpy-branch"></p>
      </div>
      <a class="menu-return-btn pdpy-back" href="{{ route('despacho-productos.menu') }}" aria-label="Volver a las opciones de Despacho de productos">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path></svg>
        <span>Módulo</span>
      </a>
    </header>

    <section class="pdpy-workspace" aria-label="Registro y consulta de pagos de clientes">
      <article class="pdpy-form-panel card" aria-labelledby="pdpyFormTitle">
        <header class="pdpy-heading">
          <div><p class="eyebrow">Cliente → Empresa</p><h2 id="pdpyFormTitle">Nuevo pago</h2></div>
          <span id="pdpyEditBadge" class="pdpy-badge" hidden>Editando</span>
        </header>
        <p class="pdpy-intro">Selecciona el cliente, el importe y el método de pago. El número de transacción es opcional.</p>
        <form id="pdpyForm" class="pdpy-form">
          <fieldset id="pdpyFields" class="pdpy-fields" disabled>
            <div class="pdpy-field pdpy-full">
              <label for="pdpyClientSearch">Buscar cliente</label>
              <input id="pdpyClientSearch" type="search" maxlength="100" autocomplete="off" placeholder="Nombre o DNI / RUC" aria-describedby="pdpyClientHelp">
              <label for="pdpyClient">Cliente <b aria-hidden="true">*</b></label>
              <select id="pdpyClient" name="cliente_id" required><option value="">Selecciona un cliente</option></select>
              <small id="pdpyClientHelp" role="status" aria-live="polite">Cargando clientes…</small>
            </div>
            <label class="pdpy-field" for="pdpyAmount">
              <span>Importe <b aria-hidden="true">*</b></span>
              <input id="pdpyAmount" name="importe" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required>
            </label>
            <label class="pdpy-field" for="pdpyCurrency">
              <span>Moneda <b aria-hidden="true">*</b></span>
              <select id="pdpyCurrency" name="moneda" required><option value="PEN">Soles (PEN)</option></select>
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyMethod">
              <span>Método de pago <b aria-hidden="true">*</b></span>
              <select id="pdpyMethod" name="metodo_pago_id" required><option value="">Selecciona un método</option></select>
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyDateTime">
              <span>Fecha y hora <b aria-hidden="true">*</b></span>
              <input id="pdpyDateTime" name="fecha_hora" type="datetime-local" required>
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyReference">
              <span>Número de transacción <small>(opcional)</small></span>
              <input id="pdpyReference" name="referencia" type="text" maxlength="100" autocomplete="off" placeholder="Puedes dejarlo vacío">
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyAccount">
              <span>Cuenta de destino <small>(opcional)</small></span>
              <select id="pdpyAccount" name="cuenta_destino_id"><option value="">Sin asignar cuenta</option></select>
            </label>
            <label class="pdpy-field pdpy-full" for="pdpyNotes">
              <span>Observaciones <small>(opcional)</small></span>
              <textarea id="pdpyNotes" name="observaciones" rows="2" maxlength="2000" placeholder="Detalle adicional del pago"></textarea>
            </label>
          </fieldset>
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
          <div><p class="eyebrow">Pagos registrados en esta vista</p><h2 id="pdpyListTitle">Transacciones</h2></div>
          <span id="pdpyCount" class="pdpy-badge pdpy-count" aria-live="polite">— pagos</span>
        </header>
        <form id="pdpyFilters" class="pdpy-filters card">
          <label class="pdpy-field pdpy-search" for="pdpySearch"><span>Buscar transacciones</span><input id="pdpySearch" type="search" maxlength="120" placeholder="Cliente, documento o número de transacción" autocomplete="off"></label>
          <label class="pdpy-field" for="pdpyDateFrom"><span>Desde</span><input id="pdpyDateFrom" type="date"></label>
          <label class="pdpy-field" for="pdpyDateTo"><span>Hasta</span><input id="pdpyDateTo" type="date"></label>
          <div class="pdpy-filter-actions"><button class="btn btn-primary" type="submit">Buscar</button><button id="pdpyClearFilters" class="btn btn-ghost" type="button">Limpiar filtros</button></div>
        </form>
        <div class="pdpy-list-status"><p id="pdpyListMessage" class="pdpy-message" role="status" aria-live="polite"></p><button id="pdpyReload" class="btn btn-ghost" type="button">Actualizar</button></div>
        <div id="pdpyTableContainer" class="pdpy-table-container card" tabindex="0" role="region" aria-label="Tabla de pagos; desplázate horizontalmente para ver todas las columnas" aria-busy="true">
          <table class="pdpy-table">
            <caption class="pdpy-sr-only">Pagos de clientes a la empresa registrados en esta vista</caption>
            <thead><tr><th scope="col">Fecha / registro</th><th scope="col">Cliente</th><th scope="col" class="pdpy-money">Importe</th><th scope="col">Método / cuenta</th><th scope="col">N.º transacción</th><th scope="col">Observaciones</th><th scope="col">Acciones</th></tr></thead>
            <tbody id="pdpyRows"><tr><td colspan="7" class="pdpy-empty">Cargando pagos…</td></tr></tbody>
          </table>
        </div>
        <nav class="pdpy-pagination" aria-label="Páginas de pagos"><button id="pdpyPrevious" class="btn btn-ghost" type="button" disabled>Anterior</button><span id="pdpyPage" aria-live="polite">Página 1 de 1</span><button id="pdpyNext" class="btn btn-ghost" type="button" disabled>Siguiente</button></nav>
      </section>
    </section>
    @include('partials.system-credit')
  </main>
  <script type="module" src="{{ asset('js/despacho-productos-pagos.js') }}?v={{ filemtime(public_path('js/despacho-productos-pagos.js')) }}"></script>
</body>
</html>
