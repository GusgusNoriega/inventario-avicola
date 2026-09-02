<!doctype html>
<html lang="es" class="product-dispatch-clients-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Agregar clientes | Despacho de productos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos-clientes.css') }}?v={{ filemtime(public_path('css/despacho-productos-clientes.css')) }}">
</head>
<body class="product-dispatch-clients-page">
  <main
    id="productDispatchQuickClients"
    class="pdqc-shell"
    data-api-base="/despacho-productos/clientes"
  >
    <header class="pdqc-header card">
      <div>
        <p class="eyebrow">Despacho de productos</p>
        <h1>Agregar clientes</h1>
        <p>Registra clientes externos con sus datos básicos para encontrarlos rápidamente al preparar un despacho.</p>
      </div>

      <a class="menu-return-btn pdqc-back" href="{{ route('despacho-productos.menu') }}" aria-label="Volver a las opciones de Despacho de productos">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z"></path>
        </svg>
        <span>Módulo</span>
      </a>
    </header>

    <section class="pdqc-workspace" aria-label="Registro rápido y consulta de clientes externos">
      <article class="pdqc-form-panel card" aria-labelledby="pdqcFormTitle">
        <header class="pdqc-section-heading">
          <div>
            <p class="eyebrow">Registro rápido</p>
            <h2 id="pdqcFormTitle">Nuevo cliente</h2>
          </div>
          <div class="pdqc-form-badges">
            <span id="pdqcEditBadge" class="pdqc-edit-badge" hidden>Editando</span>
            <span class="pdqc-external-badge">Externo</span>
          </div>
        </header>

        <p id="pdqcFormIntro" class="pdqc-section-intro">Completa los tres datos y guarda. El formulario quedará listo para registrar al siguiente cliente.</p>

        <form id="pdqcForm" class="pdqc-form" novalidate>
          <label class="pdqc-field" for="pdqcName">
            <span>Nombre / razón social <b aria-hidden="true">*</b></span>
            <input
              id="pdqcName"
              name="nombre_razon_social"
              type="text"
              maxlength="180"
              autocomplete="organization"
              placeholder="Ej: Comercial El Sol"
              autofocus
              required
            >
          </label>

          <label class="pdqc-field" for="pdqcDocument">
            <span>DNI / RUC <b aria-hidden="true">*</b></span>
            <input
              id="pdqcDocument"
              name="numero_documento"
              type="text"
              inputmode="numeric"
              maxlength="11"
              pattern="(?:\d{8}|\d{11})"
              autocomplete="off"
              aria-describedby="pdqcDocumentHelp"
              placeholder="8 u 11 dígitos"
              required
            >
            <small id="pdqcDocumentHelp">DNI de 8 dígitos o RUC de 11 dígitos.</small>
          </label>

          <label class="pdqc-field" for="pdqcAddress">
            <span>Dirección <b aria-hidden="true">*</b></span>
            <input
              id="pdqcAddress"
              name="direccion"
              type="text"
              maxlength="250"
              autocomplete="street-address"
              placeholder="Ej: Av. Principal 123"
              required
            >
          </label>

          <div class="pdqc-form-actions">
            <button id="pdqcSave" class="btn btn-primary pdqc-save" type="submit">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 4v16M4 12h16"></path>
              </svg>
              <span>Guardar cliente</span>
            </button>
            <button id="pdqcClear" class="btn btn-ghost pdqc-clear" type="button">Limpiar</button>
            <button id="pdqcCancelEdit" class="btn btn-ghost pdqc-cancel-edit" type="button" hidden>Cancelar edición</button>
          </div>

          <p id="pdqcFormMessage" class="pdqc-message" role="status" aria-live="polite"></p>
        </form>
      </article>

      <section class="pdqc-list-panel" aria-labelledby="pdqcListTitle">
        <header class="pdqc-list-heading">
          <div>
            <p class="eyebrow">Consulta rápida</p>
            <h2 id="pdqcListTitle">Clientes externos</h2>
          </div>
          <span id="pdqcClientCount" class="pdqc-count" aria-live="polite">0 clientes</span>
        </header>

        <label class="pdqc-search" for="pdqcSearch">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="10.5" cy="10.5" r="6.5"></circle>
            <path d="m15.5 15.5 5 5"></path>
          </svg>
          <span class="pdqc-sr-only">Buscar clientes externos</span>
          <input id="pdqcSearch" type="search" maxlength="100" autocomplete="off" placeholder="Buscar por nombre o documento">
        </label>

        <p id="pdqcListMessage" class="pdqc-message" role="status" aria-live="polite"></p>
        <div id="pdqcClientList" class="pdqc-client-list" role="list" aria-busy="true"></div>
      </section>
    </section>

    @include('partials.system-credit')
  </main>

  <script type="module" src="{{ asset('js/despacho-productos-clientes.js') }}?v={{ filemtime(public_path('js/despacho-productos-clientes.js')) }}"></script>
</body>
</html>
