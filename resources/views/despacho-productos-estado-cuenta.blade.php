<!doctype html>
<html lang="es" class="product-dispatch-account-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Estado de cuenta | Despacho de productos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-estado-cuenta.css') }}?v={{ filemtime(public_path('css/despacho-productos-estado-cuenta.css')) }}">
</head>
<body class="product-dispatch-account-page">
  <main
    id="productDispatchAccountStatement"
    class="pdas-shell"
    data-api-base="/despacho-productos/estado-cuenta"
    data-pdf-url="{{ route('despacho-productos.estado-cuenta.pdf') }}"
  >
    <header class="pdas-header card">
      <div class="pdas-header-copy">
        <p class="eyebrow">Despacho de productos</p>
        <h1>Estado de cuenta del cliente</h1>
        <p>Consulta las ventas de esta sucursal, la deuda anterior registrada en Finanzas y los abonos aplicados, y prepara un PDF para el cliente.</p>
      </div>
      <a class="menu-return-btn pdas-back" href="{{ route('despacho-productos.menu') }}" aria-label="Volver a las opciones de Despacho de productos">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path>
        </svg>
        <span>Módulo</span>
      </a>
    </header>

    <section class="pdas-query card" aria-labelledby="pdasQueryTitle">
      <div class="pdas-query-heading">
        <div>
          <p class="eyebrow">Consulta de movimientos</p>
          <h2 id="pdasQueryTitle">Elige el cliente y el periodo</h2>
        </div>
        <p id="pdasBranchLabel">Preparando la sucursal…</p>
      </div>

      <form id="pdasFilters" class="pdas-filter-grid" novalidate>
        <div class="pdas-client-control">
          <span class="pdas-control-label">Cliente <b>*</b></span>
          <button
            id="pdasChooseClient"
            class="pdas-client-button"
            type="button"
            aria-haspopup="dialog"
            aria-controls="pdasClientDialog"
            disabled
          >
            <span class="pdas-client-button-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false">
                <circle cx="9" cy="8" r="3.5"></circle>
                <path d="M3.5 19c.6-3.5 2.4-5.2 5.5-5.2s4.9 1.7 5.5 5.2M17 8v6M14 11h6"></path>
              </svg>
            </span>
            <span>
              <strong id="pdasClientButtonTitle">Elegir cliente</strong>
              <small id="pdasClientButtonDetail">Busca por nombre o documento</small>
            </span>
            <span class="pdas-client-button-action" aria-hidden="true">Cambiar</span>
          </button>
        </div>

        <label class="pdas-field" for="pdasDateFrom">
          <span>Desde <b>*</b></span>
          <input id="pdasDateFrom" name="date_from" type="date" required>
        </label>

        <label class="pdas-field" for="pdasDateTo">
          <span>Hasta <b>*</b></span>
          <input id="pdasDateTo" name="date_to" type="date" required>
        </label>

        <label class="pdas-field" for="pdasCurrency">
          <span>Moneda <b>*</b></span>
          <select id="pdasCurrency" name="currency" required disabled>
            <option value="">Cargando…</option>
          </select>
        </label>

        <button id="pdasConsult" class="btn btn-primary pdas-consult" type="submit" disabled>
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="10.5" cy="10.5" r="6.5"></circle>
            <path d="m15.5 15.5 5 5"></path>
          </svg>
          <span>Consultar</span>
        </button>
      </form>

      <div id="pdasSelectedClient" class="pdas-selected-client" hidden>
        <span aria-hidden="true">✓</span>
        <div>
          <small>Cliente seleccionado</small>
          <strong id="pdasSelectedClientName">—</strong>
        </div>
        <em id="pdasSelectedClientDocument">—</em>
      </div>
    </section>

    <p id="pdasMessage" class="pdas-message" role="status" aria-live="polite"></p>

    <section id="pdasReport" class="pdas-report" aria-labelledby="pdasReportTitle" aria-busy="false">
      <header class="pdas-report-heading">
        <div>
          <p class="eyebrow">Reporte del módulo</p>
          <h2 id="pdasReportTitle">Estado de cuenta</h2>
          <p id="pdasReportPeriod">Selecciona un cliente para consultar sus movimientos.</p>
        </div>
        <div class="pdas-report-actions" aria-label="Acciones del reporte">
          <button id="pdasPreviewPdf" class="btn btn-secondary" type="button" disabled>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M4 5h16v14H4z"></path>
              <path d="M8 9h8M8 13h5"></path>
            </svg>
            Vista previa PDF
          </button>
          <a id="pdasDownloadPdf" class="btn btn-primary is-disabled" aria-disabled="true">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M12 3v12M7 10l5 5 5-5M4 20h16"></path>
            </svg>
            Descargar PDF
          </a>
        </div>
      </header>

      <div class="pdas-summary" aria-label="Resumen del estado de cuenta">
        <article class="pdas-summary-card card is-opening">
          <span>Saldo anterior</span>
          <strong id="pdasOpeningBalance">—</strong>
          <small>Ventas y deudas previas al periodo</small>
        </article>
        <article class="pdas-summary-card card is-sales">
          <span>Ventas del periodo</span>
          <strong id="pdasSalesTotal">—</strong>
          <small id="pdasTicketCount">0 tickets</small>
        </article>
        <article class="pdas-summary-card card is-prior-debt">
          <span>Deuda anterior del periodo</span>
          <strong id="pdasPriorDebtTotal">—</strong>
          <small id="pdasPriorDebtCount">0 deudas anteriores</small>
        </article>
        <article class="pdas-summary-card card is-payments">
          <span>Abonos del periodo</span>
          <strong id="pdasPaymentsTotal">—</strong>
          <small id="pdasPaymentCount">0 abonos</small>
        </article>
        <article class="pdas-summary-card card is-balance">
          <span id="pdasEndingBalanceLabel">Deuda final</span>
          <strong id="pdasEndingBalance">—</strong>
          <small>Saldo al cierre del periodo</small>
        </article>
      </div>

      <article class="pdas-ledger card">
        <div class="pdas-ledger-heading">
          <div>
            <h3>Movimientos cronológicos</h3>
            <p>Incluye ventas de esta sucursal y deudas anteriores registradas en Finanzas. No incorpora ventas de otros módulos.</p>
          </div>
          <span id="pdasRowCount">Sin consulta</span>
        </div>

        <div class="pdas-table-wrap" tabindex="0" role="region" aria-label="Tabla de movimientos del estado de cuenta">
          <table class="pdas-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>N.º documento</th>
                <th>Tipo / producto</th>
                <th class="is-number">Cantidad</th>
                <th class="is-number">Kilos</th>
                <th>Detalle</th>
                <th class="is-number">Precio</th>
                <th class="is-number">Cargo</th>
                <th class="is-number">Abono</th>
                <th class="is-number">Saldo</th>
              </tr>
            </thead>
            <tbody id="pdasRows">
              <tr class="pdas-empty-row">
                <td colspan="10">
                  <strong>Aún no hay una consulta</strong>
                  <span>Elige un cliente y un periodo para ver el estado de cuenta.</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </section>

    @include('partials.system-credit')
  </main>

  <dialog id="pdasClientDialog" class="pdas-dialog pdas-client-dialog" aria-labelledby="pdasClientDialogTitle">
    <div class="pdas-dialog-card">
      <header class="pdas-dialog-header">
        <div>
          <p class="eyebrow">Directorio de clientes</p>
          <h2 id="pdasClientDialogTitle">Elegir cliente</h2>
          <p>Busca por nombre o número de documento.</p>
        </div>
        <button class="pdas-dialog-close" type="button" data-pdas-close-client aria-label="Cerrar selector de clientes">×</button>
      </header>

      <label class="pdas-client-search" for="pdasClientSearch">
        <span class="sr-only">Buscar cliente por nombre o documento</span>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <circle cx="10.5" cy="10.5" r="6.5"></circle>
          <path d="m15.5 15.5 5 5"></path>
        </svg>
        <input id="pdasClientSearch" type="search" maxlength="120" autocomplete="off" placeholder="Nombre o documento">
      </label>

      <div id="pdasClientList" class="pdas-client-list" role="group" aria-label="Clientes disponibles"></div>
    </div>
  </dialog>

  <dialog id="pdasPdfDialog" class="pdas-dialog pdas-pdf-dialog" aria-labelledby="pdasPdfDialogTitle">
    <div class="pdas-dialog-card pdas-pdf-dialog-card">
      <header class="pdas-dialog-header pdas-pdf-header">
        <div>
          <p class="eyebrow">Documento para el cliente</p>
          <h2 id="pdasPdfDialogTitle">Vista previa del PDF</h2>
          <p id="pdasPdfSubtitle">Estado de cuenta</p>
        </div>
        <button class="pdas-dialog-close" type="button" data-pdas-close-pdf aria-label="Cerrar vista previa del PDF">×</button>
      </header>

      <div class="pdas-pdf-frame-wrap">
        <div id="pdasPdfLoading" class="pdas-pdf-loading" role="status">
          <span class="pdas-spinner" aria-hidden="true"></span>
          <strong>Preparando la vista previa…</strong>
        </div>
        <iframe id="pdasPdfFrame" title="Vista previa PDF del estado de cuenta" src="about:blank"></iframe>
      </div>

      <footer class="pdas-pdf-actions">
        <a id="pdasOpenPdfTab" class="btn btn-secondary" target="_blank" rel="noopener">Abrir en otra pestaña</a>
        <button class="btn btn-ghost" type="button" data-pdas-close-pdf>Cerrar</button>
      </footer>
    </div>
  </dialog>

  <script type="module" src="{{ asset('js/despacho-productos-estado-cuenta.js') }}?v={{ filemtime(public_path('js/despacho-productos-estado-cuenta.js')) }}"></script>
</body>
</html>
