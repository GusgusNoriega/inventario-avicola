@php
  $retailStation = $retailStation ?? 1;
  $retailTitle = $retailTitle ?? 'Despacho minorista';
  $retailApiBase = $retailApiBase ?? '/despacho-minorista';
  $retailCustomerDisplayRoute = (int) $retailStation === 2
    ? route('despacho-minorista-2.pantalla-cliente')
    : route('despacho-minorista.pantalla-cliente');
@endphp
<!doctype html>
<html lang="es" class="retail-dispatch-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>{{ $retailTitle }} | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-minorista.css') }}?v={{ filemtime(public_path('css/despacho-minorista.css')) }}">
</head>
<body class="retail-dispatch-page">
  <main id="retailStation" class="rd-station" data-retail-station="{{ $retailStation }}" data-retail-api-base="{{ $retailApiBase }}">
    <header class="rd-topbar">
      <div class="rd-brand">
        <span class="rd-brand-mark" aria-hidden="true">PM</span>
        <div>
          <p>Estación de venta</p>
          <h1>{{ $retailTitle }}</h1>
        </div>
      </div>

      <div class="rd-branch-meta">
        <span id="retailBranchName">Cargando sucursal...</span>
        <strong id="retailClock" aria-hidden="true">--:--</strong>
      </div>

      <div class="rd-topbar-actions">
        <span id="retailScaleTopStatus" class="rd-status-chip is-offline">
          <i aria-hidden="true"></i>
          <span>Balanza sin conectar</span>
        </span>
        <a
          id="retailOpenCustomerDisplay"
          class="rd-menu-button rd-customer-display-button"
          href="{{ $retailCustomerDisplayRoute }}"
          target="pantalla-cliente-minorista-{{ $retailStation }}"
          aria-label="Abrir la pantalla para el cliente de venta minorista {{ $retailStation }}"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <rect x="3" y="4" width="18" height="13" rx="2"></rect>
            <path d="M8 21h8M12 17v4"></path>
          </svg>
          <span>Pantalla cliente</span>
        </a>
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
          <div class="rd-panel-heading rd-tray-quantity-heading">
            <div>
              <span>Cantidad de bandejas</span>
              <strong>Toca el número para cambiar</strong>
            </div>
            <input id="retailTrayCount" type="hidden" value="1">
            <button id="retailTrayCountTrigger" class="rd-number-trigger rd-tray-count-trigger" type="button" aria-haspopup="dialog" aria-controls="retailTrayCountModal">
              <strong id="retailTrayCountValue">1</strong>
              <small id="retailTrayCountLabel">bandeja</small>
            </button>
          </div>

          <div class="rd-tray-fields">
            <label>
              <span>Tipo de bandeja</span>
              <select id="retailTrayType" required></select>
            </label>
            <div class="rd-birds-per-tray-field">
              <span id="retailBirdsPerTrayAccessibleLabel" class="sr-only">Aves por bandeja</span>
              <input id="retailBirdsPerTray" type="hidden" value="5">
              <button id="retailBirdsPerTrayTrigger" class="rd-number-trigger rd-birds-per-tray-trigger" type="button" aria-haspopup="dialog" aria-controls="retailBirdsPerTrayModal" aria-labelledby="retailBirdsPerTrayAccessibleLabel retailBirdsPerTrayValue retailBirdsPerTrayLabel">
                <strong id="retailBirdsPerTrayValue">5</strong>
                <small id="retailBirdsPerTrayLabel">aves</small>
              </button>
            </div>
          </div>
        </article>

        <article class="rd-scale-panel rd-panel">
          <div class="rd-scale-head">
            <span id="retailWeightSourceLabel">Sin lectura</span>
            <span id="retailCaptureState" class="rd-capture-state">Sin conexión</span>
          </div>

          <input id="retailRawWeightInput" type="hidden" value="">
          <button id="retailManualWeightTrigger" class="rd-scale-reading" type="button" aria-haspopup="dialog" aria-controls="retailManualWeightModal" aria-label="Peso directo de la balanza. Toca para ingresar peso manual">
            <output id="retailAdjustedWeight">---</output>
            <span aria-hidden="true">kg</span>
          </button>

          <div class="rd-adjusted-reading">
            <span>Peso directo de la balanza</span>
          </div>

          <button id="retailCaptureWeight" class="rd-capture-button" type="button" disabled>
            <span aria-hidden="true">◎</span>
            Capturar peso
          </button>
        </article>

        <article class="rd-values-panel rd-panel">
          <div class="rd-value-card is-price">
            <span>Valor en tiempo real</span>
            <strong id="retailPricePreview">S/ --</strong>
            <small id="retailPriceSource">Asigna un precio a la lista</small>
          </div>
          <div class="rd-value-card">
            <span>Peso bruto ajustado</span>
            <strong id="retailGrossPreview">--- kg</strong>
          </div>
          <div class="rd-value-card">
            <span>Tara de bandejas</span>
            <strong id="retailTarePreview">0.000 kg</strong>
            <small id="retailTareDetail">0 × 2.500 kg por bandeja</small>
          </div>
          <div class="rd-value-card is-net">
            <span>Peso neto</span>
            <strong id="retailNetPreview">--- kg</strong>
            <small id="retailBirdTotalPreview">5 aves</small>
          </div>
        </article>
      </section>

      <section class="rd-selection-bar" aria-label="Características y destino de la pesada">
        <div class="rd-chicken-types" id="retailChickenTypes" role="group" aria-label="Tipo de pollo"></div>

        <div id="retailAdjustments" class="rd-adjustment-buttons" role="group" aria-label="Presentación del pollo" @if($retailStation === 2) hidden @endif></div>
      </section>
    </form>

    <section class="rd-workspace" aria-label="Listas de venta minorista">
      <div class="rd-lists-stage">
        <div class="rd-add-buttons" aria-label="Seleccionar lista de destino">
          @if($retailStation === 2)
            <p id="retailListSelectionHint" class="rd-list-selection-hint">Toca una columna para seleccionar la presentación.</p>
          @else
            <p id="retailListSelectionHint" class="rd-list-selection-hint">Selecciona una columna y captura; la pesada se agregará directamente.</p>
            <button type="button" class="is-active" data-retail-add-list="0" aria-pressed="true">Seleccionar lista 1</button>
            <button type="button" data-retail-add-list="1" aria-pressed="false">Seleccionar lista 2</button>
            <button type="button" data-retail-add-list="2" aria-pressed="false">Seleccionar lista 3</button>
            <button type="button" data-retail-add-list="3" aria-pressed="false">Seleccionar lista 4</button>
          @endif
        </div>
        <div id="retailListsGrid" class="rd-lists-grid"></div>
      </div>

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
          Cambiar precio
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

  <div id="retailTrayCountModal" class="rd-modal" hidden>
    <section class="rd-modal-card is-compact" role="dialog" aria-modal="true" aria-labelledby="retailTrayCountModalTitle">
      <header class="rd-modal-head">
        <div>
          <p>Selección táctil</p>
          <h2 id="retailTrayCountModalTitle">Cantidad de bandejas</h2>
        </div>
        <button type="button" data-retail-close-modal="retailTrayCountModal" aria-label="Cerrar">×</button>
      </header>
      <div class="rd-number-options rd-tray-count-options" role="group" aria-label="Seleccionar cantidad de bandejas">
        <button class="is-zero" type="button" data-retail-tray-option="0">
          <strong>0</strong>
          <small>Sin bandejas</small>
        </button>
        @for ($quantity = 1; $quantity <= 10; $quantity++)
          <button type="button" data-retail-tray-option="{{ $quantity }}">{{ $quantity }}</button>
        @endfor
      </div>
    </section>
  </div>

  <div id="retailBirdsPerTrayModal" class="rd-modal" hidden>
    <section class="rd-modal-card is-compact" role="dialog" aria-modal="true" aria-labelledby="retailBirdsPerTrayModalTitle">
      <header class="rd-modal-head">
        <div>
          <p>Selección táctil</p>
          <h2 id="retailBirdsPerTrayModalTitle">Aves por bandeja</h2>
        </div>
        <button type="button" data-retail-close-modal="retailBirdsPerTrayModal" aria-label="Cerrar">×</button>
      </header>
      <div class="rd-number-options rd-birds-per-tray-options" role="group" aria-label="Seleccionar aves por bandeja">
        @for ($quantity = 1; $quantity <= 10; $quantity++)
          <button type="button" data-retail-birds-per-tray-option="{{ $quantity }}">{{ $quantity }}</button>
        @endfor
      </div>
    </section>
  </div>

  <div id="retailManualWeightModal" class="rd-modal" hidden>
    <form id="retailManualWeightForm" class="rd-modal-card is-compact" role="dialog" aria-modal="true" aria-labelledby="retailManualWeightModalTitle">
      <header class="rd-modal-head">
        <div>
          <p>Respaldo manual</p>
          <h2 id="retailManualWeightModalTitle">Ingresar peso leído</h2>
        </div>
        <button type="button" data-retail-close-modal="retailManualWeightModal" aria-label="Cerrar">×</button>
      </header>
      <p class="rd-modal-copy">Este valor se guarda internamente; la pantalla principal mostrará únicamente el peso final con el ajuste seleccionado.</p>
      <label class="rd-manual-weight-field">
        <span>Peso leído (kg)</span>
        <input id="retailManualWeightEntry" type="number" min="0.001" step="0.001" inputmode="none" readonly required autocomplete="off" placeholder="Ej. 12.500" data-retail-keyboard="decimal" data-retail-keyboard-label="Peso leído en kilogramos">
      </label>
      <div class="rd-modal-actions">
        <button type="button" class="rd-secondary-button" data-retail-close-modal="retailManualWeightModal">Cancelar</button>
        <button class="rd-primary-button" type="submit">Aplicar peso</button>
      </div>
    </form>
  </div>

  <div id="retailRemoveWeighingModal" class="rd-modal" hidden>
    <section class="rd-modal-card is-remove-confirmation" role="alertdialog" aria-modal="true" aria-labelledby="retailRemoveWeighingModalTitle" aria-describedby="retailRemoveWeighingCopy">
      <header class="rd-modal-head">
        <div>
          <p>Confirmación requerida</p>
          <h2 id="retailRemoveWeighingModalTitle">¿Eliminar esta pesada?</h2>
        </div>
        <button type="button" data-retail-close-modal="retailRemoveWeighingModal" aria-label="Cerrar">×</button>
      </header>
      <p id="retailRemoveWeighingCopy" class="rd-modal-copy">Revisa los datos antes de continuar. La pesada será retirada de la lista activa.</p>
      <div id="retailRemoveWeighingPreview" class="rd-remove-preview"></div>
      <div class="rd-remove-warning" role="note">
        <span aria-hidden="true">!</span>
        <p><strong>Esta acción no se puede deshacer.</strong> Si los datos no corresponden, selecciona Cancelar.</p>
      </div>
      <div class="rd-modal-actions">
        <button type="button" class="rd-secondary-button" data-retail-close-modal="retailRemoveWeighingModal">Cancelar</button>
        <button id="retailConfirmRemoveWeighing" class="rd-danger-button" type="button">Sí, eliminar pesada</button>
      </div>
    </section>
  </div>

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
        <input id="retailClientSearch" type="search" placeholder="Toca para buscar..." autocomplete="off" inputmode="none" readonly data-retail-keyboard="text" data-retail-keyboard-label="Buscar cliente por nombre o documento">
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
      <p class="rd-modal-copy">Los precios vigentes se precargan para que puedas cambiarlos solo en este ticket, sin modificar las tarifas de Directorio.</p>
      <div id="retailPriceFields" class="rd-price-fields"></div>
      <div class="rd-modal-actions">
        <button id="retailClearPrices" class="rd-secondary-button" type="button">Usar precios vigentes</button>
        <button class="rd-primary-button" type="submit">Aplicar a la lista</button>
      </div>
    </form>
  </div>

  <div id="retailPaymentModal" class="rd-modal" hidden>
    <form id="retailPaymentForm" class="rd-modal-card is-payment" role="dialog" aria-modal="true" aria-labelledby="retailPaymentModalTitle" novalidate>
      <header class="rd-modal-head">
        <div>
          <p>Cobro de la venta</p>
          <h2 id="retailPaymentModalTitle">Forma de pago</h2>
        </div>
        <button type="button" data-retail-close-modal="retailPaymentModal" aria-label="Cerrar">×</button>
      </header>
      <p id="retailPaymentSummary" class="rd-delivery-summary"></p>
      <section id="retailPaymentModeOptions" class="rd-payment-mode-options" aria-label="Modalidad de pago" role="radiogroup" hidden>
        <button type="button" class="rd-payment-mode-option is-selected" data-retail-payment-mode="PAY_NOW" role="radio" aria-checked="true">
          <span aria-hidden="true">S/</span>
          <strong>Pagar ahora</strong>
          <small>Registra el cobro al momento, total o parcial.</small>
        </button>
        <button type="button" class="rd-payment-mode-option" data-retail-payment-mode="CREDIT" role="radio" aria-checked="false">
          <span aria-hidden="true">↗</span>
          <strong>Venta a crédito</strong>
          <small>Deja el total pendiente para cobrarlo después.</small>
        </button>
      </section>
      <div id="retailPaymentNowPanel" class="rd-payment-now-panel">
        <div id="retailPaymentRows" class="rd-payment-rows"></div>
        <button id="retailAddPayment" class="rd-secondary-button rd-add-payment" type="button">+ Agregar otra forma de pago</button>
        <div class="rd-payment-totals" aria-live="polite">
          <span>Total de la venta <strong id="retailPaymentSaleTotal">S/ 0.00</strong></span>
          <span>Total recibido <strong id="retailPaymentReceivedTotal">S/ 0.00</strong></span>
          <span>Pendiente <strong id="retailPaymentPendingTotal">S/ 0.00</strong></span>
        </div>
      </div>
      <div id="retailPaymentCreditPanel" class="rd-payment-credit-panel" hidden>
        <span class="rd-payment-credit-icon" aria-hidden="true">✓</span>
        <div>
          <strong>El ticket quedará como venta a crédito</strong>
          <p id="retailPaymentCreditSummary"></p>
        </div>
      </div>
      <p id="retailPaymentMessage" class="rd-settings-message" role="alert" aria-live="assertive" tabindex="-1"></p>
      <div class="rd-modal-actions">
        <button type="button" class="rd-secondary-button" data-retail-close-modal="retailPaymentModal">Cancelar</button>
        <button id="retailConfirmPayment" class="rd-primary-button" type="submit">Registrar pago y continuar</button>
      </div>
    </form>
  </div>

  <div id="retailDeliveryModal" class="rd-modal" hidden>
    <form id="retailDeliveryForm" class="rd-modal-card is-delivery" role="dialog" aria-modal="true" aria-labelledby="retailDeliveryModalTitle" novalidate>
      <header class="rd-modal-head">
        <div>
          <p>Trazabilidad de bandejas</p>
          <h2 id="retailDeliveryModalTitle">Asignar transporte</h2>
        </div>
        <button type="button" data-retail-close-modal="retailDeliveryModal" aria-label="Cerrar">×</button>
      </header>
      <p id="retailDeliverySummary" class="rd-delivery-summary"></p>
      <div class="rd-delivery-fields">
        <label>
          <span>Camión que llevará la mercancía</span>
          <select id="retailDeliveryTruck" required></select>
        </label>
        <label>
          <span>Chofer responsable</span>
          <select id="retailDeliveryDriver" required></select>
        </label>
      </div>
      <p id="retailDeliveryMessage" class="rd-settings-message" role="status" aria-live="polite"></p>
      <div class="rd-modal-actions">
        <button type="button" class="rd-secondary-button" data-retail-close-modal="retailDeliveryModal">Cancelar</button>
        <button id="retailConfirmDelivery" class="rd-primary-button" type="submit">Guardar e imprimir / PDF</button>
      </div>
    </form>
  </div>

  <div id="retailErrorModal" class="rd-modal rd-error-modal" hidden>
    <section class="rd-modal-card is-error" role="alertdialog" aria-modal="true" aria-labelledby="retailErrorModalTitle" aria-describedby="retailErrorModalMessage retailErrorModalHelp">
      <header class="rd-modal-head">
        <div>
          <p id="retailErrorModalCaption">Error al grabar</p>
          <h2 id="retailErrorModalTitle">No se registró el ticket</h2>
        </div>
        <button type="button" data-retail-close-modal="retailErrorModal" aria-label="Cerrar">×</button>
      </header>
      <p id="retailErrorModalMessage" class="rd-error-summary"></p>
      <dl id="retailErrorModalDetails" class="rd-error-details"></dl>
      <p id="retailErrorModalHelp" class="rd-error-help">La lista y sus pesadas se conservan para que puedas corregir el problema y volver a intentar.</p>
      <div class="rd-modal-actions">
        <a id="retailErrorLogin" class="rd-secondary-button rd-error-action-link" href="{{ route('login') }}" hidden>Ir a iniciar sesión</a>
        <button id="retailRetryPrint" type="button" class="rd-secondary-button" hidden>Reintentar impresión</button>
        <button id="retailCloseErrorModal" type="button" class="rd-primary-button" data-retail-close-modal="retailErrorModal">Entendido</button>
      </div>
    </section>
  </div>

  <div id="retailSettingsModal" class="rd-modal" hidden>
    <form id="retailSettingsForm" class="rd-modal-card is-settings" role="dialog" aria-modal="true" aria-labelledby="retailSettingsModalTitle">
      <header class="rd-modal-head">
        <div>
          <p>Conexión exclusiva de este navegador y estación</p>
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
              <input id="retailManualScaleInput" type="number" min="0.001" step="0.001" inputmode="none" readonly placeholder="Ej. 12.450" data-retail-keyboard="decimal" data-retail-keyboard-label="Lectura manual de prueba">
            </label>
            <button id="retailApplyManualScale" class="rd-secondary-button" type="button">Aplicar</button>
          </div>
          <p id="retailScaleRaw" class="rd-raw-frame">Trama: --</p>
        </section>

        <section class="rd-settings-section">
          <div class="rd-settings-title">
            <div>
              <span>Puerto serial</span>
              <strong>Parámetros guardados solo en esta computadora</strong>
            </div>
          </div>
          <div class="rd-serial-fields">
            <label><span>Baudios</span><input id="retailBaudRate" type="number" min="300" step="1" value="9600" inputmode="none" readonly data-retail-keyboard="integer" data-retail-keyboard-label="Baudios del puerto serial"></label>
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
            <span>Gramos adicionales por pollo</span>
            <strong>Ajuste aplicado por sexo y presentación</strong>
          </div>
          <label class="rd-default-adjustment">
            <span>Predeterminado</span>
            <select id="retailDefaultAdjustment"></select>
          </label>
        </div>
        <div id="retailSettingsAdjustments" class="rd-adjustment-settings-grid"></div>
      </section>

      <section class="rd-payment-default-settings">
        <div class="rd-settings-title">
          <div>
            <span>Cobro rápido</span>
            <strong>Método y cuenta predeterminados de esta estación</strong>
          </div>
        </div>
        <div class="rd-payment-default-fields">
          <label>
            <span>Método de pago predeterminado</span>
            <select id="retailDefaultPaymentMethod"></select>
          </label>
          <label>
            <span>Cuenta o caja receptora predeterminada</span>
            <select id="retailDefaultPaymentAccount"></select>
          </label>
        </div>
        <p class="rd-payment-default-copy">
          Estos valores se precargarán al cobrar. Podrás elegir otro método o cuenta para cualquier venta sin cambiar esta configuración.
        </p>
      </section>

      <section class="rd-typography-settings">
        <div class="rd-settings-title">
          <div>
            <span>Visualización de esta estación</span>
            <strong>Tamaños de texto personalizados</strong>
          </div>
          <button id="retailOpenTypography" class="rd-secondary-button" type="button" aria-controls="retailTypographyDrawer" aria-expanded="false">
            Tamaños de tipografía
          </button>
        </div>
      </section>

      <p id="retailSettingsMessage" class="rd-settings-message" role="status" aria-live="polite"></p>
      <div class="rd-modal-actions">
        <button type="button" class="rd-secondary-button" data-retail-close-modal="retailSettingsModal">Cancelar</button>
        <button id="retailSaveSettings" class="rd-primary-button" type="submit">Guardar configuración</button>
      </div>
    </form>
  </div>

  <aside id="retailTypographyDrawer" class="rd-typography-drawer" hidden aria-hidden="true" aria-labelledby="retailTypographyTitle">
    <header class="rd-typography-head">
      <div>
        <p>Vista previa en tiempo real</p>
        <h2 id="retailTypographyTitle">Tamaños de tipografía</h2>
      </div>
      <button id="retailTypographyClose" type="button" aria-label="Cerrar ajustes de tipografía">×</button>
    </header>
    <p class="rd-typography-copy">Ajusta cada grupo por separado y observa el resultado al instante. Los cambios se guardan automáticamente en este navegador.</p>
    <div id="retailTypographyControls" class="rd-typography-controls"></div>
    <footer class="rd-typography-footer">
      <button id="retailTypographyReset" class="rd-secondary-button" type="button">Restaurar predeterminados</button>
    </footer>
  </aside>

  <aside id="retailTouchKeyboard" class="rd-touch-keyboard" hidden aria-hidden="true">
    <section class="rd-touch-keyboard-card" role="dialog" aria-labelledby="retailTouchKeyboardTitle" aria-describedby="retailTouchKeyboardValue">
      <header class="rd-touch-keyboard-head">
        <div>
          <span>Teclado táctil</span>
          <strong id="retailTouchKeyboardTitle">Ingresar texto</strong>
        </div>
        <output id="retailTouchKeyboardValue">&nbsp;</output>
        <button type="button" data-retail-keyboard-action="cancel" aria-label="Cancelar y cerrar teclado">×</button>
      </header>
      <div id="retailTouchKeyboardKeys" class="rd-touch-keyboard-keys"></div>
      <footer class="rd-touch-keyboard-actions">
        <button type="button" data-retail-keyboard-action="clear">Limpiar</button>
        <button type="button" data-retail-keyboard-action="backspace">⌫ Borrar</button>
        <button type="button" class="is-accept" data-retail-keyboard-action="accept">Aceptar</button>
      </footer>
    </section>
  </aside>

  <script type="module" src="{{ asset('js/despacho-minorista.js') }}?v={{ filemtime(public_path('js/despacho-minorista.js')) }}"></script>
</body>
</html>
