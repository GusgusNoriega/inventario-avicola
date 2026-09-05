<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pantalla del cliente | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-pantalla-cliente.css') }}?v={{ filemtime(public_path('css/despacho-productos-pantalla-cliente.css')) }}">
  @include('partials.product-dispatch-keyboards-styles')
</head>
<body class="pdcd-page" data-authenticated-user-id="{{ auth()->id() ?? '' }}">
  <main class="pdcd-shell">
    <header class="pdcd-header">
      <span class="pdcd-mark" aria-hidden="true">DP</span>
      <h1 id="productCustomerDisplayTitle">Despacho de productos</h1>
      <div class="pdcd-actions">
        <span id="productCustomerDisplayStatus" class="pdcd-status is-waiting" role="status" aria-live="polite">Esperando despacho</span>
        <button
          id="productCustomerDisplayOpenTypography"
          class="pdcd-typography-trigger"
          type="button"
          aria-controls="productCustomerDisplayTypographyPanel"
          aria-expanded="false"
          aria-label="Configurar tipografía"
        ><span aria-hidden="true">Aa</span><b class="pdcd-action-text">Tipografía</b></button>
        <button id="productCustomerDisplayChooseScreen" class="pdcd-monitor-trigger" type="button" aria-label="Elegir monitor del cliente"><span id="productCustomerDisplayChooseScreenLabel" class="pdcd-action-text">Monitor</span></button>
        <button id="productCustomerDisplayFullscreen" class="pdcd-fullscreen-trigger" type="button" aria-label="Pantalla completa"><span id="productCustomerDisplayFullscreenLabel" class="pdcd-action-text">Pantalla completa</span></button>
      </div>
    </header>

    <section class="pdcd-content" aria-label="Información de la venta en vivo">
      <section class="pdcd-live" aria-labelledby="productCustomerDisplayLiveHeading">
        <div class="pdcd-live-heading">
          <span id="productCustomerDisplayLiveHeading">Peso neto</span>
          <small id="productCustomerDisplayLiveStatus">Sin cálculo</small>
        </div>
        <output class="pdcd-live-weight">
          <strong id="productCustomerDisplayLiveNet">---</strong>
          <span>kg</span>
        </output>
        <div class="pdcd-live-amount">
          <span>Importe</span>
          <strong id="productCustomerDisplayLiveAmount">S/ 0.00</strong>
        </div>
      </section>

      <section class="pdcd-list" aria-labelledby="productCustomerDisplayListHeading">
        <header class="pdcd-list-heading">
          <div>
            <span>Lista de venta</span>
            <h2 id="productCustomerDisplayListHeading">Lista 1</h2>
          </div>
          <strong id="productCustomerDisplayCustomer">Venta al público</strong>
        </header>

        <div class="pdcd-table-wrap">
          <table>
            <caption class="pdcd-sr-only">Productos de la lista activa</caption>
            <thead>
              <tr>
                <th scope="col">Producto</th>
                <th scope="col">Cant.</th>
                <th scope="col">P. neto</th>
                <th scope="col">Importe</th>
              </tr>
            </thead>
            <tbody id="productCustomerDisplayRows">
              <tr class="pdcd-empty-row"><td colspan="4">Lista vacía</td></tr>
            </tbody>
          </table>
        </div>

        <footer class="pdcd-list-total">
          <div><span>Total</span><strong id="productCustomerDisplayListAmount">S/ 0.00</strong></div>
        </footer>
      </section>
    </section>

    <p id="productCustomerDisplayAnnouncement" class="pdcd-sr-only" aria-live="polite" aria-atomic="true"></p>
  </main>

  <aside
    id="productCustomerDisplayTypographyPanel"
    class="pdcd-typography-panel"
    role="dialog"
    aria-labelledby="productCustomerDisplayTypographyTitle"
    aria-hidden="true"
    aria-modal="false"
    hidden
  >
    <header class="pdcd-typography-head">
      <div>
        <small>Vista del cliente</small>
        <h2 id="productCustomerDisplayTypographyTitle">Tipografía</h2>
        <span id="productCustomerDisplayTypographyProfile">Original</span>
      </div>
      <button type="button" data-pdcd-font-close aria-label="Cerrar configuración">&times;</button>
    </header>

    <div class="pdcd-typography-body">
      <section class="pdcd-typography-presets" aria-labelledby="productCustomerDisplayTypographyPresetTitle">
        <span id="productCustomerDisplayTypographyPresetTitle">Tamaño general</span>
        <div role="group" aria-label="Perfiles de tamaño">
          <button type="button" data-pdcd-font-preset="compact" aria-pressed="false">Pequeña</button>
          <button type="button" data-pdcd-font-preset="standard" aria-pressed="true">Original</button>
          <button type="button" data-pdcd-font-preset="large" aria-pressed="false">Grande</button>
          <button type="button" data-pdcd-font-preset="accessible" aria-pressed="false">Máxima</button>
        </div>
      </section>

      <label class="pdcd-typography-search" for="productCustomerDisplayTypographySearch">
        <span>Buscar texto</span>
        <input id="productCustomerDisplayTypographySearch" type="search" placeholder="Peso, título, tabla…" autocomplete="off" data-pdd-keyboard="text" readonly inputmode="none" virtualkeyboardpolicy="manual">
      </label>

      <div class="pdcd-typography-preview" aria-hidden="true">
        <span id="productCustomerDisplayTypographyPreviewLabel">Título empresa</span>
        <strong id="productCustomerDisplayTypographyPreviewValue">LA CENTRAL DE LOS POLLOS</strong>
        <small id="productCustomerDisplayTypographyPreviewSize">64 px</small>
      </div>

      <div class="pdcd-typography-toolbar">
        <span id="productCustomerDisplayTypographySummary">0 tamaños ajustados</span>
        <div>
          <button type="button" data-pdcd-font-expand="all">Abrir</button>
          <button type="button" data-pdcd-font-expand="none">Cerrar</button>
        </div>
      </div>

      <div id="productCustomerDisplayTypographyControls" class="pdcd-typography-controls"></div>
    </div>

    <footer class="pdcd-typography-footer">
      <button type="button" data-pdcd-font-reset-all>Restablecer</button>
      <span id="productCustomerDisplayTypographySaveStatus" role="status" aria-live="polite">Guardado en este equipo</span>
      <button class="is-primary" type="button" data-pdcd-font-close>Listo</button>
    </footer>
  </aside>

  <dialog id="productCustomerDisplayScreenDialog" class="pdcd-screen-dialog" aria-labelledby="productCustomerDisplayScreenTitle">
    <div class="pdcd-screen-dialog-head">
      <div><small>Monitores</small><h2 id="productCustomerDisplayScreenTitle">Elige una pantalla</h2></div>
      <button id="productCustomerDisplayScreenClose" type="button" aria-label="Cerrar">&times;</button>
    </div>
    <div id="productCustomerDisplayScreenList" class="pdcd-screen-list" role="list"></div>
    <p id="productCustomerDisplayScreenFeedback" role="status" aria-live="polite"></p>
  </dialog>

  @include('partials.product-dispatch-keyboards')
  <script type="module" src="{{ asset('js/despacho-productos-pantalla-cliente.js') }}?v={{ filemtime(public_path('js/despacho-productos-pantalla-cliente.js')) }}"></script>
</body>
</html>
