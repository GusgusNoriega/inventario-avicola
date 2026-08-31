<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, viewport-fit=cover">
  @include('partials.pwa')
  <title>Recepción de pollo vivo | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/recepcion-pollo-vivo.css') }}?v={{ filemtime(public_path('css/recepcion-pollo-vivo.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/live-chicken-reception-typography.css') }}?v={{ filemtime(public_path('css/live-chicken-reception-typography.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/live-chicken-reception-typography-panel.css') }}?v={{ filemtime(public_path('css/live-chicken-reception-typography-panel.css')) }}">
</head>
<body class="live-intake-page">
  <svg class="lir-icon-sprite" aria-hidden="true" focusable="false">
    <symbol id="lir-icon-rooster" viewBox="-1 0 66 64">
      <path d="M18 27C10 24 6 19 5 12c7 1 13 5 16 11M16 32c-7-1-12-5-15-10 7-2 14 0 19 5" />
      <path d="M42 29c-6-6-15-9-23-5-9 4-12 14-6 21 5 6 15 8 25 4 8-3 12-10 10-17l-6-3Z" />
      <path d="M39 32c5-3 7-7 6-12-1-5 1-9 5-11 5 1 8 6 7 11-1 5-4 8-9 10" />
      <path d="M49 9c-1-4 1-7 4-7 2 2 2 5 1 7 3-3 6-2 7 1 0 3-2 5-5 6" />
      <path d="m57 17 6 3-6 3M29 31c7 1 11 5 11 10-6 3-13 1-16-4M25 50l-1 9m13-10 2 10m-19 0h8m7 0h8" />
      <circle cx="52" cy="16" r="1.5" fill="currentColor" stroke="none" />
    </symbol>
    <symbol id="lir-icon-hen" viewBox="-1 0 66 64">
      <path d="M17 29c-6-1-11-5-14-10 7-1 13 1 18 6" />
      <path d="M45 30c-5-6-14-9-23-6-10 3-15 13-10 21 5 8 17 10 28 5 8-4 12-12 9-19l-4-1Z" />
      <path d="M40 33c5-3 7-7 6-12-1-4 1-8 5-10 4 1 7 5 7 9 0 5-4 8-9 10" />
      <path d="M49 11c0-3 2-5 4-5 2 2 2 4 1 6 2-2 5-1 5 2 0 2-1 4-4 5" />
      <path d="m58 18 5 3-5 3M29 32c6 1 10 5 10 10-6 3-13 1-16-4M25 51l-1 8m13-9 2 9m-19 0h8m7 0h8" />
      <circle cx="53" cy="17" r="1.5" fill="currentColor" stroke="none" />
    </symbol>
  </svg>
  <main id="liveIntakeMain" class="lir-shell" data-live-user-id="{{ auth()->id() }}">
    <div id="liveIntakeZoomSurface" class="lir-zoom-surface">
    <header class="lir-topbar">
      <div>
        <p>Camión del día</p>
        <h1>Recepción de pollo vivo</h1>
      </div>
      <div class="lir-topbar-actions">
        <span id="liveIntakeOperatingDate" class="lir-date-chip">Jornada de hoy</span>
        <button id="liveIntakeOpenSettings" class="lir-icon-button" type="button" aria-haspopup="dialog" aria-controls="liveIntakeSettingsModal" aria-expanded="false" aria-label="Abrir configuración general">⚙</button>
        <a class="lir-menu-link" href="{{ route('menu') }}">Menú</a>
      </div>
    </header>

    <section class="lir-scale-panel" aria-labelledby="liveIntakeScaleTitle">
      <div class="lir-scale-copy">
        <span id="liveIntakeScaleStatus" class="lir-status-chip is-offline"><i></i> Sin conexión</span>
        <h2 id="liveIntakeScaleTitle">Balanza de recepción</h2>
        <small id="liveIntakeScaleRaw">Trama: --</small>
      </div>
      <div class="lir-scale-readout">
        <output id="liveIntakeScaleWeight" class="lir-scale-weight">--- <small>kg</small></output>
        <button id="liveIntakeOpenManualWeight" class="lir-scale-inline-button" type="button" aria-haspopup="dialog" aria-controls="liveIntakeManualWeightModal" aria-expanded="false">Colocar peso manual</button>
      </div>
      <div class="lir-scale-panel-actions">
        <button id="liveIntakeOpenScaleSettings" type="button" aria-haspopup="dialog" aria-controls="liveIntakeScaleSettingsModal" aria-expanded="false"><span aria-hidden="true">⚙</span> Configurar balanza</button>
      </div>
    </section>

    <section class="lir-daily-summary" aria-label="Totales del camión del día">
      <article><button class="lir-summary-trigger" type="button" data-live-summary-scope="daily" aria-haspopup="dialog" aria-controls="liveIntakeSummaryModal" aria-expanded="false"><span>Pesadas</span><strong id="liveIntakeDailyWeighings">0</strong></button></article>
      <article><button class="lir-summary-trigger" type="button" data-live-summary-scope="daily" aria-haspopup="dialog" aria-controls="liveIntakeSummaryModal" aria-expanded="false"><span>Javas</span><strong id="liveIntakeDailyCages">0</strong></button></article>
      <article><button class="lir-summary-trigger" type="button" data-live-summary-scope="daily" aria-haspopup="dialog" aria-controls="liveIntakeSummaryModal" aria-expanded="false"><span>Pollos</span><strong id="liveIntakeDailyBirds">0</strong></button></article>
      <article><button class="lir-summary-trigger" type="button" data-live-summary-scope="daily" aria-haspopup="dialog" aria-controls="liveIntakeSummaryModal" aria-expanded="false"><span>Peso neto</span><strong id="liveIntakeDailyNet">0.000 kg</strong></button></article>
      <article class="is-own"><button class="lir-summary-trigger" type="button" data-live-summary-scope="own" aria-haspopup="dialog" aria-controls="liveIntakeSummaryModal" aria-expanded="false"><span>Solo mi empresa</span><strong id="liveIntakeOwnBirds">0 pollos</strong></button></article>
      <article class="is-external"><button class="lir-summary-trigger" type="button" data-live-summary-scope="external" aria-haspopup="dialog" aria-controls="liveIntakeSummaryModal" aria-expanded="false"><span id="liveIntakeExternalSummaryLabel">Empresa externa</span><strong id="liveIntakeExternalBirds">0 pollos</strong></button></article>
    </section>

    <section class="lir-capture-panel" aria-label="Datos de la siguiente pesada">
      <div class="lir-assignment-summary" aria-live="polite">
        <span>Asignación automática</span>
        <strong id="liveIntakeAssignmentTitle">Mi empresa · Macho</strong>
        <small id="liveIntakeAssignmentHelp">La columna 1 define propietario y sexo.</small>
      </div>

      <fieldset id="liveIntakeSexChoice" class="lir-choice-group lir-sex-choice" hidden>
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
        <input id="liveIntakeCageCount" type="number" min="1" max="10000" step="1" value="5" inputmode="numeric">
      </label>
      <label class="lir-select-field">
        <span>Tipo de java</span>
        <select id="liveIntakeCageType"><option value="">Cargando…</option></select>
      </label>
      <button id="liveIntakeCapture" class="lir-capture-button" type="button" disabled>
        <span id="liveIntakeCaptureLabel">Guardar en columna</span> <strong id="liveIntakeActiveLaneNumber">1</strong>
      </button>
      <p id="liveIntakeMessage" class="lir-message" role="status" aria-live="polite">Preparando la recepción…</p>
    </section>

    <section id="liveIntakeLanes" class="lir-lanes-stage" aria-label="Columnas de recepción y despacho">
      <div class="lir-lane-group is-warehouse-group">
        <header class="lir-lane-group-head">
          <div><span>Entradas a almacén</span><strong>Cuatro columnas por propietario y sexo</strong></div>
          <small>Desliza entre columnas; dentro de cada tabla, mueve a los lados para ver todos los datos.</small>
        </header>
        <div class="lir-lane-track" tabindex="0" aria-label="Desplazamiento horizontal de entradas a almacén">
          <div class="lir-lanes is-warehouse-lanes">
          @for ($lane = 1; $lane <= 4; $lane++)
            @php
              $ownLane = $lane <= 2;
              $maleLane = in_array($lane, [1, 3], true);
              $ownerLabel = $ownLane ? 'Mi empresa' : 'Empresa externa';
              $sexLabel = $maleLane ? 'Macho' : 'Hembra';
            @endphp
            <article class="lir-lane is-warehouse {{ $ownLane ? 'is-own-lane' : 'is-external-lane' }} {{ $maleLane ? 'is-male-lane' : 'is-female-lane' }} {{ $lane === 1 ? 'is-active' : '' }}" data-live-lane="{{ $lane }}">
              <button class="lir-lane-select" type="button" data-live-select-lane="{{ $lane }}" aria-pressed="{{ $lane === 1 ? 'true' : 'false' }}" aria-label="Seleccionar columna {{ $lane }}: {{ $ownerLabel }}, {{ $sexLabel }}">
                <span class="lir-lane-profile-row">
                  <span class="lir-lane-number">Columna {{ $lane }}</span>
                  <em id="liveIntakeLaneProfile{{ $lane }}" class="lir-lane-owner-badge">{{ $ownerLabel }}</em>
                </span>
                <span class="lir-lane-identity">
                  <svg class="lir-lane-sex-icon" aria-hidden="true" focusable="false">
                    <use href="#{{ $maleLane ? 'lir-icon-rooster' : 'lir-icon-hen' }}"></use>
                  </svg>
                  <span class="lir-lane-identity-copy">
                    <strong>Entrada a almacén</strong>
                    <b>{{ $maleLane ? 'Gallo · Macho' : 'Gallina · Hembra' }}</b>
                  </span>
                </span>
                <small id="liveIntakeLaneDestination{{ $lane }}">Sin configurar</small>
              </button>
              <div id="liveIntakeLaneRows{{ $lane }}" class="lir-lane-rows" role="region" tabindex="0" aria-label="Tabla desplazable de registros de la columna {{ $lane }}">
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
        </div>
      </div>

      <div class="lir-lane-group is-client-group">
        <header class="lir-lane-group-head">
          <div><span>Tickets de despacho Mayorista 1</span><strong>Dos borradores independientes</strong></div>
          <small>Agrega pesadas, revisa los totales y registra cada ticket cuando esté completo.</small>
        </header>
        <div class="lir-lanes is-client-lanes">
        @for ($lane = 5; $lane <= 6; $lane++)
          <article class="lir-lane is-client is-own-lane lir-ticket-draft" data-live-lane="{{ $lane }}">
            <header class="lir-direct-lane-head">
              <button class="lir-lane-select" type="button" data-live-select-lane="{{ $lane }}" aria-pressed="false">
                <span>Columna {{ $lane }}</span>
                <strong>Ticket de despacho</strong>
                <em id="liveIntakeLaneProfile{{ $lane }}">Mayorista 1 · Borrador vacío</em>
                <small>Cliente: <b id="liveIntakeLaneDestination{{ $lane }}">Sin cliente</b></small>
              </button>
              <button
                id="liveIntakeChooseClient{{ $lane }}"
                class="lir-client-picker-trigger"
                type="button"
                data-live-choose-client="{{ $lane }}"
                aria-haspopup="dialog"
                aria-controls="liveIntakeClientModal"
                aria-expanded="false"
                aria-label="Elegir cliente de despacho para la columna {{ $lane }}"
              >Elegir cliente</button>
            </header>
            <div id="liveIntakeLaneRows{{ $lane }}" class="lir-lane-rows" role="region" tabindex="0" aria-label="Tabla desplazable de registros de la columna {{ $lane }}">
              <p class="lir-empty-lane">Agrega la primera pesada del ticket</p>
            </div>
            <footer class="lir-ticket-draft-footer">
              <div class="lir-ticket-draft-totals">
                <span><b id="liveIntakeLaneCages{{ $lane }}">0</b> javas</span>
                <span><b id="liveIntakeLaneBirds{{ $lane }}">0</b> pollos</span>
                <strong id="liveIntakeLaneNet{{ $lane }}">0.000 kg</strong>
              </div>
              <button class="lir-register-ticket" type="button" data-live-register-ticket="{{ $lane }}" disabled>Registrar ticket</button>
            </footer>
          </article>
        @endfor
        </div>
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
    </div>
  </main>

  <div id="liveIntakeSummaryModal" class="lir-modal" hidden>
    <section class="lir-modal-card is-summary-detail" role="dialog" aria-modal="true" aria-labelledby="liveIntakeSummaryTitle" aria-describedby="liveIntakeSummaryHelp">
      <header>
        <div><p id="liveIntakeSummaryCaption">Resumen de la jornada</p><h2 id="liveIntakeSummaryTitle">Todas las pesadas</h2></div>
        <button id="liveIntakeSummaryClose" type="button" data-live-close-summary aria-label="Cerrar detalle de pesadas">×</button>
      </header>
      <section class="lir-summary-detail-body">
        <div class="lir-summary-intro">
          <p id="liveIntakeSummaryHelp" class="lir-modal-help">Pesadas registradas de la jornada actual. Desliza la tabla para consultar todos los datos.</p>
          <p id="liveIntakeSummaryMessage" class="lir-message lir-summary-message" role="status" aria-live="polite" hidden></p>
        </div>
        <div id="liveIntakeSummaryTotals" class="lir-summary-totals" aria-label="Totales de las pesadas seleccionadas">
          <span><small>Pesadas</small><strong data-live-summary-total="weighings">0</strong></span>
          <span><small>Javas</small><strong data-live-summary-total="cages">0</strong></span>
          <span><small>Pollos</small><strong data-live-summary-total="birds">0</strong></span>
          <span><small>Peso bruto</small><strong data-live-summary-total="gross_weight_kg">0.000 kg</strong></span>
          <span><small>Tara</small><strong data-live-summary-total="tare_weight_kg">0.000 kg</strong></span>
          <span class="is-net"><small>Peso neto</small><strong data-live-summary-total="net_weight_kg">0.000 kg</strong></span>
        </div>
        <div id="liveIntakeSummaryRows" class="lir-summary-table-scroll" role="region" tabindex="0" aria-label="Tabla desplazable de todas las pesadas seleccionadas">
          <p class="lir-summary-empty">Aún no hay pesadas registradas.</p>
        </div>
      </section>
      <footer><button type="button" data-live-close-summary>Cerrar</button></footer>
    </section>
  </div>

  <div id="liveIntakeSettingsModal" class="lir-modal" hidden>
    <form id="liveIntakeSettingsForm" class="lir-modal-card" role="dialog" aria-modal="true" aria-labelledby="liveIntakeSettingsTitle">
      <header>
        <div><p>Recepción diaria</p><h2 id="liveIntakeSettingsTitle">Configuración general</h2></div>
        <button type="button" data-live-close-settings aria-label="Cerrar configuración">×</button>
      </header>

      <section>
        <h3>Empresa externa</h3>
        <label><span>Propietaria de las columnas 3 y 4</span><select id="liveIntakeDefaultExternalOwner"><option value="">Seleccionar empresa…</option></select></label>
      </section>

      <section>
        <h3>Destinos de las seis columnas</h3>
        <div class="lir-settings-grid">
          <label><span>Columna 1 · Propia · Macho</span><select id="liveIntakeLane1Destination"></select></label>
          <label><span>Columna 2 · Propia · Hembra</span><select id="liveIntakeLane2Destination"></select></label>
          <label><span>Columna 3 · Externa · Macho</span><select id="liveIntakeLane3Destination"></select></label>
          <label><span>Columna 4 · Externa · Hembra</span><select id="liveIntakeLane4Destination"></select></label>
          <label><span>Columna 5 · Cliente predeterminado</span><select id="liveIntakeLane5Destination"></select></label>
          <label><span>Columna 6 · Cliente predeterminado</span><select id="liveIntakeLane6Destination"></select></label>
        </div>
      </section>

      <section>
        <h3>Valores predeterminados de las pesadas</h3>
        <div class="lir-settings-grid">
          <label><span>Machos · Aves por java</span><input id="liveIntakeDefaultMaleBirdsPerCage" type="number" min="1" max="1000" step="1" value="7" inputmode="numeric" required></label>
          <label><span>Hembras · Aves por java</span><input id="liveIntakeDefaultFemaleBirdsPerCage" type="number" min="1" max="1000" step="1" value="9" inputmode="numeric" required></label>
          <label><span>Cantidad de javas · Ambos sexos</span><input id="liveIntakeDefaultCageCount" type="number" min="1" max="10000" step="1" value="5" inputmode="numeric" required></label>
          <label><span>Java predeterminada · Inicial: 6,80 kg</span><select id="liveIntakeDefaultCageType"></select></label>
        </div>
        <p class="lir-modal-help">Se aplican al seleccionar otra columna y después de guardar una pesada. Puedes ajustar los valores de la siguiente pesada sin cambiar estos predeterminados. Si la java de 6,80 kg no está disponible, selecciona otra java activa.</p>
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

      <section class="lir-typography-setting">
        <div>
          <h3>Tipografía detallada</h3>
          <p id="liveIntakeTypographySummary">Tamaños originales</p>
          <p class="lir-modal-help">Ajusta cada texto por separado. Los cambios se guardan automáticamente para tu usuario en este navegador.</p>
        </div>
        <button id="liveIntakeOpenTypography" type="button" aria-haspopup="dialog" aria-controls="liveIntakeTypographyPanel" aria-expanded="false">Editar tamaños</button>
      </section>

      <p id="liveIntakeSettingsMessage" class="lir-message" role="status" aria-live="polite"></p>
      <footer><button type="button" data-live-close-settings>Cancelar</button><button class="is-primary" type="submit">Guardar configuración</button></footer>
    </form>
  </div>

  <div id="liveIntakeClientModal" class="lir-modal" hidden>
    <section class="lir-modal-card is-client-picker" role="dialog" aria-modal="true" aria-labelledby="liveIntakeClientModalTitle" aria-describedby="liveIntakeClientModalHelp">
      <header>
        <div><p>Despacho directo</p><h2 id="liveIntakeClientModalTitle">Elegir cliente</h2></div>
        <button type="button" data-live-close-client-picker aria-label="Cerrar selección de cliente">×</button>
      </header>
      <section class="lir-client-picker-body">
        <p id="liveIntakeClientModalHelp" class="lir-modal-help">La recepción será para mi empresa y el despacho se registrará al mismo tiempo para el cliente elegido.</p>
        <label class="lir-client-search-field">
          <span>Buscar por nombre o documento</span>
          <input id="liveIntakeClientSearch" type="search" placeholder="Escribe para buscar…" autocomplete="off">
        </label>
        <div id="liveIntakeClientOptions" class="lir-client-options" role="region" aria-label="Clientes disponibles"></div>
      </section>
      <p id="liveIntakeClientMessage" class="lir-message" role="status" aria-live="polite"></p>
    </section>
  </div>

  <div id="liveIntakeDeliveryTruckModal" class="lir-modal" hidden>
    <section class="lir-modal-card is-fleet-picker" role="dialog" aria-modal="true" aria-labelledby="liveIntakeDeliveryTruckTitle">
      <header>
        <div><p>Paso 1 de 2 · Entrega</p><h2 id="liveIntakeDeliveryTruckTitle">Seleccionar camión</h2></div>
        <button type="button" data-live-close-delivery aria-label="Cerrar selección de transporte">×</button>
      </header>
      <section class="lir-fleet-picker-body">
        <p id="liveIntakeDeliveryTruckHelp" class="lir-modal-help">Selecciona el camión que entregará este ticket.</p>
        <label class="lir-client-search-field"><span>Buscar en mi flota</span><input id="liveIntakeDeliveryTruckSearch" type="search" placeholder="Placa, marca o modelo…" autocomplete="off"></label>
        <div id="liveIntakeDeliveryTruckOptions" class="lir-fleet-options" role="listbox" aria-label="Camiones disponibles"></div>
      </section>
    </section>
  </div>

  <div id="liveIntakeDeliveryDriverModal" class="lir-modal" hidden>
    <section class="lir-modal-card is-fleet-picker" role="dialog" aria-modal="true" aria-labelledby="liveIntakeDeliveryDriverTitle">
      <header>
        <div><p>Paso 2 de 2 · Entrega</p><h2 id="liveIntakeDeliveryDriverTitle">Seleccionar chofer</h2></div>
        <button type="button" data-live-close-delivery aria-label="Cerrar selección de transporte">×</button>
      </header>
      <section class="lir-fleet-picker-body">
        <p id="liveIntakeDeliveryDriverHelp" class="lir-modal-help">Selecciona el chofer responsable de la entrega.</p>
        <label class="lir-client-search-field"><span>Buscar chofer</span><input id="liveIntakeDeliveryDriverSearch" type="search" placeholder="Nombre o documento…" autocomplete="off"></label>
        <div id="liveIntakeDeliveryDriverOptions" class="lir-fleet-options" role="listbox" aria-label="Choferes disponibles"></div>
      </section>
    </section>
  </div>

  <div id="liveIntakeWeighingEditorModal" class="lir-modal" hidden>
    <form id="liveIntakeWeighingEditorForm" class="lir-modal-card is-weighing-editor" role="dialog" aria-modal="true" aria-labelledby="liveIntakeWeighingEditorTitle">
      <header>
        <div><p id="liveIntakeWeighingEditorCaption">Corrección de pesada</p><h2 id="liveIntakeWeighingEditorTitle">Editar pesada</h2></div>
        <button type="button" data-live-close-weighing-editor aria-label="Cerrar editor de pesada">×</button>
      </header>
      <section class="lir-editor-grid">
        <label id="liveIntakeEditOwnerField"><span>Propietario</span><select id="liveIntakeEditOwner"></select></label>
        <label><span>Sexo</span><select id="liveIntakeEditSex"><option value="MACHO">Macho</option><option value="HEMBRA">Hembra</option></select></label>
        <label id="liveIntakeEditAssignmentField" class="lir-editor-assignment"><span>Destino y columna automáticos</span><output id="liveIntakeEditAssignment">Selecciona propietario y sexo</output></label>
        <label><span>Tipo de java</span><select id="liveIntakeEditCageType"></select></label>
        <label><span>Aves por java</span><input id="liveIntakeEditBirdsPerCage" type="number" min="1" max="1000" step="1" inputmode="numeric"></label>
        <label><span>Cantidad de javas</span><input id="liveIntakeEditCageCount" type="number" min="1" max="10000" step="1" inputmode="numeric"></label>
        <label><span>Peso leído (kg)</span><input id="liveIntakeEditWeight" type="number" min="0.001" step="0.001" inputmode="decimal"></label>
        <label><span>Fecha y hora</span><input id="liveIntakeEditWeighedAt" type="datetime-local" step="1"></label>
        <label class="lir-editor-reason"><span>Motivo de la corrección</span><input id="liveIntakeEditReason" type="text" minlength="3" maxlength="250" required placeholder="Ej. Corrección de lectura"></label>
      </section>
      <p id="liveIntakeWeighingEditorMessage" class="lir-message" role="status" aria-live="polite"></p>
      <footer><button id="liveIntakeDeleteWeighing" class="is-danger lir-editor-delete" type="button">Eliminar</button><button id="liveIntakeCancelWeighing" type="button" data-live-close-weighing-editor>Cancelar</button><button id="liveIntakeSaveWeighing" class="is-primary" type="submit">Guardar pesada</button></footer>
    </form>
  </div>

  <div id="liveIntakeTicketEditorModal" class="lir-modal" hidden>
    <form id="liveIntakeTicketEditorForm" class="lir-modal-card is-ticket-editor" role="dialog" aria-modal="true" aria-labelledby="liveIntakeTicketEditorTitle">
      <header>
        <div><p>Ticket registrado</p><h2 id="liveIntakeTicketEditorTitle">Cargando ticket…</h2><small id="liveIntakeTicketEditorOwner">Propietario: Mi empresa · fijo</small><small id="liveIntakeTicketEditorClient">Espere un momento</small></div>
        <button type="button" data-live-close-ticket-editor aria-label="Cerrar editor de ticket">×</button>
      </header>
      <section id="liveIntakeTicketEditorSummary" class="lir-ticket-editor-summary"></section>
      <section class="lir-ticket-editor-content">
        <p id="liveIntakeTicketEditorHelp" class="lir-modal-help">Puedes corregir todas las pesadas y guardarlas juntas. El cliente se conserva para mantener la trazabilidad del despacho.</p>
        <div id="liveIntakeTicketEditorRows" class="lir-ticket-editor-rows"><p class="lir-client-empty">Cargando pesadas…</p></div>
        <label class="lir-ticket-correction-reason"><span>Motivo de la corrección</span><input id="liveIntakeTicketEditReason" type="text" minlength="3" maxlength="250" required placeholder="Ej. Corrección del ticket completo"></label>
      </section>
      <p id="liveIntakeTicketEditorMessage" class="lir-message" role="status" aria-live="polite"></p>
      <footer>
        <button id="liveIntakePrintTicket" type="button" disabled>Imprimir ticket</button>
        <button id="liveIntakeCancelTicket" type="button" data-live-close-ticket-editor>Cancelar</button>
        <button id="liveIntakeSaveTicket" class="is-primary" type="submit" disabled>Actualizar ticket completo</button>
      </footer>
    </form>
  </div>

  <div id="liveIntakeScaleSettingsModal" class="lir-modal" hidden>
    <form id="liveIntakeScaleSettingsForm" class="lir-modal-card is-scale-modal" role="dialog" aria-modal="true" aria-labelledby="liveIntakeScaleSettingsTitle">
      <header>
        <div><p>Balanza de recepción</p><h2 id="liveIntakeScaleSettingsTitle">Configuración de balanza</h2></div>
        <button type="button" data-live-close-scale-settings aria-label="Cerrar configuración de balanza">×</button>
      </header>

      <section>
        <h3>Conexión</h3>
        <p class="lir-modal-help">Conecta la única balanza de esta estación. La autorización y los parámetros quedan guardados en esta tablet.</p>
        <div class="lir-scale-actions">
          <button id="liveIntakeConnectBle" type="button">Conectar BLE</button>
          <button id="liveIntakeConnectSerial" type="button">Conectar serial</button>
          <button id="liveIntakeDisconnectScale" class="is-danger" type="button" disabled>Desconectar</button>
        </div>
      </section>

      <section>
        <h3>Parámetros de conexión serial</h3>
        <div class="lir-settings-grid is-scale">
          <label><span>Baudios</span><input id="liveIntakeBaudRate" type="number" min="300" step="1" value="9600"></label>
          <label><span>Bits</span><select id="liveIntakeDataBits"><option value="8">8</option><option value="7">7</option></select></label>
          <label><span>Parada</span><select id="liveIntakeStopBits"><option value="1">1</option><option value="2">2</option></select></label>
          <label><span>Paridad</span><select id="liveIntakeParity"><option value="none">Ninguna</option><option value="even">Par</option><option value="odd">Impar</option></select></label>
          <label><span>Flujo</span><select id="liveIntakeFlowControl"><option value="none">Ninguno</option><option value="hardware">Hardware</option></select></label>
        </div>
      </section>

      <p id="liveIntakeScaleSettingsMessage" class="lir-message" role="status" aria-live="polite"></p>
      <footer><button type="button" data-live-close-scale-settings>Cancelar</button><button class="is-primary" type="submit">Guardar y cerrar</button></footer>
    </form>
  </div>

  <div id="liveIntakeManualWeightModal" class="lir-modal" hidden>
    <form id="liveIntakeManualWeightForm" class="lir-modal-card is-compact" role="dialog" aria-modal="true" aria-labelledby="liveIntakeManualWeightTitle">
      <header>
        <div><p>Captura alternativa</p><h2 id="liveIntakeManualWeightTitle">Colocar peso manual</h2></div>
        <button type="button" data-live-close-manual-weight aria-label="Cerrar peso manual">×</button>
      </header>

      <section>
        <label class="lir-manual-weight-field">
          <span>Peso leído en kilogramos</span>
          <input id="liveIntakeManualWeight" type="number" min="0.001" step="0.001" inputmode="decimal" placeholder="0.000" autocomplete="off">
        </label>
        <p class="lir-modal-help">Este peso se mantendrá aunque la balanza esté conectada. Al agregarlo a una columna, volverá a mostrarse la lectura de la balanza.</p>
      </section>

      <p id="liveIntakeManualWeightMessage" class="lir-message" role="status" aria-live="polite"></p>
      <footer><button type="button" data-live-close-manual-weight>Cancelar</button><button id="liveIntakeApplyManualWeight" class="is-primary" type="submit">Aplicar peso</button></footer>
    </form>
  </div>

  <aside id="liveIntakeTypographyPanel" class="lir-typography-panel" role="dialog" aria-modal="false" aria-hidden="true" aria-labelledby="liveIntakeTypographyTitle" hidden>
    <header class="lir-typography-head">
      <div><p>Recepción de pollo vivo</p><h2 id="liveIntakeTypographyTitle">Tamaños de texto</h2></div>
      <button type="button" data-font-close aria-label="Cerrar tamaños de texto">×</button>
    </header>
    <section class="lir-typography-toolbar">
      <div class="lir-typography-state">
        <span id="liveIntakeTypographyProfile">Original</span>
        <span id="liveIntakeTypographySaveStatus" role="status" aria-live="polite">Guardado en este navegador</span>
      </div>
      <p>Arrastra una barra o escribe el tamaño en píxeles. Verás el cambio al instante. El zoom y los datos de las pesadas se conservan.</p>
      <div class="lir-typography-preview">
        <span id="liveIntakeTypographyPreviewLabel">Vista previa</span>
        <small id="liveIntakeTypographyPreviewSize"></small>
        <div><strong id="liveIntakeTypographyPreviewValue">Aa 123,45</strong></div>
      </div>
      <label class="lir-typography-search" for="liveIntakeTypographySearch">
        Buscar texto o sección
        <input id="liveIntakeTypographySearch" type="search" placeholder="Ej.: balanza, columna 1, javas…" autocomplete="off">
      </label>
      <div class="lir-typography-presets" aria-label="Tamaños para toda la vista">
        <button type="button" data-font-preset="compact" aria-pressed="false">Compacta</button>
        <button type="button" data-font-preset="standard" aria-pressed="true">Original</button>
        <button type="button" data-font-preset="large" aria-pressed="false">Grande</button>
        <button type="button" data-font-preset="accessible" aria-pressed="false">Muy grande</button>
      </div>
      <div class="lir-typography-group-tools">
        <button type="button" data-font-expand="all">Abrir grupos</button>
        <button type="button" data-font-expand="none">Cerrar grupos</button>
      </div>
    </section>
    <div id="liveIntakeTypographyControls" class="lir-typography-controls"></div>
    <footer class="lir-typography-foot">
      <button type="button" data-font-reset-all>Restablecer todos los tamaños</button>
      <button type="button" data-font-close>Listo</button>
    </footer>
  </aside>

  <script type="module" src="{{ asset('js/recepcion-pollo-vivo.js') }}?v={{ filemtime(public_path('js/recepcion-pollo-vivo.js')) }}"></script>
</body>
</html>
