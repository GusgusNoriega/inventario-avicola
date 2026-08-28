import { apiRequest } from "./api-client.js";
import {
  PRICE_MODE_KG,
  escapeHtml,
  formatSalePrice,
  imageFileError,
  productInitial
} from "./dispatch-product-catalog-utils.js";

const ICONS = {
  edit: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l4.5-1 10-10-3.5-3.5-10 10z"></path><path d="M13.5 7l3.5 3.5"></path></svg>',
  delete: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3M7 7l1 13h8l1-13"></path><path d="M10 11v5M14 11v5"></path></svg>'
};
const MAX_VARIATIONS = 19;

const elements = {
  grid: document.querySelector("#productCatalogGrid"),
  message: document.querySelector("#productCatalogMessage"),
  search: document.querySelector("#productSearch"),
  status: document.querySelector("#productStatusFilter"),
  newButton: document.querySelector("#newProductBtn"),
  activeCount: document.querySelector("#productActiveCount"),
  inactiveCount: document.querySelector("#productInactiveCount"),
  variationCount: document.querySelector("#productVariationCount"),
  pagination: document.querySelector("#productCatalogPagination"),
  previousPage: document.querySelector("#productPreviousPage"),
  nextPage: document.querySelector("#productNextPage"),
  pageLabel: document.querySelector("#productPageLabel"),
  editor: document.querySelector("#productEditorDialog"),
  editorForm: document.querySelector("#productEditorForm"),
  editorTitle: document.querySelector("#productEditorTitle"),
  editorDescription: document.querySelector("#productEditorDescription"),
  editorMessage: document.querySelector("#productEditorMessage"),
  closeEditor: document.querySelector("#closeProductEditorBtn"),
  cancelEditor: document.querySelector("#cancelProductEditorBtn"),
  saveButton: document.querySelector("#saveProductBtn"),
  name: document.querySelector("#productName"),
  description: document.querySelector("#productDescription"),
  isActive: document.querySelector("#productIsActive"),
  priceMode: document.querySelector("#productPriceMode"),
  price: document.querySelector("#productPrice"),
  waste: document.querySelector("#productWaste"),
  imageInput: document.querySelector("#productImageInput"),
  imagePreview: document.querySelector("#productImagePreview"),
  removeImage: document.querySelector("#removeProductImageBtn"),
  variations: document.querySelector("#productVariations"),
  addVariation: document.querySelector("#addProductVariationBtn"),
  deleteDialog: document.querySelector("#deleteProductDialog"),
  deleteDescription: document.querySelector("#deleteProductDescription"),
  cancelDelete: document.querySelector("#cancelDeleteProductBtn"),
  confirmDelete: document.querySelector("#confirmDeleteProductBtn")
};

const state = {
  products: [],
  currentPage: 1,
  lastPage: 1,
  editingId: null,
  deletingId: null,
  busy: false,
  searchTimer: null,
  requestRevision: 0,
  variationKey: 0,
  variations: [],
  image: emptyImageState(),
  lastFocus: null
};

function emptyImageState(currentUrl = null) {
  return {
    currentUrl,
    file: null,
    remove: false,
    previewUrl: null
  };
}

function setMessage(target, message = "", tone = "") {
  target.textContent = message;
  target.classList.toggle("is-error", tone === "error");
  target.classList.toggle("is-success", tone === "success");
}

function errorMessage(error) {
  const errors = error?.data?.errors;
  if (errors && typeof errors === "object") {
    const first = Object.values(errors).flat().find(Boolean);
    if (first) {
      return String(first);
    }
  }

  return error?.message || "No se pudo completar la solicitud.";
}

