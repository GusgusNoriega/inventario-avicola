@php
  $customerDisplayMode = $customerDisplayMode ?? 'wholesale';
  $isRetailCustomerDisplay = $customerDisplayMode === 'retail';
  $customerDisplayTitle = $customerDisplayTitle ?? 'Despacho en vivo';
  $retailStation = $isRetailCustomerDisplay ? (int) ($retailStation ?? 1) : null;
@endphp
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $customerDisplayTitle }} | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/pantalla-cliente.css') }}?v={{ filemtime(public_path('css/pantalla-cliente.css')) }}">
</head>
<body
  class="customer-display-page{{ $isRetailCustomerDisplay ? ' customer-display-page--retail' : '' }}"
  data-customer-display-mode="{{ $customerDisplayMode }}"
  @if($isRetailCustomerDisplay) data-retail-station="{{ $retailStation }}" @endif
>
  <main class="customer-display-shell">
    <header class="customer-display-header">
      <div class="customer-display-identity">
        <p class="customer-display-eyebrow">
          {{ $isRetailCustomerDisplay ? "Venta minorista {$retailStation} · cliente del ticket seleccionado" : 'Cliente del ticket seleccionado' }}
        </p>
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

    @if($isRetailCustomerDisplay)
      <section class="customer-display-scales customer-display-scales--retail" aria-label="Peso actual de la balanza minorista">
        <article id="customerDisplayScaleCard1" class="customer-display-scale customer-display-scale--retail">
          <div class="customer-display-scale__head">
            <span>Peso actual</span>
            <small id="customerDisplayScaleStatus1" class="is-waiting">Sin lectura</small>
          </div>
          <div class="customer-display-scale__reading">
            <strong id="customerDisplayScale1">---</strong>
            <small>kg</small>
          </div>
        </article>
      </section>

      <section class="customer-display-ticket-summary customer-display-ticket-summary--retail" aria-label="Totales del ticket minorista seleccionado">
        <article class="customer-display-birds">
          <span>Pollos / aves del ticket seleccionado</span>
          <div>
            <strong id="customerDisplayBirds">0</strong>
            <small>pollos</small>
          </div>
        </article>

        <div class="customer-display-ticket-meta customer-display-ticket-meta--retail">
          <article>
            <span>Pesadas</span>
            <strong id="customerDisplayRecords">0</strong>
          </article>
          <article>
            <span>Bandejas</span>
            <strong id="customerDisplayTrays">0</strong>
          </article>
          <article class="customer-display-ticket-meta__weight">
            <span>Peso neto</span>
            <strong id="customerDisplayNetWeight">0.000 kg</strong>
          </article>
          <article class="customer-display-ticket-meta__amount">
            <span>Total del ticket</span>
            <strong id="customerDisplayAmount">S/ 0.00</strong>
          </article>
        </div>
      </section>
    @else
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
    @endif

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

  @if($isRetailCustomerDisplay)
    <script type="module" src="{{ asset('js/pantalla-cliente-minorista.js') }}?v={{ filemtime(public_path('js/pantalla-cliente-minorista.js')) }}"></script>
  @else
    <script type="module" src="{{ asset('js/pantalla-cliente.js') }}?v={{ filemtime(public_path('js/pantalla-cliente.js')) }}"></script>
  @endif
</body>
</html>
