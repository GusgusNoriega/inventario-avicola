<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pantalla del cliente | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-pantalla-cliente.css') }}?v={{ filemtime(public_path('css/despacho-productos-pantalla-cliente.css')) }}">
</head>
<body class="pdcd-page" data-authenticated-user-id="{{ auth()->id() ?? '' }}">
  <main class="pdcd-shell">
    <header class="pdcd-header">
      <span class="pdcd-mark" aria-hidden="true">DP</span>
      <h1 id="productCustomerDisplayTitle">Despacho de productos</h1>
      <div class="pdcd-actions">
        <span id="productCustomerDisplayStatus" class="pdcd-status is-waiting" role="status" aria-live="polite">Esperando despacho</span>
        <button id="productCustomerDisplayChooseScreen" type="button">Monitor</button>
        <button id="productCustomerDisplayFullscreen" type="button">Pantalla completa</button>
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
          <div><span>Neto lista</span><strong id="productCustomerDisplayListNet">0.000 kg</strong></div>
          <div><span>Total</span><strong id="productCustomerDisplayListAmount">S/ 0.00</strong></div>
        </footer>
      </section>
    </section>

    <p id="productCustomerDisplayAnnouncement" class="pdcd-sr-only" aria-live="polite" aria-atomic="true"></p>
  </main>

  <dialog id="productCustomerDisplayScreenDialog" class="pdcd-screen-dialog" aria-labelledby="productCustomerDisplayScreenTitle">
    <div class="pdcd-screen-dialog-head">
      <div><small>Monitores</small><h2 id="productCustomerDisplayScreenTitle">Elige una pantalla</h2></div>
      <button id="productCustomerDisplayScreenClose" type="button" aria-label="Cerrar">&times;</button>
    </div>
    <div id="productCustomerDisplayScreenList" class="pdcd-screen-list" role="list"></div>
    <p id="productCustomerDisplayScreenFeedback" role="status" aria-live="polite"></p>
  </dialog>

  <script type="module" src="{{ asset('js/despacho-productos-pantalla-cliente.js') }}?v={{ filemtime(public_path('js/despacho-productos-pantalla-cliente.js')) }}"></script>
</body>
</html>
