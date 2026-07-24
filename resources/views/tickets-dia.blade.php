<!doctype html>
<html lang="es" class="customer-history-root daily-tickets-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Resumen de la jornada | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="customer-history-page daily-tickets-page">
  <main class="customer-history-view daily-tickets-view" data-daily-tickets>
    <section class="customer-history-section" aria-labelledby="dailyClientTotalsTitle">
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Movimientos netos</p>
          <h2 id="dailyClientTotalsTitle">Resumen por cliente</h2>
        </div>
        <a class="menu-return-btn" href="{{ route('menu') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 6h7v7H4z"></path>
            <path d="M13 6h7v7h-7z"></path>
            <path d="M4 15h7v3H4z"></path>
            <path d="M13 15h7v3h-7z"></path>
          </svg>
          <span>Menú</span>
        </a>
      </div>
      <div class="customer-history-table-wrap card">
        <table class="customer-history-table daily-client-table">
          <thead>
            <tr>
              <th>Cliente</th>
              <th>Ave</th>
              <th>Num. javas</th>
              <th>Cant. aves</th>
              <th>Peso bruto</th>
              <th>Tara</th>
              <th>Devoluciones</th>
              <th>Peso neto</th>
            </tr>
          </thead>
          <tbody id="dailyClientTotals"></tbody>
        </table>
      </div>
    </section>

    <section id="dailyTicketAdmin" class="customer-history-section daily-ticket-admin" aria-labelledby="dailyTicketAdminTitle" hidden>
      <div class="customer-history-section-head">
        <div>
          <p class="eyebrow">Acceso exclusivo de administrador</p>
          <h2 id="dailyTicketAdminTitle">Administrar tickets</h2>
          <p class="daily-ticket-admin-copy">Los tickets anulados se conservan con sus pesadas y auditoría, pero quedan fuera de los totales, finanzas y control de javas.</p>
        </div>
      </div>

      <form id="dailyTicketFilters" class="daily-ticket-filters card">
        <label>
          Fecha operativa
          <input id="dailyTicketDate" name="date" type="date">
        </label>
        <label>
          Código de ticket
          <input id="dailyTicketSearch" name="ticket" type="search" maxlength="40" placeholder="Ej: T-20260724-001">
        </label>
        <label>
          Estado
          <select id="dailyTicketStatus" name="status">
            <option value="">Todos</option>
            <option value="CERRADO">Vigentes</option>
            <option value="ANULADO">Anulados</option>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Consultar</button>
      </form>

      <p id="dailyTicketFeedback" class="daily-ticket-feedback" role="status" aria-live="polite"></p>

      <div class="customer-history-table-wrap card">
        <table class="customer-history-table daily-ticket-table">
          <thead>
            <tr>
              <th>Ticket</th>
              <th>Fecha</th>
              <th>Canal</th>
              <th>Operación</th>
              <th>Destino</th>
              <th>Peso registrado</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody id="dailyTicketRows"></tbody>
        </table>
      </div>
    </section>
  </main>

  <div id="dailyTicketVoidModal" class="daily-ticket-void-modal" hidden>
    <section class="daily-ticket-void-card card" role="dialog" aria-modal="true" aria-labelledby="dailyTicketVoidTitle">
      <div class="daily-ticket-void-head">
        <div>
          <p class="eyebrow">Acción administrativa</p>
          <h2 id="dailyTicketVoidTitle">Anular ticket</h2>
        </div>
        <button id="dailyTicketVoidClose" class="daily-ticket-void-close" type="button" aria-label="Cerrar">×</button>
      </div>
      <p>Vas a anular el ticket <strong id="dailyTicketVoidCode">--</strong>. El registro no se eliminará y seguirá disponible en esta consulta.</p>
      <div class="daily-ticket-void-warning">
        La operación también anulará sus pesadas, neutralizará su cuenta por cobrar y retirará su efecto en javas. Los cobros exclusivos del ticket se reversarán automáticamente.
      </div>
      <form id="dailyTicketVoidForm">
        <label>
          Motivo de anulación
          <textarea id="dailyTicketVoidReason" name="motivo" rows="4" minlength="3" maxlength="250" required placeholder="Explica claramente por qué se anula este ticket"></textarea>
        </label>
        <p id="dailyTicketVoidError" class="daily-ticket-void-error" role="alert"></p>
        <div class="daily-ticket-void-actions">
          <button id="dailyTicketVoidCancel" class="btn btn-ghost" type="button">Cancelar</button>
          <button id="dailyTicketVoidSubmit" class="btn btn-danger" type="submit">Confirmar anulación</button>
        </div>
      </form>
    </section>
  </div>

  <script type="module" src="{{ asset('js/tickets-dia.js') }}"></script>
</body>
</html>
