import { apiRequest } from "./api-client.js";
import { RetailScaleController } from "./despacho-minorista-balanza.js";
import {
  PRODUCT_DISPATCH_SCALE_CODE,
  buildDraftCollection,
  buildTicketPayload,
  calculateDraft,
  calculateLine,
  createEmptyDraft,
  createUuid,
  currencyLabel,
  effectiveProduct,
  escapeHtml,
  formatMoney,
  formatWeight,
  itemKey,
  normalizeCatalog,
  priceModeLabel,
  productInitial,
  roundTo,
  searchClients
} from "./despacho-productos-despacho-utils.js";
import { printProductDispatchTicket } from "./despacho-productos-ticket-printer.js";

const station = document.querySelector("#productDispatchStation");
const apiBase = station?.dataset.apiBase || "/despacho-productos";
const currentUserId = station?.dataset.userId || "anonymous";
const APP_SCALE_LEVELS = [67, 75, 80, 90, 100, 110, 125, 150];
const viewStorageKey = `sistema-pollos-product-dispatch-view-v1-user-${currentUserId}`;

const elements = {
  zoomSurface: document.querySelector("#pddZoomSurface"),
  branchName: document.querySelector("#pddBranchName"),
  clock: document.querySelector("#pddClock"),
  scaleStatus: document.querySelector("#pddScaleStatus"),
  openScaleSettings: document.querySelector("#pddOpenScaleSettings"),
  openViewSettings: document.querySelector("#pddOpenViewSettings"),
  selectedName: document.querySelector("#pddSelectedName"),
  chooseProduct: document.querySelector("#pddChooseProduct"),
  productMedia: document.querySelector("#pddProductMedia"),
  weightSource: document.querySelector("#pddWeightSource"),
  readingState: document.querySelector("#pddReadingState"),
  liveWeight: document.querySelector("#pddLiveWeight"),
  manualWeight: document.querySelector("#pddManualWeight"),
  captureWeight: document.querySelector("#pddCaptureWeight"),
  selectedVariantLabel: document.querySelector("#pddSelectedVariantLabel"),
  changePrice: document.querySelector("#pddChangePrice"),
  quantity: document.querySelector("#pddQuantity"),
  unitPrice: document.querySelector("#pddUnitPrice"),
  priceMode: document.querySelector("#pddPriceMode"),
  wasteTotal: document.querySelector("#pddWasteTotal"),
  wasteHint: document.querySelector("#pddWasteHint"),
  netPreview: document.querySelector("#pddNetPreview"),
  amountPreview: document.querySelector("#pddAmountPreview"),
  variations: document.querySelector("#pddVariations"),
  lists: document.querySelector("#pddLists"),
  activeList: document.querySelector("#pddActiveList"),
  assignClient: document.querySelector("#pddAssignClient"),
  clientActionLabel: document.querySelector("#pddClientActionLabel"),
  clientActionDetail: document.querySelector("#pddClientActionDetail"),
  railChangePrice: document.querySelector("#pddRailChangePrice"),
  ticketTotal: document.querySelector("#pddTicketTotal"),
  ticketSummary: document.querySelector("#pddTicketSummary"),
  save: document.querySelector("#pddSave"),
  savePrint: document.querySelector("#pddSavePrint"),
  message: document.querySelector("#pddMessage"),
  footerWeighings: document.querySelector("#pddFooterWeighings"),
  footerQuantity: document.querySelector("#pddFooterQuantity"),
  footerWaste: document.querySelector("#pddFooterWaste"),
  footerNet: document.querySelector("#pddFooterNet"),
  lastTicket: document.querySelector("#pddLastTicket"),
  lastTicketTitle: document.querySelector("#pddLastTicketTitle"),
  lastTicketDetail: document.querySelector("#pddLastTicketDetail"),
  retryPrint: document.querySelector("#pddRetryPrint"),
  dismissTicket: document.querySelector("#pddDismissTicket"),
  productDialog: document.querySelector("#pddProductDialog"),
  productSearch: document.querySelector("#pddProductSearch"),
  productGrid: document.querySelector("#pddProductGrid"),
  manualDialog: document.querySelector("#pddManualDialog"),
  manualForm: document.querySelector("#pddManualForm"),
  manualInput: document.querySelector("#pddManualInput"),
  clientDialog: document.querySelector("#pddClientDialog"),
  publicSale: document.querySelector("#pddPublicSale"),
  clientSearch: document.querySelector("#pddClientSearch"),
  clientList: document.querySelector("#pddClientList"),
  editDialog: document.querySelector("#pddEditDialog"),
  editForm: document.querySelector("#pddEditForm"),
  editProduct: document.querySelector("#pddEditProduct"),
  editVariation: document.querySelector("#pddEditVariation"),
  editQuantity: document.querySelector("#pddEditQuantity"),
  editWeight: document.querySelector("#pddEditWeight"),
  editWaste: document.querySelector("#pddEditWaste"),
  editPrice: document.querySelector("#pddEditPrice"),
  editSource: document.querySelector("#pddEditSource"),
  editCalculated: document.querySelector("#pddEditCalculated"),
  deleteWeighing: document.querySelector("#pddDeleteWeighing"),
  priceDialog: document.querySelector("#pddPriceDialog"),
  priceForm: document.querySelector("#pddPriceForm"),
  priceRows: document.querySelector("#pddPriceRows"),
  scaleDialog: document.querySelector("#pddScaleDialog"),
  scaleForm: document.querySelector("#pddScaleForm"),
  scaleDialogDot: document.querySelector("#pddScaleDialogDot"),
  scaleDialogStatus: document.querySelector("#pddScaleDialogStatus"),
  scaleDevice: document.querySelector("#pddScaleDevice"),
  connectBle: document.querySelector("#pddConnectBle"),
  connectSerial: document.querySelector("#pddConnectSerial"),
  baudRate: document.querySelector("#pddBaudRate"),
  dataBits: document.querySelector("#pddDataBits"),
  stopBits: document.querySelector("#pddStopBits"),
  parity: document.querySelector("#pddParity"),
  rawReading: document.querySelector("#pddRawReading"),
  scaleMessage: document.querySelector("#pddScaleMessage"),
  disconnectScale: document.querySelector("#pddDisconnectScale"),
  viewDialog: document.querySelector("#pddViewDialog"),
  zoomOut: document.querySelector("#pddZoomOut"),
  zoomValue: document.querySelector("#pddZoomValue"),
  zoomIn: document.querySelector("#pddZoomIn"),
  zoomReset: document.querySelector("#pddZoomReset")
};

