<!doctype html>
<html lang="es" class="retail-dispatch-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Despacho minorista | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-minorista.css') }}?v={{ filemtime(public_path('css/despacho-minorista.css')) }}">
</head>
<body class="retail-dispatch-page">
  <main id="retailStation" class="rd-station">
    <header class="rd-topbar">
      <div class="rd-brand">
        <span class="rd-brand-mark" aria-hidden="true">PM</span>
        <div>
          <p>Estación de venta</p>
          <h1>Despacho minorista</h1>
        </div>
      </div>

      <div class="rd-branch-meta" aria-live="polite">
        <span id="retailBranchName">Cargando sucursal...</span>
        <strong id="retailClock">--:--</strong>
      </div>

      <div class="rd-topbar-actions">
        <span id="retailScaleTopStatus" class="rd-status-chip is-offline">
          <i aria-hidden="true"></i>
          <span>Balanza sin conectar</span>
        </span>
        <button id="retailOpenSettings" class="rd-icon-button" type="button" aria-label="Configurar balanza y ajustes" aria-haspopup="dialog" aria-controls="retailSettingsModal">
          <span aria-hidden="true">&#9881;</span>
        </button>
        <a class="rd-menu-button" href="{{ route('menu') }}" aria-label="Volver al menú principal">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path></svg>
          <span>Menú</span>
        </a>
      </div>
    </header>

    <form id="retailWeighingForm" class="rd-capture-form" novalidate>
      <section class="rd-capture-deck" aria-label="Captura de la pesada">
        <article class="rd-tray-panel rd-panel">
          <div class="rd-panel-heading">
            <div>
              <span>Grupo de bandejas</span>
              <strong id="retailTrayCountLabel">1 bandeja</strong>
            </div>
            <label class="rd-compact-number">
              <span class="sr-only">Cantidad de bandejas</span>
              <input id="retailTrayCount" type="number" min="1" max="1000" step="1" value="1" inputmode="numeric" required>
            </label>
          </div>

          <div class="rd-quick-grid" aria-label="Cantidad rápida de bandejas">
            <button type="button" data-retail-tray-count="1" class="is-active">1</button>
            <button type="button" data-retail-tray-count="2">2</button>
            <button type="button" data-retail-tray-count="3">3</button>
            <button type="button" data-retail-tray-count="4">4</button>
            <button type="button" data-retail-tray-count="5">5</button>
            <button type="button" data-retail-tray-count="10">10</button>
          </div>

          <div class="rd-tray-fields">
            <label>
              <span>Tipo de bandeja</span>
              <select id="retailTrayType" required></select>
            </label>
            <label>
              <span>Aves por bandeja</span>
              <input id="retailBirdsPerTray" type="number" min="1" max="100" step="1" value="5" inputmode="numeric" required>
            </label>
          </div>
        </article>

        <article class="rd-scale-panel rd-panel">
          <div class="rd-scale-head">
            <span id="retailWeightSourceLabel">Ingreso manual</span>
            <span id="retailCaptureState" class="rd-capture-state">Peso en vivo</span>
          </div>

          <div class="rd-scale-reading">
            <label class="sr-only" for="retailRawWeightInput">Peso leído en kilogramos</label>
            <input id="retailRawWeightInput" type="number" min="0" step="0.001" value="0" inputmode="decimal" autocomplete="off">
            <span aria-hidden="true">kg</span>
          </div>

          <div class="rd-adjusted-reading">
            <span>Peso final con ajuste</span>
            <strong><output id="retailAdjustedWeight">0.000</output> kg</strong>
            <small id="retailAdjustmentPreview">Sin ajuste adicional</small>
          </div>

          <button id="retailCaptureWeight" class="rd-capture-button" type="button">
            <span aria-hidden="true">◎</span>
            Capturar peso
          </button>
        </article>

        <article class="rd-values-panel rd-panel">
          <div class="rd-value-card is-price">
            <span>Precio a valorizar</span>
            <strong id="retailPricePreview">S/ --</strong>
            <small id="retailPriceSource">Asigna un cliente</small>
          </div>
          <div class="rd-value-card">
            <span>Peso bruto ajustado</span>
            <strong id="retailGrossPreview">0.000 kg</strong>
          </div>
          <div class="rd-value-card">
            <span>Tara de bandejas</span>
            <strong id="retailTarePreview">0.000 kg</strong>
          </div>
          <div class="rd-value-card is-net">
            <span>Peso neto</span>
            <strong id="retailNetPreview">0.000 kg</strong>
            <small id="retailBirdTotalPreview">5 aves</small>
          </div>
        </article>
      </section>

      <section class="rd-selection-bar" aria-label="Características y destino de la pesada">
        <div class="rd-chicken-types" id="retailChickenTypes" role="group" aria-label="Tipo de pollo"></div>

        <label class="rd-sex-field">
          <span>Sexo predeterminado</span>
          <select id="retailSex" aria-label="Sexo predeterminado">
            <option value="MACHO">Macho</option>
            <option value="HEMBRA">Hembra</option>
          </select>
        </label>

        <div id="retailAdjustments" class="rd-adjustment-buttons" role="group" aria-label="Presentación del pollo"></div>

        <div class="rd-add-buttons" aria-label="Agregar pesada a una lista">
          <button type="button" data-retail-add-list="0">Agregar a lista 1</button>
          <button type="button" data-retail-add-list="1">Agregar a lista 2</button>
          <button type="button" data-retail-add-list="2">Agregar a lista 3</button>
          <button type="button" data-retail-add-list="3">Agregar a lista 4</button>
        </div>
      </section>
    </form>

    <section class="rd-workspace" aria-label="Listas de venta minorista">
      <div id="retailListsGrid" class="rd-lists-grid"></div>

      <aside class="rd-action-rail" aria-label="Acciones de la lista activa">
        <div class="rd-operation-buttons" role="group" aria-label="Tipo de operación">
          <button type="button" data-retail-operation="DESPACHO" class="is-active">Venta</button>
          <button type="button" data-retail-operation="DEVOLUCION">Devolución</button>
        </div>
        <button id="retailAssignClient" class="rd-rail-button is-client" type="button">
          <span aria-hidden="true">＋</span>
          Asignar cliente
        </button>
        <button id="retailRemoveWeighing" class="rd-rail-button is-remove" type="button" disabled>
          <span aria-hidden="true">−</span>
          Quitar pesada
        </button>
        <button id="retailAssignPrice" class="rd-rail-button is-price" type="button">
          <span aria-hidden="true">S/</span>
          Asignar precio
        </button>
        <button id="retailSaveDispatch" class="rd-save-button" type="button" disabled>
          <span aria-hidden="true">✓</span>
          <span>Grabar</span>
        </button>
      </aside>
    </section>

    <footer class="rd-statusbar">
      <p id="retailMessage" role="status" aria-live="polite">Preparando estación minorista...</p>
      <div class="rd-active-summary" aria-live="polite">
        <span>Lista <strong id="retailActiveListNumber">1</strong></span>
        <span>Pesadas <strong id="retailTotalWeighings">0</strong></span>
        <span>Bandejas <strong id="retailTotalTrays">0</strong></span>
        <span>Aves <strong id="retailTotalBirds">0</strong></span>
        <span>Neto <strong id="retailTotalNet">0.000 kg</strong></span>
        <span>Total <strong id="retailTotalAmount">S/ 0.00</strong></span>
      </div>
    </footer>

    <section id="retailLastTicket" class="rd-ticket-toast" hidden aria-live="polite"></section>
  </main>

  <div id="retailClientModal" class="rd-modal" hidden>
    <section class="rd-modal-card is-client" role="dialog" aria-modal="true" aria-labelledby="retailClientModalTitle">
      <header class="rd-modal-head">
        <div>
          <p>Lista activa</p>
          <h2 id="retailClientModalTitle">Asignar cliente</h2>
        </div>
        <button type="button" data-retail-close-modal="retailClientModal" aria-label="Cerrar">×</button>
      </header>
      <label class="rd-search-field">
        <span>Buscar por nombre o documento</span>
        <input id="retailClientSearch" type="search" placeholder="Escribe para buscar..." autocomplete="off">
      </label>
      <div id="retailClientOptions" class="rd-client-options" role="listbox" aria-label="Clientes disponibles"></div>
    </section>
  </div>

  <div id="retailPriceModal" class="rd-modal" hidden>
    <form id="retailPriceForm" class="rd-modal-card is-price" role="dialog" aria-modal="true" aria-labelledby="retailPriceModalTitle">
      <header class="rd-modal-head">
        <div>
          <p>Precio puntual del ticket</p>
          <h2 id="retailPriceModalTitle">Asignar precio por kilogramo</h2>
        </div>
        <button type="button" data-retail-close-modal="retailPriceModal" aria-label="Cerrar">×</button>
      </header>
      <p class="rd-modal-copy">Deja un campo vacío para usar el precio vigente del cliente o la lista general.</p>
      <div id="retailPriceFields" class="rd-price-fields"></div>
      <div class="rd-modal-actions">
        <button id="retailClearPrices" class="rd-secondary-button" type="button">Usar precios vigentes</button>
        <button class="rd-primary-button" type="submit">Aplicar a la lista</button>
      </div>
    </form>
  </div>

  <div id="retailSettingsModal" class="rd-modal" hidden>
    <form id="retailSettingsForm" class="rd-modal-card is-settings" role="dialog" aria-modal="true" aria-labelledby="retailSettingsModalTitle">
      <header class="rd-modal-head">
        <div>
          <p>Configuración exclusiva de esta estación</p>
          <h2 id="retailSettingsModalTitle">Balanza y ajustes minoristas</h2>
        </div>
        <button type="button" data-retail-close-modal="retailSettingsModal" aria-label="Cerrar">×</button>
      </header>

      <div class="rd-settings-grid">
        <section class="rd-settings-section">
          <div class="rd-settings-title">
            <div>
              <span>Conexión física</span>
              <strong id="retailSettingsScaleName">Balanza minorista</strong>
            </div>
            <span id="retailSettingsStatus" class="rd-status-chip is-offline"><i aria-hidden="true"></i><span>Sin conexión</span></span>
          </div>
          <div class="rd-connection-actions">
            <button id="retailConnectBle" class="rd-secondary-button" type="button">Conectar BLE</button>
            <button id="retailConnectSerial" class="rd-secondary-button" type="button">Conectar serial</button>
            <button id="retailDisconnectScale" class="rd-danger-button" type="button" disabled>Desconectar</button>
          </div>
          <div class="rd-manual-reading">
            <label>
              <span>Lectura manual de prueba (kg)</span>
              <input id="retailManualScaleInput" type="number" min="0" step="0.001" inputmode="decimal" placeholder="Ej. 12.450">
            </label>
            <button id="retailApplyManualScale" class="rd-secondary-button" type="button">Aplicar</button>
          </div>
          <p id="retailScaleRaw" class="rd-raw-frame">Trama: --</p>
        </section>

        <section class="rd-settings-section">
          <div class="rd-settings-title">
            <div>
              <span>Puerto serial</span>
              <strong>Parámetros de comunicación</strong>
            </div>
          </div>
          <div class="rd-serial-fields">
            <label><span>Baudios</span><input id="retailBaudRate" type="number" min="300" step="1" value="9600"></label>
            <label><span>Bits</span><select id="retailDataBits"><option value="8">8</option><option value="7">7</option></select></label>
            <label><span>Parada</span><select id="retailStopBits"><option value="1">1</option><option value="2">2</option></select></label>
            <label><span>Paridad</span><select id="retailParity"><option value="none">Ninguna</option><option value="even">Par</option><option value="odd">Impar</option></select></label>
            <label><span>Flujo</span><select id="retailFlowControl"><option value="none">Ninguno</option><option value="hardware">Hardware</option></select></label>
          </div>
        </section>
      </div>

      <section class="rd-adjustment-settings">
        <div class="rd-settings-title">
          <div>
            <span>Gramos adicionales</span>
            <strong>Ajuste aplicado por sexo y presentación</strong>
          </div>
          <label class="rd-default-adjustment">
            <span>Predeterminado</span>
            <select id="retailDefaultAdjustment"></select>
          </label>
        </div>
        <div id="retailSettingsAdjustments" class="rd-adjustment-settings-grid"></div>
      </section>

      <p id="retailSettingsMessage" class="rd-settings-message" role="status" aria-live="polite"></p>
      <div class="rd-modal-actions">
        <button type="button" class="rd-secondary-button" data-retail-close-modal="retailSettingsModal">Cancelar</button>
        <button id="retailSaveSettings" class="rd-primary-button" type="submit">Guardar configuración</button>
      </div>
    </form>
  </div>

  <script type="module" src="{{ asset('js/despacho-minorista.js') }}?v={{ filemtime(public_path('js/despacho-minorista.js')) }}"></script>
</body>
</html>
