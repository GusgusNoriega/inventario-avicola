<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Consultar tickets | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body
  class="fin-page fin-ticket-page"
  data-can-manage-tickets="{{ auth()->user()->hasPermission('SALDOS_AJUSTAR') ? '1' : '0' }}"
  data-can-manage-ticket-status="{{ auth()->user()->isAdministrator() ? '1' : '0' }}"
>
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'tickets',
      'eyebrow' => 'Control de ventas',
      'title' => 'Consulta y edición de tickets',
      'description' => 'Busca tickets históricos, incluidos los anulados, y administra su estado, cliente o precios.'
    ])

    <section class="fin-card fin-management-notice" aria-label="Condición de consulta">
      <div class="fin-management-notice-mark" aria-hidden="true">i</div>
      <div>
        <strong>Debes aplicar al menos un filtro para consultar tickets.</strong>
        <p>Puedes buscar por número, cliente o rango de fecha. También puedes seleccionar “Solo anulados” para consultarlos directamente.</p>
      </div>
    </section>

    <section class="fin-card fin-ticket-panel" aria-labelledby="financeTicketsTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Historial de tickets</p>
          <h2 id="financeTicketsTitle">¿Qué tickets deseas revisar?</h2>
        </div>
        <button
          id="financeTicketBulkOpen"
          class="fin-btn fin-btn-accent fin-btn-small"
          type="button"
          aria-haspopup="dialog"
          aria-controls="financeTicketBulkDialog"
          disabled
        >Ajustar precios filtrados</button>
      </div>

      <form id="financeTicketFilters" class="fin-filter-grid fin-ticket-filters" novalidate>
        <label class="fin-field">
          <span>Número de ticket</span>
          <input id="financeTicketNumber" type="search" maxlength="40" placeholder="Ej. T-20260725-001">
        </label>
        <div class="fin-field fin-filter-search">
          <label for="financeTicketClient">Cliente</label>
          <div id="financeTicketClientCombobox" class="fin-ticket-filter-combobox">
            <input
              id="financeTicketClient"
              type="search"
              maxlength="120"
              autocomplete="off"
              placeholder="Nombre o número de documento"
              role="combobox"
              aria-autocomplete="list"
              aria-expanded="false"
              aria-controls="financeTicketClientSuggestions"
            >
            <div
              id="financeTicketClientSuggestions"
              class="fin-ticket-client-suggestions"
              role="listbox"
              aria-label="Clientes encontrados"
              hidden
            ></div>
          </div>
        </div>
        <label class="fin-field">
          <span>Estado</span>
          <select id="financeTicketStatus">
            <option value="VIGENTES">Solo vigentes</option>
            <option value="ANULADOS">Solo anulados</option>
            <option value="TODOS">Todos</option>
          </select>
        </label>
        <label class="fin-field">
          <span>Desde</span>
          <input id="financeTicketFrom" type="datetime-local">
        </label>
        <label class="fin-field">
          <span>Hasta</span>
          <input id="financeTicketUntil" type="datetime-local">
        </label>
        <div class="fin-filter-actions">
          <button id="financeTicketSearch" class="fin-btn fin-btn-primary" type="submit">Consultar tickets</button>
          <button id="financeTicketClear" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
        </div>
      </form>

      <p id="financeTicketMessage" class="fin-message" role="status" aria-live="polite">
        Aplica un filtro para mostrar los tickets.
      </p>

      <div class="fin-table-wrap fin-ticket-table-wrap">
        <table class="fin-table fin-ticket-table">
          <thead>
            <tr>
              <th>Ticket</th>
              <th>Cliente</th>
              <th>Precio asignado</th>
              <th class="fin-text-right">Monto</th>
              <th>Fecha y hora</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="financeTicketRows">
            <tr>
              <td class="fin-empty-cell" colspan="7">Los tickets aparecerán aquí después de aplicar un filtro.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="fin-pagination">
        <button id="financeTicketPrevious" class="fin-btn fin-btn-ghost fin-btn-small" type="button" disabled>Anterior</button>
        <span id="financeTicketPage">Sin consulta</span>
        <button id="financeTicketNext" class="fin-btn fin-btn-ghost fin-btn-small" type="button" disabled>Siguiente</button>
      </div>
    </section>
  </main>

  <dialog id="financeTicketPriceDialog" class="fin-purchase-dialog" aria-labelledby="financeTicketPriceTitle">
    <form id="financeTicketPriceForm" class="fin-purchase-dialog-card">
      <header class="fin-purchase-dialog-head">
        <div>
          <p class="fin-eyebrow">Precio por tipo de pollo</p>
          <h2 id="financeTicketPriceTitle">Editar precios</h2>
        </div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="financeTicketPriceDescription" class="fin-section-copy"></p>
      <div id="financeTicketPriceFields" class="fin-ticket-price-fields"></div>
      <p id="financeTicketPriceMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button class="fin-btn fin-btn-primary" type="submit">Guardar precios</button>
      </footer>
    </form>
  </dialog>

  <dialog id="financeTicketClientDialog" class="fin-purchase-dialog" aria-labelledby="financeTicketClientTitle">
    <form id="financeTicketClientForm" class="fin-purchase-dialog-card fin-ticket-client-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div>
          <p class="fin-eyebrow">Reasignar ticket</p>
          <h2 id="financeTicketClientTitle">Cambiar cliente</h2>
        </div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="financeTicketClientDescription" class="fin-section-copy"></p>
      <label class="fin-field">
        <span>Buscar cliente</span>
        <input id="financeTicketClientSearch" type="search" maxlength="120" autocomplete="off" placeholder="Nombre o documento">
      </label>
      <div id="financeTicketClientOptions" class="fin-ticket-client-options" role="radiogroup" aria-label="Clientes disponibles"></div>
      <p id="financeTicketClientSelection" class="fin-ticket-selection" aria-live="polite">Selecciona un cliente.</p>
      <p id="financeTicketClientMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button id="financeTicketClientSave" class="fin-btn fin-btn-primary" type="submit" disabled>Guardar cliente</button>
      </footer>
    </form>
  </dialog>

  <dialog id="financeTicketVoidDialog" class="fin-purchase-dialog fin-void-dialog" aria-labelledby="financeTicketVoidTitle">
    <form id="financeTicketVoidForm" class="fin-purchase-dialog-card" novalidate>
      <header class="fin-purchase-dialog-head">
        <div>
          <p class="fin-eyebrow">Acción exclusiva de administrador</p>
          <h2 id="financeTicketVoidTitle">Anular ticket</h2>
        </div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="financeTicketVoidDescription" class="fin-section-copy"></p>
      <p class="fin-ticket-lifecycle-warning">
        Se anularán sus pesadas, se retirará su efecto en javas y se neutralizará la cuenta por cobrar. Los cobros exclusivos se reversarán automáticamente.
      </p>
      <label class="fin-field">
        <span>Motivo de anulación <b>*</b></span>
        <textarea id="financeTicketVoidReason" rows="4" minlength="3" maxlength="250" required placeholder="Explica por qué se anula este ticket"></textarea>
      </label>
      <p id="financeTicketVoidMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button id="financeTicketVoidSubmit" class="fin-btn fin-btn-danger" type="submit">Sí, anular ticket</button>
      </footer>
    </form>
  </dialog>

  <dialog id="financeTicketRestoreDialog" class="fin-purchase-dialog fin-void-dialog" aria-labelledby="financeTicketRestoreTitle">
    <form id="financeTicketRestoreForm" class="fin-purchase-dialog-card">
      <header class="fin-purchase-dialog-head">
        <div>
          <p class="fin-eyebrow">Acción exclusiva de administrador</p>
          <h2 id="financeTicketRestoreTitle">Restablecer ticket</h2>
        </div>
        <button class="fin-dialog-close" type="button" data-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="financeTicketRestoreDescription" class="fin-section-copy"></p>
      <p class="fin-ticket-lifecycle-warning is-restore">
        Las pesadas anuladas junto con el ticket, la cuenta por cobrar y el movimiento de javas volverán a estar activos. Los cobros que se revirtieron al anular el ticket no se recuperan automáticamente.
      </p>
      <p id="financeTicketRestoreMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close>Cancelar</button>
        <button id="financeTicketRestoreSubmit" class="fin-btn fin-btn-primary" type="submit">Sí, restablecer ticket</button>
      </footer>
    </form>
  </dialog>

  <dialog id="financeTicketBulkDialog" class="fin-purchase-dialog" aria-labelledby="financeTicketBulkTitle">
    <form id="financeTicketBulkForm" class="fin-purchase-dialog-card fin-ticket-bulk-card">
      <header class="fin-purchase-dialog-head">
        <div>
          <p class="fin-eyebrow">Todas las páginas filtradas</p>
          <h2 id="financeTicketBulkTitle">Ajustar precios por tipo de pollo</h2>
        </div>
        <button class="fin-dialog-close" type="button" data-dialog-close data-bulk-dialog-close aria-label="Cerrar">×</button>
      </header>
      <p id="financeTicketBulkScope" class="fin-section-copy"></p>

      <fieldset class="fin-ticket-type-fieldset">
        <legend>1. Selecciona el precio que deseas modificar</legend>
        <div id="financeTicketBulkTypes" class="fin-ticket-type-options" role="radiogroup" aria-label="Tipos de pollo disponibles"></div>
      </fieldset>

      <label class="fin-field">
        <span>2. Monto que se sumará o restará</span>
        <input id="financeTicketBulkAmount" type="number" min="0.0001" max="99999999.9999" step="0.0001" inputmode="decimal" placeholder="Ej. 1.00 o 0.10" required>
      </label>

      <p class="fin-ticket-bulk-warning">
        El ajuste afectará ese tipo de precio en todos los tickets del filtro, no solamente los 30 visibles.
      </p>
      <p id="financeTicketBulkMessage" class="fin-message" role="status" aria-live="polite"></p>
      <footer class="fin-purchase-dialog-actions fin-ticket-bulk-actions">
        <button class="fin-btn fin-btn-ghost" type="button" data-dialog-close data-bulk-dialog-close>Cancelar</button>
        <button class="fin-btn fin-btn-danger" type="button" data-bulk-operation="DISMINUIR">− Disminuir</button>
        <button class="fin-btn fin-btn-primary" type="button" data-bulk-operation="AUMENTAR">+ Aumentar</button>
      </footer>
    </form>
  </dialog>

  <script type="module" src="{{ asset('js/finanzas-tickets.js') }}"></script>
</body>
</html>