const state = {
  catalog: normalizeCatalog(),
  drafts: buildDraftCollection(),
  activeIndex: 0,
  selectedProductId: null,
  selectedVariationId: null,
  wasteWasEdited: false,
  storageKey: null,
  loading: true,
  saving: false,
  editingLocalId: null,
  liveScale: {},
  lastRaw: "",
  pendingPrintTicket: null,
  lastFocus: null,
  appScale: 100,
  scale: null
};

function activeDraft() {
  return state.drafts[state.activeIndex];
}

function selectedProduct() {
  return state.catalog.products.find((product) => product.id === Number(state.selectedProductId)) || null;
}

function selectedVariation(product = selectedProduct()) {
  return product?.variations.find((variation) => variation.id === Number(state.selectedVariationId)) || null;
}

function currentSelection() {
  return effectiveProduct(selectedProduct(), selectedVariation());
}

function clientById(id) {
  return state.catalog.clients.find((client) => client.id === Number(id)) || null;
}

function errorMessage(error) {
  const errors = error?.data?.errors;
  if (errors && typeof errors === "object") {
    const first = Object.values(errors).flat().find(Boolean);
    if (first) return String(first);
  }
  return error?.message || "No se pudo completar la operación.";
}

function setMessage(message, tone = "") {
  elements.message.textContent = message;
  elements.message.classList.toggle("is-error", tone === "error");
  elements.message.classList.toggle("is-success", tone === "success");

  document.querySelectorAll(".pdd-dialog-message").forEach((notice) => notice.remove());
  const openDialog = document.querySelector("dialog.pdd-dialog[open]");
  const dialogContent = openDialog?.querySelector(":scope > form, :scope > section");
  if (tone === "error" && dialogContent) {
    const notice = document.createElement("p");
    notice.className = "pdd-dialog-message";
    notice.setAttribute("role", "alert");
    notice.textContent = message;
    const actions = dialogContent.querySelector(".pdd-dialog-actions");
    dialogContent.insertBefore(notice, actions || null);
  }
}

function openDialog(dialog, focusTarget = null) {
  if (!dialog) return;
  dialog.querySelectorAll(".pdd-dialog-message").forEach((notice) => notice.remove());
  state.lastFocus = document.activeElement;
  if (!dialog.open) dialog.showModal();
  window.setTimeout(() => (focusTarget || dialog.querySelector("input,button,select"))?.focus(), 0);
}

function closeDialog(dialog) {
  dialog?.querySelectorAll(".pdd-dialog-message").forEach((notice) => notice.remove());
  if (dialog?.open) dialog.close();
}

function normalizeAppScale(value) {
  const numeric = Number(value);
  return APP_SCALE_LEVELS.includes(numeric) ? numeric : 100;
}

function storedAppScale() {
  try {
    const saved = JSON.parse(localStorage.getItem(viewStorageKey) || "null");
    return normalizeAppScale(saved?.scale ?? saved);
  } catch {
    return 100;
  }
}

function renderAppScale() {
  elements.zoomValue.textContent = `${state.appScale}%`;
  elements.zoomValue.value = `${state.appScale}%`;
  elements.zoomOut.disabled = state.appScale === APP_SCALE_LEVELS[0];
  elements.zoomIn.disabled = state.appScale === APP_SCALE_LEVELS.at(-1);
  elements.zoomReset.disabled = state.appScale === 100;
}

function applyAppScale(value, persist = true) {
  state.appScale = normalizeAppScale(value);
  elements.zoomSurface.style.zoom = String(state.appScale / 100);
  renderAppScale();

  if (!persist) return;
  try {
    localStorage.setItem(viewStorageKey, JSON.stringify({ version: 1, scale: state.appScale }));
  } catch {
    // El ajuste sigue funcionando durante esta visita aunque el navegador bloquee el almacenamiento.
  }
}

function stepAppScale(direction) {
  const currentIndex = Math.max(0, APP_SCALE_LEVELS.indexOf(state.appScale));
  const nextIndex = Math.max(0, Math.min(APP_SCALE_LEVELS.length - 1, currentIndex + direction));
  applyAppScale(APP_SCALE_LEVELS[nextIndex]);
}

function storageRead() {
  if (!state.storageKey) return null;
  try {
    const parsed = JSON.parse(localStorage.getItem(state.storageKey) || "null");
    return Array.isArray(parsed?.drafts) ? parsed.drafts : null;
  } catch {
    return null;
  }
}

function persistDrafts() {
  if (!state.storageKey) return;
  try {
    activeDraft().updated_at = new Date().toISOString();
    localStorage.setItem(state.storageKey, JSON.stringify({ version: 1, drafts: state.drafts }));
  } catch {
    setMessage("Las listas funcionan, pero este navegador no permitió guardar el borrador local.", "error");
  }
}

function initializeDraftStorage() {
  const branchId = state.catalog.branch?.id || "default";
  const userId = state.catalog.user?.id || currentUserId;
  state.storageKey = `sistema-pollos-product-dispatch-drafts-v1-user-${userId}-branch-${branchId}`;
  state.drafts = buildDraftCollection(storageRead());
}

function effectivePrice(selection = currentSelection(), draft = activeDraft()) {
  if (!selection) return 0;
  const override = draft.price_overrides[itemKey(selection.product_id, selection.variation_id)];
  return Number(override ?? selection.price ?? 0);
}

function mediaMarkup(name, imageUrl, altPrefix = "Imagen de") {
  if (imageUrl) {
    return `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(`${altPrefix} ${name}`)}" data-pdd-image-fallback="${escapeHtml(name)}">`;
  }
  return `<span class="pdd-media-placeholder has-name"><b>${escapeHtml(productInitial(name))}</b><small>${escapeHtml(name)}</small></span>`;
}

