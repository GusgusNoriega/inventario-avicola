import { apiRequest } from "./api-client.js";
import { printWeightControlTicket } from "./ticket-printer.js";
import {
  RetailScaleController,
  RETAIL_SCALE_STORAGE_KEY,
  RETAIL_SCALE_SERIAL_DEFAULTS
} from "./despacho-minorista-balanza.js";

const STORAGE_PREFIX = "sistema-pollos-retail-dispatch-v2-branch";
const OPERATION_SALE = "DESPACHO";
const OPERATION_RETURN = "DEVOLUCION";
const SEX_MALE = "MACHO";
const LIST_COUNT = 4;
const LIST_CLASSES = ["is-list-1", "is-list-2", "is-list-3", "is-list-4"];
const TYPOGRAPHY_STORAGE_KEY = "sistema-pollos-retail-typography-v1";
const TYPOGRAPHY_GROUPS = [
  {
    label: "Vista principal",
    controls: [
      { label: "Texto general", variable: "--rd-font-base", defaultValue: 16, min: 8, max: 32, step: 1 },
      { label: "Encabezado", variable: "--rd-font-header", defaultValue: 12, min: 8, max: 28, step: 1 },
      { label: "Estado de balanza", variable: "--rd-font-scale-status", defaultValue: 10, min: 8, max: 24, step: 1 },
      { label: "Lectura principal", variable: "--rd-font-scale-reading", defaultValue: 68, min: 32, max: 120, step: 1 },
      { label: "Botón de captura", variable: "--rd-font-capture-button", defaultValue: 14, min: 9, max: 32, step: 1 },
      { label: "Etiquetas de panel", variable: "--rd-font-panel-label", defaultValue: 10, min: 8, max: 24, step: 1 },
      { label: "Valores de panel", variable: "--rd-font-panel-value", defaultValue: 16, min: 9, max: 36, step: 1 }
    ]
  },
  {
    label: "Selección del producto",
    controls: [
      { label: "Tipos de pollo", variable: "--rd-font-chicken-type", defaultValue: 14, min: 9, max: 32, step: 1 },
      { label: "Presentación y género", variable: "--rd-font-presentation", defaultValue: 14, min: 9, max: 32, step: 1 }
    ]
  },
  {
    label: "Listas de venta",
    controls: [
      { label: "Selector de lista", variable: "--rd-font-list-selector", defaultValue: 11, min: 8, max: 28, step: 1 },
      { label: "Encabezado de lista", variable: "--rd-font-list-header", defaultValue: 11, min: 8, max: 28, step: 1 },
      { label: "Cabecera de tabla", variable: "--rd-font-table-header", defaultValue: 9, min: 8, max: 24, step: 1 },
      { label: "Registros de tabla", variable: "--rd-font-table-cell", defaultValue: 11, min: 8, max: 28, step: 1 },
      { label: "Totales de lista", variable: "--rd-font-list-total", defaultValue: 10, min: 8, max: 28, step: 1 }
    ]
  },
  {
    label: "Acciones y estado",
    controls: [
      { label: "Acciones", variable: "--rd-font-actions", defaultValue: 10, min: 8, max: 28, step: 1 },
      { label: "Barra de estado", variable: "--rd-font-status-bar", defaultValue: 10, min: 8, max: 24, step: 1 }
    ]
  },
  {
    label: "Ventanas y configuración",
    controls: [
      { label: "Texto de ventanas", variable: "--rd-font-modal-text", defaultValue: 12, min: 8, max: 28, step: 1 },
      { label: "Títulos de ventanas", variable: "--rd-font-modal-title", defaultValue: 20, min: 12, max: 40, step: 1 },
      { label: "Campos de ventanas", variable: "--rd-font-modal-field", defaultValue: 12, min: 8, max: 30, step: 1 },
      { label: "Botones de ventanas", variable: "--rd-font-modal-button", defaultValue: 12, min: 8, max: 30, step: 1 }
    ]
  }
];
const TYPOGRAPHY_CONTROLS = TYPOGRAPHY_GROUPS.flatMap((group) => group.controls);

const elements = {
  station: document.querySelector("#retailStation"),
  form: document.querySelector("#retailWeighingForm"),
  branchName: document.querySelector("#retailBranchName"),
  clock: document.querySelector("#retailClock"),
  scaleTopStatus: document.querySelector("#retailScaleTopStatus"),
  openSettings: document.querySelector("#retailOpenSettings"),
  trayType: document.querySelector("#retailTrayType"),
  trayCount: document.querySelector("#retailTrayCount"),
  trayCountTrigger: document.querySelector("#retailTrayCountTrigger"),
  trayCountValue: document.querySelector("#retailTrayCountValue"),
  trayCountLabel: document.querySelector("#retailTrayCountLabel"),
  birdsPerTray: document.querySelector("#retailBirdsPerTray"),
  birdsPerTrayTrigger: document.querySelector("#retailBirdsPerTrayTrigger"),
  birdsPerTrayValue: document.querySelector("#retailBirdsPerTrayValue"),
  birdsPerTrayLabel: document.querySelector("#retailBirdsPerTrayLabel"),
  rawWeightInput: document.querySelector("#retailRawWeightInput"),
  manualWeightTrigger: document.querySelector("#retailManualWeightTrigger"),
  adjustedWeight: document.querySelector("#retailAdjustedWeight"),
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
  trayCountModal: document.querySelector("#retailTrayCountModal"),
  birdsPerTrayModal: document.querySelector("#retailBirdsPerTrayModal"),
  manualWeightModal: document.querySelector("#retailManualWeightModal"),
  manualWeightForm: document.querySelector("#retailManualWeightForm"),
  manualWeightEntry: document.querySelector("#retailManualWeightEntry"),
  clientModal: document.querySelector("#retailClientModal"),
  clientSearch: document.querySelector("#retailClientSearch"),
  clientOptions: document.querySelector("#retailClientOptions"),
  priceModal: document.querySelector("#retailPriceModal"),
  priceForm: document.querySelector("#retailPriceForm"),
  priceFields: document.querySelector("#retailPriceFields"),
  clearPrices: document.querySelector("#retailClearPrices"),
  paymentModal: document.querySelector("#retailPaymentModal"),
  paymentForm: document.querySelector("#retailPaymentForm"),
  paymentSummary: document.querySelector("#retailPaymentSummary"),
  paymentRows: document.querySelector("#retailPaymentRows"),
  addPayment: document.querySelector("#retailAddPayment"),
  paymentSaleTotal: document.querySelector("#retailPaymentSaleTotal"),
  paymentReceivedTotal: document.querySelector("#retailPaymentReceivedTotal"),
  paymentPendingTotal: document.querySelector("#retailPaymentPendingTotal"),
  paymentMessage: document.querySelector("#retailPaymentMessage"),
  skipPayment: document.querySelector("#retailSkipPayment"),
  confirmPayment: document.querySelector("#retailConfirmPayment"),
  deliveryModal: document.querySelector("#retailDeliveryModal"),
  deliveryForm: document.querySelector("#retailDeliveryForm"),
  deliverySummary: document.querySelector("#retailDeliverySummary"),
  deliveryTruck: document.querySelector("#retailDeliveryTruck"),
  deliveryDriver: document.querySelector("#retailDeliveryDriver"),
  deliveryMessage: document.querySelector("#retailDeliveryMessage"),
  confirmDelivery: document.querySelector("#retailConfirmDelivery"),
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
  saveSettings: document.querySelector("#retailSaveSettings"),
  openTypography: document.querySelector("#retailOpenTypography"),
  typographyDrawer: document.querySelector("#retailTypographyDrawer"),
  typographyControls: document.querySelector("#retailTypographyControls"),
  typographyReset: document.querySelector("#retailTypographyReset"),
  typographyClose: document.querySelector("#retailTypographyClose")
};

