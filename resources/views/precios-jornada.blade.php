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
          <input id="journeyPriceLive" type="number" min="0.01" max="99999999.9999" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
        <label class="field">
          Pollo pelado (kg)
          <input id="journeyPriceDressed" type="number" min="0.01" max="99999999.9999" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
        <label class="field">
          Pollo beneficiado (kg)
          <input id="journeyPriceProcessed" type="number" min="0.01" max="99999999.9999" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
      </div>
      <div class="journey-price-actions">
        <p id="journeyPriceMessage" class="journey-message" role="status" aria-live="polite"></p>
        <button id="journeyPriceSave" class="btn btn-success" type="button">Guardar precios</button>
      </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/precios-jornada.js') }}"></script>
</body>
</html>
