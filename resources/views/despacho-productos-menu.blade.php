<!doctype html>
<html lang="es" class="product-dispatch-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Despacho de productos | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos.css') }}?v={{ filemtime(public_path('css/despacho-productos.css')) }}">
</head>
<body class="product-dispatch-menu-page">
  <main class="product-dispatch-shell product-dispatch-menu-shell">
    <header class="product-dispatch-header card">
      <div>
        <p class="eyebrow">Nuevo módulo de venta</p>
        <h1>Despacho de productos</h1>
        <p>Administra y vende huevos, gallinas, pavos y otros productos avícolas sin usar javas ni bandejas.</p>
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

    <section class="product-dispatch-menu-section" aria-labelledby="productDispatchOptionsTitle">
      <div class="product-dispatch-section-heading">
        <div>
          <p class="eyebrow">Áreas del módulo</p>
          <h2 id="productDispatchOptionsTitle">¿Qué necesitas hacer?</h2>
        </div>
        <p>Primero configura los productos, sus variaciones, precios, mermas e imágenes. Luego estarán disponibles para vender.</p>
      </div>

      <nav class="product-dispatch-menu-grid" aria-label="Vistas de Despacho de productos">
        <a class="product-dispatch-menu-card card is-catalog" href="{{ route('despacho-productos.productos') }}">
          <span class="product-dispatch-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 5h16v15H4z"></path>
              <path d="M8 9h8M8 13h5M8 17h7"></path>
              <path d="M17 12v4M15 14h4"></path>
            </svg>
          </span>
          <span class="product-dispatch-menu-copy">
            <small>Catálogo de venta</small>
            <strong>Administrar productos</strong>
            <span>Agrega, edita o elimina productos y configura cada una de sus variaciones.</span>
          </span>
          <span class="product-dispatch-menu-action">Administrar <span aria-hidden="true">→</span></span>
        </a>

        <a class="product-dispatch-menu-card card is-dispatch" href="{{ route('despacho-productos.despacho') }}">
          <span class="product-dispatch-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M4 6h16v12H4z"></path>
              <path d="M8 10h8M8 14h4"></path>
              <path d="M17 13v6M14 16h6"></path>
            </svg>
          </span>
          <span class="product-dispatch-menu-copy">
            <small>Venta por cantidad y peso</small>
            <strong>Despachar productos</strong>
            <span>Selecciona productos, registra cantidades, captura el peso y genera la venta.</span>
          </span>
          <span class="product-dispatch-menu-action">Abrir despacho <span aria-hidden="true">→</span></span>
        </a>

        <a class="product-dispatch-menu-card card is-clients" href="{{ route('despacho-productos.clientes') }}">
          <span class="product-dispatch-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <circle cx="9" cy="8" r="3.5"></circle>
              <path d="M3.5 19c.6-3.5 2.4-5.2 5.5-5.2s4.9 1.7 5.5 5.2M17 8v6M14 11h6"></path>
            </svg>
          </span>
          <span class="product-dispatch-menu-copy">
            <small>Registro rápido</small>
            <strong>Agregar clientes</strong>
            <span>Crea, consulta, edita o elimina clientes externos desde una pantalla sencilla del módulo.</span>
          </span>
          <span class="product-dispatch-menu-action">Gestionar clientes <span aria-hidden="true">→</span></span>
        </a>

        <a class="product-dispatch-menu-card card is-clients" href="{{ route('despacho-productos.pagos') }}">
          <span class="product-dispatch-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M3 6h18v13H3zM3 10h18M7 15h3"></path>
              <path d="M16 13v4M14 15h4"></path>
            </svg>
          </span>
          <span class="product-dispatch-menu-copy">
            <small>Ingresos de clientes</small>
            <strong>Pagos de clientes</strong>
            <span>Registra, consulta, edita o elimina pagos recibidos por la empresa. El número de transacción es opcional.</span>
          </span>
          <span class="product-dispatch-menu-action">Gestionar pagos <span aria-hidden="true">→</span></span>
        </a>

        <a class="product-dispatch-menu-card card is-ticket" href="{{ route('despacho-productos.configuracion-ticket') }}">
          <span class="product-dispatch-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21z"></path>
              <path d="M9 8h6M9 12h6M9 16h4"></path>
            </svg>
          </span>
          <span class="product-dispatch-menu-copy">
            <small>Comprobante exclusivo</small>
            <strong>Configurar ticket</strong>
            <span>Define el encabezado que se imprimirá únicamente en los tickets de este despacho.</span>
          </span>
          <span class="product-dispatch-menu-action">Configurar <span aria-hidden="true">→</span></span>
        </a>

        <a class="product-dispatch-menu-card card is-history" href="{{ route('despacho-productos.tickets') }}">
          <span class="product-dispatch-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21z"></path>
              <path d="M9 8h6M9 12h4M9 16h3"></path>
              <circle cx="16.5" cy="15.5" r="2.5"></circle>
              <path d="m18.3 17.3 2.2 2.2"></path>
            </svg>
          </span>
          <span class="product-dispatch-menu-copy">
            <small>Consulta y corrección</small>
            <strong>Tickets de despacho</strong>
            <span>Busca, revisa, edita por completo o vuelve a imprimir cualquier ticket de productos.</span>
          </span>
          <span class="product-dispatch-menu-action">Ver tickets <span aria-hidden="true">→</span></span>
        </a>

        <a class="product-dispatch-menu-card card is-statement" href="{{ route('despacho-productos.estado-cuenta') }}">
          <span class="product-dispatch-menu-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M5 3h14v18H5z"></path>
              <path d="M8 7h8M8 11h4M8 15h3"></path>
              <path d="M14 14h2.5a2 2 0 0 1 0 4H14zM15.5 13v6"></path>
            </svg>
          </span>
          <span class="product-dispatch-menu-copy">
            <small>Consulta y reporte PDF</small>
            <strong>Estado de cuenta</strong>
            <span>Revisa las ventas y abonos de cada cliente en este módulo, consulta su deuda y prepara el reporte para enviarlo.</span>
          </span>
          <span class="product-dispatch-menu-action">Consultar cuenta <span aria-hidden="true">→</span></span>
        </a>
      </nav>
    </section>

    @include('partials.system-credit')
  </main>
</body>
</html>
