<!doctype html>
<html lang="es" class="java-control-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Control de Javas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="java-control-page" data-java-page="dashboard">
  <main class="java-control-shell java-dashboard-shell">
    @include('partials.java-control-header', [
      'eyebrow' => 'Control de activos',
      'title' => 'Control de javas',
      'description' => 'Consulta el estado actual y entra únicamente a la tarea que necesitas realizar.',
      'showMenu' => true,
    ])

    <section class="java-summary java-dashboard-summary" aria-label="Estado actual de las javas">
      <article class="java-summary-card card">
        <span>Disponibles en la empresa</span>
        <strong id="javaCompanyInside">—</strong>
        <small id="javaInventoryStatus">Calculando inventario actual</small>
      </article>
      <article class="java-summary-card card">
        <span>Fuera con clientes</span>
        <strong id="javaCompanyOutside">0</strong>
        <small>Javas pendientes de retorno</small>
      </article>
      <article class="java-summary-card card">
        <span>Clientes con pendientes</span>
        <strong id="javaClientsPending">0</strong>
        <small id="javaPendingSummary">Sin saldos pendientes</small>
      </article>
      <article class="java-summary-card card">
        <span>Recibidas hoy</span>
        <strong id="javaReceivedToday">0</strong>
        <small>Entradas registradas hoy</small>
      </article>
    </section>

    <section class="java-module-section" aria-labelledby="javaModulesTitle">
      <div class="java-module-heading">
        <div>
          <p class="eyebrow">Áreas del control</p>
          <h2 id="javaModulesTitle">¿Qué necesitas hacer?</h2>
        </div>
        <p>Cada vista contiene solo la información y las acciones relacionadas con esa tarea.</p>
      </div>

      <nav class="java-module-nav" aria-label="Vistas del control de javas">
        <a class="java-module-card card" href="{{ route('control-javas.inventario') }}">
          <span class="java-module-icon is-inventory" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 5h16v5H4zM4 14h16v5H4z"></path><path d="M8 10v4M16 10v4"></path></svg>
          </span>
          <span class="java-module-copy">
            <strong>Inventario y conteo</strong>
            <small>Existencias totales, disponibles y verificación física de la jornada.</small>
          </span>
          <span class="java-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>

        <a class="java-module-card card" href="{{ route('control-javas.devoluciones') }}">
          <span class="java-module-icon is-returns" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 7h12v10H4zM16 10h3l2 3v4h-5z"></path><path d="M8 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path></svg>
          </span>
          <span class="java-module-copy">
            <strong>Pendientes y devoluciones</strong>
            <small>Consulta qué debe cada cliente y registra las javas que regresan.</small>
          </span>
          <span class="java-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>

        <a class="java-module-card card" href="{{ route('control-javas.trazabilidad') }}">
          <span class="java-module-icon is-trace" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M5 5h14M5 12h14M5 19h14"></path><circle cx="8" cy="5" r="2"></circle><circle cx="16" cy="12" r="2"></circle><circle cx="10" cy="19" r="2"></circle></svg>
          </span>
          <span class="java-module-copy">
            <strong>Trazabilidad por jornada</strong>
            <small>Revisa salidas, entradas, camiones y el detalle de los movimientos.</small>
          </span>
          <span class="java-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>
      </nav>
    </section>
  </main>

  <script type="module" src="{{ asset('js/control-javas.js') }}"></script>
</body>
</html>
