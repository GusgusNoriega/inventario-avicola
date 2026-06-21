<!doctype html>
<html lang="es" class="menu-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Menú Principal | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="menu-page">
  <section id="menuView" class="main-menu-view" aria-labelledby="menuTitle">
    <div class="menu-shell">
      <header class="menu-hero card">
        <div>
          <p class="eyebrow">Sistema Pollos</p>
          <h1 id="menuTitle">Menú principal</h1>
          <p class="menu-copy">Acceso rápido a las áreas de recepción, despacho y registros del sistema.</p>
        </div>
        <p id="menuNotice" class="menu-notice" role="status" aria-live="polite"></p>
      </header>

      <nav class="menu-grid" aria-label="Vistas del sistema">
        <a class="menu-tile menu-tile-primary" href="{{ route('operacion') }}#recepcion">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M3 7h11v9H3z"></path>
              <path d="M14 10h3l3 3v3h-6z"></path>
              <path d="M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
              <path d="M17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Recepción</strong>
            <small>Registro de pesadas y balanzas</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile menu-tile-primary" href="{{ route('operacion') }}#despacho">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 5h16v4H4z"></path>
              <path d="M6 9v10"></path>
              <path d="M18 9v10"></path>
              <path d="M3 19h18"></path>
              <path d="M8 13h8"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Despacho mayorista</strong>
            <small>Tickets de despacho y totales por cliente</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile" href="#facturacion" data-future-view="Registro de facturación">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M7 3h10v18l-2-1-2 1-2-1-2 1-2-1z"></path>
              <path d="M9 8h6"></path>
              <path d="M9 12h6"></path>
              <path d="M9 16h4"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Facturación</strong>
            <small>Registro de comprobantes</small>
          </span>
          <span class="menu-status menu-status-soon">Por crear</span>
        </a>

        <a class="menu-tile menu-tile-primary" href="{{ route('directorio') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path>
              <path d="M4 21a8 8 0 0 1 16 0"></path>
              <path d="M17 8h4"></path>
              <path d="M19 6v4"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Clientes y proveedores</strong>
            <small>Datos, DNI y precios por kg</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile" href="#ingresos-despachos" data-future-view="Ingresos y despachos de pollos">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M5 4h14v16H5z"></path>
              <path d="M8 8h8"></path>
              <path d="M8 12h8"></path>
              <path d="M8 16h5"></path>
              <path d="M3 8h2"></path>
              <path d="M3 16h2"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Ingresos y despachos</strong>
            <small>Movimientos de pollos</small>
          </span>
          <span class="menu-status menu-status-soon">Por crear</span>
        </a>
      </nav>
    </div>
  </section>

  <script src="{{ asset('js/menu.js') }}"></script>
</body>
</html>