function productCard(product) {
  const inactive = product.status === "INACTIVO";
  const variations = Array.isArray(product.variations) ? product.variations : [];
  const media = product.image_url
    ? `<img src="${escapeHtml(product.image_url)}" alt="Imagen de ${escapeHtml(product.name)}" loading="lazy" width="420" height="240">`
    : `<span class="product-card-placeholder" aria-hidden="true">${escapeHtml(productInitial(product.name))}</span>`;
  const description = product.description || "Sin descripción adicional";

  return `
    <article class="product-card${inactive ? " is-inactive" : ""}" data-product-id="${Number(product.id)}">
      <div class="product-card-media">
        ${media}
        <span class="product-card-status">${inactive ? "Eliminado" : "Activo"}</span>
      </div>
      <div class="product-card-body">
        <div class="product-card-heading">
          <h2>${escapeHtml(product.name)}</h2>
          <p>${escapeHtml(description)}</p>
        </div>
        <div class="product-card-config">
          <span><small>Precio base</small><strong>${escapeHtml(formatSalePrice(product.price, product.price_mode))}</strong></span>
          <span><small>Merma</small><strong>${Number(product.waste_grams_per_unit || 0).toLocaleString("es-PE")} g / unidad</strong></span>
        </div>
        <div class="product-card-variations">
          <small>Variaciones activas</small>
          <strong>${variations.length}</strong>
        </div>
      </div>
      <div class="product-card-actions">
        <button type="button" data-product-action="edit" data-product-id="${Number(product.id)}">
          ${ICONS.edit}<span>${inactive ? "Editar / restaurar" : "Editar"}</span>
        </button>
        ${inactive ? "" : `
          <button class="is-danger" type="button" data-product-action="delete" data-product-id="${Number(product.id)}">
            ${ICONS.delete}<span>Eliminar</span>
          </button>`}
      </div>
    </article>`;
}

function renderProducts() {
  if (!state.products.length) {
    const inactive = elements.status.value === "INACTIVO";
    elements.grid.innerHTML = `
      <div class="product-catalog-empty">
        <strong>${inactive ? "No hay productos eliminados" : "No hay productos para mostrar"}</strong>
        <span>${inactive ? "Los productos que elimines aparecerán aquí." : "Agrega el primer producto avícola de este catálogo."}</span>
      </div>`;
    return;
  }

  elements.grid.innerHTML = state.products.map(productCard).join("");
}

function renderSummary(summary = {}) {
  elements.activeCount.textContent = String(summary.active_products ?? 0);
  elements.inactiveCount.textContent = String(summary.inactive_products ?? 0);
  elements.variationCount.textContent = String(summary.active_variations ?? 0);
}

function renderPagination() {
  elements.pagination.hidden = state.lastPage <= 1;
  elements.pageLabel.textContent = `Página ${state.currentPage} de ${state.lastPage}`;
  elements.previousPage.disabled = state.currentPage <= 1 || state.busy;
  elements.nextPage.disabled = state.currentPage >= state.lastPage || state.busy;
}

async function loadProducts(page = 1, preserveMessage = false) {
  const revision = ++state.requestRevision;
  const params = new URLSearchParams({
    page: String(page),
    per_page: "24",
    estado: elements.status.value
  });
  const search = elements.search.value.trim();
  if (search) {
    params.set("buscar", search);
  }

  elements.grid.setAttribute("aria-busy", "true");
  if (!preserveMessage) {
    setMessage(elements.message, "Cargando productos...");
  }

  try {
    const response = await apiRequest(`/productos-despacho?${params.toString()}`);
    if (revision !== state.requestRevision) {
      return;
    }

    state.products = response.data || [];
    state.currentPage = Number(response.meta?.current_page || page);
    state.lastPage = Math.max(1, Number(response.meta?.last_page || 1));
    renderSummary(response.summary);
    renderProducts();
    renderPagination();
    if (!preserveMessage) {
      setMessage(elements.message);
    }
  } catch (error) {
    if (revision !== state.requestRevision) {
      return;
    }

    state.products = [];
    renderProducts();
    setMessage(elements.message, errorMessage(error), "error");
  } finally {
    if (revision === state.requestRevision) {
      elements.grid.setAttribute("aria-busy", "false");
    }
  }
}

