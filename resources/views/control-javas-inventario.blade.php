<!doctype html>
<html lang="es" class="java-control-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventario de Javas y Bandejas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="java-control-page" data-java-page="inventory">
  <main class="java-control-shell java-subview-shell">
    @include('partials.java-control-header', [
      'eyebrow' => 'Control de activos',
      'title' => 'Inventario y conteo físico',
      'description' => 'Compara las existencias esperadas de javas y bandejas con el conteo real de la jornada.',
    ])

    <section class="java-summary java-company-summary" aria-label="Inventario general de javas y bandejas">
      <article class="java-summary-card card">
        <span>Total propiedad de la empresa</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaCompanyTotal">—</strong></span>
          <span><small>Bandejas</small><strong id="trayCompanyTotal">—</strong></span>
        </div>
        <small>Activos dentro y fuera</small>
      </article>
      <article class="java-summary-card card">
        <span>Disponibles dentro</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaCompanyInside">—</strong></span>
          <span><small>Bandejas</small><strong id="trayCompanyInside">—</strong></span>
        </div>
        <small>Totales menos los activos fuera</small>
      </article>
      <article class="java-summary-card card">
        <span>Fuera con clientes</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaCompanyOutside">0</strong></span>
          <span><small>Bandejas</small><strong id="trayCompanyOutside">0</strong></span>
        </div>
        <small>Pendientes de devolución</small>
      </article>
    </section>

    <section class="java-inventory-workspace">
      <article class="java-inventory-task card">
        <div>
          <p class="eyebrow">Inventario general</p>
          <h2>Definir totales de javas y bandejas</h2>
          <p id="javaInventoryStatus">Cargando los totales configurados para la empresa.</p>
        </div>
        <button id="javaInventoryOpen" class="btn java-inventory-open" type="button">Actualizar total general</button>
      </article>

      <article class="java-inventory-task java-daily-task card">
        <div>
          <p class="eyebrow">Conteo de la jornada</p>
          <h2>Verificar existencias físicas</h2>
          <p id="javaDailyStatus">Cargando el conteo físico de la jornada actual.</p>
        </div>
        <div class="java-daily-assets" aria-label="Resultado del conteo diario">
          <div class="java-daily-asset">
            <span>Javas</span>
            <div class="java-daily-result">
              <span><small>Contadas</small><strong id="javaDailyQuantity">—</strong></span>
              <span><small>Diferencia</small><strong id="javaDailyDifference">—</strong></span>
            </div>
            <p id="javaDailyExpected" class="java-daily-expected">Esperadas: —</p>
            <p id="javaDailyDifferenceLabel" class="java-daily-expected">Sin conteo para esta jornada</p>
          </div>
          <div class="java-daily-asset is-tray">
            <span>Bandejas</span>
            <div class="java-daily-result">
              <span><small>Contadas</small><strong id="trayDailyQuantity">—</strong></span>
              <span><small>Diferencia</small><strong id="trayDailyDifference">—</strong></span>
            </div>
            <p id="trayDailyExpected" class="java-daily-expected">Esperadas: —</p>
            <p id="trayDailyDifferenceLabel" class="java-daily-expected">Sin conteo para esta jornada</p>
          </div>
        </div>
        <button id="javaDailyOpen" class="btn java-inventory-open" type="button">Registrar conteo físico</button>
      </article>
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
          <p class="java-balance-hint">Hay <strong id="javaInventoryOutsideHint">0</strong> javas fuera.</p>
          <p class="java-balance-hint">Hay <strong id="trayInventoryOutsideHint">0</strong> bandejas fuera.</p>
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

  <div id="javaDailyModal" class="java-inventory-modal" hidden>
    <section class="java-inventory-modal-card card" role="dialog" aria-modal="true" aria-labelledby="javaDailyModalTitle">
      <div class="java-inventory-modal-head">
        <div><p class="eyebrow">Conteo de jornada</p><h2 id="javaDailyModalTitle">Registrar conteo físico</h2></div>
        <button id="javaDailyClose" class="java-inventory-close" type="button" aria-label="Cerrar conteo diario">×</button>
      </div>
      <form id="javaDailyForm" class="java-receipt-form" novalidate>
        <div class="java-inventory-outside-hints">
          <p class="java-balance-hint">Esperadas: <strong id="javaDailyExpectedHint">0</strong> javas.</p>
          <p class="java-balance-hint">Esperadas: <strong id="trayDailyExpectedHint">0</strong> bandejas.</p>
        </div>
        <div class="java-form-row">
          <label class="field">Javas contadas <span class="java-required">*</span>
            <input id="javaDailyCountQuantity" type="number" min="0" step="1" inputmode="numeric" placeholder="0" required>
          </label>
          <label class="field">Bandejas contadas <span class="java-required">*</span>
            <input id="trayDailyCountQuantity" type="number" min="0" step="1" inputmode="numeric" placeholder="0" required>
          </label>
        </div>
        <div class="java-inventory-actions">
          <button id="javaDailyCancel" class="java-receive-btn" type="button">Cancelar</button>
          <button id="javaDailySubmit" class="btn btn-success" type="submit">Guardar conteo</button>
        </div>
        <p id="javaDailyMessage" class="java-message" role="status" aria-live="polite"></p>
      </form>
    </section>
  </div>

  <script type="module" src="{{ asset('js/control-javas.js') }}"></script>
</body>
</html>
