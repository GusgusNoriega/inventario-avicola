<!doctype html>
<html lang="es" class="journey-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Configurar jornada | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ md5_file(public_path('css/style.css')) }}">
</head>
<body class="journey-page">
  <main class="journey-shell">
    <header class="journey-hero card">
      <div>
        <p class="eyebrow">Planeación operativa</p>
        <h1>Orígenes de la jornada</h1>
        <p>Selecciona los camiones y almacenes habilitados como origen.</p>
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
        <span>Orígenes seleccionados</span>
        <strong><span id="journeySelectedCount">0</span> / <span id="journeyTotalCount">0</span></strong>
      </article>
      <article class="journey-summary-card card">
        <span>Estado</span>
        <strong id="journeyStatus">Sin configurar</strong>
      </article>
    </section>

    <section class="journey-workspace card">
      <div class="journey-toolbar">
        <label class="journey-search">
          <span>Buscar origen</span>
          <input id="journeySearch" type="search" placeholder="Proveedor, almacén, documento o placa" autocomplete="off">
        </label>
        <label class="journey-select-all">
          <input id="journeySelectAll" type="checkbox">
          <span>Seleccionar orígenes visibles</span>
        </label>
        <button id="journeySaveBtn" class="btn btn-success" type="button">Guardar jornada</button>
      </div>

      <p id="journeyMessage" class="journey-message" role="status" aria-live="polite"></p>

      <div class="journey-table-wrap">
        <table class="journey-table">
          <thead>
            <tr id="journeyTableHead">
              <th scope="col">Elegir</th>
              <th scope="col">Origen</th>
              <th scope="col">Detalle</th>
            </tr>
          </thead>
          <tbody id="journeyRows">
            <tr>
              <td colspan="3" class="journey-loading">Cargando orígenes...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/jornada.js') }}?v={{ md5_file(public_path('js/jornada.js')) }}"></script>
</body>
</html>
