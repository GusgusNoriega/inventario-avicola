<!doctype html>
<html lang="es" class="journey-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Precios de la jornada | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="journey-page">
  <main class="journey-shell journey-prices-shell">
    <header class="journey-hero card">
      <div>
        <p class="eyebrow">Despacho minorista</p>
        <h1>Precios de la jornada</h1>
        <p>Administra la lista general utilizada por los módulos de despacho minorista 1 y 2.</p>
      </div>
      <div class="journey-header-actions">
        <a class="menu-return-btn" href="{{ route('menu') }}">Menú</a>
      </div>
    </header>

    <section class="journey-summary journey-price-summary">
      <article class="journey-summary-card card">
        <span>Jornada operativa</span>
        <strong id="journeyPriceDate">Cargando...</strong>
      </article>
      <article class="journey-summary-card card">
        <span>Horario</span>
        <strong id="journeyPriceWindow">21:00 a 21:00</strong>
      </article>
    </section>

    <section class="journey-global-prices card" aria-labelledby="journeyPricesTitle">
      <div class="journey-global-prices-head">
        <div>
          <p class="eyebrow">Lista general de venta</p>
          <h2 id="journeyPricesTitle">Precios minoristas</h2>
        </div>
        <p>Estos valores se administran de forma independiente a la selección de camiones, proveedores y almacenes de la jornada.</p>
      </div>
      <div class="journey-global-price-grid">
        <label class="field">
          Pollo vivo (kg)
          <input id="journeyPriceLive" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
        <label class="field">
          Pollo pelado (kg)
          <input id="journeyPriceDressed" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
        <label class="field">
          Pollo beneficiado (kg)
          <input id="journeyPriceProcessed" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
      </div>
      <div class="journey-price-actions">
        <p id="journeyPriceMessage" class="journey-message" role="status" aria-live="polite"></p>
        <button id="journeyPriceSave" class="btn btn-success" type="button">Guardar precios</button>
      </div>
    </section>

    <section class="journey-global-prices journey-ticket-settings card" aria-labelledby="ticketSettingsTitle">
      <div class="journey-global-prices-head">
        <div>
          <p class="eyebrow">Impresión de tickets</p>
          <h2 id="ticketSettingsTitle">Configuración global</h2>
        </div>
        <p>El título y el mensaje se aplicarán a los tickets de todos los módulos de despacho, incluidas las reimpresiones.</p>
      </div>

      <form id="ticketTitleForm" class="journey-ticket-setting" aria-labelledby="ticketTitleLabel">
        <label id="ticketTitleLabel" class="field" for="ticketTitleInput">
          Título de los tickets
          <input
            id="ticketTitleInput"
            name="ticket_title"
            type="text"
            maxlength="120"
            autocomplete="organization"
            placeholder="DISTRIBUIDORA DIEGO ALBERTO"
            required
          >
        </label>
        <div class="journey-price-actions">
          <p id="ticketTitleStatus" class="journey-message" role="status" aria-live="polite"></p>
          <button id="ticketTitleSave" class="btn btn-success" type="submit">Guardar título</button>
        </div>
      </form>

      <form id="ticketMessageForm" class="journey-ticket-setting" aria-labelledby="ticketMessageLabel">
        <label id="ticketMessageLabel" class="field" for="ticketMessageInput">
          Mensaje para los tickets
          <input
            id="ticketMessageInput"
            name="ticket_message"
            type="text"
            maxlength="255"
            autocomplete="off"
            placeholder="Escribe un mensaje breve"
          >
        </label>
        <div class="journey-price-actions">
          <p id="ticketMessageStatus" class="journey-message" role="status" aria-live="polite"></p>
          <button id="ticketMessageSave" class="btn btn-success" type="submit">Guardar mensaje</button>
        </div>
      </form>
    </section>
  </main>

  <script type="module" src="{{ asset('js/precios-jornada.js') }}"></script>
</body>
</html>