const state = {
  catalog: {
    branch: null,
    clients: [],
    general_prices: {},
    chicken_types: [],
    tray_types: [],
    delivery_trucks: [],
    delivery_drivers: [],
    adjustments: [],
    scale: null,
    financial: {
      methods: [],
      own_accounts: []
    }
  },
  activeList: 0,
  chickenType: null,
  sex: SEX_MALE,
  adjustmentCode: null,
  liveWeight: 0,
  liveSource: "manual",
  selectedItem: null,
  priceEditingListIndex: null,
  storageKey: null,
  lists: Array.from({ length: LIST_COUNT }, emptyList),
  scale: null,
  scaleState: null,
  loading: true,
  typography: {},
  pendingPayments: [],
  paymentRows: []
};
const modalFocusOrigins = new Map();

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

      const trayCount = Number(item.trayCount || 0);
      const birdsPerTray = Number(item.birdsPerTray || 0);
      const birds = trayCount * birdsPerTray;
      const adjustmentGrams = Number(adjustment.additional_grams || 0);
      const totalAdjustmentGrams = adjustmentGrams * birds;
      const grossWeight = roundWeight(Number(item.readWeight || 0) + totalAdjustmentGrams / 1000);
      const tareWeight = roundWeight(trayCount * Number(tray.weight_kg || 0));

      return {
        ...item,
        adjustmentName: adjustment.name,
        chickenSex: adjustment.sex,
        presentation: adjustment.presentation,
        adjustmentGrams,
        totalAdjustmentGrams,
        trayTypeName: tray.name,
        birds,
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

function priceEditingList() {
  const index = state.priceEditingListIndex;
  return Number.isInteger(index) && index >= 0 && index < LIST_COUNT
    ? state.lists[index]
    : activeList();
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

function currentGeneralPrice(chickenTypeCode) {
  const prices = state.catalog.general_prices || {};
  return normalizePriceRecord(prices[chickenTypeCode]);
}

function effectivePrice(list, chickenTypeCode) {
  const client = clientFor(list);
  if (client) return currentClientPrice(list, chickenTypeCode);

  const general = currentGeneralPrice(chickenTypeCode);
  if (!general) return null;

  const override = list.priceOverrides?.[chickenTypeCode];
  if (override !== undefined && override !== null && override !== "") {
    const value = Number(override);
    if (Number.isFinite(value)) return { value, source: "MANUAL" };
  }

  return general;
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

function trayQuantityLabel(value) {
  const quantity = Number(value || 0);
  if (quantity === 0) return "Sin bandejas";
  return `${quantity} bandeja${quantity === 1 ? "" : "s"}`;
}

function previewValues(readWeightOverride = null) {
  const tray = selectedTray();
  const adjustment = selectedAdjustment();
  const readWeight = Number(readWeightOverride ?? state.liveWeight ?? 0);
  const trayCount = Number(elements.trayCount.value || 0);
  const birdsPerTray = Number(elements.birdsPerTray.value || 0);
  const birds = trayCount * birdsPerTray;
  const adjustmentGrams = Number(adjustment?.additional_grams || 0);
  const totalAdjustmentGrams = adjustmentGrams * birds;
  const grossWeight = readWeight > 0
    ? roundWeight(readWeight + totalAdjustmentGrams / 1000)
    : 0;
  const tareWeight = roundWeight(trayCount * Number(tray?.weight_kg || 0));
  const netWeight = roundWeight(grossWeight - tareWeight);

  return {
    tray,
    adjustment,
    readWeight,
    trayCount,
    birdsPerTray,
    adjustmentGrams,
    totalAdjustmentGrams,
    grossWeight,
    tareWeight,
    netWeight,
    birds
  };
}

function renderWeightPreview() {
  const values = previewValues();
  const price = effectivePrice(activeList(), state.chickenType);
  const source = state.liveSource || "manual";
  const sourceLabels = {
    manual: "Ingreso manual",
    ble: "Balanza minorista · BLE",
    serial: "Balanza minorista · Serial"
  };

  elements.adjustedWeight.textContent = values.grossWeight.toFixed(3);
  elements.grossPreview.textContent = formatWeight(values.grossWeight);
  elements.tarePreview.textContent = formatWeight(values.tareWeight);
  elements.netPreview.textContent = formatWeight(Math.max(values.netWeight, 0));
  elements.netPreview.classList.toggle("is-invalid", values.readWeight > 0 && values.netWeight <= 0);
  elements.birdTotalPreview.textContent = values.trayCount === 0
    ? "Sin bandejas · 0 aves"
    : `${values.birds} ave${values.birds === 1 ? "" : "s"}`;
  elements.weightSourceLabel.textContent = sourceLabels[source] || "Balanza minorista";
  elements.captureState.textContent = "Peso en vivo";
  elements.captureState.classList.remove("is-captured");
  elements.captureWeight.classList.remove("is-captured");
  elements.captureWeight.lastChild.textContent = ` Capturar en lista ${state.activeList + 1}`;
  elements.captureWeight.setAttribute("aria-label", `Capturar el peso actual en la lista ${state.activeList + 1}`);
  const liveAmount = price && values.netWeight > 0 ? values.netWeight * price.value : null;
  elements.pricePreview.textContent = liveAmount === null ? "S/ --" : formatMoney(liveAmount);
  elements.priceSource.textContent = price
    ? `S/ ${price.value.toFixed(4)} por kg · ${price.source === "MANUAL" ? "puntual" : price.source.toLowerCase()}`
    : (clientFor() ? "Precio del cliente no configurado" : "Asigna un precio a la lista");
  elements.trayCountValue.textContent = values.trayCount;
  elements.trayCountLabel.textContent = values.trayCount === 0
    ? "sin bandejas"
    : `bandeja${values.trayCount === 1 ? "" : "s"}`;
  elements.trayCountTrigger.setAttribute("aria-label", values.trayCount === 0
    ? "Sin bandejas. Toca para cambiar la cantidad"
    : `${trayQuantityLabel(values.trayCount)}. Toca para cambiar la cantidad`);
  elements.birdsPerTrayValue.textContent = values.birdsPerTray;
  elements.birdsPerTrayLabel.textContent = `ave${values.birdsPerTray === 1 ? "" : "s"}`;
  elements.birdsPerTrayTrigger.setAttribute(
    "aria-label",
    `${values.birdsPerTray} ave${values.birdsPerTray === 1 ? "" : "s"} por bandeja. Toca para cambiar`
  );
  document.querySelectorAll("[data-retail-tray-option]").forEach((button) => {
    const selected = Number(button.dataset.retailTrayOption) === values.trayCount;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
  document.querySelectorAll("[data-retail-birds-per-tray-option]").forEach((button) => {
    const selected = Number(button.dataset.retailBirdsPerTrayOption) === values.birdsPerTray;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
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
    </button>
  `).join("");
}

function ensureAdjustmentSelection() {
  const available = state.catalog.adjustments;
  const current = available.find((adjustment) => adjustment.code === state.adjustmentCode);
  if (current) return;

  const preferred = available.find((adjustment) => adjustment.is_default)
    || available[0];
  state.adjustmentCode = preferred?.code || null;
  state.sex = preferred?.sex || SEX_MALE;
}

function renderAdjustments() {
  ensureAdjustmentSelection();
  const available = state.catalog.adjustments;

  elements.adjustments.innerHTML = available.map((adjustment) => `
    <button type="button" data-retail-adjustment="${escapeHtml(adjustment.code)}" class="${adjustment.code === state.adjustmentCode ? "is-active" : ""}" aria-pressed="${adjustment.code === state.adjustmentCode}">
      ${escapeHtml(adjustment.name)}
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
            <td>${Number(item.trayCount) === 0 ? '<span class="rd-no-trays">Sin bandeja</span>' : item.trayCount}</td>
            <td>${item.birds}</td>
            <td>${Number(item.netWeight).toFixed(3)}<small>S/ ${Number((effectivePrice(list, item.chickenTypeCode)?.value || 0) * item.netWeight).toFixed(2)}</small></td>
          </tr>
        `;
      }).join("")
      : '<tr><td colspan="4"><div class="rd-empty-list">Selecciona esta lista y captura un peso.</div></td></tr>';

    return `
      <article class="rd-list-card ${LIST_CLASSES[listIndex]} ${listIndex === state.activeList ? "is-active" : ""} ${list.saving ? "is-saving" : ""}" data-retail-list="${listIndex}" role="button" tabindex="0" aria-pressed="${listIndex === state.activeList}">
        <header class="rd-list-head">
          <span class="rd-list-number">${listIndex + 1}</span>
          <span class="rd-list-client"><strong>${escapeHtml(client?.name || "Venta sin cliente")}</strong><small>${totals.weighings} pesada${totals.weighings === 1 ? "" : "s"} · ${trayQuantityLabel(totals.trays)}</small></span>
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
    || missingPrices.length > 0;
  elements.saveDispatch.title = missingPrices.length
    ? (current.clientId
      ? "Configura en Directorio el precio del cliente para cada tipo de pollo antes de grabar."
      : "Asigna un precio a la lista para cada tipo de pollo antes de grabar.")
    : "Grabar la lista activa";
  elements.removeWeighing.disabled = current.saving
    || !state.selectedItem
    || state.selectedItem.listIndex !== state.activeList;

  document.querySelectorAll("[data-retail-operation]").forEach((button) => {
    const selected = button.dataset.retailOperation === current.operationType;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });

  document.querySelectorAll("[data-retail-add-list]").forEach((button) => {
    const selected = Number(button.dataset.retailAddList) === state.activeList;
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

  const capturedReading = {
    readWeight: roundWeight(state.liveWeight),
    source: state.liveSource || "manual",
    weighedAt: new Date().toISOString()
  };

  addWeighingToList(state.activeList, capturedReading);
}

function addWeighingToList(listIndex, capturedReading) {
  const targetIndex = Number(listIndex);
  const target = state.lists[targetIndex];
  const values = previewValues(capturedReading?.readWeight);
  const chickenType = selectedChickenType();

  if (!target || target.saving) return;
  if (!capturedReading || !Number.isFinite(capturedReading.readWeight) || capturedReading.readWeight <= 0) {
    return setMessage("La balanza debe mostrar un peso mayor que cero antes de capturarlo.", true);
  }
  if (!values.tray || !values.adjustment || !chickenType) {
    return setMessage("Los catálogos minoristas todavía no están disponibles.", true);
  }
  if (!Number.isInteger(values.trayCount) || values.trayCount < 0) {
    return setMessage("La cantidad de bandejas no puede ser negativa.", true);
  }
  if (!Number.isInteger(values.birdsPerTray) || values.birdsPerTray < 1 || values.birdsPerTray > 10) {
    return setMessage("Selecciona entre 1 y 10 aves por bandeja.", true);
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
    totalAdjustmentGrams: values.totalAdjustmentGrams,
    trayTypeCode: values.tray.code,
    trayTypeName: values.tray.name,
    trayCount: values.trayCount,
    birdsPerTray: values.birdsPerTray,
    birds: values.birds,
    readWeight: capturedReading.readWeight,
    grossWeight: values.grossWeight,
    tareWeight: values.tareWeight,
    netWeight: values.netWeight,
    weightSource: capturedReading.source === "manual" ? "MANUAL" : "BALANZA_MINORISTA",
    weighedAt: capturedReading.weighedAt
  });

  state.activeList = targetIndex;
  state.selectedItem = { listIndex: targetIndex, id: target.items.at(-1).id };
  if (capturedReading.source === "manual") {
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
  modalFocusOrigins.set(modal.id, document.activeElement);
  modal.hidden = false;
  elements.station.inert = true;
  elements.station.setAttribute("aria-hidden", "true");
  const focusTarget = modal.querySelector("input:not([disabled]), select:not([disabled]), button:not([disabled])");
  focusTarget?.focus();
}

function closeModal(modal) {
  if (!modal) return;
  modal.hidden = true;
  const hasOpenModal = [
    elements.trayCountModal,
    elements.birdsPerTrayModal,
    elements.manualWeightModal,
    elements.clientModal,
    elements.priceModal,
    elements.deliveryModal,
    elements.settingsModal
  ].some((entry) => entry && !entry.hidden);
  if (!hasOpenModal) {
    elements.station.inert = false;
    elements.station.removeAttribute("aria-hidden");
  }
  const origin = modalFocusOrigins.get(modal.id);
  modalFocusOrigins.delete(modal.id);
  origin?.focus?.();
}

function defaultTypographyValues() {
  return Object.fromEntries(
    TYPOGRAPHY_CONTROLS.map((control) => [control.variable, control.defaultValue])
  );
}

function typographyControl(variable) {
  return TYPOGRAPHY_CONTROLS.find((control) => control.variable === variable) || null;
}

function normalizeTypographyValue(control, value) {
  if ((typeof value !== "number" && typeof value !== "string") || String(value).trim() === "") {
    return control.defaultValue;
  }
  const numericValue = Number(value);
  if (!Number.isFinite(numericValue)) return control.defaultValue;

  const steppedValue = Math.round(numericValue / control.step) * control.step;
  return Math.min(control.max, Math.max(control.min, steppedValue));
}

function persistTypography() {
  try {
    localStorage.setItem(TYPOGRAPHY_STORAGE_KEY, JSON.stringify({
      version: 1,
      values: Object.fromEntries(TYPOGRAPHY_CONTROLS.map((control) => [
        control.variable,
        normalizeTypographyValue(control, state.typography[control.variable])
      ]))
    }));
  } catch {
    // La vista previa sigue funcionando aunque el navegador bloquee localStorage.
  }
}

function restoreTypography() {
  const defaults = defaultTypographyValues();

  try {
    const stored = JSON.parse(localStorage.getItem(TYPOGRAPHY_STORAGE_KEY));
    if (!stored || stored.version !== 1 || !stored.values || typeof stored.values !== "object") {
      return defaults;
    }

    return Object.fromEntries(TYPOGRAPHY_CONTROLS.map((control) => [
      control.variable,
      Object.hasOwn(stored.values, control.variable)
        ? normalizeTypographyValue(control, stored.values[control.variable])
        : control.defaultValue
    ]));
  } catch {
    try {
      localStorage.removeItem(TYPOGRAPHY_STORAGE_KEY);
    } catch {
      // El almacenamiento no es indispensable para utilizar la estación.
    }
    return defaults;
  }
}

function applyTypographyValue(variable, value) {
  const control = typographyControl(variable);
  if (!control) return;

  const normalized = normalizeTypographyValue(control, value);
  state.typography[variable] = normalized;
  document.documentElement.style.setProperty(variable, `${normalized}px`);
}

function applyTypography() {
  TYPOGRAPHY_CONTROLS.forEach((control) => {
    applyTypographyValue(control.variable, state.typography[control.variable]);
  });
}

function renderTypographyControls() {
  if (!elements.typographyControls) return;

  elements.typographyControls.innerHTML = TYPOGRAPHY_GROUPS.map((group, groupIndex) => `
    <section class="rd-typography-group" aria-labelledby="retailTypographyGroup${groupIndex}">
      <h3 id="retailTypographyGroup${groupIndex}">${escapeHtml(group.label)}</h3>
      ${group.controls.map((control, controlIndex) => {
        const inputId = `retailTypographyInput${groupIndex}-${controlIndex}`;
        const value = state.typography[control.variable];
        return `
          <label class="rd-typography-control" for="${inputId}">
            <span>${escapeHtml(control.label)}</span>
            <div class="rd-typography-stepper">
              <button type="button" data-typography-step="-1" data-typography-variable="${control.variable}" aria-label="Disminuir ${escapeHtml(control.label)}">&minus;</button>
              <input id="${inputId}" type="number" min="${control.min}" max="${control.max}" step="${control.step}" value="${value}" inputmode="numeric" data-typography-variable="${control.variable}" aria-label="${escapeHtml(control.label)} en píxeles">
              <button type="button" data-typography-step="1" data-typography-variable="${control.variable}" aria-label="Aumentar ${escapeHtml(control.label)}">&plus;</button>
            </div>
          </label>
        `;
      }).join("")}
    </section>
  `).join("") + '<p class="rd-typography-saved-note" role="status">Guardado automáticamente en este navegador</p>';
}

function syncTypographyInputs() {
  elements.typographyControls?.querySelectorAll("input[data-typography-variable]").forEach((input) => {
    const control = typographyControl(input.dataset.typographyVariable);
    if (!control) return;
    input.value = normalizeTypographyValue(control, state.typography[control.variable]);
  });
}

function updateTypographyFromInput(input, commit = false) {
  const control = typographyControl(input.dataset.typographyVariable);
  if (!control) return;

  const rawValue = String(input.value).trim();
  if (!rawValue || !Number.isFinite(Number(rawValue))) {
    if (commit) input.value = state.typography[control.variable];
    return;
  }

  const value = normalizeTypographyValue(control, rawValue);
  applyTypographyValue(control.variable, value);
  persistTypography();
  if (commit) input.value = value;
}

function stepTypography(button) {
  const control = typographyControl(button.dataset.typographyVariable);
  if (!control) return;

  const direction = Number(button.dataset.typographyStep);
  if (direction !== -1 && direction !== 1) return;

  const value = normalizeTypographyValue(
    control,
    Number(state.typography[control.variable]) + direction * control.step
  );
  applyTypographyValue(control.variable, value);
  syncTypographyInputs();
  persistTypography();
}

function openTypographyDrawer() {
  if (!elements.typographyDrawer) return;
  closeModal(elements.settingsModal);
  elements.typographyDrawer.hidden = false;
  elements.typographyDrawer.setAttribute("aria-hidden", "false");
  elements.openTypography?.setAttribute("aria-expanded", "true");
  elements.typographyClose?.focus();
}

function closeTypographyDrawer() {
  if (!elements.typographyDrawer || elements.typographyDrawer.hidden) return;
  elements.typographyDrawer.hidden = true;
  elements.typographyDrawer.setAttribute("aria-hidden", "true");
  elements.openTypography?.setAttribute("aria-expanded", "false");
  elements.openSettings?.focus();
}

function resetTypography() {
  state.typography = defaultTypographyValues();
  applyTypography();
  syncTypographyInputs();
  try {
    localStorage.removeItem(TYPOGRAPHY_STORAGE_KEY);
  } catch {
    // Los valores predeterminados ya quedaron aplicados en esta sesión.
  }
}

function initializeTypography() {
  state.typography = restoreTypography();
  applyTypography();
  renderTypographyControls();
  persistTypography();
}

function renderClientOptions(search = "") {
  const normalized = String(search || "").trim().toLocaleLowerCase("es");
  const clients = state.catalog.clients.filter((client) => {
    if (!normalized) return true;
    return `${client.name || ""} ${client.document || ""}`.toLocaleLowerCase("es").includes(normalized);
  });

  const withoutClient = `
    <button class="rd-client-option ${activeList().clientId ? "" : "is-selected"}" type="button" data-retail-clear-client role="option" aria-selected="${!activeList().clientId}">
      <span><strong>Venta sin cliente</strong><small>Persona externa no registrada</small></span>
      <b>${activeList().clientId ? "Seleccionar" : "Seleccionado"}</b>
    </button>
  `;
  elements.clientOptions.innerHTML = withoutClient + (clients.length
    ? clients.map((client) => {
      const selected = String(client.id) === String(activeList().clientId);
      return `
        <button class="rd-client-option ${selected ? "is-selected" : ""}" type="button" data-retail-client="${client.id}" role="option" aria-selected="${selected}">
          <span><strong>${escapeHtml(client.name)}</strong><small>${escapeHtml(client.document || "Sin documento")}</small></span>
          <b>${selected ? "Seleccionado" : "Asignar"}</b>
        </button>
      `;
    }).join("")
    : '<p class="rd-empty-list">No hay clientes que coincidan con la búsqueda.</p>');
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
  persistLists();
  closeModal(elements.clientModal);
  renderAll();
  setMessage(`${client.name} asignado a la lista ${state.activeList + 1}.`);
}

function clearClient() {
  activeList().clientId = "";
  persistLists();
  closeModal(elements.clientModal);
  renderAll();
  setMessage(`La lista ${state.activeList + 1} quedó como venta sin cliente.`);
}

function renderPriceFields() {
  const list = priceEditingList();
  const client = clientFor(list);
  elements.priceFields.innerHTML = state.catalog.chicken_types.map((type) => {
    const vigente = client
      ? currentClientPrice(list, type.code)
      : currentGeneralPrice(type.code);
    const current = client || !vigente ? "" : list.priceOverrides?.[type.code];
    return `
      <label class="rd-price-field">
        <span>${escapeHtml(type.name)}</span>
        <input type="number" min="0.0001" max="99999999.9999" step="0.0001" inputmode="decimal" data-retail-price-code="${escapeHtml(type.code)}" value="${current ?? ""}" placeholder="${vigente ? vigente.value.toFixed(4) : "Sin precio base"}" ${client || !vigente ? "disabled" : ""}>
        <small>${client
          ? (vigente
            ? `Precio del cliente: S/ ${vigente.value.toFixed(4)} · ${escapeHtml(vigente.source)}`
            : "Este cliente no tiene un precio vigente configurado en Directorio")
          : (current
            ? `Precio personalizado de la lista: S/ ${Number(current).toFixed(4)}`
            : (vigente
              ? `Precio general vigente: S/ ${vigente.value.toFixed(4)} · ${escapeHtml(vigente.source)}`
              : "Configura primero el precio general vigente en Directorio"))}</small>
      </label>
    `;
  }).join("");

  elements.priceForm.querySelector(".rd-modal-copy").textContent = client
    ? `Se usarán siempre los precios vigentes de ${client.name}; los precios personalizados de la lista no los reemplazan.`
    : `Venta sin cliente en la lista ${state.priceEditingListIndex + 1}. Deja un campo vacío para usar el precio general vigente.`;
  elements.clearPrices.disabled = Boolean(client);
  elements.priceForm.querySelector('[type="submit"]').disabled = Boolean(client);
}

function openPriceModal() {
  state.priceEditingListIndex = state.activeList;
  renderPriceFields();
  openModal(elements.priceModal);
  const firstInput = elements.priceFields.querySelector("input");
  firstInput?.focus();
}

function applyPrices(event) {
  event.preventDefault();
  const listIndex = state.priceEditingListIndex;
  const list = priceEditingList();
  if (clientFor(list)) {
    setMessage("Los precios vigentes del cliente tienen prioridad y no se pueden reemplazar desde la lista.", true);
    return;
  }
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

  list.priceOverrides = prices;
  persistLists();
  closeModal(elements.priceModal);
  renderAll();
  setMessage(Object.keys(prices).length
    ? `Precios personalizados aplicados a la lista ${listIndex + 1}.`
    : `La lista ${listIndex + 1} quedó sin precios personalizados.`);
}

function clearPriceOverrides() {
  priceEditingList().priceOverrides = {};
  persistLists();
  renderPriceFields();
}

function requiresDelivery(list) {
  return list.operationType === OPERATION_SALE
    && Boolean(clientFor(list))
    && list.items.some((item) => Number(item.trayCount || 0) > 0);
}

function paymentMethodOptions(selectedId = "") {
  return (state.catalog.financial.methods || []).map((method) => `
    <option value="${Number(method.id)}" ${String(method.id) === String(selectedId) ? "selected" : ""}>
      ${escapeHtml(method.name)}
    </option>
  `).join("");
}

function paymentAccountOptions(selectedId = "") {
  return (state.catalog.financial.own_accounts || []).map((account) => {
    const detail = [account.entity?.name, account.alias, account.bank, account.masked_number]
      .filter(Boolean)
      .join(" · ");
    return `
      <option value="${Number(account.id)}" ${String(account.id) === String(selectedId) ? "selected" : ""}>
        ${escapeHtml(detail)}
      </option>
    `;
  }).join("");
}

function newPaymentRow(amount = 0) {
  const methods = state.catalog.financial.methods || [];
  const accounts = state.catalog.financial.own_accounts || [];

  return {
    key: createDraftId(),
    methodId: methods[0]?.id || "",
    accountId: accounts[0]?.id || "",
    amount: Math.max(0, Number(amount || 0)).toFixed(2),
    reference: ""
  };
}

function renderPaymentRows() {
  elements.paymentRows.innerHTML = state.paymentRows.map((row, index) => `
    <article class="rd-payment-row" data-payment-row="${escapeHtml(row.key)}">
      <div class="rd-payment-row-head">
        <strong>Forma de pago ${index + 1}</strong>
        <button type="button" data-remove-payment-row="${escapeHtml(row.key)}" ${state.paymentRows.length === 1 ? "hidden" : ""}>Quitar</button>
      </div>
      <div class="rd-payment-fields">
        <label>
          <span>Método</span>
          <select data-payment-method required>${paymentMethodOptions(row.methodId)}</select>
        </label>
        <label>
          <span>Cuenta o caja receptora</span>
          <select data-payment-account required>${paymentAccountOptions(row.accountId)}</select>
        </label>
        <label>
          <span>Importe</span>
          <input data-payment-amount type="number" min="0.01" step="0.01" inputmode="decimal" value="${escapeHtml(row.amount)}" required>
        </label>
        <label>
          <span>Referencia</span>
          <input data-payment-reference type="text" maxlength="100" value="${escapeHtml(row.reference)}" placeholder="Número de operación">
        </label>
      </div>
    </article>
  `).join("");
  renderPaymentTotals();
}

function syncPaymentRowsFromForm() {
  elements.paymentRows.querySelectorAll("[data-payment-row]").forEach((rowElement) => {
    const row = state.paymentRows.find((entry) => entry.key === rowElement.dataset.paymentRow);
    if (!row) return;
    row.methodId = rowElement.querySelector("[data-payment-method]")?.value || "";
    row.accountId = rowElement.querySelector("[data-payment-account]")?.value || "";
    row.amount = rowElement.querySelector("[data-payment-amount]")?.value || "";
    row.reference = rowElement.querySelector("[data-payment-reference]")?.value || "";
  });
}

function paymentReceivedTotal() {
  return state.paymentRows.reduce((sum, row) => sum + Math.max(0, Number(row.amount || 0)), 0);
}

function renderPaymentTotals() {
  syncPaymentRowsFromForm();
  const total = Number(listTotals(activeList()).amount || 0);
  const received = paymentReceivedTotal();
  const pending = Math.max(0, total - received);
  elements.paymentSaleTotal.textContent = formatMoney(total);
  elements.paymentReceivedTotal.textContent = formatMoney(received);
  elements.paymentPendingTotal.textContent = formatMoney(pending);
  elements.paymentPendingTotal.classList.toggle("is-settled", pending < 0.005);
}

function openPaymentModal() {
  const list = activeList();
  const totals = listTotals(list);
  const client = clientFor(list);
  const methods = state.catalog.financial.methods || [];
  const accounts = state.catalog.financial.own_accounts || [];

  state.paymentRows = [newPaymentRow(totals.amount)];
  elements.paymentSummary.textContent = client
    ? `${client.name} puede pagar ahora total o parcialmente; el resto quedará en su cuenta por cobrar.`
    : "La venta no tiene un cliente identificado y debe quedar pagada completamente.";
  elements.skipPayment.hidden = !client;
  elements.addPayment.disabled = methods.length === 0 || accounts.length === 0;
  elements.confirmPayment.disabled = methods.length === 0 || accounts.length === 0;
  elements.paymentMessage.textContent = methods.length && accounts.length
    ? "Puedes dividir el cobro entre efectivo, transferencia u otros métodos."
    : "Primero registra una entidad propia y al menos una cuenta o caja desde Finanzas.";
  elements.paymentMessage.classList.toggle("is-error", methods.length === 0 || accounts.length === 0);
  renderPaymentRows();
  openModal(elements.paymentModal);
}

function continueDispatchAfterPayment(payments) {
  const list = activeList();
  state.pendingPayments = payments;

  if (!requiresDelivery(list)) {
    void saveDispatch(null, payments);
    return;
  }

  renderDeliveryOptions(list);
  openModal(elements.deliveryModal);
}

function submitPayment(event) {
  event.preventDefault();
  syncPaymentRowsFromForm();
  const list = activeList();
  const saleTotal = Number(listTotals(list).amount || 0);
  const received = paymentReceivedTotal();
  const client = clientFor(list);

  if (state.paymentRows.some((row) => !Number(row.methodId) || !Number(row.accountId) || Number(row.amount) <= 0)) {
    elements.paymentMessage.textContent = "Completa método, cuenta e importe en cada forma de pago.";
    elements.paymentMessage.classList.add("is-error");
    return;
  }
  if (received > saleTotal + 0.005) {
    elements.paymentMessage.textContent = "El total recibido no puede superar el importe de la venta.";
    elements.paymentMessage.classList.add("is-error");
    return;
  }
  if (!client && Math.abs(received - saleTotal) >= 0.005) {
    elements.paymentMessage.textContent = "La venta sin cliente debe quedar pagada completamente.";
    elements.paymentMessage.classList.add("is-error");
    return;
  }

  const methods = new Map((state.catalog.financial.methods || []).map((method) => [String(method.id), method]));
  const missingReference = state.paymentRows.some((row) => {
    const method = methods.get(String(row.methodId));
    return method?.requires_reference && !String(row.reference || "").trim();
  });
  if (missingReference) {
    elements.paymentMessage.textContent = "Ingresa la referencia de cada depósito o transferencia.";
    elements.paymentMessage.classList.add("is-error");
    return;
  }

  const payments = state.paymentRows.map((row) => ({
    idempotency_key: row.key,
    metodo_pago_id: Number(row.methodId),
    cuenta_destino_id: Number(row.accountId),
    moneda: "PEN",
    importe: Number(Number(row.amount).toFixed(2)),
    referencia: String(row.reference || "").trim() || null
  }));
  closeModal(elements.paymentModal);
  continueDispatchAfterPayment(payments);
}

function skipPayment() {
  if (!clientFor(activeList())) return;
  state.paymentRows = [];
  closeModal(elements.paymentModal);
  continueDispatchAfterPayment([]);
}

function addPaymentRow() {
  if (state.paymentRows.length >= 5) return;
  syncPaymentRowsFromForm();
  const remaining = Math.max(0, Number(listTotals(activeList()).amount || 0) - paymentReceivedTotal());
  state.paymentRows.push(newPaymentRow(remaining));
  renderPaymentRows();
}

function setDeliveryMessage(message = "", isError = false) {
  elements.deliveryMessage.textContent = message;
  elements.deliveryMessage.classList.toggle("is-error", Boolean(isError));
}

function renderDeliveryOptions(list) {
  const trucks = state.catalog.delivery_trucks || [];
  const drivers = state.catalog.delivery_drivers || [];
  const client = clientFor(list);
  const totals = listTotals(list);

  elements.deliverySummary.textContent = `${client?.name || "Cliente"} · ${trayQuantityLabel(totals.trays)}. Selecciona quién transportará la mercancía antes de imprimir.`;
  elements.deliveryTruck.innerHTML = `
    <option value="">Selecciona un camión</option>
    ${trucks.map((truck) => {
      const detail = truck.detail || [truck.brand, truck.model, truck.color, truck.description].filter(Boolean).join(" · ");
      return `<option value="${Number(truck.id)}">${escapeHtml(truck.plate)}${detail ? ` · ${escapeHtml(detail)}` : ""}</option>`;
    }).join("")}
  `;
  elements.deliveryDriver.innerHTML = `
    <option value="">Selecciona un chofer</option>
    ${drivers.map((driver) => {
      const document = driver.document || [driver.document_type, driver.document_number].filter(Boolean).join(" ");
      return `<option value="${Number(driver.id)}">${escapeHtml(driver.name)}${document ? ` · ${escapeHtml(document)}` : ""}</option>`;
    }).join("")}
  `;

  const fleetReady = trucks.length > 0 && drivers.length > 0;
  elements.confirmDelivery.disabled = !fleetReady;
  setDeliveryMessage(fleetReady
    ? "El camión y el chofer quedarán vinculados al ticket para consultar la trazabilidad de las bandejas."
    : "Debes registrar al menos un camión y un chofer activos en Mi flota antes de completar este despacho.", !fleetReady);
}

function prepareDispatchRegistration() {
  const list = activeList();
  if (!list || list.saving || !list.items.length) return;
  if (missingPriceTypes(list).length) {
    setMessage(list.clientId
      ? "Falta un precio vigente del cliente para uno o más tipos de pollo de esta lista."
      : "Falta el precio general o personalizado para uno o más tipos de pollo de esta lista.", true);
    return;
  }

  if (list.operationType === OPERATION_RETURN) {
    continueDispatchAfterPayment([]);
    return;
  }

  openPaymentModal();
}

function submitDelivery(event) {
  event.preventDefault();
  const vehicleId = Number(elements.deliveryTruck.value);
  const driverId = Number(elements.deliveryDriver.value);

  if (!Number.isInteger(vehicleId) || vehicleId < 1 || !Number.isInteger(driverId) || driverId < 1) {
    setDeliveryMessage("Selecciona el camión y el chofer responsables de llevar las bandejas.", true);
    return;
  }

  closeModal(elements.deliveryModal);
  void saveDispatch({ vehicle_id: vehicleId, driver_id: driverId }, state.pendingPayments);
}

function printedChickenTypeCode(code) {
  return ({
    POLLO_VIVO: "PV",
    POLLO_PELADO: "PP",
    POLLO_BENEFICIADO: "PB",
    POLLO_MUERTO: "PM"
  })[code] || code || "PV";
}

function buildRetailTicketPrintData(ticket) {
  return {
    code: ticket.code,
    channel: ticket.channel,
    operationType: ticket.operation_type,
    destinationName: ticket.client?.name || "Venta externa",
    customerKind: ticket.client?.id ? "CLIENTE_REGISTRADO" : "VENTA_EXTERNA",
    emittedAt: ticket.registered_at,
    totalAmount: ticket.totals?.amount,
    delivery: ticket.delivery,
    records: (ticket.weighings || []).map((weighing) => ({
      typeCode: printedChickenTypeCode(weighing.chicken_type_code),
      birdsPerCage: Number(weighing.birds_per_tray) || 0,
      cages: Number(weighing.tray_count) || 0,
      grossWeight: Number(weighing.gross_weight_kg) || 0,
      tareWeight: Number(weighing.tare_weight_kg) || 0,
      netWeight: Number(weighing.net_weight_kg) || 0,
      priceKg: Number(weighing.price_kg) || 0,
      amount: Number(weighing.amount) || 0
    }))
  };
}

function showRegisteredTicket(ticket) {
  const operationLabel = ticket.operation_type === OPERATION_RETURN ? "Devolución" : "Venta";
  elements.lastTicket.hidden = false;
  elements.lastTicket.innerHTML = `
    <span><strong>${escapeHtml(operationLabel)} ${escapeHtml(ticket.code)}</strong><br>${escapeHtml(ticket.client?.name || "Venta sin cliente")}</span>
    <span>${trayQuantityLabel(ticket.totals?.trays)} · ${formatWeight(ticket.totals?.net_weight_kg || 0)} · <strong>${formatMoney(ticket.totals?.amount || 0)}</strong></span>
  `;
  globalThis.setTimeout(() => {
    elements.lastTicket.hidden = true;
  }, 8000);
}

function clearRegisteredList(listIndex, draftId, ticket) {
  const current = state.lists[listIndex];
  if (!current || current.draftId !== draftId) return;

  state.lists[listIndex] = emptyList();
  if (state.selectedItem?.listIndex === listIndex) state.selectedItem = null;
  persistLists();
  renderAll();
  showRegisteredTicket(ticket);
  setMessage(`${ticket.code} impreso o enviado a PDF. La lista ${listIndex + 1} quedó lista para un nuevo despacho.`);
}

function printRegisteredTicket(ticket, listIndex, draftId) {
  return new Promise((resolve, reject) => {
    printWeightControlTicket(buildRetailTicketPrintData(ticket), {
      frameTitle: `Impresión de ${ticket.code}`,
      onSuccess: () => {
        clearRegisteredList(listIndex, draftId, ticket);
        resolve();
      },
      onError: () => reject(new Error("El ticket quedó guardado, pero no se pudo abrir la impresión. Presiona Grabar para intentarlo nuevamente."))
    });
  });
}

async function saveDispatch(delivery = null, payments = []) {
  const listIndex = state.activeList;
  const list = state.lists[listIndex];
  if (!list || list.saving || !list.items.length) return;
  if (missingPriceTypes(list).length) {
    setMessage(list.clientId
      ? "Falta un precio vigente del cliente para uno o más tipos de pollo de esta lista."
      : "Falta el precio general o personalizado para uno o más tipos de pollo de esta lista.", true);
    return;
  }

  list.saving = true;
  renderLists();
  setMessage(`Grabando ${list.operationType === OPERATION_RETURN ? "devolución" : "venta"} de la lista ${listIndex + 1}...`);

  try {
    const priceOverrides = list.clientId
      ? {}
      : Object.fromEntries(
        Object.entries(list.priceOverrides || {})
          .filter(([, value]) => Number.isFinite(Number(value)) && Number(value) > 0)
          .map(([code, value]) => [code, Number(value)])
      );
    const response = await apiRequest("/despacho-minorista/tickets", {
      method: "POST",
      body: JSON.stringify({
        draft_id: list.draftId,
        operation_type: list.operationType,
        client_id: list.clientId ? Number(list.clientId) : null,
        delivery,
        payments,
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
    state.pendingPayments = [];
    state.paymentRows = [];
    setMessage(`${response.message || "Despacho minorista registrado correctamente."} Abriendo impresión; también puedes elegir Guardar como PDF.`);
    await printRegisteredTicket(ticket, listIndex, list.draftId);
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
        <span>Gramos/pollo</span>
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
    elements.rawWeightInput.value = state.liveWeight.toFixed(3);
    renderWeightPreview();
    setSettingsMessage(`Lectura manual aplicada: ${formatWeight(state.liveWeight)}.`);
  } catch (error) {
    setSettingsMessage(error.message, true);
  }
}

function openManualWeightModal() {
  elements.manualWeightEntry.value = state.liveWeight > 0 ? state.liveWeight.toFixed(3) : "";
  openModal(elements.manualWeightModal);
  elements.manualWeightEntry.focus();
}

function applyMainManualWeight(event) {
  event.preventDefault();
  try {
    const scaleState = state.scale.setManualReading(elements.manualWeightEntry.value);
    state.liveWeight = scaleState.currentWeightKg;
    state.liveSource = "manual";
    elements.rawWeightInput.value = state.liveWeight.toFixed(3);
    closeModal(elements.manualWeightModal);
    renderWeightPreview();
    setMessage(`Peso manual recibido. La pantalla muestra ${formatWeight(previewValues().grossWeight)} con el ajuste.`);
  } catch (error) {
    setMessage(error.message, true);
    elements.manualWeightEntry.focus();
  }
}

function normalizeCatalog(data) {
  const adjustments = Array.isArray(data.adjustments) ? data.adjustments : [];
  const financial = data.financial && typeof data.financial === "object"
    ? data.financial
    : {};
  return {
    branch: data.branch || null,
    clients: Array.isArray(data.clients) ? data.clients : [],
    general_prices: data.general_prices && typeof data.general_prices === "object"
      ? { ...data.general_prices }
      : {},
    chicken_types: Array.isArray(data.chicken_types) ? data.chicken_types : [],
    tray_types: Array.isArray(data.tray_types) ? data.tray_types : [],
    delivery_trucks: Array.isArray(data.delivery_trucks) ? data.delivery_trucks : [],
    delivery_drivers: Array.isArray(data.delivery_drivers) ? data.delivery_drivers : [],
    adjustments: adjustments.map((adjustment) => ({
      ...adjustment,
      sex: String(adjustment.sex || SEX_MALE).toUpperCase(),
      presentation: String(adjustment.presentation || "CERRADO").toUpperCase(),
      additional_grams: Number(adjustment.additional_grams || 0),
      is_default: Boolean(adjustment.is_default)
    })),
    scale: data.scale || null,
    financial: {
      methods: Array.isArray(financial.methods) ? financial.methods : [],
      own_accounts: Array.isArray(financial.own_accounts) ? financial.own_accounts : []
    }
  };
}

function renderTrayOptions() {
  elements.trayType.innerHTML = state.catalog.tray_types.map((tray) => `
    <option value="${escapeHtml(tray.code)}">${escapeHtml(tray.name)} · tara ${Number(tray.weight_kg || 0).toFixed(3)} kg</option>
  `).join("");
  const tray = selectedTray();
  if (tray?.bird_capacity) {
    elements.birdsPerTray.value = Math.min(10, Math.max(1, Math.round(Number(tray.bird_capacity))));
  }
}

async function loadCatalog() {
  state.loading = true;
  renderLists();
  try {
    const response = await apiRequest("/despacho-minorista/catalogo");
    state.catalog = normalizeCatalog(response.data || {});
    const branchId = state.catalog.branch?.id || "default";
    restoreLists(branchId);
    recalculateDraftItems();
    elements.branchName.textContent = state.catalog.branch?.name || "Sucursal sin nombre";
    renderTrayOptions();

    const defaultAdjustment = state.catalog.adjustments.find((adjustment) => adjustment.is_default)
      || state.catalog.adjustments[0];
    if (defaultAdjustment) {
      state.sex = defaultAdjustment.sex;
      state.adjustmentCode = defaultAdjustment.code;
    }
    state.chickenType = state.catalog.chicken_types[0]?.code || null;

    state.scale.setStorageKey(`${RETAIL_SCALE_STORAGE_KEY}-branch-${branchId}`, { reload: true });

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
    setMessage("Estación minorista lista. Selecciona una lista y captura el peso para agregarlo directamente.");
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
    elements.rawWeightInput.value = state.liveWeight.toFixed(3);
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

elements.trayCount.addEventListener("input", renderWeightPreview);
elements.birdsPerTray.addEventListener("input", renderWeightPreview);
elements.trayType.addEventListener("change", () => {
  const tray = selectedTray();
  if (tray?.bird_capacity) {
    elements.birdsPerTray.value = Math.min(10, Math.max(1, Math.round(Number(tray.bird_capacity))));
  }
  renderWeightPreview();
});
elements.trayCountTrigger.addEventListener("click", () => openModal(elements.trayCountModal));
elements.birdsPerTrayTrigger.addEventListener("click", () => openModal(elements.birdsPerTrayModal));
elements.manualWeightTrigger.addEventListener("click", openManualWeightModal);
elements.manualWeightForm.addEventListener("submit", applyMainManualWeight);
elements.captureWeight.addEventListener("click", captureWeight);
elements.assignClient.addEventListener("click", openClientModal);
elements.removeWeighing.addEventListener("click", removeSelectedWeighing);
elements.assignPrice.addEventListener("click", openPriceModal);
elements.saveDispatch.addEventListener("click", prepareDispatchRegistration);
elements.clientSearch.addEventListener("input", () => renderClientOptions(elements.clientSearch.value));
elements.priceForm.addEventListener("submit", applyPrices);
elements.clearPrices.addEventListener("click", clearPriceOverrides);
elements.paymentForm.addEventListener("submit", submitPayment);
elements.addPayment.addEventListener("click", addPaymentRow);
elements.skipPayment.addEventListener("click", skipPayment);
elements.paymentRows.addEventListener("input", renderPaymentTotals);
elements.paymentRows.addEventListener("change", renderPaymentTotals);
elements.deliveryForm.addEventListener("submit", submitDelivery);
elements.openSettings.addEventListener("click", () => {
  fillSettingsForm();
  openModal(elements.settingsModal);
});
elements.openTypography?.addEventListener("click", openTypographyDrawer);
elements.typographyClose?.addEventListener("click", closeTypographyDrawer);
elements.typographyReset?.addEventListener("click", resetTypography);
elements.typographyControls?.addEventListener("click", (event) => {
  const stepButton = event.target.closest("button[data-typography-step]");
  if (stepButton) stepTypography(stepButton);
});
elements.typographyControls?.addEventListener("input", (event) => {
  if (event.target.matches("input[data-typography-variable]")) {
    updateTypographyFromInput(event.target);
  }
});
elements.typographyControls?.addEventListener("change", (event) => {
  if (event.target.matches("input[data-typography-variable]")) {
    updateTypographyFromInput(event.target, true);
  }
});
elements.settingsForm.addEventListener("submit", saveSettings);
elements.connectBle.addEventListener("click", connectBle);
elements.connectSerial.addEventListener("click", connectSerial);
elements.disconnectScale.addEventListener("click", disconnectScale);
elements.applyManualScale.addEventListener("click", applyManualScaleReading);

document.addEventListener("click", (event) => {
  const closeTypographyButton = event.target.closest("[data-retail-close-typography]");
  if (closeTypographyButton) {
    closeTypographyDrawer();
    return;
  }

  const removePaymentButton = event.target.closest("[data-remove-payment-row]");
  if (removePaymentButton) {
    syncPaymentRowsFromForm();
    state.paymentRows = state.paymentRows.filter((row) => row.key !== removePaymentButton.dataset.removePaymentRow);
    renderPaymentRows();
    return;
  }

  const trayOption = event.target.closest("[data-retail-tray-option]");
  if (trayOption) {
    elements.trayCount.value = trayOption.dataset.retailTrayOption;
    closeModal(elements.trayCountModal);
    renderWeightPreview();
    return;
  }

  const birdsOption = event.target.closest("[data-retail-birds-per-tray-option]");
  if (birdsOption) {
    elements.birdsPerTray.value = birdsOption.dataset.retailBirdsPerTrayOption;
    closeModal(elements.birdsPerTrayModal);
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
    state.sex = selectedAdjustment()?.sex || SEX_MALE;
    renderAdjustments();
    renderWeightPreview();
    return;
  }

  const addButton = event.target.closest("[data-retail-add-list]");
  if (addButton) {
    selectList(addButton.dataset.retailAddList);
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

  const clearClientButton = event.target.closest("[data-retail-clear-client]");
  if (clearClientButton) {
    clearClient();
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
    closeTypographyDrawer();
    [
      elements.trayCountModal,
      elements.birdsPerTrayModal,
      elements.manualWeightModal,
      elements.clientModal,
      elements.priceModal,
      elements.paymentModal,
      elements.deliveryModal,
      elements.settingsModal
    ]
      .filter((modal) => !modal.hidden)
      .forEach(closeModal);
  }

  if (event.key === "Tab") {
    const openModalElement = [
      elements.trayCountModal,
      elements.birdsPerTrayModal,
      elements.manualWeightModal,
      elements.clientModal,
      elements.priceModal,
      elements.paymentModal,
      elements.deliveryModal,
      elements.settingsModal
    ].find((modal) => modal && !modal.hidden);
    if (openModalElement) {
      const focusable = [...openModalElement.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )].filter((element) => !element.hidden);
      if (focusable.length) {
        const first = focusable[0];
        const last = focusable.at(-1);
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    }
  }

  if ((event.key === "Enter" || event.key === " ") && event.target.matches("[data-retail-item]")) {
    event.preventDefault();
    event.target.click();
  }

  if ((event.key === "Enter" || event.key === " ") && event.target.matches("[data-retail-list]")) {
    event.preventDefault();
    selectList(event.target.dataset.retailList);
  }
});

globalThis.addEventListener("beforeunload", () => {
  persistLists();
  void state.scale.destroy();
});

initializeTypography();
updateClock();
globalThis.setInterval(updateClock, 1000);
renderAll();
renderScaleStatus(state.scale.getState());
loadCatalog();
