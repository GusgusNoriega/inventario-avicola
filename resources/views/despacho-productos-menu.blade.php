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
      </nav>
    </section>

    @include('partials.system-credit')
  </main>
</body>
</html>