function renderSelectedProduct() {
  const product = selectedProduct();
  const variation = selectedVariation(product);
  const selection = effectiveProduct(product, variation);

  if (!selection) {
    elements.selectedName.textContent = "Ningún producto seleccionado";
    elements.selectedVariantLabel.textContent = "Producto base";
    elements.productMedia.innerHTML = '<span class="pdd-media-placeholder"><b>?</b><small>Elige un producto</small></span>';
    elements.unitPrice.textContent = `${currencyLabel(state.catalog.currency)} --`;
    elements.priceMode.textContent = "Selecciona un producto";
    elements.wasteHint.textContent = "Se calcula según la cantidad y puedes modificarla.";
  } else {
    elements.selectedName.textContent = selection.product_name;
    elements.selectedVariantLabel.textContent = selection.variation_name || "Producto base";
    elements.productMedia.innerHTML = mediaMarkup(selection.display_name, selection.image_url);
    elements.unitPrice.textContent = formatMoney(effectivePrice(selection), state.catalog.currency);
    elements.priceMode.textContent = `Precio ${priceModeLabel(selection.price_mode)}`;
    elements.wasteHint.textContent = `${selection.waste_grams_per_unit} g por unidad · puedes modificar el total.`;
  }

  renderVariations();
  renderCapturePreview();
}

function renderVariations() {
  const product = selectedProduct();
  if (!product) {
    elements.variations.innerHTML = '<span class="pdd-variation-empty">Elige un producto para ver sus variaciones.</span>';
    return;
  }

  const options = [{
    id: null,
    name: "Producto base",
    image_url: product.image_url,
    price: product.price,
    price_mode: product.price_mode
  }, ...product.variations];

  elements.variations.innerHTML = options.map((variation) => {
    const active = variation.id === null
      ? state.selectedVariationId === null
      : Number(variation.id) === Number(state.selectedVariationId);
    const visual = variation.image_url
      ? `<img src="${escapeHtml(variation.image_url)}" alt="" data-pdd-image-fallback="${escapeHtml(variation.name)}">`
      : `<i>${escapeHtml(productInitial(variation.name))}</i>`;
    return `<button class="pdd-variation-option${active ? " is-active" : ""}" type="button" role="option" aria-selected="${active}" data-pdd-variation-id="${variation.id ?? "base"}">
      ${visual}<span><b>${escapeHtml(variation.name)}</b><small>${escapeHtml(formatMoney(variation.price, state.catalog.currency))} ${escapeHtml(priceModeLabel(variation.price_mode))}</small></span>
    </button>`;
  }).join("");
}

function syncDefaultWaste() {
  const selection = currentSelection();
  if (!selection || state.wasteWasEdited) return;
  const quantity = Math.max(1, Math.round(Number(elements.quantity.value || 1)));
  elements.wasteTotal.value = String(selection.waste_grams_per_unit * quantity);
}

function renderCapturePreview() {
  const selection = currentSelection();
  const scaleWeight = Number(state.liveScale.currentWeightKg);
  const hasWeight = Number.isFinite(scaleWeight) && scaleWeight > 0;
  const line = calculateLine({
    quantity: elements.quantity.value,
    read_weight_kg: hasWeight ? scaleWeight : 0,
    waste_total_grams: elements.wasteTotal.value,
    unit_price: effectivePrice(selection),
    price_mode: selection?.price_mode
  });

  elements.netPreview.textContent = hasWeight ? formatWeight(line.net_weight_kg) : "--- kg";
  elements.amountPreview.textContent = hasWeight && selection
    ? `Total estimado ${formatMoney(line.amount, state.catalog.currency)}`
    : `Total estimado ${currencyLabel(state.catalog.currency)} --`;
  const captureReady = Boolean(selection && state.liveScale.isCaptureReady && line.net_weight_kg > 0 && !state.saving);
  elements.captureWeight.disabled = !captureReady;
}

function renderScale(scaleState = state.liveScale) {
  state.liveScale = scaleState || {};
  const weight = Number(scaleState.currentWeightKg);
  const hasWeight = Number.isFinite(weight) && weight >= 0;
  elements.liveWeight.innerHTML = `${hasWeight ? weight.toFixed(3) : "---"}<small>kg</small>`;
  elements.weightSource.textContent = scaleState.readingSource === "manual"
    ? "Peso manual"
    : scaleState.connectionMode === "ble"
      ? "Balanza Bluetooth"
      : scaleState.connectionMode === "serial"
        ? "Balanza serial"
        : "Sin lectura";
  elements.readingState.textContent = scaleState.isCaptureReady
    ? "Peso estable"
    : scaleState.currentWeightKg > 0
      ? "Estabilizando…"
      : "Esperando peso";

  const status = scaleState.status || "offline";
  elements.scaleStatus.className = `pdd-status-chip is-${status}`;
  elements.scaleStatus.querySelector("span").textContent = status === "connected"
    ? `${scaleState.deviceName || "Balanza"} conectada`
    : status === "connecting"
      ? "Conectando balanza…"
      : status === "error"
        ? "Error de balanza"
        : "Balanza sin conectar";
  elements.scaleDialogStatus.textContent = scaleState.statusMessage || "Balanza sin conexión.";
  elements.scaleDevice.textContent = scaleState.deviceName || "No hay dispositivo seleccionado";
  elements.scaleDialogDot.classList.toggle("is-connected", status === "connected");

  const capabilities = scaleState.capabilities || state.scale?.getCapabilities?.() || {};
  elements.connectBle.disabled = status === "connecting" || !capabilities.bluetooth;
  elements.connectSerial.disabled = status === "connecting" || !capabilities.serial;
  elements.disconnectScale.disabled = status === "connecting" || (!scaleState.autoConnectMode && status === "offline");
  renderCapturePreview();
}

function renderProductGrid(query = "") {
  const needle = String(query).trim().toLocaleLowerCase("es");
  const products = state.catalog.products.filter((product) => !needle || [
    product.name,
    product.description,
    ...product.variations.map((variation) => variation.name)
  ].some((value) => String(value || "").toLocaleLowerCase("es").includes(needle)));

  if (!products.length) {
    elements.productGrid.innerHTML = '<div class="pdd-empty-dialog"><strong>No encontramos productos</strong><span>Prueba con otro nombre o agrega productos desde la administración del módulo.</span></div>';
    return;
  }

  elements.productGrid.innerHTML = products.map((product) => `
    <button class="pdd-product-option" type="button" data-pdd-product-id="${product.id}">
      ${product.image_url
        ? `<img src="${escapeHtml(product.image_url)}" alt="Imagen de ${escapeHtml(product.name)}" loading="lazy" data-pdd-image-fallback="${escapeHtml(product.name)}">`
        : `<span class="pdd-product-option-placeholder">${escapeHtml(productInitial(product.name))}</span>`}
      <span><b>${escapeHtml(product.name)}</b><small>${product.variations.length} ${product.variations.length === 1 ? "variación" : "variaciones"} · ${escapeHtml(formatMoney(product.price, state.catalog.currency))}</small></span>
    </button>`).join("");
}

