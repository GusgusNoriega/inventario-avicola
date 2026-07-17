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
  </main>

  <script type="module" src="{{ asset('js/tickets-dia.js') }}"></script>
</body>
</html>