function resetEditor(product = null) {
  releaseImageState(state.image);
  state.variations.forEach((variation) => releaseImageState(variation.image));
  elements.editorForm.reset();
  clearInvalidFields();
  setMessage(elements.editorMessage);
  state.editingId = product ? Number(product.id) : null;
  state.image = emptyImageState(product?.image_url || null);
  state.variations = (product?.variations || []).map((variation) => ({
    key: ++state.variationKey,
    id: Number(variation.id),
    name: variation.name || "",
    priceMode: variation.price_mode || PRICE_MODE_KG,
    price: variation.price || "",
    waste: Number(variation.waste_grams_per_unit || 0),
    image: emptyImageState(variation.image_url || null)
  }));

  elements.editorTitle.textContent = product ? "Editar producto" : "Nuevo producto";
  elements.editorDescription.textContent = product
    ? "Actualiza la configuración. Las variaciones que retires dejarán de estar disponibles."
    : "Los valores del producto base y de cada variación son independientes.";
  elements.name.value = product?.name || "";
  elements.description.value = product?.description || "";
  elements.isActive.checked = !product || product.status === "ACTIVO";
  elements.priceMode.value = product?.price_mode || PRICE_MODE_KG;
  elements.price.value = product?.price || "";
  elements.waste.value = String(product?.waste_grams_per_unit ?? 0);
  elements.saveButton.querySelector("span").textContent = product ? "Actualizar producto" : "Guardar producto";
  renderProductImage();
  renderVariations();
}

function openEditor(product = null) {
  state.lastFocus = document.activeElement;
  resetEditor(product);
  elements.editor.showModal();
  window.setTimeout(() => elements.name.focus(), 0);
}

function closeEditor() {
  if (elements.editor.open) {
    elements.editor.close();
  }
}

function releaseImageState(image) {
  if (image?.previewUrl) {
    URL.revokeObjectURL(image.previewUrl);
    image.previewUrl = null;
  }
}

function imagePreviewUrl(image) {
  if (image.remove) {
    return null;
  }

  return image.previewUrl || image.currentUrl || null;
}

function renderProductImage() {
  const url = imagePreviewUrl(state.image);
  elements.imagePreview.innerHTML = url
    ? `<img src="${escapeHtml(url)}" alt="Vista previa del producto">`
    : "<span>Sin imagen</span>";
  elements.removeImage.hidden = !url;
}

function variationCard(variation, index) {
  const preview = imagePreviewUrl(variation.image);
  const key = Number(variation.key);

  return `
    <article class="product-variation-card" data-variation-key="${key}">
      <div class="product-variation-card-header">
        <strong>Variación ${index + 1}${variation.name ? ` · ${escapeHtml(variation.name)}` : ""}</strong>
        <button class="remove-variation-btn" type="button" data-variation-action="remove">Quitar variación</button>
      </div>
      <div class="variation-layout">
        <div class="variation-image-control">
          <div class="variation-image-preview">${preview
            ? `<img src="${escapeHtml(preview)}" alt="Vista previa de ${escapeHtml(variation.name || `variación ${index + 1}`)}">`
            : "<span>Sin imagen propia</span>"}</div>
          <label class="variation-image-button" for="variationImage${key}">Elegir imagen</label>
          <input id="variationImage${key}" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" data-variation-action="image">
          <button class="variation-image-remove" type="button" data-variation-action="remove-image"${preview ? "" : " hidden"}>Quitar imagen</button>
        </div>
        <div class="variation-fields">
          <label class="field">
            Nombre <b>*</b>
            <input type="text" maxlength="120" autocomplete="off" placeholder="Ej: Grande" required value="${escapeHtml(variation.name)}" data-variation-field="name" data-variation-index="${index}">
          </label>
          <label class="field">
            Forma de cobro <b>*</b>
            <select required data-variation-field="priceMode" data-variation-index="${index}">
              <option value="POR_KG"${variation.priceMode === "POR_KG" ? " selected" : ""}>Por kilogramo</option>
              <option value="POR_UNIDAD"${variation.priceMode === "POR_UNIDAD" ? " selected" : ""}>Por unidad</option>
            </select>
          </label>
          <label class="field">
            Precio <b>*</b>
            <input type="number" min="0.0001" max="9999999999.9999" step="0.0001" inputmode="decimal" placeholder="0.00" required value="${escapeHtml(variation.price)}" data-variation-field="price" data-variation-index="${index}">
          </label>
          <label class="field">
            Merma (g/unidad) <b>*</b>
            <input type="number" min="0" max="1000000" step="1" inputmode="numeric" required value="${escapeHtml(variation.waste)}" data-variation-field="waste" data-variation-index="${index}">
          </label>
        </div>
      </div>
    </article>`;
}

