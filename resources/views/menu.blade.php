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
      </header>

      <nav class="menu-grid" aria-label="Vistas del sistema">
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

        <a class="menu-tile menu-tile-primary" href="{{ route('despacho-minorista') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 5h16v5H4z"></path>
              <path d="M6 12h5v4H6z"></path>
              <path d="M13 12h5v4h-5z"></path>
              <path d="M5 19h14"></path>
              <path d="M8 16v3"></path><path d="M16 16v3"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Despacho minorista</strong>
            <small>Despacho rápido de pollos en bandejas</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile menu-tile-primary" href="{{ Route::has('tickets-dia') ? route('tickets-dia') : '#' }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M6 3h12v18H6z"></path>
              <path d="M9 7h6"></path>
              <path d="M9 11h6"></path>
              <path d="M9 15h4"></path>
              <path d="M4 7h2"></path>
              <path d="M18 7h2"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Resumen de la jornada</strong>
            <small>Consolidado diario por cliente</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile menu-tile-primary" href="{{ route('gestion-pesadas') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M6 3h12v18H6z"></path>
              <path d="M9 7h6"></path>
              <path d="M9 11h4"></path>
              <path d="M14.5 15.5l4-4 2 2-4 4-3 1z"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Gestión de pesadas</strong>
            <small>Buscar tickets, editar o eliminar pesadas</small>
          </span>
          <span class="menu-status">Disponible</span>
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

        <a class="menu-tile menu-tile-primary" href="{{ route('flota') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M3 7h11v9H3z"></path>
              <path d="M14 10h3l3 3v3h-6z"></path>
              <path d="M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
              <path d="M17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Mi flota y choferes</strong>
            <small>Camiones y personal propios de la empresa</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile menu-tile-primary" href="{{ route('finanzas') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 19h16"></path>
              <path d="M6 16V9"></path>
              <path d="M10 16V6"></path>
              <path d="M14 16v-4"></path>
              <path d="M18 16V4"></path>
              <path d="M5 5l4 2 4-3 5-2"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Finanzas y tesorería</strong>
            <small>Saldos, compras, cobros, pagos y cuentas</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile menu-tile-primary" href="{{ route('control-javas') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M5 4h14v5H5z"></path>
              <path d="M5 11h14v5H5z"></path>
              <path d="M7 9v2"></path><path d="M17 9v2"></path>
              <path d="M8 19h8"></path><path d="M12 16v3"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Control de javas y bandejas</strong>
            <small>Inventario, saldos y devoluciones por cliente</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

        <a class="menu-tile menu-tile-primary" href="{{ route('jornada') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 5h16v15H4z"></path>
              <path d="M8 3v4"></path>
              <path d="M16 3v4"></path>
              <path d="M7 11h4"></path>
              <path d="M13 11h4"></path>
              <path d="M7 15h4"></path>
              <path d="M13 15h4"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Jornada de proveedores</strong>
            <small>Camiones habilitados y precios del día</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>

      </nav>
    </div>
  </section>

</body>
</html>
