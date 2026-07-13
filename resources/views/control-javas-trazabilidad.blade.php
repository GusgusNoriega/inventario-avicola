<!doctype html>
<html lang="es" class="java-control-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trazabilidad de Javas y Bandejas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="java-control-page" data-java-page="traceability">
  <main class="java-control-shell java-subview-shell">
    @include('partials.java-control-header', [
      'eyebrow' => 'Control de activos',
      'title' => 'Trazabilidad por jornada',
      'description' => 'Analiza una jornada a la vez y alterna entre el consolidado y el detalle.',
    ])

    <section class="java-journey-toolbar card" aria-label="Filtro de trazabilidad por jornada">
      <div><p class="eyebrow">Jornada consultada</p><strong id="javaJourneyTitle">Selecciona una jornada</strong><small id="javaJourneyWindow">Cargando período operativo.</small></div>
      <label class="java-history-filter java-journey-filter">Ver jornada
        <select id="javaJourneyFilter"><option value="">Cargando jornadas...</option></select>
      </label>
    </section>

    <section class="java-summary java-summary-four java-trace-summary" aria-label="Resumen de la jornada seleccionada">
      <article class="java-summary-card card">
        <span>Activos que salieron</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaJourneyDispatched">0</strong></span>
          <span><small>Bandejas</small><strong id="trayJourneyDispatched">0</strong></span>
        </div>
        <small>Entregados a clientes</small>
      </article>
      <article class="java-summary-card card">
        <span>Activos que entraron</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaJourneyReceived">0</strong></span>
          <span><small>Bandejas</small><strong id="trayJourneyReceived">0</strong></span>
        </div>
        <small>Recuperados de clientes</small>
      </article>
      <article class="java-summary-card card">
        <span>Balance</span>
        <div class="java-asset-values">
          <span><small>Javas</small><strong id="javaJourneyNet" class="java-summary-debt is-clear">0</strong></span>
          <span><small>Bandejas</small><strong id="trayJourneyNet" class="java-summary-debt is-clear">0</strong></span>
        </div>
        <small>Salidas menos entradas</small>
      </article>
      <article class="java-summary-card card"><span>Camiones</span><strong id="javaJourneyTrucks">0</strong><small>Con movimientos</small></article>
    </section>

    <section class="java-history-panel java-trace-panel card" aria-labelledby="javaTraceTitle">
      <div class="java-trace-head">
        <div><p class="eyebrow">Consulta de movimientos</p><h2 id="javaTraceTitle">Información de la jornada</h2></div>
        <div class="java-trace-filters">
          <div class="java-trace-tabs" role="tablist" aria-label="Tipo de detalle">
            <button type="button" role="tab" aria-selected="true" data-java-trace-tab="activity">Consolidado</button>
            <button type="button" role="tab" aria-selected="false" data-java-trace-tab="movements">Movimientos</button>
          </div>
          <label class="java-history-filter" data-java-history-filter hidden>Ver cliente
            <select id="javaHistoryClient"><option value="">Todos los clientes</option></select>
          </label>
        </div>
      </div>

      <p id="javaHistoryMessage" class="java-message" role="status" aria-live="polite"></p>
      <div data-java-trace-panel="activity">
        <div class="java-table-wrap java-table-viewport java-trace-table-viewport">
          <table class="java-table">
            <thead><tr><th>Camión</th><th>Chofer</th><th>Cliente</th><th>Llevó</th><th>Trajo</th><th>Balance</th></tr></thead>
            <tbody id="javaTruckActivityRows"></tbody>
          </table>
        </div>
      </div>
      <div data-java-trace-panel="movements" hidden>
        <div class="java-table-wrap java-table-viewport java-trace-table-viewport">
          <table class="java-table java-history-table">
            <thead><tr><th>Fecha</th><th>Camión</th><th>Cliente</th><th>Movimiento</th><th>Javas / Bandejas</th><th>Chofer</th><th>Referencia</th></tr></thead>
            <tbody id="javaMovementRows"></tbody>
          </table>
        </div>
      </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/control-javas.js') }}"></script>
</body>
</html>
