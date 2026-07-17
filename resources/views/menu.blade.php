<!doctype html>
<html lang="es" class="menu-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Menú Principal | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body class="menu-page">
  @php($user = auth()->user())
  <section id="menuView" class="main-menu-view" aria-labelledby="menuTitle">
    <div class="menu-shell">
      <header class="menu-hero card">
        <div>
          <p class="eyebrow">Sistema Pollos</p>
          <h1 id="menuTitle">Menú principal</h1>
          <p class="menu-copy">Acceso rápido a las áreas de recepción, despacho y registros del sistema.</p>
        </div>

        <div class="menu-user-actions">
          <div class="menu-user-summary">
            <span>Sesión activa</span>
            <strong>{{ $user->nombre }}</strong>
            <small>{{ $user->roles()->pluck('nombre')->join(' · ') ?: 'Sin rol asignado' }}</small>
          </div>
          <a class="menu-account-link" href="{{ route('account') }}">Mi cuenta</a>
          <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="menu-logout-button" type="submit">Cerrar sesión</button>
          </form>
          <button
            id="menuFullscreenButton"
            class="menu-fullscreen-button"
            type="button"
            aria-label="Activar pantalla completa"
            aria-pressed="false"
            title="Activar pantalla completa"
          >
            <span class="menu-fullscreen-icon" aria-hidden="true">
              <span></span>
              <span></span>
              <span></span>
              <span></span>
            </span>
            <span id="menuFullscreenLabel" class="menu-fullscreen-label">Pantalla completa</span>
          </button>
        </div>
        <p id="menuFullscreenStatus" class="sr-only" role="status" aria-live="polite"></p>
      </header>

      <nav class="menu-grid" aria-label="Vistas del sistema">
        @if ($user->moduleCodes() === [])
        <div class="menu-empty-state card">
          <strong>No tienes módulos asignados</strong>
          <span>Solicita a un administrador que habilite las vistas necesarias para tu rol.</span>
        </div>
        @endif

        @if ($user->hasModule('MODULO_DESPACHO_MAYORISTA'))
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
        @endif

        @if ($user->hasModule('MODULO_DESPACHO_MINORISTA_1'))
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
        @endif

        @if ($user->hasModule('MODULO_DESPACHO_MINORISTA_2'))
        <a class="menu-tile menu-tile-primary" href="{{ route('despacho-minorista-2') }}">
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
            <strong>Despacho minorista 2</strong>
            <small>Segundo puesto independiente de despacho minorista</small>
          </span>
          <span class="menu-status">Disponible</span>
        </a>
        @endif

        @if ($user->hasModule('MODULO_RESUMEN_JORNADA'))
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
        @endif

        @if ($user->hasModule('MODULO_GESTION_PESADAS'))
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
        @endif

        @if ($user->hasModule('MODULO_DIRECTORIO'))
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
        @endif

        @if ($user->hasModule('MODULO_FLOTA'))
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
        @endif

        @if ($user->hasModule('MODULO_FINANZAS'))
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
        @endif

        @if ($user->hasModule('MODULO_CONTROL_JAVAS'))
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
        @endif

        @if ($user->hasModule('MODULO_JORNADA_PROVEEDORES'))
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
        @endif

        @if ($user->hasModule('MODULO_USUARIOS_ROLES'))
        <a class="menu-tile menu-tile-primary menu-tile-access" href="{{ route('admin.access-control') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path>
              <path d="M4 21a8 8 0 0 1 16 0"></path>
              <path d="M18 9v6"></path>
              <path d="M15 12h6"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Usuarios y roles</strong>
            <small>Personas, contraseñas y accesos por módulo</small>
          </span>
          <span class="menu-status">Administrar</span>
        </a>
        @endif

        <a class="menu-tile menu-tile-primary menu-tile-install" href="{{ route('install-app') }}">
          <span class="menu-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M12 3v12"></path>
              <path d="m7 10 5 5 5-5"></path>
              <path d="M5 19h14"></path>
            </svg>
          </span>
          <span class="menu-tile-text">
            <strong>Instalar aplicación</strong>
            <small>Agregar Sistema Pollos a Windows en segundos</small>
          </span>
          <span class="menu-status">Instalar</span>
        </a>

      </nav>
    </div>
  </section>

  <script src="{{ asset('js/menu.js') }}?v={{ filemtime(public_path('js/menu.js')) }}"></script>
</body>
</html>
