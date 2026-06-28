<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Configurar jornada | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="journey-page">
  <main class="journey-shell">
    <header class="journey-hero card">
      <div>
        <p class="eyebrow">Planeación operativa</p>
        <h1>Proveedores de la jornada</h1>
        <p>Selecciona las placas habilitadas para la operación.</p>
      </div>
      <div class="journey-header-actions">
        <a class="menu-return-btn" href="{{ route('menu') }}">Menú</a>
        <a class="btn btn-primary" href="{{ route('operacion') }}#despacho">Ir a despacho</a>
      </div>
    </header>

    <section class="journey-summary">
      <article class="journey-summary-card card">
        <span>Jornada operativa</span>
        <strong id="journeyDate">Cargando...</strong>
      </article>
      <article class="journey-summary-card card">
        <span>Horario</span>
        <strong id="journeyWindow">21:00 a 21:00</strong>
      </article>
      <article class="journey-summary-card card">
        <span>Camiones seleccionados</span>
        <strong><span id="journeySelectedCount">0</span> / <span id="journeyTotalCount">0</span></strong>
      </article>
      <article class="journey-summary-card card">
        <span>Estado</span>
        <strong id="journeyStatus">Sin configurar</strong>
      </article>
    </section>

    <section class="journey-global-prices card" aria-labelledby="journeyGlobalPricesTitle">
      <div class="journey-global-prices-head">
        <div>
          <p class="eyebrow">Lista general de venta</p>
          <h2 id="journeyGlobalPricesTitle">Precios globales</h2>
        </div>
        <p>Se aplican únicamente cuando el cliente no tiene un precio específico para ese tipo de pollo.</p>
      </div>
      <div class="journey-global-price-grid">
        <label class="field">
          Pollo vivo (kg)
          <input id="globalPriceLive" type="number" min="0.01" max="99999999.9999" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
        <label class="field">
          Pollo pelado (kg)
          <input id="globalPriceDressed" type="number" min="0.01" max="99999999.9999" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
        <label class="field">
          Pollo beneficiado (kg)
          <input id="globalPriceProcessed" type="number" min="0.01" max="99999999.9999" step="0.01" inputmode="decimal" placeholder="0.00">
        </label>
      </div>
    </section>

    <section class="journey-workspace card">
      <div class="journey-toolbar">
        <label class="journey-search">
          <span>Buscar proveedor o placa</span>
          <input id="journeySearch" type="search" placeholder="Nombre, documento o placa" autocomplete="off">
        </label>
        <label class="journey-select-all">
          <input id="journeySelectAll" type="checkbox">
          <span>Seleccionar camiones visibles</span>
        </label>
        <button id="journeySaveBtn" class="btn btn-success" type="button">Guardar jornada</button>
      </div>

      <p id="journeyMessage" class="journey-message" role="status" aria-live="polite"></p>

      <div class="journey-table-wrap">
        <table class="journey-table">
          <thead>
            <tr id="journeyTableHead">
              <th scope="col">Elegir</th>
              <th scope="col">Proveedor</th>
              <th scope="col">Placa</th>
            </tr>
          </thead>
          <tbody id="journeyRows">
            <tr>
              <td colspan="3" class="journey-loading">Cargando proveedores y camiones...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/jornada.js') }}"></script>
</body>
</html>