function renderVariations() {
  elements.variations.innerHTML = state.variations
    .map((variation, index) => variationCard(variation, index))
    .join("");
  updateVariationLimit();
}

function updateVariationLimit() {
  const reachedLimit = state.variations.length >= MAX_VARIATIONS;
  elements.addVariation.disabled = state.busy || reachedLimit;
  elements.addVariation.title = reachedLimit
    ? `Puedes registrar hasta ${MAX_VARIATIONS} variaciones por producto.`
    : "";
}

function addVariation() {
  if (state.variations.length >= MAX_VARIATIONS) {
    setMessage(
      elements.editorMessage,
      `Puedes registrar hasta ${MAX_VARIATIONS} variaciones por producto.`,
      "error"
    );
    return;
  }

  state.variations.push({
    key: ++state.variationKey,
    id: null,
    name: "",
    priceMode: elements.priceMode.value || PRICE_MODE_KG,
    price: elements.price.value || "",
    waste: Number(elements.waste.value || 0),
    image: emptyImageState()
  });
  renderVariations();
  const input = elements.variations.querySelector(".product-variation-card:last-child [data-variation-field='name']");
  input?.focus();
}

function variationFromElement(element) {
  const card = element.closest("[data-variation-key]");
  const key = Number(card?.dataset.variationKey);

  return state.variations.find((variation) => variation.key === key) || null;
}

function handleVariationInput(event) {
  const field = event.target.dataset.variationField;
  if (!field) {
    return;
  }

  const variation = variationFromElement(event.target);
  if (variation) {
    variation[field] = event.target.value;
    event.target.removeAttribute("aria-invalid");
  }
}

function handleVariationChange(event) {
  const action = event.target.dataset.variationAction;
  if (action !== "image") {
    handleVariationInput(event);
    return;
  }

  const variation = variationFromElement(event.target);
  const file = event.target.files?.[0] || null;
  const validationError = imageFileError(file);
  if (!variation || !file) {
    return;
  }

  if (validationError) {
    event.target.value = "";
    setMessage(elements.editorMessage, validationError, "error");
    return;
  }

  releaseImageState(variation.image);
  variation.image.file = file;
  variation.image.remove = false;
  variation.image.previewUrl = URL.createObjectURL(file);
  setMessage(elements.editorMessage);
  renderVariations();
}

function handleVariationClick(event) {
  const button = event.target.closest("[data-variation-action]");
  if (!button || button.tagName === "INPUT") {
    return;
  }

  const variation = variationFromElement(button);
  if (!variation) {
    return;
  }

  if (button.dataset.variationAction === "remove") {
    releaseImageState(variation.image);
    state.variations = state.variations.filter((item) => item.key !== variation.key);
  } else if (button.dataset.variationAction === "remove-image") {
    releaseImageState(variation.image);
    variation.image.file = null;
    variation.image.remove = true;
  }

  renderVariations();
}

function appendImage(formData, imageName, removeName, image) {
  if (image.file) {
    formData.append(imageName, image.file);
  } else if (image.remove) {
    formData.append(removeName, "1");
  }
}

