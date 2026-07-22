<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Finanzas y tesorería | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body class="fin-page fin-module-page">
  <main class="fin-shell fin-module-shell">
    <header class="fin-header fin-card fin-module-header">
      <div class="fin-header-copy">
        <p class="fin-eyebrow">Módulo financiero</p>
        <h1>Finanzas y tesorería</h1>
        <p>Consulta saldos, controla las compras y registra el recorrido del dinero desde un solo lugar.</p>
      </div>

      <div class="fin-header-actions">
        <a class="menu-return-btn" href="{{ route('menu') }}" aria-label="Volver al menú principal">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 6h7v7H4z"></path>
            <path d="M13 6h7v7h-7z"></path>
            <path d="M4 15h7v3H4z"></path>
            <path d="M13 15h7v3h-7z"></path>
          </svg>
          <span>Menú principal</span>
        </a>
      </div>
    </header>

    <section class="fin-module-section" aria-labelledby="financeModulesTitle">
      <div class="fin-module-heading">
        <div>
          <p class="fin-eyebrow">Áreas del módulo</p>
          <h2 id="financeModulesTitle">¿Qué necesitas gestionar?</h2>
        </div>
        <p>Selecciona una opción para consultar información o registrar una operación financiera.</p>
      </div>

      <nav class="fin-module-grid" aria-label="Vistas de Finanzas y tesorería">
        <a class="fin-module-card fin-card" href="{{ route('finanzas.saldos') }}">
          <span class="fin-module-icon is-balances" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 19h16"></path>
              <path d="M6 16V9"></path>
              <path d="M10 16V6"></path>
              <path d="M14 16v-4"></path>
              <path d="M18 16V4"></path>
              <path d="M5 5l4 2 4-3 5-2"></path>
            </svg>
          </span>
          <span class="fin-module-copy">
            <strong>Saldos y trazabilidad</strong>
            <small>Consulta liquidez, cartera, cuentas por pagar y el recorrido de cada movimiento.</small>
          </span>
          <span class="fin-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>

        <a class="fin-module-card fin-card" href="{{ route('compras.index') }}">
          <span class="fin-module-icon is-purchases" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 5h16v14H4z"></path>
              <path d="M7 9h10"></path>
              <path d="M7 13h6"></path>
              <path d="M15 16h3"></path>
              <path d="M17 14v4"></path>
            </svg>
          </span>
          <span class="fin-module-copy">
            <strong>Compras a proveedores</strong>
            <small>Registra compras al contado o a crédito y controla lo pendiente por proveedor.</small>
          </span>
          <span class="fin-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>

        <a class="fin-module-card fin-card" href="{{ route('finanzas.entidades') }}">
          <span class="fin-module-icon is-accounts" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 9h16"></path>
              <path d="M6 9v8"></path>
              <path d="M10 9v8"></path>
              <path d="M14 9v8"></path>
              <path d="M18 9v8"></path>
              <path d="M3 19h18"></path>
              <path d="M12 3l9 4H3z"></path>
            </svg>
          </span>
          <span class="fin-module-copy">
            <strong>Empresas y cuentas</strong>
            <small>Administra bancos, cajas, billeteras y cuentas propias o de proveedores.</small>
          </span>
          <span class="fin-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>

        <a class="fin-module-card fin-card" href="{{ route('finanzas.movimientos.nuevo') }}">
          <span class="fin-module-icon is-movements" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M7 7h12"></path>
              <path d="M16 4l3 3-3 3"></path>
              <path d="M17 17H5"></path>
              <path d="M8 14l-3 3 3 3"></path>
            </svg>
          </span>
          <span class="fin-module-copy">
            <strong>Registrar cobro o pago</strong>
            <small>Registra cobros, depósitos directos y pagos a proveedores con trazabilidad.</small>
          </span>
          <span class="fin-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>

        <a class="fin-module-card fin-card" href="{{ route('finanzas.reportes') }}">
          <span class="fin-module-icon is-balances" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M6 3h9l4 4v14H6z"></path>
              <path d="M15 3v5h4"></path>
              <path d="M9 12h7"></path>
              <path d="M9 16h7"></path>
            </svg>
          </span>
          <span class="fin-module-copy">
            <strong>Reportes PDF</strong>
            <small>Genera ventas por cliente, estados de cuenta, pagos y movimientos por responsable.</small>
          </span>
          <span class="fin-module-action">Abrir <span aria-hidden="true">→</span></span>
        </a>
      </nav>
    </section>
  </main>
</body>
</html>
