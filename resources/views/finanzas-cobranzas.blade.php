<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Cobranzas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body
  class="fin-page fin-collections-page"
  data-can-manage-collections="{{ auth()->user()->hasPermission('PAGOS_REGISTRAR') ? '1' : '0' }}"
  data-can-void-collections="{{ auth()->user()->hasPermission('PAGOS_ANULAR') ? '1' : '0' }}"
>
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'cobranzas',
      'eyebrow' => 'Recaudación en efectivo',
      'title' => 'Cobranzas',
      'description' => 'Consolida el efectivo recibido de varios clientes en un solo depósito, sin perder el origen ni la aplicación de cada abono.'
    ])

    <section class="fin-card fin-collection-guide" aria-label="Cómo se registra una cobranza">
      <article>
        <span>01</span>
        <div><strong>Elige el depósito</strong><small>Registra el cobrador, el destino y el número único de la operación.</small></div>
      </article>
      <article>
        <span>02</span>
        <div><strong>Desglosa el efectivo</strong><small>Indica cuánto entregó cada cliente y cuándo se recibió.</small></div>
      </article>
      <article>
        <span>03</span>
        <div><strong>Revisa la conciliación</strong><small>Si falta identificar una parte, quedará separada y visible hasta que se determine su origen.</small></div>
      </article>
    </section>

    <form id="collectionForm" class="fin-collection-layout" novalidate>
      <div class="fin-collection-form-main">
        <section class="fin-card fin-collection-form-section" aria-labelledby="collectionDepositTitle">
          <div class="fin-section-head fin-section-head-wrap">
            <div>
              <p class="fin-eyebrow">Paso 1</p>
              <h2 id="collectionDepositTitle">Datos del depósito único</h2>
              <p class="fin-section-copy">La referencia y la cuenta de destino se compartirán con todos los abonos del desglose.</p>
            </div>
            <button id="collectionManageCollectors" class="fin-btn fin-btn-ghost fin-btn-small" type="button" aria-haspopup="dialog" aria-controls="collectorDialog">
              Administrar cobradores
            </button>
          </div>

          <div class="fin-form-grid fin-collection-form-grid">
            <label class="fin-field">
              <span>Cobrador responsable <b>*</b></span>
              <select id="collectionCollector" required><option value="">Cargando cobradores...</option></select>
              <small>Identifica a la persona que reunió y depositó el efectivo.</small>
            </label>
            <label class="fin-field">
              <span>Fecha y hora del depósito <b>*</b></span>
              <input id="collectionDateTime" type="datetime-local" required>
            </label>
            <label class="fin-field fin-grid-span-2">
              <span>Cuenta donde se depositó <b>*</b></span>
              <select id="collectionDestination" required><option value="">Cargando cuentas...</option></select>
              <small id="collectionDestinationHelp">Selecciona una cuenta propia o una cuenta externa asociada a un proveedor.</small>
            </label>
            <label class="fin-field">
              <span>Importe total del voucher <b>*</b></span>
              <div class="fin-money-input">
                <span id="collectionCurrencyPrefix">S/</span>
                <input id="collectionTotal" type="number" min="0.01" step="0.01" inputmode="decimal" required placeholder="0.00">
              </div>
            </label>
            <label class="fin-field">
              <span>Moneda <b>*</b></span>
              <select id="collectionCurrency" required>
                <option value="PEN">Soles (PEN)</option>
                <option value="USD">Dólares (USD)</option>
              </select>
            </label>
            <label class="fin-field">
              <span>Número de operación / voucher <b>*</b></span>
              <input id="collectionReference" type="text" maxlength="100" autocomplete="off" required placeholder="Ej: OP-384729">
              <small>Se conservará como referencia común de todos los clientes.</small>
            </label>
            <label class="fin-field">
              <span>Observaciones</span>
              <textarea id="collectionNotes" rows="2" maxlength="2000" placeholder="Detalle adicional del depósito"></textarea>
            </label>
          </div>

          <div id="collectionDestinationNotice" class="fin-collection-destination-note" role="status" aria-live="polite">
            Selecciona una cuenta para conocer cómo se aplicará el depósito.
          </div>
        </section>

        <section class="fin-card fin-collection-form-section" aria-labelledby="collectionBreakdownTitle">
          <div class="fin-section-head fin-section-head-wrap">
            <div>
              <p class="fin-eyebrow">Paso 2</p>
              <h2 id="collectionBreakdownTitle">Desglose por cliente</h2>
              <p class="fin-section-copy">Cada importe se aplicará automáticamente a las deudas más antiguas del cliente; cualquier excedente quedará como saldo a favor.</p>
            </div>
            <button id="collectionAddDetail" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Agregar cliente</button>
          </div>
          <p id="collectionDetailsMessage" class="fin-message" role="status" aria-live="polite"></p>
          <div id="collectionDetails" class="fin-purchase-lines fin-collection-lines" aria-live="polite"></div>
        </section>
      </div>

      <aside class="fin-card fin-application-summary fin-collection-summary" aria-labelledby="collectionSummaryTitle">
        <p class="fin-eyebrow">Conciliación</p>
        <h2 id="collectionSummaryTitle">Comprobación del voucher</h2>
        <div class="fin-summary-line"><span>Total del depósito</span><strong id="collectionSummaryTotal">S/ 0.00</strong></div>
        <div class="fin-summary-line"><span>Importe desglosado</span><strong id="collectionSummaryDetails">S/ 0.00</strong></div>
        <div class="fin-summary-line"><span>Abonos incluidos</span><strong id="collectionSummaryCount">0</strong></div>
        <div id="collectionDifferenceLine" class="fin-summary-line fin-summary-line-total fin-collection-difference is-pending">
          <span id="collectionDifferenceLabel">Falta distribuir</span>
          <strong id="collectionSummaryDifference">S/ 0.00</strong>
        </div>
        <label id="collectionPendingConfirmationWrap" class="fin-collection-pending-confirmation" hidden>
          <input id="collectionPendingConfirmation" type="checkbox">
          <span id="collectionPendingConfirmationText">Registrar el importe pendiente por identificar.</span>
        </label>
        <p id="collectionSummaryHint" class="fin-summary-hint">Ingresa el total del voucher y agrega al menos un cliente.</p>
        <p id="collectionMessage" class="fin-message" role="status" aria-live="polite"></p>
        <button id="collectionSave" class="fin-btn fin-btn-primary fin-btn-block" type="submit" disabled>Registrar cobranza</button>
        <button id="collectionReset" class="fin-btn fin-btn-ghost fin-btn-block" type="button">Limpiar formulario</button>
      </aside>
    </form>

    <section class="fin-card fin-collection-history" aria-labelledby="collectionHistoryTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Control y seguimiento</p>
          <h2 id="collectionHistoryTitle">Historial de cobranzas</h2>
          <p class="fin-section-copy">Consulta el depósito consolidado y abre su detalle para revisar el aporte de cada cliente.</p>
        </div>
        <button id="collectionRefresh" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Actualizar</button>
      </div>

      <form id="collectionFilters" class="fin-filter-grid fin-collection-filters" novalidate>
        <label class="fin-field">
          <span>Desde</span>
          <input id="collectionFilterFrom" type="date">
        </label>
        <label class="fin-field">
          <span>Hasta</span>
          <input id="collectionFilterTo" type="date">
        </label>
        <label class="fin-field">
          <span>Cobrador</span>
          <select id="collectionFilterCollector"><option value="">Todos</option></select>
        </label>
        <label class="fin-field">
          <span>Estado</span>
          <select id="collectionFilterStatus">
            <option value="">Todos</option>
            <option value="REGISTRADO">Vigentes</option>
            <option value="ANULADO">Anulados</option>
          </select>
        </label>
        <label class="fin-field">
          <span>Conciliación</span>
          <select id="collectionFilterReconciliation">
            <option value="">Todas</option>
            <option value="PENDIENTE">Con monto por identificar</option>
            <option value="COMPLETA">Totalmente desglosadas</option>
          </select>
        </label>
        <label class="fin-field fin-filter-search">
          <span>Buscar</span>
          <input id="collectionFilterSearch" type="search" maxlength="100" placeholder="Código, operación, cobrador o destino">
        </label>
        <div class="fin-filter-actions">
          <button class="fin-btn fin-btn-primary" type="submit">Aplicar filtros</button>
          <button id="collectionClearFilters" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
        </div>
      </form>

      <p id="collectionListMessage" class="fin-message" role="status" aria-live="polite">Cargando cobranzas...</p>
      <div class="fin-table-wrap">
        <table class="fin-table fin-collection-table">
          <thead>
            <tr>
              <th>Fecha / código</th>
              <th>Cobrador</th>
              <th>Cuenta de destino</th>
              <th>Operación</th>
              <th>Desglose</th>
              <th>Estado</th>
              <th class="fin-text-right">Total</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="collectionRows"></tbody>
        </table>
      </div>
      <div class="fin-pagination">
        <button id="collectionPrevious" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Anterior</button>
        <span id="collectionPage">Página 1</span>
        <button id="collectionNext" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Siguiente</button>
      </div>
    </section>
  </main>

  <dialog id="collectorDialog" class="fin-purchase-dialog fin-collector-dialog" aria-labelledby="collectorDialogTitle">
    <div class="fin-purchase-dialog-card">
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Catálogo operativo</p><h2 id="collectorDialogTitle">Administrar cobradores</h2></div>
        <button class="fin-dialog-close" type="button" data-collection-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p class="fin-collector-intro">Agrega las personas responsables de reunir el efectivo. Desactivar un cobrador no elimina sus registros anteriores.</p>
      <div class="fin-collector-workspace">
        <form id="collectorForm" class="fin-form fin-collector-form" novalidate>
          <input id="collectorId" type="hidden">
          <div class="fin-form-subhead">
            <h3 id="collectorFormTitle">Agregar cobrador</h3>
            <span id="collectorEditBadge" class="fin-badge" hidden>Editando</span>
          </div>
          <label class="fin-field">
            <span>Nombre del cobrador <b>*</b></span>
            <input id="collectorName" type="text" minlength="2" maxlength="180" autocomplete="name" required placeholder="Ej: Carlos Ramírez">
          </label>
          <p id="collectorFormMessage" class="fin-message" role="status" aria-live="polite"></p>
          <div class="fin-form-actions">
            <button id="collectorSave" class="fin-btn fin-btn-primary" type="submit">Guardar cobrador</button>
            <button id="collectorCancel" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
          </div>
        </form>
        <section class="fin-collector-list-panel" aria-labelledby="collectorListTitle">
          <div class="fin-form-subhead"><h3 id="collectorListTitle">Cobradores registrados</h3><span id="collectorCount" class="fin-badge">0</span></div>
          <p id="collectorListMessage" class="fin-message" role="status" aria-live="polite"></p>
          <div id="collectorList" class="fin-collector-list" aria-live="polite"></div>
        </section>
      </div>
    </div>
  </dialog>

  <dialog id="collectionDetailDialog" class="fin-purchase-dialog fin-collection-detail-dialog" aria-labelledby="collectionDetailTitle">
    <div class="fin-purchase-dialog-card">
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Trazabilidad del depósito</p><h2 id="collectionDetailTitle">Detalle de cobranza</h2></div>
        <button class="fin-dialog-close" type="button" data-collection-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="collectionDetailMessage" class="fin-message" role="status" aria-live="polite">Cargando detalle...</p>
      <div id="collectionDetailContent" class="fin-collection-detail-content" hidden></div>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-collection-dialog-close>Cerrar</button>
      </footer>
    </div>
  </dialog>

  <dialog id="collectionAssignDialog" class="fin-purchase-dialog fin-collection-assign-dialog" aria-labelledby="collectionAssignTitle">
    <form id="collectionAssignForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Conciliación posterior</p><h2 id="collectionAssignTitle">Asignar saldo pendiente</h2></div>
        <button class="fin-dialog-close" type="button" data-collection-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="collectionAssignIntro" class="fin-section-copy">Agrega la identificación faltante sin modificar los abonos que ya fueron aplicados.</p>
      <div id="collectionAssignFacts" class="fin-collection-detail-facts fin-collection-assign-facts" aria-live="polite"></div>

      <section class="fin-collection-assign-workspace" aria-labelledby="collectionAssignBreakdownTitle">
        <div class="fin-section-head fin-section-head-wrap">
          <div>
            <p class="fin-eyebrow">Nuevo desglose</p>
            <h2 id="collectionAssignBreakdownTitle">Clientes identificados</h2>
            <p class="fin-section-copy">Puedes distribuir todo el saldo o solo una parte; el remanente seguirá pendiente.</p>
          </div>
          <button id="collectionAssignAddDetail" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Agregar cliente</button>
        </div>
        <div id="collectionAssignDetails" class="fin-purchase-lines fin-collection-lines" aria-live="polite"></div>
      </section>

      <div class="fin-collection-assign-summary" aria-live="polite">
        <div><span>Saldo disponible</span><strong id="collectionAssignAvailable">S/ 0.00</strong></div>
        <div><span>A asignar ahora</span><strong id="collectionAssignTotal">S/ 0.00</strong></div>
        <div id="collectionAssignRemainingLine"><span>Quedará pendiente</span><strong id="collectionAssignRemaining">S/ 0.00</strong></div>
      </div>
      <p id="collectionAssignHint" class="fin-summary-hint">Agrega al menos un cliente para continuar.</p>
      <p id="collectionAssignMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-collection-dialog-close>Cancelar</button>
        <button id="collectionAssignSubmit" class="fin-btn fin-btn-primary" type="submit" disabled>Asignar saldo</button>
      </footer>
    </form>
  </dialog>

  <dialog id="collectionVoidDialog" class="fin-purchase-dialog fin-void-dialog" aria-labelledby="collectionVoidTitle">
    <form id="collectionVoidForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Reversa con trazabilidad</p><h2 id="collectionVoidTitle">Anular cobranza</h2></div>
        <button class="fin-dialog-close" type="button" data-collection-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="collectionVoidDescription" class="fin-section-copy"></p>
      <label class="fin-field">
        <span>Motivo de anulación <b>*</b></span>
        <textarea id="collectionVoidReason" rows="4" minlength="5" maxlength="250" required placeholder="Explica por qué se anula esta cobranza"></textarea>
      </label>
      <p id="collectionVoidMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-collection-dialog-close>Cancelar</button>
        <button id="collectionVoidSubmit" class="fin-btn fin-btn-danger" type="submit">Confirmar anulación</button>
      </footer>
    </form>
  </dialog>

  <script type="module" src="{{ asset('js/finanzas-cobranzas.js') }}?v={{ filemtime(public_path('js/finanzas-cobranzas.js')) }}"></script>
</body>
</html>
