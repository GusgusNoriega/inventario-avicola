<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Entrada de Camiones | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
</head>
<body>
  <section class="scale-strip-mobile card" aria-label="Pesos de balanzas">
    <article class="scale-strip-item">
      <span>Balanza 1</span>
      <strong id="display-scale-mini-1">0.00 <small>kg</small></strong>
    </article>
    <article class="scale-strip-item">
      <span>Balanza 2</span>
      <strong id="display-scale-mini-2">0.00 <small>kg</small></strong>
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
              <button id="openJsonBtn" class="btn btn-ghost" type="button">Ver JSON</button>
              <button id="resetDayBtn" class="btn btn-ghost" type="button">Reiniciar jornada</button>
              <button id="openFontSidebarBtn" class="btn btn-ghost" type="button">Tamaños personalizados</button>
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
          <p class="eyebrow">Control de Recepción</p>
          <h1>Entrada de Camiones de Pollos</h1>
          <p class="hero-copy">Vista única para monitor táctil. Registro simultáneo con 2 balanzas.</p>
        </div>
        <div class="hero-actions">
          <div class="hero-menu-row">
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
          <p class="scale-display" id="display-scale-1">0.00 <span>kg</span></p>
          <button id="capture-scale-1" class="btn btn-secondary" type="button">Usar balanza 1</button>
        </article>

        <article class="scale-card card" data-scale-card="2">
          <div class="scale-card-head">
            <h2>Balanza 2</h2>
            <button id="openScaleSettings2" class="scale-settings-btn" type="button" aria-label="Configurar balanza 2" aria-haspopup="dialog" aria-expanded="false" aria-controls="scaleSettingsModal2">
              <span aria-hidden="true">&#9881;</span>
            </button>
          </div>
          <p class="scale-display" id="display-scale-2">0.00 <span>kg</span></p>
          <button id="capture-scale-2" class="btn btn-secondary" type="button">Usar balanza 2</button>
        </article>
      </section>

      <section class="entry-section card">
        <div class="section-head">
          <h2>Registrar Pesada</h2>
        </div>

        <form id="cageForm" novalidate>
          <div class="type-switch" role="group" aria-label="Tipo de pollo">
            <button class="type-btn is-active" data-type="pollo_vivo" type="button">Pollo vivo</button>
            <button class="type-btn" data-type="pollo_pelado" type="button">Pollo pelado</button>
          </div>

          <div class="form-grid">
            <div class="dispatch-primary-row">
              <label class="field dispatch-truck-field">
                Ticket
                <select id="truckSelect" data-touch-label="Ticket"></select>
              </label>

              <div class="selected-origin-summary">
                <span>Camión origen</span>
                <strong id="selectedProviderName">Selecciona un camión de la lista</strong>
                <small id="selectedProviderPlateLabel">Proveedor y placa pendientes</small>
              </div>

              <label class="field dispatch-quantity-field">
                <span title="Aves por java o total sin javas">Aves/J.</span>
                <input id="birdCount" type="number" min="1" step="1" placeholder="Ej: 7" required readonly inputmode="none" data-keypad-label="Aves por java o total sin javas" data-keypad-decimal="false">
              </label>

              <label class="field dispatch-quantity-field">
                <span title="Cantidad de javas">Javas</span>
                <input id="javaCount" type="number" min="0" step="1" value="1" required readonly inputmode="none" data-keypad-label="Javas" data-keypad-decimal="false">
              </label>

              <fieldset class="sex-selector dispatch-sex-selector" aria-label="Sexo de los pollos">
                <legend>Sexo</legend>
                <div class="sex-selector-buttons">
                  <button class="sex-btn sex-btn-male is-active" type="button" data-sex="macho" aria-pressed="true" aria-label="Macho" title="Macho">M</button>
                  <button class="sex-btn sex-btn-female" type="button" data-sex="hembra" aria-pressed="false" aria-label="Hembra" title="Hembra">H</button>
                </div>
              </fieldset>
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
              Peso bruto manual (kg)
              <input id="manualWeight" type="number" min="0" step="0.01" placeholder="Ej: 49.50" readonly inputmode="none" data-keypad-label="Peso bruto manual (kg)">
            </label>

            <div class="weight-preview weight-preview-gross">
              <span>Peso bruto a registrar</span>
              <strong id="selectedWeightValue">0.00 kg</strong>
              <small id="selectedWeightBreakdown" hidden></small>
            </div>

            <button id="addWeighingBtn" class="btn btn-success weighing-submit-button" type="submit" disabled>Agregar registro</button>
          </div>

          <div class="form-actions">
            <p id="formMessage" class="form-message" role="status" aria-live="polite"></p>
          </div>
        </form>
      </section>

      <button id="returnTicketBtn" class="return-ticket-btn" type="button">Cambiar a devolución</button>

      <section class="daily-provider-panel card" aria-label="Camiones del día">
        <div class="daily-provider-head">
          <div class="daily-provider-actions">
            <a class="btn btn-ghost" href="{{ route('jornada') }}">Configurar jornada</a>
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
        <input id="clientSearch" type="search" placeholder="Escribe un nombre o almacén..." autocomplete="off">
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
        <input id="deliveryTruckSearch" type="search" placeholder="Placa, marca o modelo..." autocomplete="off">
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
        <input id="deliveryDriverSearch" type="search" placeholder="Nombre o documento..." autocomplete="off">
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
            Tipo de pollo
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

          <fieldset class="sex-selector edit-sex-selector" aria-label="Sexo de los pollos">
            <legend>Sexo</legend>
            <div class="sex-selector-buttons">
              <button class="sex-btn sex-btn-male is-active" type="button" data-edit-sex="macho" aria-pressed="true">Macho</button>
              <button class="sex-btn sex-btn-female" type="button" data-edit-sex="hembra" aria-pressed="false">Hembra</button>
            </div>
          </fieldset>

          <label class="field">
            Tipo de java
            <select id="editCrateType"></select>
          </label>

          <label class="field">
            Peso bruto (kg)
            <input id="editWeight" type="number" min="0.01" step="0.01" required readonly inputmode="none" data-keypad-label="Peso bruto (kg)">
          </label>

          <label class="field">
            Balanza / peso
            <select id="editWeightSource" data-touch-label="Balanza o peso manual">
              <option value="1">Balanza 1</option>
              <option value="2">Balanza 2</option>
              <option value="manual">Manual</option>
            </select>
          </label>

          <div class="field">
            <span>Origen de la mercadería</span>
            <button id="editSelectProviderBtn" class="provider-select-btn" type="button" aria-haspopup="dialog" aria-controls="providerModal">
              <span class="provider-select-copy">
                <small>Origen registrado en esta pesada</small>
                <strong id="editSelectedProviderName">Sin origen registrado</strong>
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
          <p class="provider-modal-caption">Origen de la mercadería</p>
          <h2 id="providerModalTitle">Seleccionar origen</h2>
        </div>
        <button id="closeProviderModalBtn" class="btn btn-primary" type="button">Cerrar</button>
      </div>

      <label class="field provider-search">
        Buscar proveedor o almacén
        <input id="providerSearch" type="search" placeholder="Nombre, almacén, DNI/RUC o dirección" autocomplete="off">
      </label>

      <p id="providerModalSelection" class="provider-modal-selection">Ningún origen seleccionado.</p>
      <div id="providerList" class="provider-list" role="listbox" aria-label="Lista de proveedores y almacenes"></div>
    </div>
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

  <script type="module" src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>

