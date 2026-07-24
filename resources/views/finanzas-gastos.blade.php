<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Gastos de empresa | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body
  class="fin-page fin-expenses-page"
  data-can-create-expenses="{{ auth()->user()->hasPermission('PAGOS_REGISTRAR') ? '1' : '0' }}"
  data-can-void-expenses="{{ auth()->user()->hasPermission('PAGOS_ANULAR') ? '1' : '0' }}"
>
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'gastos',
      'eyebrow' => 'Salidas internas',
      'title' => 'Gastos de empresa',
      'description' => 'Registra en qué usa el dinero la empresa, desde qué caja sale y hacia dónde se destina.'
    ])

    <section class="fin-expense-layout">
      <form id="companyExpenseForm" class="fin-card fin-expense-form" novalidate>
        <div class="fin-section-head fin-section-head-wrap">
          <div>
            <p class="fin-eyebrow">Nuevo egreso</p>
            <h2>Registrar gasto</h2>
            <p class="fin-section-copy">Úsalo para llantas, camisas, servicios, suministros u otro gasto interno pagado por la empresa.</p>
          </div>
          <span class="fin-badge">Salida de caja</span>
        </div>

        <div class="fin-form-grid fin-expense-form-grid">
          <label class="fin-field">
            <span>Fecha y hora <b>*</b></span>
            <input id="companyExpenseDate" type="datetime-local" required>
          </label>
          <label class="fin-field">
            <span>Categoría <b>*</b></span>
            <select id="companyExpenseCategory" required></select>
          </label>
          <label class="fin-field fin-grid-span-2">
            <span>Concepto del gasto <b>*</b></span>
            <input id="companyExpenseConcept" type="text" maxlength="250" required placeholder="Ej: Compra de llanta para el camión">
          </label>
          <label class="fin-field fin-grid-span-2">
            <span>¿Hacia dónde fue el dinero? <b>*</b></span>
            <input id="companyExpenseDestination" type="text" maxlength="250" required placeholder="Ej: Taller San José / Juan Pérez / Tienda Textil">
          </label>
          <label class="fin-field">
            <span>Número de comprobante</span>
            <input id="companyExpenseDocument" type="text" maxlength="100" placeholder="Factura, boleta o recibo">
          </label>
          <label class="fin-field">
            <span>Caja o cuenta de origen <b>*</b></span>
            <select id="companyExpenseAccount" required><option value="">Cargando cuentas...</option></select>
            <small id="companyExpenseAccountHelp">Selecciona de dónde sale el dinero.</small>
          </label>
          <label class="fin-field">
            <span>Método de pago <b>*</b></span>
            <select id="companyExpenseMethod" required><option value="">Cargando métodos...</option></select>
          </label>
          <label class="fin-field">
            <span>Importe <b>*</b></span>
            <div class="fin-money-input"><span id="companyExpenseCurrencyPrefix">S/</span><input id="companyExpenseAmount" type="number" min="0.01" step="0.01" inputmode="decimal" required placeholder="0.00"></div>
          </label>
          <label class="fin-field">
            <span>Referencia de pago</span>
            <input id="companyExpenseReference" type="text" maxlength="100" placeholder="Operación, recibo o referencia">
          </label>
          <label class="fin-field fin-grid-span-2">
            <span>Observaciones</span>
            <textarea id="companyExpenseNotes" rows="2" maxlength="2000" placeholder="Detalle adicional opcional"></textarea>
          </label>
        </div>

        <div class="fin-expense-form-footer">
          <div class="fin-expense-total">
            <span>Se descontará</span>
            <strong id="companyExpenseTotal">S/ 0.00</strong>
          </div>
          <div>
            <p id="companyExpenseMessage" class="fin-message" role="status" aria-live="polite"></p>
            <button id="companyExpenseSave" class="fin-btn fin-btn-primary" type="submit">Registrar gasto</button>
          </div>
        </div>
      </form>

      <aside class="fin-expense-side">
        <article class="fin-card fin-expense-summary is-total">
          <span>Total vigente con los filtros</span>
          <strong id="companyExpenseSummaryTotal">S/ 0.00</strong>
          <small>Los anulados no forman parte del total.</small>
        </article>
        <article class="fin-card fin-expense-summary">
          <span>Gastos vigentes</span>
          <strong id="companyExpenseSummaryCount">0</strong>
          <small>Registros activos en la consulta.</small>
        </article>
        <article class="fin-card fin-expense-help">
          <span class="fin-expense-help-icon" aria-hidden="true">↺</span>
          <strong>Edición con trazabilidad</strong>
          <p>Si corriges caja o importe, se reversa el movimiento anterior y se crea el nuevo automáticamente.</p>
        </article>
      </aside>
    </section>

    <section class="fin-card fin-expense-history" aria-labelledby="companyExpenseHistoryTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Control y seguimiento</p>
          <h2 id="companyExpenseHistoryTitle">Historial de gastos</h2>
        </div>
      </div>

      <form id="companyExpenseFilters" class="fin-filter-grid fin-expense-filters" novalidate>
        <label class="fin-field fin-filter-search">
          <span>Buscar</span>
          <input id="companyExpenseSearch" type="search" placeholder="Concepto, destino, documento o código">
        </label>
        <label class="fin-field">
          <span>Categoría</span>
          <select id="companyExpenseFilterCategory"></select>
        </label>
        <label class="fin-field">
          <span>Estado</span>
          <select id="companyExpenseFilterStatus">
            <option value="">Todos</option>
            <option value="REGISTRADO">Vigentes</option>
            <option value="ANULADO">Anulados</option>
          </select>
        </label>
        <label class="fin-field">
          <span>Desde</span>
          <input id="companyExpenseFrom" type="date">
        </label>
        <label class="fin-field">
          <span>Hasta</span>
          <input id="companyExpenseTo" type="date">
        </label>
        <div class="fin-filter-actions">
          <button class="fin-btn fin-btn-primary" type="submit">Filtrar</button>
          <button id="companyExpenseClearFilters" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
        </div>
      </form>

      <p id="companyExpenseListMessage" class="fin-message" role="status" aria-live="polite">Cargando gastos...</p>
      <div class="fin-table-wrap">
        <table class="fin-table fin-expense-table">
          <thead>
            <tr>
              <th>Fecha / código</th>
              <th>Concepto</th>
              <th>Destino</th>
              <th>Salida de</th>
              <th>Estado</th>
              <th class="fin-text-right">Importe</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="companyExpenseRows"></tbody>
        </table>
      </div>
      <div class="fin-pagination">
        <button id="companyExpensePrevious" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Anterior</button>
        <span id="companyExpensePage">Página 1</span>
        <button id="companyExpenseNext" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Siguiente</button>
      </div>
    </section>
  </main>

  <dialog id="companyExpenseEditDialog" class="fin-purchase-dialog" aria-labelledby="companyExpenseEditTitle">
    <form id="companyExpenseEditForm" class="fin-purchase-dialog-card fin-expense-edit-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Corrección auditada</p><h2 id="companyExpenseEditTitle">Editar gasto</h2></div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <input id="companyExpenseEditId" type="hidden">
      <div class="fin-form-grid">
        <label class="fin-field"><span>Fecha y hora <b>*</b></span><input id="companyExpenseEditDate" type="datetime-local" required></label>
        <label class="fin-field"><span>Categoría <b>*</b></span><select id="companyExpenseEditCategory" required></select></label>
        <label class="fin-field fin-grid-span-2"><span>Concepto <b>*</b></span><input id="companyExpenseEditConcept" type="text" maxlength="250" required></label>
        <label class="fin-field fin-grid-span-2"><span>Destino del dinero <b>*</b></span><input id="companyExpenseEditDestination" type="text" maxlength="250" required></label>
        <label class="fin-field"><span>Número de comprobante</span><input id="companyExpenseEditDocument" type="text" maxlength="100"></label>
        <label class="fin-field"><span>Caja o cuenta <b>*</b></span><select id="companyExpenseEditAccount" required></select></label>
        <label class="fin-field"><span>Método <b>*</b></span><select id="companyExpenseEditMethod" required></select></label>
        <label class="fin-field"><span>Importe <b>*</b></span><input id="companyExpenseEditAmount" type="number" min="0.01" step="0.01" required></label>
        <label class="fin-field"><span>Referencia</span><input id="companyExpenseEditReference" type="text" maxlength="100"></label>
        <label class="fin-field fin-grid-span-2"><span>Observaciones</span><textarea id="companyExpenseEditNotes" rows="3" maxlength="2000"></textarea></label>
      </div>
      <p class="fin-expense-edit-note">Cambiar caja, método, moneda o importe dejará una reversa del movimiento anterior.</p>
      <p id="companyExpenseEditMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button class="fin-btn fin-btn-primary" type="submit">Guardar cambios</button>
      </footer>
    </form>
  </dialog>

  <dialog id="companyExpenseVoidDialog" class="fin-purchase-dialog fin-void-dialog" aria-labelledby="companyExpenseVoidTitle">
    <form id="companyExpenseVoidForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Reintegro con trazabilidad</p><h2 id="companyExpenseVoidTitle">Anular gasto</h2></div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="companyExpenseVoidDescription" class="fin-section-copy"></p>
      <label class="fin-field"><span>Motivo de anulación <b>*</b></span><textarea id="companyExpenseVoidReason" rows="4" minlength="5" maxlength="250" required placeholder="Explica por qué se anula este gasto"></textarea></label>
      <p id="companyExpenseVoidMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button class="fin-btn fin-btn-danger" type="submit">Sí, anular gasto</button>
      </footer>
    </form>
  </dialog>

  <script type="module" src="{{ asset('js/finanzas-gastos.js') }}?v={{ filemtime(public_path('js/finanzas-gastos.js')) }}"></script>
</body>
</html>
