import { apiRequest } from "./api-client.js";
import {
  RetailScaleController,
  RETAIL_SCALE_SERIAL_DEFAULTS
} from "./despacho-minorista-balanza.js";

const STORAGE_PREFIX = "sistema-pollos-retail-dispatch-v2-branch";
const OPERATION_SALE = "DESPACHO";
const OPERATION_RETURN = "DEVOLUCION";
const SEX_MALE = "MACHO";
const SEX_FEMALE = "HEMBRA";
const LIST_COUNT = 4;
const LIST_CLASSES = ["is-list-1", "is-list-2", "is-list-3", "is-list-4"];

const elements = {
  station: document.querySelector("#retailStation"),
  form: document.querySelector("#retailWeighingForm"),
  branchName: document.querySelector("#retailBranchName"),
  clock: document.querySelector("#retailClock"),
  scaleTopStatus: document.querySelector("#retailScaleTopStatus"),
  openSettings: document.querySelector("#retailOpenSettings"),
  trayType: document.querySelector("#retailTrayType"),
  trayCount: document.querySelector("#retailTrayCount"),
  trayCountLabel: document.querySelector("#retailTrayCountLabel"),
  birdsPerTray: document.querySelector("#retailBirdsPerTray"),
  rawWeightInput: document.querySelector("#retailRawWeightInput"),
  adjustedWeight: document.querySelector("#retailAdjustedWeight"),
  adjustmentPreview: document.querySelector("#retailAdjustmentPreview"),
  weightSourceLabel: document.querySelector("#retailWeightSourceLabel"),
  captureState: document.querySelector("#retailCaptureState"),
  captureWeight: document.querySelector("#retailCaptureWeight"),
  pricePreview: document.querySelector("#retailPricePreview"),
  priceSource: document.querySelector("#retailPriceSource"),
  grossPreview: document.querySelector("#retailGrossPreview"),
  tarePreview: document.querySelector("#retailTarePreview"),
  netPreview: document.querySelector("#retailNetPreview"),
  birdTotalPreview: document.querySelector("#retailBirdTotalPreview"),
  chickenTypes: document.querySelector("#retailChickenTypes"),
  sex: document.querySelector("#retailSex"),
  adjustments: document.querySelector("#retailAdjustments"),
  listsGrid: document.querySelector("#retailListsGrid"),
  assignClient: document.querySelector("#retailAssignClient"),
  removeWeighing: document.querySelector("#retailRemoveWeighing"),
  assignPrice: document.querySelector("#retailAssignPrice"),
  saveDispatch: document.querySelector("#retailSaveDispatch"),
  message: document.querySelector("#retailMessage"),
  activeListNumber: document.querySelector("#retailActiveListNumber"),
  totalWeighings: document.querySelector("#retailTotalWeighings"),
  totalTrays: document.querySelector("#retailTotalTrays"),
  totalBirds: document.querySelector("#retailTotalBirds"),
  totalNet: document.querySelector("#retailTotalNet"),
  totalAmount: document.querySelector("#retailTotalAmount"),
  lastTicket: document.querySelector("#retailLastTicket"),
  clientModal: document.querySelector("#retailClientModal"),
  clientSearch: document.querySelector("#retailClientSearch"),
  clientOptions: document.querySelector("#retailClientOptions"),
  priceModal: document.querySelector("#retailPriceModal"),
  priceForm: document.querySelector("#retailPriceForm"),
  priceFields: document.querySelector("#retailPriceFields"),
  clearPrices: document.querySelector("#retailClearPrices"),
  settingsModal: document.querySelector("#retailSettingsModal"),
  settingsForm: document.querySelector("#retailSettingsForm"),
  settingsScaleName: document.querySelector("#retailSettingsScaleName"),
  settingsStatus: document.querySelector("#retailSettingsStatus"),
  connectBle: document.querySelector("#retailConnectBle"),
  connectSerial: document.querySelector("#retailConnectSerial"),
  disconnectScale: document.querySelector("#retailDisconnectScale"),
  manualScaleInput: document.querySelector("#retailManualScaleInput"),
  applyManualScale: document.querySelector("#retailApplyManualScale"),
  scaleRaw: document.querySelector("#retailScaleRaw"),
  baudRate: document.querySelector("#retailBaudRate"),
  dataBits: document.querySelector("#retailDataBits"),
  stopBits: document.querySelector("#retailStopBits"),
  parity: document.querySelector("#retailParity"),
  flowControl: document.querySelector("#retailFlowControl"),
  defaultAdjustment: document.querySelector("#retailDefaultAdjustment"),
  settingsAdjustments: document.querySelector("#retailSettingsAdjustments"),
  settingsMessage: document.querySelector("#retailSettingsMessage"),
  saveSettings: document.querySelector("#retailSaveSettings")
};

const state = {
  catalog: {
    branch: null,
    clients: [],
    chicken_types: [],
    tray_types: [],
    adjustments: [],
    scale: null
  },
  activeList: 0,
  chickenType: null,
  sex: SEX_MALE,
  adjustmentCode: null,
  liveWeight: 0,
  liveSource: "manual",
  captured: null,
  selectedItem: null,
  storageKey: null,
  lists: Array.from({ length: LIST_COUNT }, emptyList),
  scale: null,
  scaleState: null,
  loading: true
};

function createDraftId() {
  if (typeof globalThis.crypto?.randomUUID === "function") {
    return globalThis.crypto.randomUUID();
  }

  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    const value = character === "x" ? random : ((random & 0x3) | 0x8);
    return value.toString(16);
  });
}

function emptyList() {
  return {
    draftId: createDraftId(),
    clientId: "",
    operationType: OPERATION_SALE,
    priceOverrides: {},
    items: [],
    saving: false
  };
}

