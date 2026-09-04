<!doctype html>
<html lang="es" class="product-dispatch-operation-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Despacho de productos | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-despacho.css') }}?v={{ filemtime(public_path('css/despacho-productos-despacho.css')) }}">
</head>
<body class="pdd-page">
  <main
    id="productDispatchStation"
    class="pdd-station"
    data-user-id="{{ auth()->id() ?? 'anonymous' }}"
    data-api-base="/despacho-productos"
  >
    <div id="pddZoomSurface" class="pdd-zoom-surface">
    <header class="pdd-topbar">
      <div class="pdd-brand">
        <span class="pdd-brand-mark" aria-hidden="true">DP</span>
        <div>
          <p>Estación de venta avícola</p>
          <h1>Despacho de productos</h1>
        </div>
      </div>

      <div class="pdd-branch-meta">
        <span id="pddBranchName">Cargando sucursal…</span>
        <strong id="pddClock" aria-hidden="true">--:--</strong>
      </div>

      <div class="pdd-top-actions">
        <span id="pddScaleStatus" class="pdd-status-chip is-offline">
          <i aria-hidden="true"></i><span>Balanza sin conectar</span>
        </span>
        <button id="pddOpenScaleSettings" class="pdd-icon-action" type="button" aria-haspopup="dialog" aria-controls="pddScaleDialog">
          <span aria-hidden="true">⚙</span><span> Balanza</span>
        </button>
        <a
          id="pddOpenCustomerDisplay"
          class="pdd-icon-action pdd-customer-display-action"
          href="{{ route('despacho-productos.pantalla-cliente') }}"
          target="pantalla-cliente-productos"
          aria-label="Abrir pantalla del cliente"
        >
          <span aria-hidden="true">▣</span><span>Pantalla</span>
        </a>
        <button id="pddOpenViewSettings" class="pdd-icon-action pdd-view-settings-action" type="button" aria-haspopup="dialog" aria-controls="pddViewDialog">
          <span aria-hidden="true">Aa</span>
          <span class="pdd-action-label"><span>Configuración</span><span>Ajustes</span></span>
        </button>
        <a class="pdd-menu-action" href="{{ route('despacho-productos.menu') }}" aria-label="Volver al módulo Despacho de productos">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path></svg>
          <span>Módulo</span>
        </a>
      </div>
    </header>

    <section class="pdd-capture-deck" aria-label="Captura de producto y peso">
      <article class="pdd-product-panel pdd-panel">
        <div class="pdd-panel-heading">
          <div><span>Producto</span><strong id="pddSelectedName">Sin producto</strong></div>
          <button id="pddChooseProduct" class="pdd-choose-product" type="button" aria-haspopup="dialog" aria-controls="pddProductDialog">
            <span aria-hidden="true">＋</span> Elegir
          </button>
        </div>

        <div class="pdd-product-scale-row">
          <button id="pddProductMedia" class="pdd-product-media" type="button" aria-label="Elegir producto">
            <span class="pdd-media-placeholder"><b>?</b><small>Elige un producto</small></span>
          </button>

          <div class="pdd-scale-reading-wrap">
            <div class="pdd-scale-reading-head">
              <span class="pdd-scale-reading-origin">
                <span id="pddWeightSource">Peso neto · Sin lectura</span>
                <button id="pddClearManualWeight" type="button" hidden>Volver a balanza</button>
              </span>
              <span id="pddReadingState">Esperando peso</span>
            </div>
            <output id="pddLiveWeight" class="pdd-live-weight" aria-label="Peso neto actual">---<small>kg</small></output>
            <div class="pdd-scale-actions">
              <button id="pddManualWeight" class="pdd-secondary-touch" type="button" aria-haspopup="dialog" aria-controls="pddManualDialog">
                <span aria-hidden="true">✎</span> Manual
              </button>
              <button id="pddCaptureWeight" class="pdd-capture-touch" type="button" disabled>
                <span aria-hidden="true">◎</span> Capturar
              </button>
            </div>
          </div>
        </div>
      </article>

      <article class="pdd-config-panel pdd-panel">
        <div class="pdd-config-head">
          <div><span>Pesada</span><strong id="pddSelectedVariantLabel">Producto base</strong></div>
        </div>

        <div class="pdd-fields-grid">
          <label>
            <span>Cantidad</span>
            <span class="pdd-touch-number-input">
              <input id="pddQuantity" type="number" min="0" max="100000" step="1" inputmode="none" value="0" readonly required data-pdd-keypad-label="Cantidad de aves o elementos" data-pdd-keypad-value-label="Cantidad seleccionada" data-pdd-keypad-confirm-label="Usar cantidad" data-pdd-keypad-value-name="cantidad" aria-label="Cantidad de aves o elementos: 0. Presiona para cambiarla con el teclado táctil." aria-haspopup="dialog" aria-controls="pddNumericKeypad" aria-expanded="false" title="Presiona para abrir el teclado numérico">
              <b aria-hidden="true">und</b>
            </span>
          </label>
          <label class="pdd-price-field">
            <span>Precio</span>
            <span class="pdd-price-input">
              <b id="pddPriceCurrency" aria-hidden="true">S/</b>
              <input id="pddUnitPrice" type="number" min="0.01" max="9999999999.99" step="0.01" inputmode="none" placeholder="0.00" readonly required data-pdd-keypad-label="Precio de la pesada" data-pdd-keypad-value-label="Precio seleccionado" data-pdd-keypad-confirm-label="Usar precio" data-pdd-keypad-value-name="precio" data-pdd-keypad-value-article="un" aria-label="Precio de la pesada. Presiona para cambiarlo con el teclado táctil." aria-haspopup="dialog" aria-controls="pddNumericKeypad" aria-expanded="false" title="Presiona para abrir el teclado numérico" disabled>
            </span>
            <small id="pddPriceMode">Selecciona un producto</small>
          </label>
          <label>
            <span>Tara</span>
            <span class="pdd-touch-number-input">
              <input id="pddTare" type="number" min="0" max="1000000000" step="1" inputmode="none" value="0" readonly data-pdd-keypad-label="Tara en gramos" data-pdd-keypad-value-label="Tara seleccionada" data-pdd-keypad-confirm-label="Usar tara" data-pdd-keypad-value-name="tara" aria-label="Tara en gramos: 0. Presiona para cambiarla con el teclado táctil." aria-haspopup="dialog" aria-controls="pddNumericKeypad" aria-expanded="false" title="Presiona para abrir el teclado numérico">
              <b aria-hidden="true">g</b>
            </span>
          </label>
          <div class="pdd-gross-preview">
            <span>Peso bruto</span>
            <strong id="pddGrossPreview">--- kg</strong>
            <small id="pddAmountPreview">Importe pesada S/ --</small>
          </div>
        </div>

        <div class="pdd-waste-strip">
          <label class="pdd-waste-unit-control">
            <span>Merma/u</span>
            <span class="pdd-touch-number-input">
              <input id="pddWastePerUnit" type="number" min="0" max="1000000" step="1" inputmode="none" value="0" readonly data-pdd-keypad-label="Merma por unidad en gramos" data-pdd-keypad-value-label="Merma por unidad" data-pdd-keypad-confirm-label="Usar merma" data-pdd-keypad-value-name="merma por unidad" aria-label="Merma por unidad en gramos: 0. Presiona para cambiarla con el teclado táctil." aria-describedby="pddWasteHint" aria-haspopup="dialog" aria-controls="pddNumericKeypad" aria-expanded="false" title="Presiona para abrir el teclado numérico">
              <b aria-hidden="true">g/u</b>
            </span>
          </label>
          <div id="pddWastePresets" class="pdd-waste-presets" role="group" aria-label="Mermas estándar">
            <button type="button" data-pdd-waste-preset="0" aria-pressed="true"><span>M1</span><strong>0 g</strong></button>
            <button type="button" data-pdd-waste-preset="1" aria-pressed="false"><span>M2</span><strong>50 g</strong></button>
            <button type="button" data-pdd-waste-preset="2" aria-pressed="false"><span>M3</span><strong>100 g</strong></button>
          </div>
          <small id="pddWasteHint" class="pdd-waste-hint" aria-live="polite"></small>
        </div>

        <div class="pdd-variations-block">
          <div id="pddVariations" class="pdd-variations-slider" role="listbox" aria-label="Variaciones del producto">
            <span class="pdd-variation-empty">Elige producto</span>
          </div>
        </div>
      </article>

      <article class="pdd-quick-panel pdd-panel" aria-labelledby="pddQuickProductsTitle">
        <div class="pdd-quick-heading">
          <strong id="pddQuickProductsTitle">Rápidos</strong>
          <button id="pddQuickAllProducts" type="button" aria-haspopup="dialog" aria-controls="pddProductDialog">Todos</button>
        </div>
        <div id="pddQuickProducts" class="pdd-quick-products" aria-label="Productos de acceso rápido">
          <span class="pdd-quick-empty">Cargando…</span>
        </div>
      </article>
    </section>

    <section class="pdd-workspace" aria-label="Ocho listas de despacho">
      <div class="pdd-lists-area">
        <div id="pddLists" class="pdd-lists-grid"></div>
      </div>

      <aside class="pdd-action-rail" aria-label="Acciones del ticket activo">
        <button id="pddAssignClient" class="pdd-rail-action is-client" type="button" aria-haspopup="dialog" aria-controls="pddClientDialog">
          <span aria-hidden="true">♙</span><span><b id="pddClientActionLabel">Asignar cliente</b><small id="pddClientActionDetail">Venta al público</small></span>
        </button>
        <div class="pdd-ticket-total">
          <span>Total de la lista</span>
          <strong id="pddTicketTotal">S/ 0.00</strong>
          <small id="pddTicketSummary">0 pesadas · 0.000 kg netos</small>
        </div>
        <button id="pddSave" class="pdd-save-button is-secondary" type="button" disabled>
          <span aria-hidden="true">✓</span><span><b>Guardar</b><small>Sin imprimir</small></span>
        </button>
        <button id="pddSavePrint" class="pdd-save-button is-primary" type="button" disabled>
          <span aria-hidden="true">▣</span><span><b>Guardar e imprimir</b><small>Ticket de despacho</small></span>
        </button>
      </aside>
    </section>

    </div>

    <p id="pddMessage" class="pdd-message-live" role="status" aria-live="polite">Preparando la estación de despacho…</p>

    <section id="pddLastTicket" class="pdd-ticket-toast" hidden aria-live="polite">
      <div><strong id="pddLastTicketTitle">Ticket guardado</strong><span id="pddLastTicketDetail"></span></div>
      <button id="pddRetryPrint" type="button" hidden>Reintentar impresión</button>
      <button id="pddDismissTicket" type="button" aria-label="Cerrar aviso">×</button>
    </section>
  </main>

  <dialog id="pddProductDialog" class="pdd-dialog pdd-product-dialog" aria-labelledby="pddProductDialogTitle">
    <section>
      <header class="pdd-dialog-head">
        <div><p>Catálogo disponible</p><h2 id="pddProductDialogTitle">Elegir producto</h2></div>
        <button type="button" data-pdd-close="pddProductDialog" aria-label="Cerrar">×</button>
      </header>
      <label class="pdd-dialog-search">
        <span aria-hidden="true">⌕</span>
        <input id="pddProductSearch" type="search" autocomplete="off" aria-label="Buscar producto" placeholder="Buscar por nombre o variación…">
      </label>
      <div id="pddProductGrid" class="pdd-product-grid"></div>
    </section>
  </dialog>

  <dialog id="pddManualDialog" class="pdd-dialog pdd-small-dialog" aria-labelledby="pddManualDialogTitle">
    <form id="pddManualForm">
      <header class="pdd-dialog-head">
        <div><p>Lectura alternativa</p><h2 id="pddManualDialogTitle">Colocar peso manual</h2></div>
        <button type="button" data-pdd-close="pddManualDialog" aria-label="Cerrar">×</button>
      </header>
      <label class="pdd-big-number-input">
        <span>Peso neto</span>
        <span><input id="pddManualInput" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" placeholder="0.000" required><b>kg</b></span>
      </label>
      <p>El valor se usará directamente como peso neto, sin aplicar merma ni tara.</p>
      <footer class="pdd-dialog-actions">
        <button class="pdd-dialog-cancel" type="button" data-pdd-close="pddManualDialog">Cancelar</button>
        <button class="pdd-dialog-confirm" type="submit">Mostrar este peso</button>
      </footer>
    </form>
  </dialog>

  <dialog id="pddNumericKeypad" class="pdd-dialog pdd-keypad-dialog" aria-labelledby="pddNumericKeypadTitle">
    <section>
      <header class="pdd-dialog-head">
        <div><p>Teclado táctil</p><h2 id="pddNumericKeypadTitle">Ingresar valor</h2></div>
        <button type="button" data-pdd-close="pddNumericKeypad" aria-label="Cerrar">×</button>
      </header>

      <div class="pdd-keypad-value-wrap">
        <span id="pddNumericKeypadValueLabel">Valor seleccionado</span>
        <output id="pddNumericKeypadValue" class="pdd-keypad-value" tabindex="-1" aria-labelledby="pddNumericKeypadValueLabel" aria-live="polite">1</output>
      </div>
      <p id="pddNumericKeypadMessage" class="pdd-keypad-message" role="status" aria-live="polite"></p>

      <div class="pdd-keypad-grid" role="group" aria-label="Números disponibles">
        <button type="button" data-pdd-keypad-key="7">7</button>
        <button type="button" data-pdd-keypad-key="8">8</button>
        <button type="button" data-pdd-keypad-key="9">9</button>
        <button type="button" data-pdd-keypad-key="4">4</button>
        <button type="button" data-pdd-keypad-key="5">5</button>
        <button type="button" data-pdd-keypad-key="6">6</button>
        <button type="button" data-pdd-keypad-key="1">1</button>
        <button type="button" data-pdd-keypad-key="2">2</button>
        <button type="button" data-pdd-keypad-key="3">3</button>
        <button type="button" data-pdd-keypad-key="00">00</button>
        <button type="button" data-pdd-keypad-key="0">0</button>
        <button class="is-backspace" type="button" data-pdd-keypad-key="backspace" aria-label="Borrar último número">⌫</button>
      </div>

      <footer class="pdd-keypad-actions">
        <button id="pddNumericKeypadClear" class="pdd-dialog-cancel" type="button">Limpiar</button>
        <button class="pdd-dialog-cancel" type="button" data-pdd-close="pddNumericKeypad">Cancelar</button>
        <button id="pddNumericKeypadConfirm" class="pdd-dialog-confirm" type="button">Usar valor</button>
      </footer>
    </section>
  </dialog>

  <dialog id="pddClientDialog" class="pdd-dialog pdd-client-dialog" aria-labelledby="pddClientDialogTitle">
    <section>
      <header class="pdd-dialog-head">
        <div><p>Ticket activo</p><h2 id="pddClientDialogTitle">Asignar cliente</h2></div>
        <button type="button" data-pdd-close="pddClientDialog" aria-label="Cerrar">×</button>
      </header>
      <button id="pddPublicSale" class="pdd-public-client" type="button">
        <span aria-hidden="true">👥</span><span><strong>Venta al público</strong><small>Guarda el ticket sin un cliente registrado.</small></span>
      </button>
      <label class="pdd-dialog-search">
        <span aria-hidden="true">⌕</span>
        <input id="pddClientSearch" type="search" autocomplete="off" aria-label="Buscar cliente" placeholder="Buscar nombre, documento o teléfono…">
      </label>
      <div id="pddClientList" class="pdd-client-list"></div>
    </section>
  </dialog>

  <dialog id="pddEditDialog" class="pdd-dialog pdd-edit-dialog" aria-labelledby="pddEditDialogTitle">
    <form id="pddEditForm">
      <header class="pdd-dialog-head">
        <div><p>Detalle completo</p><h2 id="pddEditDialogTitle">Editar pesada</h2></div>
        <button type="button" data-pdd-close="pddEditDialog" aria-label="Cerrar">×</button>
      </header>
      <div class="pdd-edit-grid">
        <label><span>Producto</span><select id="pddEditProduct" required></select></label>
        <label><span>Variación</span><select id="pddEditVariation"></select></label>
        <label><span>Cantidad</span><input id="pddEditQuantity" type="number" min="0" max="100000" step="1" required></label>
        <label><span>Peso leído (kg)</span><input id="pddEditWeight" type="number" min="0.001" max="999999999.999" step="0.001" required></label>
        <label><span>Merma/u (g)</span><input id="pddEditWastePerUnit" type="number" min="0" max="1000000" step="1" required></label>
        <label><span>Merma total (g)</span><output id="pddEditWasteTotal" class="pdd-edit-output">0 g</output></label>
        <label><span>Tara (g)</span><input id="pddEditTare" type="number" min="0" max="1000000000" step="1" required></label>
        <label class="pdd-edit-price-field"><span>Precio</span><input id="pddEditPrice" type="number" min="0.01" max="9999999999.99" step="0.01" inputmode="none" readonly required data-pdd-keypad-label="Precio de la pesada" data-pdd-keypad-value-label="Precio seleccionado" data-pdd-keypad-confirm-label="Usar precio" data-pdd-keypad-value-name="precio" data-pdd-keypad-value-article="un" aria-label="Precio de la pesada. Presiona para cambiarlo con el teclado táctil." aria-haspopup="dialog" aria-controls="pddNumericKeypad" aria-expanded="false" title="Presiona para abrir el teclado numérico"></label>
      </div>
      <div class="pdd-edit-summary">
        <span id="pddEditSource">Origen: manual</span><strong id="pddEditCalculated">Neto 0.000 kg · S/ 0.00</strong>
      </div>
      <footer class="pdd-dialog-actions is-split">
        <button id="pddDeleteWeighing" class="pdd-dialog-delete" type="button">Eliminar pesada</button>
        <span></span>
        <button class="pdd-dialog-cancel" type="button" data-pdd-close="pddEditDialog">Cancelar</button>
        <button class="pdd-dialog-confirm" type="submit">Guardar cambios</button>
      </footer>
    </form>
  </dialog>

  <dialog id="pddScaleDialog" class="pdd-dialog pdd-scale-dialog" aria-labelledby="pddScaleDialogTitle">
    <form id="pddScaleForm">
      <header class="pdd-dialog-head">
        <div><p>Conexión rápida</p><h2 id="pddScaleDialogTitle">Balanza del despacho</h2></div>
        <button type="button" data-pdd-close="pddScaleDialog" aria-label="Cerrar">×</button>
      </header>
      <div class="pdd-scale-dialog-status">
        <span id="pddScaleDialogDot"></span><div><strong id="pddScaleDialogStatus">Sin conexión</strong><small id="pddScaleDevice">No hay dispositivo seleccionado</small></div>
      </div>
      <div class="pdd-connect-actions">
        <button id="pddConnectBle" type="button"><span aria-hidden="true">ᛒ</span><b>Conectar Bluetooth</b><small>Ideal para tablet o celular</small></button>
        <button id="pddConnectSerial" type="button"><span aria-hidden="true">USB</span><b>Conectar cable USB</b><small>Puerto serial del equipo</small></button>
      </div>
      <details class="pdd-serial-options">
        <summary>Parámetros de conexión serial</summary>
        <div>
          <label><span>Velocidad</span><select id="pddBaudRate"><option>1200</option><option>2400</option><option>4800</option><option selected>9600</option><option>19200</option><option>38400</option><option>57600</option><option>115200</option></select></label>
          <label><span>Bits de datos</span><select id="pddDataBits"><option>7</option><option selected>8</option></select></label>
          <label><span>Bits de parada</span><select id="pddStopBits"><option selected>1</option><option>2</option></select></label>
          <label><span>Paridad</span><select id="pddParity"><option value="none">Ninguna</option><option value="even">Par</option><option value="odd">Impar</option></select></label>
        </div>
      </details>
      <pre id="pddRawReading" class="pdd-raw-reading">Trama: --</pre>
      <p id="pddScaleMessage" class="pdd-scale-message"></p>
      <footer class="pdd-dialog-actions is-spread">
        <button id="pddDisconnectScale" class="pdd-dialog-delete" type="button">Desconectar y olvidar</button>
        <button class="pdd-dialog-confirm" type="button" data-pdd-close="pddScaleDialog">Listo</button>
      </footer>
    </form>
  </dialog>

  <dialog id="pddViewDialog" class="pdd-dialog pdd-view-dialog" aria-labelledby="pddViewDialogTitle">
    <section>
      <header class="pdd-dialog-head">
        <div><p>Vista y despacho</p><h2 id="pddViewDialogTitle">Configuración</h2></div>
        <button type="button" data-pdd-close="pddViewDialog" aria-label="Cerrar">×</button>
      </header>

      <form id="pddQuickProductForm" class="pdd-quick-product-setting">
        <div class="pdd-quick-product-setting-head">
          <span><strong>Despacho rápido</strong><small id="pddQuickProductCount">0/4</small></span>
          <button id="pddSaveQuickProducts" type="submit">Guardar</button>
        </div>
        <div id="pddQuickProductSelection" class="pdd-quick-product-selection" aria-label="Productos rápidos seleccionados"></div>
        <label class="pdd-quick-product-search">
          <span aria-hidden="true">⌕</span>
          <input id="pddQuickProductSearch" type="search" autocomplete="off" placeholder="Buscar producto" aria-label="Buscar producto rápido">
        </label>
        <div id="pddQuickProductResults" class="pdd-quick-product-results" aria-live="polite"></div>
        <small id="pddQuickProductStatus" class="pdd-quick-product-status" role="status" aria-live="polite"></small>
      </form>

      <form id="pddCustomerDisplayTitleForm" class="pdd-customer-display-setting">
        <span class="pdd-customer-display-setting-icon" aria-hidden="true">▣</span>
        <label for="pddCustomerDisplayTitle">
          <strong>Pantalla cliente</strong>
          <input
            id="pddCustomerDisplayTitle"
            type="text"
            maxlength="120"
            autocomplete="organization"
            placeholder="Título de la empresa"
            required
          >
        </label>
        <button id="pddSaveCustomerDisplayTitle" type="submit">Guardar</button>
        <small id="pddCustomerDisplayTitleStatus" role="status" aria-live="polite"></small>
      </form>

      <div class="pdd-theme-setting">
        <span class="pdd-theme-swatch" aria-hidden="true"><i></i><i></i><i></i></span>
        <span><strong>Tema oscuro</strong><small>Fondo negro con acentos de color para trabajar cómodamente.</small></span>
        <b>Activo</b>
      </div>

      <div class="pdd-zoom-setting">
        <div>
          <span>Tamaño de la aplicación</span>
          <small>Se guarda solamente para este usuario en este navegador.</small>
        </div>
        <div class="pdd-zoom-controls" role="group" aria-label="Cambiar tamaño de la aplicación">
          <button id="pddZoomOut" type="button" aria-label="Disminuir tamaño">−</button>
          <output id="pddZoomValue" aria-live="polite">100%</output>
          <button id="pddZoomIn" type="button" aria-label="Aumentar tamaño">＋</button>
        </div>
        <div class="pdd-zoom-scale" aria-hidden="true"><span>67%</span><i></i><span>150%</span></div>
      </div>

      <div class="pdd-typography-setting">
        <span class="pdd-typography-setting-icon" aria-hidden="true">Aa</span>
        <span>
          <strong>Tipografía detallada</strong>
          <small id="pddTypographySummary">Tamaño estándar · ajustes independientes para toda la vista.</small>
        </span>
        <button
          id="pddOpenTypography"
          type="button"
          aria-controls="pddTypographyPanel"
          aria-expanded="false"
        >Editar tamaños</button>
      </div>

      <form id="pddWastePresetForm" class="pdd-waste-preset-setting">
        <div><strong>Mermas M1–M3</strong><small>Gramos por unidad</small></div>
        <div class="pdd-waste-preset-inputs">
          <label><span>M1</span><input id="pddWastePreset1" type="number" min="0" max="1000000" step="1" value="0" required></label>
          <label><span>M2</span><input id="pddWastePreset2" type="number" min="0" max="1000000" step="1" value="50" required></label>
          <label><span>M3</span><input id="pddWastePreset3" type="number" min="0" max="1000000" step="1" value="100" required></label>
        </div>
        <button id="pddSaveWastePresets" type="submit">Guardar</button>
        <small id="pddWastePresetStatus" class="pdd-waste-preset-status" role="status" aria-live="polite"></small>
      </form>

      <footer class="pdd-dialog-actions is-spread">
        <button id="pddZoomReset" class="pdd-dialog-cancel" type="button">Restablecer a 100%</button>
        <button class="pdd-dialog-confirm" type="button" data-pdd-close="pddViewDialog">Listo</button>
      </footer>
    </section>
  </dialog>

  <aside
    id="pddTypographyPanel"
    class="pdd-typography-panel"
    role="dialog"
    aria-modal="false"
    aria-hidden="true"
    aria-labelledby="pddTypographyTitle"
    hidden
  >
    <header class="pdd-typography-head">
      <div>
        <p>Vista previa en tiempo real</p>
        <h2 id="pddTypographyTitle">Tipografía detallada</h2>
      </div>
      <button id="pddTypographyClose" type="button" aria-label="Cerrar configuración de tipografía">×</button>
    </header>

    <section class="pdd-typography-toolbar" aria-label="Herramientas de tipografía">
      <div class="pdd-typography-state">
        <span id="pddTypographyProfile">Perfil estándar</span>
        <span id="pddTypographySaveStatus" role="status" aria-live="polite">Guardado automático activo</span>
      </div>
      <p>Modifica cada texto por separado. El resultado se aplica al instante y queda guardado para este usuario en este navegador.</p>

      <div id="pddTypographyPreview" class="pdd-typography-preview" aria-live="polite">
        <span>Vista del ajuste activo</span>
        <strong>Aa 123.45 kg</strong>
        <small>Producto avícola · S/ 98.70</small>
      </div>

      <label class="pdd-typography-search">
        <span aria-hidden="true">⌕</span>
        <input id="pddTypographySearch" type="search" autocomplete="off" placeholder="Buscar: peso, lista, botón, ventana…" aria-label="Buscar un ajuste tipográfico">
      </label>

      <div class="pdd-typography-presets" role="group" aria-label="Perfiles rápidos de tipografía">
        <button type="button" data-pdd-typography-preset="compact">Compacta</button>
        <button type="button" data-pdd-typography-preset="standard">Estándar</button>
        <button type="button" data-pdd-typography-preset="large">Grande</button>
        <button type="button" data-pdd-typography-preset="accessible">Alta legibilidad</button>
      </div>

      <div class="pdd-typography-group-tools">
        <button id="pddTypographyExpandAll" type="button">Abrir todos</button>
        <button id="pddTypographyCollapseAll" type="button">Cerrar todos</button>
      </div>
    </section>

    <div id="pddTypographyControls" class="pdd-typography-controls"></div>

    <footer class="pdd-typography-footer">
      <button id="pddTypographyResetAll" type="button">Restablecer todo</button>
      <button id="pddTypographyDone" type="button">Cerrar</button>
    </footer>
  </aside>

  <script type="module" src="{{ asset('js/despacho-productos-despacho.js') }}?v={{ filemtime(public_path('js/despacho-productos-despacho.js')) }}"></script>
</body>
</html>
