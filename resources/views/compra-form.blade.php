<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registrar compra | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body class="fin-page fin-purchase-form-page" data-purchases-url="{{ route('compras.index') }}">
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'compras',
      'eyebrow' => 'Nueva obligación de proveedor',
      'title' => 'Registrar compra',
      'description' => 'Guarda el documento y sus productos; elige si queda pendiente o si se paga al momento.'
    ])

    <section class="fin-purchase-form-guide fin-card" aria-label="Efecto financiero de la compra">
      <div><strong>Crédito</strong><span>La compra crea una cuenta por pagar. Después podrás abonarla desde Finanzas.</span></div>
      <div><strong>Contado</strong><span>La compra y la salida de dinero se registran juntas y el documento queda pagado.</span></div>
      <div><strong>Importante</strong><span>Un depósito del cliente a nuestra empresa no paga al proveedor; un pago directo al proveedor sí reduce las dos deudas.</span></div>
    </section>

    <form id="purchaseForm" class="fin-purchase-form" novalidate>
      <div class="fin-purchase-form-columns">
        <div class="fin-purchase-form-main">
          <section class="fin-card fin-purchase-form-section" aria-labelledby="purchaseHeaderTitle">
            <div class="fin-section-head">
              <div>
                <p class="fin-eyebrow">Paso 1</p>
                <h2 id="purchaseHeaderTitle">Proveedor y documento</h2>
              </div>
              <span id="purchaseConditionBadge" class="fin-badge">Crédito</span>
            </div>

            <div class="fin-segmented fin-purchase-condition" role="radiogroup" aria-label="Condición de pago">
              <label>
                <input type="radio" name="purchaseCondition" value="CREDITO" checked>
                <span><strong>Compra a crédito</strong><small>Genera saldo pendiente con el proveedor.</small></span>
              </label>
              <label>
                <input type="radio" name="purchaseCondition" value="CONTADO">
                <span><strong>Compra al contado</strong><small>Registra también la salida de dinero y queda pagada.</small></span>
              </label>
            </div>

            <div class="fin-form-grid fin-purchase-header-grid">
              <label class="fin-field fin-grid-span-2">
                <span>Proveedor <b>*</b></span>
                <select id="purchaseProvider" required><option value="">Selecciona un proveedor</option></select>
              </label>
              <label class="fin-field">
                <span>Tipo de documento <b>*</b></span>
                <select id="purchaseDocumentType" required>
                  <option value="FACTURA">Factura</option>
                  <option value="BOLETA">Boleta</option>
                  <option value="NOTA_VENTA">Nota de venta</option>
                  <option value="GUIA">Guía / remisión</option>
                  <option value="INTERNO">Documento interno</option>
                  <option value="OTRO">Otro</option>
                </select>
              </label>
              <label class="fin-field">
                <span>Número del documento</span>
                <input id="purchaseDocumentNumber" type="text" maxlength="80" autocomplete="off" placeholder="Ej: F001-000184">
              </label>
              <label class="fin-field">
                <span>Fecha de compra <b>*</b></span>
                <input id="purchaseDate" type="date" required>
              </label>
              <label id="purchaseDueDateField" class="fin-field">
                <span>Fecha de vencimiento <b>*</b></span>
                <input id="purchaseDueDate" type="date" required>
              </label>
              <label class="fin-field">
                <span>Moneda</span>
                <select id="purchaseCurrency">
                  <option value="PEN">Soles (PEN)</option>
                  <option value="USD">Dólares (USD)</option>
                </select>
              </label>
              <label class="fin-field fin-grid-span-2">
                <span>Observaciones</span>
                <textarea id="purchaseNotes" rows="2" maxlength="2000" placeholder="Detalle adicional de la compra"></textarea>
              </label>
            </div>
          </section>

          <section class="fin-card fin-purchase-form-section" aria-labelledby="purchaseLinesTitle">
            <div class="fin-section-head fin-section-head-wrap">
              <div>
                <p class="fin-eyebrow">Paso 2</p>
                <h2 id="purchaseLinesTitle">Detalle comprado</h2>
                <p>El importe de cada línea se calcula con el peso por el precio por kilogramo.</p>
              </div>
              <button id="purchaseAddLine" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Agregar producto</button>
            </div>
            <p id="purchaseLinesMessage" class="fin-message" role="status" aria-live="polite"></p>
            <div id="purchaseLines" class="fin-purchase-lines" aria-live="polite"></div>
          </section>

          <section id="purchaseCashPanel" class="fin-card fin-purchase-form-section fin-purchase-cash-panel" aria-labelledby="purchaseCashTitle" hidden>
            <div class="fin-section-head">
              <div>
                <p class="fin-eyebrow">Paso 3</p>
                <h2 id="purchaseCashTitle">Pago al proveedor</h2>
                <p>Estos datos crean la salida de dinero y aplican el pago completo a la compra.</p>
              </div>
              <span class="fin-badge">Salida propia</span>
            </div>
            <div class="fin-form-grid fin-purchase-payment-grid">
              <label class="fin-field">
                <span>Cuenta propia de origen <b>*</b></span>
                <select id="purchaseOriginAccount"><option value="">Selecciona una cuenta propia</option></select>
                <small id="purchaseOriginHelp">El total se descontará de esta cuenta.</small>
              </label>
              <label class="fin-field">
                <span>Cuenta receptora del proveedor <b>*</b></span>
                <select id="purchaseDestinationAccount"><option value="">Selecciona una cuenta del proveedor</option></select>
                <small>Solo aparecen cuentas externas asociadas al proveedor elegido.</small>
              </label>
              <label class="fin-field">
                <span>Método de pago <b>*</b></span>
                <select id="purchasePaymentMethod"><option value="">Selecciona un método</option></select>
              </label>
              <label class="fin-field">
                <span>Fecha y hora del pago <b>*</b></span>
                <input id="purchasePaymentDate" type="datetime-local">
              </label>
              <label class="fin-field fin-grid-span-2">
                <span id="purchasePaymentReferenceLabel">Número de operación / referencia</span>
                <input id="purchasePaymentReference" type="text" maxlength="100" autocomplete="off" placeholder="Ej: OP-384729">
              </label>
            </div>
          </section>
        </div>

        <aside class="fin-card fin-application-summary fin-purchase-total-panel" aria-labelledby="purchaseSummaryTitle">
          <p class="fin-eyebrow">Resumen</p>
          <h2 id="purchaseSummaryTitle">Total de la compra</h2>
          <div class="fin-summary-line"><span>Subtotal</span><strong id="purchaseSubtotal">S/ 0.00</strong></div>
          <label class="fin-purchase-tax-line">
            <span>Impuesto</span>
            <span class="fin-purchase-tax-input">S/<input id="purchaseTax" type="number" min="0" step="0.01" inputmode="decimal" value="0.00" aria-label="Impuesto de la compra"></span>
          </label>
          <div class="fin-summary-line fin-summary-line-total"><span>Total</span><strong id="purchaseTotal">S/ 0.00</strong></div>
          <p id="purchaseSummaryHint" class="fin-summary-hint">Al guardar se creará una deuda por el total de la compra.</p>
          <p id="purchaseFormMessage" class="fin-message" role="status" aria-live="polite"></p>
          <button id="purchaseSave" class="fin-btn fin-btn-primary fin-btn-block" type="submit">Registrar compra a crédito</button>
          <button id="purchaseReset" class="fin-btn fin-btn-ghost fin-btn-block" type="button">Limpiar formulario</button>
          <a class="fin-btn fin-btn-ghost fin-btn-block" href="{{ route('compras.index') }}">Volver al historial</a>
        </aside>
      </div>
    </form>
  </main>

  <script type="module" src="{{ asset('js/compra-form.js') }}"></script>
</body>
</html>
