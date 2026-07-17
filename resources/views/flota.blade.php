<!doctype html>
<html lang="es" class="fleet-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Mi Flota y Choferes | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="fleet-page" data-fleet-view="camiones">
  <main class="fleet-shell">
    <header class="fleet-header card">
      <div>
        <p class="eyebrow">Recursos de la empresa</p>
        <h1>Flota de la empresa</h1>
        <p>Consulta todos los camiones registrados, incluidos los asignados a proveedores, y administra los choferes de tu empresa.</p>
      </div>
      <a class="menu-return-btn" href="{{ route('menu') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M4 6h7v7H4z"></path>
          <path d="M13 6h7v7h-7z"></path>
          <path d="M4 15h7v3H4z"></path>
          <path d="M13 15h7v3h-7z"></path>
        </svg>
        <span>Menú</span>
      </a>
    </header>

    <section class="fleet-summary" aria-label="Resumen de recursos de la empresa">
      <article class="fleet-summary-card card">
        <span>Camiones registrados</span>
        <strong id="truckCount">0</strong>
      </article>
      <article class="fleet-summary-card card">
        <span>Choferes de la empresa</span>
        <strong id="driverCount">0</strong>
      </article>
      <article class="fleet-summary-card fleet-summary-note card">
        <span>Flota completa</span>
        <strong>Todos son propios; con o sin proveedor</strong>
      </article>
    </section>

    <nav class="fleet-tabs card" aria-label="Tipo de recurso" role="tablist">
      <button class="fleet-tab is-active" type="button" data-fleet-type="camiones" role="tab" aria-selected="true">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M3 7h11v9H3z"></path>
          <path d="M14 10h3l3 3v3h-6z"></path>
          <circle cx="7" cy="18" r="2"></circle>
          <circle cx="17" cy="18" r="2"></circle>
        </svg>
        <span>Camiones</span>
      </button>
      <button class="fleet-tab" type="button" data-fleet-type="choferes" role="tab" aria-selected="false">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <circle cx="12" cy="7" r="4"></circle>
          <path d="M4 21a8 8 0 0 1 16 0"></path>
        </svg>
        <span>Mis choferes</span>
      </button>
    </nav>

    <section class="fleet-workspace">
      <section class="fleet-form-panel card" aria-labelledby="fleetFormTitle">
        <div class="fleet-section-head">
          <div>
            <p class="eyebrow">Registro de camión</p>
            <h2 id="fleetFormTitle">Agregar camión</h2>
          </div>
          <span id="fleetEditBadge" class="fleet-edit-badge" hidden>Editando</span>
        </div>

        <form id="fleetForm" class="fleet-form" novalidate>
          <div id="truckFields" class="fleet-form-grid">
            <label class="field fleet-field-wide">
              Placa del camión <span class="fleet-required">*</span>
              <input id="truckPlate" type="text" maxlength="20" autocomplete="off" placeholder="Ej: ABC-123" required>
            </label>
            <label class="field">
              Marca
              <input id="truckBrand" type="text" maxlength="80" autocomplete="off" placeholder="Ej: Hino">
            </label>
            <label class="field">
              Modelo
              <input id="truckModel" type="text" maxlength="80" autocomplete="off" placeholder="Ej: Serie 500">
            </label>
            <label class="field">
              Color
              <input id="truckColor" type="text" maxlength="50" autocomplete="off" placeholder="Ej: Blanco">
            </label>
            <label class="field fleet-field-wide">
              Descripción
              <input id="truckDescription" type="text" maxlength="150" autocomplete="off" placeholder="Información adicional del camión">
            </label>
          </div>

          <div id="driverFields" class="fleet-form-grid" hidden>
            <label class="field fleet-field-wide">
              Nombre completo <span class="fleet-required">*</span>
              <input id="driverName" type="text" maxlength="150" autocomplete="name" placeholder="Ej: Juan Carlos Pérez">
            </label>
            <label class="field">
              Tipo de documento <span class="fleet-optional">Opcional</span>
              <select id="driverDocumentType">
                <option value="">Sin documento</option>
                <option value="CC">Cédula de ciudadanía</option>
                <option value="CE">Cédula de extranjería</option>
                <option value="DNI">DNI</option>
                <option value="PASAPORTE">Pasaporte</option>
                <option value="OTRO">Otro</option>
              </select>
            </label>
            <label class="field">
              Número de documento <span class="fleet-optional">Opcional</span>
              <input id="driverDocumentNumber" type="text" maxlength="30" autocomplete="off" placeholder="Ej: 1020304050">
            </label>
            <label class="field fleet-field-wide">
              Teléfono <span class="fleet-optional">Opcional</span>
              <input id="driverPhone" type="tel" maxlength="30" autocomplete="tel" placeholder="Ej: 300 123 4567">
            </label>
          </div>

          <div class="fleet-form-actions">
            <button id="saveFleetBtn" class="btn btn-success fleet-save-btn" type="submit">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M5 4h12l2 2v14H5z"></path>
                <path d="M8 4v6h8V4"></path>
                <path d="M8 17h8"></path>
              </svg>
              <span>Guardar camión</span>
            </button>
            <button id="clearFleetBtn" class="btn btn-ghost" type="button">Limpiar</button>
          </div>
          <p id="fleetFormMessage" class="fleet-message" role="status" aria-live="polite"></p>
        </form>
      </section>

      <section class="fleet-list-panel card" aria-labelledby="fleetListTitle">
        <div class="fleet-list-head">
          <div>
            <p class="eyebrow">Todos los registros</p>
            <h2 id="fleetListTitle">Camiones de la empresa</h2>
          </div>
          <label class="fleet-search">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="10.5" cy="10.5" r="7.5"></circle>
              <path d="M16 16l5 5"></path>
            </svg>
            <input id="fleetSearch" type="search" placeholder="Buscar por placa, marca, modelo o proveedor">
          </label>
        </div>
        <p id="fleetListMessage" class="fleet-message" role="status" aria-live="polite"></p>
        <div id="fleetList" class="fleet-list" aria-live="polite"></div>
      </section>
    </section>
  </main>

  <script type="module" src="{{ asset('js/flota.js') }}"></script>
</body>
</html>
