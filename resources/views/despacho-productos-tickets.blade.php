<!doctype html>
<html lang="es" class="product-dispatch-tickets-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Tickets de despacho | Despacho de productos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-tickets.css') }}?v={{ filemtime(public_path('css/despacho-productos-tickets.css')) }}">
  @include('partials.product-dispatch-keyboards-styles')
</head>
<body class="product-dispatch-tickets-page">
  <main
    id="productDispatchTickets"
    class="pdt-shell"
    data-api-base="/despacho-productos"
  >
    <header class="pdt-header card">
      <div class="pdt-header-copy">
        <p class="eyebrow">Despacho de productos</p>
        <h1>Tickets de despacho</h1>
        <p>Consulta el detalle completo de cada venta, corrige sus datos y vuelve a imprimir el comprobante cuando lo necesites.</p>
      </div>
      <div class="pdt-header-actions">
        <a class="btn btn-primary pdt-new-dispatch" href="{{ route('despacho-productos.despacho') }}">
          <span aria-hidden="true">＋</span>
          Nuevo despacho
        </a>
        <a class="menu-return-btn pdt-back" href="{{ route('despacho-productos.menu') }}" aria-label="Volver a las opciones de Despacho de productos">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path>
          </svg>
          <span>Módulo</span>
        </a>
      </div>
    </header>

    <section class="pdt-summary" aria-label="Resumen de la consulta">
      <article class="pdt-summary-card card">
        <span>Tickets encontrados</span>
        <strong id="pdtSummaryTickets">—</strong>
        <small>En todos los resultados del filtro</small>
      </article>
      <article class="pdt-summary-card card is-amount">
        <span>Importe acumulado</span>
        <strong id="pdtSummaryAmount">—</strong>
        <small>Suma total de los tickets encontrados</small>
      </article>
    </section>

    <form id="pdtFilters" class="pdt-filters card" novalidate>
      <div class="pdt-filter-heading">
        <div>
          <p class="eyebrow">Búsqueda de tickets</p>
          <h2>Encuentra el comprobante</h2>
        </div>
        <p>Busca por código, cliente, documento o producto. Puedes combinar el texto con un rango de fechas.</p>
      </div>

      <div class="pdt-filter-grid">
        <label class="pdt-field pdt-search-field" for="pdtSearch">
          <span>Ticket, cliente o producto</span>
          <span class="pdt-search-input">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="10.5" cy="10.5" r="6.5"></circle>
              <path d="m15.5 15.5 5 5"></path>
            </svg>
            <input id="pdtSearch" name="search" type="search" maxlength="120" autocomplete="off" placeholder="Ej. PD-000123, nombre o producto" data-pdd-keyboard="text" readonly inputmode="none" virtualkeyboardpolicy="manual">
          </span>
        </label>

        <label class="pdt-field" for="pdtDateFrom">
          <span>Desde</span>
          <input id="pdtDateFrom" name="date_from" type="date" inputmode="none" virtualkeyboardpolicy="manual">
        </label>

        <label class="pdt-field" for="pdtDateTo">
          <span>Hasta</span>
          <input id="pdtDateTo" name="date_to" type="date" inputmode="none" virtualkeyboardpolicy="manual">
        </label>

        <label class="pdt-field pdt-per-page-field" for="pdtPerPage">
          <span>Por página</span>
          <select id="pdtPerPage" name="per_page">
            <option value="10">10 tickets</option>
            <option value="20">20 tickets</option>
            <option value="50">50 tickets</option>
          </select>
        </label>

        <div class="pdt-filter-actions">
          <button id="pdtFilterSubmit" class="btn btn-primary" type="submit">Buscar</button>
          <button id="pdtFilterReset" class="btn btn-ghost" type="button">Limpiar</button>
        </div>
      </div>
    </form>

    <p id="pdtMessage" class="pdt-message" role="status" aria-live="polite"></p>

    <section class="pdt-results" aria-labelledby="pdtResultsTitle">
      <div class="pdt-results-heading">
        <div>
          <p class="eyebrow">Información completa</p>
          <h2 id="pdtResultsTitle">Detalle de tickets</h2>
        </div>
        <span id="pdtRecordRange" class="pdt-record-range">Consultando…</span>
      </div>

      <div
        id="pdtTicketList"
        class="pdt-ticket-list"
        aria-busy="true"
      >
        <div class="pdt-state card">
          <span class="pdt-spinner" aria-hidden="true"></span>
          <strong>Cargando tickets…</strong>
          <span>Estamos preparando el detalle de las ventas.</span>
        </div>
      </div>

      <nav id="pdtPagination" class="pdt-pagination card" aria-label="Páginas de tickets" hidden>
        <button id="pdtPagePrevious" class="btn btn-ghost" type="button">← Anterior</button>
        <span id="pdtPageStatus">Página 1 de 1</span>
        <button id="pdtPageNext" class="btn btn-ghost" type="button">Siguiente →</button>
      </nav>
    </section>

    @include('partials.system-credit')
  </main>

  <dialog id="pdtEditorDialog" class="pdt-editor-dialog" aria-labelledby="pdtEditorTitle">
    <form id="pdtEditorForm" class="pdt-editor-form" novalidate>
      <header class="pdt-editor-header">
        <div>
          <p class="eyebrow">Corrección con trazabilidad</p>
          <h2 id="pdtEditorTitle">Editar ticket</h2>
          <p id="pdtEditorSubtitle">Cargando detalle…</p>
        </div>
        <button class="pdt-editor-close" type="button" data-pdt-close-editor aria-label="Cerrar editor">×</button>
      </header>

      <div class="pdt-editor-scroll">
        <div id="pdtEditorLoading" class="pdt-editor-loading">
          <span class="pdt-spinner" aria-hidden="true"></span>
          <strong>Cargando ticket y catálogo…</strong>
        </div>

        <div id="pdtEditorContent" class="pdt-editor-content" hidden>
          <section class="pdt-editor-section" aria-labelledby="pdtGeneralTitle">
            <div class="pdt-editor-section-heading">
              <span aria-hidden="true">1</span>
              <div>
                <h3 id="pdtGeneralTitle">Datos generales</h3>
                <p>Actualiza la identificación del comprobante, el cliente y la fecha real de registro.</p>
              </div>
            </div>

            <div class="pdt-editor-fields">
              <label class="pdt-field pdt-wide-field" for="pdtTicketTitle">
                <span>Título del ticket <b>*</b></span>
                <textarea id="pdtTicketTitle" name="ticket_title" rows="2" maxlength="180" required data-pdd-keyboard="text" readonly inputmode="none" virtualkeyboardpolicy="manual"></textarea>
              </label>

              <label class="pdt-field" for="pdtListNumber">
                <span>Número de lista <b>*</b></span>
                <select id="pdtListNumber" name="list_number" required>
                  <option value="1">Lista 1</option>
                  <option value="2">Lista 2</option>
                  <option value="3">Lista 3</option>
                  <option value="4">Lista 4</option>
                  <option value="5">Lista 5</option>
                  <option value="6">Lista 6</option>
                  <option value="7">Lista 7</option>
                  <option value="8">Lista 8</option>
                </select>
              </label>

              <label class="pdt-field" for="pdtClient">
                <span>Cliente</span>
                <select id="pdtClient" name="client_id">
                  <option value="">Venta al público</option>
                </select>
              </label>

              <label class="pdt-field" for="pdtRegisteredAt">
                <span>Fecha y hora <b>*</b></span>
                <input id="pdtRegisteredAt" name="registered_at" type="datetime-local" step="1" aria-describedby="pdtRegisteredAtHelp" required inputmode="none" virtualkeyboardpolicy="manual">
                <small id="pdtRegisteredAtHelp">La hora de cada pesada se desplazará junto con el ticket.</small>
              </label>

              <label class="pdt-field pdt-wide-field" for="pdtCorrectionReason">
                <span>Motivo de la corrección <small>(opcional)</small></span>
                <textarea
                  id="pdtCorrectionReason"
                  name="correction_reason"
                  rows="3"
                  minlength="3"
                  maxlength="250"
                  placeholder="Si lo deseas, deja una nota sobre este cambio"
                  data-pdd-keyboard="text"
                  readonly
                  inputmode="none"
                  virtualkeyboardpolicy="manual"
                ></textarea>
                <small>Si escribes un motivo, quedará asociado a la corrección para mantener la trazabilidad.</small>
              </label>
            </div>

            <div id="pdtTimeChangeWarning" class="pdt-time-change-warning" role="status" hidden>
              <strong>Este cambio modifica la hora de las pesadas.</strong>
              <span>Las pesadas vinculadas a balanza quedarán identificadas como manuales. La lectura física original se conserva sin alteraciones.</span>
              <label for="pdtAcknowledgeTimeChange">
                <input id="pdtAcknowledgeTimeChange" type="checkbox">
                <span>Entiendo el efecto de esta corrección sobre las horas y el origen de las pesadas.</span>
              </label>
            </div>
          </section>

          <section class="pdt-editor-section" aria-labelledby="pdtLinesTitle">
            <div class="pdt-editor-section-heading pdt-lines-heading">
              <span aria-hidden="true">2</span>
              <div>
                <h3 id="pdtLinesTitle">Pesadas del ticket</h3>
                <p>Puedes cambiar productos y valores, quitar pesadas o agregar registros nuevos.</p>
              </div>
              <button id="pdtAddLine" class="btn btn-secondary" type="button">＋ Agregar pesada</button>
            </div>

            <div id="pdtEditorLines" class="pdt-editor-lines"></div>
          </section>

          <section class="pdt-editor-total" aria-label="Totales recalculados">
            <div><span>Pesadas</span><strong id="pdtEditTotalWeighings">0</strong></div>
            <div><span>Cantidad</span><strong id="pdtEditTotalQuantity">0</strong></div>
            <div><span>Peso neto</span><strong id="pdtEditTotalNet">0.00 kg</strong></div>
            <div class="is-amount"><span>Nuevo total</span><strong id="pdtEditTotalAmount">—</strong></div>
          </section>
        </div>

        <p id="pdtEditorMessage" class="pdt-editor-message" role="status" aria-live="polite"></p>
      </div>

      <footer class="pdt-editor-actions">
        <button class="btn btn-ghost" type="button" data-pdt-close-editor>Cancelar</button>
        <button id="pdtSaveTicket" class="btn btn-primary" type="submit" disabled>Guardar corrección</button>
      </footer>
    </form>
  </dialog>

  @include('partials.product-dispatch-keyboards')
  <script type="module" src="{{ asset('js/despacho-productos-tickets.js') }}?v={{ filemtime(public_path('js/despacho-productos-tickets.js')) }}"></script>
</body>
</html>
