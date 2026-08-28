<!doctype html>
<html lang="es" class="product-dispatch-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('partials.pwa')
  <title>Administrar productos | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/despacho-productos.css') }}?v={{ filemtime(public_path('css/despacho-productos.css')) }}">
</head>
<body class="product-catalog-page">
  <main class="product-dispatch-shell product-catalog-shell">
    <header class="product-dispatch-header product-catalog-header card">
      <div>
        <p class="eyebrow">Catálogo de venta</p>
        <h1>Administrar productos</h1>
        <p>Configura el producto base y, si lo necesitas, agrega variaciones con precio, forma de cobro, merma e imagen propios.</p>
      </div>
      <a class="menu-return-btn" href="{{ route('despacho-productos.menu') }}" aria-label="Volver a Despacho de productos">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M15 5l-7 7 7 7"></path>
          <path d="M8 12h12"></path>
        </svg>
        <span>Volver al módulo</span>
      </a>
    </header>

    <section class="product-catalog-summary" aria-label="Resumen del catálogo">
      <article class="product-catalog-summary-card card">
        <span>Productos activos</span>
        <strong id="productActiveCount">—</strong>
        <small>Disponibles para el futuro despacho</small>
      </article>
      <article class="product-catalog-summary-card card">
        <span>Variaciones activas</span>
        <strong id="productVariationCount">—</strong>
        <small>Con precio y merma independientes</small>
      </article>
      <article class="product-catalog-summary-card card is-muted">
        <span>Productos eliminados</span>
        <strong id="productInactiveCount">—</strong>
        <small>Se pueden restaurar al editarlos</small>
      </article>
    </section>

    <section class="product-catalog-toolbar card" aria-label="Herramientas del catálogo">
      <label class="product-catalog-search">
        <span>Buscar producto o variación</span>
        <span class="product-catalog-input-wrap">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="M15.5 15.5L21 21"></path></svg>
          <input id="productSearch" type="search" maxlength="120" autocomplete="off" placeholder="Ej: huevo, gallina roja, pavo...">
        </span>
      </label>

      <label class="product-catalog-filter">
        <span>Estado</span>
        <select id="productStatusFilter">
          <option value="ACTIVO">Activos</option>
          <option value="TODOS">Todos</option>
          <option value="INACTIVO">Eliminados</option>
        </select>
      </label>

      <button id="newProductBtn" class="btn btn-success product-catalog-new" type="button">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
        <span>Nuevo producto</span>
      </button>
    </section>

    <p id="productCatalogMessage" class="product-catalog-message" role="status" aria-live="polite"></p>

    <section id="productCatalogGrid" class="product-catalog-grid" aria-label="Productos registrados" aria-busy="true"></section>

    <nav id="productCatalogPagination" class="product-catalog-pagination card" aria-label="Páginas del catálogo" hidden>
      <button id="productPreviousPage" class="btn btn-ghost" type="button">← Anterior</button>
      <span id="productPageLabel">Página 1</span>
      <button id="productNextPage" class="btn btn-ghost" type="button">Siguiente →</button>
    </nav>

    @include('partials.system-credit')
  </main>

  <dialog id="productEditorDialog" class="product-editor-dialog" aria-labelledby="productEditorTitle" aria-describedby="productEditorDescription">
    <form id="productEditorForm" class="product-editor-form" novalidate>
      <header class="product-editor-header">
        <div>
          <p class="eyebrow">Configuración del catálogo</p>
          <h2 id="productEditorTitle">Nuevo producto</h2>
          <p id="productEditorDescription">Los valores del producto base y de cada variación son independientes.</p>
        </div>
        <button id="closeProductEditorBtn" class="product-editor-close" type="button" aria-label="Cerrar formulario">×</button>
      </header>

      <div class="product-editor-scroll">
        <p id="productEditorMessage" class="product-editor-message" role="alert" aria-live="assertive"></p>

        <section class="product-editor-section" aria-labelledby="productGeneralTitle">
          <div class="product-editor-section-heading">
            <span class="product-editor-step">1</span>
            <div><h3 id="productGeneralTitle">Datos generales</h3><p>Identifica el producto y agrega una imagen si la tienes.</p></div>
          </div>

          <div class="product-editor-general-grid">
            <div class="product-image-field">
              <div id="productImagePreview" class="product-image-preview" aria-label="Vista previa de la imagen del producto">
                <span>Sin imagen</span>
              </div>
              <label class="product-file-button" for="productImageInput">Elegir imagen</label>
              <input id="productImageInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp">
              <button id="removeProductImageBtn" class="product-image-remove" type="button" hidden>Quitar imagen</button>
              <small>JPG, PNG o WEBP. Máximo 4 MB.</small>
            </div>

            <div class="product-editor-fields">
              <label class="field product-field-wide">
                Nombre del producto <b>*</b>
                <input id="productName" name="nombre" type="text" minlength="2" maxlength="120" autocomplete="off" placeholder="Ej: Huevo" required>
              </label>
              <label class="field product-field-wide">
                Descripción <span>Opcional</span>
                <textarea id="productDescription" name="descripcion" maxlength="500" rows="3" placeholder="Información útil para identificarlo"></textarea>
              </label>
              <label class="product-active-toggle">
                <input id="productIsActive" type="checkbox" checked>
                <span><strong>Producto activo</strong><small>Aparecerá disponible cuando se implemente la vista de despacho.</small></span>
              </label>
            </div>
          </div>
        </section>

        <section class="product-editor-section" aria-labelledby="productBaseConfigTitle">
          <div class="product-editor-section-heading">
            <span class="product-editor-step">2</span>
            <div><h3 id="productBaseConfigTitle">Precio y merma del producto base</h3><p>Estos valores se usarán cuando se venda sin elegir una variación.</p></div>
          </div>

          <div class="product-base-grid">
            <label class="field">
              Forma de cobro <b>*</b>
              <select id="productPriceMode" required>
                <option value="POR_KG">Por kilogramo</option>
                <option value="POR_UNIDAD">Por unidad</option>
              </select>
            </label>
            <label class="field">
              Precio de venta <b>*</b>
              <span class="product-money-input"><span>S/</span><input id="productPrice" type="number" min="0.0001" max="9999999999.9999" step="0.0001" inputmode="decimal" placeholder="0.00" required></span>
            </label>
            <label class="field">
              Merma por unidad (g) <b>*</b>
              <input id="productWaste" type="number" min="0" max="1000000" step="1" inputmode="numeric" value="0" required>
            </label>
          </div>
          <p class="product-merma-help">Usa 0 si el producto no tiene merma. Este valor queda preparado para el cálculo de peso de la futura vista de despacho.</p>
        </section>

        <section class="product-editor-section" aria-labelledby="productVariationsTitle">
          <div class="product-editor-section-heading product-variation-heading">
            <span class="product-editor-step">3</span>
            <div><h3 id="productVariationsTitle">Variaciones del producto</h3><p>Son opcionales. Puedes agregar hasta 19, cada una con precio, forma de cobro, merma e imagen propios.</p></div>
            <button id="addProductVariationBtn" class="btn btn-ghost" type="button">+ Agregar variación</button>
          </div>

          <div id="productVariations" class="product-variations"></div>
          <div id="productVariationsEmpty" class="product-variations-empty">
            <strong>Este producto no tiene variaciones</strong>
            <span>Puedes vender el producto base o agregar presentaciones como grande, mediano, rojo, doble, etc.</span>
          </div>
        </section>
      </div>

      <footer class="product-editor-actions">
        <button id="cancelProductEditorBtn" class="btn btn-ghost" type="button">Cancelar</button>
        <button id="saveProductBtn" class="btn btn-success" type="submit">
          <span>Guardar producto</span>
        </button>
      </footer>
    </form>
  </dialog>

  <dialog id="deleteProductDialog" class="product-delete-dialog" aria-labelledby="deleteProductTitle">
    <div class="product-delete-content">
      <span class="product-delete-icon" aria-hidden="true">!</span>
      <h2 id="deleteProductTitle">Eliminar producto del catálogo</h2>
      <p id="deleteProductDescription">El producto dejará de aparecer en los despachos, pero su información se conservará y podrás restaurarlo después.</p>
      <div class="product-delete-actions">
        <button id="cancelDeleteProductBtn" class="btn btn-ghost" type="button">Cancelar</button>
        <button id="confirmDeleteProductBtn" class="btn product-danger-btn" type="button">Sí, eliminar</button>
      </div>
    </div>
  </dialog>

  <script type="module" src="{{ asset('js/despacho-productos.js') }}?v={{ filemtime(public_path('js/despacho-productos.js')) }}"></script>
</body>
</html>
