import { apiRequest } from "./api-client.js";
import {
  getRetailDispatchErrorPresentation,
  getRetailLocalActionErrorPresentation
} from "./retail-dispatch-errors.js";
import {
  buildRetailTicketPrintData,
  printWeightControlTicket
} from "./ticket-printer.js";
import {
  normalizeRetailPaymentDefaultId,
  resolveRetailPaymentDefaults
} from "./retail-payment-defaults.js";
import {
  paymentsForRetailPaymentMode,
  resolveRetailPaymentMode,
  RETAIL_PAYMENT_MODE_CREDIT,
  RETAIL_PAYMENT_MODE_NOW
} from "./retail-payment-mode.js";
import {
  buildRetailDeliveryPayload,
  resolveRetailDeliveryMode,
  RETAIL_DELIVERY_MODE_COMPANY_TRUCK,
  RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP
} from "./retail-delivery-mode.js";
import {
  calculateRetailWeightAdjustment,
  RETAIL_PROCESSED_CHICKEN_CODE
} from "./retail-weight-calculation.js";
import {
  buildRetailScaleStorageKey,
  RetailScaleController,
  migrateLegacyRetailScaleStorage,
  RETAIL_SCALE_SERIAL_DEFAULTS
} from "./despacho-minorista-balanza.js";
import {
  buildRetailCustomerDisplayChannelName,
  buildRetailCustomerDisplayPayload,
  buildRetailCustomerDisplayStorageKey,
  resolveRetailCustomerDisplayWeights,
  RETAIL_CUSTOMER_DISPLAY_REQUEST_TYPE,
  RETAIL_CUSTOMER_DISPLAY_RESET_TYPE
} from "./retail-customer-display.js";
import { newestRecordsFirst } from "./record-order.js";

const retailStationElement = document.querySelector("#retailStation");
const RETAIL_STATION = String(retailStationElement?.dataset.retailStation || "1");
const RETAIL_API_BASE = String(retailStationElement?.dataset.retailApiBase || "/despacho-minorista");
const RETAIL_CUSTOMER_DISPLAY_PRODUCER_SESSION_KEY = `sistema-pollos-pantalla-cliente-minorista-${RETAIL_STATION}-productor-v1`;
const RETAIL_CUSTOMER_DISPLAY_INSTANCE_SESSION_KEY = `sistema-pollos-pantalla-cliente-minorista-${RETAIL_STATION}-instancia-v1`;
const RETAIL_CUSTOMER_DISPLAY_CHANNEL_NAME = buildRetailCustomerDisplayChannelName(RETAIL_STATION);
const STORAGE_PREFIX = RETAIL_STATION === "1"
  ? "sistema-pollos-retail-dispatch-v2-branch"
  : `sistema-pollos-retail-dispatch-v2-station-${RETAIL_STATION}-branch`;
const OPERATION_SALE = "DESPACHO";
const OPERATION_RETURN = "DEVOLUCION";
const SEX_MALE = "MACHO";
const RETAIL_DRESSED_CHICKEN_CODE = "POLLO_PELADO";
const LIST_COUNT = RETAIL_STATION === "1" ? 8 : 4;
const MAX_RETAIL_BIRD_QUANTITY = 40;
const STATION_2_LIST_ADJUSTMENT_CODES = [
  "MACHO_CERRADO",
  "MACHO_ABIERTO",
  "HEMBRA_CERRADA",
  "HEMBRA_ABIERTA"
];
const MONEY_DECIMALS = 2;
const MONEY_FACTOR = 10 ** MONEY_DECIMALS;
const TICKET_PRICE_OVERRIDE_VERSION = 1;
const LIST_CLASSES = [
  "is-list-1",
  "is-list-2",
  "is-list-3",
  "is-list-4",
  "is-list-5",
  "is-list-6",
  "is-list-7",
  "is-list-8"
];
const RETAIL_CHICKEN_TYPE_CODES = new Set([
  RETAIL_DRESSED_CHICKEN_CODE,
  RETAIL_PROCESSED_CHICKEN_CODE
]);
const TYPOGRAPHY_STORAGE_KEY = RETAIL_STATION === "1"
  ? "sistema-pollos-retail-typography-v1"
  : `sistema-pollos-retail-typography-v1-station-${RETAIL_STATION}`;
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

function getRetailCustomerDisplayProducerId() {
  try {
    const existingId = sessionStorage.getItem(RETAIL_CUSTOMER_DISPLAY_PRODUCER_SESSION_KEY);
    if (existingId) {
      return existingId;
    }

    const generatedId = globalThis.crypto?.randomUUID?.()
      || `minorista-${RETAIL_STATION}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    sessionStorage.setItem(RETAIL_CUSTOMER_DISPLAY_PRODUCER_SESSION_KEY, generatedId);
    return generatedId;
  } catch {
    return globalThis.crypto?.randomUUID?.()
      || `minorista-${RETAIL_STATION}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }
}

function getRetailCustomerDisplayProducerInstance() {
  const currentTimestamp = Date.now();

  try {
    const previousInstance = Number(
      sessionStorage.getItem(RETAIL_CUSTOMER_DISPLAY_INSTANCE_SESSION_KEY)
    );
    const nextInstance = Number.isSafeInteger(previousInstance) && previousInstance > 0
      ? Math.max(currentTimestamp, previousInstance + 1)
      : currentTimestamp;
    sessionStorage.setItem(RETAIL_CUSTOMER_DISPLAY_INSTANCE_SESSION_KEY, String(nextInstance));
    return nextInstance;
  } catch {
    return currentTimestamp;
  }
}

const RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID = getRetailCustomerDisplayProducerId();
const RETAIL_CUSTOMER_DISPLAY_PRODUCER_INSTANCE = getRetailCustomerDisplayProducerInstance();
const RETAIL_CUSTOMER_DISPLAY_STORAGE_KEY = buildRetailCustomerDisplayStorageKey(
  RETAIL_STATION,
  RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID
);

const elements = {
  station: document.querySelector("#retailStation"),
  form: document.querySelector("#retailWeighingForm"),
  branchName: document.querySelector("#retailBranchName"),
  clock: document.querySelector("#retailClock"),
  scaleTopStatus: document.querySelector("#retailScaleTopStatus"),
  openCustomerDisplay: document.querySelector("#retailOpenCustomerDisplay"),
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
  birdsPerTrayAccessibleLabel: document.querySelector("#retailBirdsPerTrayAccessibleLabel"),
  birdsPerTrayModalTitle: document.querySelector("#retailBirdsPerTrayModalTitle"),
  birdsPerTrayOptions: document.querySelector(".rd-birds-per-tray-options"),
  rawWeightInput: document.querySelector("#retailRawWeightInput"),
  manualWeightTrigger: document.querySelector("#retailManualWeightTrigger"),
  openManualWeight: document.querySelector("#retailOpenManualWeight"),
  adjustedWeight: document.querySelector("#retailAdjustedWeight"),
  adjustmentReadingHint: document.querySelector(".rd-adjusted-reading > span"),
  weightSourceLabel: document.querySelector("#retailWeightSourceLabel"),
  captureState: document.querySelector("#retailCaptureState"),
  captureWeight: document.querySelector("#retailCaptureWeight"),
  pricePreview: document.querySelector("#retailPricePreview"),
  priceSource: document.querySelector("#retailPriceSource"),
  grossPreview: document.querySelector("#retailGrossPreview"),
  tarePreview: document.querySelector("#retailTarePreview"),
  tareDetail: document.querySelector("#retailTareDetail"),
  netPreview: document.querySelector("#retailNetPreview"),
  birdTotalPreview: document.querySelector("#retailBirdTotalPreview"),
  adjustments: document.querySelector("#retailAdjustments"),
  listSelectionHint: document.querySelector("#retailListSelectionHint"),
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
  removeWeighingModal: document.querySelector("#retailRemoveWeighingModal"),
  removeWeighingPreview: document.querySelector("#retailRemoveWeighingPreview"),
  confirmRemoveWeighing: document.querySelector("#retailConfirmRemoveWeighing"),
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
  paymentModeOptions: document.querySelector("#retailPaymentModeOptions"),
  paymentNowPanel: document.querySelector("#retailPaymentNowPanel"),
  paymentCreditPanel: document.querySelector("#retailPaymentCreditPanel"),
  paymentCreditSummary: document.querySelector("#retailPaymentCreditSummary"),
  paymentRows: document.querySelector("#retailPaymentRows"),
  addPayment: document.querySelector("#retailAddPayment"),
  paymentSaleTotal: document.querySelector("#retailPaymentSaleTotal"),
  paymentReceivedTotal: document.querySelector("#retailPaymentReceivedTotal"),
  paymentPendingTotal: document.querySelector("#retailPaymentPendingTotal"),
  paymentMessage: document.querySelector("#retailPaymentMessage"),
  confirmPayment: document.querySelector("#retailConfirmPayment"),
  deliveryModal: document.querySelector("#retailDeliveryModal"),
  deliveryForm: document.querySelector("#retailDeliveryForm"),
  deliverySummary: document.querySelector("#retailDeliverySummary"),
  deliveryModeOptions: document.querySelector("#retailDeliveryModeOptions"),
  deliveryFields: document.querySelector("#retailDeliveryFields"),
  deliveryTruck: document.querySelector("#retailDeliveryTruck"),
  deliveryDriver: document.querySelector("#retailDeliveryDriver"),
  deliveryMessage: document.querySelector("#retailDeliveryMessage"),
  confirmDelivery: document.querySelector("#retailConfirmDelivery"),
  errorModal: document.querySelector("#retailErrorModal"),
  errorModalCaption: document.querySelector("#retailErrorModalCaption"),
  errorModalTitle: document.querySelector("#retailErrorModalTitle"),
  errorModalMessage: document.querySelector("#retailErrorModalMessage"),
  errorModalDetails: document.querySelector("#retailErrorModalDetails"),
  errorModalHelp: document.querySelector("#retailErrorModalHelp"),
  errorLogin: document.querySelector("#retailErrorLogin"),
  retryPrint: document.querySelector("#retailRetryPrint"),
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
  defaultPaymentMethod: document.querySelector("#retailDefaultPaymentMethod"),
  defaultPaymentAccount: document.querySelector("#retailDefaultPaymentAccount"),
  settingsMessage: document.querySelector("#retailSettingsMessage"),
  saveSettings: document.querySelector("#retailSaveSettings"),
  openTypography: document.querySelector("#retailOpenTypography"),
  typographyDrawer: document.querySelector("#retailTypographyDrawer"),
  typographyControls: document.querySelector("#retailTypographyControls"),
  typographyReset: document.querySelector("#retailTypographyReset"),
  typographyClose: document.querySelector("#retailTypographyClose"),
  touchKeyboard: document.querySelector("#retailTouchKeyboard"),
  touchKeyboardTitle: document.querySelector("#retailTouchKeyboardTitle"),
  touchKeyboardValue: document.querySelector("#retailTouchKeyboardValue"),
  touchKeyboardKeys: document.querySelector("#retailTouchKeyboardKeys")
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
      own_accounts: [],
      default_method_id: null,
      default_account_id: null
    }
  },
  activeList: 0,
  chickenType: null,
  sex: SEX_MALE,
  adjustmentCode: null,
  liveWeight: null,
  liveSource: null,
  liveReadingAt: null,
  liveReadingId: null,
  liveReadingStatus: "unknown",
  liveIsStable: false,
  liveIsFresh: false,
  liveRaw: "",
  selectedItem: null,
  priceEditingListIndex: null,
  storageKey: null,
  lists: Array.from({ length: LIST_COUNT }, emptyList),
  scale: null,
  scaleState: null,
  loading: true,
  typography: {},
  pendingPayments: [],
  paymentRows: [],
  paymentContext: null,
  paymentMode: RETAIL_PAYMENT_MODE_NOW,
  deliveryMode: null,
  pendingPrintTicket: null
};
let retailCustomerDisplayChannel = null;
let retailCustomerDisplayRevision = 0;
let lastRetailCustomerDisplayStorageWrite = 0;
let pendingRetailCustomerDisplayStoragePayload = null;
let retailCustomerDisplayStorageTimer = null;
let retailCustomerDisplayHeartbeatTimer = null;
const modalFocusOrigins = new Map();
const touchKeyboardState = {
  target: null,
  initialValue: "",
  buffer: "",
  mode: "text",
  decimalPlaces: null,
  uppercase: true,
  replaceOnNextKey: false,
  suppressOpen: false
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
    ticketPriceOverrideVersion: TICKET_PRICE_OVERRIDE_VERSION,
    priceOverrides: {},
    items: [],
    saving: false
  };
}