function itemDisplayName(item) {
  return item.variation_name ? `${item.product_name} · ${item.variation_name}` : item.product_name;
}

function renderLists() {
  elements.lists.innerHTML = state.drafts.map((draft, index) => {
    const totals = calculateDraft(draft.items);
    const client = clientById(draft.client_id);
    const rows = draft.items.length
      ? draft.items.map((item, itemIndex) => `<button class="pdd-weighing-row" type="button"${state.saving ? " disabled" : ""} data-pdd-edit-item="${escapeHtml(item.local_id)}" data-pdd-list-index="${index}">
          <i>${itemIndex + 1}</i><span><b>${escapeHtml(itemDisplayName(item))}</b><small>${item.quantity} und · ${formatWeight(item.net_weight_kg)} · ${item.waste_total_grams} g merma</small></span><strong>${escapeHtml(formatMoney(item.amount, state.catalog.currency))}</strong>
        </button>`).join("")
      : '<div class="pdd-list-empty"><b>Lista vacía</b><span>Selecciónala y captura el primer peso.</span></div>';

    return `<article class="pdd-list-card${index === state.activeIndex ? " is-active" : ""}" data-pdd-list-card="${index}">
      <button class="pdd-list-card-head" type="button" aria-pressed="${index === state.activeIndex}"${state.saving ? " disabled" : ""} data-pdd-select-list="${index}">
        <span class="pdd-list-number">${index + 1}</span><span><b>${escapeHtml(client?.name || "Venta al público")}</b><small>${client?.document ? `Doc. ${escapeHtml(client.document)}` : "Cliente opcional"}</small></span><span class="pdd-list-count">${totals.weighings}</span>
      </button>
      <div class="pdd-list-items">${rows}</div>
      <div class="pdd-list-totals"><span>Neto</span><strong>${formatWeight(totals.net_weight_kg)}</strong><span>Total</span><strong class="pdd-list-total-amount">${escapeHtml(formatMoney(totals.amount, state.catalog.currency))}</strong></div>
    </article>`;
  }).join("");
}

function renderActiveSummary() {
  const draft = activeDraft();
  const totals = calculateDraft(draft.items);
  const client = clientById(draft.client_id);
  elements.activeList.textContent = String(state.activeIndex + 1);
  elements.clientActionLabel.textContent = client ? client.name : "Asignar cliente";
  elements.clientActionDetail.textContent = client?.document || "Venta al público";
  elements.ticketTotal.textContent = formatMoney(totals.amount, state.catalog.currency);
  elements.ticketSummary.textContent = `${totals.weighings} ${totals.weighings === 1 ? "pesada" : "pesadas"} · ${formatWeight(totals.net_weight_kg)} netos`;
  elements.footerWeighings.textContent = String(totals.weighings);
  elements.footerQuantity.textContent = String(totals.quantity);
  elements.footerWaste.textContent = `${totals.waste_total_grams} g`;
  elements.footerNet.textContent = formatWeight(totals.net_weight_kg);
  elements.assignClient.disabled = state.saving;
  elements.railChangePrice.disabled = state.saving;
  elements.changePrice.disabled = state.saving;
  elements.manualWeight.disabled = state.saving;
  elements.save.disabled = state.saving || !draft.items.length;
  elements.savePrint.disabled = state.saving || !draft.items.length;
}

function renderAll() {
  renderSelectedProduct();
  renderLists();
  renderActiveSummary();
  renderScale();
}

function selectList(index, scroll = false) {
  if (state.saving) return;
  const next = Number(index);
  if (!Number.isInteger(next) || next < 0 || next >= state.drafts.length) return;
  state.activeIndex = next;
  renderLists();
  renderActiveSummary();
  renderSelectedProduct();
  if (scroll) {
    elements.lists.querySelector(`[data-pdd-list-card="${next}"]`)?.scrollIntoView({ behavior: "smooth", inline: "center", block: "nearest" });
  }
}

function selectProduct(productId) {
  const product = state.catalog.products.find((entry) => entry.id === Number(productId));
  if (!product) return;
  state.selectedProductId = product.id;
  state.selectedVariationId = null;
  state.wasteWasEdited = false;
  syncDefaultWaste();
  renderSelectedProduct();
  closeDialog(elements.productDialog);
  setMessage(`${product.name} seleccionado. Captura un peso o ingrésalo manualmente.`);
}

function selectVariation(variationId) {
  const product = selectedProduct();
  if (!product) return;
  const normalized = variationId === "base" ? null : Number(variationId);
  if (normalized !== null && !product.variations.some((variation) => variation.id === normalized)) return;
  state.selectedVariationId = normalized;
  state.wasteWasEdited = false;
  syncDefaultWaste();
  renderSelectedProduct();
}

function capturedReadingIds() {
  return new Set(state.drafts.flatMap((draft) => draft.items)
    .map((item) => item.physical_reading_id)
    .filter(Boolean));
}

function addCurrentReading(scaleState = state.scale.getState()) {
  if (state.saving) {
    setMessage("Espera a que termine el guardado antes de agregar otra pesada.", "error");
    return false;
  }
  const selection = currentSelection();
  if (!selection) {
    setMessage("Primero elige el producto que vas a despachar.", "error");
    openProductDialog();
    return false;
  }

  const weight = Number(scaleState.currentWeightKg);
  if (!scaleState.isCaptureReady || !Number.isFinite(weight) || weight <= 0) {
    setMessage("Espera un peso estable de la balanza o usa el botón Peso manual.", "error");
    return false;
  }
  if (activeDraft().items.length >= 100) {
    setMessage("Esta lista ya alcanzó el máximo de 100 pesadas.", "error");
    return false;
  }

  const isPhysical = ["ble", "serial"].includes(scaleState.readingSource);
  if (isPhysical && scaleState.readingId && capturedReadingIds().has(scaleState.readingId)) {
    setMessage("Esta lectura de la balanza ya fue capturada. Retira el producto y espera una lectura nueva.", "error");
    return false;
  }

  const calculated = calculateLine({
    quantity: elements.quantity.value,
    read_weight_kg: weight,
    waste_total_grams: elements.wasteTotal.value,
    unit_price: effectivePrice(selection),
    price_mode: selection.price_mode
  });
  if (calculated.net_weight_kg <= 0) {
    setMessage("La merma no puede ser igual o mayor que el peso leído.", "error");
    return false;
  }
  if (calculated.amount < 0.01) {
    setMessage("El precio y la cantidad o peso neto deben producir un total mínimo de 0.01.", "error");
    return false;
  }

  const item = {
    local_id: createUuid(),
    physical_reading_id: isPhysical ? scaleState.readingId : null,
    product_id: selection.product_id,
    variation_id: selection.variation_id,
    product_name: selection.product_name,
    variation_name: selection.variation_name,
    image_url: selection.image_url,
    catalog_price: selection.price,
    catalog_waste_grams_per_unit: selection.waste_grams_per_unit,
    ...calculated,
    weight_source: isPhysical ? PRODUCT_DISPATCH_SCALE_CODE : "MANUAL",
    weighed_at: scaleState.readingAt || new Date().toISOString(),
    scale_reading: isPhysical ? {
      raw_frame: String(scaleState.readingRaw || state.lastRaw || "").slice(0, 500) || null,
      connection_mode: scaleState.connectionMode,
      device_name: scaleState.deviceName || null,
      captured_at: scaleState.readingAt || new Date().toISOString()
    } : null
  };

  activeDraft().items.push(item);
  persistDrafts();
  renderLists();
  renderActiveSummary();
  setMessage(`${itemDisplayName(item)} agregado a la lista ${state.activeIndex + 1}.`, "success");
  state.scale.clearReading();
  return true;
}

