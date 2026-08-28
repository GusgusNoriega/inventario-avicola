<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Despacho mayorista 2 | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/despacho-mayorista-2.css') }}?v={{ filemtime(public_path('css/despacho-mayorista-2.css')) }}">
</head>
<body class="operation-touch-page">
  <section class="scale-strip-mobile card" aria-label="Pesos de balanzas">
    <article class="scale-strip-item">
      <span>Balanza 1</span>
      <strong id="display-scale-mini-1">---</strong>
    </article>
    <article class="scale-strip-item">
      <span>Balanza 2</span>
      <strong id="display-scale-mini-2">---</strong>
    </article>
  </section>

  <nav class="mobile-tabs" aria-label="Navegación móvil">
    <button id="mobileTabRegistro" class="mobile-tab is-active" type="button" data-mobile-panel-target="registro">Registro</button>
    <button id="mobileTabCamiones" class="mobile-tab" type="button" data-mobile-panel-target="camiones">Tickets</button>
  </nav>

  <main id="appShell" class="app-shell" data-mobile-panel="registro">
    <section class="control-panel" data-mobile-panel="registro">
      <header class="hero card">
        <div class="admin-config">
          <button id="configMenuBtn" class="config-menu-btn" type="button" aria-label="Abrir configuración" aria-haspopup="dialog" aria-expanded="false" aria-controls="configMenu">
            <span aria-hidden="true">&#9881;</span>
          </button>
          <div id="configMenu" class="config-menu" role="dialog" aria-label="Configuración del panel" hidden>
            <div class="config-menu-head">
              <strong>Configuración</strong>
              <button id="closeConfigMenuBtn" class="config-close-btn" type="button" aria-label="Cerrar configuración">X</button>
            </div>
            <div class="config-menu-actions">
              <button id="openWeightAdjustmentSettingsBtn" class="btn btn-ghost" type="button">Configurar mermas</button>
              <button id="openJsonBtn" class="btn btn-ghost" type="button">Ver JSON</button>
              <button id="resetDayBtn" class="btn btn-ghost" type="button">Reiniciar jornada</button>
              <button id="openFontSidebarBtn" class="btn btn-ghost" type="button">Tamaños personalizados</button>
              <div class="view-zoom-tools" aria-labelledby="viewZoomLabel">
                <div class="view-zoom-heading">
                  <span id="viewZoomLabel">Zoom de la vista</span>
                  <small>Guardado en este navegador</small>
                </div>
                <div class="view-zoom-actions" role="group" aria-label="Ajustar zoom de la vista">
                  <button id="viewZoomDecreaseBtn" class="view-zoom-btn" type="button" aria-label="Reducir zoom">&minus;</button>
                  <output id="viewZoomStatus" for="viewZoomDecreaseBtn viewZoomIncreaseBtn" aria-live="polite">100 %</output>
                  <button id="viewZoomIncreaseBtn" class="view-zoom-btn" type="button" aria-label="Aumentar zoom">+</button>
                  <button id="viewZoomResetBtn" class="view-zoom-btn view-zoom-reset" type="button" aria-label="Restablecer zoom al 100 %" title="Restablecer al 100 %">100 %</button>
                </div>
                <small class="view-zoom-help">Niveles disponibles: 67 % a 150 %.</small>
              </div>
              <div class="font-tools" aria-label="Tamaño de letra">
                <span>Letra</span>
                <div class="font-tools-actions">
                  <button id="fontDecreaseBtn" class="font-size-btn" type="button" aria-label="Reducir tamaño de letra">A-</button>
                  <strong id="fontSizeStatus" aria-live="polite">Normal</strong>
                  <button id="fontIncreaseBtn" class="font-size-btn" type="button" aria-label="Aumentar tamaño de letra">A+</button>
                  <button id="fontResetBtn" class="font-size-btn font-size-reset" type="button" aria-label="Restablecer tamaño de letra">A</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div>
          <p class="eyebrow">Despacho mayorista 2</p>
          <h1>Recepción y despacho de pollos</h1>
          <p class="hero-copy">Recepción con origen mayorista o despacho directo sin proveedor. Registro simultáneo con 2 balanzas.</p>
        </div>
        <div class="hero-actions">
          <div class="hero-menu-row">
            <a id="openCustomerDisplayBtn" class="menu-return-btn customer-display-link" href="{{ route('despacho-mayorista-2.pantalla-cliente') }}" target="pantalla-cliente-mayorista-2">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="3" y="4" width="18" height="13" rx="2"></rect>
                <path d="M8 21h8M12 17v4"></path>
              </svg>
              <span>Pantalla cliente</span>
            </a>
            <a id="backToMenuBtn" class="menu-return-btn" href="{{ route('menu') }}">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M4 6h7v7H4z"></path>
                <path d="M13 6h7v7h-7z"></path>
                <path d="M4 15h7v3H4z"></path>
                <path d="M13 15h7v3h-7z"></path>
              </svg>
              <span>Menú</span>
            </a>
          </div>
        </div>
      </header>

      <section class="scale-section">
        <article class="scale-card card" data-scale-card="1">
          <div class="scale-card-head">
            <h2>Balanza 1</h2>
            <button id="openScaleSettings1" class="scale-settings-btn" type="button" aria-label="Configurar balanza 1" aria-haspopup="dialog" aria-expanded="false" aria-controls="scaleSettingsModal1">
              <span aria-hidden="true">&#9881;</span>
            </button>
          </div>
          <p class="scale-display" id="display-scale-1">---</p>
          <button id="capture-scale-1" class="btn btn-secondary" type="button" disabled>Seleccionar balanza 1</button>
        </article>

        <article class="scale-card card" data-scale-card="2">
          <div class="scale-card-head">
            <h2>Balanza 2</h2>
            <button id="openScaleSettings2" class="scale-settings-btn" type="button" aria-label="Configurar balanza 2" aria-haspopup="dialog" aria-expanded="false" aria-controls="scaleSettingsModal2">
              <span aria-hidden="true">&#9881;</span>
            </button>
          </div>
          <p class="scale-display" id="display-scale-2">---</p>
          <button id="capture-scale-2" class="btn btn-secondary" type="button" disabled>Seleccionar balanza 2</button>
        </article>
      </section>

      <section class="entry-section card">
        <div class="section-head">
          <h2>Registrar Pesada</h2>
        </div>

        <form id="cageForm" novalidate>
          <div class="type-switch" role="group" aria-label="Familia de producto">
            <button class="type-btn is-active" data-type="pollo_vivo" type="button">Pollo vivo</button>
            <button class="type-btn" data-type="pollo_pelado" type="button">Pollo pelado</button>
            <button class="type-btn" data-type="gallina" type="button">Gallina</button>
            <button class="type-btn" data-type="otros" type="button">Otros</button>
          </div>

          <div class="form-grid">
            <div class="dispatch-primary-row">
              <label class="field dispatch-truck-field">
                Ticket
                <select id="truckSelect" data-touch-label="Ticket"></select>
              </label>

              <div class="selected-origin-summary">
                <span>Origen opcional</span>
                <strong id="selectedProviderName">Despacho directo</strong>
                <small id="selectedProviderPlateLabel">Sin proveedor ni placa</small>
              </div>

              <label class="field dispatch-quantity-field">
                <span title="Aves por java o total sin javas">Aves/J.</span>
                <input id="birdCount" type="number" min="1" step="1" placeholder="Ej: 7" required readonly inputmode="none" data-keypad-label="Aves por java o total sin javas" data-keypad-decimal="false">
              </label>

              <label class="field dispatch-quantity-field">
                <span title="Cantidad de javas">Javas</span>
                <input id="javaCount" type="number" min="0" step="1" value="1" required readonly inputmode="none" data-keypad-label="Javas" data-keypad-decimal="false">
              </label>

              <fieldset id="liveSexSelector" class="sex-selector dispatch-sex-selector" aria-label="Sexo de los pollos vivos">
                <legend>Sexo</legend>
                <div class="sex-selector-buttons">
                  <button class="sex-btn sex-btn-male is-active" type="button" data-sex="macho" aria-pressed="true" aria-label="Macho" title="Macho">M</button>
                  <button class="sex-btn sex-btn-female" type="button" data-sex="hembra" aria-pressed="false" aria-label="Hembra" title="Hembra">H</button>
                </div>
              </fieldset>

              <fieldset id="henVariantSelector" class="hen-variant-selector dispatch-hen-variant-selector" aria-label="Tipo de gallina" hidden>
                <legend>Tipo de gallina</legend>
                <div class="hen-variant-buttons">
                  <button class="hen-variant-btn is-red is-active" type="button" data-hen-variant="GALLINA_ROJA" aria-pressed="true">Roja</button>
                  <button class="hen-variant-btn is-double" type="button" data-hen-variant="GALLINA_DOBLE" aria-pressed="false">Doble</button>
                </div>
              </fieldset>

              <fieldset id="dressedVariantSelector" class="dressed-variant-selector dispatch-dressed-variant-selector" aria-label="Clasificación del pollo pelado" hidden>
                <legend>Clasificación del pollo pelado</legend>
                <div class="dressed-variant-buttons">
                  <button class="dressed-variant-btn is-male is-open is-active" type="button" data-dressed-variant="MACHO_ABIERTO" aria-pressed="true">Macho abierto</button>
                  <button class="dressed-variant-btn is-male is-closed" type="button" data-dressed-variant="MACHO_CERRADO" aria-pressed="false">Macho cerrado</button>
                  <button class="dressed-variant-btn is-female is-open" type="button" data-dressed-variant="HEMBRA_ABIERTA" aria-pressed="false">Hembra abierta</button>
                  <button class="dressed-variant-btn is-female is-closed" type="button" data-dressed-variant="HEMBRA_CERRADA" aria-pressed="false">Hembra cerrada</button>
                  <button class="dressed-variant-btn is-processed" type="button" data-dressed-variant="POLLO_BENEFICIADO" aria-pressed="false">Pollo beneficiado</button>
                </div>
              </fieldset>

              <p id="henPricePreview" class="hen-price-preview" role="status" aria-live="polite" hidden></p>
            </div>

            <div class="entry-origin-controls" aria-hidden="true">
              <button id="selectProviderBtn" type="button" tabindex="-1">Seleccionar origen</button>
              <label id="truckPlateField">
                Placa del camión de origen
                <select id="truckPlate" required tabindex="-1">
                  <option value="">Selecciona primero un proveedor</option>
                </select>
                <small id="truckPlateHelp">Selecciona una placa activa asignada al proveedor.</small>
              </label>
            </div>

            <label class="field">
              Tipo de java
              <select id="crateType" data-touch-label="Tipo de java">
                <option value="java_700">Java 7.00 kg</option>
                <option value="java_690">Java 6.90 kg</option>
                <option value="java_680">Java 6.80 kg</option>
              </select>
            </label>

            <label class="field">
              Balanza / peso
              <select id="weightSource" data-touch-label="Balanza o peso manual">
                <option value="1">Balanza 1</option>
                <option value="2">Balanza 2</option>
                <option value="manual">Manual</option>
              </select>
            </label>

            <label class="field" id="manualWeightField" hidden>
              Peso leído manual (kg)
              <input id="manualWeight" type="number" min="0" step="0.001" placeholder="Ej: 49.500" readonly inputmode="none" data-keypad-label="Peso leído manual (kg)">
            </label>

            <div class="weight-preview weight-preview-gross">
              <span>Peso neto a registrar</span>
              <strong id="selectedWeightValue">---</strong>
              <small id="selectedWeightBreakdown"></small>
            </div>

            <button id="addWeighingBtn" class="btn btn-success weighing-submit-button" type="submit" disabled>Capturar peso</button>
          </div>

          <div class="form-actions">
            <p id="formMessage" class="form-message" role="status" aria-live="polite"></p>
          </div>
        </form>
      </section>

      <button id="returnTicketBtn" class="return-ticket-btn" type="button">Cambiar a devolución</button>

      <section class="daily-provider-panel card" aria-label="Origen opcional de las pesadas">
        <div class="daily-provider-head">
          <span class="daily-provider-title">Origen opcional</span>
          <div class="daily-provider-actions">
            @if (auth()->user()->hasModule('MODULO_JORNADA_PROVEEDORES'))
              <a class="btn btn-ghost" href="{{ route('jornada') }}">Configurar jornada</a>
            @endif
            <strong id="dailyProviderCount" class="daily-provider-count">0</strong>
          </div>
        </div>
        <div id="dailyProviderList" class="daily-provider-list" role="listbox" aria-label="Camiones disponibles para la jornada"></div>
      </section>
    </section>

    <section class="columns-section card" data-mobile-panel="camiones">
      <div class="section-head">
        <p id="globalStats" class="global-stats"></p>
      </div>
      <div id="trucksGrid" class="truck-grid"></div>
      <aside id="selectedTruckDetails" class="selected-truck-details" aria-live="polite"></aside>
    </section>
  </main>

  <div id="fontSidebarOverlay" class="font-sidebar-overlay" hidden>
    <aside id="fontSizeSidebar" class="font-size-sidebar card" role="dialog" aria-modal="true" aria-labelledby="fontSizeSidebarTitle">
      <div class="font-sidebar-head">
        <div>
          <p class="font-sidebar-caption">Ajuste por sección</p>
          <h2 id="fontSizeSidebarTitle">Tamaños personalizados</h2>
        </div>
        <button id="closeFontSidebarBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <div id="fontSizeControls" class="font-size-controls"></div>
      <div class="font-sidebar-footer">
        <button id="resetFontSizesBtn" class="btn btn-ghost" type="button">Restablecer tamaños</button>
      </div>
    </aside>
  </div>

  <div id="weightAdjustmentSettingsModal" class="modal weight-adjustment-settings-modal" hidden>
    <form id="weightAdjustmentSettingsForm" class="weight-adjustment-settings-card card" role="dialog" aria-modal="true" aria-labelledby="weightAdjustmentSettingsTitle">
      <div class="section-head weight-adjustment-settings-head">
        <div>
          <p class="weight-adjustment-settings-caption">Configuración exclusiva de Despacho mayorista 2</p>
          <h2 id="weightAdjustmentSettingsTitle">Mermas por tipo de producto</h2>
        </div>
        <button id="closeWeightAdjustmentSettingsBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>

      <p class="weight-adjustment-settings-help">Los gramos se suman por cada ave al peso leído antes de descontar la tara de las javas. Las pesadas pendientes se recalculan al guardar.</p>

      <div id="weightAdjustmentSettingsGrid" class="weight-adjustment-settings-grid">
        <article class="weight-adjustment-setting is-live">
          <strong>PV-M · Pollo vivo macho</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="MACHO" readonly inputmode="none" data-keypad-label="Merma en gramos para pollo vivo macho" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-live">
          <strong>PV-H · Pollo vivo hembra</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="HEMBRA" readonly inputmode="none" data-keypad-label="Merma en gramos para pollo vivo hembra" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-male">
          <strong>MA · Macho abierto</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="MACHO_ABIERTO" readonly inputmode="none" data-keypad-label="Merma en gramos para macho abierto" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-male">
          <strong>MC · Macho cerrado</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="MACHO_CERRADO" readonly inputmode="none" data-keypad-label="Merma en gramos para macho cerrado" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-female">
          <strong>HA · Hembra abierta</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="HEMBRA_ABIERTA" readonly inputmode="none" data-keypad-label="Merma en gramos para hembra abierta" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-female">
          <strong>HC · Hembra cerrada</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="HEMBRA_CERRADA" readonly inputmode="none" data-keypad-label="Merma en gramos para hembra cerrada" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-hen is-hen-red">
          <strong>GR · Gallina roja</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="GALLINA_ROJA" readonly inputmode="none" data-keypad-label="Merma en gramos para gallina roja" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-hen is-hen-double">
          <strong>GD · Gallina doble</strong>
          <label><span>Gramos por ave</span><input type="number" min="0" max="1000000" step="1" value="0" data-weight-adjustment-variant="GALLINA_DOBLE" readonly inputmode="none" data-keypad-label="Merma en gramos para gallina doble" data-keypad-decimal="false"></label>
        </article>
        <article class="weight-adjustment-setting is-processed is-locked" aria-disabled="true">
          <strong>PB · Pollo beneficiado</strong>
          <label><span>Gramos por ave</span><input type="number" value="0" disabled aria-describedby="processedWeightAdjustmentHelp"></label>
          <small id="processedWeightAdjustmentHelp">Sin merma por regla del sistema.</small>
        </article>
        <article class="weight-adjustment-setting is-other is-locked" aria-disabled="true">
          <strong>OT · Otros</strong>
          <label><span>Gramos por producto</span><input type="number" value="0" disabled aria-describedby="otherWeightAdjustmentHelp"></label>
          <small id="otherWeightAdjustmentHelp">Otros se pesa sin opciones adicionales ni merma.</small>
        </article>
      </div>

      <p id="weightAdjustmentSettingsMessage" class="weight-adjustment-settings-message" role="status" aria-live="polite"></p>
      <div class="weight-adjustment-settings-actions">
        <button id="cancelWeightAdjustmentSettingsBtn" class="btn btn-ghost" type="button">Cancelar</button>
        <button id="saveWeightAdjustmentSettingsBtn" class="btn btn-success" type="submit">Guardar mermas</button>
      </div>
    </form>
  </div>

  <div id="scaleSettingsModal1" class="modal" hidden>
    <div class="scale-settings-card card" role="dialog" aria-modal="true" aria-labelledby="scaleSettingsTitle1">
      <div class="section-head">
        <h2 id="scaleSettingsTitle1">Configurar Balanza 1</h2>
        <button id="closeScaleSettings1" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <div class="scale-settings-content">
        <section class="scale-settings-panel" aria-labelledby="scaleSyncTitle1">
          <h3 id="scaleSyncTitle1">Sincronizar balanza</h3>
          <div class="scale-connection" data-scale-connection="1">
            <div class="scale-status-row">
              <span id="scale-status-1" class="scale-status scale-status-offline">Sin conexión Bluetooth</span>
              <small id="scale-last-1">Sin lecturas</small>
            </div>
            <div class="scale-connect-actions">
              <button id="connect-ble-scale-1" class="btn btn-ghost" type="button">Conectar BLE</button>
              <button id="connect-serial-scale-1" class="btn btn-ghost" type="button">Serial BT</button>
              <button id="disconnect-scale-1" class="btn btn-ghost" type="button" disabled>Desconectar</button>
            </div>
            <small id="scale-raw-1" class="scale-raw">Trama: --</small>
          </div>
        </section>

        <section class="scale-settings-panel" aria-labelledby="scaleSerialTitle1">
          <h3 id="scaleSerialTitle1">Parámetros seriales de esta balanza</h3>
          <div class="form-grid">
            <label class="field" for="serial-baud-scale-1">
              Baudios
              <input id="serial-baud-scale-1" type="number" min="300" max="921600" step="1" value="9600" readonly inputmode="none" data-keypad-label="Baudios balanza 1" data-keypad-decimal="false">
            </label>
            <label class="field" for="serial-data-bits-scale-1">
              Bits de datos
              <select id="serial-data-bits-scale-1" data-touch-label="Bits de datos balanza 1"><option value="8">8</option><option value="7">7</option></select>
            </label>
            <label class="field" for="serial-stop-bits-scale-1">
              Bits de parada
              <select id="serial-stop-bits-scale-1" data-touch-label="Bits de parada balanza 1"><option value="1">1</option><option value="2">2</option></select>
            </label>
            <label class="field" for="serial-parity-scale-1">
              Paridad
              <select id="serial-parity-scale-1" data-touch-label="Paridad balanza 1"><option value="none">Ninguna</option><option value="even">Par</option><option value="odd">Impar</option></select>
            </label>
            <label class="field" for="serial-flow-scale-1">
              Control de flujo
              <select id="serial-flow-scale-1" data-touch-label="Control de flujo balanza 1"><option value="none">Ninguno</option><option value="hardware">Hardware</option></select>
            </label>
          </div>
          <button id="save-serial-scale-1" class="btn btn-ghost" type="button">Guardar parámetros seriales</button>
        </section>

        <section class="scale-settings-panel" aria-labelledby="scaleManualTitle1">
          <h3 id="scaleManualTitle1">Agregar valor manual</h3>
          <div class="field">
            <label for="input-scale-1">Lectura manual (kg)</label>
            <div class="inline-control">
              <input id="input-scale-1" type="number" min="0" step="0.01" placeholder="Ej: 54.80" required readonly inputmode="none" data-keypad-label="Lectura manual balanza 1 (kg)">
              <button id="set-scale-1" class="btn btn-primary" type="button">Actualizar</button>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>

  <div id="scaleSettingsModal2" class="modal" hidden>
    <div class="scale-settings-card card" role="dialog" aria-modal="true" aria-labelledby="scaleSettingsTitle2">
      <div class="section-head">
        <h2 id="scaleSettingsTitle2">Configurar Balanza 2</h2>
        <button id="closeScaleSettings2" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <div class="scale-settings-content">
        <section class="scale-settings-panel" aria-labelledby="scaleSyncTitle2">
          <h3 id="scaleSyncTitle2">Sincronizar balanza</h3>
          <div class="scale-connection" data-scale-connection="2">
            <div class="scale-status-row">
              <span id="scale-status-2" class="scale-status scale-status-offline">Sin conexión Bluetooth</span>
              <small id="scale-last-2">Sin lecturas</small>
            </div>
            <div class="scale-connect-actions">
              <button id="connect-ble-scale-2" class="btn btn-ghost" type="button">Conectar BLE</button>
              <button id="connect-serial-scale-2" class="btn btn-ghost" type="button">Serial BT</button>
              <button id="disconnect-scale-2" class="btn btn-ghost" type="button" disabled>Desconectar</button>
            </div>
            <small id="scale-raw-2" class="scale-raw">Trama: --</small>
          </div>
        </section>

        <section class="scale-settings-panel" aria-labelledby="scaleSerialTitle2">
          <h3 id="scaleSerialTitle2">Parámetros seriales de esta balanza</h3>
          <div class="form-grid">
            <label class="field" for="serial-baud-scale-2">
              Baudios
              <input id="serial-baud-scale-2" type="number" min="300" max="921600" step="1" value="9600" readonly inputmode="none" data-keypad-label="Baudios balanza 2" data-keypad-decimal="false">
            </label>
            <label class="field" for="serial-data-bits-scale-2">
              Bits de datos
              <select id="serial-data-bits-scale-2" data-touch-label="Bits de datos balanza 2"><option value="8">8</option><option value="7">7</option></select>
            </label>
            <label class="field" for="serial-stop-bits-scale-2">
              Bits de parada
              <select id="serial-stop-bits-scale-2" data-touch-label="Bits de parada balanza 2"><option value="1">1</option><option value="2">2</option></select>
            </label>
            <label class="field" for="serial-parity-scale-2">
              Paridad
              <select id="serial-parity-scale-2" data-touch-label="Paridad balanza 2"><option value="none">Ninguna</option><option value="even">Par</option><option value="odd">Impar</option></select>
            </label>
            <label class="field" for="serial-flow-scale-2">
              Control de flujo
              <select id="serial-flow-scale-2" data-touch-label="Control de flujo balanza 2"><option value="none">Ninguno</option><option value="hardware">Hardware</option></select>
            </label>
          </div>
          <button id="save-serial-scale-2" class="btn btn-ghost" type="button">Guardar parámetros seriales</button>
        </section>

        <section class="scale-settings-panel" aria-labelledby="scaleManualTitle2">
          <h3 id="scaleManualTitle2">Agregar valor manual</h3>
          <div class="field">
            <label for="input-scale-2">Lectura manual (kg)</label>
            <div class="inline-control">
              <input id="input-scale-2" type="number" min="0" step="0.01" placeholder="Ej: 61.30" required readonly inputmode="none" data-keypad-label="Lectura manual balanza 2 (kg)">
              <button id="set-scale-2" class="btn btn-primary" type="button">Actualizar</button>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>

  <div id="jsonModal" class="modal" hidden>
    <div class="modal-card card" role="dialog" aria-modal="true" aria-labelledby="jsonModalTitle">
      <div class="section-head">
        <h2 id="jsonModalTitle">Estado JSON (Base para API)</h2>
        <div class="modal-actions">
          <button id="copyJsonBtn" class="btn btn-ghost" type="button">Copiar JSON</button>
          <button id="closeJsonBtn" class="btn btn-primary" type="button">Cerrar</button>
        </div>
      </div>
      <pre id="jsonOutput" class="json-output"></pre>
    </div>
  </div>

  <div id="clientModal" class="modal" hidden>
    <div class="client-modal-card card" role="dialog" aria-modal="true" aria-labelledby="clientModalTitle">
      <div class="section-head">
        <h2 id="clientModalTitle">Asignar destino</h2>
        <button id="closeClientModalBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <p id="clientModalTruckLabel" class="client-modal-truck">--</p>
      <label class="client-search">
        <span>Buscar cliente o almacén</span>
        <input id="clientSearch" type="search" placeholder="Escribe un nombre o almacén..." autocomplete="off" maxlength="120" readonly inputmode="none" data-touch-keyboard="text" data-touch-keyboard-label="Buscar cliente o almacén">
      </label>
      <div id="clientList" class="client-list" role="listbox" aria-label="Lista de clientes y almacenes"></div>
    </div>
  </div>

  <div id="deliveryTruckModal" class="modal delivery-fleet-modal" hidden>
    <div class="delivery-fleet-card card" role="dialog" aria-modal="true" aria-labelledby="deliveryTruckModalTitle">
      <div class="section-head">
        <div>
          <p class="delivery-fleet-caption">Paso 1 de 2 · Entrega</p>
          <h2 id="deliveryTruckModalTitle">Seleccionar camión</h2>
        </div>
        <button id="closeDeliveryTruckModalBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <p id="deliveryTruckTicketLabel" class="client-modal-truck">--</p>
      <label class="client-search">
        <span>Buscar en mi flota</span>
        <input id="deliveryTruckSearch" type="search" placeholder="Placa, marca o modelo..." autocomplete="off" maxlength="120" readonly inputmode="none" data-touch-keyboard="text" data-touch-keyboard-label="Buscar camión de entrega">
      </label>
      <div id="deliveryTruckList" class="delivery-fleet-list" role="listbox" aria-label="Camiones propios disponibles"></div>
    </div>
  </div>

  <div id="deliveryDriverModal" class="modal delivery-fleet-modal" hidden>
    <div class="delivery-fleet-card card" role="dialog" aria-modal="true" aria-labelledby="deliveryDriverModalTitle">
      <div class="section-head">
        <div>
          <p class="delivery-fleet-caption">Paso 2 de 2 · Entrega</p>
          <h2 id="deliveryDriverModalTitle">Seleccionar chofer</h2>
        </div>
        <button id="closeDeliveryDriverModalBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <p id="deliveryDriverTicketLabel" class="client-modal-truck">--</p>
      <label class="client-search">
        <span>Buscar en mis choferes</span>
        <input id="deliveryDriverSearch" type="search" placeholder="Nombre o documento..." autocomplete="off" maxlength="120" readonly inputmode="none" data-touch-keyboard="text" data-touch-keyboard-label="Buscar chofer de entrega">
      </label>
      <div id="deliveryDriverList" class="delivery-fleet-list" role="listbox" aria-label="Choferes disponibles"></div>
    </div>
  </div>

  <div id="itemModal" class="modal" hidden>
    <div class="item-modal-card card" role="dialog" aria-modal="true" aria-labelledby="itemModalTitle">
      <div class="section-head">
        <h2 id="itemModalTitle">Detalle de registro</h2>
        <button id="closeItemModalBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>

      <div class="item-highlight">
        <div class="item-pill">
          <span>Registro</span>
          <strong id="itemCageNumber">#--</strong>
        </div>
        <div class="item-pill">
          <span>Ticket de despacho</span>
          <strong id="itemTruckName">--</strong>
        </div>
        <div class="item-pill">
          <span>Hora</span>
          <strong id="itemHour">--</strong>
        </div>
      </div>

      <form id="itemEditForm" class="item-form" novalidate>
        <div class="item-form-grid">
          <label class="field">
            Familia de pollo
            <select id="editType"></select>
          </label>

          <label class="field">
            Aves / java (o total sin javas)
            <input id="editBirdCount" type="number" min="1" step="1" required readonly inputmode="none" data-keypad-label="Aves por java o total sin javas" data-keypad-decimal="false">
          </label>

          <label class="field">
            Javas
            <input id="editJavaCount" type="number" min="0" step="1" required readonly inputmode="none" data-keypad-label="Javas" data-keypad-decimal="false">
          </label>

          <fieldset id="editLiveSexSelector" class="sex-selector edit-sex-selector" aria-label="Sexo de los pollos vivos">
            <legend>Sexo</legend>
            <div class="sex-selector-buttons">
              <button class="sex-btn sex-btn-male is-active" type="button" data-edit-sex="macho" aria-pressed="true">Macho</button>
              <button class="sex-btn sex-btn-female" type="button" data-edit-sex="hembra" aria-pressed="false">Hembra</button>
            </div>
          </fieldset>

          <fieldset id="editHenVariantSelector" class="hen-variant-selector edit-hen-variant-selector" aria-label="Tipo de gallina" hidden>
            <legend>Tipo de gallina</legend>
            <div class="hen-variant-buttons">
              <button class="hen-variant-btn is-red is-active" type="button" data-edit-hen-variant="GALLINA_ROJA" aria-pressed="true">Gallina roja</button>
              <button class="hen-variant-btn is-double" type="button" data-edit-hen-variant="GALLINA_DOBLE" aria-pressed="false">Gallina doble</button>
            </div>
          </fieldset>

          <fieldset id="editDressedVariantSelector" class="dressed-variant-selector edit-dressed-variant-selector" aria-label="Clasificación del pollo pelado" hidden>
            <legend>Clasificación del pollo pelado</legend>
            <div class="dressed-variant-buttons">
              <button class="dressed-variant-btn is-male is-open is-active" type="button" data-edit-dressed-variant="MACHO_ABIERTO" aria-pressed="true">Macho abierto</button>
              <button class="dressed-variant-btn is-male is-closed" type="button" data-edit-dressed-variant="MACHO_CERRADO" aria-pressed="false">Macho cerrado</button>
              <button class="dressed-variant-btn is-female is-open" type="button" data-edit-dressed-variant="HEMBRA_ABIERTA" aria-pressed="false">Hembra abierta</button>
              <button class="dressed-variant-btn is-female is-closed" type="button" data-edit-dressed-variant="HEMBRA_CERRADA" aria-pressed="false">Hembra cerrada</button>
              <button class="dressed-variant-btn is-processed" type="button" data-edit-dressed-variant="POLLO_BENEFICIADO" aria-pressed="false">Pollo beneficiado</button>
            </div>
          </fieldset>

          <label class="field">
            Tipo de java
            <select id="editCrateType"></select>
          </label>

          <label class="field">
            Peso leído (kg)
            <input id="editWeight" type="number" min="0.001" step="0.001" required readonly inputmode="none" data-keypad-label="Peso leído (kg)">
          </label>

          <label class="field">
            Balanza / peso
            <select id="editWeightSource" data-touch-label="Balanza o peso manual">
              <option value="1">Balanza 1</option>
              <option value="2">Balanza 2</option>
              <option value="manual">Manual</option>
            </select>
          </label>

          <div id="editWeightBreakdown" class="weight-adjustment-preview" aria-live="polite">
            <span>Merma y peso neto</span>
            <strong>---</strong>
            <small>Selecciona los datos de la pesada.</small>
          </div>

          <div class="field">
            <span>Origen de la mercadería (opcional)</span>
            <button id="editSelectProviderBtn" class="provider-select-btn" type="button" aria-haspopup="dialog" aria-controls="providerModal">
              <span class="provider-select-copy">
                <small>Origen registrado en esta pesada</small>
                <strong id="editSelectedProviderName">Despacho directo · sin proveedor</strong>
              </span>
              <span class="provider-select-action">Cambiar</span>
            </button>
          </div>

          <label id="editTruckPlateField" class="field">
            Placa del camión de origen
            <select id="editTruckPlate" required>
              <option value="">Selecciona primero un proveedor</option>
            </select>
            <small id="editTruckPlateHelp" class="field-help">Selecciona una placa activa asignada al proveedor.</small>
          </label>
        </div>

        <p id="itemFormMessage" class="item-form-message" role="status" aria-live="polite"></p>

        <div class="item-form-actions">
          <button id="deleteItemBtn" class="btn btn-danger" type="button">Eliminar registro</button>
          <button class="btn btn-success" type="submit">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>

  <div id="providerModal" class="modal provider-modal" hidden>
    <div class="provider-modal-card card" role="dialog" aria-modal="true" aria-labelledby="providerModalTitle">
      <div class="section-head">
        <div>
          <p class="provider-modal-caption">Origen opcional de la mercadería</p>
          <h2 id="providerModalTitle">Seleccionar origen o despacho directo</h2>
        </div>
        <button id="closeProviderModalBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>

      <label class="field provider-search">
        Buscar proveedor o almacén
        <input id="providerSearch" type="search" placeholder="Nombre, almacén, DNI/RUC o dirección" autocomplete="off" maxlength="120" readonly inputmode="none" data-touch-keyboard="text" data-touch-keyboard-label="Buscar proveedor o almacén de origen">
      </label>

      <p id="providerModalSelection" class="provider-modal-selection">Despacho directo seleccionado: sin proveedor ni placa.</p>
      <div id="providerList" class="provider-list" role="listbox" aria-label="Lista de proveedores y almacenes"></div>
    </div>
  </div>

  <div id="specialPriceModal" class="modal special-price-modal" hidden>
    <form id="specialPriceForm" class="special-price-card card" role="dialog" aria-modal="true" aria-labelledby="specialPriceTitle" novalidate>
      <div class="section-head special-price-head">
        <div>
          <p class="special-price-caption">Precios exclusivos de este ticket</p>
          <h2 id="specialPriceTitle">Asignar precios de gallinas y otros</h2>
        </div>
        <button id="closeSpecialPriceBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>

      <p class="special-price-help">El precio guardado aquí pertenece únicamente al ticket y tiene prioridad sobre el precio opcional del cliente. No modificará los precios del directorio.</p>

      <div class="special-price-ticket">
        <span>Ticket seleccionado</span>
        <strong id="specialPriceTicketLabel">Ticket --</strong>
      </div>

      <div id="specialPriceFields" class="special-price-fields" aria-live="polite"></div>

      <p id="specialPriceMessage" class="special-price-message" role="status" aria-live="polite"></p>
      <div class="special-price-actions">
        <button id="cancelSpecialPriceBtn" class="btn btn-ghost" type="button">Cancelar</button>
        <button id="saveSpecialPriceBtn" class="btn btn-success" type="submit">Guardar precios</button>
      </div>
    </form>
  </div>

  <div id="errorModal" class="modal error-modal" hidden>
    <div class="error-modal-card card" role="alertdialog" aria-modal="true" aria-labelledby="errorModalTitle" aria-describedby="errorModalMessage">
      <div class="error-modal-head">
        <div>
          <p class="error-modal-caption">Error de registro</p>
          <h2 id="errorModalTitle">Revisa los datos</h2>
        </div>
        <button id="closeErrorModalBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <p id="errorModalMessage" class="error-modal-message"></p>
      <dl id="errorModalDetails" class="error-modal-details"></dl>
    </div>
  </div>

  <div id="touchSelectModal" class="modal touch-select-modal" hidden>
    <div class="touch-select-card card" role="dialog" aria-modal="true" aria-labelledby="touchSelectTitle">
      <div class="touch-select-head">
        <div>
          <p class="touch-select-caption">Selección táctil</p>
          <h2 id="touchSelectTitle">Seleccionar opción</h2>
        </div>
        <button id="touchSelectCloseBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>
      <div class="touch-select-current">
        <span>Opción actual</span>
        <strong id="touchSelectCurrentValue">--</strong>
      </div>
      <div id="touchSelectOptions" class="touch-select-options" role="listbox" aria-label="Opciones disponibles"></div>
    </div>
  </div>

  <div id="numericPadModal" class="modal numeric-pad-modal" hidden>
    <div class="numeric-pad-card card" role="dialog" aria-modal="true" aria-labelledby="numericPadTitle">
      <div class="numeric-pad-head">
        <div>
          <p class="numeric-pad-caption">Editando campo numérico</p>
          <h2 id="numericPadTitle">Campo</h2>
        </div>
        <button id="numericPadCloseBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>

      <p id="numericPadValue" class="numeric-pad-value" aria-live="polite">0</p>
      <p id="numericPadMessage" class="numeric-pad-message" role="status" aria-live="polite"></p>

      <div class="numeric-pad-grid">
        <button class="numeric-key" type="button" data-keypad-key="7">7</button>
        <button class="numeric-key" type="button" data-keypad-key="8">8</button>
        <button class="numeric-key" type="button" data-keypad-key="9">9</button>
        <button class="numeric-key" type="button" data-keypad-key="4">4</button>
        <button class="numeric-key" type="button" data-keypad-key="5">5</button>
        <button class="numeric-key" type="button" data-keypad-key="6">6</button>
        <button class="numeric-key" type="button" data-keypad-key="1">1</button>
        <button class="numeric-key" type="button" data-keypad-key="2">2</button>
        <button class="numeric-key" type="button" data-keypad-key="3">3</button>
        <button class="numeric-key" type="button" data-keypad-key="0">0</button>
        <button class="numeric-key" type="button" data-keypad-key="00">00</button>
        <button id="numericPadDotBtn" class="numeric-key" type="button" data-keypad-key="dot">.</button>
      </div>

      <div class="numeric-pad-actions">
        <button id="numericPadBackBtn" class="btn btn-ghost" type="button">Borrar</button>
        <button id="numericPadClearBtn" class="btn btn-ghost" type="button">Limpiar</button>
        <button id="numericPadOkBtn" class="btn btn-success" type="button">OK</button>
      </div>
    </div>
  </div>

  <aside id="textTouchKeyboard" class="text-touch-keyboard" hidden aria-hidden="true">
    <section class="text-touch-keyboard-card card" role="dialog" aria-labelledby="textTouchKeyboardTitle" aria-describedby="textTouchKeyboardValue">
      <header class="text-touch-keyboard-head">
        <div>
          <span>Teclado táctil</span>
          <strong id="textTouchKeyboardTitle">Ingresar texto</strong>
        </div>
        <output id="textTouchKeyboardValue">&nbsp;</output>
        <button type="button" data-text-keyboard-action="cancel" aria-label="Cancelar y cerrar teclado">×</button>
      </header>

      <div id="textTouchKeyboardKeys" class="text-touch-keyboard-keys" aria-label="Teclado español táctil"></div>

      <footer class="text-touch-keyboard-actions">
        <button type="button" data-text-keyboard-action="clear">Limpiar</button>
        <button type="button" data-text-keyboard-action="backspace">⌫ Borrar</button>
        <button type="button" class="is-accept" data-text-keyboard-action="accept">Aceptar</button>
      </footer>
    </section>
  </aside>

  <script type="module" src="{{ asset('js/despacho-mayorista-2.js') }}?v={{ filemtime(public_path('js/despacho-mayorista-2.js')) }}"></script>
</body>
</html>