function normalizeList(list) {
  const source = list && typeof list === "object" ? list : {};
  const clientId = String(source.clientId || "");
  const normalizedPriceOverrides = source.priceOverrides && typeof source.priceOverrides === "object"
    ? Object.fromEntries(
      Object.entries(source.priceOverrides)
        .map(([code, value]) => [code, roundMoney(value)])
        .filter(([, value]) => value > 0)
    )
    : {};
  const supportsClientOverrides = Number(source.ticketPriceOverrideVersion)
    === TICKET_PRICE_OVERRIDE_VERSION;

  return {
    draftId: source.draftId || createDraftId(),
    clientId,
    operationType: source.operationType === OPERATION_RETURN ? OPERATION_RETURN : OPERATION_SALE,
    ticketPriceOverrideVersion: TICKET_PRICE_OVERRIDE_VERSION,
    priceOverrides: clientId && !supportsClientOverrides ? {} : normalizedPriceOverrides,
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
    if (Array.isArray(stored)) {
      const restoredLists = stored
        .slice(0, LIST_COUNT)
        .map(normalizeList);

      while (restoredLists.length < LIST_COUNT) {
        restoredLists.push(emptyList());
      }

      state.lists = restoredLists;
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
    ticketPriceOverrideVersion: TICKET_PRICE_OVERRIDE_VERSION,
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
  const availableChickenTypeCodes = new Set(
    state.catalog.chicken_types.map((type) => type.code)
  );

  state.lists.forEach((list, listIndex) => {
    list.priceOverrides = Object.fromEntries(
      Object.entries(list.priceOverrides || {})
        .filter(([code]) => availableChickenTypeCodes.has(code))
        .map(([code, value]) => [code, roundMoney(value)])
        .filter(([, value]) => value > 0)
    );
    list.items = list.items
      .filter((item) => availableChickenTypeCodes.has(item.chickenTypeCode))
      .map((item) => {
        const fixedAdjustment = station2AdjustmentForList(listIndex);
        const adjustmentCode = fixedAdjustment?.code || item.adjustmentCode;
        const adjustment = state.catalog.adjustments.find((entry) => entry.code === adjustmentCode);
        const tray = state.catalog.tray_types.find((entry) => entry.code === item.trayTypeCode);
        if (!adjustment || !tray) return item;

        const trayCount = Number(item.trayCount || 0);
        const birdsPerTray = Number(item.birdsPerTray || 0);
        const {
          birds,
          adjustmentGrams,
          totalAdjustmentGrams
        } = calculateRetailWeightAdjustment({
          chickenTypeCode: item.chickenTypeCode,
          trayCount,
          birdsPerTray,
          configuredAdjustmentGrams: adjustment.additional_grams
        });
        const grossWeight = roundWeight(Number(item.readWeight || 0) + totalAdjustmentGrams / 1000);
        const tareWeight = roundWeight(trayCount * Number(tray.weight_kg || 0));

        return {
          ...item,
          adjustmentCode: adjustment.code,
          adjustmentName: isProcessedChickenType(item.chickenTypeCode)
            ? "Sin merma"
            : adjustment.name,
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

function roundMoney(value) {
  const amount = Number(value);
  if (!Number.isFinite(amount)) return 0;
  const sign = Math.sign(amount) || 1;
  return sign * Math.round((Math.abs(amount) + Number.EPSILON) * MONEY_FACTOR) / MONEY_FACTOR;
}

function formatMoneyValue(value) {
  return roundMoney(value).toFixed(MONEY_DECIMALS);
}

function moneyToCents(value) {
  return Math.round(roundMoney(value) * MONEY_FACTOR);
}

function centsToMoney(value) {
  return Number(value || 0) / MONEY_FACTOR;
}

function hasAtMostMoneyDecimals(value) {
  return /^\d+(?:\.\d{1,2})?$/.test(String(value ?? "").trim());
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function formatMoney(value, signed = false) {
  const amount = roundMoney(value);
  const prefix = signed && amount > 0 ? "+" : "";
  return `${prefix}S/ ${formatMoneyValue(amount)}`;
}

function setMessage(message, isError = false) {
  elements.message.textContent = message || "";
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function showRetailError(presentation = {}) {
  if (touchKeyboardState.target && !elements.touchKeyboard?.hidden) {
    closeTouchKeyboard(true);
  }
  const details = Array.isArray(presentation.details)
    ? presentation.details.filter((detail) => detail?.value)
    : [];

  elements.errorModalCaption.textContent = presentation.caption || "Error al grabar";
  elements.errorModalTitle.textContent = presentation.title || "No se registró el ticket";
  elements.errorModalMessage.textContent = presentation.message || "No se pudo grabar el despacho.";
  elements.errorModalHelp.textContent = presentation.help
    || "La lista y sus pesadas se conservan para que puedas corregir el problema y volver a intentar.";
  elements.errorLogin.hidden = presentation.action !== "login";
  elements.retryPrint.hidden = !presentation.canRetryPrint;
  elements.errorModalDetails.replaceChildren();
  elements.errorModalDetails.hidden = details.length === 0;

  details.forEach((detail) => {
    const wrapper = document.createElement("div");
    const label = document.createElement("dt");
    const value = document.createElement("dd");
    wrapper.className = "rd-error-detail";
    label.textContent = detail.label || "Motivo";
    value.textContent = detail.value;
    wrapper.append(label, value);
    elements.errorModalDetails.appendChild(wrapper);
  });

  openModal(elements.errorModal);
}

function showLocalActionIssue(options = {}) {
  const presentation = getRetailLocalActionErrorPresentation(options);
  setMessage(presentation.summary, true);
  showRetailError(presentation);
}

function showCaptureIssue(options = {}) {
  const details = [
    {
      label: "Estación",
      value: RETAIL_STATION === "2" ? "Despacho minorista 2" : "Despacho minorista 1"
    },
    { label: "Lista activa", value: `Lista ${state.activeList + 1}` },
    ...(Array.isArray(options.details) ? options.details : [])
  ];

  showLocalActionIssue({
    caption: "Captura bloqueada",
    ...options,
    details
  });
}

function unavailableReadingPresentation(availability) {
  const scaleState = availability.scaleState || {};
  const weight = Number.isFinite(availability.weight) && availability.weight > 0
    ? formatWeight(availability.weight)
    : "Sin peso válido";
  const source = availability.source === "manual"
    ? "Ingreso manual"
    : availability.source === "ble"
      ? "Balanza Bluetooth"
      : availability.source === "serial"
        ? "Balanza serial"
        : "Sin origen de lectura";

  if (availability.isPhysical && !availability.connectionMatches) {
    return {
      title: "La balanza no está conectada",
      message: "No se agregó la pesada porque la lectura no pertenece a una conexión activa.",
      details: [
        { label: "Lectura detectada", value: weight },
        { label: "Origen", value: source },
        { label: "Motivo", value: "La conexión física de la balanza está cerrada o cambió." }
      ],
      help: "Abre la configuración, conecta la balanza de esta estación y espera una lectura estable antes de capturar."
    };
  }

  if (!Number.isFinite(availability.weight) || availability.weight <= 0) {
    return {
      title: "No hay un peso válido para capturar",
      message: "No se agregó la pesada porque la balanza no muestra un valor mayor que cero.",
      details: [
        { label: "Peso detectado", value: weight },
        { label: "Origen", value: source }
      ],
      help: "Coloca el producto en la balanza o ingresa un peso manual mayor que cero y vuelve a intentar."
    };
  }

  if (availability.isExpired || state.liveReadingStatus === "stale" || scaleState.isFresh === false) {
    return {
      title: "La lectura ya venció",
      message: "No se agregó la pesada porque el peso dejó de ser reciente.",
      details: [
        { label: "Último peso", value: weight },
        { label: "Origen", value: source },
        { label: "Motivo", value: "La balanza no ha confirmado una muestra reciente." }
      ],
      help: "Mueve o vuelve a colocar el producto y espera una nueva lectura estable antes de capturar."
    };
  }

  if (state.liveReadingStatus === "unstable" || scaleState.isStable === false) {
    return {
      title: "El peso todavía está inestable",
      message: "No se agregó la pesada porque la balanza aún está variando.",
      details: [
        { label: "Peso detectado", value: weight },
        { label: "Origen", value: source },
        { label: "Motivo", value: "Faltan muestras coincidentes para confirmar el peso." }
      ],
      help: "Deja el producto quieto sobre la balanza y vuelve a presionar Capturar cuando indique Peso estable."
    };
  }

  return {
    title: "La lectura aún no está lista",
    message: "No se agregó la pesada porque faltan datos de confirmación de la lectura.",
    details: [
      { label: "Peso detectado", value: weight },
      { label: "Origen", value: source },
      { label: "Estado", value: scaleState.statusMessage || "Esperando confirmación de la balanza" }
    ],
    help: "Espera a que la pantalla indique Peso estable y vuelve a presionar Capturar."
  };
}

function showRegistrationIssue(message, details = [], title = "Revisa los datos del ticket") {
  showLocalActionIssue({
    caption: "Datos por corregir",
    title,
    message,
    details: details.length ? details : [{ label: "Registro", value: message }]
  });
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

function chickenTypeByCode(code) {
  const normalizedCode = String(code || "").toUpperCase();
  return state.catalog.chicken_types.find(
    (type) => String(type.code || "").toUpperCase() === normalizedCode
  ) || null;
}

function isProcessedChickenType(code = state.chickenType) {
  return String(code || "").toUpperCase() === RETAIL_PROCESSED_CHICKEN_CODE;
}

function selectChickenType(code) {
  const chickenType = chickenTypeByCode(code);
  if (!chickenType) return false;
  state.chickenType = chickenType.code;
  return true;
}

function ensureDressedChickenTypeSelection() {
  if (selectChickenType(RETAIL_DRESSED_CHICKEN_CODE)) return;
  state.chickenType = state.catalog.chicken_types[0]?.code || null;
}

function selectedChickenType() {
  return chickenTypeByCode(state.chickenType);
}

function selectedAdjustment() {
  return state.catalog.adjustments.find((adjustment) => adjustment.code === state.adjustmentCode) || null;
}

function station2AdjustmentForList(listIndex) {
  if (RETAIL_STATION !== "2") return null;
  const code = STATION_2_LIST_ADJUSTMENT_CODES[Number(listIndex)];
  return state.catalog.adjustments.find((adjustment) => adjustment.code === code) || null;
}

function syncStation2AdjustmentWithActiveList() {
  if (RETAIL_STATION !== "2") return;
  const adjustment = station2AdjustmentForList(state.activeList);
  if (!adjustment) {
    state.adjustmentCode = "";
    state.sex = SEX_MALE;
    return;
  }
  state.adjustmentCode = adjustment.code;
  state.sex = adjustment.sex || SEX_MALE;
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
    const rawValue = Number(record.price_kg ?? record.priceKg ?? record.value);
    const value = roundMoney(rawValue);
    return Number.isFinite(rawValue) && value > 0
      ? { value, source: String(record.source || record.origin || "VIGENTE") }
      : null;
  }

  const rawValue = Number(record);
  const value = roundMoney(rawValue);
  return Number.isFinite(rawValue) && value > 0 ? { value, source: "VIGENTE" } : null;
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
  const base = client
    ? currentClientPrice(list, chickenTypeCode)
    : currentGeneralPrice(chickenTypeCode);
  if (!base) return null;

  const override = list.priceOverrides?.[chickenTypeCode];
  if (override !== undefined && override !== null && override !== "") {
    const rawValue = Number(override);
    const value = roundMoney(rawValue);
    if (Number.isFinite(rawValue) && value > 0) return { value, source: "MANUAL" };
  }

  return base;
}

function missingPriceTypes(list) {
  return [...new Set(list.items.map((item) => item.chickenTypeCode))]
    .filter((code) => !effectivePrice(list, code));
}

function lineAmount(list, item) {
  const price = effectivePrice(list, item.chickenTypeCode)?.value;
  if (!Number.isFinite(price)) return null;
  return roundMoney(Number(item.netWeight || 0) * price);
}

function listTotals(list) {
  const sign = list.operationType === OPERATION_RETURN ? -1 : 1;

  return list.items.reduce((totals, item) => {
    const amount = lineAmount(list, item);
    totals.trays += Number(item.trayCount || 0);
    totals.birds += Number(item.birds || 0);
    totals.gross += Number(item.grossWeight || 0);
    totals.tare += Number(item.tareWeight || 0);
    totals.net += Number(item.netWeight || 0);
    if (amount !== null) totals.amountCents += sign * moneyToCents(amount);
    totals.amount = centsToMoney(totals.amountCents);
    return totals;
  }, {
    weighings: list.items.length,
    trays: 0,
    birds: 0,
    gross: 0,
    tare: 0,
    net: 0,
    amount: 0,
    amountCents: 0
  });
}

function buildCurrentRetailCustomerDisplayState() {
  const list = activeList();
  const totals = listTotals(list);
  const availability = liveReadingAvailability();
  const values = previewValues();
  const customer = clientFor(list);
  const fixedPresentation = station2AdjustmentForList(state.activeList);
  const selectedPresentation = isProcessedChickenType()
    ? "Pollo beneficiado · sin merma"
    : (fixedPresentation?.name || selectedAdjustment()?.name || "");
  const pricingComplete = missingPriceTypes(list).length === 0;
  const calculationAvailable = availability.fixedAdjustmentAvailable
    && Boolean(values.tray)
    && Boolean(values.adjustment)
    && Boolean(selectedChickenType());
  const displayWeights = resolveRetailCustomerDisplayWeights({
    hasReading: values.hasReading,
    readWeightKg: values.readWeight,
    netWeightKg: values.netWeight,
    calculationAvailable,
    isPhysical: availability.isPhysical,
    isFresh: availability.scaleState.isFresh,
    connectionMatches: availability.connectionMatches,
    isExpired: availability.isExpired
  });

  return buildRetailCustomerDisplayPayload({
    station: RETAIL_STATION,
    producerId: RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID,
    producerInstance: RETAIL_CUSTOMER_DISPLAY_PRODUCER_INSTANCE,
    revision: ++retailCustomerDisplayRevision,
    updatedAt: new Date().toISOString(),
    customerName: customer?.name || "Venta sin cliente",
    ticketLabel: `Lista ${state.activeList + 1}`,
    listNumber: state.activeList + 1,
    operationType: list.operationType,
    presentation: selectedPresentation,
    totals,
    pricingComplete,
    readWeightKg: displayWeights.readWeightKg,
    displayWeightKg: displayWeights.displayWeightKg,
    weightSource: state.liveSource,
    weightStatus: availability.isExpired ? "stale" : state.liveReadingStatus,
    isStable: availability.scaleState.isStable,
    isFresh: availability.scaleState.isFresh
  });
}

function flushRetailCustomerDisplayStorage() {
  if (!pendingRetailCustomerDisplayStoragePayload) {
    return;
  }

  try {
    localStorage.setItem(
      RETAIL_CUSTOMER_DISPLAY_STORAGE_KEY,
      JSON.stringify(pendingRetailCustomerDisplayStoragePayload)
    );
    lastRetailCustomerDisplayStorageWrite = Date.now();
    pendingRetailCustomerDisplayStoragePayload = null;
  } catch {
    // BroadcastChannel mantiene la pantalla en vivo si localStorage no está disponible.
  }
}

function persistRetailCustomerDisplayState(payload, forceStorage = false) {
  pendingRetailCustomerDisplayStoragePayload = payload;
  const remainingDelay = Math.max(
    100 - (Date.now() - lastRetailCustomerDisplayStorageWrite),
    0
  );

  if (forceStorage || remainingDelay === 0) {
    if (retailCustomerDisplayStorageTimer) {
      globalThis.clearTimeout(retailCustomerDisplayStorageTimer);
      retailCustomerDisplayStorageTimer = null;
    }
    flushRetailCustomerDisplayStorage();
    return;
  }

  if (!retailCustomerDisplayStorageTimer) {
    retailCustomerDisplayStorageTimer = globalThis.setTimeout(() => {
      retailCustomerDisplayStorageTimer = null;
      flushRetailCustomerDisplayStorage();
    }, remainingDelay);
  }
}

function publishRetailCustomerDisplayState(forceStorage = false) {
  const payload = buildCurrentRetailCustomerDisplayState();
  retailCustomerDisplayChannel?.postMessage(payload);
  persistRetailCustomerDisplayState(payload, forceStorage);
}

function initializeRetailCustomerDisplaySync() {
  if (!("BroadcastChannel" in globalThis)) {
    return;
  }

  retailCustomerDisplayChannel = new BroadcastChannel(RETAIL_CUSTOMER_DISPLAY_CHANNEL_NAME);
  retailCustomerDisplayChannel.addEventListener("message", (event) => {
    if (
      event.data?.type === RETAIL_CUSTOMER_DISPLAY_REQUEST_TYPE
      && String(event.data.station || "") === RETAIL_STATION
      && event.data.producerId === RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID
    ) {
      publishRetailCustomerDisplayState(true);
    }
  });
}

function resetRetailCustomerDisplay() {
  retailCustomerDisplayChannel?.postMessage({
    type: RETAIL_CUSTOMER_DISPLAY_RESET_TYPE,
    station: RETAIL_STATION,
    producerId: RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID
  });

  try {
    localStorage.removeItem(RETAIL_CUSTOMER_DISPLAY_STORAGE_KEY);
  } catch {
    // La ventana receptora también limpia la información al vencer su tiempo de vida.
  }
}

function openRetailCustomerDisplay(event) {
  event.preventDefault();
  const displayHref = elements.openCustomerDisplay?.href;
  if (!displayHref) {
    return;
  }

  publishRetailCustomerDisplayState(true);
  const displayUrl = new URL(displayHref, globalThis.location.href);
  displayUrl.searchParams.set("source", RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID);
  const windowName = `pantalla-cliente-minorista-${RETAIL_STATION}-${RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID}`;
  const displayWindow = globalThis.open(
    displayUrl.toString(),
    windowName,
    "popup=yes,width=1280,height=800,resizable=yes,scrollbars=no"
  );

  if (displayWindow) {
    displayWindow.focus();
    return;
  }

  showLocalActionIssue({
    caption: "Ventana bloqueada",
    title: "No se pudo abrir la pantalla del cliente",
    message: "El navegador bloqueó la nueva ventana y no se cambió ningún dato del ticket.",
    details: [
      { label: "Estación", value: `Despacho minorista ${RETAIL_STATION}` },
      { label: "Acción necesaria", value: "Permite las ventanas emergentes para este sistema." }
    ],
    help: "Después de habilitar las ventanas emergentes, vuelve a presionar Pantalla cliente."
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
  const sourceWeight = readWeightOverride ?? state.liveWeight;
  const parsedWeight = sourceWeight === null || sourceWeight === undefined || sourceWeight === ""
    ? null
    : Number(sourceWeight);
  const hasReading = Number.isFinite(parsedWeight) && parsedWeight >= 0;
  const readWeight = hasReading ? parsedWeight : null;
  const trayCount = Number(elements.trayCount.value || 0);
  const birdsPerTray = Number(elements.birdsPerTray.value || 0);
  const {
    birds,
    adjustmentGrams,
    totalAdjustmentGrams,
    appliesAdjustment
  } = calculateRetailWeightAdjustment({
    chickenTypeCode: state.chickenType,
    trayCount,
    birdsPerTray,
    configuredAdjustmentGrams: adjustment?.additional_grams
  });
  const grossWeight = readWeight > 0
    ? roundWeight(readWeight + totalAdjustmentGrams / 1000)
    : 0;
  const tareWeight = roundWeight(trayCount * Number(tray?.weight_kg || 0));
  const netWeight = roundWeight(grossWeight - tareWeight);

  return {
    tray,
    adjustment,
    hasReading,
    readWeight,
    trayCount,
    birdsPerTray,
    adjustmentGrams,
    totalAdjustmentGrams,
    grossWeight,
    tareWeight,
    netWeight,
    birds,
    appliesAdjustment
  };
}

function liveReadingAvailability() {
  const scaleState = state.scale?.getState?.() || state.scaleState || {};
  syncLiveScaleState(scaleState);
  const weight = Number.isFinite(scaleState.currentWeightKg)
    ? Number(scaleState.currentWeightKg)
    : null;
  const source = scaleState.readingSource || null;
  const readingAt = scaleState.readingAt || null;
  const readingId = scaleState.readingId || null;
  const isPhysical = source === "ble" || source === "serial";
  const connectionMatches = !isPhysical
    || (scaleState.status === "connected" && scaleState.connectionMode === source);
  const readingAtMs = Date.parse(readingAt || "");
  const freshnessMs = Number(scaleState.freshnessMs);
  const isExpired = isPhysical
    && Number.isFinite(readingAtMs)
    && Number.isFinite(freshnessMs)
    && Date.now() - readingAtMs > freshnessMs;
  const fixedAdjustmentAvailable = RETAIL_STATION !== "2"
    || Boolean(station2AdjustmentForList(state.activeList));
  const ready = Number.isFinite(weight)
    && weight > 0
    && Boolean(scaleState.isStable)
    && Boolean(scaleState.isFresh)
    && connectionMatches
    && Boolean(readingAt)
    && Boolean(readingId)
    && fixedAdjustmentAvailable
    && !state.loading;

  return {
    ready,
    weight,
    source,
    readingAt,
    readingId,
    isPhysical,
    isExpired,
    connectionMatches,
    fixedAdjustmentAvailable,
    scaleState
  };
}

function renderWeightPreview() {
  const availability = liveReadingAvailability();
  const values = previewValues();
  const calculationsAvailable = availability.fixedAdjustmentAvailable;
  const price = effectivePrice(activeList(), state.chickenType);
  const source = state.liveSource;
  const sourceLabels = {
    manual: "Ingreso manual",
    ble: "Balanza minorista · BLE",
    serial: "Balanza minorista · Serial"
  };

  elements.adjustedWeight.textContent = values.hasReading ? values.readWeight.toFixed(3) : "---";
  elements.grossPreview.textContent = values.hasReading && calculationsAvailable
    ? formatWeight(values.grossWeight)
    : "--- kg";
  elements.tarePreview.textContent = formatWeight(values.tareWeight);
  elements.tareDetail.textContent = `${values.trayCount} × ${Number(values.tray?.weight_kg || 0).toFixed(3)} kg por bandeja`;
  elements.netPreview.textContent = values.hasReading && calculationsAvailable
    ? formatWeight(Math.max(values.netWeight, 0))
    : "--- kg";
  elements.netPreview.classList.toggle(
    "is-invalid",
    calculationsAvailable && values.readWeight > 0 && values.netWeight <= 0
  );
  elements.birdTotalPreview.textContent = values.trayCount === 0
    ? `${values.birds} ave${values.birds === 1 ? "" : "s"} · sin bandeja`
    : `${values.birds} ave${values.birds === 1 ? "" : "s"}`;
  elements.adjustmentReadingHint.textContent = !calculationsAvailable
    ? "Peso directo de balanza · ajuste no disponible"
    : values.appliesAdjustment
      ? "Peso directo de balanza · merma aplicada solo en cálculos"
      : "Peso directo de balanza · pollo beneficiado sin merma";
  elements.manualWeightTrigger.setAttribute(
    "aria-label",
    "Peso directo de la balanza. Toca para ingresar peso manual"
  );
  elements.weightSourceLabel.textContent = sourceLabels[source]
    || (availability.scaleState.status === "connected" ? "Esperando balanza" : "Sin lectura");
  elements.captureState.textContent = availability.ready
    ? (source === "manual" ? "Peso manual listo" : "Peso estable")
    : !availability.fixedAdjustmentAvailable
      ? "Ajuste no disponible"
      : availability.isExpired
        ? "Lectura vencida"
        : state.liveReadingStatus === "unstable"
          ? "Peso inestable"
          : state.liveReadingStatus === "stale"
            ? "Lectura vencida"
            : availability.scaleState.status === "connected"
              ? "Esperando lectura"
              : "Sin conexión";
  elements.captureState.classList.toggle("is-captured", availability.ready);
  elements.captureWeight.classList.remove("is-captured");
  const captureLocked = state.loading || state.lists.some((list) => list.saving);
  elements.captureWeight.disabled = captureLocked;
  elements.manualWeightTrigger.disabled = captureLocked;
  elements.openManualWeight.disabled = captureLocked;
  elements.captureWeight.title = availability.ready
    ? "Capturar el peso actual"
    : "Presiona para ver por qué la lectura todavía no puede capturarse";
  elements.captureWeight.lastChild.textContent = ` Capturar en lista ${state.activeList + 1}`;
  elements.captureWeight.setAttribute("aria-label", `Capturar el peso actual en la lista ${state.activeList + 1}`);
  const liveAmount = calculationsAvailable && price && values.netWeight > 0
    ? roundMoney(values.netWeight * price.value)
    : null;
  elements.pricePreview.textContent = liveAmount === null ? "S/ --" : formatMoney(liveAmount);
  elements.priceSource.textContent = price
    ? `S/ ${formatMoneyValue(price.value)} por kg · ${price.source === "MANUAL" ? "puntual" : price.source.toLowerCase()}`
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
  elements.birdsPerTrayAccessibleLabel.textContent = values.trayCount === 0
    ? "Cantidad de aves sin bandeja"
    : "Aves por bandeja";
  elements.birdsPerTrayModalTitle.textContent = values.trayCount === 0
    ? "Cantidad de aves sin bandeja"
    : "Aves por bandeja";
  elements.birdsPerTrayOptions.setAttribute(
    "aria-label",
    values.trayCount === 0
      ? "Seleccionar cantidad de aves sin bandeja"
      : "Seleccionar aves por bandeja"
  );
  elements.birdsPerTrayTrigger.setAttribute(
    "aria-label",
    values.trayCount === 0
      ? `${values.birdsPerTray} ave${values.birdsPerTray === 1 ? "" : "s"} sin bandeja. Toca para cambiar`
      : `${values.birdsPerTray} ave${values.birdsPerTray === 1 ? "" : "s"} por bandeja. Toca para cambiar`
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
  publishRetailCustomerDisplayState();
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
  elements.adjustments.hidden = false;
  if (RETAIL_STATION === "2") {
    syncStation2AdjustmentWithActiveList();
  } else {
    ensureAdjustmentSelection();
  }

  const processed = isProcessedChickenType();
  const available = RETAIL_STATION === "2"
    ? STATION_2_LIST_ADJUSTMENT_CODES
      .map((code, listIndex) => ({
        adjustment: state.catalog.adjustments.find((entry) => entry.code === code),
        listIndex
      }))
      .filter(({ adjustment }) => Boolean(adjustment))
    : state.catalog.adjustments.map((adjustment) => ({ adjustment, listIndex: null }));
  const adjustmentButtons = available.map(({ adjustment, listIndex }) => {
    const selected = !processed && adjustment.code === state.adjustmentCode;
    const listAttribute = Number.isInteger(listIndex)
      ? ` data-retail-list-adjustment="${listIndex}"`
      : "";
    return `
      <button type="button" data-retail-adjustment="${escapeHtml(adjustment.code)}"${listAttribute} class="${selected ? "is-active" : ""}" aria-pressed="${selected}">
        ${escapeHtml(adjustment.name)}
      </button>
    `;
  }).join("");
  const processedType = chickenTypeByCode(RETAIL_PROCESSED_CHICKEN_CODE);
  const processedButton = processedType
    ? `
      <button type="button" data-retail-processed="${escapeHtml(processedType.code)}" class="is-processed ${processed ? "is-active" : ""}" aria-pressed="${processed}">
        ${escapeHtml(processedType.name)}
        <small>Sin merma</small>
      </button>
    `
    : "";

  elements.adjustments.innerHTML = adjustmentButtons + processedButton;
}

function renderLists() {
  const current = activeList();
  const currentTotals = listTotals(current);

  elements.listsGrid.innerHTML = state.lists.map((list, listIndex) => {
    const client = clientFor(list);
    const fixedAdjustment = station2AdjustmentForList(listIndex);
    const totals = listTotals(list);
    const operationLabel = list.operationType === OPERATION_RETURN ? "Devolución" : "Venta";
    const headerTitle = fixedAdjustment?.name || client?.name || "Venta sin cliente";
    const headerSubtitle = fixedAdjustment
      ? `${client?.name || "Venta sin cliente"} · ${totals.weighings} pesada${totals.weighings === 1 ? "" : "s"}`
      : `${totals.weighings} pesada${totals.weighings === 1 ? "" : "s"} · ${trayQuantityLabel(totals.trays)}`;
    const rows = list.items.length
      ? newestRecordsFirst(list.items).map((item) => {
        const selected = state.selectedItem?.listIndex === listIndex && state.selectedItem?.id === item.id;
        const amount = lineAmount(list, item) || 0;
        const signedAmount = list.operationType === OPERATION_RETURN ? -amount : amount;
        return `
          <tr class="rd-list-row ${selected ? "is-selected" : ""}" data-retail-item="${listIndex}:${escapeHtml(item.id)}" tabindex="0" aria-selected="${selected}">
            <td>${escapeHtml(item.chickenShortName || item.chickenTypeName || "Pollo")}<small>${escapeHtml(item.adjustmentName || item.adjustmentCode)}</small></td>
            <td>${Number(item.trayCount) === 0 ? '<span class="rd-no-trays">Sin bandeja</span>' : item.trayCount}</td>
            <td>${item.birds}</td>
            <td>${Number(item.netWeight).toFixed(3)}<small>${formatMoney(signedAmount)}</small></td>
          </tr>
        `;
      }).join("")
      : '<tr><td colspan="4"><div class="rd-empty-list">Selecciona esta lista y captura un peso.</div></td></tr>';

    return `
      <article class="rd-list-card ${LIST_CLASSES[listIndex]} ${listIndex === state.activeList ? "is-active" : ""} ${list.saving ? "is-saving" : ""}" data-retail-list="${listIndex}" role="button" tabindex="0" aria-pressed="${listIndex === state.activeList}">
        <header class="rd-list-head">
          <span class="rd-list-number">${listIndex + 1}</span>
          <span class="rd-list-client"><strong>${escapeHtml(headerTitle)}</strong><small>${escapeHtml(headerSubtitle)}</small></span>
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
    || !current.items.length;
  elements.saveDispatch.title = missingPrices.length
    ? (current.clientId
      ? "Configura en Directorio un precio base vigente del cliente antes de grabar."
      : "Configura en Directorio un precio general base vigente antes de grabar.")
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
    const listIndex = Number(button.dataset.retailAddList);
    const selected = listIndex === state.activeList;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });

  const activeFixedAdjustment = station2AdjustmentForList(state.activeList);
  if (RETAIL_STATION === "2" && elements.listSelectionHint) {
    elements.listSelectionHint.textContent = isProcessedChickenType()
      ? `Pollo beneficiado sin merma · destino: lista ${state.activeList + 1}.`
      : activeFixedAdjustment
        ? `Pollo pelado · columna activa: ${activeFixedAdjustment.name}.`
      : "Columna activa sin presentación disponible.";
  }
}

function renderAll() {
  syncStation2AdjustmentWithActiveList();
  renderAdjustments();
  renderWeightPreview();
  renderLists();
}

function selectList(index, { selectDressed = RETAIL_STATION === "2" } = {}) {
  const nextIndex = Number(index);
  if (!Number.isInteger(nextIndex) || nextIndex < 0 || nextIndex >= LIST_COUNT) return;
  if (state.lists.some((list) => list.saving)) return;

  if (selectDressed) {
    ensureDressedChickenTypeSelection();
  }
  state.activeList = nextIndex;
  syncStation2AdjustmentWithActiveList();
  state.selectedItem = null;
  renderAll();
  const fixedAdjustment = station2AdjustmentForList(nextIndex);
  setMessage(fixedAdjustment
    ? `Pollo pelado · ${fixedAdjustment.name} activo; se aplicará la merma configurada.`
    : `Lista ${nextIndex + 1} activa.`);
}

function captureWeight() {
  const availability = liveReadingAvailability();
  if (!availability.fixedAdjustmentAvailable) {
    const expectedCode = STATION_2_LIST_ADJUSTMENT_CODES[state.activeList] || "NO DEFINIDA";
    return showCaptureIssue({
      title: "La presentación fija no está disponible",
      message: "No se agregó la pesada porque falta la presentación asignada a esta columna.",
      details: [
        {
          label: "Presentación esperada",
          value: expectedCode.replaceAll("_", " ").toLocaleLowerCase("es")
        },
        { label: "Catálogo", value: "La estación no recibió esta presentación como activa." }
      ],
      help: "Recarga la pantalla. Si continúa igual, revisa los ajustes de Minorista 2 antes de volver a capturar."
    });
  }
  if (!availability.ready) {
    renderWeightPreview();
    return showCaptureIssue(unavailableReadingPresentation(availability));
  }

  const scaleState = availability.scaleState;
  const isPhysical = availability.isPhysical;
  const connectionMode = availability.source === "ble" ? "BLUETOOTH" : "SERIAL";
  const capturedReading = {
    readWeight: roundWeight(availability.weight),
    source: availability.source,
    readingId: availability.readingId,
    readingAt: availability.readingAt,
    weighedAt: availability.readingAt,
    raw: isPhysical ? String(scaleState.readingRaw || state.liveRaw || "") : null,
    device: isPhysical ? String(scaleState.deviceName || "") : null,
    mode: isPhysical ? connectionMode : "MANUAL",
    scaleReading: isPhysical
      ? {
          raw_frame: String(scaleState.readingRaw || state.liveRaw || ""),
          connection_mode: connectionMode,
          device_name: String(scaleState.deviceName || "") || null,
          captured_at: availability.readingAt
        }
      : null
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
    return showCaptureIssue({
      title: "El peso leído no es válido",
      message: "No se agregó la pesada porque el valor recibido no es mayor que cero.",
      details: [{ label: "Peso recibido", value: formatWeight(capturedReading?.readWeight) }],
      help: "Coloca el producto en la balanza o ingresa un peso manual mayor que cero y vuelve a intentar."
    });
  }
  if (!values.tray || !values.adjustment || !chickenType) {
    return showCaptureIssue({
      title: "Faltan datos del catálogo minorista",
      message: "No se agregó la pesada porque uno o más datos seleccionados ya no están disponibles.",
      details: [
        { label: "Tipo de pollo", value: chickenType?.name || "No disponible" },
        { label: "Tipo de bandeja", value: values.tray?.name || "No disponible" },
        { label: "Presentación", value: values.adjustment?.name || "No disponible" }
      ],
      help: "Recarga la pantalla para actualizar los catálogos. La lectura no se guardará hasta que todos los datos estén disponibles."
    });
  }
  if (!Number.isInteger(values.trayCount) || values.trayCount < 0) {
    return showCaptureIssue({
      title: "La cantidad de bandejas no es válida",
      message: "No se agregó la pesada porque la cantidad de bandejas debe ser un entero mayor o igual que cero.",
      details: [{ label: "Cantidad recibida", value: String(values.trayCount) }],
      help: "Selecciona nuevamente la cantidad de bandejas y vuelve a capturar."
    });
  }
  if (
    !Number.isInteger(values.birdsPerTray)
    || values.birdsPerTray < 1
    || values.birdsPerTray > MAX_RETAIL_BIRD_QUANTITY
  ) {
    const birdLabel = values.trayCount === 0 ? "Cantidad de aves" : "Aves por bandeja";
    return showCaptureIssue({
      title: "La cantidad de aves no es válida",
      message: `No se agregó la pesada porque ${birdLabel.toLocaleLowerCase("es")} debe estar entre 1 y ${MAX_RETAIL_BIRD_QUANTITY}.`,
      details: [{ label: birdLabel, value: String(values.birdsPerTray) }],
      help: "Selecciona nuevamente la cantidad de aves y vuelve a capturar."
    });
  }
  if (values.netWeight <= 0) {
    return showCaptureIssue({
      title: "La tara iguala o supera el peso",
      message: "No se agregó la pesada porque el peso neto resultante no es mayor que cero.",
      details: [
        { label: "Peso leído", value: formatWeight(values.readWeight) },
        { label: "Peso con ajuste", value: formatWeight(values.grossWeight) },
        { label: "Tara de bandejas", value: formatWeight(values.tareWeight) },
        { label: "Peso neto resultante", value: formatWeight(values.netWeight) }
      ],
      help: "Verifica el peso leído, el tipo y la cantidad de bandejas; corrige el dato que corresponda y vuelve a capturar."
    });
  }

  target.items.push({
    id: createDraftId(),
    chickenTypeCode: chickenType.code,
    chickenTypeName: chickenType.name,
    chickenShortName: chickenType.name.replace(/^Pollo\s+/i, ""),
    adjustmentCode: values.adjustment.code,
    adjustmentName: isProcessedChickenType(chickenType.code)
      ? "Sin merma"
      : values.adjustment.name,
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
    weightSource: capturedReading.source === "manual"
      ? "MANUAL"
      : (state.catalog.scale?.code || "BALANZA_MINORISTA"),
    weighedAt: capturedReading.weighedAt,
    readingId: capturedReading.readingId,
    readingAt: capturedReading.readingAt,
    raw: capturedReading.raw,
    device: capturedReading.device,
    mode: capturedReading.mode,
    scaleReading: capturedReading.scaleReading
  });

  state.activeList = targetIndex;
  state.selectedItem = { listIndex: targetIndex, id: target.items.at(-1).id };
  if (capturedReading.source === "manual") {
    state.scale.clearReading();
    elements.rawWeightInput.value = "";
  } else {
    elements.rawWeightInput.value = Number(state.liveWeight || 0).toFixed(3);
  }
  persistLists();
  renderAll();
  setMessage(`Pesada agregada a la lista ${targetIndex + 1}.`);
}

function openRemoveWeighingModal() {
  const selected = state.selectedItem;
  if (!selected || selected.listIndex !== state.activeList) return;

  const list = activeList();
  const item = list.items.find((entry) => entry.id === selected.id);
  if (!item) {
    state.selectedItem = null;
    renderAll();
    return;
  }

  const price = effectivePrice(list, item.chickenTypeCode)?.value;
  const amount = lineAmount(list, item);
  const signedAmount = amount === null
    ? null
    : (list.operationType === OPERATION_RETURN ? -amount : amount);
  const operationLabel = list.operationType === OPERATION_RETURN ? "Devolución" : "Venta";
  const trayLabel = Number(item.trayCount) === 0
    ? "Sin bandejas"
    : `${trayQuantityLabel(item.trayCount)} · ${item.trayTypeName || item.trayTypeCode || "Tipo no indicado"}`;
  const sourceLabel = item.weightSource === "MANUAL" ? "Ingreso manual" : "Balanza";

  elements.removeWeighingPreview.innerHTML = `
    <div class="rd-remove-preview-heading">
      <span>Lista ${selected.listIndex + 1}</span>
      <strong>${escapeHtml(operationLabel)}</strong>
    </div>
    <dl>
      <div><dt>Producto</dt><dd>${escapeHtml(item.chickenTypeName || item.chickenShortName || item.chickenTypeCode || "Pollo")}</dd></div>
      <div><dt>Presentación</dt><dd>${escapeHtml(item.adjustmentName || item.adjustmentCode || "Sin presentación")}</dd></div>
      <div><dt>Bandejas</dt><dd>${escapeHtml(trayLabel)}</dd></div>
      <div><dt>Aves</dt><dd>${Number(item.birds || 0)}</dd></div>
      <div><dt>Peso leído</dt><dd>${Number(item.readWeight || 0).toFixed(3)} kg<small>${escapeHtml(sourceLabel)}</small></dd></div>
      <div><dt>Peso bruto</dt><dd>${Number(item.grossWeight || 0).toFixed(3)} kg</dd></div>
      <div><dt>Tara total</dt><dd>${Number(item.tareWeight || 0).toFixed(3)} kg</dd></div>
      <div class="is-emphasis"><dt>Peso neto</dt><dd>${Number(item.netWeight || 0).toFixed(3)} kg</dd></div>
      <div><dt>Precio por kg</dt><dd>${Number.isFinite(price) ? formatMoney(price) : "Sin precio"}</dd></div>
      <div class="is-emphasis"><dt>Importe</dt><dd>${signedAmount === null ? "Sin calcular" : formatMoney(signedAmount)}</dd></div>
    </dl>
  `;
  openModal(elements.removeWeighingModal);
}

function confirmRemoveSelectedWeighing() {
  const selected = state.selectedItem;
  if (!selected || selected.listIndex !== state.activeList) {
    closeModal(elements.removeWeighingModal);
    return;
  }

  const list = activeList();
  const index = list.items.findIndex((item) => item.id === selected.id);
  if (index < 0) {
    state.selectedItem = null;
    closeModal(elements.removeWeighingModal);
    renderAll();
    return;
  }

  list.items.splice(index, 1);
  state.selectedItem = null;
  closeModal(elements.removeWeighingModal);
  persistLists();
  renderAll();
  setMessage("Pesada retirada de la lista activa.");
}

function retailModals() {
  return [
    elements.errorModal,
    elements.trayCountModal,
    elements.birdsPerTrayModal,
    elements.manualWeightModal,
    elements.removeWeighingModal,
    elements.clientModal,
    elements.priceModal,
    elements.paymentModal,
    elements.deliveryModal,
    elements.settingsModal
  ].filter(Boolean);
}

function hasOpenRetailModal() {
  return retailModals().some((modal) => !modal.hidden);
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
  if (touchKeyboardState.target && modal.contains(touchKeyboardState.target)) {
    closeTouchKeyboard(true);
  }
  modal.hidden = true;
  if (!hasOpenRetailModal()) {
    elements.station.inert = false;
    elements.station.removeAttribute("aria-hidden");
  }
  const origin = modalFocusOrigins.get(modal.id);
  modalFocusOrigins.delete(modal.id);
  origin?.focus?.();
}

function updateTouchKeyboardValue() {
  const target = touchKeyboardState.target;
  if (!target || !elements.touchKeyboardValue) return;
  const value = touchKeyboardState.mode === "text" ? target.value : touchKeyboardState.buffer;
  elements.touchKeyboardValue.textContent = value || target.placeholder || "Campo vacío";
}

function renderTouchKeyboard() {
  if (!elements.touchKeyboardKeys) return;

  if (touchKeyboardState.mode !== "text") {
    elements.touchKeyboardKeys.setAttribute("aria-label", "Teclado numérico táctil");
    const decimalKey = touchKeyboardState.mode === "decimal"
      ? '<button type="button" data-retail-keyboard-key=".">,</button>'
      : '<button type="button" class="is-disabled" disabled aria-label="Decimales no permitidos">,</button>';
    elements.touchKeyboardKeys.className = "rd-touch-keyboard-keys is-numeric";
    elements.touchKeyboardKeys.innerHTML = `
      <div class="rd-touch-keyboard-row">
        ${["7", "8", "9"].map((key) => `<button type="button" data-retail-keyboard-key="${key}">${key}</button>`).join("")}
      </div>
      <div class="rd-touch-keyboard-row">
        ${["4", "5", "6"].map((key) => `<button type="button" data-retail-keyboard-key="${key}">${key}</button>`).join("")}
      </div>
      <div class="rd-touch-keyboard-row">
        ${["1", "2", "3"].map((key) => `<button type="button" data-retail-keyboard-key="${key}">${key}</button>`).join("")}
      </div>
      <div class="rd-touch-keyboard-row">
        <button type="button" data-retail-keyboard-key="0">0</button>
        <button type="button" data-retail-keyboard-key="00">00</button>
        ${decimalKey}
      </div>
    `;
    return;
  }

  const rows = [
    ["1", "2", "3", "4", "5", "6", "7", "8", "9", "0"],
    ["Q", "W", "E", "R", "T", "Y", "U", "I", "O", "P"],
    ["A", "S", "D", "F", "G", "H", "J", "K", "L", "Ñ"],
    ["Z", "X", "C", "V", "B", "N", "M"],
    ["Á", "É", "Í", "Ó", "Ú", "Ü", "-", "/", "."]
  ];
  const caseKey = (key) => touchKeyboardState.uppercase ? key : key.toLocaleLowerCase("es");
  elements.touchKeyboardKeys.setAttribute("aria-label", "Teclado español táctil");
  elements.touchKeyboardKeys.className = "rd-touch-keyboard-keys is-text";
  elements.touchKeyboardKeys.innerHTML = rows.map((row, index) => `
    <div class="rd-touch-keyboard-row ${index === 3 ? "is-short" : ""}">
      ${index === 3 ? `<button type="button" class="is-shift ${touchKeyboardState.uppercase ? "is-active" : ""}" data-retail-keyboard-action="shift" aria-pressed="${touchKeyboardState.uppercase}">Mayús</button>` : ""}
      ${row.map((key) => `<button type="button" data-retail-keyboard-key="${escapeHtml(caseKey(key))}">${escapeHtml(caseKey(key))}</button>`).join("")}
    </div>
  `).join("") + `
    <div class="rd-touch-keyboard-row">
      <button type="button" class="is-space" data-retail-keyboard-key=" ">Espacio</button>
    </div>
  `;
}

function openTouchKeyboard(input) {
  if (!input || input.disabled || touchKeyboardState.suppressOpen || !elements.touchKeyboard) return;

  const mode = input.dataset.retailKeyboard;
  if (!['text', 'decimal', 'integer'].includes(mode)) return;

  touchKeyboardState.target?.setAttribute("aria-expanded", "false");
  touchKeyboardState.target = input;
  touchKeyboardState.initialValue = input.value;
  touchKeyboardState.buffer = input.value;
  touchKeyboardState.mode = mode;
  const step = String(input.getAttribute("step") || "");
  const stepMatch = /^\d+\.(\d+)$/.exec(step);
  touchKeyboardState.decimalPlaces = mode === "decimal" && stepMatch
    ? stepMatch[1].length
    : null;
  touchKeyboardState.uppercase = true;
  touchKeyboardState.replaceOnNextKey = mode !== "text";
  input.setAttribute("aria-expanded", "true");
  input.setAttribute("aria-controls", "retailTouchKeyboard");
  elements.touchKeyboardTitle.textContent = input.dataset.retailKeyboardLabel || "Ingresar valor";
  renderTouchKeyboard();
  updateTouchKeyboardValue();
  elements.touchKeyboard.hidden = false;
  elements.touchKeyboard.setAttribute("aria-hidden", "false");
  document.body.classList.add("has-retail-touch-keyboard");
}

function closeTouchKeyboard(commit = true) {
  if (!elements.touchKeyboard || elements.touchKeyboard.hidden) return;
  const target = touchKeyboardState.target;

  if (!commit && target) {
    target.value = touchKeyboardState.initialValue;
    target.dispatchEvent(new Event("input", { bubbles: true }));
  }
  if (commit && target) {
    if (touchKeyboardState.mode !== "text") {
      let normalized = touchKeyboardState.buffer.endsWith(".")
        ? touchKeyboardState.buffer.slice(0, -1)
        : touchKeyboardState.buffer;
      if (
        normalized !== ""
        && touchKeyboardState.mode === "decimal"
        && Number.isInteger(touchKeyboardState.decimalPlaces)
        && Number.isFinite(Number(normalized))
      ) {
        normalized = Number(normalized).toFixed(touchKeyboardState.decimalPlaces);
      }
      target.value = normalized;
      target.dispatchEvent(new Event("input", { bubbles: true }));
    }
    target.dispatchEvent(new Event("change", { bubbles: true }));
  }

  elements.touchKeyboard.hidden = true;
  elements.touchKeyboard.setAttribute("aria-hidden", "true");
  document.body.classList.remove("has-retail-touch-keyboard");
  target?.setAttribute("aria-expanded", "false");
  touchKeyboardState.target = null;
  touchKeyboardState.initialValue = "";
  touchKeyboardState.buffer = "";
  touchKeyboardState.decimalPlaces = null;
  touchKeyboardState.replaceOnNextKey = false;
  touchKeyboardState.suppressOpen = true;
  target?.focus({ preventScroll: true });
  queueMicrotask(() => {
    touchKeyboardState.suppressOpen = false;
  });
}

function setTouchKeyboardInputValue(value) {
  const target = touchKeyboardState.target;
  if (!target) return;
  touchKeyboardState.buffer = value;
  if (touchKeyboardState.mode !== "text" && value.endsWith(".")) {
    updateTouchKeyboardValue();
    return;
  }
  target.value = value;
  target.dispatchEvent(new Event("input", { bubbles: true }));
  updateTouchKeyboardValue();
}

function appendTouchKeyboardKey(key) {
  const target = touchKeyboardState.target;
  if (!target) return;

  if (touchKeyboardState.mode !== "text") {
    const current = touchKeyboardState.replaceOnNextKey ? "" : touchKeyboardState.buffer;
    touchKeyboardState.replaceOnNextKey = false;
    if (key === "." && touchKeyboardState.mode !== "decimal") return;
    if (key === "." && current.includes(".")) return;
    let acceptedKey = key;
    if (
      key !== "."
      && current.includes(".")
      && Number.isInteger(touchKeyboardState.decimalPlaces)
    ) {
      const decimalsUsed = current.split(".")[1]?.length || 0;
      const remaining = touchKeyboardState.decimalPlaces - decimalsUsed;
      if (remaining <= 0) return;
      acceptedKey = key.slice(0, remaining);
    }
    const next = acceptedKey === "." && !current ? "0." : `${current}${acceptedKey}`;
    if (next.length <= 16) setTouchKeyboardInputValue(next);
    return;
  }

  const current = touchKeyboardState.buffer;
  const next = `${current}${key}`;
  const maximum = target.maxLength > 0 ? target.maxLength : 120;
  if (next.length > maximum) return;
  setTouchKeyboardInputValue(next);
}

function backspaceTouchKeyboardInput() {
  const target = touchKeyboardState.target;
  if (!target) return;
  const current = touchKeyboardState.buffer;

  if (touchKeyboardState.mode !== "text") {
    touchKeyboardState.replaceOnNextKey = false;
    setTouchKeyboardInputValue(current.slice(0, -1));
    return;
  }

  setTouchKeyboardInputValue(current.slice(0, -1));
}

function handleTouchKeyboardAction(action) {
  if (action === "accept") return closeTouchKeyboard(true);
  if (action === "cancel") return closeTouchKeyboard(false);
  if (action === "clear") {
    touchKeyboardState.replaceOnNextKey = false;
    return setTouchKeyboardInputValue("");
  }
  if (action === "backspace") return backspaceTouchKeyboardInput();
  if (action === "shift") {
    touchKeyboardState.uppercase = !touchKeyboardState.uppercase;
    renderTouchKeyboard();
  }
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
              <input id="${inputId}" type="number" min="${control.min}" max="${control.max}" step="${control.step}" value="${value}" inputmode="none" readonly data-retail-keyboard="integer" data-retail-keyboard-label="${escapeHtml(control.label)} en píxeles" data-typography-variable="${control.variable}" aria-label="${escapeHtml(control.label)} en píxeles">
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
  if (touchKeyboardState.target && elements.typographyDrawer.contains(touchKeyboardState.target)) {
    closeTouchKeyboard(true);
  }
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
  openTouchKeyboard(elements.clientSearch);
}

function assignClient(clientId) {
  const client = state.catalog.clients.find((entry) => String(entry.id) === String(clientId));
  if (!client) return;

  const list = activeList();
  if (String(list.clientId) !== String(client.id)) {
    list.clientId = String(client.id);
    list.priceOverrides = {};
  }
  persistLists();
  closeModal(elements.clientModal);
  renderAll();
  setMessage(`${client.name} asignado a la lista ${state.activeList + 1}.`);
}

function clearClient() {
  const list = activeList();
  if (list.clientId) {
    list.clientId = "";
    list.priceOverrides = {};
  }
  persistLists();
  closeModal(elements.clientModal);
  renderAll();
  setMessage(`La lista ${state.activeList + 1} quedó como venta sin cliente.`);
}

function renderPriceFields() {
  const list = priceEditingList();
  const client = clientFor(list);
  elements.priceFields.innerHTML = state.catalog.chicken_types.map((type) => {
    const base = client
      ? currentClientPrice(list, type.code)
      : currentGeneralPrice(type.code);
    const override = list.priceOverrides?.[type.code];
    const hasOverride = override !== undefined && override !== null && override !== "";
    const current = hasOverride ? override : base?.value;
    const hasCurrent = current !== undefined && current !== null && current !== "";
    const baseValue = base ? formatMoneyValue(base.value) : "";
    const baseLabel = client ? "Precio vigente del cliente" : "Precio general vigente";
    const help = !base
      ? (client
        ? "Este cliente no tiene un precio base vigente configurado en Directorio"
        : "Configura primero el precio general base vigente en Directorio")
      : (hasOverride
        ? `Precio personalizado de este ticket: S/ ${formatMoneyValue(override)} · Base vigente: S/ ${baseValue}`
        : `${baseLabel}: S/ ${baseValue} · ${escapeHtml(base.source)}`);
    return `
      <label class="rd-price-field">
        <span>${escapeHtml(type.name)}</span>
        <input type="number" min="0.01" max="99999999.99" step="0.01" inputmode="none" readonly data-retail-keyboard="decimal" data-retail-keyboard-label="Precio de ${escapeHtml(type.name)} por kilogramo" data-retail-price-code="${escapeHtml(type.code)}" data-retail-base-price="${baseValue}" value="${hasCurrent ? formatMoneyValue(current) : ""}" placeholder="${baseValue || "Sin precio base"}" ${!base ? "disabled" : ""}>
        <small>${help}</small>
      </label>
    `;
  }).join("");

  elements.priceForm.querySelector(".rd-modal-copy").textContent = client
    ? `Los precios vigentes de ${client.name} están precargados. Puedes cambiarlos solo para este ticket; no se modificará Directorio.`
    : `Los precios generales vigentes están precargados para la lista ${state.priceEditingListIndex + 1}. Puedes cambiarlos solo para este ticket; no se modificará Directorio.`;
  elements.clearPrices.disabled = false;
  elements.priceForm.querySelector('[type="submit"]').disabled = false;
}

function openPriceModal() {
  state.priceEditingListIndex = state.activeList;
  renderPriceFields();
  openModal(elements.priceModal);
  const firstInput = elements.priceFields.querySelector("input");
  firstInput?.focus();
  if (firstInput && !firstInput.disabled) openTouchKeyboard(firstInput);
}

function applyPrices(event) {
  event.preventDefault();
  const listIndex = state.priceEditingListIndex;
  const list = priceEditingList();
  const prices = {};
  const invalidPrices = [];

  elements.priceFields.querySelectorAll("[data-retail-price-code]").forEach((input) => {
    const baseRaw = String(input.dataset.retailBasePrice || "").trim();
    if (!baseRaw) return;

    const raw = input.value.trim();
    if (!raw) return;
    const value = Number(raw);
    if (
      !hasAtMostMoneyDecimals(raw)
      || !Number.isFinite(value)
      || value < 0.01
      || value > 99999999.99
    ) {
      const type = state.catalog.chicken_types.find(
        (item) => item.code === input.dataset.retailPriceCode
      );
      invalidPrices.push({
        label: type?.name || input.dataset.retailPriceCode || "Tipo de pollo",
        value: `Valor ingresado: ${raw || "vacío"}`
      });
      return;
    }
    const normalizedValue = roundMoney(value);
    if (moneyToCents(normalizedValue) === moneyToCents(baseRaw)) return;
    prices[input.dataset.retailPriceCode] = normalizedValue;
  });

  if (invalidPrices.length) {
    return showLocalActionIssue({
      caption: "Precio no aplicado",
      title: "Hay precios manuales no válidos",
      message: "No se modificaron los precios de la lista. Corrige los valores indicados.",
      details: invalidPrices,
      help: "Cada precio debe estar entre S/ 0.01 y S/ 99,999,999.99 y usar como máximo dos decimales."
    });
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
  renderAll();
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
    const typeLabel = {
      CAJA: "Caja",
      BANCO: "Cuenta bancaria",
      BILLETERA: "Billetera",
      OTRA: "Otra cuenta"
    }[String(account.type || "").toUpperCase()] || "Cuenta";
    const detail = [typeLabel, account.entity?.name, account.alias, account.bank, account.masked_number]
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
  const defaults = resolveRetailPaymentDefaults(state.catalog.financial);

  return {
    key: createDraftId(),
    methodId: defaults.methodId,
    accountId: defaults.accountId,
    amount: formatMoneyValue(Math.max(0, Number(amount || 0))),
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
          <input data-payment-amount type="number" min="0.01" max="999999999999.99" step="0.01" inputmode="none" readonly data-retail-keyboard="decimal" data-retail-keyboard-label="Importe de la forma de pago ${index + 1}" value="${escapeHtml(row.amount)}" required>
        </label>
        <label>
          <span>Referencia (opcional)</span>
          <input data-payment-reference type="text" maxlength="100" inputmode="none" readonly data-retail-keyboard="text" data-retail-keyboard-label="Referencia opcional de la forma de pago ${index + 1}" value="${escapeHtml(row.reference)}" placeholder="Número de operación (opcional)">
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
  return centsToMoney(state.paymentRows.reduce(
    (sum, row) => sum + Math.max(0, moneyToCents(row.amount)),
    0
  ));
}

function renderPaymentTotals() {
  syncPaymentRowsFromForm();
  const total = roundMoney(listTotals(activeList()).amount || 0);
  const received = paymentReceivedTotal();
  const pending = centsToMoney(Math.max(0, moneyToCents(total) - moneyToCents(received)));
  elements.paymentSaleTotal.textContent = formatMoney(total);
  elements.paymentReceivedTotal.textContent = formatMoney(received);
  elements.paymentPendingTotal.textContent = formatMoney(pending);
  elements.paymentPendingTotal.classList.toggle("is-settled", moneyToCents(pending) === 0);
}

function renderPaymentMode() {
  const client = clientFor(activeList());
  const hasClient = Boolean(client);
  state.paymentMode = resolveRetailPaymentMode(state.paymentMode, hasClient);
  const isCredit = state.paymentMode === RETAIL_PAYMENT_MODE_CREDIT;
  const methods = state.catalog.financial.methods || [];
  const accounts = state.catalog.financial.own_accounts || [];

  elements.paymentModeOptions.hidden = !hasClient;
  elements.paymentModeOptions.querySelectorAll("[data-retail-payment-mode]").forEach((button) => {
    const selected = button.dataset.retailPaymentMode === state.paymentMode;
    button.classList.toggle("is-selected", selected);
    button.setAttribute("aria-checked", String(selected));
  });
  elements.paymentNowPanel.hidden = isCredit;
  elements.paymentCreditPanel.hidden = !isCredit;
  elements.paymentNowPanel.querySelectorAll("input, select, button").forEach((control) => {
    control.disabled = isCredit;
  });
  if (!isCredit) {
    elements.addPayment.disabled = methods.length === 0 || accounts.length === 0;
  }
  elements.paymentCreditSummary.textContent = isCredit && client
    ? `El total de ${formatMoney(listTotals(activeList()).amount)} quedará pendiente a nombre de ${client.name}.`
    : "";
  elements.confirmPayment.textContent = isCredit
    ? "Registrar venta a crédito"
    : "Registrar pago y continuar";

  if (isCredit) {
    elements.paymentMessage.textContent = "No se registrará ningún cobro ahora. Podrás cobrar este ticket posteriormente desde Finanzas.";
    elements.paymentMessage.classList.remove("is-error");
    return;
  }

  elements.paymentMessage.textContent = methods.length && accounts.length
    ? "Puedes dividir el cobro entre efectivo, transferencia u otros métodos."
    : "Primero registra una entidad propia y al menos una cuenta o caja desde Finanzas.";
  elements.paymentMessage.classList.toggle("is-error", methods.length === 0 || accounts.length === 0);
}

function selectPaymentMode(requestedMode) {
  syncPaymentRowsFromForm();
  state.paymentMode = resolveRetailPaymentMode(requestedMode, Boolean(clientFor(activeList())));
  renderPaymentMode();
}

function openPaymentModal() {
  const list = activeList();
  const totals = listTotals(list);
  const client = clientFor(list);
  const methods = state.catalog.financial.methods || [];
  const accounts = state.catalog.financial.own_accounts || [];
  const paymentContext = [
    list.draftId,
    list.clientId || "SIN_CLIENTE",
    list.operationType,
    moneyToCents(totals.amount)
  ].join(":");

  if (state.paymentContext !== paymentContext || !state.paymentRows.length) {
    state.paymentRows = [newPaymentRow(totals.amount)];
    state.paymentMode = RETAIL_PAYMENT_MODE_NOW;
  }
  state.paymentContext = paymentContext;
  elements.paymentSummary.textContent = client
    ? `Elige si ${client.name} pagará ahora o si el ticket completo quedará como venta a crédito.`
    : "La venta no tiene un cliente identificado y debe quedar pagada completamente.";
  elements.addPayment.disabled = methods.length === 0 || accounts.length === 0;
  elements.confirmPayment.disabled = false;
  renderPaymentRows();
  renderPaymentMode();
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
  const methods = state.catalog.financial.methods || [];
  const accounts = state.catalog.financial.own_accounts || [];
  const saleTotal = roundMoney(listTotals(list).amount || 0);
  const received = paymentReceivedTotal();
  const saleTotalCents = moneyToCents(saleTotal);
  const receivedCents = moneyToCents(received);
  const client = clientFor(list);
  const paymentMode = resolveRetailPaymentMode(state.paymentMode, Boolean(client));
  state.paymentMode = paymentMode;

  if (paymentMode === RETAIL_PAYMENT_MODE_CREDIT) {
    const creditPayments = paymentsForRetailPaymentMode(
      state.paymentMode,
      Boolean(client),
      state.paymentRows
    );
    state.paymentRows = [];
    closeModal(elements.paymentModal);
    continueDispatchAfterPayment(creditPayments);
    return;
  }

  if (methods.length === 0 || accounts.length === 0) {
    elements.paymentMessage.textContent = methods.length === 0
      ? "No hay métodos de pago activos. Registra uno en Finanzas antes de continuar."
      : "No hay una cuenta o caja receptora activa. Regístrala en Finanzas antes de continuar.";
    elements.paymentMessage.classList.add("is-error");
    elements.paymentMessage.focus?.();
    return;
  }

  if (state.paymentRows.some((row) => (
    !Number(row.methodId)
    || !Number(row.accountId)
    || !hasAtMostMoneyDecimals(row.amount)
    || Number(row.amount) < 0.01
    || Number(row.amount) > 999999999999.99
  ))) {
    elements.paymentMessage.textContent = "Completa método, cuenta e importe con un máximo de dos decimales en cada forma de pago.";
    elements.paymentMessage.classList.add("is-error");
    return;
  }
  if (receivedCents > saleTotalCents) {
    elements.paymentMessage.textContent = "El total recibido no puede superar el importe de la venta.";
    elements.paymentMessage.classList.add("is-error");
    return;
  }
  if (!client && receivedCents !== saleTotalCents) {
    elements.paymentMessage.textContent = "La venta sin cliente debe quedar pagada completamente.";
    elements.paymentMessage.classList.add("is-error");
    return;
  }

  const payments = state.paymentRows.map((row) => ({
    idempotency_key: row.key,
    metodo_pago_id: Number(row.methodId),
    cuenta_destino_id: Number(row.accountId),
    moneda: "PEN",
    importe: formatMoneyValue(row.amount),
    referencia: String(row.reference || "").trim() || null
  }));
  closeModal(elements.paymentModal);
  continueDispatchAfterPayment(payments);
}

function addPaymentRow() {
  if (state.paymentRows.length >= 5) return;
  syncPaymentRowsFromForm();
  const remaining = centsToMoney(Math.max(
    0,
    moneyToCents(listTotals(activeList()).amount) - moneyToCents(paymentReceivedTotal())
  ));
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

  state.deliveryMode = null;
  elements.deliverySummary.textContent = `${client?.name || "Cliente"} · ${trayQuantityLabel(totals.trays)}. Elige cómo saldrá el pedido. Las bandejas permanecerán registradas a nombre del cliente en cualquiera de las dos opciones.`;
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

  renderDeliveryMode();
}

function renderDeliveryMode() {
  const trucks = state.catalog.delivery_trucks || [];
  const drivers = state.catalog.delivery_drivers || [];
  const companyTruckSelected = state.deliveryMode === RETAIL_DELIVERY_MODE_COMPANY_TRUCK;
  const customerPickupSelected = state.deliveryMode === RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP;
  const fleetReady = trucks.length > 0 && drivers.length > 0;
  const vehicleSelected = Number.isInteger(Number(elements.deliveryTruck.value))
    && Number(elements.deliveryTruck.value) > 0;
  const driverSelected = Number.isInteger(Number(elements.deliveryDriver.value))
    && Number(elements.deliveryDriver.value) > 0;

  elements.deliveryModeOptions.querySelectorAll("[data-retail-delivery-mode]").forEach((button) => {
    const selected = button.dataset.retailDeliveryMode === state.deliveryMode;
    button.classList.toggle("is-selected", selected);
    button.setAttribute("aria-checked", String(selected));
  });
  elements.deliveryFields.hidden = !companyTruckSelected;
  elements.deliveryTruck.disabled = !companyTruckSelected;
  elements.deliveryDriver.disabled = !companyTruckSelected;
  elements.confirmDelivery.disabled = !customerPickupSelected
    && !(companyTruckSelected && fleetReady && vehicleSelected && driverSelected);

  if (customerPickupSelected) {
    elements.deliveryTruck.value = "";
    elements.deliveryDriver.value = "";
    setDeliveryMessage("Se registrará el retiro directo. El cliente conservará el saldo de las bandejas, sin asignar camión ni chofer.");
    return;
  }

  if (companyTruckSelected) {
    setDeliveryMessage(fleetReady
      ? "Selecciona el camión y el chofer. Ambos quedarán vinculados al ticket y al movimiento de bandejas."
      : "No hay camión y chofer activos suficientes. Puedes elegir «Cliente retira directamente» o completar la flota antes de registrar.", !fleetReady);
    return;
  }

  setDeliveryMessage("Selecciona una modalidad para continuar.");
}

function selectDeliveryMode(requestedMode) {
  const mode = resolveRetailDeliveryMode(requestedMode);
  if (!mode) return;
  if (mode !== state.deliveryMode) {
    elements.deliveryTruck.value = "";
    elements.deliveryDriver.value = "";
  }
  state.deliveryMode = mode;
  renderDeliveryMode();
}

function showMissingPricesError(list, missingTypes = missingPriceTypes(list)) {
  const client = clientFor(list);
  const details = missingTypes.map((code) => {
    const type = state.catalog.chicken_types.find((item) => item.code === code);
    return {
      label: type?.name || code,
      value: client
        ? `El cliente ${client.name} no tiene un precio base vigente para este tipo de pollo.`
        : "No existe un precio general base vigente para este tipo de pollo."
    };
  });
  const message = client
    ? "Faltan precios base vigentes del cliente. Configúralos en Directorio antes de grabar; Cambiar precio solo personaliza una tarifa base existente para este ticket."
    : "Faltan precios generales base vigentes. Configúralos en Directorio antes de grabar; Cambiar precio solo personaliza una tarifa base existente para este ticket.";

  showRegistrationIssue(message, details, "Faltan precios para grabar");
}

function prepareDispatchRegistration() {
  const list = activeList();
  if (!list || list.saving || !list.items.length) return;
  const missingPrices = missingPriceTypes(list);
  if (missingPrices.length) {
    showMissingPricesError(list, missingPrices);
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
  if (!state.deliveryMode) {
    setDeliveryMessage("Selecciona si el cliente retira directamente o si se entregará con un camión de la empresa.", true);
    return;
  }

  const delivery = buildRetailDeliveryPayload(
    state.deliveryMode,
    elements.deliveryTruck.value,
    elements.deliveryDriver.value
  );
  if (!delivery) {
    setDeliveryMessage("Selecciona el camión y el chofer responsables de llevar las bandejas.", true);
    return;
  }

  closeModal(elements.deliveryModal);
  void saveDispatch(delivery, state.pendingPayments);
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
  setMessage(`${ticket.code} quedó registrado. La lista ${listIndex + 1} ya está disponible para un nuevo despacho.`);
}

function printRegisteredTicket(ticket) {
  return new Promise((resolve, reject) => {
    printWeightControlTicket(buildRetailTicketPrintData(ticket), {
      frameTitle: `Impresión de ${ticket.code}`,
      onSuccess: resolve,
      onError: () => reject(new Error("El ticket quedó guardado, pero no se pudo abrir la ventana de impresión."))
    });
  });
}

function showPrintError(ticket, error) {
  const message = error?.message || "El ticket quedó guardado, pero no se pudo abrir la impresión.";
  state.pendingPrintTicket = ticket;
  setMessage(message, true);
  showRetailError({
    caption: "Impresión pendiente",
    title: "Ticket guardado correctamente",
    message: `${ticket.code} sí quedó registrado. No vuelvas a capturar ni a pagar este despacho.`,
    help: "La lista ya quedó liberada para evitar duplicados. Puedes reintentar la impresión desde esta ventana.",
    canRetryPrint: true,
    details: [
      { label: "Impresión", value: message },
      { label: "Estado del registro", value: "Ticket, pesadas y pago guardados correctamente." }
    ]
  });
}

async function printTicketAndReport(ticket) {
  try {
    await printRegisteredTicket(ticket);
    state.pendingPrintTicket = null;
    setMessage(`${ticket.code} impreso o enviado a PDF correctamente.`);
    return true;
  } catch (error) {
    showPrintError(ticket, error);
    return false;
  }
}

async function retryPendingPrint() {
  const ticket = state.pendingPrintTicket;
  if (!ticket) return;

  closeModal(elements.errorModal);
  setMessage(`Abriendo nuevamente la impresión de ${ticket.code}...`);
  await printTicketAndReport(ticket);
}

async function saveDispatch(delivery = null, payments = []) {
  const listIndex = state.activeList;
  const list = state.lists[listIndex];
  if (!list || list.saving || !list.items.length) return;
  const missingPrices = missingPriceTypes(list);
  if (missingPrices.length) {
    showMissingPricesError(list, missingPrices);
    return;
  }

  list.saving = true;
  elements.station.inert = true;
  renderLists();
  setMessage(`Grabando ${list.operationType === OPERATION_RETURN ? "devolución" : "venta"} de la lista ${listIndex + 1}...`);

  try {
    const priceOverrides = Object.fromEntries(
      Object.entries(list.priceOverrides || {})
        .filter(([, value]) => Number.isFinite(Number(value)) && roundMoney(value) >= 0.01)
        .map(([code, value]) => [code, formatMoneyValue(value)])
    );
    let response;
    try {
      response = await apiRequest(`${RETAIL_API_BASE}/tickets`, {
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
            weighed_at: item.weighedAt,
            scale_reading: item.weightSource === "MANUAL" ? null : (item.scaleReading || null)
          }))
        })
      });
    } catch (error) {
      const presentation = getRetailDispatchErrorPresentation(error);
      setMessage(presentation.summary, true);
      showRetailError(presentation);
      return;
    }

    const ticket = response.data;
    state.pendingPayments = [];
    state.paymentRows = [];
    state.paymentContext = null;
    state.deliveryMode = null;
    setMessage(`${response.message || "Despacho minorista registrado correctamente."} Abriendo impresión; también puedes elegir Guardar como PDF.`);
    clearRegisteredList(listIndex, list.draftId, ticket);
    await printTicketAndReport(ticket);
  } finally {
    if (state.lists[listIndex] === list) list.saving = false;
    renderLists();
    if (!hasOpenRetailModal()) {
      elements.station.inert = false;
      elements.station.removeAttribute("aria-hidden");
    }
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
        <input type="number" min="0" max="100000" step="1" value="${Number(adjustment.additional_grams || 0)}" data-retail-setting-adjustment="${escapeHtml(adjustment.code)}" inputmode="none" readonly data-retail-keyboard="integer" data-retail-keyboard-label="Gramos adicionales para ${escapeHtml(adjustment.name)}">
      </label>
    </article>
  `).join("");
}

function renderPaymentDefaultSettings() {
  const financial = state.catalog.financial || {};
  const methods = Array.isArray(financial.methods) ? financial.methods : [];
  const accounts = Array.isArray(financial.own_accounts) ? financial.own_accounts : [];
  const defaults = resolveRetailPaymentDefaults(financial);

  elements.defaultPaymentMethod.innerHTML = methods.length
    ? paymentMethodOptions(defaults.methodId)
    : '<option value="">No hay métodos de pago activos</option>';
  elements.defaultPaymentAccount.innerHTML = accounts.length
    ? paymentAccountOptions(defaults.accountId)
    : '<option value="">No hay cuentas o cajas activas en PEN</option>';
  elements.defaultPaymentMethod.disabled = methods.length === 0;
  elements.defaultPaymentAccount.disabled = accounts.length === 0;
}

function fillSettingsForm() {
  const serialOptions = state.scale?.getState?.().serialOptions || RETAIL_SCALE_SERIAL_DEFAULTS;
  applySerialOptionsToForm(serialOptions);
  elements.settingsScaleName.textContent = state.catalog.scale?.name || "Balanza minorista";
  renderSettingsAdjustments();
  renderPaymentDefaultSettings();
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
  if (payload.payment_defaults && typeof payload.payment_defaults === "object") {
    const previousMethodId = state.catalog.financial.default_method_id;
    const previousAccountId = state.catalog.financial.default_account_id;
    const nextMethodId = normalizeRetailPaymentDefaultId(payload.payment_defaults.method_id);
    const nextAccountId = normalizeRetailPaymentDefaultId(payload.payment_defaults.account_id);

    state.catalog.financial.default_method_id = nextMethodId;
    state.catalog.financial.default_account_id = nextAccountId;

    if (previousMethodId !== nextMethodId || previousAccountId !== nextAccountId) {
      state.paymentRows = [];
      state.paymentContext = null;
    }
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
  const methods = state.catalog.financial.methods || [];
  const accounts = state.catalog.financial.own_accounts || [];
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

  const selectedMethodId = normalizeRetailPaymentDefaultId(elements.defaultPaymentMethod.value);
  const selectedAccountId = normalizeRetailPaymentDefaultId(elements.defaultPaymentAccount.value);
  if (methods.length && !methods.some((method) => Number(method.id) === selectedMethodId)) {
    setSettingsMessage("Selecciona un método de pago predeterminado activo.", true);
    return;
  }
  if (accounts.length && !accounts.some((account) => Number(account.id) === selectedAccountId)) {
    setSettingsMessage("Selecciona una cuenta o caja predeterminada activa en PEN.", true);
    return;
  }
  const paymentDefaults = methods.length && accounts.length
    ? {
        method_id: selectedMethodId,
        account_id: selectedAccountId
      }
    : {
        method_id: null,
        account_id: null
      };

  try {
    state.scale.configureSerial(serialOptionsFromForm());
  } catch (error) {
    setSettingsMessage(error.message, true);
    return;
  }

  elements.saveSettings.disabled = true;
  setSettingsMessage("Guardando configuración minorista...");
  try {
    const response = await apiRequest(`${RETAIL_API_BASE}/configuracion`, {
      method: "PUT",
      body: JSON.stringify({
        default_adjustment_code: elements.defaultAdjustment.value,
        adjustments,
        payment_defaults: paymentDefaults
      })
    });
    applySettingsResponse(response.data);
    fillSettingsForm();
    renderAll();
    setSettingsMessage(response.message || "Configuración guardada correctamente.");
    setMessage("Configuración de balanza, merma y cobro predeterminado actualizada.");
  } catch (error) {
    const validation = error.data?.errors ? Object.values(error.data.errors).flat()[0] : null;
    setSettingsMessage(validation || error.message, true);
  } finally {
    elements.saveSettings.disabled = false;
  }
}

async function connectBle() {
  scaleRestoreSuppressed = false;
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

  scaleRestoreSuppressed = false;
  setSettingsMessage("Selecciona el puerto serial de la balanza...");
  const connected = await state.scale.connectSerial({ serialOptions });
  setSettingsMessage(connected ? "Balanza serial conectada." : state.scale.getState().statusMessage, !connected);
}

async function disconnectScale() {
  scaleRestoreSuppressed = true;
  await state.scale.disconnect({ forget: false });
  setSettingsMessage("Balanza desconectada. La autorización quedó recordada.");
}

function applyManualScaleReading() {
  try {
    const scaleState = state.scale.setManualReading(elements.manualScaleInput.value);
    setSettingsMessage(`Lectura manual aplicada: ${formatWeight(scaleState.currentWeightKg)}.`);
  } catch (error) {
    setSettingsMessage(error.message, true);
  }
}

function openManualWeightModal() {
  elements.manualWeightEntry.value = state.liveWeight > 0 ? state.liveWeight.toFixed(3) : "";
  openModal(elements.manualWeightModal);
  elements.manualWeightEntry.focus();
  openTouchKeyboard(elements.manualWeightEntry);
}

function applyMainManualWeight(event) {
  event.preventDefault();
  try {
    state.scale.setManualReading(elements.manualWeightEntry.value);
    closeModal(elements.manualWeightModal);
    captureWeight();
  } catch (error) {
    showLocalActionIssue({
      caption: "Peso manual rechazado",
      title: "El peso manual no es válido",
      message: "No se aplicó el peso ingresado y la lectura anterior se conserva.",
      details: [
        { label: "Motivo", value: error?.message || "El valor no pudo interpretarse como un peso." },
        {
          label: "Valor ingresado",
          value: String(elements.manualWeightEntry.value || "").trim() || "Campo vacío"
        }
      ],
      help: "Cierra este aviso, ingresa un peso mayor que cero con hasta tres decimales y vuelve a presionar Agregar pesada manual."
    });
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
    chicken_types: (Array.isArray(data.chicken_types) ? data.chicken_types : [])
      .filter((type) => RETAIL_CHICKEN_TYPE_CODES.has(String(type?.code || "").toUpperCase())),
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
      own_accounts: Array.isArray(financial.own_accounts) ? financial.own_accounts : [],
      default_method_id: normalizeRetailPaymentDefaultId(financial.default_method_id),
      default_account_id: normalizeRetailPaymentDefaultId(financial.default_account_id)
    }
  };
}

function renderTrayOptions() {
  elements.trayType.innerHTML = state.catalog.tray_types.map((tray) => `
    <option value="${escapeHtml(tray.code)}">${escapeHtml(tray.name)} · tara ${Number(tray.weight_kg || 0).toFixed(3)} kg</option>
  `).join("");
  const tray = selectedTray();
  if (tray?.bird_capacity) {
    elements.birdsPerTray.value = Math.min(
      MAX_RETAIL_BIRD_QUANTITY,
      Math.max(1, Math.round(Number(tray.bird_capacity)))
    );
  }
}

async function loadCatalog() {
  state.loading = true;
  renderLists();
  let response;
  try {
    response = await apiRequest(`${RETAIL_API_BASE}/catalogo`);
  } catch (error) {
    state.loading = false;
    renderAll();
    const presentation = getRetailDispatchErrorPresentation(error);
    setMessage(presentation.summary, true);
    showRetailError({
      ...presentation,
      caption: presentation.caption || "Error de carga",
      title: "No se pudo preparar la estación",
      message: "El servidor no entregó los datos necesarios para registrar despachos.",
      help: presentation.action === "login"
        ? "La lista guardada en este navegador se conservará mientras vuelves a iniciar sesión."
        : "Vuelve a intentarlo. Si el problema continúa, comunica el motivo mostrado al administrador."
    });
    return;
  }

  try {
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
    ensureDressedChickenTypeSelection();

    const scaleStorageKey = buildRetailScaleStorageKey(RETAIL_STATION, branchId);
    if (RETAIL_STATION === "1") {
      migrateLegacyRetailScaleStorage({
        station: RETAIL_STATION,
        storage: globalThis.localStorage,
        targetKey: scaleStorageKey
      });
    }
    state.scale.setStorageKey(scaleStorageKey, {
      reload: true,
      persistCurrent: false
    });
    scaleRestoreReady = true;

    fillSettingsForm();
    state.loading = false;
    renderAll();
    setMessage("Estación minorista lista. Selecciona una lista y captura el peso para agregarlo directamente.");
    void restoreRememberedScale("carga inicial");
  } catch (error) {
    state.loading = false;
    renderAll();
    const message = "Los datos llegaron, pero la estación no pudo inicializarse correctamente.";
    setMessage(message, true);
    showRetailError({
      caption: "Error de inicialización",
      title: "No se pudo preparar la estación",
      message,
      help: "Recarga la página. Si el problema continúa, comunica este mensaje al administrador.",
      details: [{ label: "Interfaz", value: "No se modificó ni se registró ningún despacho." }]
    });
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

function syncLiveScaleState(scaleState = {}) {
  state.scaleState = scaleState;
  state.liveWeight = Number.isFinite(scaleState.currentWeightKg)
    ? Number(scaleState.currentWeightKg)
    : null;
  state.liveSource = scaleState.readingSource || null;
  state.liveReadingAt = scaleState.readingAt || null;
  state.liveReadingId = scaleState.readingId || null;
  state.liveReadingStatus = scaleState.inputStatus || "unknown";
  state.liveIsStable = Boolean(scaleState.isStable);
  state.liveIsFresh = Boolean(scaleState.isFresh);
  elements.rawWeightInput.value = Number.isFinite(state.liveWeight)
    ? state.liveWeight.toFixed(3)
    : "";
}

state.scale = new RetailScaleController({
  onReading({ state: scaleState }) {
    syncLiveScaleState(scaleState);
    renderWeightPreview();
  },
  onStatus(payload) {
    syncLiveScaleState(payload.state);
    renderScaleStatus(payload);
    renderWeightPreview();
  },
  onRaw({ raw }) {
    state.liveRaw = raw || "";
    elements.scaleRaw.textContent = `Trama: ${raw || "--"}`;
    elements.scaleRaw.title = raw || "";
  }
});

elements.trayCount.addEventListener("input", renderWeightPreview);
elements.birdsPerTray.addEventListener("input", renderWeightPreview);
elements.openCustomerDisplay?.addEventListener("click", openRetailCustomerDisplay);
elements.trayType.addEventListener("change", () => {
  const tray = selectedTray();
  if (tray?.bird_capacity) {
    elements.birdsPerTray.value = Math.min(
      MAX_RETAIL_BIRD_QUANTITY,
      Math.max(1, Math.round(Number(tray.bird_capacity)))
    );
  }
  renderWeightPreview();
});
elements.trayCountTrigger.addEventListener("click", () => openModal(elements.trayCountModal));
elements.birdsPerTrayTrigger.addEventListener("click", () => openModal(elements.birdsPerTrayModal));
elements.manualWeightTrigger.addEventListener("click", openManualWeightModal);
elements.openManualWeight.addEventListener("click", openManualWeightModal);
elements.manualWeightForm.addEventListener("submit", applyMainManualWeight);
elements.captureWeight.addEventListener("click", captureWeight);
elements.assignClient.addEventListener("click", openClientModal);
elements.removeWeighing.addEventListener("click", openRemoveWeighingModal);
elements.confirmRemoveWeighing.addEventListener("click", confirmRemoveSelectedWeighing);
elements.assignPrice.addEventListener("click", openPriceModal);
elements.saveDispatch.addEventListener("click", prepareDispatchRegistration);
elements.clientSearch.addEventListener("input", () => renderClientOptions(elements.clientSearch.value));
elements.priceForm.addEventListener("submit", applyPrices);
elements.clearPrices.addEventListener("click", clearPriceOverrides);
elements.paymentForm.addEventListener("submit", submitPayment);
elements.addPayment.addEventListener("click", addPaymentRow);
elements.paymentModeOptions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-retail-payment-mode]");
  if (option) selectPaymentMode(option.dataset.retailPaymentMode);
});
elements.paymentRows.addEventListener("input", renderPaymentTotals);
elements.paymentRows.addEventListener("change", renderPaymentTotals);
elements.deliveryModeOptions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-retail-delivery-mode]");
  if (option) selectDeliveryMode(option.dataset.retailDeliveryMode);
});
elements.deliveryTruck.addEventListener("change", renderDeliveryMode);
elements.deliveryDriver.addEventListener("change", renderDeliveryMode);
elements.deliveryForm.addEventListener("submit", submitDelivery);
elements.retryPrint.addEventListener("click", () => void retryPendingPrint());
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

document.addEventListener("focusin", (event) => {
  if (event.target.matches("[data-retail-keyboard]")) {
    openTouchKeyboard(event.target);
  }
});

document.addEventListener("click", (event) => {
  const keyboardKey = event.target.closest("[data-retail-keyboard-key]");
  if (keyboardKey) {
    appendTouchKeyboardKey(keyboardKey.dataset.retailKeyboardKey);
    return;
  }

  const keyboardAction = event.target.closest("[data-retail-keyboard-action]");
  if (keyboardAction) {
    handleTouchKeyboardAction(keyboardAction.dataset.retailKeyboardAction);
    return;
  }

  const keyboardInput = event.target.closest("[data-retail-keyboard]");
  if (keyboardInput) {
    openTouchKeyboard(keyboardInput);
    return;
  }

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

  const processedButton = event.target.closest("[data-retail-processed]");
  if (processedButton) {
    selectChickenType(processedButton.dataset.retailProcessed);
    renderAdjustments();
    renderWeightPreview();
    renderLists();
    setMessage("Pollo beneficiado activo: no se aplicará merma.");
    return;
  }

  const adjustmentButton = event.target.closest("[data-retail-adjustment]");
  if (adjustmentButton) {
    ensureDressedChickenTypeSelection();
    const station2ListIndex = Number(adjustmentButton.dataset.retailListAdjustment);
    if (
      RETAIL_STATION === "2"
      && Number.isInteger(station2ListIndex)
      && station2ListIndex >= 0
    ) {
      selectList(station2ListIndex);
      return;
    }
    state.adjustmentCode = adjustmentButton.dataset.retailAdjustment;
    state.sex = selectedAdjustment()?.sex || SEX_MALE;
    renderAdjustments();
    renderWeightPreview();
    setMessage(`Pollo pelado · ${selectedAdjustment()?.name || "presentación"} activa; se aplicará la merma configurada.`);
    return;
  }

  const addButton = event.target.closest("[data-retail-add-list]");
  if (addButton) {
    selectList(addButton.dataset.retailAddList);
    return;
  }

  const itemRow = event.target.closest("[data-retail-item]");
  if (itemRow) {
    if (state.lists.some((list) => list.saving)) return;
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
    if (elements.touchKeyboard && !elements.touchKeyboard.hidden) {
      closeTouchKeyboard(false);
      return;
    }
    closeTypographyDrawer();
    retailModals()
      .filter((modal) => !modal.hidden)
      .forEach(closeModal);
  }

  if (event.key === "Tab") {
    const openModalElement = retailModals().find((modal) => !modal.hidden);
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

let clockIntervalId = null;
let scaleRestoreReady = false;
let scaleRestorePromise = null;
let scaleRestoreSuppressed = false;
let teardownStarted = false;

function restoreRememberedScale(reason = "reactivación") {
  if (!scaleRestoreReady
    || scaleRestoreSuppressed
    || teardownStarted
    || document.visibilityState === "hidden") {
    return Promise.resolve(false);
  }

  const currentState = state.scale?.getState?.() || {};
  if (!currentState.autoConnectMode) {
    return Promise.resolve(false);
  }
  if (scaleRestorePromise) {
    return scaleRestorePromise;
  }

  const restorePromise = state.scale.restoreAuthorizedConnection()
    .catch((error) => {
      if (!teardownStarted) {
        setMessage(`No se pudo restaurar la balanza durante ${reason}: ${error.message}`, true);
      }
      return false;
    });
  const trackedRestorePromise = restorePromise.finally(() => {
    if (scaleRestorePromise === trackedRestorePromise) {
      scaleRestorePromise = null;
    }
  });
  scaleRestorePromise = trackedRestorePromise;
  return trackedRestorePromise;
}

function handleRetailPageShow(event) {
  // El controlador se destruye siempre al salir para liberar GATT/Serial. Si
  // el navegador conservó el documento en BFCache, se recarga una vez para
  // crear un controlador limpio y restaurar la preferencia de esta vista.
  if (event?.persisted && teardownStarted) {
    globalThis.location.reload();
    return;
  }
  void restoreRememberedScale("el regreso a la vista");
}

function handleRetailWindowFocus() {
  void restoreRememberedScale("la reactivación de la ventana");
}

function handleRetailVisibilityChange() {
  if (document.visibilityState === "hidden") {
    persistLists();
    return;
  }
  void restoreRememberedScale("la reactivación de la pestaña");
}

function teardownRetailStation(event) {
  persistLists();
  if (teardownStarted) return;
  teardownStarted = true;
  scaleRestoreReady = false;
  if (clockIntervalId) globalThis.clearInterval(clockIntervalId);
  if (retailCustomerDisplayHeartbeatTimer) {
    globalThis.clearInterval(retailCustomerDisplayHeartbeatTimer);
    retailCustomerDisplayHeartbeatTimer = null;
  }
  resetRetailCustomerDisplay();
  if (retailCustomerDisplayStorageTimer) {
    globalThis.clearTimeout(retailCustomerDisplayStorageTimer);
    retailCustomerDisplayStorageTimer = null;
  }
  retailCustomerDisplayChannel?.close();
  void state.scale.destroy();
}

globalThis.addEventListener("pagehide", teardownRetailStation);
globalThis.addEventListener("pageshow", handleRetailPageShow);
globalThis.addEventListener("focus", handleRetailWindowFocus);
document.addEventListener("visibilitychange", handleRetailVisibilityChange);

initializeTypography();
updateClock();
clockIntervalId = globalThis.setInterval(updateClock, 1000);
initializeRetailCustomerDisplaySync();
renderAll();
retailCustomerDisplayHeartbeatTimer = globalThis.setInterval(
  () => publishRetailCustomerDisplayState(),
  2000
);
renderScaleStatus(state.scale.getState());
loadCatalog();