function openProductDialog() {
  elements.productSearch.value = "";
  renderProductGrid();
  openDialog(elements.productDialog, elements.productSearch);
}

function renderClientList(query = "") {
  const clients = searchClients(state.catalog.clients, query).slice(0, 100);
  if (!clients.length) {
    elements.clientList.innerHTML = '<div class="pdd-empty-dialog"><strong>No encontramos clientes</strong><span>Puedes guardar como Venta al público o probar otra búsqueda.</span></div>';
    return;
  }
  elements.clientList.innerHTML = clients.map((client) => `
    <button class="pdd-client-option" type="button" data-pdd-client-id="${client.id}">
      <i>${escapeHtml(productInitial(client.name))}</i><span><b>${escapeHtml(client.name)}</b><small>${escapeHtml([client.document && `Doc. ${client.document}`, client.phone].filter(Boolean).join(" · ") || "Sin documento")}</small></span><em>Elegir</em>
    </button>`).join("");
}

function openClientDialog() {
  elements.clientSearch.value = "";
  renderClientList();
  openDialog(elements.clientDialog, elements.clientSearch);
}

function assignClient(clientId = null) {
  if (state.saving) return;
  activeDraft().client_id = clientId ? Number(clientId) : null;
  persistDrafts();
  renderLists();
  renderActiveSummary();
  closeDialog(elements.clientDialog);
  const client = clientById(clientId);
  setMessage(client ? `Ticket asignado a ${client.name}.` : "El ticket se guardará como Venta al público.", "success");
}

function productForItem(item) {
  return state.catalog.products.find((product) => product.id === Number(item.product_id)) || null;
}

function variationForItem(item, product = productForItem(item)) {
  return product?.variations.find((variation) => variation.id === Number(item.variation_id)) || null;
}

function fillEditProductOptions(selectedId) {
  elements.editProduct.innerHTML = state.catalog.products.map((product) => `<option value="${product.id}"${product.id === Number(selectedId) ? " selected" : ""}>${escapeHtml(product.name)}</option>`).join("");
  fillEditVariationOptions();
}

function fillEditVariationOptions(selectedId = null) {
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  elements.editVariation.innerHTML = `<option value="">Producto base</option>${(product?.variations || []).map((variation) => `<option value="${variation.id}"${variation.id === Number(selectedId) ? " selected" : ""}>${escapeHtml(variation.name)}</option>`).join("")}`;
}

function editingItem() {
  return activeDraft().items.find((item) => item.local_id === state.editingLocalId) || null;
}

function renderEditCalculation() {
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  const variation = product?.variations.find((entry) => entry.id === Number(elements.editVariation.value)) || null;
  const selection = effectiveProduct(product, variation);
  const line = calculateLine({
    quantity: elements.editQuantity.value,
    read_weight_kg: elements.editWeight.value,
    waste_total_grams: elements.editWaste.value,
    unit_price: elements.editPrice.value,
    price_mode: selection?.price_mode
  });
  elements.editCalculated.textContent = `Neto ${formatWeight(line.net_weight_kg)} · ${formatMoney(line.amount, state.catalog.currency)}`;
}

function openEditDialog(localId, listIndex) {
  selectList(listIndex);
  const item = activeDraft().items.find((entry) => entry.local_id === localId);
  if (!item) return;
  state.editingLocalId = item.local_id;
  fillEditProductOptions(item.product_id);
  fillEditVariationOptions(item.variation_id);
  elements.editQuantity.value = String(item.quantity);
  elements.editWeight.value = Number(item.read_weight_kg).toFixed(3);
  elements.editWaste.value = String(item.waste_total_grams);
  elements.editPrice.value = Number(item.unit_price).toFixed(4);
  elements.editSource.textContent = item.weight_source === PRODUCT_DISPATCH_SCALE_CODE
    ? `Origen: balanza ${item.scale_reading?.device_name || "conectada"}`
    : "Origen: peso manual";
  renderEditCalculation();
  openDialog(elements.editDialog, elements.editQuantity);
}

function changeEditingProduct(useCatalogDefaults = true) {
  const item = editingItem();
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  const variation = product?.variations.find((entry) => entry.id === Number(elements.editVariation.value)) || null;
  const selection = effectiveProduct(product, variation);
  if (selection && useCatalogDefaults) {
    const override = activeDraft().price_overrides[itemKey(selection.product_id, selection.variation_id)];
    elements.editPrice.value = Number(override ?? selection.price).toFixed(4);
    elements.editWaste.value = String(selection.waste_grams_per_unit * Math.max(1, Math.round(Number(elements.editQuantity.value || 1))));
  } else if (!selection && item) {
    elements.editPrice.value = Number(item.unit_price).toFixed(4);
  }
  renderEditCalculation();
}