function productFormData() {
  const formData = new FormData();
  formData.append("nombre", elements.name.value.trim());
  formData.append("descripcion", elements.description.value.trim());
  formData.append("modo_precio", elements.priceMode.value);
  formData.append("precio_venta", elements.price.value);
  formData.append("merma_gramos_unidad", elements.waste.value);
  formData.append("estado", elements.isActive.checked ? "ACTIVO" : "INACTIVO");
  formData.append("sincronizar_variaciones", "1");
  appendImage(formData, "imagen", "eliminar_imagen", state.image);

  state.variations.forEach((variation, index) => {
    const prefix = `variaciones[${index}]`;
    if (variation.id) {
      formData.append(`${prefix}[id]`, String(variation.id));
    }
    formData.append(`${prefix}[nombre]`, String(variation.name).trim());
    formData.append(`${prefix}[modo_precio]`, variation.priceMode);
    formData.append(`${prefix}[precio_venta]`, String(variation.price));
    formData.append(`${prefix}[merma_gramos_unidad]`, String(variation.waste));
    appendImage(
      formData,
      `${prefix}[imagen]`,
      `${prefix}[eliminar_imagen]`,
      variation.image
    );
  });

  return formData;
}

function clearInvalidFields() {
  elements.editorForm.querySelectorAll("[aria-invalid='true']")
    .forEach((field) => field.removeAttribute("aria-invalid"));
}

function showValidationErrors(error) {
  clearInvalidFields();
  const errors = error?.data?.errors || {};
  let firstField = null;
  const baseFields = {
    nombre: elements.name,
    descripcion: elements.description,
    modo_precio: elements.priceMode,
    precio_venta: elements.price,
    merma_gramos_unidad: elements.waste,
    imagen: elements.imageInput
  };

  Object.keys(errors).forEach((path) => {
    let field = baseFields[path] || null;
    const variationMatch = path.match(/^variaciones\.(\d+)\.(nombre|modo_precio|precio_venta|merma_gramos_unidad|imagen)$/);
    if (variationMatch) {
      const [, index, name] = variationMatch;
      const fieldName = {
        nombre: "name",
        modo_precio: "priceMode",
        precio_venta: "price",
        merma_gramos_unidad: "waste"
      }[name];
      field = fieldName
        ? elements.variations.querySelector(`[data-variation-index="${index}"][data-variation-field="${fieldName}"]`)
        : elements.variations.querySelectorAll("[data-variation-action='image']")[Number(index)];
    }

    if (field) {
      field.setAttribute("aria-invalid", "true");
      firstField ||= field;
    }
  });

  firstField?.focus();
}

function setEditorBusy(busy) {
  state.busy = busy;
  elements.editorForm.setAttribute("aria-busy", String(busy));
  elements.saveButton.disabled = busy;
  elements.cancelEditor.disabled = busy;
  elements.closeEditor.disabled = busy;
  updateVariationLimit();
  elements.saveButton.querySelector("span").textContent = busy
    ? "Guardando..."
    : state.editingId ? "Actualizar producto" : "Guardar producto";
  renderPagination();
}

async function saveProduct(event) {
  event.preventDefault();
  clearInvalidFields();
  setMessage(elements.editorMessage);

  if (!elements.editorForm.checkValidity()) {
    elements.editorForm.reportValidity();
    return;
  }

  const editingId = state.editingId;
  const formData = productFormData();
  if (editingId) {
    formData.append("_method", "PUT");
  }

  try {
    setEditorBusy(true);
    const response = await apiRequest(
      editingId ? `/productos-despacho/${editingId}` : "/productos-despacho",
      { method: "POST", body: formData }
    );
    closeEditor();
    setMessage(elements.message, response.message || "Producto guardado correctamente.", "success");
    await loadProducts(state.currentPage, true);
  } catch (error) {
    showValidationErrors(error);
    setMessage(elements.editorMessage, errorMessage(error), "error");
  } finally {
    setEditorBusy(false);
  }
}