function normalizeList(list) {
  const source = list && typeof list === "object" ? list : {};

  return {
    draftId: source.draftId || createDraftId(),
    clientId: String(source.clientId || ""),
    operationType: source.operationType === OPERATION_RETURN ? OPERATION_RETURN : OPERATION_SALE,
    priceOverrides: source.priceOverrides && typeof source.priceOverrides === "object"
      ? { ...source.priceOverrides }
      : {},
    items: Array.isArray(source.items)
      ? source.items
        .filter((item) => item && item.adjustmentCode && Number(item.readWeight) > 0)
        .map((item) => ({ ...item, id: item.id || createDraftId() }))
      : [],
    saving: false
  };
}

function restoreLists(branchId) {
  state.storageKey = `${STORAGE_PREFIX}-${branchId}`;

  try {
    const stored = JSON.parse(localStorage.getItem(state.storageKey));
    if (Array.isArray(stored) && stored.length === LIST_COUNT) {
      state.lists = stored.map(normalizeList);
      return;
    }
  } catch {
    // Si el almacenamiento está dañado, se inicia una estación limpia.
  }

  state.lists = Array.from({ length: LIST_COUNT }, emptyList);
}

function persistLists() {
  if (!state.storageKey) return;

  const serializable = state.lists.map((list) => ({
    draftId: list.draftId,
    clientId: list.clientId,
    operationType: list.operationType,
    priceOverrides: list.priceOverrides,
    items: list.items
  }));

  try {
    localStorage.setItem(state.storageKey, JSON.stringify(serializable));
  } catch {
    // La venta continúa aunque el navegador no permita almacenamiento local.
  }
}