function saveEditingItem(event) {
  event.preventDefault();
  if (state.saving) return;
  const item = editingItem();
  if (!item || !elements.editForm.reportValidity()) return;
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  const variation = product?.variations.find((entry) => entry.id === Number(elements.editVariation.value)) || null;
  const selection = effectiveProduct(product, variation);
  if (!selection) return;
  const calculated = calculateLine({
    quantity: elements.editQuantity.value,
    read_weight_kg: elements.editWeight.value,
    waste_total_grams: elements.editWaste.value,
    unit_price: elements.editPrice.value,
    price_mode: selection.price_mode
  });
  if (calculated.net_weight_kg <= 0) {
    setMessage("La merma editada no puede ser igual o mayor que el peso leído.", "error");
    return;
  }
  if (calculated.amount < 0.01) {
    setMessage("La pesada editada debe producir un total mínimo de 0.01.", "error");
    return;
  }

  const weightChanged = roundTo(item.read_weight_kg, 3) !== calculated.read_weight_kg;
  Object.assign(item, {
    product_id: selection.product_id,
    variation_id: selection.variation_id,
    product_name: selection.product_name,
    variation_name: selection.variation_name,
    image_url: selection.image_url,
    catalog_price: selection.price,
    catalog_waste_grams_per_unit: selection.waste_grams_per_unit,
    ...calculated,
    weight_source: weightChanged ? "MANUAL" : item.weight_source,
    scale_reading: weightChanged ? null : item.scale_reading,
    physical_reading_id: weightChanged ? null : item.physical_reading_id
  });
  persistDrafts();
  renderLists();
  renderActiveSummary();
  closeDialog(elements.editDialog);
  setMessage("Pesada actualizada correctamente.", "success");
}

function deleteEditingItem() {
  if (state.saving) return;
  const item = editingItem();
  if (!item || !window.confirm(`¿Quitar la pesada de ${itemDisplayName(item)} de esta lista?`)) return;
  activeDraft().items = activeDraft().items.filter((entry) => entry.local_id !== item.local_id);
  state.editingLocalId = null;
  persistDrafts();
  renderLists();
  renderActiveSummary();
  closeDialog(elements.editDialog);
  setMessage("La pesada se quitó del borrador.", "success");
}

function priceTargets() {
  const targets = new Map();
  activeDraft().items.forEach((item) => {
    const key = itemKey(item.product_id, item.variation_id);
    if (!targets.has(key)) targets.set(key, {
      key,
      product_id: item.product_id,
      variation_id: item.variation_id,
      name: itemDisplayName(item),
      price_mode: item.price_mode,
      catalog_price: item.catalog_price,
      price: activeDraft().price_overrides[key] ?? item.unit_price
    });
  });
  const selection = currentSelection();
  if (selection) {
    const key = itemKey(selection.product_id, selection.variation_id);
    if (!targets.has(key)) targets.set(key, {
      key,
      product_id: selection.product_id,
      variation_id: selection.variation_id,
      name: selection.display_name,
      price_mode: selection.price_mode,
      catalog_price: selection.price,
      price: effectivePrice(selection)
    });
  }
  return [...targets.values()];
}

function openPriceDialog() {
  const targets = priceTargets();
  elements.priceRows.innerHTML = targets.length ? targets.map((target) => `
    <div class="pdd-price-row">
      <span><b>${escapeHtml(target.name)}</b><small>Catálogo: ${escapeHtml(formatMoney(target.catalog_price, state.catalog.currency))} ${escapeHtml(priceModeLabel(target.price_mode))}</small></span>
      <label><span>${escapeHtml(currencyLabel(state.catalog.currency))}</span><input type="number" min="0.0001" max="9999999999.9999" step="0.0001" value="${Number(target.price).toFixed(4)}" data-pdd-price-key="${escapeHtml(target.key)}" required></label>
    </div>`).join("") : '<div class="pdd-empty-dialog"><strong>No hay productos para cambiar</strong><span>Selecciona un producto o agrega una pesada primero.</span></div>';
  openDialog(elements.priceDialog, elements.priceRows.querySelector("input"));
}

function savePrices(event) {
  event.preventDefault();
  if (state.saving) return;
  const inputs = [...elements.priceRows.querySelectorAll("[data-pdd-price-key]")];
  if (!inputs.length) {
    closeDialog(elements.priceDialog);
    return;
  }
  if (!elements.priceForm.reportValidity()) return;
  const proposedPrices = new Map(inputs.map((input) => [
    input.dataset.pddPriceKey,
    roundTo(input.value, 4)
  ]));
  const invalidItem = activeDraft().items.find((item) => {
    const price = proposedPrices.get(itemKey(item.product_id, item.variation_id));
    return price !== undefined && calculateLine({ ...item, unit_price: price }).amount < 0.01;
  });
  if (invalidItem) {
    const key = itemKey(invalidItem.product_id, invalidItem.variation_id);
    elements.priceRows.querySelector(`[data-pdd-price-key="${CSS.escape(key)}"]`)?.focus();
    setMessage(`El precio de ${itemDisplayName(invalidItem)} debe producir un total mínimo de 0.01.`, "error");
    return;
  }
  inputs.forEach((input) => {
    const price = proposedPrices.get(input.dataset.pddPriceKey);
    activeDraft().price_overrides[input.dataset.pddPriceKey] = price;
    activeDraft().items.forEach((item) => {
      if (itemKey(item.product_id, item.variation_id) !== input.dataset.pddPriceKey) return;
      Object.assign(item, calculateLine({ ...item, unit_price: price }));
    });
  });
  persistDrafts();
  renderAll();
  closeDialog(elements.priceDialog);
  setMessage("Los precios del ticket activo fueron actualizados.", "success");
}

function showTicketToast(ticket, printed, printError = null) {
  state.pendingPrintTicket = printError ? ticket : null;
  elements.lastTicket.hidden = false;
  elements.lastTicketTitle.textContent = printError
    ? "Ticket guardado; impresión pendiente"
    : printed
      ? "Ticket guardado y enviado a impresión"
      : "Ticket guardado sin imprimir";
  elements.lastTicketDetail.textContent = `${ticket?.code || ticket?.codigo || "Ticket confirmado"}${printError ? ` · ${printError}` : ""}`;
  elements.retryPrint.hidden = !printError;
}

function closeReservedPrintWindow(printWindow) {
  try { printWindow?.close(); } catch { /* La ventana pudo cerrarse manualmente. */ }
}

