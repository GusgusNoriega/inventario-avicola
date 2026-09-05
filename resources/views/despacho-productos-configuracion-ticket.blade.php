<!doctype html>
<html lang="es" class="product-ticket-config-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Configurar ticket | Despacho de productos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-configuracion-ticket.css') }}?v={{ filemtime(public_path('css/despacho-productos-configuracion-ticket.css')) }}">
  @include('partials.product-dispatch-keyboards-styles')
</head>
<body class="product-ticket-config-page">
  <main id="productTicketConfiguration" class="ptc-shell" data-api-base="/despacho-productos">
    <header class="ptc-header">
      <div class="ptc-brand">
        <span aria-hidden="true">DP</span>
        <div>
          <p>Despacho de productos</p>
          <h1>Configurar ticket</h1>
        </div>
      </div>
      <a class="ptc-back" href="{{ route('despacho-productos.menu') }}">
        <span aria-hidden="true">←</span> Módulo
      </a>
    </header>

    <section class="ptc-layout" aria-label="Configuración y vista previa del ticket">
      <form id="productTicketTitleForm" class="ptc-editor" novalidate>
        <div>
          <p class="ptc-kicker">Solo para esta vista</p>
          <h2>Encabezado</h2>
          <p class="ptc-help">Puedes usar varias líneas. Los demás modelos de ticket no cambiarán.</p>
        </div>

        <label class="ptc-field" for="productTicketTitle">
          <span>Título del ticket</span>
          <textarea
            id="productTicketTitle"
            name="product_ticket_title"
            maxlength="180"
            rows="5"
            required
            spellcheck="true"
            placeholder="Nombre de la empresa"
            data-pdd-keyboard="text"
            readonly
            inputmode="none"
            virtualkeyboardpolicy="manual"
          ></textarea>
          <small><span id="productTicketTitleCount">0</span>/180</small>
        </label>

        <p id="productTicketTitleStatus" class="ptc-status" role="status" aria-live="polite">Cargando…</p>

        <button id="productTicketTitleSave" class="ptc-save" type="submit" disabled>
          Guardar título
        </button>
      </form>

      <aside class="ptc-preview-panel" aria-labelledby="productTicketPreviewHeading">
        <div class="ptc-preview-heading">
          <div>
            <p class="ptc-kicker">Impresora de 80 mm</p>
            <h2 id="productTicketPreviewHeading">Vista previa</h2>
          </div>
          <span>Ticket de productos</span>
        </div>

        <div class="ptc-receipt-wrap">
          <article class="ptc-receipt" aria-label="Ejemplo del ticket impreso">
            <h3 id="productTicketTitlePreview">DESPACHO DE PRODUCTOS</h3>
            <strong class="ptc-receipt-subtitle">CONTROL DE DESPACHO</strong>
            <div class="ptc-receipt-control">
              <b>CONTROL DE PESO</b><b>PD-000001</b>
              <span>FECHA 01/09/2026</span><span>19:35</span>
            </div>
            <p class="ptc-receipt-sale">VENTA AL PÚBLICO - 1</p>
            <table>
              <thead><tr><th>TIPO</th><th>C/A</th><th>P NETO</th></tr></thead>
              <tbody>
                <tr><td>GALLO</td><td>1</td><td>0.65</td></tr>
                <tr><td>HUEVO</td><td>6</td><td>1</td></tr>
                <tr><td>GALLO</td><td>1</td><td>0.95</td></tr>
              </tbody>
            </table>
            <table class="ptc-receipt-detail">
              <thead><tr><th>PRODUCTO</th><th>C/A</th><th>P NETO</th><th>PRECIO</th><th>TOTAL</th></tr></thead>
              <tbody>
                <tr><td>GALLO</td><td>2</td><td>1.6</td><td>8</td><td>12.80</td></tr>
                <tr><td>HUEVO</td><td>6</td><td>1</td><td>0.8</td><td>4.80</td></tr>
              </tbody>
            </table>
            <p class="ptc-receipt-total"><span>TOT S/</span><b>17.60</b></p>
            <p class="ptc-receipt-observ">OBSERV:</p>
          </article>
        </div>
      </aside>
    </section>
  </main>

  @include('partials.product-dispatch-keyboards')
  <script type="module" src="{{ asset('js/despacho-productos-configuracion-ticket.js') }}?v={{ filemtime(public_path('js/despacho-productos-configuracion-ticket.js')) }}"></script>
</body>
</html>