function requestDelete(product) {
  state.deletingId = Number(product.id);
  state.lastFocus = document.activeElement;
  elements.deleteDescription.textContent = `${product.name} dejará de aparecer en los despachos, pero su información se conservará y podrás restaurarlo después.`;
  elements.deleteDialog.showModal();
}

async function confirmDelete() {
  if (!state.deletingId || state.busy) {
    return;
  }

  elements.confirmDelete.disabled = true;
  elements.cancelDelete.disabled = true;
  state.busy = true;

  try {
    const response = await apiRequest(`/productos-despacho/${state.deletingId}`, { method: "DELETE" });
    elements.deleteDialog.close();
    setMessage(elements.message, response.message || "Producto eliminado del catálogo.", "success");
    await loadProducts(state.currentPage, true);
    if (!state.products.length && state.currentPage > 1) {
      await loadProducts(state.currentPage - 1, true);
    }
  } catch (error) {
    elements.deleteDialog.close();
    setMessage(elements.message, errorMessage(error), "error");
  } finally {
    state.busy = false;
    state.deletingId = null;
    elements.confirmDelete.disabled = false;
    elements.cancelDelete.disabled = false;
    renderPagination();
  }
}

elements.newButton.addEventListener("click", () => openEditor());
elements.closeEditor.addEventListener("click", closeEditor);
elements.cancelEditor.addEventListener("click", closeEditor);
elements.editorForm.addEventListener("submit", saveProduct);
elements.addVariation.addEventListener("click", addVariation);
elements.variations.addEventListener("input", handleVariationInput);
elements.variations.addEventListener("change", handleVariationChange);
elements.variations.addEventListener("click", handleVariationClick);

elements.imageInput.addEventListener("change", () => {
  const file = elements.imageInput.files?.[0] || null;
  const validationError = imageFileError(file);
  if (!file) {
    return;
  }
  if (validationError) {
    elements.imageInput.value = "";
    setMessage(elements.editorMessage, validationError, "error");
    return;
  }

  releaseImageState(state.image);
  state.image.file = file;
  state.image.remove = false;
  state.image.previewUrl = URL.createObjectURL(file);
  setMessage(elements.editorMessage);
  renderProductImage();
});

elements.removeImage.addEventListener("click", () => {
  releaseImageState(state.image);
  state.image.file = null;
  state.image.remove = true;
  elements.imageInput.value = "";
  renderProductImage();
});

elements.grid.addEventListener("click", (event) => {
  const button = event.target.closest("[data-product-action]");
  if (!button || state.busy) {
    return;
  }

  const product = state.products.find((item) => Number(item.id) === Number(button.dataset.productId));
  if (!product) {
    return;
  }

  if (button.dataset.productAction === "edit") {
    openEditor(product);
  } else if (button.dataset.productAction === "delete") {
    requestDelete(product);
  }
});

elements.search.addEventListener("input", () => {
  window.clearTimeout(state.searchTimer);
  state.searchTimer = window.setTimeout(() => loadProducts(1), 280);
});

elements.status.addEventListener("change", () => loadProducts(1));
elements.previousPage.addEventListener("click", () => loadProducts(state.currentPage - 1));
elements.nextPage.addEventListener("click", () => loadProducts(state.currentPage + 1));
elements.cancelDelete.addEventListener("click", () => elements.deleteDialog.close());
elements.confirmDelete.addEventListener("click", confirmDelete);

[elements.editor, elements.deleteDialog].forEach((dialog) => {
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog && !state.busy) {
      dialog.close();
    }
  });
  dialog.addEventListener("close", () => {
    if (dialog === elements.editor) {
      releaseImageState(state.image);
      state.variations.forEach((variation) => releaseImageState(variation.image));
    }
    state.lastFocus?.focus?.();
  });
});

[elements.name, elements.description, elements.priceMode, elements.price, elements.waste]
  .forEach((field) => field.addEventListener("input", () => field.removeAttribute("aria-invalid")));

window.addEventListener("auth:expired", () => {
  setMessage(elements.message, "La sesión expiró. Inicia sesión nuevamente.", "error");
});

loadProducts();
