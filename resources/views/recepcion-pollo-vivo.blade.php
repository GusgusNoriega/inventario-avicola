<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  @include('partials.pwa')
  <title>Recepción de pollo vivo | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/recepcion-pollo-vivo.css') }}?v={{ filemtime(public_path('css/recepcion-pollo-vivo.css')) }}">
</head>
<body class="live-intake-page">
  <main class="lir-shell">
    <header class="lir-topbar">
      <div>
        <p>Camión del día</p>
        <h1>Recepción de pollo vivo</h1>
      </div>
      <div class="lir-topbar-actions">
        <span id="liveIntakeOperatingDate" class="lir-date-chip">Jornada de hoy</span>
        <button id="liveIntakeOpenSettings" class="lir-icon-button" type="button" aria-haspopup="dialog" aria-controls="liveIntakeSettingsModal" aria-label="Abrir configuración">⚙</button>
        <a class="lir-menu-link" href="{{ route('menu') }}">Menú</a>
      </div>
    </header>

    <section class="lir-scale-panel" aria-labelledby="liveIntakeScaleTitle">
      <div class="lir-scale-copy">
        <span id="liveIntakeScaleStatus" class="lir-status-chip is-offline"><i></i> Sin conexión</span>
        <h2 id="liveIntakeScaleTitle">Balanza de recepción</h2>
        <small id="liveIntakeScaleRaw">Trama: --</small>
      </div>
      <output id="liveIntakeScaleWeight" class="lir-scale-weight">--- <small>kg</small></output>
      <div class="lir-scale-actions">
        <button id="liveIntakeConnectBle" type="button">Conectar BLE</button>
        <button id="liveIntakeConnectSerial" type="button">Conectar serial</button>
        <button id="liveIntakeDisconnectScale" class="is-danger" type="button" disabled>Desconectar</button>
      </div>
    </section>

    <section class="lir-daily-summary" aria-label="Totales del camión del día">
      <article><span>Pesadas</span><strong id="liveIntakeDailyWeighings">0</strong></article>
      <article><span>Javas</span><strong id="liveIntakeDailyCages">0</strong></article>
      <article><span>Pollos</span><strong id="liveIntakeDailyBirds">0</strong></article>
      <article><span>Peso neto</span><strong id="liveIntakeDailyNet">0.000 kg</strong></article>
      <article class="is-own"><span>Solo mi empresa</span><strong id="liveIntakeOwnBirds">0 pollos</strong></article>
      <article class="is-external"><span id="liveIntakeExternalSummaryLabel">Empresa externa</span><strong id="liveIntakeExternalBirds">0 pollos</strong></article>
    </section>

    <section class="lir-capture-panel" aria-label="Datos de la siguiente pesada">
      <fieldset class="lir-choice-group lir-owner-choice">
        <legend>Propietario</legend>
        <button class="is-active" type="button" data-live-owner="PROPIA" aria-pressed="true">Mi empresa</button>
        <button id="liveIntakeExternalOwnerButton" type="button" data-live-owner="EXTERNA" aria-pressed="false" disabled>Empresa externa</button>
      </fieldset>

      <fieldset class="lir-choice-group lir-sex-choice">
        <legend>Sexo</legend>
        <button class="is-active" type="button" data-live-sex="MACHO" aria-pressed="true">Macho</button>
        <button type="button" data-live-sex="HEMBRA" aria-pressed="false">Hembra</button>
      </fieldset>

      <label class="lir-number-field">
        <span>Aves por java</span>
        <input id="liveIntakeBirdsPerCage" type="number" min="1" max="1000" step="1" value="7" inputmode="numeric">
      </label>
      <label class="lir-number-field">
        <span>Cantidad de javas</span>
        <input id="liveIntakeCageCount" type="number" min="1" max="10000" step="1" value="1" inputmode="numeric">
      </label>
      <label class="lir-select-field">
        <span>Tipo de java</span>
        <select id="liveIntakeCageType"><option value="">Cargando…</option></select>
      </label>
      <button id="liveIntakeCapture" class="lir-capture-button" type="button" disabled>
        Guardar en columna <strong id="liveIntakeActiveLaneNumber">1</strong>
      </button>
      <p id="liveIntakeMessage" class="lir-message" role="status" aria-live="polite">Preparando la recepción…</p>
    </section>

    <section class="lir-lanes-stage" aria-label="Columnas de recepción y despacho">
      <div id="liveIntakeLanes" class="lir-lanes">
        @for ($lane = 1; $lane <= 4; $lane++)
          @php($warehouseLane = $lane <= 2)
          <article class="lir-lane {{ $warehouseLane ? 'is-warehouse' : 'is-client' }} {{ $lane === 1 ? 'is-active' : '' }}" data-live-lane="{{ $lane }}">
            <button class="lir-lane-select" type="button" data-live-select-lane="{{ $lane }}" aria-pressed="{{ $lane === 1 ? 'true' : 'false' }}">
              <span>Columna {{ $lane }}</span>
              <strong>{{ $warehouseLane ? 'Entrada a almacén' : 'Despacho directo' }}</strong>
              <small id="liveIntakeLaneDestination{{ $lane }}">Sin configurar</small>
            </button>
            <div id="liveIntakeLaneRows{{ $lane }}" class="lir-lane-rows">
              <p class="lir-empty-lane">Aún no hay pesadas</p>
            </div>
            <footer>
              <span><b id="liveIntakeLaneCages{{ $lane }}">0</b> javas</span>
              <span><b id="liveIntakeLaneBirds{{ $lane }}">0</b> pollos</span>
              <strong id="liveIntakeLaneNet{{ $lane }}">0.000 kg</strong>
            </footer>
          </article>
        @endfor
      </div>
    </section>

    <footer class="lir-selected-total" aria-live="polite">
      <div><span>Columna seleccionada</span><strong id="liveIntakeSelectedLaneLabel">1 · Entrada a almacén</strong></div>
      <div><span>Pesadas</span><strong id="liveIntakeSelectedWeighings">0</strong></div>
      <div><span>Javas</span><strong id="liveIntakeSelectedCages">0</strong></div>
      <div><span>Pollos</span><strong id="liveIntakeSelectedBirds">0</strong></div>
      <div><span>Bruto</span><strong id="liveIntakeSelectedGross">0.000 kg</strong></div>
      <div class="is-net"><span>Neto</span><strong id="liveIntakeSelectedNet">0.000 kg</strong></div>
    </footer>
  </main>

  <div id="liveIntakeSettingsModal" class="lir-modal" hidden>
    <form id="liveIntakeSettingsForm" class="lir-modal-card" role="dialog" aria-modal="true" aria-labelledby="liveIntakeSettingsTitle">
      <header>
        <div><p>Recepción diaria</p><h2 id="liveIntakeSettingsTitle">Configuración</h2></div>
        <button type="button" data-live-close-settings aria-label="Cerrar configuración">×</button>
      </header>

      <section>
        <h3>Botón de empresa externa</h3>
        <label><span>Empresa externa predeterminada</span><select id="liveIntakeDefaultExternalOwner"><option value="">Seleccionar empresa…</option></select></label>
      </section>

      <section>
        <h3>Destinos de las cuatro columnas</h3>
        <div class="lir-settings-grid">
          <label><span>Columna 1 · Almacén</span><select id="liveIntakeLane1Destination"></select></label>
          <label><span>Columna 2 · Almacén</span><select id="liveIntakeLane2Destination"></select></label>
          <label><span>Columna 3 · Cliente</span><select id="liveIntakeLane3Destination"></select></label>
          <label><span>Columna 4 · Cliente</span><select id="liveIntakeLane4Destination"></select></label>
        </div>
      </section>

      <section>
        <h3>Zoom de la vista</h3>
        <div class="lir-zoom-control">
          <button id="liveIntakeZoomOut" type="button" aria-label="Reducir zoom">−</button>
          <output id="liveIntakeZoomValue">100 %</output>
          <button id="liveIntakeZoomIn" type="button" aria-label="Aumentar zoom">+</button>
          <button id="liveIntakeZoomReset" type="button">Restablecer</button>
        </div>
      </section>

      <section>
        <h3>Balanza y lectura manual</h3>
        <div class="lir-settings-grid is-scale">
          <label><span>Lectura manual (kg)</span><input id="liveIntakeManualWeight" type="number" min="0.001" step="0.001" inputmode="decimal"></label>
          <button id="liveIntakeApplyManualWeight" type="button">Aplicar lectura</button>
          <label><span>Baudios</span><input id="liveIntakeBaudRate" type="number" min="300" step="1" value="9600"></label>
          <label><span>Bits</span><select id="liveIntakeDataBits"><option value="8">8</option><option value="7">7</option></select></label>
          <label><span>Parada</span><select id="liveIntakeStopBits"><option value="1">1</option><option value="2">2</option></select></label>
          <label><span>Paridad</span><select id="liveIntakeParity"><option value="none">Ninguna</option><option value="even">Par</option><option value="odd">Impar</option></select></label>
          <label><span>Flujo</span><select id="liveIntakeFlowControl"><option value="none">Ninguno</option><option value="hardware">Hardware</option></select></label>
        </div>
      </section>

      <p id="liveIntakeSettingsMessage" class="lir-message" role="status" aria-live="polite"></p>
      <footer><button type="button" data-live-close-settings>Cancelar</button><button class="is-primary" type="submit">Guardar configuración</button></footer>
    </form>
  </div>

  <script type="module" src="{{ asset('js/recepcion-pollo-vivo.js') }}?v={{ filemtime(public_path('js/recepcion-pollo-vivo.js')) }}"></script>
</body>
</html>