async function saveActiveDraft(shouldPrint = false) {
  const draft = activeDraft();
  if (!draft.items.length || state.saving) return;
  const finishedIndex = state.activeIndex;
  const finishedDraftId = draft.id;
  const payload = buildTicketPayload(draft);
  let printWindow = null;
  if (shouldPrint) {
    printWindow = window.open("", "_blank", "popup=yes,width=420,height=720");
    if (printWindow) {
      printWindow.document.write('<!doctype html><html><body style="font-family:sans-serif;padding:28px;text-align:center">Guardando ticket…</body></html>');
      printWindow.document.close();
    }
  }

  state.saving = true;
  renderActiveSummary();
  setMessage("Guardando el ticket y sus pesadas…");
  try {
    const response = await apiRequest(`${apiBase}/tickets`, {
      method: "POST",
      body: JSON.stringify(payload)
    });
    const ticket = response?.data?.ticket || response?.data || response?.ticket || response;
    if (state.drafts[finishedIndex]?.id === finishedDraftId) {
      state.drafts[finishedIndex] = createEmptyDraft(finishedIndex + 1);
    }
    persistDrafts();
    renderLists();
    renderActiveSummary();
    setMessage(`Ticket ${ticket?.code || ticket?.codigo || "confirmado"} guardado correctamente.`, "success");

    if (shouldPrint) {
      try {
        printProductDispatchTicket(ticket, {
          currency: state.catalog.currency,
          ticketTitle: state.catalog.ticket_title,
          ticketMessage: state.catalog.ticket_message,
          printWindow
        });
        showTicketToast(ticket, true);
      } catch (error) {
        closeReservedPrintWindow(printWindow);
        showTicketToast(ticket, false, errorMessage(error));
        setMessage("El ticket se guardó. La impresión puede reintentarse sin volver a guardar.", "error");
      }
    } else {
      showTicketToast(ticket, false);
    }
  } catch (error) {
    closeReservedPrintWindow(printWindow);
    setMessage(errorMessage(error), "error");
  } finally {
    state.saving = false;
    renderLists();
    renderActiveSummary();
  }
}

function retryPrint() {
  if (!state.pendingPrintTicket) return;
  try {
    printProductDispatchTicket(state.pendingPrintTicket, {
      currency: state.catalog.currency,
      ticketTitle: state.catalog.ticket_title,
      ticketMessage: state.catalog.ticket_message
    });
    const ticket = state.pendingPrintTicket;
    state.pendingPrintTicket = null;
    showTicketToast(ticket, true);
    setMessage("La impresión se abrió correctamente.", "success");
  } catch (error) {
    showTicketToast(state.pendingPrintTicket, false, errorMessage(error));
  }
}

function serialOptions() {
  return {
    baudRate: Number(elements.baudRate.value),
    dataBits: Number(elements.dataBits.value),
    stopBits: Number(elements.stopBits.value),
    parity: elements.parity.value,
    flowControl: "none"
  };
}

function fillSerialOptions() {
  const options = state.scale.getState().serialOptions || {};
  elements.baudRate.value = String(options.baudRate || 9600);
  elements.dataBits.value = String(options.dataBits || 8);
  elements.stopBits.value = String(options.stopBits || 1);
  elements.parity.value = options.parity || "none";
}

async function connectBle() {
  elements.scaleMessage.classList.remove("is-error");
  elements.scaleMessage.textContent = "Selecciona la balanza Bluetooth en la ventana del navegador…";
  try {
    const connected = await state.scale.connectBle();
    elements.scaleMessage.textContent = connected ? "Balanza Bluetooth conectada." : state.scale.getState().statusMessage;
  } catch (error) {
    elements.scaleMessage.classList.add("is-error");
    elements.scaleMessage.textContent = errorMessage(error);
  }
}

async function connectSerial() {
  elements.scaleMessage.classList.remove("is-error");
  elements.scaleMessage.textContent = "Selecciona el puerto de la balanza…";
  try {
    state.scale.configureSerial(serialOptions());
    const connected = await state.scale.connectSerial({ serialOptions: serialOptions() });
    elements.scaleMessage.textContent = connected ? "Balanza serial conectada." : state.scale.getState().statusMessage;
  } catch (error) {
    elements.scaleMessage.classList.add("is-error");
    elements.scaleMessage.textContent = errorMessage(error);
  }
}

async function disconnectScale() {
  try {
    await state.scale.disconnect({ forget: true });
    elements.scaleMessage.textContent = "La balanza se desconectó y se olvidó en este puesto.";
  } catch (error) {
    elements.scaleMessage.classList.add("is-error");
    elements.scaleMessage.textContent = errorMessage(error);
  }
}

async function restoreScale() {
  try {
    await state.scale.restoreAuthorizedConnection();
  } catch {
    // La interfaz conserva el modo manual aunque el dispositivo recordado ya no esté disponible.
  }
}

async function loadCatalog() {
  state.loading = true;
  setMessage("Cargando productos, clientes y configuración de la sucursal…");
  try {
    const response = await apiRequest(`${apiBase}/catalogo`);
    state.catalog = normalizeCatalog(response);
    initializeDraftStorage();
    elements.branchName.textContent = state.catalog.branch?.name || state.catalog.branch?.nombre || "Sucursal actual";
    const branchId = state.catalog.branch?.id || "default";
    state.scale.setStorageKey(`sistema-pollos-product-dispatch-scale-v1-branch-${branchId}`, {
      reload: true,
      persistCurrent: false
    });
    const scaleState = state.scale.getState();
    if (!scaleState.autoConnectMode && state.catalog.scale?.configuration) {
      state.scale.configureSerial(state.catalog.scale.configuration);
    }
    fillSerialOptions();
    state.loading = false;
    renderAll();
    setMessage("Estación lista. Elige un producto y captura el peso; cada lista se conserva automáticamente.", "success");
    void restoreScale();
  } catch (error) {
    state.loading = false;
    renderAll();
    setMessage(errorMessage(error), "error");
  }
}

state.scale = new RetailScaleController({
  storageKey: "sistema-pollos-product-dispatch-scale-v1-pending",
  onReading(payload) {
    renderScale(payload?.state || payload || state.scale.getState());
  },
  onStatus(payload) {
    renderScale(payload?.state || payload || state.scale.getState());
  },
  onRaw(payload) {
    state.lastRaw = String(payload?.raw || "");
    elements.rawReading.textContent = `Trama: ${state.lastRaw || "--"}`;
  }
});

