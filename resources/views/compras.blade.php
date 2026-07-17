<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Compras a proveedores | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body class="fin-page fin-purchases-page" data-finance-movement-url="{{ route('finanzas.movimientos.nuevo') }}">
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'compras',
      'eyebrow' => 'Abastecimiento y cuentas por pagar',
      'title' => 'Compras a proveedores',
      'description' => 'Registra cada compra al contado o a crédito sin depender del despacho mayorista.'
    ])

    <section class="fin-purchase-guide fin-card" aria-label="Cómo se conectan compras y finanzas">
      <article>
        <span class="fin-purchase-guide-mark is-credit" aria-hidden="true">C</span>
        <div><strong>Compra a crédito</strong><small>Crea una deuda con el proveedor y queda disponible para pagos parciales o totales.</small></div>
      </article>
      <article>
        <span class="fin-purchase-guide-mark is-cash" aria-hidden="true">$</span>
        <div><strong>Compra al contado</strong><small>Registra la compra y la salida de una cuenta propia en una sola operación; queda pagada.</small></div>
      </article>
      <article class="fin-purchase-guide-wide">
        <span class="fin-purchase-guide-mark is-direct" aria-hidden="true">→</span>
        <div>
          <strong>Depósitos de clientes</strong>
          <small>Cliente → nuestra empresa aumenta nuestro saldo y no reduce lo adeudado al proveedor. Cliente → proveedor (pago directo) sí reduce ambas deudas.</small>
        </div>
      </article>
    </section>

    <section class="fin-summary-grid" aria-label="Resumen de compras">
      <article class="fin-summary-card fin-card">
        <div><span>Total comprado</span><small>Importe del período consultado</small></div>
        <strong id="purchaseTotalAmount">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card fin-summary-balance">
        <div><span>Compras al contado</span><small>Pagadas al momento de registrarlas</small></div>
        <strong id="purchaseCashAmount">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card fin-summary-direct">
        <div><span>Compras a crédito</span><small>Total adquirido con pago posterior</small></div>
        <strong id="purchaseCreditAmount">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card fin-summary-legacy">
        <div><span>Históricas sin clasificar</span><small>Importadas del modelo financiero anterior</small></div>
        <strong id="purchaseLegacyAmount">S/ 0.00</strong>
      </article>
      <article class="fin-summary-card fin-card fin-summary-payable">
        <div><span>Saldo por pagar</span><small>Deuda vigente con proveedores</small></div>
        <strong id="purchasePendingAmount">S/ 0.00</strong>
      </article>
    </section>

    <section class="fin-card fin-purchase-list-panel" aria-labelledby="purchaseListTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Historial independiente</p>
          <h2 id="purchaseListTitle">Compras registradas</h2>
          <p>Consulta el documento, su condición de pago y el saldo que aún se debe.</p>
        </div>
        <a class="fin-btn fin-btn-primary" href="{{ route('compras.create') }}">Registrar nueva compra</a>
      </div>

      <form id="purchaseFilters" class="fin-filter-grid fin-purchase-filter-grid" novalidate>
        <label class="fin-field">
          <span>Desde</span>
          <input id="purchaseFilterFrom" type="date" name="desde">
        </label>
        <label class="fin-field">
          <span>Hasta</span>
          <input id="purchaseFilterTo" type="date" name="hasta">
        </label>
        <label class="fin-field">
          <span>Proveedor</span>
          <select id="purchaseFilterProvider" name="proveedor_id"><option value="">Todos</option></select>
        </label>
        <label class="fin-field">
          <span>Condición</span>
          <select id="purchaseFilterCondition" name="condicion">
            <option value="">Todas</option>
            <option value="CONTADO">Contado</option>
            <option value="CREDITO">Crédito</option>
            <option value="LEGADO">Histórica sin clasificar</option>
          </select>
        </label>
        <label class="fin-field">
          <span>Moneda</span>
          <select id="purchaseFilterCurrency" name="moneda">
            <option value="">Moneda de la empresa</option>
            <option value="PEN">Soles (PEN)</option>
            <option value="USD">Dólares (USD)</option>
          </select>
        </label>
        <label class="fin-field">
          <span>Estado</span>
          <select id="purchaseFilterStatus" name="estado">
            <option value="">Todos</option>
            <option value="PENDIENTE">Pendiente</option>
            <option value="PARCIAL">Pago parcial</option>
            <option value="PAGADO">Pagado</option>
            <option value="ANULADO">Anulado</option>
          </select>
        </label>
        <label class="fin-field fin-filter-search">
          <span>Documento o proveedor</span>
          <input id="purchaseFilterSearch" type="search" name="buscar" placeholder="Factura, código o nombre">
        </label>
        <div class="fin-filter-actions">
          <button class="fin-btn fin-btn-primary" type="submit">Aplicar filtros</button>
          <button id="purchaseClearFilters" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
        </div>
      </form>

      <p id="purchaseListMessage" class="fin-message" role="status" aria-live="polite">Cargando compras...</p>
      <div class="fin-table-wrap">
        <table class="fin-table fin-purchase-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Documento</th>
              <th>Proveedor</th>
              <th>Condición</th>
              <th class="fin-text-right">Total</th>
              <th class="fin-text-right">Saldo pendiente</th>
              <th>Estado</th>
              <th><span class="fin-sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody id="purchaseRows"></tbody>
        </table>
      </div>
      <div class="fin-pagination" aria-label="Paginación de compras">
        <button id="purchasePrevious" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Anterior</button>
        <span id="purchasePage">Página 1</span>
        <button id="purchaseNext" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Siguiente</button>
      </div>
    </section>
  </main>

  <dialog id="purchaseDetailDialog" class="fin-purchase-dialog" aria-labelledby="purchaseDetailTitle">
    <section class="fin-purchase-dialog-card">
      <header class="fin-purchase-dialog-head">
        <div>
          <p class="fin-eyebrow">Detalle de compra</p>
          <h2 id="purchaseDetailTitle">Consultando compra...</h2>
        </div>
        <button id="purchaseDetailClose" class="fin-dialog-close" type="button" aria-label="Cerrar detalle">×</button>
      </header>
      <p id="purchaseDetailMessage" class="fin-message" role="status" aria-live="polite"></p>
      <div id="purchaseDetailContent" class="fin-purchase-detail-content"></div>

      <section id="purchaseVoidPanel" class="fin-purchase-void-panel" hidden>
        <label class="fin-field">
          <span>Motivo de anulación <b>*</b></span>
          <textarea id="purchaseVoidReason" rows="3" maxlength="250" placeholder="Explica por qué debe anularse esta compra"></textarea>
        </label>
        <p id="purchaseVoidMessage" class="fin-message" role="status" aria-live="polite"></p>
        <div class="fin-form-actions">
          <button id="purchaseVoidConfirm" class="fin-btn fin-btn-danger" type="button">Confirmar anulación</button>
          <button id="purchaseVoidCancel" class="fin-btn fin-btn-ghost" type="button">Cancelar</button>
        </div>
      </section>

      <footer class="fin-purchase-dialog-actions">
        <a id="purchaseCompanyPayment" class="fin-btn fin-btn-primary" href="{{ route('finanzas.movimientos.nuevo') }}?tipo=PAGO_PROVEEDOR" hidden>Pagar desde nuestra empresa</a>
        <a id="purchaseDirectPayment" class="fin-btn fin-btn-ghost" href="{{ route('finanzas.movimientos.nuevo') }}?tipo=PAGO_DIRECTO" hidden>Registrar pago directo de cliente</a>
        <button id="purchaseVoidStart" class="fin-btn fin-btn-danger" type="button" hidden>Anular compra</button>
      </footer>
    </section>
  </dialog>

  <script type="module" src="{{ asset('js/compras.js') }}"></script>
</body>
</html>
