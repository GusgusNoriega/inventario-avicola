<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Descuentos a clientes | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body
  class="fin-page fin-expenses-page"
  data-can-adjust-discounts="{{ auth()->user()->hasPermission('SALDOS_AJUSTAR') ? '1' : '0' }}"
>
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'descuentos',
      'eyebrow' => 'Cartera de clientes',
      'title' => 'Descuentos a clientes',
      'description' => 'Aplica un descuento a la deuda del cliente. Si la deuda ya queda cubierta, el excedente se conserva como saldo a favor.'
    ])

    <section class="fin-expense-layout">
      <form id="customerDiscountForm" class="fin-card fin-expense-form" novalidate>
        <div class="fin-section-head fin-section-head-wrap">
          <div>
            <p class="fin-eyebrow">Nuevo descuento</p>
            <h2>Registrar descuento</h2>
            <p class="fin-section-copy">Selecciona el cliente, indica la fecha de la transacción, el monto y el motivo.</p>
          </div>
          <span class="fin-badge">Sin movimiento de caja</span>
        </div>

        <div class="fin-form-grid fin-expense-form-grid">
          <label class="fin-field fin-grid-span-2">
            <span>Buscar cliente</span>
            <input id="customerDiscountClientSearch" type="search" maxlength="100" placeholder="Nombre o número de documento">
          </label>
          <label class="fin-field fin-grid-span-2">
            <span>Cliente <b>*</b></span>
            <select id="customerDiscountClient" required>
              <option value="">Cargando clientes...</option>
            </select>
          </label>
          <label class="fin-field">
            <span>Fecha de la transacción <b>*</b></span>
            <input id="customerDiscountDate" type="date" value="{{ now()->toDateString() }}" min="1970-01-01" max="{{ now()->toDateString() }}" required>
            <small>Puede ser anterior al día en que registras el descuento.</small>
          </label>
          <label class="fin-field">
            <span>Monto del descuento <b>*</b></span>
            <div class="fin-money-input">
              <span id="customerDiscountCurrencyPrefix">S/</span>
              <input id="customerDiscountAmount" type="number" min="0.01" step="0.01" inputmode="decimal" required placeholder="0.00">
            </div>
          </label>
          <label class="fin-field fin-grid-span-2">
            <span>Motivo del descuento <b>*</b></span>
            <textarea id="customerDiscountReason" rows="3" minlength="3" maxlength="250" required placeholder="Ej: Acuerdo comercial por diferencia de peso"></textarea>
          </label>
        </div>

        <div class="fin-expense-form-footer">
          <div class="fin-expense-total">
            <span>Se descontará</span>
            <strong id="customerDiscountTotal">S/ 0.00</strong>
          </div>
          <div>
            <p id="customerDiscountMessage" class="fin-message" role="status" aria-live="polite"></p>
            <button id="customerDiscountSave" class="fin-btn fin-btn-primary" type="submit">Registrar descuento</button>
          </div>
        </div>
      </form>

      <aside class="fin-expense-side">
        <article class="fin-card fin-expense-summary is-total">
          <span>Deuda actual del cliente</span>
          <strong id="customerDiscountCurrentDebt">S/ 0.00</strong>
          <small id="customerDiscountCurrentHelp">Selecciona un cliente para consultar su saldo.</small>
        </article>
        <article class="fin-card fin-expense-summary">
          <span>Saldo a favor después</span>
          <strong id="customerDiscountProjectedCredit">S/ 0.00</strong>
          <small>Se calcula con el monto que estás registrando.</small>
        </article>
        <article class="fin-card fin-expense-help">
          <span class="fin-expense-help-icon" aria-hidden="true">✓</span>
          <strong>Aplicación automática</strong>
          <p>El sistema descuenta primero las deudas pendientes más antiguas del cliente.</p>
        </article>
      </aside>
    </section>

    <section class="fin-card fin-expense-history" aria-labelledby="customerDiscountHistoryTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Consulta y control</p>
          <h2 id="customerDiscountHistoryTitle">Registros de descuentos</h2>
        </div>
      </div>

      <form id="customerDiscountFilters" class="fin-filter-grid" novalidate>
        <label class="fin-field fin-filter-search">
          <span>Buscar registros</span>
          <input id="customerDiscountRecordSearch" type="search" maxlength="100" placeholder="Cliente, documento, motivo o código">
        </label>
        <label class="fin-field">
          <span>Estado</span>
          <select id="customerDiscountStatus">
            <option value="">Todos</option>
            <option value="REGISTRADO">Vigentes</option>
            <option value="ANULADO">Anulados</option>
          </select>
        </label>
        <div class="fin-filter-actions">
          <button class="fin-btn fin-btn-primary" type="submit">Buscar</button>
          <button id="customerDiscountClearFilters" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
        </div>
      </form>

      <p id="customerDiscountListMessage" class="fin-message" role="status" aria-live="polite">Cargando descuentos...</p>
      <div class="fin-table-wrap">
        <table class="fin-table fin-management-table">
          <thead>
            <tr>
              <th>Fecha de transacción / código</th>
              <th>Cliente</th>
              <th>Motivo</th>
              <th>Estado</th>
              <th class="fin-text-right">Descuento</th>
              <th class="fin-text-right">Aplicado</th>
              <th class="fin-text-right">Saldo a favor</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="customerDiscountRows"></tbody>
        </table>
      </div>
      <div class="fin-pagination">
        <button id="customerDiscountPrevious" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Anterior</button>
        <span id="customerDiscountPage">Página 1</span>
        <button id="customerDiscountNext" class="fin-btn fin-btn-ghost fin-btn-small" type="button">Siguiente</button>
      </div>
    </section>
  </main>

  <dialog id="customerDiscountEditDialog" class="fin-purchase-dialog" aria-labelledby="customerDiscountEditTitle">
    <form id="customerDiscountEditForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Corrección con trazabilidad</p><h2 id="customerDiscountEditTitle">Editar descuento</h2></div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <input id="customerDiscountEditId" type="hidden">
      <div class="fin-form-grid">
        <label class="fin-field fin-grid-span-2">
          <span>Cliente <b>*</b></span>
          <select id="customerDiscountEditClient" required></select>
        </label>
        <label class="fin-field">
          <span>Fecha de la transacción <b>*</b></span>
          <input id="customerDiscountEditDate" type="date" min="1970-01-01" max="{{ now()->toDateString() }}" required>
        </label>
        <label class="fin-field">
          <span>Monto <b>*</b></span>
          <input id="customerDiscountEditAmount" type="number" min="0.01" step="0.01" required>
        </label>
        <label class="fin-field fin-grid-span-2">
          <span>Motivo <b>*</b></span>
          <textarea id="customerDiscountEditReason" rows="3" minlength="3" maxlength="250" required></textarea>
        </label>
      </div>
      <p class="fin-expense-edit-note">La corrección anulará el registro anterior y volverá a aplicar el descuento automáticamente.</p>
      <p id="customerDiscountEditMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button class="fin-btn fin-btn-primary" type="submit">Guardar cambios</button>
      </footer>
    </form>
  </dialog>

  <dialog id="customerDiscountVoidDialog" class="fin-purchase-dialog fin-void-dialog" aria-labelledby="customerDiscountVoidTitle">
    <form id="customerDiscountVoidForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div><p class="fin-eyebrow">Restauración de saldo</p><h2 id="customerDiscountVoidTitle">Anular descuento</h2></div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="customerDiscountVoidDescription" class="fin-section-copy"></p>
      <label class="fin-field">
        <span>Motivo de anulación <b>*</b></span>
        <textarea id="customerDiscountVoidReason" rows="4" minlength="5" maxlength="250" required placeholder="Explica por qué se anula este descuento"></textarea>
      </label>
      <p id="customerDiscountVoidMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button class="fin-btn fin-btn-danger" type="submit">Sí, anular descuento</button>
      </footer>
    </form>
  </dialog>

  <script type="module" src="{{ asset('js/finanzas-descuentos-clientes.js') }}?v={{ filemtime(public_path('js/finanzas-descuentos-clientes.js')) }}"></script>
</body>
</html>