function recalculateDraftItems() {
  state.lists.forEach((list) => {
    list.items = list.items.map((item) => {
      const adjustment = state.catalog.adjustments.find((entry) => entry.code === item.adjustmentCode);
      const tray = state.catalog.tray_types.find((entry) => entry.code === item.trayTypeCode);
      if (!adjustment || !tray) return item;

      const adjustmentGrams = Number(adjustment.additional_grams || 0);
      const grossWeight = roundWeight(Number(item.readWeight || 0) + adjustmentGrams / 1000);
      const tareWeight = roundWeight(Number(item.trayCount || 0) * Number(tray.weight_kg || 0));

      return {
        ...item,
        adjustmentName: adjustment.name,
        chickenSex: adjustment.sex,
        presentation: adjustment.presentation,
        adjustmentGrams,
        trayTypeName: tray.name,
        grossWeight,
        tareWeight,
        netWeight: roundWeight(grossWeight - tareWeight)
      };
    });
  });
  persistLists();
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function roundWeight(value) {
  return Math.round((Number(value || 0) + Number.EPSILON) * 1000) / 1000;
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function formatMoney(value, signed = false) {
  const amount = Number(value || 0);
  const prefix = signed && amount > 0 ? "+" : "";
  return `${prefix}S/ ${amount.toFixed(2)}`;
}

function formatGrams(value) {
  const grams = Number(value || 0);
  if (!grams) return "Sin ajuste adicional";
  return `${grams > 0 ? "+" : ""}${grams} g adicionales`;
}

function setMessage(message, isError = false) {
  elements.message.textContent = message || "";
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function setSettingsMessage(message, isError = false) {
  elements.settingsMessage.textContent = message || "";
  elements.settingsMessage.classList.toggle("is-error", Boolean(isError));
}

function activeList() {
  return state.lists[state.activeList];
}

function selectedTray() {
  return state.catalog.tray_types.find((tray) => tray.code === elements.trayType.value) || null;
}

function selectedChickenType() {
  return state.catalog.chicken_types.find((type) => type.code === state.chickenType) || null;
}

function selectedAdjustment() {
  return state.catalog.adjustments.find((adjustment) => adjustment.code === state.adjustmentCode) || null;
}

function clientFor(list = activeList()) {
  return state.catalog.clients.find((client) => String(client.id) === String(list.clientId)) || null;
}

function normalizePriceRecord(record) {
  if (record === null || record === undefined || record === "") return null;
  if (typeof record === "object") {
    const value = Number(record.price_kg ?? record.priceKg ?? record.value);
    return Number.isFinite(value)
      ? { value, source: String(record.source || record.origin || "VIGENTE") }
      : null;
  }

  const value = Number(record);
  return Number.isFinite(value) ? { value, source: "VIGENTE" } : null;
}

function currentClientPrice(list, chickenTypeCode) {
  const client = clientFor(list);
  if (!client) return null;

  const prices = client.prices || client.prices_kg || client.pricesKg || {};
  const direct = prices[chickenTypeCode];
  if (direct !== undefined) return normalizePriceRecord(direct);

  const legacyKeys = {
    POLLO_VIVO: "pollo_vivo",
    POLLO_PELADO: "pollo_pelado",
    POLLO_BENEFICIADO: "pollo_beneficiado"
  };

  return normalizePriceRecord(prices[legacyKeys[chickenTypeCode]]);
}

function effectivePrice(list, chickenTypeCode) {
  const override = list.priceOverrides?.[chickenTypeCode];
  if (override !== undefined && override !== null && override !== "") {
    const value = Number(override);
    if (Number.isFinite(value)) return { value, source: "MANUAL" };
  }

  return currentClientPrice(list, chickenTypeCode);
}

function missingPriceTypes(list) {
  return [...new Set(list.items.map((item) => item.chickenTypeCode))]
    .filter((code) => !effectivePrice(list, code));
}

function listTotals(list) {
  const sign = list.operationType === OPERATION_RETURN ? -1 : 1;

  return list.items.reduce((totals, item) => {
    const price = effectivePrice(list, item.chickenTypeCode)?.value;
    totals.trays += Number(item.trayCount || 0);
    totals.birds += Number(item.birds || 0);
    totals.gross += Number(item.grossWeight || 0);
    totals.tare += Number(item.tareWeight || 0);
    totals.net += Number(item.netWeight || 0);
    if (Number.isFinite(price)) totals.amount += sign * Number(item.netWeight || 0) * price;
    return totals;
  }, {
    weighings: list.items.length,
    trays: 0,
    birds: 0,
    gross: 0,
    tare: 0,
    net: 0,
    amount: 0
  });
}

function previewValues() {
  const tray = selectedTray();
  const adjustment = selectedAdjustment();
  const readWeight = Number(state.captured?.readWeight ?? state.liveWeight ?? 0);
  const trayCount = Math.max(0, Number(elements.trayCount.value || 0));
  const birdsPerTray = Math.max(0, Number(elements.birdsPerTray.value || 0));
  const adjustmentGrams = Number(adjustment?.additional_grams || 0);
  const grossWeight = roundWeight(readWeight + adjustmentGrams / 1000);
  const tareWeight = roundWeight(trayCount * Number(tray?.weight_kg || 0));
  const netWeight = roundWeight(grossWeight - tareWeight);

  return {
    tray,
    adjustment,
    readWeight,
    trayCount,
    birdsPerTray,
    adjustmentGrams,
    grossWeight,
    tareWeight,
    netWeight,
    birds: trayCount * birdsPerTray
  };
}

function renderWeightPreview() {
  const values = previewValues();
  const price = effectivePrice(activeList(), state.chickenType);
  const source = state.captured?.source || state.liveSource || "manual";
  const sourceLabels = {
    manual: "Ingreso manual",
    ble: "Balanza minorista · BLE",
    serial: "Balanza minorista · Serial"
  };

  elements.adjustedWeight.textContent = values.grossWeight.toFixed(3);
  elements.adjustmentPreview.textContent = formatGrams(values.adjustmentGrams);
  elements.grossPreview.textContent = formatWeight(values.grossWeight);
  elements.tarePreview.textContent = formatWeight(values.tareWeight);
  elements.netPreview.textContent = formatWeight(Math.max(values.netWeight, 0));
  elements.netPreview.classList.toggle("is-invalid", values.readWeight > 0 && values.netWeight <= 0);
  elements.birdTotalPreview.textContent = `${values.birds} ave${values.birds === 1 ? "" : "s"}`;
  elements.weightSourceLabel.textContent = sourceLabels[source] || "Balanza minorista";
  elements.captureState.textContent = state.captured ? "Peso congelado" : "Peso en vivo";
  elements.captureState.classList.toggle("is-captured", Boolean(state.captured));
  elements.captureWeight.classList.toggle("is-captured", Boolean(state.captured));
  elements.captureWeight.lastChild.textContent = state.captured ? " Volver a capturar" : " Capturar peso";
  elements.pricePreview.textContent = price ? `S/ ${price.value.toFixed(2)}` : "S/ --";
  elements.priceSource.textContent = price
    ? (price.source === "MANUAL" ? "Precio puntual de la lista" : `Precio ${price.source.toLowerCase()}`)
    : (clientFor() ? "Precio no configurado" : "Asigna un cliente");
  elements.trayCountLabel.textContent = `${values.trayCount} bandeja${values.trayCount === 1 ? "" : "s"}`;

  document.querySelectorAll("[data-retail-tray-count]").forEach((button) => {
    button.classList.toggle("is-active", Number(button.dataset.retailTrayCount) === values.trayCount);
  });
}

function renderChickenTypes() {
  if (!state.catalog.chicken_types.length) {
    elements.chickenTypes.innerHTML = '<span class="rd-empty-list">Sin tipos de pollo</span>';
    return;
  }

  if (!state.catalog.chicken_types.some((type) => type.code === state.chickenType)) {
    state.chickenType = state.catalog.chicken_types[0].code;
  }

  elements.chickenTypes.innerHTML = state.catalog.chicken_types.map((type) => `
    <button type="button" data-retail-chicken="${escapeHtml(type.code)}" class="${type.code === state.chickenType ? "is-active" : ""}" aria-pressed="${type.code === state.chickenType}">
      ${escapeHtml(type.name)}
      <small>${escapeHtml(type.code.replaceAll("_", " "))}</small>
    </button>
  `).join("");
}

function adjustmentsForSex(sex) {
  return state.catalog.adjustments.filter((adjustment) => adjustment.sex === sex);
}

function presentationKind(value) {
  const normalized = String(value || "").toUpperCase();
  if (normalized.startsWith("ABIERT")) return "ABIERTO";
  if (normalized.startsWith("CERRAD")) return "CERRADO";
  return normalized;
}

function ensureAdjustmentSelection(preferredPresentation = null) {
  const available = adjustmentsForSex(state.sex);
  const current = available.find((adjustment) => adjustment.code === state.adjustmentCode);
  if (current) return;

  const preferred = available.find((adjustment) => (
    presentationKind(adjustment.presentation) === presentationKind(preferredPresentation)
  ))
    || available.find((adjustment) => adjustment.is_default)
    || available[0];
  state.adjustmentCode = preferred?.code || null;
}

function renderAdjustments() {
  ensureAdjustmentSelection();
  const available = adjustmentsForSex(state.sex);

  elements.adjustments.innerHTML = available.map((adjustment) => `
    <button type="button" data-retail-adjustment="${escapeHtml(adjustment.code)}" class="${adjustment.code === state.adjustmentCode ? "is-active" : ""}" aria-pressed="${adjustment.code === state.adjustmentCode}">
      ${escapeHtml(adjustment.name)}
      <small>${formatGrams(adjustment.additional_grams)}</small>
    </button>
  `).join("");
}

function renderLists() {
  const current = activeList();
  const currentTotals = listTotals(current);

  elements.listsGrid.innerHTML = state.lists.map((list, listIndex) => {
    const client = clientFor(list);
    const totals = listTotals(list);
    const operationLabel = list.operationType === OPERATION_RETURN ? "Devolución" : "Venta";
    const rows = list.items.length
      ? list.items.map((item) => {
        const selected = state.selectedItem?.listIndex === listIndex && state.selectedItem?.id === item.id;
        return `
          <tr class="rd-list-row ${selected ? "is-selected" : ""}" data-retail-item="${listIndex}:${escapeHtml(item.id)}" tabindex="0" aria-selected="${selected}">
            <td>${escapeHtml(item.chickenShortName || item.chickenTypeName || "Pollo")}<small>${escapeHtml(item.adjustmentName || item.adjustmentCode)}</small></td>
            <td>${item.trayCount}</td>
            <td>${item.birds}</td>
            <td>${Number(item.netWeight).toFixed(3)}<small>S/ ${Number((effectivePrice(list, item.chickenTypeCode)?.value || 0) * item.netWeight).toFixed(2)}</small></td>
          </tr>
        `;
      }).join("")
      : '<tr><td colspan="4"><div class="rd-empty-list">Captura un peso y agrégalo a esta lista.</div></td></tr>';

    return `
      <article class="rd-list-card ${LIST_CLASSES[listIndex]} ${listIndex === state.activeList ? "is-active" : ""} ${list.saving ? "is-saving" : ""}" data-retail-list="${listIndex}">
        <header class="rd-list-head">
          <span class="rd-list-number">${listIndex + 1}</span>
          <span class="rd-list-client"><strong>${escapeHtml(client?.name || "Cliente sin asignar")}</strong><small>${totals.weighings} pesada${totals.weighings === 1 ? "" : "s"} · ${totals.trays} bandejas</small></span>
          <span class="rd-list-operation ${list.operationType === OPERATION_RETURN ? "is-return" : ""}">${operationLabel}</span>
        </header>
        <div class="rd-list-table-wrap">
          <table class="rd-list-table">
            <thead><tr><th>Tipo</th><th>Band.</th><th>Aves</th><th>P. neto</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        <footer class="rd-list-foot">
          <span>Peso neto<b>${formatWeight(totals.net)}</b></span>
          <strong>${operationLabel}<b>${formatMoney(totals.amount)}</b></strong>
        </footer>
      </article>
    `;
  }).join("");

  elements.activeListNumber.textContent = state.activeList + 1;
  elements.totalWeighings.textContent = currentTotals.weighings;
  elements.totalTrays.textContent = currentTotals.trays;
  elements.totalBirds.textContent = currentTotals.birds;
  elements.totalNet.textContent = formatWeight(currentTotals.net);
  elements.totalAmount.textContent = formatMoney(currentTotals.amount);
  const missingPrices = missingPriceTypes(current);
  elements.saveDispatch.disabled = state.loading
    || current.saving
    || !current.items.length
    || !current.clientId
    || missingPrices.length > 0;
  elements.saveDispatch.title = missingPrices.length
    ? "Configura un precio vigente para cada tipo de pollo antes de grabar."
    : "Grabar la lista activa";
  elements.removeWeighing.disabled = current.saving
    || !state.selectedItem
    || state.selectedItem.listIndex !== state.activeList;

  document.querySelectorAll("[data-retail-operation]").forEach((button) => {
    const selected = button.dataset.retailOperation === current.operationType;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
}

function renderAll() {
  renderChickenTypes();
  renderAdjustments();
  renderWeightPreview();
  renderLists();
}

function selectList(index) {
  const nextIndex = Number(index);
  if (!Number.isInteger(nextIndex) || nextIndex < 0 || nextIndex >= LIST_COUNT) return;
  if (activeList().saving) return;

  state.activeList = nextIndex;
  state.selectedItem = null;
  renderAll();
  setMessage(`Lista ${nextIndex + 1} activa.`);
}

function captureWeight() {
  const manualValue = Number(String(elements.rawWeightInput.value || "0").replace(",", "."));
  if (state.liveSource === "manual" && Number.isFinite(manualValue)) {
    state.liveWeight = roundWeight(manualValue);
  }

  if (!Number.isFinite(state.liveWeight) || state.liveWeight <= 0) {
    setMessage("La balanza debe mostrar un peso mayor que cero antes de capturarlo.", true);
    elements.rawWeightInput.focus();
    return;
  }

  state.captured = {
    readWeight: roundWeight(state.liveWeight),
    source: state.liveSource || "manual",
    weighedAt: new Date().toISOString()
  };
  renderWeightPreview();
  setMessage(`Peso de ${formatWeight(state.captured.readWeight)} congelado para agregar a una lista.`);
}

function addWeighingToList(listIndex) {
  const targetIndex = Number(listIndex);
  const target = state.lists[targetIndex];
  const values = previewValues();
  const chickenType = selectedChickenType();

  if (!target || target.saving) return;
  if (!state.captured) return setMessage("Primero captura el peso de la balanza.", true);
  if (!values.tray || !values.adjustment || !chickenType) {
    return setMessage("Los catálogos minoristas todavía no están disponibles.", true);
  }
  if (!Number.isInteger(values.trayCount) || values.trayCount < 1) {
    return setMessage("Indica una cantidad válida de bandejas.", true);
  }
  if (!Number.isInteger(values.birdsPerTray) || values.birdsPerTray < 1) {
    return setMessage("Indica cuántas aves lleva cada bandeja.", true);
  }
  if (values.netWeight <= 0) {
    return setMessage("El peso ajustado debe ser mayor que la tara total de las bandejas.", true);
  }

  target.items.push({
    id: createDraftId(),
    chickenTypeCode: chickenType.code,
    chickenTypeName: chickenType.name,
    chickenShortName: chickenType.name.replace(/^Pollo\s+/i, ""),
    adjustmentCode: values.adjustment.code,
    adjustmentName: values.adjustment.name,
    chickenSex: values.adjustment.sex,
    presentation: values.adjustment.presentation,
    adjustmentGrams: values.adjustmentGrams,
    trayTypeCode: values.tray.code,
    trayTypeName: values.tray.name,
    trayCount: values.trayCount,
    birdsPerTray: values.birdsPerTray,
    birds: values.birds,
    readWeight: state.captured.readWeight,
    grossWeight: values.grossWeight,
    tareWeight: values.tareWeight,
    netWeight: values.netWeight,
    weightSource: state.captured.source === "manual" ? "MANUAL" : "BALANZA_MINORISTA",
    weighedAt: state.captured.weighedAt
  });

  state.activeList = targetIndex;
  state.selectedItem = { listIndex: targetIndex, id: target.items.at(-1).id };
  const capturedSource = state.captured.source;
  state.captured = null;
  if (capturedSource === "manual") {
    state.liveWeight = 0;
    state.liveSource = "manual";
    elements.rawWeightInput.value = "0";
  } else {
    elements.rawWeightInput.value = Number(state.liveWeight || 0).toFixed(3);
  }
  persistLists();
  renderAll();
  setMessage(`Pesada agregada a la lista ${targetIndex + 1}.`);
}

function removeSelectedWeighing() {
  const selected = state.selectedItem;
  if (!selected || selected.listIndex !== state.activeList) return;

  const list = activeList();
  const index = list.items.findIndex((item) => item.id === selected.id);
  if (index < 0) return;

  list.items.splice(index, 1);
  state.selectedItem = null;
  persistLists();
  renderAll();
  setMessage("Pesada retirada de la lista activa.");
}

function openModal(modal) {
  if (!modal) return;
  modal.hidden = false;
}

function closeModal(modal) {
  if (!modal) return;
  modal.hidden = true;
}

function renderClientOptions(search = "") {
  const normalized = String(search || "").trim().toLocaleLowerCase("es");
  const clients = state.catalog.clients.filter((client) => {
    if (!normalized) return true;
    return `${client.name || ""} ${client.document || ""}`.toLocaleLowerCase("es").includes(normalized);
  });

  elements.clientOptions.innerHTML = clients.length
    ? clients.map((client) => {
      const selected = String(client.id) === String(activeList().clientId);
      return `
        <button class="rd-client-option ${selected ? "is-selected" : ""}" type="button" data-retail-client="${client.id}" role="option" aria-selected="${selected}">
          <span><strong>${escapeHtml(client.name)}</strong><small>${escapeHtml(client.document || "Sin documento")}</small></span>
          <b>${selected ? "Seleccionado" : "Asignar"}</b>
        </button>
      `;
    }).join("")
    : '<p class="rd-empty-list">No hay clientes que coincidan con la búsqueda.</p>';
}

function openClientModal() {
  elements.clientSearch.value = "";
  renderClientOptions();
  openModal(elements.clientModal);
  elements.clientSearch.focus();
}

function assignClient(clientId) {
  const client = state.catalog.clients.find((entry) => String(entry.id) === String(clientId));
  if (!client) return;

  activeList().clientId = String(client.id);
  activeList().priceOverrides = {};
  persistLists();
  closeModal(elements.clientModal);
  renderAll();
  setMessage(`${client.name} asignado a la lista ${state.activeList + 1}.`);
}

function renderPriceFields() {
  const list = activeList();
  const client = clientFor(list);
  elements.priceFields.innerHTML = state.catalog.chicken_types.map((type) => {
    const current = list.priceOverrides?.[type.code];
    const vigente = currentClientPrice(list, type.code);
    return `
      <label class="rd-price-field">
        <span>${escapeHtml(type.name)}</span>
        <input type="number" min="0.0001" max="99999999.9999" step="0.0001" inputmode="decimal" data-retail-price-code="${escapeHtml(type.code)}" value="${current ?? ""}" placeholder="${vigente ? vigente.value.toFixed(4) : "Sin precio base"}" ${vigente ? "" : "disabled"}>
        <small>${vigente ? `Vigente: S/ ${vigente.value.toFixed(4)} · ${escapeHtml(vigente.source)}` : "Configura primero el precio del cliente o el precio general en Directorio"}</small>
      </label>
    `;
  }).join("");

  elements.priceForm.querySelector(".rd-modal-copy").textContent = client
    ? `Precios para ${client.name}. Deja un campo vacío para conservar el precio vigente.`
    : "Primero asigna un cliente. Puedes preparar precios, pero el despacho no se podrá grabar sin cliente.";
}

function openPriceModal() {
  renderPriceFields();
  openModal(elements.priceModal);
  const firstInput = elements.priceFields.querySelector("input");
  firstInput?.focus();
}

function applyPrices(event) {
  event.preventDefault();
  const prices = {};
  let invalid = false;

  elements.priceFields.querySelectorAll("[data-retail-price-code]").forEach((input) => {
    const raw = input.value.trim();
    if (!raw) return;
    const value = Number(raw);
    if (!Number.isFinite(value) || value <= 0) {
      invalid = true;
      return;
    }
    prices[input.dataset.retailPriceCode] = Number(value.toFixed(4));
  });

  if (invalid) {
    setMessage("Los precios manuales deben ser mayores que cero.", true);
    return;
  }

  activeList().priceOverrides = prices;
  persistLists();
  closeModal(elements.priceModal);
  renderAll();
  setMessage(Object.keys(prices).length
    ? "Precios puntuales aplicados a la lista activa."
    : "La lista usará los precios vigentes del cliente.");
}

function clearPriceOverrides() {
  activeList().priceOverrides = {};
  renderPriceFields();
}

async function saveDispatch() {
  const listIndex = state.activeList;
  const list = state.lists[listIndex];
  if (!list || list.saving || !list.clientId || !list.items.length) return;
  if (missingPriceTypes(list).length) {
    setMessage("Falta un precio vigente para uno o más tipos de pollo de esta lista.", true);
    return;
  }

  list.saving = true;
  renderLists();
  setMessage(`Grabando ${list.operationType === OPERATION_RETURN ? "devolución" : "venta"} de la lista ${listIndex + 1}...`);

  try {
    const priceOverrides = Object.fromEntries(
      Object.entries(list.priceOverrides || {})
        .filter(([, value]) => Number.isFinite(Number(value)) && Number(value) > 0)
        .map(([code, value]) => [code, Number(value)])
    );
    const response = await apiRequest("/despacho-minorista/tickets", {
      method: "POST",
      body: JSON.stringify({
        draft_id: list.draftId,
        operation_type: list.operationType,
        client_id: Number(list.clientId),
        price_overrides: priceOverrides,
        weighings: list.items.map((item, index) => ({
          local_id: index + 1,
          chicken_type_code: item.chickenTypeCode,
          adjustment_code: item.adjustmentCode,
          tray_type_code: item.trayTypeCode,
          weight_source: item.weightSource,
          birds_per_tray: item.birdsPerTray,
          tray_count: item.trayCount,
          read_weight_kg: item.readWeight,
          weighed_at: item.weighedAt
        }))
      })
    });

    const ticket = response.data;
    state.lists[listIndex] = emptyList();
    state.selectedItem = null;
    persistLists();
    renderAll();

    const operationLabel = ticket.operation_type === OPERATION_RETURN ? "Devolución" : "Venta";
    elements.lastTicket.hidden = false;
    elements.lastTicket.innerHTML = `
      <span><strong>${escapeHtml(operationLabel)} ${escapeHtml(ticket.code)}</strong><br>${escapeHtml(ticket.client?.name || "Cliente")}</span>
      <span>${ticket.totals?.trays || 0} bandejas · ${formatWeight(ticket.totals?.net_weight_kg || 0)} · <strong>${formatMoney(ticket.totals?.amount || 0)}</strong></span>
    `;
    setMessage(response.message || "Despacho minorista registrado correctamente.");
    globalThis.setTimeout(() => {
      elements.lastTicket.hidden = true;
    }, 8000);
  } catch (error) {
    const validation = error.data?.errors ? Object.values(error.data.errors).flat()[0] : null;
    setMessage(validation || error.message, true);
  } finally {
    if (state.lists[listIndex] === list) list.saving = false;
    renderLists();
  }
}

function serialOptionsFromForm() {
  return {
    baudRate: Number(elements.baudRate.value),
    dataBits: Number(elements.dataBits.value),
    stopBits: Number(elements.stopBits.value),
    parity: elements.parity.value,
    flowControl: elements.flowControl.value
  };
}

function applySerialOptionsToForm(options = {}) {
  const serial = { ...RETAIL_SCALE_SERIAL_DEFAULTS, ...(options || {}) };
  elements.baudRate.value = serial.baudRate;
  elements.dataBits.value = String(serial.dataBits);
  elements.stopBits.value = String(serial.stopBits);
  elements.parity.value = serial.parity;
  elements.flowControl.value = serial.flowControl;
}

function renderScaleStatus(payload = state.scaleState) {
  const scaleState = payload?.state || payload || state.scale?.getState?.() || {};
  state.scaleState = scaleState;
  const status = scaleState.status || "offline";
  const statusText = scaleState.statusMessage || "Balanza sin conexión";
  const className = status === "connected"
    ? "is-connected"
    : status === "connecting"
      ? "is-connecting"
      : status === "error"
        ? "is-error"
        : "is-offline";

  [elements.scaleTopStatus, elements.settingsStatus].forEach((element) => {
    element.className = `rd-status-chip ${className}`;
    element.querySelector("span").textContent = statusText;
    element.title = statusText;
  });

  const capabilities = scaleState.capabilities || {};
  elements.connectBle.disabled = status === "connecting" || status === "connected" || !capabilities.bluetooth;
  elements.connectSerial.disabled = status === "connecting" || status === "connected" || !capabilities.serial;
  elements.disconnectScale.disabled = status !== "connecting" && status !== "connected" && !scaleState.autoConnectMode;
}

function renderSettingsAdjustments() {
  elements.defaultAdjustment.innerHTML = state.catalog.adjustments.map((adjustment) => `
    <option value="${escapeHtml(adjustment.code)}" ${adjustment.is_default ? "selected" : ""}>${escapeHtml(adjustment.name)}</option>
  `).join("");

  elements.settingsAdjustments.innerHTML = state.catalog.adjustments.map((adjustment) => `
    <article class="rd-adjustment-setting">
      <strong>${escapeHtml(adjustment.name)}</strong>
      <small>${escapeHtml(adjustment.sex)} · ${escapeHtml(adjustment.presentation)}</small>
      <label>
        <span>Gramos</span>
        <input type="number" min="0" max="100000" step="1" value="${Number(adjustment.additional_grams || 0)}" data-retail-setting-adjustment="${escapeHtml(adjustment.code)}" inputmode="numeric">
      </label>
    </article>
  `).join("");
}

function fillSettingsForm() {
  const configuration = state.catalog.scale?.configuration || {};
  const serialOptions = {
    ...RETAIL_SCALE_SERIAL_DEFAULTS,
    ...(configuration.serial || {}),
    ...configuration
  };
  applySerialOptionsToForm(serialOptions);
  elements.settingsScaleName.textContent = state.catalog.scale?.name || "Balanza minorista";
  renderSettingsAdjustments();
  renderScaleStatus();
  setSettingsMessage("");
}

function applySettingsResponse(data) {
  const payload = data?.catalog || data || {};
  if (Array.isArray(payload.adjustments)) {
    state.catalog.adjustments = payload.adjustments;
  }
  if (payload.scale) {
    state.catalog.scale = payload.scale;
  }

  const defaultAdjustment = state.catalog.adjustments.find((entry) => entry.is_default);
  if (defaultAdjustment) {
    state.sex = defaultAdjustment.sex;
    state.adjustmentCode = defaultAdjustment.code;
    elements.sex.value = state.sex;
  }
  recalculateDraftItems();
}

async function saveSettings(event) {
  event.preventDefault();
  const adjustments = [];
  let invalid = false;

  elements.settingsAdjustments.querySelectorAll("[data-retail-setting-adjustment]").forEach((input) => {
    const grams = Number(input.value);
    if (!Number.isInteger(grams) || grams < 0) invalid = true;
    adjustments.push({
      code: input.dataset.retailSettingAdjustment,
      additional_grams: grams
    });
  });

  if (invalid) {
    setSettingsMessage("Los ajustes deben expresarse en gramos enteros mayores o iguales que cero.", true);
    return;
  }

  let serialOptions;
  try {
    serialOptions = state.scale.configureSerial(serialOptionsFromForm());
  } catch (error) {
    setSettingsMessage(error.message, true);
    return;
  }

  elements.saveSettings.disabled = true;
  setSettingsMessage("Guardando configuración minorista...");
  try {
    const scaleState = state.scale.getState();
    const selectedConnectionMode = String(
      scaleState.connectionMode || state.catalog.scale?.connection_mode || "MANUAL"
    ).toLowerCase();
    const connectionMode = ["ble", "bluetooth"].includes(selectedConnectionMode)
      ? "BLUETOOTH"
      : selectedConnectionMode === "serial"
        ? "SERIAL"
        : "MANUAL";
    const response = await apiRequest("/despacho-minorista/configuracion", {
      method: "PUT",
      body: JSON.stringify({
        scale: {
          connection_mode: connectionMode,
          device: scaleState.deviceName || state.catalog.scale?.device || null,
          configuration: {
            ...serialOptions,
            profileId: scaleState.profileId || null,
            profileLabel: scaleState.profileLabel || null
          }
        },
        default_adjustment_code: elements.defaultAdjustment.value,
        adjustments
      })
    });
    applySettingsResponse(response.data);
    fillSettingsForm();
    renderAll();
    setSettingsMessage(response.message || "Configuración guardada correctamente.");
    setMessage("Configuración de balanza y gramos actualizada.");
  } catch (error) {
    const validation = error.data?.errors ? Object.values(error.data.errors).flat()[0] : null;
    setSettingsMessage(validation || error.message, true);
  } finally {
    elements.saveSettings.disabled = false;
  }
}

async function connectBle() {
  setSettingsMessage("Selecciona la balanza Bluetooth en el navegador...");
  const connected = await state.scale.connectBle();
  setSettingsMessage(connected ? "Balanza BLE conectada." : state.scale.getState().statusMessage, !connected);
}

async function connectSerial() {
  let serialOptions;
  try {
    serialOptions = state.scale.configureSerial(serialOptionsFromForm());
  } catch (error) {
    setSettingsMessage(error.message, true);
    return;
  }

  setSettingsMessage("Selecciona el puerto serial de la balanza...");
  const connected = await state.scale.connectSerial({ serialOptions });
  setSettingsMessage(connected ? "Balanza serial conectada." : state.scale.getState().statusMessage, !connected);
}

async function disconnectScale() {
  await state.scale.disconnect({ forget: false });
  state.liveSource = "manual";
  setSettingsMessage("Balanza desconectada. La autorización quedó recordada.");
}

function applyManualScaleReading() {
  try {
    const scaleState = state.scale.setManualReading(elements.manualScaleInput.value);
    state.liveWeight = scaleState.currentWeightKg;
    state.liveSource = "manual";
    state.captured = null;
    elements.rawWeightInput.value = state.liveWeight.toFixed(3);
    renderWeightPreview();
    setSettingsMessage(`Lectura manual aplicada: ${formatWeight(state.liveWeight)}.`);
  } catch (error) {
    setSettingsMessage(error.message, true);
  }
}

function normalizeCatalog(data) {
  const adjustments = Array.isArray(data.adjustments) ? data.adjustments : [];
  return {
    branch: data.branch || null,
    clients: Array.isArray(data.clients) ? data.clients : [],
    chicken_types: Array.isArray(data.chicken_types) ? data.chicken_types : [],
    tray_types: Array.isArray(data.tray_types) ? data.tray_types : [],
    adjustments: adjustments.map((adjustment) => ({
      ...adjustment,
      sex: String(adjustment.sex || SEX_MALE).toUpperCase(),
      presentation: String(adjustment.presentation || "CERRADO").toUpperCase(),
      additional_grams: Number(adjustment.additional_grams || 0),
      is_default: Boolean(adjustment.is_default)
    })),
    scale: data.scale || null
  };
}

function renderTrayOptions() {
  elements.trayType.innerHTML = state.catalog.tray_types.map((tray) => `
    <option value="${escapeHtml(tray.code)}">${escapeHtml(tray.name)} · tara ${Number(tray.weight_kg || 0).toFixed(3)} kg</option>
  `).join("");
  const tray = selectedTray();
  if (tray?.bird_capacity) elements.birdsPerTray.value = tray.bird_capacity;
}

async function loadCatalog() {
  state.loading = true;
  renderLists();
  try {
    const response = await apiRequest("/despacho-minorista/catalogo");
    state.catalog = normalizeCatalog(response.data || {});
    restoreLists(state.catalog.branch?.id || "default");
    recalculateDraftItems();
    elements.branchName.textContent = state.catalog.branch?.name || "Sucursal sin nombre";
    renderTrayOptions();

    const defaultAdjustment = state.catalog.adjustments.find((adjustment) => adjustment.is_default)
      || state.catalog.adjustments[0];
    if (defaultAdjustment) {
      state.sex = defaultAdjustment.sex;
      state.adjustmentCode = defaultAdjustment.code;
      elements.sex.value = state.sex;
    }
    state.chickenType = state.catalog.chicken_types[0]?.code || null;

    const configuration = state.catalog.scale?.configuration || {};
    try {
      state.scale.configureSerial({
        ...RETAIL_SCALE_SERIAL_DEFAULTS,
        ...(configuration.serial || {}),
        ...configuration
      });
    } catch {
      state.scale.configureSerial(RETAIL_SCALE_SERIAL_DEFAULTS);
    }

    fillSettingsForm();
    state.loading = false;
    renderAll();
    setMessage("Estación minorista lista. Captura el peso y agrégalo a una de las cuatro listas.");
    void state.scale.restore().catch(() => undefined);
  } catch (error) {
    state.loading = false;
    renderAll();
    setMessage(error.message, true);
  }
}

function updateClock() {
  elements.clock.textContent = new Intl.DateTimeFormat("es-PE", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false
  }).format(new Date());
}

state.scale = new RetailScaleController({
  onReading({ weightKg, source }) {
    state.liveWeight = Number(weightKg || 0);
    state.liveSource = source || "manual";
    if (!state.captured) elements.rawWeightInput.value = state.liveWeight.toFixed(3);
    renderWeightPreview();
  },
  onStatus(payload) {
    state.scaleState = payload.state;
    renderScaleStatus(payload);
  },
  onRaw({ raw }) {
    elements.scaleRaw.textContent = `Trama: ${raw || "--"}`;
    elements.scaleRaw.title = raw || "";
  }
});

elements.rawWeightInput.addEventListener("input", () => {
  const value = Number(String(elements.rawWeightInput.value || "0").replace(",", "."));
  state.liveWeight = Number.isFinite(value) ? Math.max(roundWeight(value), 0) : 0;
  state.liveSource = "manual";
  state.captured = null;
  renderWeightPreview();
});
elements.trayCount.addEventListener("input", renderWeightPreview);
elements.birdsPerTray.addEventListener("input", renderWeightPreview);
elements.trayType.addEventListener("change", () => {
  const tray = selectedTray();
  if (tray?.bird_capacity) elements.birdsPerTray.value = tray.bird_capacity;
  renderWeightPreview();
});
elements.sex.addEventListener("change", () => {
  const previousPresentation = selectedAdjustment()?.presentation;
  state.sex = elements.sex.value === SEX_FEMALE ? SEX_FEMALE : SEX_MALE;
  state.adjustmentCode = null;
  ensureAdjustmentSelection(previousPresentation);
  renderAdjustments();
  renderWeightPreview();
});
elements.captureWeight.addEventListener("click", captureWeight);
elements.assignClient.addEventListener("click", openClientModal);
elements.removeWeighing.addEventListener("click", removeSelectedWeighing);
elements.assignPrice.addEventListener("click", openPriceModal);
elements.saveDispatch.addEventListener("click", saveDispatch);
elements.clientSearch.addEventListener("input", () => renderClientOptions(elements.clientSearch.value));
elements.priceForm.addEventListener("submit", applyPrices);
elements.clearPrices.addEventListener("click", clearPriceOverrides);
elements.openSettings.addEventListener("click", () => {
  fillSettingsForm();
  openModal(elements.settingsModal);
});
elements.settingsForm.addEventListener("submit", saveSettings);
elements.connectBle.addEventListener("click", connectBle);
elements.connectSerial.addEventListener("click", connectSerial);
elements.disconnectScale.addEventListener("click", disconnectScale);
elements.applyManualScale.addEventListener("click", applyManualScaleReading);

document.addEventListener("click", (event) => {
  const quickTray = event.target.closest("[data-retail-tray-count]");
  if (quickTray) {
    elements.trayCount.value = quickTray.dataset.retailTrayCount;
    renderWeightPreview();
    return;
  }

  const chickenButton = event.target.closest("[data-retail-chicken]");
  if (chickenButton) {
    state.chickenType = chickenButton.dataset.retailChicken;
    renderChickenTypes();
    renderWeightPreview();
    return;
  }

  const adjustmentButton = event.target.closest("[data-retail-adjustment]");
  if (adjustmentButton) {
    state.adjustmentCode = adjustmentButton.dataset.retailAdjustment;
    renderAdjustments();
    renderWeightPreview();
    return;
  }

  const addButton = event.target.closest("[data-retail-add-list]");
  if (addButton) {
    addWeighingToList(addButton.dataset.retailAddList);
    return;
  }

  const itemRow = event.target.closest("[data-retail-item]");
  if (itemRow) {
    const separator = itemRow.dataset.retailItem.indexOf(":");
    const listIndex = Number(itemRow.dataset.retailItem.slice(0, separator));
    const itemId = itemRow.dataset.retailItem.slice(separator + 1);
    state.activeList = listIndex;
    state.selectedItem = { listIndex, id: itemId };
    renderAll();
    return;
  }

  const listCard = event.target.closest("[data-retail-list]");
  if (listCard) {
    selectList(listCard.dataset.retailList);
    return;
  }

  const operationButton = event.target.closest("[data-retail-operation]");
  if (operationButton) {
    activeList().operationType = operationButton.dataset.retailOperation === OPERATION_RETURN
      ? OPERATION_RETURN
      : OPERATION_SALE;
    persistLists();
    renderAll();
    setMessage(`Lista ${state.activeList + 1} configurada como ${activeList().operationType === OPERATION_RETURN ? "devolución" : "venta"}.`);
    return;
  }

  const clientButton = event.target.closest("[data-retail-client]");
  if (clientButton) {
    assignClient(clientButton.dataset.retailClient);
    return;
  }

  const closeButton = event.target.closest("[data-retail-close-modal]");
  if (closeButton) {
    closeModal(document.getElementById(closeButton.dataset.retailCloseModal));
    return;
  }

  const modalBackdrop = event.target.closest(".rd-modal");
  if (modalBackdrop && event.target === modalBackdrop) closeModal(modalBackdrop);
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    [elements.clientModal, elements.priceModal, elements.settingsModal]
      .filter((modal) => !modal.hidden)
      .forEach(closeModal);
  }

  if ((event.key === "Enter" || event.key === " ") && event.target.matches("[data-retail-item]")) {
    event.preventDefault();
    event.target.click();
  }
});

globalThis.addEventListener("beforeunload", () => {
  persistLists();
  void state.scale.destroy();
});

updateClock();
globalThis.setInterval(updateClock, 1000);
renderAll();
renderScaleStatus(state.scale.getState());
loadCatalog();
