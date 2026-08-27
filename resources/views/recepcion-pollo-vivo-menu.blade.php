<!doctype html>
<html lang="es" class="live-reception-menu-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Recepción de pollo vivo | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/recepcion-pollo-vivo-menu.css') }}?v={{ filemtime(public_path('css/recepcion-pollo-vivo-menu.css')) }}">
</head>
<body class="live-reception-menu-page">
  <main class="live-reception-menu-shell">
    <header class="live-reception-menu-header card">
      <div>
        <p class="eyebrow">Módulo de recepción</p>
        <h1>Recepción de pollo vivo</h1>
        <p>Elige si vas a registrar la recepción de la jornada o consultar el detalle de jornadas anteriores.</p>
      </div>

      <a class="menu-return-btn" href="{{ route('menu') }}" aria-label="Volver al menú principal">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M4 6h7v7H4z"></path>
          <path d="M13 6h7v7h-7z"></path>
          <path d="M4 15h7v3H4z"></path>
          <path d="M13 15h7v3h-7z"></path>
        </svg>
        <span>Menú principal</span>
      </a>
    </header>

    <section class="live-reception-menu-section" aria-labelledby="liveReceptionOptionsTitle">
      <div class="live-reception-menu-heading">
        <div>
          <p class="eyebrow">Áreas del módulo</p>
          <h2 id="liveReceptionOptionsTitle">¿Qué necesitas hacer?</h2>
        </div>
        <p>Cada opción contiene únicamente las pesadas registradas desde Recepción de pollo vivo.</p>
      </div>

      <nav class="live-reception-menu-grid" aria-label="Vistas de Recepción de pollo vivo">
        <a class="live-reception-menu-card card is-register" href="{{ route('recepcion-pollo-vivo') }}">
          <span class="live-reception-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M3 7h12v10H3z"></path>
              <path d="M15 10h3l3 3v4h-6z"></path>
              <path d="M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
              <path d="M17 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
              <path d="M6 10h6M6 13h6"></path>
            </svg>
          </span>
          <span class="live-reception-menu-copy">
            <small>Operación de la jornada</small>
            <strong>Registrar recepción</strong>
            <span>Abre la vista de balanza para ingresar pollo propio o de empresas externas.</span>
          </span>
          <span class="live-reception-menu-action">Entrar <span aria-hidden="true">→</span></span>
        </a>

        <a class="live-reception-menu-card card is-history" href="{{ route('recepcion-pollo-vivo.historial') }}">
          <span class="live-reception-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 5h16v15H4z"></path>
              <path d="M8 3v4M16 3v4M4 9h16"></path>
              <path d="M8 13h3M8 17h5M15 13h2"></path>
            </svg>
          </span>
          <span class="live-reception-menu-copy">
            <small>Consulta por jornada</small>
            <strong>Historial y totales</strong>
            <span>Filtra jornadas anteriores y revisa sus pesadas, cantidades y pesos detallados.</span>
          </span>
          <span class="live-reception-menu-action">Consultar <span aria-hidden="true">→</span></span>
        </a>
      </nav>
    </section>

    @include('partials.system-credit')
  </main>
</body>
</html>
