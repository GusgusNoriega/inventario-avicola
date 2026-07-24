<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Despacho en vivo | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/pantalla-cliente.css') }}?v={{ filemtime(public_path('css/pantalla-cliente.css')) }}">
</head>
<body class="customer-display-page">
  <main class="customer-display-shell">
    <header class="customer-display-header">
      <div class="customer-display-identity">
        <p class="customer-display-eyebrow">Cliente del ticket seleccionado</p>
        <h1 id="customerDisplayName">Sin cliente asignado</h1>
        <p class="customer-display-ticket">
          <span>Ticket</span>
          <strong id="customerDisplayTicket">Sin ticket seleccionado</strong>
        </p>
      </div>
      <div class="customer-display-actions">
        <span id="customerDisplayStatus" class="customer-display-status is-waiting" role="status" aria-live="polite">Esperando despacho</span>
        <button id="customerDisplayChooseScreen" type="button">Elegir pantalla</button>
        <button id="customerDisplayFullscreen" type="button">Pantalla completa</button>
      </div>
    </header>

    <section class="customer-display-scales" aria-label="Pesos actuales de las balanzas">
      <article id="customerDisplayScaleCard1" class="customer-display-scale">
        <div class="customer-display-scale__head">
          <span>Balanza 1</span>
          <small id="customerDisplayScaleStatus1" class="is-waiting">Sin lectura</small>
        </div>
        <div class="customer-display-scale__reading">
          <strong id="customerDisplayScale1">---</strong>
          <small>kg</small>
        </div>
      </article>

      <article id="customerDisplayScaleCard2" class="customer-display-scale customer-display-scale--secondary">
        <div class="customer-display-scale__head">
          <span>Balanza 2</span>
          <small id="customerDisplayScaleStatus2" class="is-waiting">Sin lectura</small>
        </div>
        <div class="customer-display-scale__reading">
          <strong id="customerDisplayScale2">---</strong>
          <small>kg</small>
        </div>
      </article>
    </section>

    <section class="customer-display-ticket-summary" aria-label="Cantidad actual del ticket seleccionado">
      <article class="customer-display-birds">
        <span>Cantidad actual de aves en el ticket</span>
        <div>
          <strong id="customerDisplayBirds">0</strong>
          <small>aves</small>
        </div>
      </article>

      <div class="customer-display-ticket-meta">
        <article>
          <span>Pesadas</span>
          <strong id="customerDisplayRecords">0</strong>
        </article>
        <article>
          <span>Javas</span>
          <strong id="customerDisplayCages">0</strong>
        </article>
      </div>
    </section>

    <p id="customerDisplayAnnouncement" class="customer-display-sr-only" aria-live="polite" aria-atomic="true"></p>
  </main>

  <dialog id="customerDisplayScreenDialog" class="customer-display-screen-dialog" aria-labelledby="customerDisplayScreenTitle">
    <div class="customer-display-screen-dialog__header">
      <div>
        <p class="customer-display-screen-dialog__eyebrow">Monitores disponibles</p>
        <h2 id="customerDisplayScreenTitle">Selecciona dónde mostrar esta vista</h2>
      </div>
      <button id="customerDisplayScreenClose" class="customer-display-screen-dialog__close" type="button" aria-label="Cerrar">&times;</button>
    </div>
    <p id="customerDisplayScreenHelp" class="customer-display-screen-dialog__help">
      Al seleccionar un monitor, esta vista se abrirá allí en pantalla completa.
    </p>
    <div id="customerDisplayScreenList" class="customer-display-screen-list" role="list"></div>
    <p id="customerDisplayScreenFeedback" class="customer-display-screen-feedback" role="status" aria-live="polite"></p>
  </dialog>

  <script type="module" src="{{ asset('js/pantalla-cliente.js') }}?v={{ filemtime(public_path('js/pantalla-cliente.js')) }}"></script>
</body>
</html>
