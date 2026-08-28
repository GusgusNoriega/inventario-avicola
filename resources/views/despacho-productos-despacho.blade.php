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
        <a class="pdd-menu-action" href="{{ route('despacho-productos.menu') }}" aria-label="Volver al módulo Despacho de productos">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path></svg>
          <span>Módulo</span>
        </a>
      </div>
    </header>

    <section class="pdd-capture-deck" aria-label="Captura de producto y peso">
      <article class="pdd-product-panel pdd-panel">
        <div class="pdd-panel-heading">
          <div><span>Producto a despachar</span><strong id="pddSelectedName">Ningún producto seleccionado</strong></div>
          <button id="pddChooseProduct" class="pdd-choose-product" type="button" aria-haspopup="dialog" aria-controls="pddProductDialog">
            <span aria-hidden="true">＋</span> Elegir producto
          </button>
        </div>

        <div class="pdd-product-scale-row">
          <button id="pddProductMedia" class="pdd-product-media" type="button" aria-label="Elegir producto">
            <span class="pdd-media-placeholder"><b>?</b><small>Elige un producto</small></span>
          </button>

          <div class="pdd-scale-reading-wrap">
            <div class="pdd-scale-reading-head">
              <span id="pddWeightSource">Sin lectura</span>
              <span id="pddReadingState">Esperando peso</span>
            </div>
            <output id="pddLiveWeight" class="pdd-live-weight">---<small>kg</small></output>
            <div class="pdd-scale-actions">
              <button id="pddManualWeight" class="pdd-secondary-touch" type="button" aria-haspopup="dialog" aria-controls="pddManualDialog">
                <span aria-hidden="true">✎</span> Peso manual
              </button>
              <button id="pddCaptureWeight" class="pdd-capture-touch" type="button" disabled>
                <span aria-hidden="true">◎</span> Capturar peso
              </button>
            </div>
          </div>
        </div>
      </article>

      <article class="pdd-config-panel pdd-panel">
        <div class="pdd-config-head">
          <div><span>Configuración de la pesada</span><strong id="pddSelectedVariantLabel">Producto base</strong></div>
          <button id="pddChangePrice" class="pdd-link-button" type="button" aria-haspopup="dialog" aria-controls="pddPriceDialog">Cambiar precios del ticket</button>
        </div>

        <div class="pdd-fields-grid">
          <label>
            <span>Cantidad</span>
            <span class="pdd-stepper">
              <button type="button" data-pdd-quantity-step="-1" aria-label="Disminuir cantidad">−</button>
              <input id="pddQuantity" type="number" min="1" max="100000" step="1" inputmode="numeric" value="1">
              <button type="button" data-pdd-quantity-step="1" aria-label="Aumentar cantidad">＋</button>
            </span>
          </label>
          <label>
            <span>Precio de venta</span>
            <strong id="pddUnitPrice">S/ --</strong>
            <small id="pddPriceMode">Selecciona un producto</small>
          </label>
          <label>
            <span>Merma total</span>
            <span class="pdd-suffix-input"><input id="pddWasteTotal" type="number" min="0" max="1000000000" step="1" inputmode="numeric" value="0"><b>g</b></span>
            <small id="pddWasteHint">Se calcula según la cantidad y puedes modificarla.</small>
          </label>
          <div class="pdd-net-preview">
            <span>Peso neto estimado</span>
            <strong id="pddNetPreview">--- kg</strong>
            <small id="pddAmountPreview">Total estimado S/ --</small>
          </div>
        </div>

        <div class="pdd-variations-block">
          <div class="pdd-variations-heading"><span>Variaciones</span><small>Desliza para ver todas</small></div>
          <div id="pddVariations" class="pdd-variations-slider" role="listbox" aria-label="Variaciones del producto">
            <span class="pdd-variation-empty">Elige un producto para ver sus variaciones.</span>
          </div>
        </div>
      </article>
    </section>

    <section class="pdd-workspace" aria-label="Ocho listas de despacho">
      <div class="pdd-lists-area">
        <div class="pdd-lists-heading">
          <div><span>Distribución de tickets</span><strong>Selecciona una lista y agrega sus pesadas</strong></div>
          <small>Desliza horizontalmente para ver las listas 5 a 8.</small>
        </div>
        <div id="pddLists" class="pdd-lists-grid"></div>
      </div>

      <aside class="pdd-action-rail" aria-label="Acciones del ticket activo">
        <div class="pdd-active-list-badge"><span>Lista activa</span><strong id="pddActiveList">1</strong></div>
        <button id="pddAssignClient" class="pdd-rail-action is-client" type="button" aria-haspopup="dialog" aria-controls="pddClientDialog">
          <span aria-hidden="true">♙</span><span><b id="pddClientActionLabel">Asignar cliente</b><small id="pddClientActionDetail">Venta al público</small></span>
        </button>
        <button id="pddRailChangePrice" class="pdd-rail-action is-price" type="button" aria-haspopup="dialog" aria-controls="pddPriceDialog">
          <span aria-hidden="true">$</span><span><b>Cambiar precio</b><small>Del ticket activo</small></span>
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

    <footer class="pdd-statusbar">
      <p id="pddMessage" role="status" aria-live="polite">Preparando la estación de despacho…</p>
      <div class="pdd-footer-summary">
        <span>Pesadas <strong id="pddFooterWeighings">0</strong></span>
        <span>Unidades <strong id="pddFooterQuantity">0</strong></span>
        <span>Merma <strong id="pddFooterWaste">0 g</strong></span>
        <span>Neto <strong id="pddFooterNet">0.000 kg</strong></span>
      </div>
    </footer>

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
        <span>Peso leído</span>
        <span><input id="pddManualInput" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" placeholder="0.000" required><b>kg</b></span>
      </label>
      <p>El peso se marcará como manual y podrá editarse antes de guardar.</p>
      <footer class="pdd-dialog-actions">
        <button class="pdd-dialog-cancel" type="button" data-pdd-close="pddManualDialog">Cancelar</button>
        <button class="pdd-dialog-confirm" type="submit">Usar y agregar pesada</button>
      </footer>
    </form>
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
        <label><span>Cantidad</span><input id="pddEditQuantity" type="number" min="1" max="100000" step="1" required></label>
        <label><span>Peso leído (kg)</span><input id="pddEditWeight" type="number" min="0.001" max="999999999.999" step="0.001" required></label>
        <label><span>Merma total (g)</span><input id="pddEditWaste" type="number" min="0" max="1000000000" step="1" required></label>
        <label><span>Precio de venta</span><input id="pddEditPrice" type="number" min="0.0001" max="9999999999.9999" step="0.0001" required></label>
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

  <dialog id="pddPriceDialog" class="pdd-dialog pdd-price-dialog" aria-labelledby="pddPriceDialogTitle">
    <form id="pddPriceForm">
      <header class="pdd-dialog-head">
        <div><p>Lista activa</p><h2 id="pddPriceDialogTitle">Cambiar precios del ticket</h2></div>
        <button type="button" data-pdd-close="pddPriceDialog" aria-label="Cerrar">×</button>
      </header>
      <p class="pdd-dialog-intro">El precio modificado se aplicará a las pesadas existentes y a las próximas pesadas del mismo producto o variación en esta lista.</p>
      <div id="pddPriceRows" class="pdd-price-rows"></div>
      <footer class="pdd-dialog-actions">
        <button class="pdd-dialog-cancel" type="button" data-pdd-close="pddPriceDialog">Cancelar</button>
        <button class="pdd-dialog-confirm" type="submit">Aplicar precios</button>
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

  <script type="module" src="{{ asset('js/despacho-productos-despacho.js') }}?v={{ filemtime(public_path('js/despacho-productos-despacho.js')) }}"></script>
</body>
</html>