elements.chooseProduct.addEventListener("click", openProductDialog);
elements.productMedia.addEventListener("click", openProductDialog);
elements.productSearch.addEventListener("input", () => renderProductGrid(elements.productSearch.value));
elements.productGrid.addEventListener("click", (event) => {
  const option = event.target.closest("[data-pdd-product-id]");
  if (option) selectProduct(option.dataset.pddProductId);
});
elements.variations.addEventListener("click", (event) => {
  const option = event.target.closest("[data-pdd-variation-id]");
  if (option) selectVariation(option.dataset.pddVariationId);
});
document.addEventListener("error", (event) => {
  const image = event.target;
  if (!(image instanceof HTMLImageElement) || !image.dataset.pddImageFallback) return;
  const name = image.dataset.pddImageFallback;
  if (image.closest(".pdd-product-option")) {
    const fallback = document.createElement("span");
    fallback.className = "pdd-product-option-placeholder";
    fallback.textContent = productInitial(name);
    image.replaceWith(fallback);
    return;
  }
  if (image.closest(".pdd-variation-option")) {
    const fallback = document.createElement("i");
    fallback.textContent = productInitial(name);
    image.replaceWith(fallback);
    return;
  }
  const fallback = document.createElement("span");
  fallback.className = "pdd-media-placeholder has-name";
  const initial = document.createElement("b");
  initial.textContent = productInitial(name);
  const label = document.createElement("small");
  label.textContent = name;
  fallback.append(initial, label);
  image.replaceWith(fallback);
}, true);
document.addEventListener("click", (event) => {
  const step = event.target.closest("[data-pdd-quantity-step]");
  if (step) {
    elements.quantity.value = String(Math.max(1, Math.min(100000, Math.round(Number(elements.quantity.value || 1)) + Number(step.dataset.pddQuantityStep))));
    syncDefaultWaste();
    renderCapturePreview();
  }
  const close = event.target.closest("[data-pdd-close]");
  if (close) closeDialog(document.querySelector(`#${CSS.escape(close.dataset.pddClose)}`));
});
elements.quantity.addEventListener("input", () => {
  syncDefaultWaste();
  renderCapturePreview();
});
elements.quantity.addEventListener("change", () => {
  elements.quantity.value = String(Math.max(1, Math.min(100000, Math.round(Number(elements.quantity.value || 1)))));
  syncDefaultWaste();
  renderCapturePreview();
});
elements.wasteTotal.addEventListener("input", () => {
  state.wasteWasEdited = true;
  renderCapturePreview();
});
elements.captureWeight.addEventListener("click", () => addCurrentReading());
elements.manualWeight.addEventListener("click", () => {
  if (!currentSelection()) {
    setMessage("Primero elige un producto antes de ingresar el peso.", "error");
    openProductDialog();
    return;
  }
  elements.manualForm.reset();
  openDialog(elements.manualDialog, elements.manualInput);
});
elements.manualForm.addEventListener("submit", (event) => {
  event.preventDefault();
  if (!elements.manualForm.reportValidity()) return;
  try {
    const scaleState = state.scale.setManualReading(elements.manualInput.value);
    if (addCurrentReading(scaleState)) closeDialog(elements.manualDialog);
  } catch (error) {
    setMessage(errorMessage(error), "error");
  }
});
elements.lists.addEventListener("click", (event) => {
  const edit = event.target.closest("[data-pdd-edit-item]");
  if (edit) {
    openEditDialog(edit.dataset.pddEditItem, edit.dataset.pddListIndex);
    return;
  }
  const list = event.target.closest("[data-pdd-select-list]");
  if (list) selectList(list.dataset.pddSelectList);
});
elements.assignClient.addEventListener("click", openClientDialog);
elements.clientSearch.addEventListener("input", () => renderClientList(elements.clientSearch.value));
elements.clientList.addEventListener("click", (event) => {
  const client = event.target.closest("[data-pdd-client-id]");
  if (client) assignClient(client.dataset.pddClientId);
});
elements.publicSale.addEventListener("click", () => assignClient(null));
elements.editProduct.addEventListener("change", () => {
  fillEditVariationOptions();
  changeEditingProduct(true);
});
elements.editVariation.addEventListener("change", () => changeEditingProduct(true));
[elements.editQuantity, elements.editWeight, elements.editWaste, elements.editPrice].forEach((input) => input.addEventListener("input", renderEditCalculation));
elements.editForm.addEventListener("submit", saveEditingItem);
elements.deleteWeighing.addEventListener("click", deleteEditingItem);
elements.changePrice.addEventListener("click", openPriceDialog);
elements.railChangePrice.addEventListener("click", openPriceDialog);
elements.priceForm.addEventListener("submit", savePrices);
elements.save.addEventListener("click", () => void saveActiveDraft(false));
elements.savePrint.addEventListener("click", () => void saveActiveDraft(true));
elements.retryPrint.addEventListener("click", retryPrint);
elements.dismissTicket.addEventListener("click", () => { elements.lastTicket.hidden = true; });
elements.openScaleSettings.addEventListener("click", () => {
  fillSerialOptions();
  elements.scaleMessage.textContent = "La primera conexión necesita que elijas el dispositivo; luego intentaremos restaurarla automáticamente.";
  elements.scaleMessage.classList.remove("is-error");
  openDialog(elements.scaleDialog);
});
elements.openViewSettings.addEventListener("click", () => {
  renderAppScale();
  openDialog(elements.viewDialog, state.appScale === APP_SCALE_LEVELS.at(-1) ? elements.zoomOut : elements.zoomIn);
});
elements.zoomOut.addEventListener("click", () => stepAppScale(-1));
elements.zoomIn.addEventListener("click", () => stepAppScale(1));
elements.zoomReset.addEventListener("click", () => applyAppScale(100));
elements.connectBle.addEventListener("click", () => void connectBle());
elements.connectSerial.addEventListener("click", () => void connectSerial());
elements.disconnectScale.addEventListener("click", () => void disconnectScale());

document.querySelectorAll(".pdd-dialog").forEach((dialog) => {
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) closeDialog(dialog);
  });
  dialog.addEventListener("close", () => state.lastFocus?.focus?.());
});

window.addEventListener("auth:expired", () => setMessage("Tu sesión venció. Inicia sesión nuevamente; los ocho borradores permanecen en este navegador.", "error"));
window.addEventListener("storage", (event) => {
  if (event.key === viewStorageKey) applyAppScale(storedAppScale(), false);
});
window.addEventListener("pagehide", () => { void state.scale.destroy(); });
document.addEventListener("visibilitychange", () => {
  if (!document.hidden && !state.loading && state.scale.getState().autoConnectMode) void restoreScale();
});

function updateClock() {
  elements.clock.textContent = new Intl.DateTimeFormat("es-CO", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false
  }).format(new Date());
}

updateClock();
window.setInterval(updateClock, 1000);
applyAppScale(storedAppScale(), false);
renderAll();
void loadCatalog();
