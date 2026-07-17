<!doctype html>
<html lang="es" class="java-control-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Inventario de Javas y Bandejas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="java-control-page" data-java-page="inventory">
  <main class="java-control-shell java-subview-shell">
    @include('partials.java-control-header', [
      'eyebrow' => 'Control de activos',
      'title' => 'Inventario y conteo físico',
      'description' => 'Ubica las javas y bandejas de la jornada entre clientes, camiones y local, y comprueba que el total cuadre.',
    ])

    <section class="java-count-journey card" aria-label="Jornada del conteo diario">
      <div>
        <p class="eyebrow">Jornada actual</p>
        <strong id="javaCountJourneyTitle">Preparando la jornada operativa</strong>
        <small id="javaCountJourneyWindow">El conteo se guardará en la jornada vigente.</small>
      </div>
      <span id="javaCountJourneyState" class="java-count-state">Sin conteo</span>
    </section>

    <section class="java-summary java-summary-four java-company-summary" aria-label="Ubicación general de javas y bandejas">
      <article class="java-summary-card card">
        <span>Total propiedad de la empresa</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaCompanyTotal">—</strong></span>
          <span><small>Bandejas</small><strong id="trayCompanyTotal">—</strong></span>
        </div>
        <small>Todo lo que debe quedar explicado</small>
      </article>
      <article class="java-summary-card card">
        <span>Para conteo directo</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaCompanyInside">—</strong></span>
          <span><small>Bandejas</small><strong id="trayCompanyInside">—</strong></span>
        </div>
        <small>Debe estar en el local o en camiones</small>
      </article>
      <article class="java-summary-card card">
        <span>Clientes externos</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaExternalCompanyJavas">0</strong></span>
          <span><small>Bandejas</small><strong id="trayExternalCompanyQuantity">0</strong></span>
        </div>
        <small><strong id="javaExternalClientsCount" class="java-inline-number">0</strong> con activos pendientes</small>
      </article>
      <article class="java-summary-card card">
        <span>Clientes internos</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaInternalCompanyJavas">0</strong></span>
          <span><small>Bandejas</small><strong id="trayInternalCompanyQuantity">0</strong></span>
        </div>
        <small><strong id="javaInternalClientsCount" class="java-inline-number">0</strong> dentro de la avícola</small>
      </article>
    </section>

    <section class="java-inventory-settings card" aria-labelledby="javaInventorySettingsTitle">
      <div>
        <p class="eyebrow">Inventario general</p>
        <h2 id="javaInventorySettingsTitle">Totales propiedad de la empresa</h2>
        <p id="javaInventoryStatus">Cargando los totales configurados.</p>
      </div>
      <button id="javaInventoryOpen" class="btn java-inventory-open" type="button">Actualizar total general</button>
    </section>

    <section class="java-holder-section card" aria-labelledby="javaHolderTitle">
      <div class="java-section-head java-holder-heading">
        <div>
          <p class="eyebrow">Activos asignados</p>
          <h2 id="javaHolderTitle">Clientes que tienen javas o bandejas</h2>
        </div>
        <p>El resumen incluye cualquier cliente con saldo, incluso si actualmente está inactivo.</p>
      </div>
      <div class="java-holder-grid">
        <article class="java-holder-panel is-external" aria-labelledby="javaExternalHolderTitle">
          <header>
            <div>
              <span class="java-holder-kind">Clientes externos</span>
              <strong id="javaExternalHolderTitle">Pendientes fuera de la avícola</strong>
            </div>
            <span id="javaExternalHolderCount" class="java-holder-count">0 clientes</span>
          </header>
          <div class="java-holder-totals">
            <span><small>Javas</small><strong id="javaExternalHolderJavas">0</strong></span>
            <span><small>Bandejas</small><strong id="trayExternalHolderQuantity">0</strong></span>
          </div>
          <div id="javaExternalHolderList" class="java-holder-list" aria-live="polite"></div>
        </article>
        <article class="java-holder-panel is-internal" aria-labelledby="javaInternalHolderTitle">
          <header>
            <div>
              <span class="java-holder-kind">Clientes internos</span>
              <strong id="javaInternalHolderTitle">Asignados dentro de la avícola</strong>
            </div>
            <span id="javaInternalHolderCount" class="java-holder-count">0 clientes</span>
          </header>
          <div class="java-holder-totals">
            <span><small>Javas</small><strong id="javaInternalHolderJavas">0</strong></span>
            <span><small>Bandejas</small><strong id="trayInternalHolderQuantity">0</strong></span>
          </div>
          <div id="javaInternalHolderList" class="java-holder-list" aria-live="polite"></div>
        </article>
      </div>
    </section>

    <section class="java-daily-control card" aria-labelledby="javaDailyControlTitle">
      <div class="java-daily-control-head">
        <div>
          <p class="eyebrow">Conteo de la jornada</p>
          <h2 id="javaDailyControlTitle">Local y camiones de la empresa</h2>
          <p id="javaDailyStatus">Cargando el registro diario.</p>
        </div>
        <span class="java-entry-badge">Un registro por jornada</span>
      </div>

      <form id="javaDailyForm" class="java-daily-form" novalidate>
        <div class="java-daily-entry-grid">
          <fieldset class="java-daily-local-panel">
            <legend>Conteo en el local</legend>
            <p>Registra solo lo que está en el local y fuera de los camiones. No incluyas aquí los activos asignados a clientes internos.</p>
            <div class="java-form-row">
              <label class="field">Javas en el local <span class="java-required">*</span>
                <input id="javaDailyLocalQuantity" type="number" min="0" step="1" inputmode="numeric" value="0" required>
              </label>
              <label class="field">Bandejas en el local <span class="java-required">*</span>
                <input id="trayDailyLocalQuantity" type="number" min="0" step="1" inputmode="numeric" value="0" required>
              </label>
            </div>
          </fieldset>

          <fieldset class="java-daily-truck-panel">
            <legend>Conteo en camiones</legend>
            <p>Aparece toda la flota activa. Registra cero cuando el camión no tenga javas o bandejas.</p>
            <div class="java-table-wrap java-daily-truck-viewport">
              <table class="java-table java-daily-truck-table">
                <thead>
                  <tr><th>Camión</th><th>Javas que quedan</th><th>Bandejas que quedan</th><th>Registro</th></tr>
                </thead>
                <tbody id="javaDailyTruckInputs"></tbody>
              </table>
            </div>
          </fieldset>
        </div>

        <section class="java-reconciliation" aria-labelledby="javaReconciliationTitle">
          <div class="java-reconciliation-head">
            <div>
              <p class="eyebrow">Cuadre en tiempo real</p>
              <h3 id="javaReconciliationTitle">Explicación completa del inventario</h3>
            </div>
            <p id="javaDailyDifferenceLabel">Completa el conteo para revisar la diferencia.</p>
          </div>
          <div class="java-reconciliation-rows">
            <div class="java-reconciliation-row">
              <span><strong>Local</strong><small>Fuera de camiones</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyLocalTotal">0</strong></span><span><small>Bandejas</small><strong id="trayDailyLocalTotal">0</strong></span></span>
            </div>
            <div class="java-reconciliation-row">
              <span><strong>Camiones</strong><small>Suma de toda la flota</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyTruckTotal">0</strong></span><span><small>Bandejas</small><strong id="trayDailyTruckTotal">0</strong></span></span>
            </div>
            <div class="java-reconciliation-row is-primary">
              <span><strong>Conteo directo</strong><small>Local + camiones</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyQuantity">0</strong></span><span><small>Bandejas</small><strong id="trayDailyQuantity">0</strong></span></span>
            </div>
            <div class="java-reconciliation-row">
              <span><strong>Esperado directo</strong><small>Total menos todos los clientes</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyExpected">—</strong></span><span><small>Bandejas</small><strong id="trayDailyExpected">—</strong></span></span>
            </div>
            <div class="java-reconciliation-row is-difference">
              <span><strong>Diferencia</strong><small>Conteo directo − esperado</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyDifference">—</strong></span><span><small>Bandejas</small><strong id="trayDailyDifference">—</strong></span></span>
            </div>
            <div class="java-reconciliation-row">
              <span><strong>Clientes internos</strong><small>Asignados dentro de la avícola</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyInternalTotal">0</strong></span><span><small>Bandejas</small><strong id="trayDailyInternalTotal">0</strong></span></span>
            </div>
            <div class="java-reconciliation-row">
              <span><strong>Total dentro de la avícola</strong><small>Conteo directo + clientes internos</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyInsideTotal">0</strong></span><span><small>Bandejas</small><strong id="trayDailyInsideTotal">0</strong></span></span>
            </div>
            <div class="java-reconciliation-row">
              <span><strong>Clientes externos</strong><small>Activos pendientes fuera</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyExternalTotal">0</strong></span><span><small>Bandejas</small><strong id="trayDailyExternalTotal">0</strong></span></span>
            </div>
            <div class="java-reconciliation-row is-accounted">
              <span><strong>Total explicado</strong><small>Avícola + clientes externos</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyAccountedTotal">0</strong></span><span><small>Bandejas</small><strong id="trayDailyAccountedTotal">0</strong></span></span>
            </div>
            <div class="java-reconciliation-row">
              <span><strong>Total propiedad</strong><small>Inventario general de referencia</small></span>
              <span class="java-reconciliation-assets"><span><small>Javas</small><strong id="javaDailyPropertyTotal">—</strong></span><span><small>Bandejas</small><strong id="trayDailyPropertyTotal">—</strong></span></span>
            </div>
          </div>
        </section>

        <div class="java-daily-actions">
          <p>El servidor recalculará el total y validará que estén incluidos todos los camiones activos.</p>
          <button id="javaDailySubmit" class="btn btn-success" type="submit">Guardar conteo de la jornada</button>
        </div>
        <p id="javaDailyMessage" class="java-message" role="status" aria-live="polite"></p>
      </form>
    </section>
  </main>

  <div id="javaInventoryModal" class="java-inventory-modal" hidden>
    <section class="java-inventory-modal-card card" role="dialog" aria-modal="true" aria-labelledby="javaInventoryModalTitle">
      <div class="java-inventory-modal-head">
        <div><p class="eyebrow">Inventario general</p><h2 id="javaInventoryModalTitle">Actualizar totales de activos</h2></div>
        <button id="javaInventoryClose" class="java-inventory-close" type="button" aria-label="Cerrar">×</button>
      </div>
      <form id="javaInventoryForm" class="java-receipt-form" novalidate>
        <div class="java-inventory-outside-hints">
          <p class="java-balance-hint">Asignadas: <strong id="javaInventoryOutsideHint">0</strong> javas.</p>
          <p class="java-balance-hint">Asignadas: <strong id="trayInventoryOutsideHint">0</strong> bandejas.</p>
        </div>
        <div class="java-form-row">
          <label class="field">Total de javas <span class="java-required">*</span>
            <input id="javaInventoryQuantity" type="number" min="0" step="1" inputmode="numeric" placeholder="0" required>
          </label>
          <label class="field">Total de bandejas <span class="java-required">*</span>
            <input id="trayInventoryQuantity" type="number" min="0" step="1" inputmode="numeric" placeholder="0" required>
          </label>
        </div>
        <div class="java-inventory-actions">
          <button id="javaInventoryCancel" class="java-receive-btn" type="button">Cancelar</button>
          <button id="javaInventorySubmit" class="btn btn-success" type="submit">Guardar total</button>
        </div>
        <p id="javaInventoryMessage" class="java-message" role="status" aria-live="polite"></p>
      </form>
    </section>
  </div>

  <script type="module" src="{{ asset('js/control-javas.js') }}"></script>
</body>
</html>
