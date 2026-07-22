const STORAGE_KEY = "sistema-pollos-state-v1";

import { apiRequest } from "./api-client.js";
import { printWeightControlTicket } from "./ticket-printer.js";

const LEGACY_STORAGE_KEY = STORAGE_KEY;
const STORAGE_KEY_PREFIX = "sistema-pollos-state-v2";
const STORAGE_MIGRATION_KEY = "sistema-pollos-state-v2-migrated-branch";
let activeStorageKey = LEGACY_STORAGE_KEY;

const CUSTOMER_DISPLAY_CHANNEL_NAME = "sistema-pollos-pantalla-cliente-v1";
const CUSTOMER_DISPLAY_STORAGE_KEY = "sistema-pollos-pantalla-cliente-estado-v1";
const CUSTOMER_DISPLAY_PRODUCER_SESSION_KEY = "sistema-pollos-pantalla-cliente-productor-v1";
const CUSTOMER_DISPLAY_PRODUCER_ID = getCustomerDisplayProducerId();
const PEOPLE_STORAGE_KEY = "sistema-pollos-personas-v1";
const PERU_LOCALE = "es-PE";
const PERU_TIME_ZONE = "America/Lima";
const PERU_UTC_OFFSET = "-05:00";
const PERU_UTC_OFFSET_MINUTES = -5 * 60;

const FONT_SIZE_STORAGE_KEY = "sistema-pollos-font-size-v1";
const CUSTOM_FONT_SIZE_STORAGE_KEY = "sistema-pollos-custom-font-sizes-v1";
const FONT_SIZE_STEPS = ["compact", "normal", "large", "xlarge"];
const FONT_SIZE_LABELS = {
  compact: "Compacta",
  normal: "Normal",
  large: "Grande",
  xlarge: "Extra"
};
const CUSTOM_FONT_SIZE_GROUPS = [
  {
    title: "Balanzas",
    items: [
      { id: "scaleTitle", label: "Texto título balanza", type: "letra", cssVar: "--fs-scale-title", defaultPx: 16, min: 10, max: 34 },
      { id: "scaleNumber", label: "Número de peso balanza", type: "número", cssVar: "--fs-scale-number", defaultPx: 33, min: 18, max: 76 },
      { id: "scaleUnit", label: "Texto kg balanza", type: "letra", cssVar: "--fs-scale-unit", defaultPx: 14, min: 9, max: 30 },
      { id: "scalePanel", label: "Texto popup balanza", type: "letra", cssVar: "--fs-scale-panel-text", defaultPx: 12, min: 9, max: 24 }
    ]
  },
  {
    title: "Registro",
    items: [
      { id: "sectionTitle", label: "Títulos de secciones", type: "letra", cssVar: "--fs-section-title", defaultPx: 16, min: 11, max: 34 },
      { id: "formLabel", label: "Etiquetas formulario", type: "letra", cssVar: "--fs-form-label", defaultPx: 14, min: 10, max: 26 },
      { id: "inputNumber", label: "Números en campos", type: "número", cssVar: "--fs-input-number", defaultPx: 15, min: 10, max: 32 },
      { id: "buttonText", label: "Texto de botones", type: "letra", cssVar: "--fs-button-text", defaultPx: 15, min: 10, max: 30 },
      { id: "previewNumber", label: "Número peso neto", type: "número", cssVar: "--fs-preview-number", defaultPx: 28, min: 16, max: 48 },
      { id: "previewDetail", label: "Detalle peso neto", type: "letra", cssVar: "--fs-preview-detail", defaultPx: 16, min: 11, max: 30 }
    ]
  },
  {
    title: "Tickets de despacho",
    items: [
      { id: "truckTitle", label: "Título ticket", type: "letra", cssVar: "--fs-truck-title", defaultPx: 15, min: 10, max: 32 },
      { id: "truckLabel", label: "Etiquetas tickets", type: "letra", cssVar: "--fs-truck-label", defaultPx: 12, min: 9, max: 24 },
      { id: "truckNumber", label: "Números resumen ticket", type: "número", cssVar: "--fs-truck-number", defaultPx: 16, min: 10, max: 36 },
      { id: "truckClientName", label: "Nombre del cliente", type: "letra", cssVar: "--fs-truck-client-name", defaultPx: 12, min: 9, max: 28 },
      { id: "truckTableHead", label: "Encabezados tabla", type: "letra", cssVar: "--fs-truck-table-head", defaultPx: 12, min: 8, max: 24 },
      { id: "truckTableId", label: "Columna # registro", type: "número", cssVar: "--fs-truck-table-id", defaultPx: 13, min: 8, max: 26 },
      { id: "truckTableType", label: "Columna tipo pollo", type: "letra", cssVar: "--fs-truck-table-type", defaultPx: 12, min: 8, max: 26 },
      { id: "truckTableCount", label: "Columnas aves y javas", type: "número", cssVar: "--fs-truck-table-count", defaultPx: 13, min: 8, max: 28 },
      { id: "truckTableWeight", label: "Columnas pesos kg", type: "número", cssVar: "--fs-truck-table-weight", defaultPx: 13, min: 8, max: 28 },
      { id: "truckTableMeta", label: "Origen y hora", type: "letra", cssVar: "--fs-truck-table-meta", defaultPx: 13, min: 8, max: 26 },
      { id: "truckTotal", label: "Totales ticket", type: "número", cssVar: "--fs-truck-total", defaultPx: 12, min: 9, max: 28 }
    ]
  },
  {
    title: "Resumen y teclado",
    items: [
      { id: "heroTitle", label: "Titulo principal", type: "letra", cssVar: "--fs-hero-title", defaultPx: 25, min: 14, max: 48 },
      { id: "helperText", label: "Mensajes y estadisticas", type: "letra", cssVar: "--fs-helper-text", defaultPx: 14, min: 10, max: 28 },
      { id: "selectedLabel", label: "Etiquetas ticket seleccionado", type: "letra", cssVar: "--fs-selected-label", defaultPx: 12, min: 9, max: 24 },
      { id: "selectedNumber", label: "Números ticket seleccionado", type: "número", cssVar: "--fs-selected-number", defaultPx: 19, min: 11, max: 42 },
      { id: "keypadValue", label: "Número teclado táctil", type: "número", cssVar: "--fs-keypad-value", defaultPx: 42, min: 22, max: 80 },
      { id: "keypadNumber", label: "Botones teclado táctil", type: "número", cssVar: "--fs-keypad-number", defaultPx: 29, min: 16, max: 58 }
    ]
  }
];
const CUSTOM_FONT_SIZE_ITEMS = CUSTOM_FONT_SIZE_GROUPS.flatMap((group) => group.items);

const CHICKEN_TYPES = [
  { id: "pollo_vivo", apiCode: "POLLO_VIVO", label: "Pollo vivo", shortLabel: "Vivo", tagClass: "tag-pollo-vivo" },
  { id: "pollo_pelado", apiCode: "POLLO_PELADO", label: "Pollo pelado", shortLabel: "Pelado", tagClass: "tag-pollo-pelado" },
  { id: "pollo_beneficiado", apiCode: "POLLO_BENEFICIADO", label: "Pollo beneficiado", shortLabel: "Benef.", tagClass: "tag-pollo-beneficiado" }
];
const DISPATCH_CHICKEN_TYPES = CHICKEN_TYPES.filter((type) => type.id !== "pollo_beneficiado");
const TICKET_OPERATIONS = {
  DISPATCH: "DESPACHO",
  RETURN: "DEVOLUCION"
};
const RETURN_CONDITIONS = [
  { id: "vivo", apiCode: "VIVO", label: "Pollo vivo", shortLabel: "Vivo", tagClass: "tag-pollo-vivo" },
  { id: "muerto", apiCode: "MUERTO", label: "Pollo muerto", shortLabel: "Muerto", tagClass: "tag-pollo-muerto" }
];
const VALID_RETURN_CONDITIONS = new Set(RETURN_CONDITIONS.map((condition) => condition.id));
const CHICKEN_SEXES = [
  { id: "macho", apiCode: "MACHO", label: "Macho" },
  { id: "hembra", apiCode: "HEMBRA", label: "Hembra" }
];
const DEFAULT_CHICKEN_SEX = CHICKEN_SEXES[0].id;
const VALID_CHICKEN_SEXES = new Set(CHICKEN_SEXES.map((sex) => sex.id));

const LEGACY_TYPE_MAP = {
  vivo: "pollo_vivo",
  desplumado: "pollo_pelado",
  beneficiado: "pollo_beneficiado",
  hembra_cerrada: "pollo_vivo",
  hembra_abierta: "pollo_vivo",
  macho_cerrado: "pollo_pelado",
  macho_abierto: "pollo_pelado"
};

const CLIENTS = [
  { id: "cli-001", name: "Rogelio Oscar Cruz Alvino" },
  { id: "cli-002", name: "Ursula Huaman" },
  { id: "cli-003", name: "Avicola San Fernando" },
  { id: "cli-004", name: "Distribuidora Polleria Central" },
  { id: "cli-005", name: "Comercializadora El Corral" },
  { id: "cli-006", name: "Mercado Mayorista La Esperanza" }
];

const WAREHOUSE_DESTINATIONS = [
  {
    id: "destino-almacen-1",
    name: "Almacén 1",
    destinationType: "almacen",
    warehouseNumber: 1,
    databaseId: null,
    warehouseCode: "ALMACEN_1"
  },
  {
    id: "destino-almacen-2",
    name: "Almacén 2",
    destinationType: "almacen",
    warehouseNumber: 2,
    databaseId: null,
    warehouseCode: "ALMACEN_2"
  }
];
const WAREHOUSE_ORIGINS = WAREHOUSE_DESTINATIONS.map((warehouse) => ({
  id: `origen-almacen-${warehouse.warehouseNumber}`,
  name: warehouse.name,
  nombre: warehouse.name,
  originType: "almacen",
  warehouseNumber: warehouse.warehouseNumber,
  databaseId: warehouse.databaseId,
  warehouseCode: warehouse.warehouseCode,
  dni: "",
  direccion: ""
}));

const VALID_TYPES = new Set(DISPATCH_CHICKEN_TYPES.map((type) => type.id));
const VALID_WEIGHT_SOURCES = new Set(["1", "2", "manual"]);
const CRATE_TYPES = [
  { id: "java_700", apiCode: "JAVA_700", label: "Java 7.00 kg", weightKg: 7.0 },
  { id: "java_690", apiCode: "JAVA_690", label: "Java 6.90 kg", weightKg: 6.9 }
];
const DEFAULT_CRATE_TYPE_ID = CRATE_TYPES[0].id;
const VALID_CRATE_TYPE_IDS = new Set(CRATE_TYPES.map((crate) => crate.id));
const MOBILE_PANEL_IDS = new Set(["registro", "camiones"]);
const COMPACT_LAYOUT_QUERY = "(max-width: 980px)";
const TAP_MAX_MOVEMENT_PX = 12;
const TOUCH_TAP_MAX_HOLD_MS = 450;
const MAX_TRUCKS = 10;
const MAX_TRUCK_PLATE_LENGTH = 15;
const SCALE_IDS = [1, 2];
const MAX_SCALE_RAW_LENGTH = 160;
const SCALE_PERSIST_INTERVAL_MS = 1000;
const SCALE_AUTO_RECONNECT_DELAY_MS = 1200;
const SCALE_AUTO_RECONNECT_MAX_DELAY_MS = 30000;
const SCALE_MAX_SERIAL_BUFFER_LENGTH = 2048;
const SCALE_READING_MAX_AGE_MS = 15000;
const SCALE_CAPTURE_MAX_AGE_MS = 60000;
const SCALE_STABILITY_TOLERANCE_KG = 0.02;
const SCALE_STABLE_READING_COUNT = 2;
const SCALE_STABILITY_WINDOW_MS = 1500;
const SCALE_ZERO_CONFIRMATION_COUNT = 3;
const SCALE_ZERO_CONFIRMATION_MS = 300;
const KG_PER_LB = 0.45359237;
const SCALE_SERIAL_DEFAULTS = {
  baudRate: 9600,
  dataBits: 8,
  stopBits: 1,
  parity: "none",
  flowControl: "none"
};
const SCALE_SERIAL_DATA_BITS = new Set([7, 8]);
const SCALE_SERIAL_STOP_BITS = new Set([1, 2]);
const SCALE_SERIAL_PARITIES = new Set(["none", "even", "odd"]);
const SCALE_SERIAL_FLOW_CONTROLS = new Set(["none", "hardware"]);
const SCALE_BLE_PROFILES = [
  {
    id: "sig-weight-scale",
    label: "BLE Weight Scale",
    service: "0000181d-0000-1000-8000-00805f9b34fb",
    characteristics: ["00002a9d-0000-1000-8000-00805f9b34fb"],
    parser: "sig-weight"
  },
  {
    id: "nordic-uart",
    label: "Nordic UART",
    service: "6e400001-b5a3-f393-e0a9-e50e24dcca9e",
    characteristics: ["6e400003-b5a3-f393-e0a9-e50e24dcca9e"],
    parser: "text"
  },
  {
    id: "hm10-ffe0",
    label: "HM-10 FFE0",
    service: "0000ffe0-0000-1000-8000-00805f9b34fb",
    characteristics: ["0000ffe1-0000-1000-8000-00805f9b34fb"],
    parser: "text"
  },
  {
    id: "fff0-serial",
    label: "Serial FFF0",
    service: "0000fff0-0000-1000-8000-00805f9b34fb",
    characteristics: [
      "0000fff1-0000-1000-8000-00805f9b34fb",
      "0000fff4-0000-1000-8000-00805f9b34fb"
    ],
    parser: "text"
  },
  {
    id: "ffe5-serial",
    label: "Serial FFE5",
    service: "0000ffe5-0000-1000-8000-00805f9b34fb",
    characteristics: ["0000ffe4-0000-1000-8000-00805f9b34fb"],
    parser: "text"
  }
];
const SCALE_BLE_SERVICE_UUIDS = Array.from(new Set(SCALE_BLE_PROFILES.map((profile) => profile.service)));
let liveDirectoryClients = null;
let liveDirectoryProviders = null;
let operationCatalog = null;
let dailyJourneyPlan = null;
let directoryLoadPromise = null;

function getCustomerDisplayProducerId() {
  try {
    const existingId = sessionStorage.getItem(CUSTOMER_DISPLAY_PRODUCER_SESSION_KEY);
    if (existingId) {
      return existingId;
    }

    const generatedId = globalThis.crypto?.randomUUID?.()
      || `despacho-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    sessionStorage.setItem(CUSTOMER_DISPLAY_PRODUCER_SESSION_KEY, generatedId);
    return generatedId;
  } catch {
    return globalThis.crypto?.randomUUID?.()
      || `despacho-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }
}

const elements = {
  backToMenuBtn: document.getElementById("backToMenuBtn"),
  openCustomerDisplayBtn: document.getElementById("openCustomerDisplayBtn"),
  appShell: document.getElementById("appShell"),
  form: document.getElementById("cageForm"),
  addWeighingBtn: document.getElementById("addWeighingBtn"),
  mobileTabs: document.querySelectorAll("[data-mobile-panel-target]"),
  typeButtons: document.querySelectorAll(".type-btn"),
  sexButtons: document.querySelectorAll("[data-sex]"),
  truckSelect: document.getElementById("truckSelect"),
  selectProviderBtn: document.getElementById("selectProviderBtn"),
  selectedProviderName: document.getElementById("selectedProviderName"),
  selectedProviderPlateLabel: document.getElementById("selectedProviderPlateLabel"),
  truckPlateField: document.getElementById("truckPlateField"),
  truckPlate: document.getElementById("truckPlate"),
  truckPlateHelp: document.getElementById("truckPlateHelp"),
  dailyProviderCount: document.getElementById("dailyProviderCount"),
  dailyProviderList: document.getElementById("dailyProviderList"),
  birdCount: document.getElementById("birdCount"),
  javaCount: document.getElementById("javaCount"),
  crateType: document.getElementById("crateType"),
  weightSource: document.getElementById("weightSource"),
  manualWeightField: document.getElementById("manualWeightField"),
  manualWeight: document.getElementById("manualWeight"),
  selectedWeightValue: document.getElementById("selectedWeightValue"),
  selectedWeightBreakdown: document.getElementById("selectedWeightBreakdown"),
  formMessage: document.getElementById("formMessage"),
  returnTicketBtn: document.getElementById("returnTicketBtn"),
  trucksGrid: document.getElementById("trucksGrid"),
  selectedTruckDetails: document.getElementById("selectedTruckDetails"),
  globalStats: document.getElementById("globalStats"),
  jsonModal: document.getElementById("jsonModal"),
  jsonOutput: document.getElementById("jsonOutput"),
  configMenuBtn: document.getElementById("configMenuBtn"),
  configMenu: document.getElementById("configMenu"),
  closeConfigMenuBtn: document.getElementById("closeConfigMenuBtn"),
  openJsonBtn: document.getElementById("openJsonBtn"),
  closeJsonBtn: document.getElementById("closeJsonBtn"),
  copyJsonBtn: document.getElementById("copyJsonBtn"),
  resetDayBtn: document.getElementById("resetDayBtn"),
  fontDecreaseBtn: document.getElementById("fontDecreaseBtn"),
  fontIncreaseBtn: document.getElementById("fontIncreaseBtn"),
  fontResetBtn: document.getElementById("fontResetBtn"),
  fontSizeStatus: document.getElementById("fontSizeStatus"),
  openFontSidebarBtn: document.getElementById("openFontSidebarBtn"),
  fontSidebarOverlay: document.getElementById("fontSidebarOverlay"),
  closeFontSidebarBtn: document.getElementById("closeFontSidebarBtn"),
  resetFontSizesBtn: document.getElementById("resetFontSizesBtn"),
  fontSizeControls: document.getElementById("fontSizeControls"),
  clientModal: document.getElementById("clientModal"),
  clientModalTitle: document.getElementById("clientModalTitle"),
  closeClientModalBtn: document.getElementById("closeClientModalBtn"),
  clientModalTruckLabel: document.getElementById("clientModalTruckLabel"),
  clientSearch: document.getElementById("clientSearch"),
  clientList: document.getElementById("clientList"),
  deliveryTruckModal: document.getElementById("deliveryTruckModal"),
  closeDeliveryTruckModalBtn: document.getElementById("closeDeliveryTruckModalBtn"),
  deliveryTruckTicketLabel: document.getElementById("deliveryTruckTicketLabel"),
  deliveryTruckSearch: document.getElementById("deliveryTruckSearch"),
  deliveryTruckList: document.getElementById("deliveryTruckList"),
  deliveryDriverModal: document.getElementById("deliveryDriverModal"),
  closeDeliveryDriverModalBtn: document.getElementById("closeDeliveryDriverModalBtn"),
  deliveryDriverTicketLabel: document.getElementById("deliveryDriverTicketLabel"),
  deliveryDriverSearch: document.getElementById("deliveryDriverSearch"),
  deliveryDriverList: document.getElementById("deliveryDriverList"),
  itemModal: document.getElementById("itemModal"),
  itemEditForm: document.getElementById("itemEditForm"),
  closeItemModalBtn: document.getElementById("closeItemModalBtn"),
  deleteItemBtn: document.getElementById("deleteItemBtn"),
  itemCageNumber: document.getElementById("itemCageNumber"),
  itemTruckName: document.getElementById("itemTruckName"),
  itemHour: document.getElementById("itemHour"),
  itemFormMessage: document.getElementById("itemFormMessage"),
  editType: document.getElementById("editType"),
  editBirdCount: document.getElementById("editBirdCount"),
  editJavaCount: document.getElementById("editJavaCount"),
  editSexButtons: document.querySelectorAll("[data-edit-sex]"),
  editCrateType: document.getElementById("editCrateType"),
  editWeight: document.getElementById("editWeight"),
  editWeightSource: document.getElementById("editWeightSource"),
  editSelectProviderBtn: document.getElementById("editSelectProviderBtn"),
  editSelectedProviderName: document.getElementById("editSelectedProviderName"),
  editTruckPlateField: document.getElementById("editTruckPlateField"),
  editTruckPlate: document.getElementById("editTruckPlate"),
  editTruckPlateHelp: document.getElementById("editTruckPlateHelp"),
  providerModal: document.getElementById("providerModal"),
  providerModalTitle: document.getElementById("providerModalTitle"),
  closeProviderModalBtn: document.getElementById("closeProviderModalBtn"),
  providerSearch: document.getElementById("providerSearch"),
  providerModalSelection: document.getElementById("providerModalSelection"),
  providerList: document.getElementById("providerList"),
  errorModal: document.getElementById("errorModal"),
  closeErrorModalBtn: document.getElementById("closeErrorModalBtn"),
  errorModalTitle: document.getElementById("errorModalTitle"),
  errorModalMessage: document.getElementById("errorModalMessage"),
  errorModalDetails: document.getElementById("errorModalDetails"),
  touchSelectModal: document.getElementById("touchSelectModal"),
  touchSelectTitle: document.getElementById("touchSelectTitle"),
  touchSelectCurrentValue: document.getElementById("touchSelectCurrentValue"),
  touchSelectOptions: document.getElementById("touchSelectOptions"),
  touchSelectCloseBtn: document.getElementById("touchSelectCloseBtn"),
  numericPadModal: document.getElementById("numericPadModal"),
  numericPadTitle: document.getElementById("numericPadTitle"),
  numericPadValue: document.getElementById("numericPadValue"),
  numericPadMessage: document.getElementById("numericPadMessage"),
  numericPadDotBtn: document.getElementById("numericPadDotBtn"),
  numericPadCloseBtn: document.getElementById("numericPadCloseBtn"),
  numericPadBackBtn: document.getElementById("numericPadBackBtn"),
  numericPadClearBtn: document.getElementById("numericPadClearBtn"),
  numericPadOkBtn: document.getElementById("numericPadOkBtn"),
  numericPadKeys: document.querySelectorAll("[data-keypad-key]"),
  textTouchKeyboard: document.getElementById("textTouchKeyboard"),
  textTouchKeyboardTitle: document.getElementById("textTouchKeyboardTitle"),
  textTouchKeyboardValue: document.getElementById("textTouchKeyboardValue"),
  textTouchKeyboardKeys: document.getElementById("textTouchKeyboardKeys"),
  scaleDisplays: {
    1: document.getElementById("display-scale-1"),
    2: document.getElementById("display-scale-2")
  },
  miniScaleDisplays: {
    1: document.getElementById("display-scale-mini-1"),
    2: document.getElementById("display-scale-mini-2")
  },
  scaleInputs: {
    1: document.getElementById("input-scale-1"),
    2: document.getElementById("input-scale-2")
  },
  scaleSetButtons: {
    1: document.getElementById("set-scale-1"),
    2: document.getElementById("set-scale-2")
  },
  scaleSettingsModals: {
    1: document.getElementById("scaleSettingsModal1"),
    2: document.getElementById("scaleSettingsModal2")
  },
  scaleSettingsOpenButtons: {
    1: document.getElementById("openScaleSettings1"),
    2: document.getElementById("openScaleSettings2")
  },
  scaleSettingsCloseButtons: {
    1: document.getElementById("closeScaleSettings1"),
    2: document.getElementById("closeScaleSettings2")
  },
  scaleCaptureButtons: {
    1: document.getElementById("capture-scale-1"),
    2: document.getElementById("capture-scale-2")
  },
  scaleConnectBleButtons: {
    1: document.getElementById("connect-ble-scale-1"),
    2: document.getElementById("connect-ble-scale-2")
  },
  scaleConnectSerialButtons: {
    1: document.getElementById("connect-serial-scale-1"),
    2: document.getElementById("connect-serial-scale-2")
  },
  scaleDisconnectButtons: {
    1: document.getElementById("disconnect-scale-1"),
    2: document.getElementById("disconnect-scale-2")
  },
  scaleStatusDisplays: {
    1: document.getElementById("scale-status-1"),
    2: document.getElementById("scale-status-2")
  },
  scaleLastDisplays: {
    1: document.getElementById("scale-last-1"),
    2: document.getElementById("scale-last-2")
  },
  scaleRawDisplays: {
    1: document.getElementById("scale-raw-1"),
    2: document.getElementById("scale-raw-2")
  },
  scaleSerialBaudInputs: {
    1: document.getElementById("serial-baud-scale-1"),
    2: document.getElementById("serial-baud-scale-2")
  },
  scaleSerialDataBitsSelects: {
    1: document.getElementById("serial-data-bits-scale-1"),
    2: document.getElementById("serial-data-bits-scale-2")
  },
  scaleSerialStopBitsSelects: {
    1: document.getElementById("serial-stop-bits-scale-1"),
    2: document.getElementById("serial-stop-bits-scale-2")
  },
  scaleSerialParitySelects: {
    1: document.getElementById("serial-parity-scale-1"),
    2: document.getElementById("serial-parity-scale-2")
  },
  scaleSerialFlowControlSelects: {
    1: document.getElementById("serial-flow-scale-1"),
    2: document.getElementById("serial-flow-scale-2")
  },
  scaleSerialSaveButtons: {
    1: document.getElementById("save-serial-scale-1"),
    2: document.getElementById("save-serial-scale-2")
  }
};

let editingContext = null;
let clientModalTruckId = null;
let clientModalMode = "destination";
let deliverySelectionContext = null;
let providerPickerContext = null;
let editSelectedOrigin = null;
let editingChickenSex = DEFAULT_CHICKEN_SEX;
let scaleTextDecoder = new TextDecoder("utf-8");
let scaleConnections = { 1: null, 2: null };
let scaleConnectionGenerations = { 1: 0, 2: 0 };
let scaleReadingSequences = { 1: 0, 2: 0 };
let lastRegisteredScaleReadingIds = { 1: null, 2: null };
let scaleRenderFrames = { 1: null, 2: null };
let scaleLastPersistedAt = { 1: 0, 2: 0 };
let scalePersistTimers = { 1: null, 2: null };
let capturedScaleReadings = { 1: null, 2: null };
let scaleRestorePromise = null;
let scaleRestorePromiseGeneration = null;
let scaleRestoreTimer = null;
let scaleRestoreAttempt = 0;
let scaleLifecycleGeneration = 0;
let scalePageActive = true;
const pendingTicketRegistrations = new Set();
let keypadContext = {
  targetInput: null,
  value: "",
  allowDecimal: true,
  decimalPlaces: null,
  replaceOnNextKey: false,
  fieldLabel: "",
  validationMessage: ""
};
let textKeyboardContext = {
  targetInput: null,
  initialValue: "",
  value: "",
  uppercase: true
};
let suppressTextKeyboardOpen = false;
let touchSelectContext = {
  targetSelect: null,
  fieldLabel: ""
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

function normalizeTicketOperation(operationType) {
  const normalized = String(operationType || "").trim().toUpperCase();
  return normalized === TICKET_OPERATIONS.RETURN
    ? TICKET_OPERATIONS.RETURN
    : TICKET_OPERATIONS.DISPATCH;
}

function normalizeReturnCondition(condition) {
  const normalized = String(condition || "").trim().toLowerCase();
  if (VALID_RETURN_CONDITIONS.has(normalized)) {
    return normalized;
  }

  if (["muerto", "dead", "pollo_muerto", "pm"].includes(normalized)) {
    return "muerto";
  }

  return "vivo";
}

function getReturnConditionMeta(condition) {
  const normalizedCondition = normalizeReturnCondition(condition);
  return RETURN_CONDITIONS.find((item) => item.id === normalizedCondition) || RETURN_CONDITIONS[0];
}

function getTruckOperationType(truck) {
  return normalizeTicketRegistration(truck?.registration)?.operationType
    || normalizeTicketOperation(truck?.operationType || truck?.tipoOperacion || truck?.tipo_operacion);
}

function isReturnTicket(truck) {
  return getTruckOperationType(truck) === TICKET_OPERATIONS.RETURN;
}

function getSelectedTruck() {
  const activeTruckId = elements.truckSelect?.value || state?.trucks?.[0]?.id;
  return state?.trucks?.find((truck) => truck.id === activeTruckId) || null;
}

function getTicketOperationLabel(truck) {
  return isReturnTicket(truck) ? "Devolución" : "Despacho";
}

function normalizeTicketRegistration(registration) {
  const id = Number(registration?.id);
  const code = String(registration?.code || "").trim();

  if (!Number.isInteger(id) || id <= 0 || !code) {
    return null;
  }

  return {
    id,
    code,
    operationType: normalizeTicketOperation(registration?.operationType || registration?.operation_type),
    status: String(registration?.status || "CERRADO"),
    operatingDate: String(registration?.operatingDate || registration?.operating_date || ""),
    registeredAt: String(registration?.registeredAt || registration?.registered_at || ""),
    destination: registration?.destination
      ? {
          type: String(registration.destination.type || ""),
          id: String(registration.destination.id || ""),
          name: String(registration.destination.name || "")
        }
      : null
  };
}

function createDefaultTruck(index) {
  return {
    id: `camion-${index + 1}`,
    name: `Ticket ${index + 1}`,
    draftId: createDraftId(),
    operationType: TICKET_OPERATIONS.DISPATCH,
    registration: null,
    clientId: null,
    destination: null,
    cages: []
  };
}

function isTruckRegistered(truck) {
  return Boolean(normalizeTicketRegistration(truck?.registration));
}

function getTruckTicketLabel(truck) {
  return normalizeTicketRegistration(truck?.registration)?.code || truck?.name || "Ticket";
}

function resetTruckColumn(truck) {
  truck.draftId = createDraftId();
  truck.operationType = TICKET_OPERATIONS.DISPATCH;
  truck.registration = null;
  truck.clientId = null;
  truck.destination = null;
  truck.cages = [];
}

function normalizeDispatchTicketName(name, index) {
  const normalizedName = String(name || "").trim();
  const legacyMatch = normalizedName.match(/^cami[oó]n\s+(\d+)$/i);

  if (legacyMatch) {
    return `Ticket ${legacyMatch[1]}`;
  }

  return normalizedName || `Ticket ${index + 1}`;
}

function createDefaultTrucks(count = MAX_TRUCKS) {
  return Array.from({ length: count }, (_, index) => createDefaultTruck(index));
}

function ensureTruckSlots(trucks, count = MAX_TRUCKS) {
  const normalizedTrucks = Array.isArray(trucks) ? [...trucks] : [];
  const usedIds = new Set(normalizedTrucks.map((truck) => truck.id));

  for (let index = 0; normalizedTrucks.length < count; index += 1) {
    const defaultTruck = createDefaultTruck(index);

    if (usedIds.has(defaultTruck.id)) {
      continue;
    }

    normalizedTrucks.push(defaultTruck);
    usedIds.add(defaultTruck.id);
  }

  return normalizedTrucks;
}

function normalizeScaleSerialOptions(options = {}, fallback = SCALE_SERIAL_DEFAULTS) {
  const source = options && typeof options === "object" ? options : {};
  const base = fallback && typeof fallback === "object" ? fallback : SCALE_SERIAL_DEFAULTS;
  const baudRate = Number(source.baudRate ?? base.baudRate);
  const dataBits = Number(source.dataBits ?? base.dataBits);
  const stopBits = Number(source.stopBits ?? base.stopBits);
  const parity = String(source.parity ?? base.parity).toLowerCase();
  const flowControl = String(source.flowControl ?? base.flowControl).toLowerCase();

  return {
    baudRate: Number.isInteger(baudRate) && baudRate >= 300 && baudRate <= 921600
      ? baudRate
      : SCALE_SERIAL_DEFAULTS.baudRate,
    dataBits: SCALE_SERIAL_DATA_BITS.has(dataBits) ? dataBits : SCALE_SERIAL_DEFAULTS.dataBits,
    stopBits: SCALE_SERIAL_STOP_BITS.has(stopBits) ? stopBits : SCALE_SERIAL_DEFAULTS.stopBits,
    parity: SCALE_SERIAL_PARITIES.has(parity) ? parity : SCALE_SERIAL_DEFAULTS.parity,
    flowControl: SCALE_SERIAL_FLOW_CONTROLS.has(flowControl) ? flowControl : SCALE_SERIAL_DEFAULTS.flowControl
  };
}

function createDefaultScaleState(id) {
  return {
    id,
    currentWeight: null,
    lastRaw: "",
    lastRawAt: null,
    updatedAt: null,
    readingValid: false,
    readingStable: false,
    readingStatus: "unknown",
    readingRaw: "",
    readingMode: null,
    readingDeviceName: "",
    readingGeneration: 0,
    readingId: null,
    connectionMode: null,
    deviceName: "",
    autoConnectMode: null,
    serialPortInfo: null,
    serialOptions: { ...SCALE_SERIAL_DEFAULTS },
    bleDeviceId: ""
  };
}

function normalizeSerialPortInfo(info) {
  if (!info || typeof info !== "object") {
    return null;
  }

  const usbVendorId = info.usbVendorId === null || info.usbVendorId === undefined
    ? null
    : Number(info.usbVendorId);
  const usbProductId = info.usbProductId === null || info.usbProductId === undefined
    ? null
    : Number(info.usbProductId);
  const hasMatchIndex = Object.prototype.hasOwnProperty.call(info, "matchIndex")
    && info.matchIndex !== null
    && info.matchIndex !== "";
  const hasPortIndex = Object.prototype.hasOwnProperty.call(info, "portIndex")
    && info.portIndex !== null
    && info.portIndex !== "";
  const matchIndex = hasMatchIndex ? Number(info.matchIndex) : null;
  const portIndex = hasPortIndex ? Number(info.portIndex) : null;
  const bluetoothServiceClassId = String(info.bluetoothServiceClassId || "").trim();
  const normalized = {
    usbVendorId: Number.isInteger(usbVendorId) && usbVendorId >= 0 ? usbVendorId : null,
    usbProductId: Number.isInteger(usbProductId) && usbProductId >= 0 ? usbProductId : null,
    bluetoothServiceClassId: bluetoothServiceClassId || null,
    matchIndex: Number.isInteger(matchIndex) && matchIndex >= 0 ? matchIndex : null,
    portIndex: Number.isInteger(portIndex) && portIndex >= 0 ? portIndex : null
  };

  return normalized.usbVendorId !== null
    || normalized.usbProductId !== null
    || normalized.bluetoothServiceClassId
    || normalized.matchIndex !== null
    || normalized.portIndex !== null
    ? normalized
    : null;
}

function normalizeScaleState(scale, id, options = {}) {
  const fallback = createDefaultScaleState(id);
  const rawCurrentWeight = scale?.currentWeight;
  const currentWeight = rawCurrentWeight === null || rawCurrentWeight === undefined || rawCurrentWeight === ""
    ? null
    : roundWeight(Number(rawCurrentWeight));
  const autoConnectMode = ["ble", "serial"].includes(scale?.autoConnectMode)
    ? scale.autoConnectMode
    : null;
  const invalidateReading = Boolean(options.invalidateReading);
  const hasCurrentWeight = Number.isFinite(currentWeight) && currentWeight >= 0;
  const readingValid = !invalidateReading && hasCurrentWeight && Boolean(scale?.readingValid);

  return {
    ...fallback,
    currentWeight: hasCurrentWeight ? currentWeight : null,
    lastRaw: String(scale?.lastRaw || "").slice(-MAX_SCALE_RAW_LENGTH),
    lastRawAt: scale?.lastRawAt || null,
    updatedAt: scale?.updatedAt || null,
    readingValid,
    readingStable: readingValid && Boolean(scale?.readingStable),
    readingStatus: invalidateReading && hasCurrentWeight
      ? "stale"
      : String(scale?.readingStatus || (readingValid ? "stable" : "unknown")),
    readingRaw: String(scale?.readingRaw || "").slice(-MAX_SCALE_RAW_LENGTH),
    readingMode: scale?.readingMode || null,
    readingDeviceName: String(scale?.readingDeviceName || ""),
    readingGeneration: Math.max(0, Math.trunc(Number(scale?.readingGeneration) || 0)),
    readingId: readingValid ? (String(scale?.readingId || "") || null) : null,
    connectionMode: scale?.connectionMode || null,
    deviceName: scale?.deviceName || "",
    autoConnectMode,
    serialPortInfo: normalizeSerialPortInfo(scale?.serialPortInfo),
    serialOptions: normalizeScaleSerialOptions(scale?.serialOptions),
    bleDeviceId: String(scale?.bleDeviceId || "")
  };
}

function snapshotScaleConnectionPreferences(scales = {}) {
  return Object.fromEntries(SCALE_IDS.map((scaleId) => {
    const scale = normalizeScaleState(scales?.[scaleId], scaleId, { invalidateReading: true });
    return [scaleId, {
      autoConnectMode: scale.autoConnectMode,
      connectionMode: scale.connectionMode,
      deviceName: scale.deviceName,
      serialPortInfo: scale.serialPortInfo,
      serialOptions: scale.serialOptions,
      bleDeviceId: scale.bleDeviceId
    }];
  }));
}

function applyScaleConnectionPreferences(targetState, preferences = {}) {
  if (!targetState || typeof targetState !== "object") {
    return targetState;
  }

  targetState.scales = targetState.scales && typeof targetState.scales === "object"
    ? targetState.scales
    : {};
  SCALE_IDS.forEach((scaleId) => {
    const current = normalizeScaleState(targetState.scales[scaleId], scaleId, { invalidateReading: true });
    const preference = normalizeScaleState(preferences?.[scaleId], scaleId, { invalidateReading: true });
    targetState.scales[scaleId] = {
      ...current,
      autoConnectMode: preference.autoConnectMode,
      connectionMode: preference.connectionMode,
      deviceName: preference.deviceName,
      serialPortInfo: preference.serialPortInfo,
      serialOptions: preference.serialOptions,
      bleDeviceId: preference.bleDeviceId
    };
  });
  return targetState;
}

function normalizeType(type) {
  const normalized = String(type ?? "").trim().toLowerCase();
  if (VALID_TYPES.has(normalized)) {
    return normalized;
  }

  const legacyType = LEGACY_TYPE_MAP[normalized];
  if (legacyType && VALID_TYPES.has(legacyType)) {
    return legacyType;
  }

  return DISPATCH_CHICKEN_TYPES[0].id;
}

function getTypeMeta(typeId) {
  const normalizedType = normalizeType(typeId);
  return DISPATCH_CHICKEN_TYPES.find((type) => type.id === normalizedType) || DISPATCH_CHICKEN_TYPES[0];
}

function normalizeChickenSex(value, fallback = DEFAULT_CHICKEN_SEX) {
  const normalized = String(value ?? "").trim().toLowerCase();
  return VALID_CHICKEN_SEXES.has(normalized) ? normalized : fallback;
}

function getChickenSexMeta(value) {
  const normalized = normalizeChickenSex(value);
  return CHICKEN_SEXES.find((sex) => sex.id === normalized) || CHICKEN_SEXES[0];
}

function getSuggestedChickenSex(birdsPerJava, fallback = DEFAULT_CHICKEN_SEX) {
  const birdCount = normalizeBirdCountPerJava(birdsPerJava, 0);
  if (birdCount === 9) {
    return "hembra";
  }
  if (birdCount === 7) {
    return "macho";
  }

  return normalizeChickenSex(fallback);
}

function readPeopleDirectoryClients() {
  if (Array.isArray(liveDirectoryClients)) {
    return liveDirectoryClients;
  }

  try {
    const raw = localStorage.getItem(PEOPLE_STORAGE_KEY);
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw);
    return Array.isArray(parsed?.clientes) ? parsed.clientes : [];
  } catch {
    return [];
  }
}

function readPeopleDirectoryProviders() {
  if (Array.isArray(liveDirectoryProviders)) {
    return liveDirectoryProviders;
  }

  try {
    const raw = localStorage.getItem(PEOPLE_STORAGE_KEY);
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw);
    return Array.isArray(parsed?.proveedores) ? parsed.proveedores : [];
  } catch {
    return [];
  }
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function padDatePart(value) {
  return String(value).padStart(2, "0");
}

function getPeruDateTimeParts(date) {
  try {
    const parts = new Intl.DateTimeFormat("en-US", {
      timeZone: PERU_TIME_ZONE,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23"
    }).formatToParts(date);
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return {
      year: values.year,
      month: values.month,
      day: values.day,
      hour: values.hour === "24" ? "00" : values.hour,
      minute: values.minute,
      second: values.second
    };
  } catch {
    const shifted = new Date(date.getTime() + PERU_UTC_OFFSET_MINUTES * 60000);

    return {
      year: String(shifted.getUTCFullYear()),
      month: padDatePart(shifted.getUTCMonth() + 1),
      day: padDatePart(shifted.getUTCDate()),
      hour: padDatePart(shifted.getUTCHours()),
      minute: padDatePart(shifted.getUTCMinutes()),
      second: padDatePart(shifted.getUTCSeconds())
    };
  }
}

function formatPeruIsoString(date = new Date()) {
  const parts = getPeruDateTimeParts(date);

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}${PERU_UTC_OFFSET}`;
}

function formatPeruTime(date, options = {}) {
  return date.toLocaleTimeString(PERU_LOCALE, {
    timeZone: PERU_TIME_ZONE,
    ...options
  });
}

function formatPeruDate(date, options = {}) {
  return date.toLocaleDateString(PERU_LOCALE, {
    timeZone: PERU_TIME_ZONE,
    ...options
  });
}

function normalizeCatalogClient(record, source = "directory") {
  const id = String(record?.id || "").trim();
  const name = String(record?.name || record?.nombre || "").trim();

  if (!id || !name) {
    return null;
  }

  return {
    id,
    name,
    nombre: name,
    dni: String(record?.dni || "").trim(),
    direccion: String(record?.direccion || "").trim(),
    isInternalClient: Boolean(record?.es_cliente_interno || record?.isInternalClient),
    source,
    destinationType: record?.destinationType === "almacen" ? "almacen" : "cliente",
    databaseId: String(record?.databaseId || (
      record?.destinationType === "almacen" ? "" : record?.id
    ) || "").trim() || null,
    warehouseNumber: record?.destinationType === "almacen"
      ? Number(record?.warehouseNumber) || null
      : null,
    warehouseCode: record?.destinationType === "almacen"
      ? String(record?.warehouseCode || "").trim()
      : "",
    updatedAt: record?.updatedAt || record?.createdAt || ""
  };
}

function normalizeDestinationType(value) {
  const normalized = String(value || "").trim().toLowerCase();
  return normalized === "almacen" || normalized === "warehouse" ? "almacen" : "cliente";
}

function normalizeStoredDestination(record, fallbackId = null) {
  if (!record || typeof record !== "object") {
    return null;
  }

  const destinationType = normalizeDestinationType(
    record.destinationType || record.tipoDestino || record.tipo || record.type
  );
  const warehouseNumber = Number(
    record.warehouseNumber || record.numeroAlmacen || record.numero_almacen
  );
  const id = String(
    record.id
      || record.clientId
      || record.clienteId
      || fallbackId
      || (
        destinationType === "almacen" && Number.isInteger(warehouseNumber) && warehouseNumber > 0
          ? `destino-almacen-${warehouseNumber}`
          : ""
      )
  ).trim();
  const name = String(record.name || record.nombre || "").trim();

  if (!id || !name) {
    return null;
  }

  return {
    id,
    name,
    nombre: name,
    dni: String(record.dni || record.numero_documento || "").trim(),
    direccion: String(record.direccion || record.address || "").trim(),
    isInternalClient: Boolean(
      record.isInternalClient || record.es_cliente_interno || record.internal_client
    ),
    source: record.source || "saved",
    destinationType,
    databaseId: String(
      record.databaseId
        || record.database_id
        || record.terceroId
        || record.tercero_id
        || (
          destinationType === "almacen"
            ? record.warehouseId || record.almacenId || record.almacen_id || ""
            : record.id || fallbackId || ""
        )
    ).trim() || null,
    warehouseNumber: destinationType === "almacen" && Number.isInteger(warehouseNumber) && warehouseNumber > 0
      ? warehouseNumber
      : null,
    warehouseCode: destinationType === "almacen"
      ? String(record.warehouseCode || record.codigoAlmacen || record.warehouse_code || "").trim()
      : "",
    updatedAt: record.updatedAt || record.updated_at || record.createdAt || ""
  };
}

function snapshotDestination(destination) {
  return normalizeStoredDestination(destination, destination?.id);
}

function isInternalClientDestination(destination) {
  return Boolean(destination?.destinationType !== "almacen" && destination?.isInternalClient);
}

function requiresDelivery(truck) {
  return !isReturnTicket(truck) && !isInternalClientDestination(getTruckDestination(truck));
}

function getClientCatalog() {
  const catalogById = new Map();

  readPeopleDirectoryClients().forEach((client) => {
    const normalized = normalizeCatalogClient(client, "directory");
    if (normalized) {
      catalogById.set(normalized.id, normalized);
    }
  });

  if (!Array.isArray(liveDirectoryClients)) {
    CLIENTS.forEach((client) => {
      const normalized = normalizeCatalogClient(client, "sample");
      if (normalized && !catalogById.has(normalized.id)) {
        catalogById.set(normalized.id, normalized);
      }
    });
  }

  WAREHOUSE_DESTINATIONS.forEach((warehouse) => {
    const normalized = normalizeCatalogClient(warehouse, "warehouse");
    if (normalized) {
      catalogById.set(normalized.id, normalized);
    }
  });

  return Array.from(catalogById.values()).sort((a, b) => {
    if (a.destinationType !== b.destinationType) {
      return a.destinationType === "almacen" ? -1 : 1;
    }

    if (a.destinationType === "almacen") {
      return Number(a.warehouseNumber) - Number(b.warehouseNumber);
    }

    if (a.source !== b.source) {
      return a.source === "directory" ? -1 : 1;
    }

    const updatedComparison = String(b.updatedAt || "").localeCompare(String(a.updatedAt || ""));
    if (updatedComparison !== 0) {
      return updatedComparison;
    }

    return a.name.localeCompare(b.name, "es", { sensitivity: "base" });
  });
}

function getClientById(clientId) {
  if (!clientId) {
    return null;
  }

  return getClientCatalog().find((client) => client.id === clientId) || null;
}

function getTruckDestination(truck) {
  if (!truck) {
    return null;
  }

  return getClientById(truck.clientId)
    || normalizeStoredDestination(truck.destination, truck.clientId);
}

function normalizeClientId(clientId) {
  if (!clientId) {
    return null;
  }

  const normalized = String(clientId);
  if (!Array.isArray(liveDirectoryClients)) {
    return normalized;
  }

  return getClientCatalog().some((client) => client.id === normalized) ? normalized : null;
}

function getTruckClientName(truck) {
  const client = getTruckDestination(truck);
  return client?.name
    || normalizeTicketRegistration(truck?.registration)?.destination?.name
    || "Sin destino asignado";
}

function isWarehouseDestination(client) {
  return client?.destinationType === "almacen";
}

function getDestinationTypeLabel(client) {
  return isWarehouseDestination(client) ? "Almacén" : "Cliente";
}

function normalizeProviderVehicle(vehicle) {
  const rawPlate = typeof vehicle === "string"
    ? vehicle
    : vehicle?.plate || vehicle?.placa;
  const plate = normalizeTruckPlate(rawPlate);

  if (!plate) {
    return null;
  }

  return {
    id: String(vehicle?.id || vehicle?.association_id || "").trim() || null,
    vehicleId: String(vehicle?.vehicle_id || vehicle?.vehicleId || "").trim() || null,
    plate,
    alias: String(vehicle?.alias || "").trim()
  };
}

function normalizeCatalogProvider(record) {
  const id = String(record?.id || "").trim();
  const name = String(record?.name || record?.nombre || "").trim();

  if (!id || !name) {
    return null;
  }

  const vehiclesByPlate = new Map();
  const rawVehicles = record?.vehicles || record?.vehiculos || record?.plates || [];

  if (Array.isArray(rawVehicles)) {
    rawVehicles.forEach((vehicle) => {
      const normalizedVehicle = normalizeProviderVehicle(vehicle);
      if (normalizedVehicle) {
        vehiclesByPlate.set(normalizedVehicle.plate, normalizedVehicle);
      }
    });
  }

  return {
    id,
    name,
    nombre: name,
    originType: "proveedor",
    warehouseNumber: null,
    dni: String(record?.dni || "").trim(),
    direccion: String(record?.direccion || "").trim(),
    updatedAt: record?.updatedAt || record?.createdAt || "",
    vehicles: Array.from(vehiclesByPlate.values())
  };
}

function getAllProviderCatalog() {
  return readPeopleDirectoryProviders()
    .map(normalizeCatalogProvider)
    .filter(Boolean)
    .sort((a, b) => {
      const updatedComparison = String(b.updatedAt || "").localeCompare(String(a.updatedAt || ""));
      return updatedComparison || a.name.localeCompare(b.name, "es", { sensitivity: "base" });
    });
}

function getProviderCatalog() {
  const providers = getAllProviderCatalog();
  if (!isJourneyConfigured()) {
    return [];
  }

  const selectedVehicleIds = new Set(
    (dailyJourneyPlan.trucks || [])
      .filter((truck) => truck.selected)
      .map((truck) => String(truck.provider_vehicle_id))
  );

  return providers
    .map((provider) => ({
      ...provider,
      vehicles: getProviderVehicles(provider)
        .filter((vehicle) => selectedVehicleIds.has(String(vehicle.id)))
    }))
    .filter((provider) => provider.vehicles.length > 0);
}

function isJourneyConfigured() {
  return dailyJourneyPlan?.configured === true
    && dailyJourneyPlan?.status === "PUBLICADA";
}

function getJourneyKey() {
  if (!isJourneyConfigured()) {
    return "";
  }

  return `${dailyJourneyPlan.program_id || ""}:${dailyJourneyPlan.operating_date || ""}`;
}

function getConfiguredWarehouseOrigins() {
  if (!isJourneyConfigured()) {
    return [];
  }

  const selectedWarehouseIds = new Set(
    (dailyJourneyPlan.warehouses || [])
      .filter((warehouse) => warehouse.selected)
      .map((warehouse) => String(warehouse.id))
  );

  return WAREHOUSE_ORIGINS.filter((origin) => (
    selectedWarehouseIds.has(String(origin.databaseId))
  ));
}

function getProviderById(providerId) {
  const normalizedId = String(providerId || "").trim();
  if (!normalizedId) {
    return null;
  }

  return getProviderCatalog().find((provider) => provider.id === normalizedId) || null;
}

function normalizeProviderId(providerId) {
  const normalizedId = String(providerId || "").trim();
  if (normalizedId && !Array.isArray(liveDirectoryProviders)) {
    return normalizedId;
  }

  return getProviderById(normalizedId) ? normalizedId : null;
}

function getOriginCatalog() {
  return [
    ...getConfiguredWarehouseOrigins(),
    ...getProviderCatalog()
  ];
}

function getOriginById(originId) {
  const normalizedId = String(originId || "").trim();
  if (!normalizedId) {
    return null;
  }

  return getOriginCatalog().find((origin) => origin.id === normalizedId) || null;
}

function normalizeOriginId(originId) {
  const normalizedId = String(originId || "").trim();
  if (normalizedId && !Array.isArray(liveDirectoryProviders)) {
    return normalizedId;
  }

  return getOriginById(normalizedId) ? normalizedId : null;
}

function isWarehouseOrigin(origin) {
  return origin?.originType === "almacen";
}

function getProviderVehicles(origin) {
  return isWarehouseOrigin(origin) || !Array.isArray(origin?.vehicles)
    ? []
    : origin.vehicles;
}

function getProviderVehicleByPlate(origin, plate) {
  const normalizedPlate = normalizeTruckPlate(plate);
  return getProviderVehicles(origin)
    .find((vehicle) => vehicle.plate === normalizedPlate) || null;
}

function getWarehouseOriginByNumber(warehouseNumber) {
  const normalizedNumber = Number(warehouseNumber);
  return WAREHOUSE_ORIGINS.find((origin) => origin.warehouseNumber === normalizedNumber) || null;
}

function buildOriginRecord(origin) {
  if (!origin) {
    return {
      tipoOrigen: null,
      origenId: null,
      origenNombre: "",
      numeroAlmacenOrigen: null,
      almacenOrigenId: null,
      origen: null,
      proveedorOrigenId: null,
      proveedorOrigenNombre: "",
      proveedorOrigen: null
    };
  }

  const warehouse = isWarehouseOrigin(origin);
  const originRecord = {
    id: origin.id,
    nombre: origin.name,
    name: origin.name,
    tipo: origin.originType,
    numeroAlmacen: warehouse ? origin.warehouseNumber : null,
    almacenId: warehouse ? origin.databaseId : null
  };

  return {
    tipoOrigen: origin.originType,
    origenId: origin.id,
    origenNombre: origin.name,
    numeroAlmacenOrigen: warehouse ? origin.warehouseNumber : null,
    almacenOrigenId: warehouse ? origin.databaseId : null,
    origen: originRecord,
    proveedorOrigenId: warehouse ? null : origin.id,
    proveedorOrigenNombre: warehouse ? "" : origin.name,
    proveedorOrigen: warehouse ? null : {
      id: origin.id,
      nombre: origin.name,
      name: origin.name
    }
  };
}

function normalizeTruckPlate(value) {
  return String(value ?? "")
    .toUpperCase()
    .replace(/[^A-Z0-9-]/g, "")
    .replace(/-{2,}/g, "-")
    .slice(0, MAX_TRUCK_PLATE_LENGTH);
}

function isValidTruckPlate(value) {
  const plate = normalizeTruckPlate(value);
  return plate.length >= 3 && /^[A-Z0-9]+(?:-[A-Z0-9]+)*$/.test(plate);
}

function normalizeWeightSource(source) {
  if (source === "manual") {
    return "manual";
  }

  const numeric = String(source);
  return numeric === "2" ? "2" : "1";
}

function normalizeScaleReadingSnapshot(snapshot) {
  if (!snapshot || typeof snapshot !== "object") {
    return null;
  }

  const weightKg = roundWeight(Number(snapshot.weightKg ?? snapshot.weight_kg));
  const scaleId = Number(snapshot.scaleId ?? snapshot.scale_id);
  const capturedAt = String(snapshot.capturedAt ?? snapshot.captured_at ?? "").trim();
  if (
    !Number.isFinite(weightKg)
    || weightKg <= 0
    || !SCALE_IDS.includes(scaleId)
    || !Number.isFinite(Date.parse(capturedAt))
  ) {
    return null;
  }
  const rawReadingAt = String(snapshot.readingAt ?? snapshot.reading_at ?? capturedAt).trim();
  const readingAt = Number.isFinite(Date.parse(rawReadingAt)) ? rawReadingAt : capturedAt;

  return {
    scaleId,
    scaleCode: String(snapshot.scaleCode ?? snapshot.scale_code ?? `BALANZA_${scaleId}`),
    readingId: String(snapshot.readingId ?? snapshot.reading_id ?? "").trim() || null,
    weightKg,
    rawFrame: String(snapshot.rawFrame ?? snapshot.raw_frame ?? "").slice(-MAX_SCALE_RAW_LENGTH),
    connectionMode: String(snapshot.connectionMode ?? snapshot.connection_mode ?? "").toLowerCase() || null,
    deviceName: String(snapshot.deviceName ?? snapshot.device_name ?? "").slice(0, 180),
    readingAt,
    capturedAt,
    generation: Math.max(0, Math.trunc(Number(snapshot.generation) || 0))
  };
}

function normalizeCageRecord(cage, fallbackId, operationType = TICKET_OPERATIONS.DISPATCH) {
  const ticketOperation = normalizeTicketOperation(
    cage?.operationType || cage?.tipoOperacion || cage?.tipo_operacion || operationType
  );
  const id = Number.isFinite(cage?.id) ? cage.id : fallbackId;
  const rawProvider = cage?.proveedorOrigen || cage?.provider || {};
  const rawOrigin = cage?.origen || {};
  const legacyProviderId = String(
    cage?.proveedorOrigenId || cage?.providerId || rawProvider?.id || ""
  ).trim() || null;
  const warehouseOrigin = getWarehouseOriginByNumber(
    cage?.numeroAlmacenOrigen ?? rawOrigin?.numeroAlmacen
  );
  const requestedOriginId = String(
    cage?.origenId
      || rawOrigin?.id
      || warehouseOrigin?.id
      || legacyProviderId
      || ""
  ).trim() || null;
  const catalogOrigin = getOriginById(requestedOriginId);
  const originType = (
    cage?.tipoOrigen === "almacen"
    || rawOrigin?.tipo === "almacen"
    || warehouseOrigin
    || isWarehouseOrigin(catalogOrigin)
  ) ? "almacen" : "proveedor";
  const originName = String(
    cage?.origenNombre
      || rawOrigin?.nombre
      || rawOrigin?.name
      || cage?.proveedorOrigenNombre
      || cage?.providerName
      || rawProvider?.nombre
      || rawProvider?.name
      || catalogOrigin?.name
      || ""
  ).trim();
  const origin = catalogOrigin || (
    requestedOriginId || originName
      ? {
          id: requestedOriginId,
          name: originName,
          originType,
          warehouseNumber: originType === "almacen"
            ? Number(cage?.numeroAlmacenOrigen ?? rawOrigin?.numeroAlmacen) || null
            : null,
          databaseId: originType === "almacen"
            ? String(cage?.almacenOrigenId || rawOrigin?.almacenId || "").trim() || null
            : null
        }
      : null
  );
  const originRecord = buildOriginRecord(origin);
  const truckPlate = isWarehouseOrigin(origin)
    ? ""
    : normalizeTruckPlate(cage?.placaCamion ?? cage?.truckPlate ?? cage?.placa ?? "");
  const source = cage?.origenPeso === "manual"
    ? "manual"
    : normalizeWeightSource(cage?.balanza || cage?.origenPeso || "1");
  const hasJavaData = (
    cage?.cantidadJavas !== undefined
    || cage?.cantidadJaulas !== undefined
    || cage?.crateTypeId !== undefined
    || cage?.pesoJavaKg !== undefined
    || cage?.taraTotalKg !== undefined
    || cage?.pesoBrutoKg !== undefined
  );
  const crateTypeId = normalizeCrateTypeId(cage?.crateTypeId || getCrateTypeIdByWeight(cage?.pesoJavaKg) || DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);
  const javaCount = hasJavaData
    ? normalizeJavaCount(cage?.cantidadJavas ?? cage?.cantidadJaulas, 1)
    : 1;
  const legacyTotalBirds = Number.isInteger(cage?.cantidadAves)
    ? cage.cantidadAves
    : (Number.isInteger(cage?.cantidadPollos) ? cage.cantidadPollos : Math.trunc(Number(cage?.cantidadPollos) || 0));
  const rawBirdsPerJava = normalizeBirdCountPerJava(cage?.cantidadAvesPorJava ?? cage?.cantidadPollosPorJava, 0);
  const birdsPerJava = rawBirdsPerJava > 0
    ? rawBirdsPerJava
    : normalizeBirdCountPerJava(Math.round(legacyTotalBirds / Math.max(javaCount, 1)), 0);
  const birdsTotal = rawBirdsPerJava > 0
    ? calculateBirdTotal(rawBirdsPerJava, javaCount)
    : legacyTotalBirds;
  const defaultTare = roundWeight(javaCount * crateMeta.weightKg);

  let grossWeight = roundWeight(Number(cage?.pesoBrutoKg));
  let tareWeight = roundWeight(Number(cage?.taraTotalKg));
  let netWeight = roundWeight(Number(cage?.pesoNetoKg));

  if (!hasJavaData) {
    const legacyNet = roundWeight(Number(cage?.pesoKg) || 0);
    netWeight = legacyNet;
    tareWeight = defaultTare;
    grossWeight = roundWeight(legacyNet + tareWeight);
  } else {
    if (!Number.isFinite(grossWeight) || grossWeight <= 0) {
      const fallbackGross = Number(cage?.pesoKg);
      if (Number.isFinite(fallbackGross) && fallbackGross > 0) {
        grossWeight = roundWeight(fallbackGross);
      }
    }

    if (!Number.isFinite(tareWeight) || tareWeight < 0) {
      tareWeight = defaultTare;
    }

    if (!Number.isFinite(netWeight) || netWeight <= 0) {
      if (Number.isFinite(grossWeight) && grossWeight > 0) {
        netWeight = roundWeight(grossWeight - tareWeight);
      }
    }

    if ((!Number.isFinite(grossWeight) || grossWeight <= 0) && Number.isFinite(netWeight) && netWeight > 0) {
      grossWeight = roundWeight(netWeight + tareWeight);
    }
  }

  if (!Number.isFinite(grossWeight) || grossWeight < 0) {
    grossWeight = 0;
  }

  if (!Number.isFinite(tareWeight) || tareWeight < 0) {
    tareWeight = 0;
  }

  if (!Number.isFinite(netWeight) || netWeight < 0) {
    netWeight = 0;
  }

  return {
    id,
    operationType: ticketOperation,
    timestamp: cage?.timestamp || formatPeruIsoString(),
    hora: cage?.hora || "--:--:--",
    tipo: ticketOperation === TICKET_OPERATIONS.RETURN
      ? "pollo_vivo"
      : normalizeType(cage?.tipo),
    chickenCondition: ticketOperation === TICKET_OPERATIONS.RETURN
      ? normalizeReturnCondition(cage?.chickenCondition || cage?.condicionPollo || cage?.condicion_pollo || cage?.tipo)
      : "vivo",
    chickenSex: normalizeChickenSex(
      cage?.chickenSex || cage?.sexo,
      getSuggestedChickenSex(birdsPerJava)
    ),
    cantidadAvesPorJava: birdsPerJava,
    cantidadPollosPorJava: birdsPerJava,
    cantidadAves: birdsTotal,
    cantidadPollos: birdsTotal,
    cantidadJavas: javaCount,
    crateTypeId: crateMeta.id,
    pesoJavaKg: roundWeight(crateMeta.weightKg),
    pesoLeidoKg: Number.isFinite(Number(cage?.pesoLeidoKg))
      ? roundWeight(Number(cage.pesoLeidoKg))
      : grossWeight,
    pesoBrutoKg: grossWeight,
    taraTotalKg: tareWeight,
    pesoNetoKg: netWeight,
    pesoKg: netWeight,
    lecturaBalanzaComoNeto: Boolean(cage?.lecturaBalanzaComoNeto),
    origenPeso: source,
    balanza: source === "manual" ? null : Number(source),
    scaleReading: source === "manual"
      ? null
      : normalizeScaleReadingSnapshot(cage?.scaleReading || cage?.scale_reading),
    ...originRecord,
    placaCamion: truckPlate,
    proveedorVehiculoId: cage?.proveedorVehiculoId || cage?.proveedor_vehiculo_id || null,
    vehiculoId: cage?.vehiculoId || cage?.vehiculo_id || null
  };
}

function createDefaultState() {
  return {
    lastId: 0,
    selectedType: DISPATCH_CHICKEN_TYPES[0].id,
    selectedReturnCondition: RETURN_CONDITIONS[0].id,
    entryDefaults: {
      birdCountPerJava: 1,
      javaCount: 1,
      chickenSex: DEFAULT_CHICKEN_SEX,
      crateTypeId: DEFAULT_CRATE_TYPE_ID,
      originId: null,
      journeyKey: "",
      truckPlate: ""
    },
    scales: {
      1: createDefaultScaleState(1),
      2: createDefaultScaleState(2)
    },
    trucks: createDefaultTrucks()
  };
}

function roundWeight(value) {
  return Math.round(value * 100) / 100;
}

function formatWeight(value) {
  return `${Number(value).toFixed(2)} kg`;
}

function normalizeJavaCount(value, fallback = 1) {
  if (value === null || value === undefined || String(value).trim() === "") {
    return fallback;
  }

  const parsed = Math.trunc(Number(value));
  if (!Number.isInteger(parsed) || parsed < 0) {
    return fallback;
  }

  return parsed;
}

function calculateBirdTotal(birdsPerJava, javaCount) {
  return birdsPerJava * Math.max(javaCount, 1);
}

function normalizeBirdCountPerJava(value, fallback = 0) {
  const parsed = Math.trunc(Number(value));
  if (!Number.isInteger(parsed) || parsed <= 0) {
    return fallback;
  }

  return parsed;
}

function normalizeCrateTypeId(crateTypeId, fallback = DEFAULT_CRATE_TYPE_ID) {
  if (VALID_CRATE_TYPE_IDS.has(crateTypeId)) {
    return crateTypeId;
  }

  return fallback;
}

function getCrateTypeMeta(crateTypeId) {
  const normalizedId = normalizeCrateTypeId(crateTypeId);
  return CRATE_TYPES.find((crate) => crate.id === normalizedId) || CRATE_TYPES[0];
}

function getCrateTypeIdByWeight(weightKg) {
  const normalizedWeight = roundWeight(Number(weightKg));
  if (!Number.isFinite(normalizedWeight) || normalizedWeight <= 0) {
    return null;
  }

  const exact = CRATE_TYPES.find((crate) => roundWeight(crate.weightKg) === normalizedWeight);
  if (exact) {
    return exact.id;
  }

  const closest = CRATE_TYPES.reduce((best, crate) => {
    const diff = Math.abs(normalizedWeight - crate.weightKg);
    if (!best || diff < best.diff) {
      return { id: crate.id, diff };
    }

    return best;
  }, null);

  return closest?.id || null;
}

function calculateWeightBreakdown(grossWeight, javaCount, crateWeightKg) {
  const scaleWeight = roundWeight(Number(grossWeight) || 0);
  const javas = normalizeJavaCount(javaCount, 1);
  const crateWeight = roundWeight(Number(crateWeightKg) || 0);
  const tare = roundWeight(javas * crateWeight);
  const gross = scaleWeight;
  const net = roundWeight(gross - tare);

  return {
    grossWeight: gross,
    scaleWeightKg: scaleWeight,
    javaCount: javas,
    crateWeightKg: crateWeight,
    tareWeightKg: tare,
    netWeightKg: net
  };
}

function sanitizeNumericBuffer(rawValue, allowDecimal) {
  let value = String(rawValue ?? "").trim().replace(/,/g, ".");

  if (allowDecimal) {
    value = value.replace(/[^0-9.]/g, "");
    const firstDot = value.indexOf(".");
    if (firstDot !== -1) {
      value = value.slice(0, firstDot + 1) + value.slice(firstDot + 1).replace(/\./g, "");
    }

    return value;
  }

  return value.replace(/\D/g, "");
}

function inputAllowsDecimal(input) {
  if (!input) {
    return true;
  }

  if (input.dataset.keypadDecimal === "false") {
    return false;
  }

  const step = input.getAttribute("step");
  if (!step || step === "any") {
    return true;
  }

  const numericStep = Number(step);
  if (!Number.isFinite(numericStep)) {
    return true;
  }

  return !Number.isInteger(numericStep);
}

function getInputDecimalPlaces(input) {
  if (!inputAllowsDecimal(input)) {
    return 0;
  }

  const step = String(input?.getAttribute("step") || "");
  const match = /^\d+\.(\d+)$/.exec(step);
  return match ? match[1].length : null;
}

function getKeypadFieldLabel(input) {
  if (input.dataset.keypadLabel) {
    return input.dataset.keypadLabel;
  }

  const wrappingLabel = input.closest("label");
  if (wrappingLabel) {
    const candidate = wrappingLabel.childNodes[0]?.textContent || wrappingLabel.textContent;
    if (candidate) {
      return candidate.replace(/\s+/g, " ").trim();
    }
  }

  return input.id || "Campo numérico";
}

function resetKeypadContext() {
  keypadContext = {
    targetInput: null,
    value: "",
    allowDecimal: true,
    decimalPlaces: null,
    replaceOnNextKey: false,
    fieldLabel: "",
    validationMessage: ""
  };
}

function renderNumericPad() {
  elements.numericPadTitle.textContent = keypadContext.fieldLabel || "Campo numérico";
  elements.numericPadValue.textContent = keypadContext.value || "0";
  elements.numericPadDotBtn.disabled = !keypadContext.allowDecimal;
  elements.numericPadMessage.textContent = keypadContext.validationMessage;
}

function clearNumericPadValidation() {
  keypadContext.validationMessage = "";
}

function validateNumericPadValue(input, rawValue) {
  if (!rawValue) {
    return input.required ? "Este valor es obligatorio." : "";
  }

  const value = Number(rawValue);
  if (!Number.isFinite(value)) {
    return "Ingresa un número válido.";
  }

  const min = Number(input.getAttribute("min"));
  if (input.hasAttribute("min") && Number.isFinite(min) && value < min) {
    return `El valor mínimo es ${min}.`;
  }

  const max = Number(input.getAttribute("max"));
  if (input.hasAttribute("max") && Number.isFinite(max) && value > max) {
    return `El valor máximo es ${max}.`;
  }

  return "";
}

function openNumericPadForInput(input) {
  if (!input || input.disabled) {
    return;
  }

  closeTextTouchKeyboard(true, false);
  closeTouchSelect();

  if (!elements.numericPadModal.hidden && keypadContext.targetInput === input) {
    return;
  }

  keypadContext.targetInput = input;
  keypadContext.allowDecimal = inputAllowsDecimal(input);
  keypadContext.decimalPlaces = getInputDecimalPlaces(input);
  keypadContext.replaceOnNextKey = true;
  keypadContext.fieldLabel = getKeypadFieldLabel(input);
  keypadContext.value = sanitizeNumericBuffer(input.value, keypadContext.allowDecimal);
  keypadContext.validationMessage = "";

  renderNumericPad();
  elements.numericPadModal.hidden = false;
  input.setAttribute("aria-expanded", "true");
  document.body.classList.add("numeric-pad-open");
}

function closeNumericPad() {
  keypadContext.targetInput?.setAttribute("aria-expanded", "false");
  elements.numericPadModal.hidden = true;
  document.body.classList.remove("numeric-pad-open");
  resetKeypadContext();
}

function handleNumericKeyPress(key) {
  if (!keypadContext.targetInput) {
    return;
  }

  let value = keypadContext.value;

  if (key === "clear") {
    keypadContext.value = "";
    keypadContext.replaceOnNextKey = false;
    clearNumericPadValidation();
    renderNumericPad();
    return;
  }

  if (key === "backspace") {
    keypadContext.value = value.slice(0, -1);
    keypadContext.replaceOnNextKey = false;
    clearNumericPadValidation();
    renderNumericPad();
    return;
  }

  if (key === "dot") {
    if (!keypadContext.allowDecimal || value.includes(".")) {
      return;
    }

    value = keypadContext.replaceOnNextKey ? "" : value;
    keypadContext.replaceOnNextKey = false;
    keypadContext.value = value ? `${value}.` : "0.";
    clearNumericPadValidation();
    renderNumericPad();
    return;
  }

  if (!/^\d+$/.test(key)) {
    return;
  }

  if (keypadContext.replaceOnNextKey) {
    value = "";
    keypadContext.replaceOnNextKey = false;
  }

  if (value === "0" && !value.includes(".")) {
    value = "";
  }

  let acceptedKey = key;
  if (value.includes(".") && Number.isInteger(keypadContext.decimalPlaces)) {
    const decimalsUsed = value.split(".")[1]?.length || 0;
    const remaining = keypadContext.decimalPlaces - decimalsUsed;
    if (remaining <= 0) {
      return;
    }
    acceptedKey = key.slice(0, remaining);
  }

  keypadContext.value = `${value}${acceptedKey}`.slice(0, 14);
  clearNumericPadValidation();
  renderNumericPad();
}

function confirmNumericPadValue() {
  if (!keypadContext.targetInput) {
    closeNumericPad();
    return;
  }

  const targetInput = keypadContext.targetInput;
  const nextValue = keypadContext.value.endsWith(".")
    ? keypadContext.value.slice(0, -1)
    : keypadContext.value;
  const validationMessage = validateNumericPadValue(targetInput, nextValue);
  if (validationMessage) {
    keypadContext.validationMessage = validationMessage;
    renderNumericPad();
    return;
  }

  targetInput.value = nextValue;
  targetInput.dispatchEvent(new Event("input", { bubbles: true }));
  targetInput.dispatchEvent(new Event("change", { bubbles: true }));

  closeNumericPad();
}

function bindNumericInputs() {
  const numericInputs = document.querySelectorAll('input[type="number"]');

  numericInputs.forEach((input) => {
    if (input.dataset.keypadBound === "true") {
      return;
    }

    input.dataset.keypadBound = "true";
    input.readOnly = true;
    input.setAttribute("inputmode", "none");
    input.classList.add("touch-number-control");
    input.setAttribute("role", "button");
    input.setAttribute("aria-haspopup", "dialog");
    input.setAttribute("aria-controls", "numericPadModal");
    input.setAttribute("aria-expanded", "false");

    let tapState = null;
    let suppressNextClick = false;

    if (window.PointerEvent) {
      input.addEventListener("pointerdown", (event) => {
        if (event.button !== 0) {
          return;
        }

        suppressNextClick = false;
        tapState = {
          pointerId: event.pointerId,
          startX: event.clientX,
          startY: event.clientY,
          startedAt: Date.now(),
          moved: false
        };
      });

      input.addEventListener("pointermove", (event) => {
        if (!tapState || tapState.pointerId !== event.pointerId) {
          return;
        }

        const deltaX = Math.abs(event.clientX - tapState.startX);
        const deltaY = Math.abs(event.clientY - tapState.startY);
        if (deltaX > TAP_MAX_MOVEMENT_PX || deltaY > TAP_MAX_MOVEMENT_PX) {
          tapState.moved = true;
        }
      });

      input.addEventListener("pointercancel", () => {
        tapState = null;
        suppressNextClick = true;
      });

      input.addEventListener("pointerup", (event) => {
        if (!tapState || tapState.pointerId !== event.pointerId) {
          return;
        }

        const holdMs = Date.now() - tapState.startedAt;
        const isTouchLikePointer = event.pointerType === "touch" || event.pointerType === "pen";
        const maxHoldMs = isTouchLikePointer ? TOUCH_TAP_MAX_HOLD_MS : Number.POSITIVE_INFINITY;
        const shouldOpen = !tapState.moved && holdMs <= maxHoldMs;
        tapState = null;
        suppressNextClick = !shouldOpen;
      });
    }

    input.addEventListener("click", (event) => {
      event.preventDefault();
      if (suppressNextClick) {
        suppressNextClick = false;
        return;
      }
      openNumericPadForInput(input);
    });

    input.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") {
        return;
      }

      event.preventDefault();
      openNumericPadForInput(input);
    });
  });

}

function resetTextKeyboardContext() {
  textKeyboardContext = {
    targetInput: null,
    initialValue: "",
    value: "",
    uppercase: true
  };
}

function updateTextTouchKeyboardValue() {
  const target = textKeyboardContext.targetInput;
  if (!target || !elements.textTouchKeyboardValue) {
    return;
  }

  elements.textTouchKeyboardValue.textContent = textKeyboardContext.value
    || target.placeholder
    || "Campo vacío";
}

function renderTextTouchKeyboard() {
  if (!elements.textTouchKeyboardKeys) {
    return;
  }

  const rows = [
    ["1", "2", "3", "4", "5", "6", "7", "8", "9", "0"],
    ["Q", "W", "E", "R", "T", "Y", "U", "I", "O", "P"],
    ["A", "S", "D", "F", "G", "H", "J", "K", "L", "Ñ"],
    ["Z", "X", "C", "V", "B", "N", "M"],
    ["Á", "É", "Í", "Ó", "Ú", "Ü", "-", "/", "."]
  ];
  const keyForCurrentCase = (key) => textKeyboardContext.uppercase
    ? key
    : key.toLocaleLowerCase("es");

  elements.textTouchKeyboardKeys.innerHTML = rows.map((row, index) => `
    <div class="text-touch-keyboard-row ${index === 3 ? "is-short" : ""}">
      ${index === 3 ? `
        <button
          type="button"
          class="is-shift ${textKeyboardContext.uppercase ? "is-active" : ""}"
          data-text-keyboard-action="shift"
          aria-pressed="${textKeyboardContext.uppercase}"
        >Mayús</button>
      ` : ""}
      ${row.map((key) => {
        const displayKey = keyForCurrentCase(key);
        return `<button type="button" data-text-keyboard-key="${escapeHtml(displayKey)}">${escapeHtml(displayKey)}</button>`;
      }).join("")}
    </div>
  `).join("") + `
    <div class="text-touch-keyboard-row">
      <button type="button" class="is-space" data-text-keyboard-key=" ">Espacio</button>
    </div>
  `;
}

function openTextTouchKeyboard(input) {
  if (
    !input
    || input.disabled
    || input.dataset.touchKeyboard !== "text"
    || suppressTextKeyboardOpen
    || !elements.textTouchKeyboard
  ) {
    return;
  }

  if (!elements.textTouchKeyboard.hidden && textKeyboardContext.targetInput === input) {
    return;
  }

  if (!elements.textTouchKeyboard.hidden) {
    closeTextTouchKeyboard(true, false);
  }

  closeNumericPad();
  closeTouchSelect();
  textKeyboardContext.targetInput?.setAttribute("aria-expanded", "false");
  textKeyboardContext = {
    targetInput: input,
    initialValue: input.value,
    value: input.value,
    uppercase: true
  };

  input.setAttribute("aria-controls", "textTouchKeyboard");
  input.setAttribute("aria-expanded", "true");
  elements.textTouchKeyboardTitle.textContent = input.dataset.touchKeyboardLabel || "Ingresar texto";
  renderTextTouchKeyboard();
  updateTextTouchKeyboardValue();
  elements.textTouchKeyboard.hidden = false;
  elements.textTouchKeyboard.setAttribute("aria-hidden", "false");
  document.body.classList.add("text-touch-keyboard-open");
}

function closeTextTouchKeyboard(commit = true, restoreFocus = true) {
  if (!elements.textTouchKeyboard || elements.textTouchKeyboard.hidden) {
    return;
  }

  const target = textKeyboardContext.targetInput;
  if (target && !commit) {
    target.value = textKeyboardContext.initialValue;
    target.dispatchEvent(new Event("input", { bubbles: true }));
  }
  if (target && commit) {
    target.dispatchEvent(new Event("change", { bubbles: true }));
  }

  elements.textTouchKeyboard.hidden = true;
  elements.textTouchKeyboard.setAttribute("aria-hidden", "true");
  document.body.classList.remove("text-touch-keyboard-open");
  target?.setAttribute("aria-expanded", "false");
  resetTextKeyboardContext();

  suppressTextKeyboardOpen = true;
  if (restoreFocus) {
    target?.focus({ preventScroll: true });
  }
  queueMicrotask(() => {
    suppressTextKeyboardOpen = false;
  });
}

function setTextTouchKeyboardValue(value) {
  const target = textKeyboardContext.targetInput;
  if (!target) {
    return;
  }

  const maximumLength = target.maxLength > 0 ? target.maxLength : 120;
  textKeyboardContext.value = String(value).slice(0, maximumLength);
  target.value = textKeyboardContext.value;
  target.dispatchEvent(new Event("input", { bubbles: true }));
  updateTextTouchKeyboardValue();
}

function appendTextTouchKeyboardKey(key) {
  if (!textKeyboardContext.targetInput || typeof key !== "string") {
    return;
  }

  setTextTouchKeyboardValue(`${textKeyboardContext.value}${key}`);
}

function handleTextTouchKeyboardAction(action) {
  if (action === "accept") {
    closeTextTouchKeyboard(true);
    return;
  }
  if (action === "cancel") {
    closeTextTouchKeyboard(false);
    return;
  }
  if (action === "clear") {
    setTextTouchKeyboardValue("");
    return;
  }
  if (action === "backspace") {
    setTextTouchKeyboardValue(textKeyboardContext.value.slice(0, -1));
    return;
  }
  if (action === "shift") {
    textKeyboardContext.uppercase = !textKeyboardContext.uppercase;
    renderTextTouchKeyboard();
  }
}

function bindTextTouchInputs() {
  document.querySelectorAll('input[data-touch-keyboard="text"]').forEach((input) => {
    if (input.dataset.touchKeyboardBound === "true") {
      return;
    }

    input.dataset.touchKeyboardBound = "true";
    input.readOnly = true;
    input.setAttribute("inputmode", "none");
    input.classList.add("touch-text-control");
    input.setAttribute("aria-haspopup", "dialog");
    input.setAttribute("aria-controls", "textTouchKeyboard");
    input.setAttribute("aria-expanded", "false");

    input.addEventListener("click", (event) => {
      event.preventDefault();
      input.focus({ preventScroll: true });
      openTextTouchKeyboard(input);
    });

    input.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") {
        return;
      }

      event.preventDefault();
      openTextTouchKeyboard(input);
    });
  });
}

function getTouchSelectFieldLabel(select) {
  if (select.dataset.touchLabel) {
    return select.dataset.touchLabel;
  }

  const wrappingLabel = select.closest("label");
  if (wrappingLabel) {
    const firstText = Array.from(wrappingLabel.childNodes)
      .find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
    if (firstText?.textContent) {
      return firstText.textContent.replace(/\s+/g, " ").trim();
    }
  }

  const field = select.closest(".field");
  const fieldLabel = field?.querySelector(":scope > span")?.textContent;
  return fieldLabel?.trim() || select.id || "Seleccionar opción";
}

function resetTouchSelectContext() {
  touchSelectContext = {
    targetSelect: null,
    fieldLabel: ""
  };
}

function renderTouchSelectOptions() {
  const select = touchSelectContext.targetSelect;
  if (!select || !elements.touchSelectOptions) {
    return;
  }

  const options = Array.from(select.options);
  const selectedOption = options.find((option) => option.selected);
  elements.touchSelectTitle.textContent = touchSelectContext.fieldLabel || "Seleccionar opción";
  elements.touchSelectCurrentValue.textContent = selectedOption?.textContent?.trim() || "Sin selección";

  elements.touchSelectOptions.innerHTML = options.map((option, index) => {
    const isActive = option.value === select.value;
    const isDisabled = option.disabled || !option.value && option.textContent.trim().toLowerCase().startsWith("selecciona");

    return `
      <button
        class="touch-select-option ${isActive ? "is-active" : ""}"
        type="button"
        role="option"
        aria-selected="${isActive ? "true" : "false"}"
        data-touch-select-index="${index}"
        ${isDisabled ? "disabled" : ""}
      >
        <span class="touch-select-option-index">${String(index + 1).padStart(2, "0")}</span>
        <strong>${escapeHtml(option.textContent.trim())}</strong>
        <span class="touch-select-option-check" aria-hidden="true">${isActive ? "✓" : "›"}</span>
      </button>
    `;
  }).join("");
}

function openTouchSelect(select) {
  if (!select || select.disabled || !elements.touchSelectModal) {
    return;
  }

  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeConfigMenu();
  touchSelectContext.targetSelect = select;
  touchSelectContext.fieldLabel = getTouchSelectFieldLabel(select);
  renderTouchSelectOptions();
  elements.touchSelectModal.hidden = false;
  select.setAttribute("aria-expanded", "true");
}

function closeTouchSelect() {
  touchSelectContext.targetSelect?.setAttribute("aria-expanded", "false");
  if (elements.touchSelectModal) {
    elements.touchSelectModal.hidden = true;
  }
  resetTouchSelectContext();
}

function selectTouchOption(index) {
  const select = touchSelectContext.targetSelect;
  const option = select?.options?.[Number(index)];
  if (!select || !option || option.disabled) {
    return;
  }

  select.value = option.value;
  select.dispatchEvent(new Event("input", { bubbles: true }));
  select.dispatchEvent(new Event("change", { bubbles: true }));
  closeTouchSelect();
}

function bindTouchSelects() {
  document.querySelectorAll("select").forEach((select) => {
    if (select.dataset.touchSelectBound === "true") {
      return;
    }

    select.dataset.touchSelectBound = "true";
    select.classList.add("touch-select-control");
    select.setAttribute("aria-haspopup", "dialog");
    select.setAttribute("aria-controls", "touchSelectModal");
    select.setAttribute("aria-expanded", "false");

    let tapState = null;
    let suppressNextClick = false;

    select.addEventListener("pointerdown", (event) => {
      if (event.button !== 0 || select.disabled) {
        return;
      }

      event.preventDefault();
      suppressNextClick = false;
      tapState = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        startedAt: Date.now(),
        moved: false
      };
    });

    select.addEventListener("pointermove", (event) => {
      if (!tapState || tapState.pointerId !== event.pointerId) {
        return;
      }

      const deltaX = Math.abs(event.clientX - tapState.startX);
      const deltaY = Math.abs(event.clientY - tapState.startY);
      if (deltaX > TAP_MAX_MOVEMENT_PX || deltaY > TAP_MAX_MOVEMENT_PX) {
        tapState.moved = true;
      }
    });

    select.addEventListener("pointercancel", () => {
      tapState = null;
      suppressNextClick = true;
    });

    select.addEventListener("pointerup", (event) => {
      if (!tapState || tapState.pointerId !== event.pointerId) {
        return;
      }

      const holdMs = Date.now() - tapState.startedAt;
      const isTouchLikePointer = event.pointerType === "touch" || event.pointerType === "pen";
      const maxHoldMs = isTouchLikePointer ? TOUCH_TAP_MAX_HOLD_MS : Number.POSITIVE_INFINITY;
      suppressNextClick = tapState.moved || holdMs > maxHoldMs;
      tapState = null;
    });

    select.addEventListener("click", (event) => {
      event.preventDefault();
      if (suppressNextClick) {
        suppressNextClick = false;
        return;
      }
      openTouchSelect(select);
    });

    select.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") {
        return;
      }
      event.preventDefault();
      openTouchSelect(select);
    });
  });
}

function calculateTruckTotals(cages, operationType = TICKET_OPERATIONS.DISPATCH) {
  const normalizedOperation = normalizeTicketOperation(operationType);
  const typeCatalog = normalizedOperation === TICKET_OPERATIONS.RETURN
    ? RETURN_CONDITIONS
    : DISPATCH_CHICKEN_TYPES;
  const birdsBySex = {
    macho: 0,
    hembra: 0
  };
  const byTypeMap = {};
  typeCatalog.forEach((type) => {
    byTypeMap[type.id] = {
      id: type.id,
      label: type.label,
      records: 0,
      cages: 0,
      javas: 0,
      birds: 0,
      grossWeight: 0,
      tareWeight: 0,
      netWeight: 0,
      weight: 0
    };
  });

  cages.forEach((cage) => {
    const typeId = normalizedOperation === TICKET_OPERATIONS.RETURN
      ? normalizeReturnCondition(cage.chickenCondition)
      : normalizeType(cage.tipo);
    const bucket = byTypeMap[typeId];
    if (!bucket) {
      return;
    }
    const javas = normalizeJavaCount(cage.cantidadJavas, 1);
    const avesPorJava = normalizeBirdCountPerJava(cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava, 0);
    const aves = avesPorJava > 0
      ? calculateBirdTotal(avesPorJava, javas)
      : (Number(cage.cantidadAves ?? cage.cantidadPollos) || 0);
    const grossWeight = Number(cage.pesoBrutoKg ?? cage.pesoKg) || 0;
    const tareWeight = Number(cage.taraTotalKg) || 0;
    const netWeight = Number(cage.pesoNetoKg ?? cage.pesoKg) || 0;
    const chickenSex = normalizeChickenSex(cage.chickenSex || cage.sexo);

    bucket.records += 1;
    bucket.cages += 1;
    bucket.javas += javas;
    bucket.birds += aves;
    bucket.grossWeight += grossWeight;
    bucket.tareWeight += tareWeight;
    bucket.netWeight += netWeight;
    bucket.weight += netWeight;
    birdsBySex[chickenSex] += aves;
  });

  const byType = typeCatalog.map((type) => ({
    ...byTypeMap[type.id],
    grossWeight: roundWeight(byTypeMap[type.id].grossWeight),
    tareWeight: roundWeight(byTypeMap[type.id].tareWeight),
    netWeight: roundWeight(byTypeMap[type.id].netWeight),
    weight: roundWeight(byTypeMap[type.id].weight)
  }));

  const totalJavas = byType.reduce((sum, item) => sum + item.javas, 0);
  const totalBirds = byType.reduce((sum, item) => sum + item.birds, 0);
  const totalGrossWeight = roundWeight(byType.reduce((sum, item) => sum + item.grossWeight, 0));
  const totalTareWeight = roundWeight(byType.reduce((sum, item) => sum + item.tareWeight, 0));
  const totalNetWeight = roundWeight(byType.reduce((sum, item) => sum + item.netWeight, 0));

  return {
    records: cages.length,
    cages: cages.length,
    javas: totalJavas,
    birds: totalBirds,
    grossWeight: totalGrossWeight,
    tareWeight: totalTareWeight,
    netWeight: totalNetWeight,
    weight: totalNetWeight,
    maleBirds: birdsBySex.macho,
    femaleBirds: birdsBySex.hembra,
    byType
  };
}

function loadState(storageKey = activeStorageKey) {
  const fallback = createDefaultState();

  try {
    const raw = localStorage.getItem(storageKey);
    if (!raw) {
      return fallback;
    }

    const parsed = JSON.parse(raw);

    if (!parsed || typeof parsed !== "object") {
      return fallback;
    }

    const loadedTrucks = Array.isArray(parsed.trucks) && parsed.trucks.length
      ? parsed.trucks.map((truck, index) => {
          const registration = normalizeTicketRegistration(truck.registration || truck.registroTicket);
          const operationType = normalizeTicketOperation(
            truck.operationType
              || truck.tipoOperacion
              || truck.tipo_operacion
              || registration?.operationType
          );
          const destination = normalizeStoredDestination(
            truck.destination || truck.destino || truck.cliente,
            truck.clientId || truck.clienteId || truck?.cliente?.id
          );
          const clientId = normalizeClientId(
            truck.clientId || truck.clienteId || truck?.cliente?.id || destination?.id
          );

          return {
            id: truck.id || `camion-${index + 1}`,
            name: normalizeDispatchTicketName(truck.name, index),
            draftId: String(truck.draftId || truck.draft_id || "").trim() || createDraftId(),
            operationType,
            registration,
            clientId,
            destination: clientId ? destination : null,
            cages: Array.isArray(truck.cages)
              ? truck.cages.map((cage, cageIndex) => normalizeCageRecord(cage, cageIndex + 1, operationType))
              : []
          };
        })
      : fallback.trucks;
    const trucks = ensureTruckSlots(loadedTrucks);

    const maxId = trucks.reduce((max, truck) => {
      const localMax = truck.cages.reduce((innerMax, cage) => Math.max(innerMax, cage.id), 0);
      return Math.max(max, localMax);
    }, 0);

    const parsedEntryDefaults = parsed.entryDefaults || {};
    const legacyBirdCountPerJava = normalizeBirdCountPerJava(
      parsedEntryDefaults.birdCountPerJava
        ?? parsedEntryDefaults.avesPorJava
        ?? parsedEntryDefaults.cantidadAvesPorJava
        ?? parsed.cantidadAvesPorJavaFija
        ?? parsed.cantidadPollosPorJavaFija,
      0
    );
    const entryDefaults = {
      birdCountPerJava: normalizeBirdCountPerJava(legacyBirdCountPerJava, fallback.entryDefaults.birdCountPerJava),
      javaCount: normalizeJavaCount(parsedEntryDefaults.javaCount ?? parsed.cantidadJavasFija, 1),
      chickenSex: normalizeChickenSex(
        parsedEntryDefaults.chickenSex ?? parsedEntryDefaults.sexo,
        getSuggestedChickenSex(legacyBirdCountPerJava)
      ),
      crateTypeId: normalizeCrateTypeId(
        parsedEntryDefaults.crateTypeId
          || getCrateTypeIdByWeight(parsedEntryDefaults.pesoJavaKg)
          || DEFAULT_CRATE_TYPE_ID
      ),
      originId: normalizeOriginId(
        parsedEntryDefaults.originId
          || parsedEntryDefaults.origenId
          || parsedEntryDefaults.providerId
          || parsedEntryDefaults.proveedorId
          || parsedEntryDefaults.proveedorOrigenId
      ),
      journeyKey: String(parsedEntryDefaults.journeyKey || ""),
      truckPlate: normalizeTruckPlate(
        parsedEntryDefaults.truckPlate
          || parsedEntryDefaults.plate
          || parsedEntryDefaults.placaCamion
          || ""
      )
    };

    return {
      lastId: Math.max(Number(parsed.lastId) || 0, maxId),
      selectedType: normalizeType(parsed.selectedType),
      selectedReturnCondition: normalizeReturnCondition(parsed.selectedReturnCondition),
      entryDefaults,
      scales: {
        1: normalizeScaleState(parsed?.scales?.[1], 1, { invalidateReading: true }),
        2: normalizeScaleState(parsed?.scales?.[2], 2, { invalidateReading: true })
      },
      trucks
    };
  } catch {
    return fallback;
  }
}

function branchStorageKey(branch, company = null) {
  const branchId = String(branch?.id || branch?.code || "default")
    .trim()
    .replace(/[^a-zA-Z0-9_-]/g, "-");
  const companyId = String(
    company?.id
      || branch?.company_id
      || branch?.companyId
      || "default"
  )
    .trim()
    .replace(/[^a-zA-Z0-9_-]/g, "-");
  return `${STORAGE_KEY_PREFIX}-company-${companyId || "default"}-branch-${branchId || "default"}`;
}

function activateBranchStorage(catalog) {
  const branch = catalog?.branch || catalog;
  const nextStorageKey = branchStorageKey(branch, catalog?.company);
  if (nextStorageKey === activeStorageKey) {
    return false;
  }

  try {
    const scopedStateExists = Boolean(localStorage.getItem(nextStorageKey));
    let nextState;

    if (scopedStateExists) {
      nextState = loadState(nextStorageKey);
    } else {
      const migratedBranch = localStorage.getItem(STORAGE_MIGRATION_KEY);
      const canMigrateLegacyState = activeStorageKey === LEGACY_STORAGE_KEY && !migratedBranch;
      nextState = canMigrateLegacyState ? state : createDefaultState();
      if (canMigrateLegacyState) {
        localStorage.setItem(STORAGE_MIGRATION_KEY, nextStorageKey);
      }
    }

    // Una sesión puede cambiar de empresa/sucursal sin recargar la pestaña.
    // Se liberan los objetos físicos de la sucursal anterior, pero sus
    // preferencias ya guardadas se conservan en su clave local independiente.
    releaseScaleConnectionsForNavigation();
    activeStorageKey = nextStorageKey;
    state = nextState;
    capturedScaleReadings = { 1: null, 2: null };
    scaleReadingSequences = { 1: 0, 2: 0 };
    lastRegisteredScaleReadingIds = { 1: null, 2: null };
    reconcileTruckClientAssignments(false);
    ensureDefaultOriginSelection(false);
    saveState();
    return true;
  } catch (error) {
    console.warn("No se pudo aislar el estado mayorista por sucursal.", error);
    return false;
  }
}

let state = loadState();
reconcileTruckClientAssignments(true);
ensureDefaultOriginSelection(true);
let customFontSizes = loadCustomFontSizes();
let customerDisplayChannel = null;
let lastCustomerDisplayStorageWrite = 0;
let pendingCustomerDisplayStoragePayload = null;
let customerDisplayStorageTimer = null;
let customerDisplayRevision = 0;

function normalizeFontSizeStep(step) {
  return FONT_SIZE_STEPS.includes(step) ? step : "normal";
}

function loadFontSizePreference() {
  try {
    return normalizeFontSizeStep(localStorage.getItem(FONT_SIZE_STORAGE_KEY));
  } catch {
    return "normal";
  }
}

function saveFontSizePreference(step) {
  try {
    localStorage.setItem(FONT_SIZE_STORAGE_KEY, step);
  } catch {
    // La interfaz puede seguir funcionando aunque el navegador bloquee localStorage.
  }
}

function applyFontSizePreference(step, shouldSave = true) {
  const normalizedStep = normalizeFontSizeStep(step);
  document.documentElement.dataset.fontSize = normalizedStep;

  if (elements.fontSizeStatus) {
    elements.fontSizeStatus.textContent = FONT_SIZE_LABELS[normalizedStep];
  }

  const currentIndex = FONT_SIZE_STEPS.indexOf(normalizedStep);
  if (elements.fontDecreaseBtn) {
    elements.fontDecreaseBtn.disabled = currentIndex <= 0;
  }
  if (elements.fontIncreaseBtn) {
    elements.fontIncreaseBtn.disabled = currentIndex >= FONT_SIZE_STEPS.length - 1;
  }

  if (shouldSave) {
    saveFontSizePreference(normalizedStep);
  }
}

function changeFontSize(delta) {
  const currentStep = normalizeFontSizeStep(document.documentElement.dataset.fontSize);
  const currentIndex = FONT_SIZE_STEPS.indexOf(currentStep);
  const nextIndex = Math.min(Math.max(currentIndex + delta, 0), FONT_SIZE_STEPS.length - 1);
  applyFontSizePreference(FONT_SIZE_STEPS[nextIndex]);
}

function getCustomFontSizeItem(itemId) {
  return CUSTOM_FONT_SIZE_ITEMS.find((item) => item.id === itemId);
}

function normalizeCustomFontSizeValue(item, value) {
  const numericValue = Number(value);
  const fallback = item?.defaultPx || 16;
  const min = item?.min || 8;
  const max = item?.max || 80;

  if (!Number.isFinite(numericValue)) {
    return fallback;
  }

  return Math.min(Math.max(Math.round(numericValue), min), max);
}

function loadCustomFontSizes() {
  try {
    const raw = localStorage.getItem(CUSTOM_FONT_SIZE_STORAGE_KEY);
    if (!raw) {
      return {};
    }

    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== "object") {
      return {};
    }

    return CUSTOM_FONT_SIZE_ITEMS.reduce((sizes, item) => {
      if (Object.prototype.hasOwnProperty.call(parsed, item.id)) {
        sizes[item.id] = normalizeCustomFontSizeValue(item, parsed[item.id]);
      }
      return sizes;
    }, {});
  } catch {
    return {};
  }
}

function saveCustomFontSizes() {
  try {
    localStorage.setItem(CUSTOM_FONT_SIZE_STORAGE_KEY, JSON.stringify(customFontSizes));
  } catch {
    // La interfaz conserva los cambios visuales aunque el navegador no permita guardar.
  }
}

function getCustomFontSizeValue(item) {
  return normalizeCustomFontSizeValue(item, customFontSizes[item.id] ?? item.defaultPx);
}

function applyCustomFontSizes() {
  CUSTOM_FONT_SIZE_ITEMS.forEach((item) => {
    document.documentElement.style.setProperty(item.cssVar, `${getCustomFontSizeValue(item)}px`);
  });
}

function setCustomFontSize(itemId, value) {
  const item = getCustomFontSizeItem(itemId);
  if (!item) {
    return;
  }

  customFontSizes[item.id] = normalizeCustomFontSizeValue(item, value);
  applyCustomFontSizes();
  saveCustomFontSizes();
  renderFontSizeControls();
}

function changeCustomFontSize(itemId, delta) {
  const item = getCustomFontSizeItem(itemId);
  if (!item) {
    return;
  }

  setCustomFontSize(item.id, getCustomFontSizeValue(item) + delta);
}

function resetCustomFontSize(itemId) {
  const item = getCustomFontSizeItem(itemId);
  if (!item) {
    return;
  }

  delete customFontSizes[item.id];
  applyCustomFontSizes();
  saveCustomFontSizes();
  renderFontSizeControls();
}

function resetAllCustomFontSizes() {
  customFontSizes = {};
  applyCustomFontSizes();
  saveCustomFontSizes();
  renderFontSizeControls();
}

function createFontSizeButton(label, action, itemId, disabled = false) {
  const button = document.createElement("button");
  button.type = "button";
  button.textContent = label;
  button.dataset.fontSizeAction = action;
  button.dataset.fontSizeId = itemId;
  button.disabled = disabled;
  return button;
}

function renderFontSizeControls() {
  if (!elements.fontSizeControls) {
    return;
  }

  elements.fontSizeControls.replaceChildren();

  CUSTOM_FONT_SIZE_GROUPS.forEach((group) => {
    const groupElement = document.createElement("section");
    groupElement.className = "font-size-group";

    const title = document.createElement("h3");
    title.textContent = group.title;
    groupElement.append(title);

    group.items.forEach((item) => {
      const value = getCustomFontSizeValue(item);
      const isDefault = value === item.defaultPx;
      const control = document.createElement("article");
      control.className = "font-size-control";

      const main = document.createElement("div");
      main.className = "font-size-control-main";

      const kind = document.createElement("span");
      kind.className = "font-size-control-kind";
      kind.textContent = item.type;

      const label = document.createElement("strong");
      label.textContent = item.label;

      const range = document.createElement("small");
      range.textContent = `Estándar ${item.defaultPx}px | rango ${item.min}-${item.max}px`;

      main.append(kind, label, range);

      const stepper = document.createElement("div");
      stepper.className = "font-size-stepper";

      const valueOutput = document.createElement("output");
      valueOutput.textContent = `${value}px`;

      stepper.append(
        createFontSizeButton("-", "decrease", item.id, value <= item.min),
        valueOutput,
        createFontSizeButton("+", "increase", item.id, value >= item.max),
        createFontSizeButton("R", "reset", item.id, isDefault)
      );

      control.append(main, stepper);
      groupElement.append(control);
    });

    elements.fontSizeControls.append(groupElement);
  });
}

function setFontSidebarOpen(isOpen) {
  if (!elements.fontSidebarOverlay) {
    return;
  }

  elements.fontSidebarOverlay.hidden = !isOpen;
}

function openFontSidebar() {
  closeConfigMenu();
  closeAllScaleSettings();
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();
  renderFontSizeControls();
  setFontSidebarOpen(true);
}

function closeFontSidebar() {
  setFontSidebarOpen(false);
}

function setConfigMenuOpen(isOpen) {
  if (!elements.configMenu || !elements.configMenuBtn) {
    return;
  }

  elements.configMenu.hidden = !isOpen;
  elements.configMenuBtn.setAttribute("aria-expanded", String(isOpen));
}

function toggleConfigMenu() {
  setConfigMenuOpen(Boolean(elements.configMenu?.hidden));
}

function closeConfigMenu() {
  setConfigMenuOpen(false);
}

function setScaleSettingsOpen(scaleId, isOpen) {
  const modal = elements.scaleSettingsModals?.[scaleId];
  const button = elements.scaleSettingsOpenButtons?.[scaleId];

  if (!modal || !button) {
    return;
  }

  modal.hidden = !isOpen;
  button.setAttribute("aria-expanded", String(isOpen));
}

function closeScaleSettings(scaleId) {
  setScaleSettingsOpen(scaleId, false);
}

function closeAllScaleSettings() {
  SCALE_IDS.forEach((scaleId) => closeScaleSettings(scaleId));
}

function openScaleSettings(scaleId) {
  closeConfigMenu();
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();
  closeAllScaleSettings();
  renderScaleConnectionPanels();
  renderScaleSerialOptions(scaleId);
  setScaleSettingsOpen(scaleId, true);
}

function renderScaleSerialOptions(scaleId) {
  const options = getScaleState(scaleId).serialOptions;
  if (elements.scaleSerialBaudInputs[scaleId]) {
    elements.scaleSerialBaudInputs[scaleId].value = String(options.baudRate);
  }
  if (elements.scaleSerialDataBitsSelects[scaleId]) {
    elements.scaleSerialDataBitsSelects[scaleId].value = String(options.dataBits);
  }
  if (elements.scaleSerialStopBitsSelects[scaleId]) {
    elements.scaleSerialStopBitsSelects[scaleId].value = String(options.stopBits);
  }
  if (elements.scaleSerialParitySelects[scaleId]) {
    elements.scaleSerialParitySelects[scaleId].value = options.parity;
  }
  if (elements.scaleSerialFlowControlSelects[scaleId]) {
    elements.scaleSerialFlowControlSelects[scaleId].value = options.flowControl;
  }
}

function saveScaleSerialOptions(scaleId) {
  const baudRate = Number(elements.scaleSerialBaudInputs[scaleId]?.value);
  if (!Number.isInteger(baudRate) || baudRate < 300 || baudRate > 921600) {
    setFormMessage(`Los baudios de la Balanza ${scaleId} deben estar entre 300 y 921600.`, true);
    return;
  }

  const scale = getScaleState(scaleId);
  scale.serialOptions = normalizeScaleSerialOptions({
    baudRate,
    dataBits: elements.scaleSerialDataBitsSelects[scaleId]?.value,
    stopBits: elements.scaleSerialStopBitsSelects[scaleId]?.value,
    parity: elements.scaleSerialParitySelects[scaleId]?.value,
    flowControl: elements.scaleSerialFlowControlSelects[scaleId]?.value
  });
  saveState();
  renderScaleSerialOptions(scaleId);

  const reconnectNotice = isScaleConnectionActive(scaleConnections[scaleId])
    ? " Desconecta y vuelve a conectar para aplicarlos."
    : "";
  setFormMessage(`Parámetros seriales de Balanza ${scaleId} guardados.${reconnectNotice}`);
}

function saveState() {
  try {
    localStorage.setItem(activeStorageKey, JSON.stringify(state));
    return true;
  } catch (error) {
    console.warn("No se pudo guardar el estado local mayorista.", error);
    return false;
  }
}

function getCurrentGrossWeight() {
  const source = elements.weightSource.value;
  const weight = getWeightFromSource(source);
  return Number.isFinite(weight) && weight >= 0 ? roundWeight(weight) : null;
}

function buildCustomerDisplayState() {
  const selectedTruck = getSelectedTruck();
  const birdsPerJava = normalizeBirdCountPerJava(elements.birdCount.value, 0);
  const javaCount = normalizeJavaCount(elements.javaCount.value, 0);
  const customerName = getTruckClientName(selectedTruck);

  return {
    type: "customer-display-state",
    producerId: CUSTOMER_DISPLAY_PRODUCER_ID,
    revision: ++customerDisplayRevision,
    customerName: customerName === "Sin destino asignado" ? "Sin cliente asignado" : customerName,
    weightKg: getCurrentGrossWeight(),
    weightSource: elements.weightSource.value,
    cages: javaCount,
    birds: birdsPerJava > 0 ? calculateBirdTotal(birdsPerJava, javaCount) : 0,
    updatedAt: new Date().toISOString()
  };
}

function flushCustomerDisplayStorage() {
  if (!pendingCustomerDisplayStoragePayload) {
    return;
  }

  try {
    localStorage.setItem(
      `${CUSTOMER_DISPLAY_STORAGE_KEY}:${CUSTOMER_DISPLAY_PRODUCER_ID}`,
      JSON.stringify(pendingCustomerDisplayStoragePayload)
    );
    lastCustomerDisplayStorageWrite = Date.now();
    pendingCustomerDisplayStoragePayload = null;
  } catch {
    // BroadcastChannel mantiene la pantalla en vivo si localStorage no está disponible.
  }
}

function persistCustomerDisplayState(payload, forceStorage = false) {
  pendingCustomerDisplayStoragePayload = payload;
  const remainingDelay = Math.max(100 - (Date.now() - lastCustomerDisplayStorageWrite), 0);

  if (forceStorage || remainingDelay === 0) {
    if (customerDisplayStorageTimer) {
      window.clearTimeout(customerDisplayStorageTimer);
      customerDisplayStorageTimer = null;
    }
    flushCustomerDisplayStorage();
    return;
  }

  if (!customerDisplayStorageTimer) {
    customerDisplayStorageTimer = window.setTimeout(() => {
      customerDisplayStorageTimer = null;
      flushCustomerDisplayStorage();
    }, remainingDelay);
  }
}

function publishCustomerDisplayState(forceStorage = false) {
  const payload = buildCustomerDisplayState();
  customerDisplayChannel?.postMessage(payload);
  persistCustomerDisplayState(payload, forceStorage);
}

function initializeCustomerDisplaySync() {
  if (!("BroadcastChannel" in window)) {
    return;
  }

  customerDisplayChannel = new BroadcastChannel(CUSTOMER_DISPLAY_CHANNEL_NAME);
  customerDisplayChannel.addEventListener("message", (event) => {
    if (
      event.data?.type === "customer-display-request"
      && (!event.data.producerId || event.data.producerId === CUSTOMER_DISPLAY_PRODUCER_ID)
    ) {
      publishCustomerDisplayState(true);
    }
  });
}

function reconcileTruckClientAssignments(shouldSave = false) {
  const validClientIds = new Set(getClientCatalog().map((client) => client.id));
  let changed = false;

  state.trucks.forEach((truck) => {
    const catalogDestination = getClientById(truck.clientId);
    const savedDestination = normalizeStoredDestination(truck.destination, truck.clientId);

    if (truck.clientId && catalogDestination) {
      const nextDestination = snapshotDestination(catalogDestination);
      if (JSON.stringify(truck.destination || null) !== JSON.stringify(nextDestination || null)) {
        truck.destination = nextDestination;
        changed = true;
      }
      return;
    }

    if (truck.clientId && savedDestination) {
      if (JSON.stringify(truck.destination || null) !== JSON.stringify(savedDestination)) {
        truck.destination = savedDestination;
        changed = true;
      }
      return;
    }

    if (!isTruckRegistered(truck) && truck.clientId && !validClientIds.has(truck.clientId)) {
      truck.clientId = null;
      truck.destination = null;
      changed = true;
      return;
    }

    if (!truck.clientId && truck.destination) {
      truck.destination = null;
      changed = true;
    }
  });

  if (changed && shouldSave) {
    saveState();
  }

  return changed;
}

function ensureDefaultOriginSelection(shouldSave = false) {
  if (dailyJourneyPlan === null) {
    return false;
  }

  const origins = getOriginCatalog();
  const currentOriginId = String(
    state.entryDefaults?.originId || state.entryDefaults?.providerId || ""
  ).trim();
  const currentTruckPlate = normalizeTruckPlate(state.entryDefaults?.truckPlate || "");
  const journeyKey = getJourneyKey();
  const storedJourneyKey = String(state.entryDefaults?.journeyKey || "");
  const selectionIsCurrent = Boolean(journeyKey)
    && storedJourneyKey === journeyKey
    && origins.some((origin) => origin.id === currentOriginId);
  const nextOriginId = selectionIsCurrent ? currentOriginId : null;
  const changed = currentOriginId !== String(nextOriginId || "")
    || storedJourneyKey !== journeyKey;

  state.entryDefaults = {
    ...(state.entryDefaults || {}),
    originId: nextOriginId,
    journeyKey,
    truckPlate: changed ? "" : currentTruckPlate
  };
  delete state.entryDefaults.providerId;

  if (changed && shouldSave) {
    saveState();
  }

  return changed;
}

function refreshClientsFromDirectory() {
  const clientsChanged = reconcileTruckClientAssignments(false);
  const originChanged = ensureDefaultOriginSelection(false);
  if (clientsChanged || originChanged) {
    saveState();
  }
  renderAll();
}

async function loadDirectoryRecords(type) {
  const firstPage = await apiRequest(`/operacion/${type}?per_page=100`);
  const records = [...(firstPage.data || [])];
  const lastPage = Number(firstPage.meta?.last_page || 1);

  if (lastPage > 1) {
    const remainingPages = await Promise.all(
      Array.from(
        { length: lastPage - 1 },
        (_, index) => apiRequest(`/operacion/${type}?per_page=100&page=${index + 2}`)
      )
    );
    remainingPages.forEach((page) => records.push(...(page.data || [])));
  }

  return records;
}

function applyOperationCatalog(catalog) {
  activateBranchStorage(catalog);
  operationCatalog = catalog || null;
  const warehouses = Array.isArray(catalog?.warehouses) ? catalog.warehouses : [];

  if (warehouses.length) {
    const destinations = warehouses.map((warehouse, index) => {
      const code = String(warehouse.code || "").trim();
      const codeNumber = Number(code.match(/(\d+)$/)?.[1]);
      const warehouseNumber = Number.isInteger(codeNumber) && codeNumber > 0
        ? codeNumber
        : index + 1;

      return {
        id: `destino-almacen-${warehouseNumber}`,
        name: String(warehouse.name || `Almacén ${warehouseNumber}`),
        destinationType: "almacen",
        warehouseNumber,
        databaseId: String(warehouse.id),
        warehouseCode: code,
        direccion: String(warehouse.address || "")
      };
    });

    WAREHOUSE_DESTINATIONS.splice(0, WAREHOUSE_DESTINATIONS.length, ...destinations);
    WAREHOUSE_ORIGINS.splice(
      0,
      WAREHOUSE_ORIGINS.length,
      ...destinations.map((warehouse) => ({
        id: `origen-almacen-${warehouse.warehouseNumber}`,
        name: warehouse.name,
        nombre: warehouse.name,
        originType: "almacen",
        warehouseNumber: warehouse.warehouseNumber,
        databaseId: warehouse.databaseId,
        warehouseCode: warehouse.warehouseCode,
        dni: "",
        direccion: warehouse.direccion
      }))
    );
  }

  const cageTypesByCode = new Map(
    (catalog?.cage_types || []).map((type) => [String(type.code || ""), type])
  );
  CRATE_TYPES.forEach((crate) => {
    const apiType = cageTypesByCode.get(crate.apiCode);
    if (!apiType) {
      return;
    }

    crate.label = String(apiType.name || crate.label);
    crate.weightKg = roundWeight(Number(apiType.weight_kg) || crate.weightKg);
  });
  renderCrateTypeOptions();

}

function applyJourneyPlan(plan) {
  dailyJourneyPlan = plan && typeof plan === "object"
    ? plan
    : { configured: false, trucks: [], selected_count: 0 };
}

function loadCurrentDirectoryData() {
  if (directoryLoadPromise) {
    return directoryLoadPromise;
  }

  directoryLoadPromise = (async () => {
    const [clientsResult, providersResult, catalogResult, journeyResult] = await Promise.allSettled([
      loadDirectoryRecords("clientes"),
      loadDirectoryRecords("proveedores"),
      apiRequest("/operacion/catalogo"),
      apiRequest("/operacion/jornada")
    ]);

    if (clientsResult.status === "fulfilled") {
      liveDirectoryClients = clientsResult.value;
    }

    if (providersResult.status === "fulfilled") {
      liveDirectoryProviders = providersResult.value;
    }

    if (catalogResult.status === "fulfilled") {
      applyOperationCatalog(catalogResult.value?.data);
    }

    if (journeyResult.status === "fulfilled") {
      applyJourneyPlan(journeyResult.value?.data);
    } else {
      applyJourneyPlan(null);
    }

    refreshClientsFromDirectory();

    if (
      providersResult.status === "rejected"
      || catalogResult.status === "rejected"
      || journeyResult.status === "rejected"
    ) {
      setFormMessage("No se pudieron actualizar todos los catálogos operativos desde la base de datos.", true);
    }
  })().finally(() => {
    directoryLoadPromise = null;
  });

  return directoryLoadPromise;
}

function getScaleState(scaleId) {
  if (!state.scales[scaleId]) {
    state.scales[scaleId] = createDefaultScaleState(scaleId);
  }

  state.scales[scaleId] = normalizeScaleState(state.scales[scaleId], scaleId);
  return state.scales[scaleId];
}

function clearScaleConnectionPreference(scaleId) {
  const scale = getScaleState(scaleId);
  scale.autoConnectMode = null;
  scale.serialPortInfo = null;
  scale.bleDeviceId = "";
  scale.connectionMode = null;
  scale.deviceName = "";
  saveState();
}

function getSerialPortInfo(port) {
  try {
    return normalizeSerialPortInfo(port?.getInfo?.() || {});
  } catch {
    return null;
  }
}

function getSerialPortDeviceName(port) {
  const info = getSerialPortInfo(port);
  if (info?.bluetoothServiceClassId) {
    return `Serial Bluetooth ${info.bluetoothServiceClassId}`.slice(0, 180);
  }
  if (Number.isInteger(info?.usbVendorId) && Number.isInteger(info?.usbProductId)) {
    const vendor = info.usbVendorId.toString(16).toUpperCase().padStart(4, "0");
    const product = info.usbProductId.toString(16).toUpperCase().padStart(4, "0");
    return `Puerto serial USB VID ${vendor} PID ${product}`;
  }
  return "Puerto serial autorizado";
}

function serialPortIdentifiersMatch(portInfo, savedInfo) {
  const current = normalizeSerialPortInfo(portInfo);
  const saved = normalizeSerialPortInfo(savedInfo);
  if (!current || !saved) {
    return false;
  }

  const identifiers = ["usbVendorId", "usbProductId", "bluetoothServiceClassId"];
  const comparableIdentifiers = identifiers.filter((key) => saved[key] !== null);
  return comparableIdentifiers.length > 0
    && comparableIdentifiers.every((key) => current[key] === saved[key]);
}

function serialPortPreferencesMatch(firstInfo, secondInfo) {
  const first = normalizeSerialPortInfo(firstInfo);
  const second = normalizeSerialPortInfo(secondInfo);
  if (!first || !second) {
    return false;
  }

  const identifiers = ["usbVendorId", "usbProductId", "bluetoothServiceClassId"];
  const firstHasIdentifiers = identifiers.some((key) => first[key] !== null);
  const secondHasIdentifiers = identifiers.some((key) => second[key] !== null);
  if (firstHasIdentifiers !== secondHasIdentifiers) {
    return false;
  }
  if (!firstHasIdentifiers) {
    return first.portIndex === second.portIndex;
  }
  return identifiers.every((key) => first[key] === second[key])
    && first.matchIndex === second.matchIndex;
}

function otherScaleRemembersSerialPreference(scaleId, serialPortInfo) {
  return SCALE_IDS.some((candidateId) => {
    if (candidateId === scaleId) {
      return false;
    }
    const candidateScale = getScaleState(candidateId);
    return candidateScale.autoConnectMode === "serial"
      && serialPortPreferencesMatch(candidateScale.serialPortInfo, serialPortInfo);
  });
}

async function rememberSerialScalePort(
  scaleId,
  port,
  expectedConnection = null,
  options = {}
) {
  let authorizedPorts = [];

  if (typeof navigator.serial?.getPorts === "function") {
    try {
      authorizedPorts = await navigator.serial.getPorts();
    } catch {
      authorizedPorts = [];
    }
  }

  if (expectedConnection && !isCurrentScaleConnection(scaleId, expectedConnection)) {
    return false;
  }

  const portInfo = getSerialPortInfo(port) || {
    usbVendorId: null,
    usbProductId: null,
    bluetoothServiceClassId: null,
    matchIndex: null,
    portIndex: null
  };
  const matchingPorts = authorizedPorts.filter((candidate) => {
    const candidateInfo = getSerialPortInfo(candidate);
    return serialPortIdentifiersMatch(candidateInfo, portInfo);
  });
  const rememberedMatchIndex = matchingPorts.findIndex((candidate) => candidate === port);
  const rememberedPortIndex = authorizedPorts.findIndex((candidate) => candidate === port);
  const matchIndex = rememberedMatchIndex >= 0 ? rememberedMatchIndex : null;
  const portIndex = rememberedPortIndex >= 0 ? rememberedPortIndex : null;

  const rememberedPortInfo = {
    ...portInfo,
    matchIndex,
    portIndex
  };
  if (options.rejectDuplicate && otherScaleRemembersSerialPreference(scaleId, rememberedPortInfo)) {
    throw new Error("Ese puerto serial ya está guardado para la otra balanza. Elige un puerto físico diferente.");
  }

  const scale = getScaleState(scaleId);
  scale.autoConnectMode = "serial";
  scale.connectionMode = "serial";
  scale.serialPortInfo = rememberedPortInfo;
  scale.bleDeviceId = "";
  scale.deviceName = getSerialPortDeviceName(port);
  saveState();
  return true;
}

function rememberBleScaleDevice(scaleId, device) {
  const scale = getScaleState(scaleId);
  scale.autoConnectMode = "ble";
  scale.connectionMode = "ble";
  scale.serialPortInfo = null;
  scale.bleDeviceId = String(device?.id || "");
  scale.deviceName = device?.name || "BLE sin nombre";
  saveState();
}

function getScaleModeLabel(mode) {
  if (mode === "ble") {
    return "BLE";
  }

  if (mode === "serial") {
    return "Serial BT";
  }

  return "Manual";
}

function getScaleFeatureWarning() {
  if (!window.isSecureContext) {
    return "Abre la web en localhost o HTTPS para usar Bluetooth/Serial.";
  }

  if (!navigator.bluetooth && !navigator.serial) {
    return "Este navegador no expone Web Bluetooth ni Web Serial.";
  }

  return "";
}

function canRestoreSerialScaleConnections() {
  return typeof navigator.serial?.getPorts === "function";
}

function canRestoreBleScaleConnections() {
  return typeof navigator.bluetooth?.getDevices === "function";
}

function formatScaleTime(timestamp) {
  if (!timestamp) {
    return "Sin lecturas";
  }

  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) {
    return "Lectura guardada";
  }

  return `Leído ${formatPeruTime(date, {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit"
  })}`;
}

function renderScaleConnectionPanels() {
  const featureWarning = getScaleFeatureWarning();
  const canUseBle = window.isSecureContext && Boolean(navigator.bluetooth);
  const canUseSerial = window.isSecureContext && Boolean(navigator.serial);

  SCALE_IDS.forEach((scaleId) => {
    const scale = getScaleState(scaleId);
    const connection = scaleConnections[scaleId];
    const statusElement = elements.scaleStatusDisplays[scaleId];
    const lastElement = elements.scaleLastDisplays[scaleId];
    const rawElement = elements.scaleRawDisplays[scaleId];
    const connectBleButton = elements.scaleConnectBleButtons[scaleId];
    const connectSerialButton = elements.scaleConnectSerialButtons[scaleId];
    const disconnectButton = elements.scaleDisconnectButtons[scaleId];

    let statusClass = "scale-status-offline";
    const rememberedMode = scale.autoConnectMode
      ? getScaleModeLabel(scale.autoConnectMode)
      : "";
    let statusText = featureWarning || (
      rememberedMode
        ? `Reconexión automática ${rememberedMode} pendiente`
        : "Sin conexión Bluetooth"
    );
    if (!featureWarning && scale.autoConnectMode === "ble" && !canRestoreBleScaleConnections()) {
      statusText = "BLE recordada; este navegador requiere usar Conectar BLE";
    } else if (!featureWarning && scale.autoConnectMode === "serial" && !canRestoreSerialScaleConnections()) {
      statusText = "Puerto serial recordado; este navegador requiere usar Conectar Serial";
    }
    const isConnecting = connection?.status === "connecting";
    const isConnected = connection?.status === "connected";
    const hasRememberedPreference = Boolean(scale.autoConnectMode);

    if (isConnecting) {
      statusClass = "scale-status-connecting";
      statusText = connection.statusMessage || "Conectando...";
    } else if (isConnected) {
      statusClass = "scale-status-connected";
      const modeLabel = getScaleModeLabel(connection.mode);
      const name = connection.deviceName ? ` - ${connection.deviceName}` : "";
      statusText = connection.statusMessage || `Conectada ${modeLabel}${name}`;
    } else if (connection?.status === "error") {
      statusClass = "scale-status-error";
      statusText = connection.statusMessage || "Conexión interrumpida";
    }

    if (statusElement) {
      statusElement.className = `scale-status ${statusClass}`;
      statusElement.textContent = statusText;
    }

    if (lastElement) {
      lastElement.textContent = formatScaleTime(scale.updatedAt);
    }

    if (rawElement) {
      rawElement.textContent = `Trama: ${scale.lastRaw || "--"}`;
      rawElement.title = scale.lastRaw || "";
    }

    if (connectBleButton) {
      connectBleButton.disabled = isConnecting || isConnected || !canUseBle;
    }

    if (connectSerialButton) {
      connectSerialButton.disabled = isConnecting || isConnected || !canUseSerial;
    }

    if (disconnectButton) {
      disconnectButton.disabled = !isConnecting && !isConnected && !hasRememberedPreference;
    }
  });
}

function scheduleScaleRender(scaleId) {
  if (scaleRenderFrames[scaleId]) {
    return;
  }

  scaleRenderFrames[scaleId] = window.requestAnimationFrame(() => {
    scaleRenderFrames[scaleId] = null;
    renderScaleDisplays();
    renderWeightPreview();
    if (!elements.jsonModal?.hidden) {
      renderJson();
    }
    renderScaleConnectionPanels();
  });
}

function persistScaleReading(scaleId, force = false) {
  const now = Date.now();
  if (!force && now - scaleLastPersistedAt[scaleId] < SCALE_PERSIST_INTERVAL_MS) {
    if (!scalePersistTimers[scaleId]) {
      const delay = SCALE_PERSIST_INTERVAL_MS - (now - scaleLastPersistedAt[scaleId]);
      scalePersistTimers[scaleId] = window.setTimeout(() => {
        scalePersistTimers[scaleId] = null;
        persistScaleReading(scaleId, true);
      }, Math.max(delay, 0));
    }
    return;
  }

  if (scalePersistTimers[scaleId]) {
    window.clearTimeout(scalePersistTimers[scaleId]);
    scalePersistTimers[scaleId] = null;
  }
  scaleLastPersistedAt[scaleId] = now;
  saveState();
}

function sanitizeScaleRawText(rawText) {
  const text = String(rawText ?? "")
    .replace(/\0/g, "")
    .replace(/\r/g, "\\r")
    .replace(/\n/g, "\\n")
    .replace(/\s+/g, " ")
    .trim();

  return text.slice(-MAX_SCALE_RAW_LENGTH);
}

function dataViewToBytes(value) {
  if (value instanceof DataView) {
    return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
  }

  if (value instanceof Uint8Array) {
    return value;
  }

  if (value instanceof ArrayBuffer) {
    return new Uint8Array(value);
  }

  return new Uint8Array();
}

function bytesToHex(bytes) {
  return Array.from(bytes)
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join(" ");
}

function decodeScaleBytes(value) {
  const bytes = dataViewToBytes(value);
  if (!bytes.length) {
    return "";
  }

  try {
    return scaleTextDecoder.decode(bytes);
  } catch {
    return "";
  }
}

function parseWeightUnit(unit) {
  const normalized = String(unit || "kg").trim().toLowerCase();

  if (["lb", "lbs", "libra", "libras"].includes(normalized)) {
    return "lb";
  }

  if (["g", "gr", "gramo", "gramos"].includes(normalized)) {
    return "g";
  }

  return "kg";
}

function convertWeightToKg(value, unit) {
  if (unit === "lb") {
    return value * KG_PER_LB;
  }

  if (unit === "g") {
    return value / 1000;
  }

  return value;
}

function getIndustrialScaleCandidateRole(text, start, end) {
  const contextStart = Math.max(0, start - 28);
  const contextEnd = Math.min(text.length, end + 20);
  const context = text.slice(contextStart, contextEnd).toUpperCase();
  const roles = [
    { role: "net", pattern: /\b(?:NET|NETO|NT|NW)\b/g },
    { role: "gross", pattern: /\b(?:GROSS|BRUTO|GS|GW)\b/g },
    { role: "tare", pattern: /\b(?:TARE|TARA|TAR|TR|T|PT|TW)\b/g },
    { role: "weight", pattern: /\b(?:WEIGHT|PESO)\b/g }
  ];
  const labels = [];
  const firstNumberIndex = text.search(/\d/);
  const firstLabelIndex = text.search(/\b(?:NET|NETO|NT|NW|GROSS|BRUTO|GS|GW|TARE|TARA|TAR|TR|T|PT|TW|WEIGHT|PESO)\b/i);
  const labelsFollowValues = firstNumberIndex >= 0
    && firstLabelIndex >= 0
    && firstNumberIndex < firstLabelIndex;

  roles.forEach(({ role, pattern }) => {
    let label = pattern.exec(context);
    while (label) {
      const labelStart = contextStart + label.index;
      const labelEnd = labelStart + label[0].length;
      if ((labelsFollowValues && labelStart < end) || (!labelsFollowValues && labelEnd > start)) {
        label = pattern.exec(context);
        continue;
      }
      const distance = labelEnd <= start
        ? start - labelEnd
        : (labelStart >= end ? labelStart - end : 0);
      labels.push({ role, distance });
      label = pattern.exec(context);
    }
  });

  return labels.sort((left, right) => left.distance - right.distance)[0]?.role || "unlabeled";
}

export function parseIndustrialScaleText(rawText) {
  const text = String(rawText ?? "").replace(/\0/g, " ").trim();
  if (!text) {
    return null;
  }

  const normalizedText = text.toUpperCase();
  if (
    /\b(?:ERR(?:OR)?|FAULT|FALLO|OVER(?:LOAD)?|UNDER(?:LOAD)?|OL)\b/.test(normalizedText)
    || /^\s*S\s+(?:I|\+|-)(?:\s|,|$)/.test(normalizedText)
  ) {
    return { weightKg: null, rawValue: null, status: "error", stable: false };
  }
  if (
    /\b(?:US|UNSTABLE|MOTION|MOVING|INESTABLE|DYNAMIC|DINAMICO)\b/.test(normalizedText)
    || /\bS\s*(?:D|U)\b/.test(normalizedText)
  ) {
    return { weightKg: null, rawValue: null, status: "unstable", stable: false };
  }

  const matches = [];
  const weightPattern = /([+-]?\s*\d+(?:[.,]\d+)?)(?:\s*(kilogramos|kilogramo|gramos|gramo|libras|libra|kgs|lbs|kg|lb|gr|g)\b)?/gi;
  let match = weightPattern.exec(text);

  while (match) {
    const numericText = match[1].replace(/\s+/g, "").replace(",", ".");
    const value = Number(numericText);

    if (Number.isFinite(value)) {
      const candidateRole = getIndustrialScaleCandidateRole(
        text,
        match.index,
        match.index + match[0].length
      );
      const isTareCandidate = candidateRole === "tare";
      const unit = parseWeightUnit(match[2]);
      const hasUnit = Boolean(match[2]);
      const hasDecimal = /[.,]/.test(match[1]);
      const hasSign = /^[+-]/.test(match[1].trim());
      const weightKg = convertWeightToKg(value, unit);
      const roleScore = candidateRole === "gross"
        ? 16
        : (["net", "weight"].includes(candidateRole) ? 12 : 0);
      const score = (hasUnit ? 20 : 0)
        + (hasDecimal ? 8 : 0)
        + (hasSign ? 4 : 0)
        + roleScore
        - (isTareCandidate ? 100 : 0)
        + match.index / 100000;

      matches.push({
        weightKg,
        score,
        rawValue: match[0].trim(),
        isTareCandidate,
        role: candidateRole
      });
    }

    match = weightPattern.exec(text);
  }

  if (!matches.length) {
    return null;
  }

  const commercialMatches = matches.filter((candidate) => !candidate.isTareCandidate);
  if (!commercialMatches.length) {
    return { weightKg: null, rawValue: null, status: "tare-only", stable: false };
  }

  const bestMatch = commercialMatches.sort((a, b) => b.score - a.score)[0];
  if (bestMatch.weightKg < 0) {
    return { weightKg: null, rawValue: null, status: "negative", stable: false };
  }

  return {
    weightKg: roundWeight(bestMatch.weightKg),
    rawValue: bestMatch.rawValue,
    status: "weight",
    stable: /\b(?:ST|STABLE|ESTABLE)\b/.test(normalizedText)
      || /\bS\s*S\b/.test(normalizedText)
  };
}

export function parseSigWeightMeasurement(value) {
  const bytes = dataViewToBytes(value);
  if (bytes.length < 3) {
    return null;
  }

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const flags = view.getUint8(0);
  const rawWeight = view.getUint16(1, true);
  if (rawWeight === 0xffff) {
    return null;
  }
  const usesImperialUnits = Boolean(flags & 0x01);
  const weightKg = usesImperialUnits
    ? rawWeight * 0.01 * KG_PER_LB
    : rawWeight * 0.005;

  return {
    weightKg: roundWeight(weightKg),
    rawValue: `BLE Weight Measurement ${rawWeight}`,
    status: "weight",
    stable: true
  };
}

export function parseScalePayload(payload, parser = "text") {
  if (typeof payload === "string") {
    const textReading = parseIndustrialScaleText(payload);
    return {
      rawText: payload,
      weightKg: textReading?.weightKg ?? null,
      rawValue: textReading?.rawValue ?? null,
      status: textReading?.status || "no-weight",
      stable: Boolean(textReading?.stable)
    };
  }

  const bytes = dataViewToBytes(payload);
  const decodedText = decodeScaleBytes(bytes);

  if (parser === "sig-weight") {
    const binaryReading = parseSigWeightMeasurement(bytes);
    if (binaryReading) {
      return {
        rawText: bytesToHex(bytes),
        weightKg: binaryReading.weightKg,
        rawValue: binaryReading.rawValue,
        status: binaryReading.status,
        stable: binaryReading.stable
      };
    }
    return {
      rawText: bytesToHex(bytes),
      weightKg: null,
      rawValue: null,
      status: "no-weight",
      stable: false
    };
  }

  const decodedReading = parseIndustrialScaleText(decodedText);

  if (decodedReading) {
    return {
      rawText: decodedText,
      weightKg: decodedReading.weightKg,
      rawValue: decodedReading.rawValue,
      status: decodedReading.status,
      stable: decodedReading.stable
    };
  }

  return {
    rawText: decodedText || bytesToHex(bytes),
    weightKg: null,
    rawValue: null,
    status: "no-weight",
    stable: false
  };
}

export function splitScaleTextFrames(buffer, chunkText) {
  const combined = `${buffer || ""}${chunkText || ""}`;
  let safeText = combined;
  let overflowed = false;

  if (combined.length > SCALE_MAX_SERIAL_BUFFER_LENGTH) {
    overflowed = true;
    const delimiter = combined.match(/\r\n|\r|\n/);
    if (!delimiter) {
      return {
        completeFrames: [],
        pendingFrame: "",
        overflowed: true,
        discardUntilDelimiter: true
      };
    }
    safeText = combined.slice(delimiter.index + delimiter[0].length);
  }

  const frames = safeText.split(/\r\n|\r|\n/);
  const pendingFrame = frames.at(-1) || "";
  return {
    completeFrames: frames
      .slice(0, -1)
      .filter((frame) => frame.trim() && frame.length <= SCALE_MAX_SERIAL_BUFFER_LENGTH),
    pendingFrame: pendingFrame.length <= SCALE_MAX_SERIAL_BUFFER_LENGTH ? pendingFrame : "",
    overflowed,
    discardUntilDelimiter: pendingFrame.length > SCALE_MAX_SERIAL_BUFFER_LENGTH
  };
}

function isCurrentScaleConnection(scaleId, connection) {
  return Boolean(
    connection
    && scaleConnections[scaleId] === connection
    && connection.generation === scaleConnectionGenerations[scaleId]
    && !connection.abort
  );
}

function nextScaleConnectionGeneration(scaleId) {
  scaleConnectionGenerations[scaleId] = (scaleConnectionGenerations[scaleId] || 0) + 1;
  return scaleConnectionGenerations[scaleId];
}

export function canReuseScaleReadingId(previousReading, nextReading, forceNewReading = false) {
  const previousWeight = Number(previousReading?.weightKg);
  const nextWeight = Number(nextReading?.weightKg);
  return !forceNewReading
    && previousReading?.valid === true
    && Boolean(previousReading?.readingId)
    && previousReading?.mode === nextReading?.mode
    && previousReading?.generation === nextReading?.generation
    && Number.isFinite(previousWeight)
    && Number.isFinite(nextWeight)
    && Math.abs(previousWeight - nextWeight) <= SCALE_STABILITY_TOLERANCE_KG;
}

export function shouldStartNewScaleReadingIdentity(previousReading, nextReading) {
  if (!previousReading?.readingId || previousReading?.valid !== true) {
    return false;
  }

  return nextReading?.status === "unstable";
}

export function canReleaseConsumedScaleReadingId(
  consumedReadingId,
  removedScaleReading,
  remainingScaleReadings = []
) {
  const removedReadingId = String(removedScaleReading?.readingId || "");
  if (!removedReadingId || String(consumedReadingId || "") !== removedReadingId) {
    return false;
  }

  return !remainingScaleReadings.some(
    (reading) => String(reading?.readingId || "") === removedReadingId
  );
}

function nextScaleReadingId(scaleId, connection) {
  scaleReadingSequences[scaleId] = (scaleReadingSequences[scaleId] || 0) + 1;
  return `${connection?.mode || "scale"}-${scaleId}-${connection?.generation || 0}-${Date.now()}-${scaleReadingSequences[scaleId]}`;
}

function invalidateScaleReading(scaleId, status = "unknown", options = {}) {
  const scale = getScaleState(scaleId);
  scale.currentWeight = null;
  scale.readingValid = false;
  scale.readingStable = false;
  scale.readingStatus = status;
  scale.readingId = null;
  if (options.clearTimestamp) {
    scale.updatedAt = null;
  }
  clearCapturedScaleReading(scaleId);
  persistScaleReading(scaleId, Boolean(options.persistImmediately));
  scheduleScaleRender(scaleId);
}

function markScaleReadingPending(scaleId, status) {
  const scale = getScaleState(scaleId);
  scale.readingStatus = status;
  persistScaleReading(scaleId);
  scheduleScaleRender(scaleId);
}

function scheduleScaleReadingExpiry(scaleId, connection, readingTimestamp) {
  if (!connection) {
    return;
  }
  if (connection.freshnessTimer) {
    window.clearTimeout(connection.freshnessTimer);
  }
  connection.freshnessTimer = window.setTimeout(() => {
    const scale = getScaleState(scaleId);
    if (!isCurrentScaleConnection(scaleId, connection) || scale.updatedAt !== readingTimestamp) {
      return;
    }
    invalidateScaleReading(scaleId, "stale", { persistImmediately: true });
    connection.statusMessage = `Conectada ${getScaleModeLabel(connection.mode)} - lectura vencida`;
    renderScaleConnectionPanels();
  }, SCALE_READING_MAX_AGE_MS + 50);
}

function acceptScaleReading(scaleId, connection, reading, rawText, options) {
  const scale = getScaleState(scaleId);
  const readingMode = options.mode || connection?.mode || scale.connectionMode;
  const readingGeneration = connection?.generation || 0;
  const reuseReadingId = canReuseScaleReadingId(
    {
      readingId: scale.readingId,
      valid: scale.readingValid,
      weightKg: scale.currentWeight,
      mode: scale.readingMode,
      generation: scale.readingGeneration
    },
    {
      weightKg: reading.weightKg,
      mode: readingMode,
      generation: readingGeneration
    },
    Boolean(connection?.forceNewReading)
  );
  const timestamp = new Date().toISOString();
  scale.currentWeight = roundWeight(reading.weightKg);
  scale.updatedAt = timestamp;
  scale.readingValid = true;
  scale.readingStable = true;
  scale.readingStatus = "stable";
  scale.readingRaw = rawText;
  scale.readingMode = readingMode;
  scale.readingDeviceName = options.deviceName || connection?.deviceName || scale.deviceName;
  scale.readingGeneration = readingGeneration;
  scale.readingId = reuseReadingId ? scale.readingId : nextScaleReadingId(scaleId, connection);
  scale.connectionMode = scale.readingMode;
  scale.deviceName = scale.readingDeviceName;
  if (connection) {
    connection.forceNewReading = false;
  }
  scheduleScaleReadingExpiry(scaleId, connection, timestamp);
}

export function evaluateScaleStability(previousTracker, reading, now = Date.now()) {
  const tracker = previousTracker || {
    candidateWeight: null,
    count: 0,
    firstSeenAt: 0,
    lastSeenAt: 0
  };
  const sameCandidate = Number.isFinite(tracker.candidateWeight)
    && Math.abs(tracker.candidateWeight - reading.weightKg) <= SCALE_STABILITY_TOLERANCE_KG
    && now - tracker.lastSeenAt <= SCALE_STABILITY_WINDOW_MS;

  const nextTracker = sameCandidate
    ? { ...tracker, count: tracker.count + 1, lastSeenAt: now }
    : {
        candidateWeight: reading.weightKg,
        count: 1,
        firstSeenAt: now,
        lastSeenAt: now
      };

  const zeroCandidate = reading.weightKg === 0;
  const requiredCount = zeroCandidate
    ? SCALE_ZERO_CONFIRMATION_COUNT
    : (reading.stable ? 1 : SCALE_STABLE_READING_COUNT);
  const waitedLongEnough = !zeroCandidate || now - nextTracker.firstSeenAt >= SCALE_ZERO_CONFIRMATION_MS;
  const accepted = nextTracker.count >= requiredCount && waitedLongEnough;

  return {
    tracker: nextTracker,
    accepted,
    status: accepted ? "stable" : (zeroCandidate ? "confirming-zero" : "stabilizing")
  };
}

function applyScaleStabilityFilter(scaleId, connection, reading, rawText, options) {
  const result = evaluateScaleStability(connection.stability, reading);
  connection.stability = result.tracker;

  if (!result.accepted) {
    markScaleReadingPending(scaleId, result.status);
    return false;
  }

  acceptScaleReading(scaleId, connection, reading, rawText, options);
  return true;
}

function handleScalePayload(scaleId, payload, options = {}) {
  const connection = options.connection || scaleConnections[scaleId];
  if (options.connection && !isCurrentScaleConnection(scaleId, options.connection)) {
    return false;
  }
  const reading = parseScalePayload(payload, options.parser || "text");
  const scale = getScaleState(scaleId);
  const rawText = sanitizeScaleRawText(reading.rawText);

  if (connection && shouldStartNewScaleReadingIdentity(
    {
      readingId: scale.readingId,
      valid: scale.readingValid,
      weightKg: scale.currentWeight
    },
    reading
  )) {
    connection.forceNewReading = true;
  }

  if (rawText) {
    scale.lastRaw = rawText;
    scale.lastRawAt = new Date().toISOString();
  }

  if (!Number.isFinite(reading.weightKg)) {
    if (connection) {
      connection.stability = null;
    }
    markScaleReadingPending(scaleId, reading.status || "no-weight");
    if (connection?.status === "connected") {
      const statusLabel = reading.status === "unstable"
        ? "lectura inestable"
        : (reading.status === "error" ? "trama de error" : "datos sin peso comercial");
      connection.statusMessage = `Conectada ${getScaleModeLabel(connection.mode)} - ${statusLabel}`;
    }

    persistScaleReading(scaleId);
    scheduleScaleRender(scaleId);
    return false;
  }

  if (reading.weightKg < 0) {
    markScaleReadingPending(scaleId, "negative");
    return false;
  }

  const accepted = connection
    ? applyScaleStabilityFilter(scaleId, connection, reading, rawText, options)
    : false;

  if (connection?.status === "connected") {
    const profileLabel = options.profileLabel || connection.profileLabel || getScaleModeLabel(connection.mode);
    connection.statusMessage = accepted
      ? `Conectada ${getScaleModeLabel(connection.mode)} - ${profileLabel} - peso estable`
      : `Conectada ${getScaleModeLabel(connection.mode)} - estabilizando lectura`;
  }

  persistScaleReading(scaleId);
  scheduleScaleRender(scaleId);
  return accepted;
}

function getConnectionErrorMessage(error, fallback) {
  if (error?.name === "NotFoundError") {
    return "Selección cancelada por el usuario.";
  }

  if (error?.name === "SecurityError") {
    return "El navegador bloqueó el acceso. Usa localhost o HTTPS.";
  }

  if (error?.name === "NetworkError") {
    return "No se pudo mantener la conexión con el dispositivo.";
  }

  return error?.message || fallback;
}

async function findBleScaleCharacteristic(server) {
  for (const profile of SCALE_BLE_PROFILES) {
    let service = null;

    try {
      service = await server.getPrimaryService(profile.service);
    } catch {
      continue;
    }

    for (const characteristicUuid of profile.characteristics) {
      try {
        const characteristic = await service.getCharacteristic(characteristicUuid);
        const canNotify = characteristic.properties.notify || characteristic.properties.indicate;
        const canRead = characteristic.properties.read;

        if (canNotify || canRead) {
          return {
            profile,
            characteristic,
            canNotify,
            canRead
          };
        }
      } catch {
        // Se prueba el siguiente UUID porque las balanzas industriales no tienen un perfil unico.
      }
    }
  }

  return null;
}

function clearScaleConnectionTimers(connection) {
  if (connection?.pollingTimer) {
    window.clearInterval(connection.pollingTimer);
    connection.pollingTimer = null;
  }
  if (connection?.freshnessTimer) {
    window.clearTimeout(connection.freshnessTimer);
    connection.freshnessTimer = null;
  }
}

function otherScaleUsingSerialPort(scaleId, port) {
  return SCALE_IDS.some((candidateId) => {
    if (candidateId === scaleId) {
      return false;
    }
    const candidate = scaleConnections[candidateId];
    return candidate?.port === port && !candidate.abort;
  });
}

function otherScaleUsingBleDevice(scaleId, device, options = {}) {
  const deviceId = String(device?.id || "");
  if (!deviceId) {
    return false;
  }
  return SCALE_IDS.some((candidateId) => {
    if (candidateId === scaleId) {
      return false;
    }
    const candidate = scaleConnections[candidateId];
    const activeDeviceId = String(candidate?.device?.id || "");
    const rememberedDeviceId = String(getScaleState(candidateId).bleDeviceId || "");
    if (!candidate?.abort && activeDeviceId === deviceId) {
      return true;
    }
    if (rememberedDeviceId !== deviceId) {
      return false;
    }
    return options.automatic ? candidateId < scaleId : true;
  });
}

function processScaleTextFrame(scaleId, connection, frame, options = {}) {
  const normalizedFrame = String(frame || "").trim();
  if (!normalizedFrame || !isCurrentScaleConnection(scaleId, connection)) {
    return false;
  }
  return handleScalePayload(scaleId, normalizedFrame, {
    ...options,
    parser: "text",
    connection
  });
}

function handleFramedScaleTextChunk(scaleId, connection, value, options = {}) {
  if (!isCurrentScaleConnection(scaleId, connection)) {
    return;
  }

  let chunkText = "";
  if (typeof value === "string") {
    chunkText = value;
  } else {
    try {
      chunkText = connection.decoder.decode(dataViewToBytes(value), { stream: true });
    } catch {
      chunkText = "";
    }
  }
  if (!chunkText) {
    return;
  }

  if (connection.discardUntilDelimiter) {
    const delimiter = chunkText.match(/\r\n|\r|\n/);
    if (!delimiter) {
      return;
    }
    chunkText = chunkText.slice(delimiter.index + delimiter[0].length);
    connection.discardUntilDelimiter = false;
    connection.buffer = "";
    if (!chunkText) {
      return;
    }
  }

  const framed = splitScaleTextFrames(connection.buffer, chunkText);
  connection.buffer = framed.pendingFrame;
  connection.discardUntilDelimiter = framed.discardUntilDelimiter;
  if (framed.overflowed) {
    const scale = getScaleState(scaleId);
    const overflowTail = sanitizeScaleRawText(chunkText).slice(-(MAX_SCALE_RAW_LENGTH - 20));
    scale.lastRaw = `[trama excedida] ${overflowTail}`;
    scale.lastRawAt = new Date().toISOString();
    connection.stability = null;
    connection.statusMessage = `Conectada ${getScaleModeLabel(connection.mode)} - trama inválida descartada`;
    markScaleReadingPending(scaleId, "invalid");
  }
  framed.completeFrames.forEach((frame) => processScaleTextFrame(scaleId, connection, frame, options));
}

function releaseUnexpectedScaleConnection(connection) {
  if (connection.characteristic && connection.valueHandler) {
    try {
      connection.characteristic.removeEventListener("characteristicvaluechanged", connection.valueHandler);
    } catch {
      // El objeto BLE puede haber quedado inutilizable al perder señal.
    }
  }
  if (connection.device && connection.disconnectHandler) {
    try {
      connection.device.removeEventListener("gattserverdisconnected", connection.disconnectHandler);
    } catch {
      // El listener ya puede haber sido retirado por el navegador.
    }
  }
  if (connection.device?.gatt?.connected) {
    try {
      connection.device.gatt.disconnect();
    } catch {
      // Desconexión de mejor esfuerzo.
    }
  }

  if (connection.reader) {
    try {
      void Promise.resolve(connection.reader.cancel()).catch(() => {});
    } catch {
      // El reader ya puede haber sido liberado por el navegador.
    }
  }
  if (connection.port) {
    void Promise.resolve(connection.readPromise)
      .catch(() => {})
      .then(() => connection.port.close())
      .catch(() => {});
  }
}

function isScaleLifecycleCurrent(generation = scaleLifecycleGeneration) {
  return scalePageActive && generation === scaleLifecycleGeneration;
}

function resumeScaleConnectionsAfterNavigation() {
  scaleLifecycleGeneration += 1;
  scalePageActive = true;
}

function releaseScaleConnectionsForNavigation(options = {}) {
  scaleLifecycleGeneration += 1;
  if (options.deactivate) {
    scalePageActive = false;
  }
  if (scaleRestoreTimer) {
    window.clearTimeout(scaleRestoreTimer);
    scaleRestoreTimer = null;
  }
  scaleRestoreAttempt = 0;

  SCALE_IDS.forEach((scaleId) => {
    const connection = scaleConnections[scaleId];
    nextScaleConnectionGeneration(scaleId);
    if (connection) {
      connection.abort = true;
      clearScaleConnectionTimers(connection);
      // gatt.disconnect() ocurre de forma síncrona dentro de este helper. Para
      // Serial se cancela el reader y se encadena close() sin bloquear pagehide.
      releaseUnexpectedScaleConnection(connection);
      if (scaleConnections[scaleId] === connection) {
        scaleConnections[scaleId] = null;
      }
    }
    // La preferencia (BLE ID / descriptor Serial) permanece; solo se invalida
    // la lectura para que BFCache nunca rehabilite un peso de la vista anterior.
    invalidateScaleReading(scaleId, "disconnected", { persistImmediately: true });
  });
}

function handleScaleDisconnected(scaleId, message = "Balanza desconectada.", expectedConnection = null) {
  const connection = expectedConnection || scaleConnections[scaleId];
  if (!connection || connection.abort || !isCurrentScaleConnection(scaleId, connection)) {
    return;
  }

  connection.abort = true;
  clearScaleConnectionTimers(connection);
  releaseUnexpectedScaleConnection(connection);
  const generation = nextScaleConnectionGeneration(scaleId);
  scaleConnections[scaleId] = {
    generation,
    status: "error",
    statusMessage: message,
    abort: false
  };
  invalidateScaleReading(scaleId, "disconnected", { persistImmediately: true });
  renderScaleConnectionPanels();
  setFormMessage(message, true);
  scheduleScaleConnectionRestore({ reset: true });
}

function startBleReadPolling(scaleId) {
  const connection = scaleConnections[scaleId];
  if (!connection?.characteristic || !connection.canRead) {
    return;
  }

  connection.pollingTimer = window.setInterval(async () => {
    const currentConnection = scaleConnections[scaleId];
    if (!currentConnection || currentConnection !== connection || currentConnection.abort || currentConnection.isPolling) {
      return;
    }

    currentConnection.isPolling = true;
    try {
      const value = await currentConnection.characteristic.readValue();
      if (currentConnection.parser === "sig-weight") {
        handleScalePayload(scaleId, value, {
          parser: "sig-weight",
          mode: "ble",
          deviceName: currentConnection.deviceName,
          profileLabel: currentConnection.profileLabel,
          connection: currentConnection
        });
      } else {
        handleFramedScaleTextChunk(scaleId, currentConnection, value, {
          mode: "ble",
          deviceName: currentConnection.deviceName,
          profileLabel: currentConnection.profileLabel
        });
      }
    } catch (error) {
      handleScaleDisconnected(
        scaleId,
        getConnectionErrorMessage(error, `No se pudo leer la balanza ${scaleId}.`),
        currentConnection
      );
    } finally {
      currentConnection.isPolling = false;
    }
  }, 750);
}

async function disconnectScale(scaleId, showMessage = true, forgetPreference = false) {
  const connection = scaleConnections[scaleId];
  if (!connection) {
    nextScaleConnectionGeneration(scaleId);
    invalidateScaleReading(scaleId, "disconnected", { persistImmediately: true });
    if (forgetPreference) {
      clearScaleConnectionPreference(scaleId);
    }
    renderScaleConnectionPanels();
    return;
  }

  nextScaleConnectionGeneration(scaleId);
  connection.abort = true;
  connection.status = "connecting";
  connection.statusMessage = "Desconectando...";
  renderScaleConnectionPanels();
  clearScaleConnectionTimers(connection);

  if (connection.characteristic && connection.valueHandler) {
    try {
      connection.characteristic.removeEventListener("characteristicvaluechanged", connection.valueHandler);
    } catch {
      // Algunos navegadores no exponen remocion si el dispositivo ya se desconecto.
    }
  }

  if (connection.characteristic && connection.notifyActive) {
    try {
      await connection.characteristic.stopNotifications();
    } catch {
      // Si la balanza ya corto la conexion, basta con limpiar el estado local.
    }
  }

  if (connection.device && connection.disconnectHandler) {
    try {
      connection.device.removeEventListener("gattserverdisconnected", connection.disconnectHandler);
    } catch {
      // No todos los objetos de dispositivo permiten quitar el listener despues de desconectar.
    }
  }

  if (connection.device?.gatt?.connected) {
    try {
      connection.device.gatt.disconnect();
    } catch {
      // Desconectar es mejor esfuerzo.
    }
  }

  if (connection.reader) {
    try {
      await connection.reader.cancel();
    } catch {
      // El lector puede estar cerrado por el sistema.
    }
  }

  if (connection.readPromise) {
    try {
      await connection.readPromise;
    } catch {
      // El bucle de lectura ya informa los errores inesperados.
    }
  }

  if (connection.port) {
    try {
      await connection.port.close();
    } catch {
      // El puerto puede seguir liberando el reader; el loop de lectura terminara solo.
    }
  }

  if (scaleConnections[scaleId] === connection) {
    scaleConnections[scaleId] = null;
  }
  invalidateScaleReading(scaleId, "disconnected", { persistImmediately: true });
  if (forgetPreference) {
    clearScaleConnectionPreference(scaleId);
  }
  renderScaleConnectionPanels();

  if (showMessage) {
    setFormMessage(`Balanza ${scaleId} desconectada.`);
  }
}

async function connectBleScale(scaleId, rememberedDevice = null, options = {}) {
  const lifecycleGeneration = Number.isInteger(options.lifecycleGeneration)
    ? options.lifecycleGeneration
    : scaleLifecycleGeneration;
  if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
    return false;
  }
  if (!window.isSecureContext) {
    setFormMessage("Para conectar por Bluetooth abre la web en localhost o HTTPS.", true);
    renderScaleConnectionPanels();
    return;
  }

  if (!navigator.bluetooth) {
    setFormMessage("Este navegador no soporta Web Bluetooth BLE.", true);
    renderScaleConnectionPanels();
    return;
  }

  await disconnectScale(scaleId, false);
  if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
    return false;
  }
  const automatic = Boolean(rememberedDevice);
  const generation = nextScaleConnectionGeneration(scaleId);
  const connection = {
    generation,
    mode: "ble",
    status: "connecting",
    statusMessage: automatic
      ? `Reconectando Balanza ${scaleId} por BLE...`
      : `Selecciona el dispositivo BLE para Balanza ${scaleId}...`,
    decoder: new TextDecoder("utf-8"),
    buffer: "",
    stability: null,
    abort: false
  };
  scaleConnections[scaleId] = connection;
  renderScaleConnectionPanels();

  try {
    const device = rememberedDevice || await navigator.bluetooth.requestDevice({
      acceptAllDevices: true,
      optionalServices: SCALE_BLE_SERVICE_UUIDS
    });
    if (!isCurrentScaleConnection(scaleId, connection)) {
      return false;
    }
    if (otherScaleUsingBleDevice(scaleId, device, { automatic })) {
      throw new Error(`Ese dispositivo BLE ya está asignado a la otra balanza. Desconéctalo o elimina su preferencia antes de continuar.`);
    }

    const deviceName = device.name || "BLE sin nombre";
    connection.device = device;
    connection.deviceName = deviceName;
    connection.statusMessage = `Conectando a ${deviceName}...`;
    connection.disconnectHandler = () => {
      if (!isCurrentScaleConnection(scaleId, connection)) {
        return;
      }
      handleScaleDisconnected(scaleId, `Balanza ${scaleId} BLE desconectada.`, connection);
    };
    device.addEventListener("gattserverdisconnected", connection.disconnectHandler);
    renderScaleConnectionPanels();

    const server = await device.gatt.connect();
    if (!isCurrentScaleConnection(scaleId, connection)) {
      if (device.gatt.connected) {
        device.gatt.disconnect();
      }
      return false;
    }
    const found = await findBleScaleCharacteristic(server);
    if (!isCurrentScaleConnection(scaleId, connection)) {
      if (device.gatt.connected) {
        device.gatt.disconnect();
      }
      return false;
    }
    if (!found) {
      throw new Error("No se encontro una caracteristica BLE compatible. Agrega el UUID de la balanza cuando tengas la marca/modelo.");
    }

    // La selección ya quedó validada contra un perfil de balanza. Se guarda
    // antes de iniciar notificaciones/lecturas para que un fallo transitorio
    // posterior no borre la posibilidad de reconectar al volver a la vista.
    rememberBleScaleDevice(scaleId, device);
    connection.server = server;
    connection.characteristic = found.characteristic;
    connection.canRead = found.canRead;
    connection.parser = found.profile.parser;
    connection.profileLabel = found.profile.label;
    connection.status = "connected";
    connection.statusMessage = `Conectada BLE - ${found.profile.label}`;
    connection.valueHandler = (event) => {
      if (found.profile.parser === "sig-weight") {
        handleScalePayload(scaleId, event.target.value, {
          parser: "sig-weight",
          mode: "ble",
          deviceName,
          profileLabel: found.profile.label,
          connection
        });
        return;
      }
      handleFramedScaleTextChunk(scaleId, connection, event.target.value, {
        mode: "ble",
        deviceName,
        profileLabel: found.profile.label
      });
    };

    if (found.canNotify) {
      found.characteristic.addEventListener("characteristicvaluechanged", connection.valueHandler);
      await found.characteristic.startNotifications();
      if (!isCurrentScaleConnection(scaleId, connection)) {
        return false;
      }
      connection.notifyActive = true;
    }

    if (found.canRead) {
      const value = await found.characteristic.readValue();
      if (!isCurrentScaleConnection(scaleId, connection)) {
        return false;
      }
      if (found.profile.parser === "sig-weight") {
        handleScalePayload(scaleId, value, {
          parser: "sig-weight",
          mode: "ble",
          deviceName,
          profileLabel: found.profile.label,
          connection
        });
      } else {
        handleFramedScaleTextChunk(scaleId, connection, value, {
          mode: "ble",
          deviceName,
          profileLabel: found.profile.label
        });
      }
    }

    if (!found.canNotify && found.canRead) {
      startBleReadPolling(scaleId);
    }

    scaleRestoreAttempt = 0;
    renderScaleConnectionPanels();
    setFormMessage(
      automatic
        ? `Balanza ${scaleId} reconectada automáticamente por BLE (${found.profile.label}).`
        : `Balanza ${scaleId} conectada por BLE (${found.profile.label}).`
    );
    return true;
  } catch (error) {
    const message = getConnectionErrorMessage(error, `No se pudo conectar la balanza ${scaleId} por BLE.`);
    if (isCurrentScaleConnection(scaleId, connection)) {
      await disconnectScale(scaleId, false);
      if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
        return false;
      }
      const errorGeneration = nextScaleConnectionGeneration(scaleId);
      scaleConnections[scaleId] = {
        generation: errorGeneration,
        status: "error",
        statusMessage: message,
        abort: false
      };
      renderScaleConnectionPanels();
      if (!automatic) {
        setFormMessage(message, error?.name !== "NotFoundError");
      }
      const rememberedScale = getScaleState(scaleId);
      if (
        isScaleLifecycleCurrent(lifecycleGeneration)
        && (
          automatic
          || (
            rememberedScale.autoConnectMode === "ble"
            && Boolean(rememberedScale.bleDeviceId)
          )
        )
      ) {
        scheduleScaleConnectionRestore();
      }
    }
    return false;
  }
}

function handleSerialScaleChunk(scaleId, connection, bytes) {
  handleFramedScaleTextChunk(scaleId, connection, bytes, {
    mode: "serial",
    deviceName: connection.deviceName,
    profileLabel: "Puerto serial"
  });
}

async function readSerialScale(scaleId, connection) {
  if (!connection?.port || !isCurrentScaleConnection(scaleId, connection)) {
    return;
  }

  try {
    while (isCurrentScaleConnection(scaleId, connection) && connection.port.readable) {
      const reader = connection.port.readable.getReader();
      connection.reader = reader;

      try {
        while (isCurrentScaleConnection(scaleId, connection)) {
          const { value, done } = await reader.read();
          if (done) {
            break;
          }

          if (value) {
            handleSerialScaleChunk(scaleId, connection, value);
          }
        }
      } finally {
        try {
          reader.releaseLock();
        } catch {
          // El puerto puede retirar el lock mientras se desconecta físicamente.
        }
        if (scaleConnections[scaleId] === connection) {
          connection.reader = null;
        }
      }
    }
  } catch (error) {
    if (isCurrentScaleConnection(scaleId, connection)) {
      handleScaleDisconnected(
        scaleId,
        getConnectionErrorMessage(error, `Lectura serial interrumpida en balanza ${scaleId}.`),
        connection
      );
    }
    return;
  }

  if (isCurrentScaleConnection(scaleId, connection)) {
    handleScaleDisconnected(scaleId, `Puerto serial de balanza ${scaleId} desconectado.`, connection);
  }
}

async function connectSerialScale(scaleId, rememberedPort = null, options = {}) {
  const lifecycleGeneration = Number.isInteger(options.lifecycleGeneration)
    ? options.lifecycleGeneration
    : scaleLifecycleGeneration;
  if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
    return false;
  }
  if (!window.isSecureContext) {
    setFormMessage("Para conectar por puerto serial Bluetooth abre la web en localhost o HTTPS.", true);
    renderScaleConnectionPanels();
    return;
  }

  if (!navigator.serial) {
    setFormMessage("Este navegador no soporta Web Serial. Empareja la balanza y usa Chrome/Edge.", true);
    renderScaleConnectionPanels();
    return;
  }

  await disconnectScale(scaleId, false);
  if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
    return false;
  }
  const automatic = Boolean(rememberedPort);
  const generation = nextScaleConnectionGeneration(scaleId);
  const serialOptions = normalizeScaleSerialOptions(getScaleState(scaleId).serialOptions);
  const connection = {
    generation,
    mode: "serial",
    status: "connecting",
    statusMessage: automatic
      ? `Reconectando puerto serial de Balanza ${scaleId}...`
      : `Selecciona el puerto Bluetooth serial de Balanza ${scaleId}...`,
    deviceName: "Puerto serial Bluetooth",
    profileLabel: "Puerto serial",
    decoder: new TextDecoder("utf-8"),
    buffer: "",
    stability: null,
    abort: false
  };
  scaleConnections[scaleId] = connection;
  renderScaleConnectionPanels();

  try {
    const port = rememberedPort || await navigator.serial.requestPort();
    if (!isCurrentScaleConnection(scaleId, connection)) {
      return false;
    }
    if (otherScaleUsingSerialPort(scaleId, port)) {
      throw new Error("Ese puerto serial ya está asignado a la otra balanza. Selecciona un puerto físico diferente.");
    }

    connection.port = port;
    connection.deviceName = getSerialPortDeviceName(port);
    await port.open(serialOptions);
    if (!isCurrentScaleConnection(scaleId, connection)) {
      try {
        await port.close();
      } catch {
        // La conexión dejó de ser vigente mientras el navegador abría el puerto.
      }
      return false;
    }
    connection.status = "connected";
    connection.statusMessage = "Conectada Serial BT - esperando trama completa";
    const remembered = await rememberSerialScalePort(scaleId, port, connection, {
      rejectDuplicate: !automatic
    });
    if (!remembered || !isCurrentScaleConnection(scaleId, connection)) {
      try {
        await port.close();
      } catch {
        // La conexión fue reemplazada mientras se guardaba la preferencia.
      }
      return false;
    }
    scaleRestoreAttempt = 0;
    renderScaleConnectionPanels();
    connection.readPromise = readSerialScale(scaleId, connection);
    setFormMessage(
      automatic
        ? `Balanza ${scaleId} reconectada automáticamente por puerto serial.`
        : `Balanza ${scaleId} conectada por puerto serial Bluetooth a ${serialOptions.baudRate} baudios.`
    );
    return true;
  } catch (error) {
    const message = getConnectionErrorMessage(error, `No se pudo abrir el puerto serial de balanza ${scaleId}.`);
    if (isCurrentScaleConnection(scaleId, connection)) {
      await disconnectScale(scaleId, false);
      if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
        return false;
      }
      const errorGeneration = nextScaleConnectionGeneration(scaleId);
      scaleConnections[scaleId] = {
        generation: errorGeneration,
        status: "error",
        statusMessage: message,
        abort: false
      };
      renderScaleConnectionPanels();
      if (!automatic) {
        setFormMessage(message, error?.name !== "NotFoundError");
      } else if (isScaleLifecycleCurrent(lifecycleGeneration)) {
        scheduleScaleConnectionRestore();
      }
    }
    return false;
  }
}

function hasSerialPortIdentifiers(info) {
  const normalized = normalizeSerialPortInfo(info);
  return Boolean(
    normalized
    && (
      normalized.usbVendorId !== null
      || normalized.usbProductId !== null
      || normalized.bluetoothServiceClassId
    )
  );
}

function resolveRememberedSerialPort(savedInfo, ports = [], claimedPorts = new Set()) {
  const normalizedSavedInfo = normalizeSerialPortInfo(savedInfo);
  if (!normalizedSavedInfo) {
    return { port: null, reason: "missing-preference" };
  }

  const authorizedPorts = Array.from(ports || []);
  const hasIdentifiers = hasSerialPortIdentifiers(normalizedSavedInfo);
  const candidates = hasIdentifiers
    ? authorizedPorts.filter((port) => {
        return serialPortIdentifiersMatch(getSerialPortInfo(port), normalizedSavedInfo);
      })
    : authorizedPorts;
  if (!candidates.length) {
    return { port: null, reason: "unavailable" };
  }

  // Web Serial no expone un identificador físico serializable. Para dos
  // adaptadores RFCOMM con el mismo Service Class ID se conserva el ordinal
  // dentro de los puertos coincidentes; para puertos sin IDs se usa el ordinal
  // global. claimedPorts garantiza que un objeto físico no termine en ambos
  // puestos durante la misma restauración.
  const savedIndex = hasIdentifiers
    ? normalizedSavedInfo.matchIndex
    : normalizedSavedInfo.portIndex;
  if (!Number.isInteger(savedIndex) || savedIndex < 0) {
    return candidates.length === 1
      ? { port: candidates[0], reason: null }
      : { port: null, reason: "ambiguous" };
  }

  const preferredPort = candidates[savedIndex] || null;
  if (!preferredPort) {
    return { port: null, reason: "unavailable" };
  }
  if (preferredPort && !claimedPorts.has(preferredPort)) {
    return { port: preferredPort, reason: null };
  }
  return { port: null, reason: "claimed" };
}

function resolveRememberedBleDevice(deviceId, devices = [], claimedDeviceIds = new Set()) {
  const normalizedDeviceId = String(deviceId || "");
  if (!normalizedDeviceId) {
    return { device: null, reason: "missing-preference" };
  }
  if (claimedDeviceIds.has(normalizedDeviceId)) {
    return { device: null, reason: "claimed" };
  }

  const device = Array.from(devices || []).find((candidate) => {
    return String(candidate?.id || "") === normalizedDeviceId;
  });
  return device
    ? { device, reason: null }
    : { device: null, reason: "unavailable" };
}

function showScaleRestoreError(scaleId, message) {
  const current = scaleConnections[scaleId];
  if (current?.status === "connecting" || isScaleConnectionActive(current)) {
    return;
  }
  const generation = nextScaleConnectionGeneration(scaleId);
  scaleConnections[scaleId] = {
    generation,
    status: "error",
    statusMessage: message,
    abort: false
  };
}

function isScaleConnectionActive(connection) {
  if (connection?.status !== "connected") {
    return false;
  }

  if (connection.mode === "serial") {
    return Boolean(connection.port?.readable);
  }

  if (connection.mode === "ble") {
    return Boolean(connection.device?.gatt?.connected);
  }

  return false;
}

async function restoreScaleConnections() {
  const lifecycleGeneration = scaleLifecycleGeneration;
  if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
    return false;
  }
  if (
    scaleRestorePromise
    && scaleRestorePromiseGeneration === lifecycleGeneration
  ) {
    return scaleRestorePromise;
  }

  const restorePromise = (async () => {
    if (!window.isSecureContext) {
      return false;
    }

    const claimedSerialPorts = new Set(
      SCALE_IDS
        .map((scaleId) => scaleConnections[scaleId])
        .filter((connection) => isScaleConnectionActive(connection))
        .map((connection) => connection.port)
        .filter(Boolean)
    );

    if (canRestoreSerialScaleConnections()) {
      let ports = [];
      try {
        ports = await navigator.serial.getPorts();
      } catch {
        ports = [];
      }
      if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
        return false;
      }

      for (const scaleId of SCALE_IDS) {
        if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
          return false;
        }
        const connection = scaleConnections[scaleId];
        if (connection?.status === "connecting" || isScaleConnectionActive(connection)) {
          continue;
        }

        const scale = getScaleState(scaleId);
        if (scale.autoConnectMode !== "serial") {
          continue;
        }

        const portResolution = resolveRememberedSerialPort(
          scale.serialPortInfo,
          ports,
          claimedSerialPorts
        );
        const port = portResolution.port;
        if (!port) {
          if (portResolution.reason === "ambiguous") {
            showScaleRestoreError(
              scaleId,
              `Balanza ${scaleId}: no se pudo distinguir el puerto recordado; usa Conectar Serial una vez.`
            );
          } else if (portResolution.reason === "claimed") {
            showScaleRestoreError(
              scaleId,
              `Balanza ${scaleId}: el puerto recordado ya está asignado a la otra balanza.`
            );
          } else if (portResolution.reason === "unavailable") {
            showScaleRestoreError(
              scaleId,
              `Balanza ${scaleId}: el puerto serial recordado no está disponible; enciende o empareja la balanza.`
            );
          }
          continue;
        }

        claimedSerialPorts.add(port);
        const connected = await connectSerialScale(scaleId, port, { lifecycleGeneration });
        if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
          return false;
        }
        if (!connected) {
          claimedSerialPorts.delete(port);
        }
      }
    }

    if (canRestoreBleScaleConnections()) {
      let devices = [];
      try {
        devices = await navigator.bluetooth.getDevices();
      } catch {
        devices = [];
      }
      if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
        return false;
      }

      const claimedBleDeviceIds = new Set(
        SCALE_IDS
          .map((scaleId) => scaleConnections[scaleId])
          .filter((connection) => isScaleConnectionActive(connection) && connection.mode === "ble")
          .map((connection) => String(connection.device?.id || ""))
          .filter(Boolean)
      );

      for (const scaleId of SCALE_IDS) {
        if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
          return false;
        }
        const connection = scaleConnections[scaleId];
        if (connection?.status === "connecting" || isScaleConnectionActive(connection)) {
          continue;
        }

        const scale = getScaleState(scaleId);
        if (scale.autoConnectMode !== "ble" || !scale.bleDeviceId) {
          continue;
        }

        const deviceResolution = resolveRememberedBleDevice(
          scale.bleDeviceId,
          devices,
          claimedBleDeviceIds
        );
        if (deviceResolution.reason === "claimed") {
          showScaleRestoreError(
            scaleId,
            `Balanza ${scaleId}: el dispositivo BLE ya está asignado a la otra balanza.`
          );
          continue;
        }

        const device = deviceResolution.device;
        if (device) {
          claimedBleDeviceIds.add(scale.bleDeviceId);
          const connected = await connectBleScale(scaleId, device, { lifecycleGeneration });
          if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
            return false;
          }
          if (!connected) {
            claimedBleDeviceIds.delete(scale.bleDeviceId);
          }
        } else if (deviceResolution.reason === "unavailable") {
          showScaleRestoreError(
            scaleId,
            `Balanza ${scaleId}: el dispositivo BLE recordado no está disponible; enciende la balanza.`
          );
        }
      }
    }

    if (isScaleLifecycleCurrent(lifecycleGeneration)) {
      renderScaleConnectionPanels();
      return true;
    }
    return false;
  })();
  scaleRestorePromise = restorePromise;
  scaleRestorePromiseGeneration = lifecycleGeneration;
  const clearRestorePromise = () => {
    if (scaleRestorePromise === restorePromise) {
      scaleRestorePromise = null;
      scaleRestorePromiseGeneration = null;
    }
  };
  void restorePromise.then(clearRestorePromise, clearRestorePromise);

  return restorePromise;
}

function hasPendingRememberedScaleConnections() {
  if (!window.isSecureContext || !scalePageActive) {
    return false;
  }

  return SCALE_IDS.some((scaleId) => {
    const scale = getScaleState(scaleId);
    const connection = scaleConnections[scaleId];
    if (!scale.autoConnectMode || connection?.status === "connecting" || isScaleConnectionActive(connection)) {
      return false;
    }
    return scale.autoConnectMode === "serial"
      ? canRestoreSerialScaleConnections()
      : canRestoreBleScaleConnections();
  });
}

function scheduleScaleConnectionRestore(options = {}) {
  if (!scalePageActive) {
    return;
  }
  if (options.reset) {
    scaleRestoreAttempt = 0;
  }
  if (scaleRestoreTimer) {
    window.clearTimeout(scaleRestoreTimer);
  }

  const delay = options.immediate
    ? 0
    : Math.min(
        SCALE_AUTO_RECONNECT_DELAY_MS * (2 ** scaleRestoreAttempt),
        SCALE_AUTO_RECONNECT_MAX_DELAY_MS
      );
  const lifecycleGeneration = scaleLifecycleGeneration;
  scaleRestoreTimer = window.setTimeout(async () => {
    scaleRestoreTimer = null;
    if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
      return;
    }
    try {
      await restoreScaleConnections();
    } catch (error) {
      console.warn("No se pudieron restaurar las balanzas mayoristas.", error);
    }
    if (!isScaleLifecycleCurrent(lifecycleGeneration)) {
      return;
    }
    if (hasPendingRememberedScaleConnections()) {
      scaleRestoreAttempt += 1;
      scheduleScaleConnectionRestore();
    } else {
      scaleRestoreAttempt = 0;
    }
  }, delay);
}

function getScaleWeight(scaleId) {
  const scale = getScaleState(scaleId);
  const weight = Number(scale.currentWeight);
  return scale.readingValid && scale.readingStable && Number.isFinite(weight) && weight >= 0
    ? roundWeight(weight)
    : null;
}

function scaleTimestampAge(timestamp, now = Date.now()) {
  const timestampMs = Date.parse(String(timestamp || ""));
  return Number.isFinite(timestampMs) ? Math.max(0, now - timestampMs) : Number.POSITIVE_INFINITY;
}

function getScaleReadingEligibility(scaleId) {
  const connection = scaleConnections[scaleId];
  const scale = getScaleState(scaleId);
  const weight = getScaleWeight(scaleId);

  if (!isCurrentScaleConnection(scaleId, connection) || !isScaleConnectionActive(connection)) {
    return { ok: false, reason: `Balanza ${scaleId} sin conexión física activa.` };
  }
  if (!scale.readingValid || !scale.readingStable || !Number.isFinite(weight)) {
    return { ok: false, reason: `Balanza ${scaleId} todavía no tiene una lectura estable válida.` };
  }
  if (scale.readingStatus !== "stable") {
    return { ok: false, reason: `Balanza ${scaleId}: la entrada actual no está estable; espera una nueva lectura válida.` };
  }
  if (!scale.readingId) {
    return { ok: false, reason: `Balanza ${scaleId}: la lectura actual no tiene una identidad válida.` };
  }
  if (scale.readingId === lastRegisteredScaleReadingIds[scaleId]) {
    return { ok: false, reason: `El peso actual de Balanza ${scaleId} ya fue registrado; espera una nueva pesada.` };
  }
  if (scale.readingMode !== connection.mode || scale.readingGeneration !== connection.generation) {
    return { ok: false, reason: `La lectura de Balanza ${scaleId} no pertenece a la conexión física actual.` };
  }
  if (scaleTimestampAge(scale.updatedAt) > SCALE_READING_MAX_AGE_MS) {
    return { ok: false, reason: `La lectura de Balanza ${scaleId} venció; espera una trama nueva.` };
  }
  if (weight <= 0) {
    return { ok: false, reason: `La lectura confirmada de Balanza ${scaleId} está en cero.` };
  }

  return { ok: true, connection, scale, weight };
}

function createScaleReadingSnapshot(scaleId) {
  const eligible = getScaleReadingEligibility(scaleId);
  if (!eligible.ok) {
    return null;
  }

  return normalizeScaleReadingSnapshot({
    scaleId,
    scaleCode: `BALANZA_${scaleId}`,
    readingId: eligible.scale.readingId,
    weightKg: eligible.weight,
    rawFrame: eligible.scale.readingRaw || eligible.scale.lastRaw,
    connectionMode: eligible.connection.mode,
    deviceName: eligible.connection.deviceName || eligible.scale.readingDeviceName,
    readingAt: eligible.scale.updatedAt,
    capturedAt: eligible.scale.updatedAt,
    generation: eligible.connection.generation
  });
}

function setCapturedScaleReading(scaleId, snapshot) {
  capturedScaleReadings[scaleId] = normalizeScaleReadingSnapshot(snapshot);
}

function clearCapturedScaleReading(scaleId) {
  if (capturedScaleReadings[scaleId] !== undefined) {
    capturedScaleReadings[scaleId] = null;
  }
}

function getCapturedScaleReading(scaleId) {
  const snapshot = normalizeScaleReadingSnapshot(capturedScaleReadings[scaleId]);
  if (!snapshot?.readingId) {
    return null;
  }

  const connection = scaleConnections[scaleId];
  const validConnection = isCurrentScaleConnection(scaleId, connection)
    && isScaleConnectionActive(connection)
    && connection.generation === snapshot.generation
    && connection.mode === snapshot.connectionMode;
  if (!validConnection || scaleTimestampAge(snapshot.capturedAt) > SCALE_CAPTURE_MAX_AGE_MS) {
    clearCapturedScaleReading(scaleId);
    return null;
  }

  return snapshot;
}

function getCapturedScaleWeight(scaleId) {
  return getCapturedScaleReading(scaleId)?.weightKg ?? null;
}

function getWeightFromSource(source) {
  if (source === "manual") {
    const rawValue = String(elements.manualWeight.value ?? "").trim();
    const weight = Number(rawValue.replace(",", "."));
    return rawValue && Number.isFinite(weight) && weight >= 0 ? roundWeight(weight) : null;
  }

  const scaleId = Number(source);
  return getCapturedScaleWeight(scaleId);
}

function formatWeightBreakdownDetail(breakdown, totalBirds) {
  const javaDetail = `${breakdown.javaCount} javas x ${breakdown.crateWeightKg.toFixed(2)} kg`;

  if (breakdown.netWeightKg <= 0 && breakdown.grossWeight > 0) {
    return `No concuerda: Bruto ${breakdown.grossWeight.toFixed(2)} - Javas ${breakdown.tareWeightKg.toFixed(2)} (${javaDetail}) | Aves totales ${totalBirds}`;
  }

  return `Bruto ${breakdown.grossWeight.toFixed(2)} - Javas ${breakdown.tareWeightKg.toFixed(2)} (${javaDetail}) | Aves totales ${totalBirds}`;
}

function formatWeightMismatchMessage(breakdown) {
  return `El peso no concuerda: las javas pesan ${breakdown.tareWeightKg.toFixed(2)} kg y el bruto es ${breakdown.grossWeight.toFixed(2)} kg. Revisa la cantidad de javas, el tipo de java o la lectura de la balanza.`;
}

function buildWeightMismatchErrorOptions(breakdown) {
  return {
    title: "Peso no concuerda",
    details: [
      { label: "Peso bruto", value: formatWeight(breakdown.grossWeight) },
      { label: "Peso de javas", value: formatWeight(breakdown.tareWeightKg) },
      { label: "Neto calculado", value: formatWeight(breakdown.netWeightKg) },
      { label: "Cantidad de javas", value: String(breakdown.javaCount) },
      { label: "Peso por java", value: formatWeight(breakdown.crateWeightKg) }
    ]
  };
}

function findTruckAndCage(truckId, cageId) {
  const truck = state.trucks.find((item) => item.id === truckId);
  if (!truck) {
    return null;
  }

  const numericCageId = Number(cageId);
  const cage = truck.cages.find((item) => item.id === numericCageId);

  if (!cage) {
    return null;
  }

  return { truck, cage };
}

function normalizeErrorDetails(details) {
  if (!Array.isArray(details)) {
    return [];
  }

  return details
    .map((item) => ({
      label: String(item?.label ?? "").trim(),
      value: String(item?.value ?? "").trim()
    }))
    .filter((item) => item.label && item.value);
}

function showErrorModal(message, options = {}) {
  if (!elements.errorModal) {
    return;
  }

  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();

  const details = normalizeErrorDetails(options.details);
  elements.errorModalTitle.textContent = options.title || "Revisa los datos";
  elements.errorModalMessage.textContent = message || "Ocurrió un error.";
  elements.errorModalDetails.innerHTML = "";
  elements.errorModalDetails.hidden = !details.length;

  details.forEach((item) => {
    const wrapper = document.createElement("div");
    wrapper.className = "error-modal-detail";

    const label = document.createElement("dt");
    label.textContent = item.label;

    const value = document.createElement("dd");
    value.textContent = item.value;

    wrapper.append(label, value);
    elements.errorModalDetails.appendChild(wrapper);
  });

  elements.errorModal.hidden = false;
  queueMicrotask(() => elements.closeErrorModalBtn?.focus({ preventScroll: true }));
}

function closeErrorModal() {
  if (elements.errorModal) {
    elements.errorModal.hidden = true;
  }
}

function setFormMessage(message, isError = false, options = {}) {
  elements.formMessage.textContent = message;
  elements.formMessage.style.color = isError ? "var(--danger)" : "var(--info)";

  if (isError) {
    showErrorModal(message, options);
  }
}

function setItemFormMessage(message, isError = false, options = {}) {
  elements.itemFormMessage.textContent = message;
  elements.itemFormMessage.style.color = isError ? "var(--danger)" : "var(--info)";

  if (isError) {
    showErrorModal(message, options);
  }
}

function renderTypeButtons() {
  const activeTruck = getSelectedTruck();
  const isReturn = isReturnTicket(activeTruck);
  const options = isReturn ? RETURN_CONDITIONS : DISPATCH_CHICKEN_TYPES;
  const activeValue = isReturn
    ? normalizeReturnCondition(state.selectedReturnCondition)
    : normalizeType(state.selectedType);

  elements.form?.classList.toggle("is-return-mode", isReturn);

  elements.typeButtons.forEach((button) => {
    const option = options[Number(button.dataset.optionIndex) || Array.from(elements.typeButtons).indexOf(button)] || options[0];
    button.dataset.type = option.id;
    button.dataset.optionIndex = String(options.indexOf(option));
    button.textContent = option.label;
    button.classList.toggle("is-active", option.id === activeValue);
    button.classList.toggle("is-return-option", isReturn);
  });
}

function renderChickenSexButtons(buttons, selectedSex) {
  const normalizedSex = normalizeChickenSex(selectedSex);
  buttons.forEach((button) => {
    const buttonSex = normalizeChickenSex(button.dataset.sex || button.dataset.editSex);
    const selected = buttonSex === normalizedSex;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
}

function selectEntryChickenSex(sex) {
  state.entryDefaults = {
    ...(state.entryDefaults || {}),
    chickenSex: normalizeChickenSex(sex)
  };
  saveState();
  renderChickenSexButtons(elements.sexButtons, state.entryDefaults.chickenSex);
}

function handleEntryBirdCountInput() {
  const birdsPerJava = normalizeBirdCountPerJava(elements.birdCount.value, 0);
  if (birdsPerJava === 7 || birdsPerJava === 9) {
    state.entryDefaults = {
      ...(state.entryDefaults || {}),
      chickenSex: getSuggestedChickenSex(birdsPerJava, state.entryDefaults?.chickenSex)
    };
    renderChickenSexButtons(elements.sexButtons, state.entryDefaults.chickenSex);
  }
  renderWeightPreview();
}

function handleEditBirdCountInput() {
  const birdsPerJava = normalizeBirdCountPerJava(elements.editBirdCount.value, 0);
  if (birdsPerJava === 7 || birdsPerJava === 9) {
    editingChickenSex = getSuggestedChickenSex(birdsPerJava, editingChickenSex);
    renderChickenSexButtons(elements.editSexButtons, editingChickenSex);
  }
}

function renderEditTypeOptions(operationType = TICKET_OPERATIONS.DISPATCH) {
  const options = normalizeTicketOperation(operationType) === TICKET_OPERATIONS.RETURN
    ? RETURN_CONDITIONS
    : DISPATCH_CHICKEN_TYPES;

  elements.editType.innerHTML = options
    .map((type) => `<option value="${type.id}">${type.label}</option>`)
    .join("");
}

function renderCrateTypeOptions() {
  const options = CRATE_TYPES
    .map((crate) => `<option value="${crate.id}">${crate.label}</option>`)
    .join("");

  elements.crateType.innerHTML = options;
  elements.editCrateType.innerHTML = options;
}

function renderEntryDefaults() {
  const defaults = state.entryDefaults || {};
  const birdCountPerJava = normalizeBirdCountPerJava(defaults.birdCountPerJava, 1);
  const javaCount = normalizeJavaCount(defaults.javaCount, 1);
  const chickenSex = normalizeChickenSex(
    defaults.chickenSex,
    getSuggestedChickenSex(birdCountPerJava)
  );
  const crateTypeId = normalizeCrateTypeId(defaults.crateTypeId, DEFAULT_CRATE_TYPE_ID);
  const originId = normalizeOriginId(defaults.originId || defaults.providerId);
  const truckPlate = normalizeTruckPlate(defaults.truckPlate || defaults.plate || "");

  state.entryDefaults = {
    birdCountPerJava,
    javaCount,
    chickenSex,
    crateTypeId,
    originId,
    journeyKey: String(defaults.journeyKey || getJourneyKey()),
    truckPlate
  };
  elements.birdCount.value = String(birdCountPerJava);
  elements.javaCount.value = String(javaCount);
  elements.crateType.value = crateTypeId;
  renderChickenSexButtons(elements.sexButtons, chickenSex);
  renderEntryProviderSelection();
}

function renderEntryProviderSelection() {
  const activeTruck = getSelectedTruck();
  renderTicketOperationToggle(activeTruck);
  if (isReturnTicket(activeTruck)) {
    elements.selectedProviderName.textContent = getTruckClientName(activeTruck);
    elements.selectProviderBtn.classList.add("is-empty");
    elements.truckPlateField.hidden = true;
    elements.truckPlate.disabled = true;
    elements.truckPlate.required = false;
    elements.truckPlate.innerHTML = '<option value="">No aplica para devoluciones</option>';
    elements.truckPlate.value = "";
    elements.truckPlateHelp.textContent = "La devolución no requiere proveedor ni placa de origen.";
    elements.selectedProviderPlateLabel.textContent = "Devolución: no requiere proveedor ni placa";
    return;
  }

  const origin = getOriginById(state.entryDefaults?.originId);
  const hasOrigin = Boolean(origin);
  const currentPlate = normalizeTruckPlate(state.entryDefaults?.truckPlate || elements.truckPlate.value);

  elements.selectedProviderName.textContent = origin?.name || "Selecciona un camión de la lista";
  elements.selectProviderBtn.classList.toggle("is-empty", !hasOrigin);
  updateTruckPlateField(
    origin,
    elements.truckPlate,
    elements.truckPlateField,
    elements.truckPlateHelp,
    { currentPlate }
  );

  const selectedPlate = isWarehouseOrigin(origin)
    ? "Origen interno · No requiere placa"
    : normalizeTruckPlate(elements.truckPlate.value);
  state.entryDefaults.truckPlate = isWarehouseOrigin(origin) ? "" : selectedPlate;
  elements.selectedProviderPlateLabel.textContent = selectedPlate
    || (origin ? "Selecciona una placa de la lista" : "Proveedor y placa pendientes");
}

function renderTicketOperationToggle(truck = getSelectedTruck()) {
  if (!elements.returnTicketBtn) {
    return;
  }

  const registered = isTruckRegistered(truck);
  const isReturn = isReturnTicket(truck);
  elements.returnTicketBtn.disabled = !truck || registered;
  elements.returnTicketBtn.classList.toggle("is-dispatch-action", isReturn);
  elements.returnTicketBtn.classList.toggle("is-return-action", !isReturn);
  elements.returnTicketBtn.textContent = registered
    ? (isReturn ? "Devolución registrada" : "Despacho registrado")
    : (isReturn ? "Cambiar a despacho" : "Cambiar a devolución");
  elements.returnTicketBtn.title = registered
    ? "El tipo de un ticket registrado no puede modificarse."
    : `Convertir este ticket en ${isReturn ? "despacho" : "devolución"}.`;
}

function renderEditProviderSelection() {
  const originName = String(editSelectedOrigin?.name || "").trim();
  elements.editSelectedProviderName.textContent = originName || "Sin origen registrado";
  elements.editSelectProviderBtn.classList.toggle("is-empty", !originName);
  updateTruckPlateField(
    editSelectedOrigin,
    elements.editTruckPlate,
    elements.editTruckPlateField,
    elements.editTruckPlateHelp,
    {
      allowHistoricalPlate: Boolean(editingContext),
      currentPlate: elements.editTruckPlate.dataset.historicalPlate
        || elements.editTruckPlate.value
    }
  );
}

function updateTruckPlateField(origin, select, field, help, options = {}) {
  const warehouse = isWarehouseOrigin(origin);
  field.hidden = warehouse;
  select.required = !warehouse;

  if (warehouse) {
    select.disabled = true;
    select.innerHTML = '<option value="">No aplica para almacenes</option>';
    select.value = "";
    return;
  }

  const currentPlate = normalizeTruckPlate(options.currentPlate || select.value);
  const vehicles = getProviderVehicles(origin);
  const activePlate = getProviderVehicleByPlate(origin, currentPlate);
  const historicalPlate = options.allowHistoricalPlate && currentPlate && !activePlate;
  const optionMarkup = [];

  if (!origin) {
    select.disabled = true;
    select.innerHTML = '<option value="">Selecciona primero un proveedor</option>';
    help.textContent = "Selecciona un proveedor para consultar sus placas asignadas.";
    return;
  }

  if (vehicles.length > 1) {
    optionMarkup.push('<option value="">Selecciona una placa</option>');
  }

  vehicles.forEach((vehicle) => {
    const alias = vehicle.alias ? ` · ${vehicle.alias}` : "";
    optionMarkup.push(
      `<option value="${escapeHtml(vehicle.plate)}">${escapeHtml(vehicle.plate + alias)}</option>`
    );
  });

  if (historicalPlate) {
    optionMarkup.push(
      `<option value="${escapeHtml(currentPlate)}">${escapeHtml(`${currentPlate} · placa histórica`)}</option>`
    );
  }

  if (!optionMarkup.length) {
    select.disabled = true;
    select.innerHTML = '<option value="">Proveedor sin placas asignadas</option>';
    help.textContent = "Asigna al menos una placa activa desde el detalle del proveedor.";
    return;
  }

  select.disabled = false;
  select.innerHTML = optionMarkup.join("");

  if (activePlate || historicalPlate) {
    select.value = currentPlate;
  } else if (vehicles.length === 1) {
    select.value = vehicles[0].plate;
  } else {
    select.value = "";
  }

  help.textContent = historicalPlate
    ? "Esta pesada conserva una placa histórica; puedes mantenerla o elegir una placa activa."
    : (
      vehicles.length === 1
        ? "Placa activa asignada a este proveedor."
        : `Selecciona una de las ${vehicles.length} placas activas del proveedor.`
    );
}

function renderScaleDisplays() {
  SCALE_IDS.forEach((scaleId) => {
    const weight = getScaleWeight(scaleId);
    const hasWeight = Number.isFinite(weight);
    const value = hasWeight ? weight.toFixed(2) : "---";
    if (elements.scaleDisplays[scaleId]) {
      elements.scaleDisplays[scaleId].innerHTML = hasWeight ? `${value} <span>kg</span>` : value;
    }
    if (elements.miniScaleDisplays[scaleId]) {
      elements.miniScaleDisplays[scaleId].innerHTML = hasWeight ? `${value} <small>kg</small>` : value;
    }
    if (elements.scaleInputs[scaleId]) {
      elements.scaleInputs[scaleId].value = hasWeight ? value : "";
    }
    if (elements.scaleCaptureButtons[scaleId]) {
      const eligibility = getScaleReadingEligibility(scaleId);
      elements.scaleCaptureButtons[scaleId].disabled = !eligibility.ok;
      elements.scaleCaptureButtons[scaleId].title = eligibility.ok ? "" : eligibility.reason;
    }
  });
}

function getMobilePanelFromHash(hash = window.location.hash) {
  const normalizedHash = String(hash || "").toLowerCase();

  if (normalizedHash === "#despacho" || normalizedHash === "#camiones") {
    return "camiones";
  }

  return "registro";
}

function hasMobilePanelHash(hash = window.location.hash) {
  const normalizedHash = String(hash || "").toLowerCase();
  return ["#recepcion", "#registro", "#operacion", "#despacho", "#camiones"].includes(normalizedHash);
}

function initializeMobilePanelFromHash() {
  if (!elements.appShell) {
    return;
  }

  elements.appShell.dataset.mobilePanel = getMobilePanelFromHash();
  renderMobileTabs();
}

function isCompactLayout() {
  return window.matchMedia(COMPACT_LAYOUT_QUERY).matches;
}

function normalizeMobilePanel(panelId) {
  return MOBILE_PANEL_IDS.has(panelId) ? panelId : "registro";
}

function renderMobileTabs() {
  if (!elements.mobileTabs.length) {
    return;
  }

  const activePanel = normalizeMobilePanel(elements.appShell?.dataset.mobilePanel);
  elements.mobileTabs.forEach((button) => {
    const targetPanel = normalizeMobilePanel(button.dataset.mobilePanelTarget);
    const isActive = targetPanel === activePanel;
    button.classList.toggle("is-active", isActive);
    button.setAttribute("aria-selected", String(isActive));
  });
}

function setMobilePanel(panelId) {
  if (!elements.appShell || !isCompactLayout()) {
    return;
  }

  elements.appShell.dataset.mobilePanel = normalizeMobilePanel(panelId);
  renderMobileTabs();
}

function syncResponsiveLayout() {
  if (!elements.appShell) {
    return;
  }

  if (!isCompactLayout()) {
    elements.appShell.dataset.mobilePanel = "registro";
  } else if (hasMobilePanelHash()) {
    elements.appShell.dataset.mobilePanel = getMobilePanelFromHash();
  } else {
    elements.appShell.dataset.mobilePanel = normalizeMobilePanel(elements.appShell.dataset.mobilePanel);
  }

  renderMobileTabs();
}

function bindResponsiveLayout() {
  if (elements.mobileTabs.length) {
    elements.mobileTabs.forEach((button) => {
      button.addEventListener("click", () => {
        setMobilePanel(button.dataset.mobilePanelTarget);
      });
    });
  }

  const mediaQuery = window.matchMedia(COMPACT_LAYOUT_QUERY);
  if (typeof mediaQuery.addEventListener === "function") {
    mediaQuery.addEventListener("change", syncResponsiveLayout);
  } else {
    mediaQuery.addListener(syncResponsiveLayout);
  }

  syncResponsiveLayout();
}

function renderTruckSelect() {
  const currentValue = elements.truckSelect.value || state.trucks[0]?.id;
  elements.truckSelect.innerHTML = state.trucks
    .map((truck) => `<option value="${escapeHtml(truck.id)}">${escapeHtml(getTruckTicketLabel(truck))}</option>`)
    .join("");

  const exists = state.trucks.some((truck) => truck.id === currentValue);
  elements.truckSelect.value = exists ? currentValue : state.trucks[0]?.id;
}

function renderWeightPreview() {
  const source = elements.weightSource.value;
  const isManual = source === "manual";
  elements.manualWeightField.hidden = !isManual;

  const grossWeight = getWeightFromSource(source);
  const crateTypeId = normalizeCrateTypeId(elements.crateType.value, state.entryDefaults?.crateTypeId || DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);

  elements.crateType.value = crateMeta.id;

  if (!Number.isFinite(grossWeight) || grossWeight < 0) {
    elements.selectedWeightValue.textContent = "---";
    elements.selectedWeightBreakdown.textContent = "";
    publishCustomerDisplayState();
    return;
  }

  elements.selectedWeightValue.textContent = formatWeight(Math.max(grossWeight, 0));
  elements.selectedWeightBreakdown.textContent = "";
  publishCustomerDisplayState();
}

function renderClientList(truckId) {
  const truck = state.trucks.find((item) => item.id === truckId);
  if (!truck) {
    elements.clientList.innerHTML = "";
    return;
  }

  const isReturnMode = clientModalMode === "return";
  const query = String(elements.clientSearch?.value || "").trim().toLocaleLowerCase("es");
  const destinations = getClientCatalog().filter((client) => {
    if (isReturnMode && isWarehouseDestination(client)) {
      return false;
    }

    if (!query) {
      return true;
    }

    return [
      client.name,
      getDestinationTypeLabel(client),
      isWarehouseDestination(client) ? `almacen ${client.warehouseNumber}` : "",
      client.dni,
      client.direccion
    ]
      .join(" ")
      .toLocaleLowerCase("es")
      .includes(query);
  });
  const noneActive = !truck.clientId ? "is-active" : "";
  const options = [];

  if (!query && !isReturnMode) {
    options.push(`
      <button class="client-option ${noneActive}" type="button" data-client-id="">
        <span class="client-option-name">Sin destino asignado</span>
        <span class="client-option-detail">El ticket quedará pendiente de destino.</span>
      </button>
    `);
  }

  destinations.forEach((client) => {
    const isActive = truck.clientId === client.id ? "is-active" : "";
    const warehouse = isWarehouseDestination(client);
    const detail = [
      warehouse ? "Destino interno" : "Cliente registrado",
      client.dni ? `DNI/RUC: ${client.dni}` : "",
      client.direccion
    ].filter(Boolean).join(" · ");

    options.push(`
      <button class="client-option ${warehouse ? "is-warehouse" : ""} ${isActive}" type="button" data-client-id="${escapeHtml(client.id)}">
        <span class="client-option-heading">
          <span class="client-option-name">${escapeHtml(client.name)}</span>
          <span class="client-option-kind">${escapeHtml(getDestinationTypeLabel(client))}</span>
        </span>
        <span class="client-option-detail">${escapeHtml(detail)}</span>
      </button>
    `);
  });

  elements.clientList.innerHTML = options.length
    ? options.join("")
    : `<div class="client-empty">${isReturnMode ? "No hay clientes que coincidan con la búsqueda." : "No hay clientes o almacenes que coincidan con la búsqueda."}</div>`;
}

function getActiveProviderSelection() {
  if (providerPickerContext === "edit") {
    return editSelectedOrigin;
  }

  return getOriginById(state.entryDefaults?.originId);
}

function renderProviderList() {
  const query = String(elements.providerSearch.value || "").trim().toLocaleLowerCase("es");
  const selectedOrigin = getActiveProviderSelection();
  const origins = getOriginCatalog().filter((origin) => {
    if (!query) {
      return true;
    }

    return [
      origin.name,
      isWarehouseOrigin(origin) ? `almacen ${origin.warehouseNumber}` : "proveedor",
      origin.dni,
      origin.direccion
    ]
      .join(" ")
      .toLocaleLowerCase("es")
      .includes(query);
  });

  elements.providerModalSelection.textContent = selectedOrigin?.name
    ? `Seleccionado: ${selectedOrigin.name}`
    : "Ningún origen seleccionado.";

  if (!origins.length) {
    const message = "No hay proveedores o almacenes que coincidan con la búsqueda.";

    elements.providerList.innerHTML = `
      <div class="provider-empty">
        <p>${escapeHtml(message)}</p>
      </div>
    `;
    return;
  }

  elements.providerList.innerHTML = origins.map((origin) => {
    const isActive = selectedOrigin?.id === origin.id ? "is-active" : "";
    const warehouse = isWarehouseOrigin(origin);
    const plateCount = getProviderVehicles(origin).length;
    const details = [
      warehouse ? "Origen interno · No requiere placa" : "Proveedor registrado",
      warehouse ? "" : `${plateCount} ${plateCount === 1 ? "placa asignada" : "placas asignadas"}`,
      origin.dni ? `DNI/RUC: ${origin.dni}` : "",
      origin.direccion
    ].filter(Boolean).join(" | ");

    return `
      <button class="provider-option ${warehouse ? "is-warehouse" : ""} ${isActive}" type="button" data-origin-id="${escapeHtml(origin.id)}">
        <span class="provider-option-name">${escapeHtml(origin.name)}</span>
        <span class="provider-option-meta">${escapeHtml(details)}</span>
      </button>
    `;
  }).join("");
}

function getDailyProviderRows() {
  if (dailyJourneyPlan === null) {
    return [];
  }

  const warehouseRows = getConfiguredWarehouseOrigins().map((origin) => ({
    key: `${origin.id}:interno`,
    origin,
    plate: "",
    plateLabel: "INTERNO",
    alias: "Almacén",
    disabled: false
  }));
  const providerRows = getProviderCatalog().flatMap((origin) => {
    const vehicles = getProviderVehicles(origin);
    if (!vehicles.length) {
      return [{
        key: `${origin.id}:sin-placa`,
        origin,
        plate: "",
        plateLabel: "SIN PLACA",
        alias: "Configurar vehículo",
        disabled: true
      }];
    }

    return vehicles.map((vehicle) => ({
      key: `${origin.id}:${vehicle.plate}`,
      origin,
      plate: vehicle.plate,
      plateLabel: vehicle.plate,
      alias: vehicle.alias,
      disabled: false
    }));
  });

  return [...warehouseRows, ...providerRows];
}

function renderDailyProviderList() {
  if (!elements.dailyProviderList) {
    return;
  }

  const rows = getDailyProviderRows();
  const activeOrigin = getOriginById(state.entryDefaults?.originId);
  const activePlate = normalizeTruckPlate(
    state.entryDefaults?.truckPlate || elements.truckPlate.value
  );
  const selectableCount = rows.filter((row) => !row.disabled).length;

  if (elements.dailyProviderCount) {
    elements.dailyProviderCount.textContent = String(selectableCount);
    elements.dailyProviderCount.title = `${selectableCount} camiones u orígenes disponibles`;
  }

  if (!rows.length) {
    elements.dailyProviderList.innerHTML = `
      <div class="daily-provider-empty">
        ${isJourneyConfigured() ? "Sin orígenes disponibles." : "Configura la jornada para habilitar orígenes y pesadas."}
      </div>
    `;
    return;
  }

  elements.dailyProviderList.innerHTML = rows.map((row, index) => {
    const isActive = activeOrigin?.id === row.origin.id
      && activePlate === row.plate;
    const rowClass = [
      "daily-provider-row",
      isActive ? "is-active" : "",
      row.disabled ? "is-disabled" : ""
    ].filter(Boolean).join(" ");

    return `
      <button
        class="${rowClass}"
        type="button"
        role="option"
        aria-selected="${isActive ? "true" : "false"}"
        data-origin-id="${escapeHtml(row.origin.id)}"
        data-plate="${escapeHtml(row.plate)}"
        ${row.disabled ? "disabled" : ""}
      >
        <span class="daily-provider-index">${String(index + 1).padStart(2, "0")}</span>
        <span class="daily-provider-name">
          <strong>${escapeHtml(row.origin.name)}</strong>
        </span>
        <span class="daily-provider-plate">
          <strong>${escapeHtml(row.plateLabel)}</strong>
        </span>
      </button>
    `;
  }).join("");
}

function selectDailyProviderVehicle(originId, plate) {
  const origin = getOriginById(originId);
  if (!origin) {
    renderDailyProviderList();
    return;
  }

  const warehouse = isWarehouseOrigin(origin);
  const normalizedPlate = warehouse ? "" : normalizeTruckPlate(plate);
  if (!warehouse && !getProviderVehicleByPlate(origin, normalizedPlate)) {
    setFormMessage("La placa seleccionada ya no está activa para este proveedor.", true);
    renderDailyProviderList();
    return;
  }

  state.entryDefaults = {
    ...(state.entryDefaults || {}),
    originId: origin.id,
    journeyKey: getJourneyKey(),
    truckPlate: normalizedPlate
  };
  saveState();
  renderEntryProviderSelection();
  renderDailyProviderList();
  setFormMessage(
    warehouse
      ? `Origen seleccionado: ${origin.name}. No requiere placa.`
      : `Camión seleccionado: ${origin.name} · ${normalizedPlate}.`
  );
}

function buildTruckTableRows(truck) {
  const isReturn = isReturnTicket(truck);

  return truck.cages
    .map((cage) => {
      const typeMeta = isReturn
        ? getReturnConditionMeta(cage.chickenCondition)
        : getTypeMeta(cage.tipo);
      const sexMeta = getChickenSexMeta(cage.chickenSex);
      const typeTag = `<span class="tag ${typeMeta.tagClass}">${typeMeta.label}</span>`;
      const sexBadge = `<span class="chicken-sex-badge chicken-sex-${sexMeta.id}" title="${sexMeta.label}" aria-label="${sexMeta.label}">${sexMeta.id === "hembra" ? "H" : "M"}</span>`;
      const weightSourceText = cage.origenPeso === "manual" ? "Manual" : `B${cage.balanza}`;
      const merchandiseOrigin = isReturn
        ? "Devolución cliente"
        : String(cage.origenNombre || cage.proveedorOrigenNombre || "").trim();
      const sourceText = merchandiseOrigin
        ? `${merchandiseOrigin} · ${weightSourceText}`
        : weightSourceText;
      const javas = normalizeJavaCount(cage.cantidadJavas, 1);
      const avesPorJava = normalizeBirdCountPerJava(cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava, 0);
      const aves = avesPorJava > 0
        ? calculateBirdTotal(avesPorJava, javas)
        : (Number(cage.cantidadAves ?? cage.cantidadPollos) || 0);
      const grossWeight = Number(cage.pesoBrutoKg ?? cage.pesoKg) || 0;
      const tareWeight = Number(cage.taraTotalKg) || 0;
      const netWeight = Number(cage.pesoNetoKg ?? cage.pesoKg) || 0;

      return `
        <tr class="cage-row" data-truck-id="${escapeHtml(truck.id)}" data-cage-id="${escapeHtml(cage.id)}" tabindex="0" role="button" aria-label="Editar registro ${escapeHtml(cage.id)}">
          <td class="truck-cell-id">${escapeHtml(cage.id)}</td>
          <td class="truck-cell-type">${typeTag}</td>
          <td class="truck-cell-sex">${sexBadge}</td>
          <td class="truck-cell-count">${avesPorJava}</td>
          <td class="truck-cell-count">${aves}</td>
          <td class="truck-cell-count">${javas}</td>
          <td class="truck-cell-weight">${grossWeight.toFixed(2)}</td>
          <td class="truck-cell-weight">${tareWeight.toFixed(2)}</td>
          <td class="truck-cell-weight">${netWeight.toFixed(2)}</td>
          <td class="truck-cell-meta">${escapeHtml(sourceText)}</td>
          <td class="truck-cell-meta">${escapeHtml(cage.hora)}</td>
        </tr>
      `;
    })
    .join("");
}

function getTicketTypeCode(typeId, condition = "vivo", operationType = TICKET_OPERATIONS.DISPATCH) {
  if (normalizeTicketOperation(operationType) === TICKET_OPERATIONS.RETURN) {
    return normalizeReturnCondition(condition || typeId) === "muerto" ? "PM" : "PV";
  }

  const normalizedType = normalizeType(typeId);
  return normalizedType === "pollo_pelado" ? "PP" : "PV";
}

function buildDispatchTicketData(truck) {
  const operationType = getTruckOperationType(truck);

  return {
    code: getTruckTicketLabel(truck),
    operationType,
    destinationName: getTruckClientName(truck),
    records: truck.cages.map((cage) => ({
      typeCode: getTicketTypeCode(cage.tipo, cage.chickenCondition, operationType),
      birdsPerCage: normalizeBirdCountPerJava(
        cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava,
        0
      ),
      cages: normalizeJavaCount(cage.cantidadJavas, 1),
      grossWeight: Number(cage.pesoBrutoKg ?? cage.pesoKg) || 0,
      tareWeight: Number(cage.taraTotalKg) || 0,
      netWeight: Number(cage.pesoNetoKg ?? cage.pesoKg) || 0
    }))
  };
}

function printDispatchTicket(truckId) {
  const truck = state.trucks.find((item) => item.id === truckId);

  if (!truck) {
    setFormMessage("No se encontró el ticket seleccionado.", true);
    return;
  }

  if (!truck.cages.length) {
    setFormMessage(`${truck.name} no tiene registros para imprimir.`, true);
    return;
  }

  if (!isTruckRegistered(truck)) {
    setFormMessage("Primero registra el ticket en la base de datos.", true);
    return;
  }

  const ticketCode = getTruckTicketLabel(truck);

  printWeightControlTicket(buildDispatchTicketData(truck), {
    frameTitle: `Impresión de ${truck.name}`,
    onSuccess: () => clearRegisteredTruckColumn(
      truck.id,
      `${ticketCode} enviado a impresión. ${truck.name} está limpia y lista para otro ticket.`
    ),
    onError: () => setFormMessage("No se pudo iniciar la impresión del ticket.", true)
  });
}

function getDeliveryTrucks() {
  return Array.isArray(operationCatalog?.delivery_trucks)
    ? operationCatalog.delivery_trucks
    : [];
}

function getDeliveryDrivers() {
  return Array.isArray(operationCatalog?.delivery_drivers)
    ? operationCatalog.delivery_drivers
    : [];
}

function renderDeliveryTruckList() {
  const query = String(elements.deliveryTruckSearch?.value || "").trim().toLocaleLowerCase("es");
  const trucks = getDeliveryTrucks().filter((truck) => [
    truck.plate,
    truck.brand,
    truck.model,
    truck.color,
    truck.description
  ].filter(Boolean).join(" ").toLocaleLowerCase("es").includes(query));

  elements.deliveryTruckList.innerHTML = trucks.length
    ? trucks.map((truck) => {
        const detail = [truck.brand, truck.model, truck.color, truck.description]
          .filter(Boolean)
          .join(" · ");
        return `
          <button class="delivery-fleet-option" type="button" data-delivery-truck-id="${escapeHtml(truck.id)}">
            <span class="delivery-fleet-option-name">${escapeHtml(truck.plate || "Sin placa")}</span>
            <span class="delivery-fleet-option-detail">${escapeHtml(detail || "Camión propio")}</span>
          </button>
        `;
      }).join("")
    : `<div class="client-empty">${query
        ? "No hay camiones que coincidan con la búsqueda."
        : "No hay camiones propios activos. Registra uno en Mi flota y choferes."}</div>`;
}

function renderDeliveryDriverList() {
  const query = String(elements.deliveryDriverSearch?.value || "").trim().toLocaleLowerCase("es");
  const drivers = getDeliveryDrivers().filter((driver) => [
    driver.name,
    driver.document_type,
    driver.document_number,
    driver.phone
  ].filter(Boolean).join(" ").toLocaleLowerCase("es").includes(query));

  elements.deliveryDriverList.innerHTML = drivers.length
    ? drivers.map((driver) => {
        const document = [driver.document_type, driver.document_number].filter(Boolean).join(" ");
        const detail = [document, driver.phone].filter(Boolean).join(" · ");
        return `
          <button class="delivery-fleet-option" type="button" data-delivery-driver-id="${escapeHtml(driver.id)}">
            <span class="delivery-fleet-option-name">${escapeHtml(driver.name || "Chofer sin nombre")}</span>
            <span class="delivery-fleet-option-detail">${escapeHtml(detail || "Chofer activo")}</span>
          </button>
        `;
      }).join("")
    : `<div class="client-empty">${query
        ? "No hay choferes que coincidan con la búsqueda."
        : "No hay choferes activos. Registra uno en Mi flota y choferes."}</div>`;
}

function openDeliveryTruckModal(truck) {
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();
  deliverySelectionContext = { truckId: truck.id, vehicleId: null };
  elements.deliveryTruckSearch.value = "";
  elements.deliveryTruckTicketLabel.textContent = `${truck.name} · ${getTruckClientName(truck)}`;
  renderDeliveryTruckList();
  elements.deliveryTruckModal.hidden = false;
  window.setTimeout(() => {
    elements.deliveryTruckSearch.focus({ preventScroll: true });
    openTextTouchKeyboard(elements.deliveryTruckSearch);
  }, 0);
}

function closeDeliverySelection() {
  closeTextTouchKeyboard(true, false);
  deliverySelectionContext = null;
  elements.deliveryTruckModal.hidden = true;
  elements.deliveryDriverModal.hidden = true;
}

function selectDeliveryTruck(vehicleId) {
  const normalizedVehicleId = Number(vehicleId);
  const truck = getDeliveryTrucks().find((item) => Number(item.id) === normalizedVehicleId);
  if (!deliverySelectionContext || !truck) {
    setFormMessage("El camión seleccionado ya no está disponible.", true);
    return;
  }

  deliverySelectionContext.vehicleId = normalizedVehicleId;
  closeTextTouchKeyboard(true, false);
  elements.deliveryTruckModal.hidden = true;
  elements.deliveryDriverSearch.value = "";
  elements.deliveryDriverTicketLabel.textContent = `Camión ${truck.plate} · Selecciona el chofer`;
  renderDeliveryDriverList();
  elements.deliveryDriverModal.hidden = false;
  window.setTimeout(() => {
    elements.deliveryDriverSearch.focus({ preventScroll: true });
    openTextTouchKeyboard(elements.deliveryDriverSearch);
  }, 0);
}

function selectDeliveryDriver(driverId) {
  const normalizedDriverId = Number(driverId);
  const driver = getDeliveryDrivers().find((item) => Number(item.id) === normalizedDriverId);
  if (!deliverySelectionContext || !driver) {
    setFormMessage("El chofer seleccionado ya no está disponible.", true);
    return;
  }

  const selection = {
    truckId: deliverySelectionContext.truckId,
    vehicleId: deliverySelectionContext.vehicleId,
    driverId: normalizedDriverId
  };
  closeDeliverySelection();
  void registerDispatchTicket(selection.truckId, selection);
}

function buildDispatchTicketPayload(truck, deliverySelection = null) {
  const destination = getTruckDestination(truck);
  const operationType = getTruckOperationType(truck);
  const isReturn = operationType === TICKET_OPERATIONS.RETURN;

  if (!destination) {
    throw new Error(isReturn
      ? "Selecciona el cliente de la devolución antes de registrar el ticket."
      : "Asigna un cliente o almacén de destino antes de registrar el ticket.");
  }

  if (isReturn && isWarehouseDestination(destination)) {
    throw new Error("Las devoluciones deben registrarse contra un cliente.");
  }

  const destinationId = isWarehouseDestination(destination)
    ? Number(destination.databaseId)
    : Number(destination.id);

  if (!Number.isInteger(destinationId) || destinationId <= 0) {
    throw new Error("El destino seleccionado no está vinculado con la base de datos.");
  }

  const ticketPayload = {
    draft_id: truck.draftId,
    operation_type: operationType,
    destination: {
      type: isWarehouseDestination(destination) ? "ALMACEN" : "CLIENTE",
      id: destinationId
    },
    weighings: truck.cages.map((cage) => {
      const type = getTypeMeta(isReturn ? "pollo_vivo" : cage.tipo);
      const returnCondition = isReturn
        ? getReturnConditionMeta(cage.chickenCondition)
        : null;
      const crate = getCrateTypeMeta(cage.crateTypeId);
      const origin = getOriginById(cage.origenId);
      const warehouseOrigin = isWarehouseOrigin(origin)
        || cage.tipoOrigen === "almacen";
      const warehouseId = Number(
        origin?.databaseId
          || cage.almacenOrigenId
          || cage.origen?.almacenId
      );
      const providerId = Number(
        cage.proveedorOrigenId
          || cage.origenId
          || cage.proveedorOrigen?.id
      );
      const scaleReading = cage.origenPeso === "manual"
        ? null
        : normalizeScaleReadingSnapshot(cage.scaleReading);

      if (!isReturn && warehouseOrigin && (!Number.isInteger(warehouseId) || warehouseId <= 0)) {
        throw new Error(`El almacén de origen del registro #${cage.id} no está vinculado con la base de datos.`);
      }

      if (!isReturn && !warehouseOrigin && (!Number.isInteger(providerId) || providerId <= 0)) {
        throw new Error(`El proveedor del registro #${cage.id} no está vinculado con la base de datos.`);
      }

      const payload = {
        local_id: Number(cage.id),
        chicken_type_code: isReturn && returnCondition?.id === "muerto"
          ? "POLLO_MUERTO"
          : type.apiCode,
        chicken_condition: isReturn
          ? returnCondition.apiCode
          : "VIVO",
        chicken_sex: getChickenSexMeta(cage.chickenSex).apiCode,
        cage_type_code: crate.apiCode,
        weight_source: cage.origenPeso === "manual"
          ? "MANUAL"
          : `BALANZA_${Number(cage.balanza) === 2 ? 2 : 1}`,
        birds_per_cage: normalizeBirdCountPerJava(
          cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava,
          0
        ),
        cage_count: normalizeJavaCount(cage.cantidadJavas, 1),
        read_weight_kg: roundWeight(Number(cage.pesoLeidoKg ?? cage.pesoBrutoKg)),
        gross_weight_kg: roundWeight(Number(cage.pesoBrutoKg ?? cage.pesoKg)),
        weighed_at: cage.timestamp
      };

      if (scaleReading) {
        payload.scale_reading = {
          raw_frame: scaleReading.rawFrame || null,
          connection_mode: String(scaleReading.connectionMode || "").toUpperCase() || null,
          device_name: scaleReading.deviceName || null,
          captured_at: scaleReading.capturedAt
        };
      }

      if (!isReturn) {
        payload.origin = warehouseOrigin
          ? {
              type: "ALMACEN",
              warehouse_id: warehouseId
            }
          : {
              type: "PROVEEDOR",
              provider_id: providerId,
              provider_vehicle_id: Number(cage.proveedorVehiculoId) || null,
              vehicle_id: Number(cage.vehiculoId) || null,
              plate: normalizeTruckPlate(cage.placaCamion)
            };
      }

      return payload;
    })
  };

  if (!isReturn && !isInternalClientDestination(destination)) {
    ticketPayload.delivery = {
      vehicle_id: Number(deliverySelection?.vehicleId),
      driver_id: Number(deliverySelection?.driverId)
    };
  }

  return ticketPayload;
}

function getApiErrorPresentation(error) {
  const validationErrors = error?.data?.errors || {};
  const details = Object.entries(validationErrors)
    .flatMap(([field, messages]) => {
      const values = Array.isArray(messages) ? messages : [messages];
      return values.map((message) => ({
        label: field,
        value: String(message || "")
      }));
    })
    .filter((item) => item.value);

  return {
    message: details[0]?.value || error?.message || "No se pudo registrar el ticket.",
    details
  };
}

async function registerDispatchTicket(truckId, deliverySelection = null) {
  const truck = state.trucks.find((item) => item.id === truckId);

  if (!isJourneyConfigured()) {
    setFormMessage("Configura la jornada antes de registrar el ticket y sus pesadas.", true);
    return;
  }

  if (!truck) {
    setFormMessage("No se encontró el ticket seleccionado.", true);
    return;
  }

  if (isTruckRegistered(truck)) {
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado.`);
    return;
  }

  if (!truck.cages.length) {
    setFormMessage(`${truck.name} no tiene pesadas para registrar.`, true);
    return;
  }

  if (pendingTicketRegistrations.has(truck.id)) {
    return;
  }

  let payload;
  try {
    payload = buildDispatchTicketPayload(truck, deliverySelection);
  } catch (error) {
    setFormMessage(error.message, true);
    return;
  }

  if (requiresDelivery(truck) && !deliverySelection) {
    openDeliveryTruckModal(truck);
    return;
  }

  pendingTicketRegistrations.add(truck.id);
  renderSelectedTruckDetails();
  setFormMessage(`Registrando ${truck.name} y sus ${truck.cages.length} pesadas...`);

  try {
    const response = await apiRequest("/operacion/tickets", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    truck.registration = normalizeTicketRegistration({
      id: response.data?.id,
      code: response.data?.code,
      operation_type: response.data?.operation_type,
      status: response.data?.status,
      operating_date: response.data?.operating_date,
      registered_at: response.data?.registered_at,
      destination: response.data?.destination,
      delivery: response.data?.delivery
    });
    saveState();
    renderAll();
    setFormMessage(
      `${response.data?.code || truck.name} registrado con ${response.data?.weighing_count || truck.cages.length} pesadas.`
    );
  } catch (error) {
    const presentation = getApiErrorPresentation(error);
    setFormMessage(presentation.message, true, {
      title: "No se registró el ticket",
      details: presentation.details
    });
  } finally {
    pendingTicketRegistrations.delete(truck.id);
    renderSelectedTruckDetails();
  }
}

function buildSelectedTruckDetails(truck, totals) {
  const registration = normalizeTicketRegistration(truck.registration);
  const isRegistering = pendingTicketRegistrations.has(truck.id);
  const hasDestination = Boolean(getTruckDestination(truck));
  const isReturn = isReturnTicket(truck);
  const typeItems = totals.byType
    .map((item) => {
      const toneClass = `type-total-${item.id.replace(/_/g, "-")}`;

      return `
        <div class="selected-truck-type ${toneClass}">
          <span>${item.label}</span>
          <strong>${item.records} registros | ${item.javas} javas | ${item.birds} aves</strong>
          <small>Neto ${item.weight.toFixed(2)} kg</small>
        </div>
      `;
    })
    .join("");

  return `
    <div class="selected-truck-bar-head">
      <div>
        <span>Ticket seleccionado</span>
        <strong>${escapeHtml(getTruckTicketLabel(truck))}</strong>
        <small>${escapeHtml(`${getTicketOperationLabel(truck)} · ${getTruckClientName(truck)}`)}</small>
      </div>
    </div>

    <div class="selected-truck-summary">
      <div class="selected-truck-stat">
        <span>Registros</span>
        <strong>${totals.records}</strong>
      </div>
      <div class="selected-truck-stat">
        <span>Javas</span>
        <strong>${totals.javas}</strong>
      </div>
      <div class="selected-truck-stat">
        <span>Aves</span>
        <strong>${totals.birds}</strong>
        <small
          class="selected-truck-sex-counts"
          aria-label="${totals.maleBirds} pollos machos y ${totals.femaleBirds} pollos hembras"
          title="Machos: ${totals.maleBirds} | Hembras: ${totals.femaleBirds}"
        >M: ${totals.maleBirds} | H: ${totals.femaleBirds}</small>
      </div>
      <div class="selected-truck-stat">
        <span>Bruto kg</span>
        <strong>${totals.grossWeight.toFixed(2)}</strong>
      </div>
      <div class="selected-truck-stat">
        <span>Javas kg</span>
        <strong>${totals.tareWeight.toFixed(2)}</strong>
      </div>
      <div class="selected-truck-stat">
        <span>Neto kg</span>
        <strong>${totals.netWeight.toFixed(2)}</strong>
      </div>
    </div>

    <div class="selected-truck-types">
      ${typeItems}
    </div>

    <div class="selected-truck-actions">
      ${registration ? `
        <span class="ticket-registration-status">
          Registrado · ${escapeHtml(registration.code)}
        </span>
      ` : ""}
      <button
        class="register-ticket-btn"
        type="button"
        data-register-ticket="${escapeHtml(truck.id)}"
        ${totals.records && hasDestination && !isRegistering && !registration ? "" : "disabled"}
      >${isRegistering ? "Registrando..." : (registration ? "Ticket registrado" : (isReturn ? "Registrar devolución" : "Registrar ticket"))}</button>
      <button
        class="print-ticket-btn"
        type="button"
        data-print-ticket="${escapeHtml(truck.id)}"
        ${totals.records && registration ? "" : "disabled"}
      >Imprimir ticket</button>
    </div>
  `;
}

function renderSelectedTruckDetails() {
  if (!elements.selectedTruckDetails) {
    return;
  }

  const activeTruckId = elements.truckSelect.value || state.trucks[0]?.id;
  const truck = state.trucks.find((item) => item.id === activeTruckId);

  if (!truck) {
    elements.selectedTruckDetails.innerHTML = `
      <div class="selected-truck-empty">Selecciona un ticket para ver sus totales.</div>
    `;
    return;
  }

  const totals = calculateTruckTotals(truck.cages, getTruckOperationType(truck));
  elements.selectedTruckDetails.innerHTML = buildSelectedTruckDetails(truck, totals);
}

function renderTruckColumns() {
  const activeTruckId = elements.truckSelect.value;

  elements.trucksGrid.innerHTML = state.trucks
    .map((truck) => {
      const totals = calculateTruckTotals(truck.cages, getTruckOperationType(truck));
      const isReturn = isReturnTicket(truck);

      const tableContent = totals.cages
        ? `
          <div class="table-wrap">
            <table class="truck-table">
              <thead>
                <tr>
                  <th class="truck-head-id">#</th>
                  <th class="truck-head-type">Tipo</th>
                  <th class="truck-head-sex">Sexo</th>
                  <th class="truck-head-count">Aves/Java</th>
                  <th class="truck-head-count">Aves</th>
                  <th class="truck-head-count">Javas</th>
                  <th class="truck-head-weight">Bruto</th>
                  <th class="truck-head-weight">Javas kg</th>
                  <th class="truck-head-weight">Neto</th>
                  <th class="truck-head-meta">Origen</th>
                  <th class="truck-head-meta">Hora</th>
                </tr>
              </thead>
              <tbody>
                ${buildTruckTableRows(truck)}
              </tbody>
            </table>
          </div>
        `
        : `
          <div class="table-wrap">
            <p class="empty">Sin registros.</p>
          </div>
        `;

      const isActive = truck.id === activeTruckId ? "active" : "";
      const clientName = escapeHtml(getTruckClientName(truck));
      const registration = normalizeTicketRegistration(truck.registration);

      return `
        <article class="truck-card ${isActive} ${registration ? "is-registered" : ""} ${isReturn ? "is-return" : ""}" data-truck-id="${escapeHtml(truck.id)}">
          <header class="truck-head">
            <div class="truck-head-top">
              <h3>${escapeHtml(getTruckTicketLabel(truck))}</h3>
              ${registration
                ? `<button class="clear-column-btn" type="button" data-clear-column="${escapeHtml(truck.id)}">Limpiar columna</button>`
                : `<button class="assign-client-btn" type="button" data-assign-client="${escapeHtml(truck.id)}">${isReturn ? "Cliente" : "Destino"}</button>`}
            </div>
            <p class="truck-operation-line">${escapeHtml(getTicketOperationLabel(truck))}</p>
            <p class="truck-client-name">${clientName}</p>
            ${registration ? `<p class="truck-registration-line">Registrado en base de datos</p>` : ""}
          </header>

          <div class="truck-metrics">
            <div class="metric">
              <span>Registros</span>
              <strong>${totals.records}</strong>
            </div>
            <div class="metric">
              <span>Aves totales</span>
              <strong>${totals.birds}</strong>
            </div>
            <div class="metric">
              <span>Javas</span>
              <strong>${totals.javas}</strong>
            </div>
            <div class="metric">
              <span>Javas kg</span>
              <strong>${totals.tareWeight.toFixed(2)}</strong>
            </div>
          </div>

          ${tableContent}
        </article>
      `;
    })
    .join("");
}

function renderGlobalStats() {
  const total = state.trucks.reduce(
    (acc, truck) => {
      const truckTotals = calculateTruckTotals(truck.cages, getTruckOperationType(truck));
      acc.records += truckTotals.records;
      acc.javas += truckTotals.javas;
      acc.birds += truckTotals.birds;
      acc.grossWeight += truckTotals.grossWeight;
      acc.tareWeight += truckTotals.tareWeight;
      acc.netWeight += truckTotals.netWeight;
      return acc;
    },
    { records: 0, javas: 0, birds: 0, grossWeight: 0, tareWeight: 0, netWeight: 0 }
  );

  elements.globalStats.textContent = `Registros: ${total.records} | Javas: ${total.javas} | Javas kg: ${roundWeight(total.tareWeight).toFixed(2)} | Aves: ${total.birds} | Neto aves kg: ${roundWeight(total.netWeight).toFixed(2)}`;
}

function buildJsonPayload() {
  return {
    fechaCorte: formatPeruIsoString(),
    tiposDisponibles: DISPATCH_CHICKEN_TYPES.map((type) => ({ id: type.id, nombre: type.label })),
    tiposJavasDisponibles: CRATE_TYPES.map((crate) => ({ id: crate.id, nombre: crate.label, pesoKg: crate.weightKg })),
    clientesDisponibles: getClientCatalog().map((client) => ({
      id: client.id,
      name: client.name,
      nombre: client.name,
      tipoDestino: client.destinationType,
      numeroAlmacen: client.warehouseNumber
    })),
    destinosDisponibles: getClientCatalog().map((destination) => ({
      id: destination.id,
      nombre: destination.name,
      tipo: destination.destinationType,
      numeroAlmacen: destination.warehouseNumber
    })),
    origenesDisponibles: getOriginCatalog().map((origin) => ({
      id: origin.id,
      nombre: origin.name,
      tipo: origin.originType,
      numeroAlmacen: isWarehouseOrigin(origin) ? origin.warehouseNumber : null,
      requierePlaca: !isWarehouseOrigin(origin)
    })),
    balanzas: {
      balanza1: {
        id: 1,
        pesoActualKg: getScaleWeight(1),
        modoConexion: getScaleState(1).connectionMode,
        dispositivo: getScaleState(1).deviceName,
        ultimaTrama: getScaleState(1).lastRaw,
        ultimaLectura: getScaleState(1).updatedAt
      },
      balanza2: {
        id: 2,
        pesoActualKg: getScaleWeight(2),
        modoConexion: getScaleState(2).connectionMode,
        dispositivo: getScaleState(2).deviceName,
        ultimaTrama: getScaleState(2).lastRaw,
        ultimaLectura: getScaleState(2).updatedAt
      }
    },
    camiones: state.trucks.map((truck) => {
      const totals = calculateTruckTotals(truck.cages, getTruckOperationType(truck));
      const client = getTruckDestination(truck);
      const destination = client
        ? {
            id: client.id,
            name: client.name,
            nombre: client.name,
            tipo: client.destinationType,
            numeroAlmacen: client.warehouseNumber
          }
        : null;

      return {
        id: truck.id,
        nombre: truck.name,
        tipoOperacion: getTruckOperationType(truck),
        cliente: destination,
        destino: destination,
        tipoDestino: client?.destinationType || null,
        totalRegistros: totals.records,
        totalJavas: totals.javas,
        totalAves: totals.birds,
        totalKgBruto: totals.grossWeight,
        totalKgJavas: totals.tareWeight,
        totalKgTara: totals.tareWeight,
        totalKgNeto: totals.netWeight,
        totalJaulas: totals.cages,
        totalPollos: totals.birds,
        totalKg: totals.weight,
        totalesPorTipo: totals.byType.map((item) => ({
          tipoId: item.id,
          tipo: item.label,
          totalRegistros: item.records,
          totalJaulas: item.cages,
          totalJavas: item.javas,
          totalAves: item.birds,
          totalPollos: item.birds,
          totalKgBruto: item.grossWeight,
          totalKgJavas: item.tareWeight,
          totalKgTara: item.tareWeight,
          totalKgNeto: item.weight,
          totalKg: item.weight
        })),
        jaulas: truck.cages.map((cage) => ({
          ...cage,
          condicionPollo: cage.chickenCondition || "vivo"
        }))
      };
    })
  };
}

function renderJson() {
  elements.jsonOutput.textContent = JSON.stringify(buildJsonPayload(), null, 2);
}

function renderAll() {
  ensureDefaultOriginSelection(true);
  renderTypeButtons();
  renderScaleDisplays();
  renderScaleConnectionPanels();
  renderTruckSelect();
  renderEntryDefaults();
  renderDailyProviderList();
  renderWeightPreview();
  renderTruckColumns();
  renderSelectedTruckDetails();
  renderGlobalStats();
  renderJson();
  renderJourneyAvailability();

  if (!elements.clientModal.hidden && clientModalTruckId) {
    const truck = state.trucks.find((item) => item.id === clientModalTruckId);
    if (truck) {
      if (elements.clientModalTitle) {
        elements.clientModalTitle.textContent = clientModalMode === "return"
          ? "Cliente de devolución"
          : "Asignar destino";
      }
      elements.clientModalTruckLabel.textContent = `${truck.name} - ${getTicketOperationLabel(truck)} - ${getTruckClientName(truck)}`;
      renderClientList(clientModalTruckId);
    }
  }

  if (!elements.providerModal.hidden) {
    renderProviderList();
  }
}

function renderJourneyAvailability() {
  const configured = isJourneyConfigured();
  if (elements.addWeighingBtn) {
    elements.addWeighingBtn.disabled = !configured;
    elements.addWeighingBtn.title = configured
      ? ""
      : "Configura la jornada antes de agregar pesadas.";
  }
}

function updateScale(scaleId) {
  const raw = elements.scaleInputs[scaleId].value;
  const weight = Number(raw);

  if (!Number.isFinite(weight) || weight < 0) {
    setFormMessage(`Peso inválido en balanza ${scaleId}.`, true);
    return;
  }

  state.scales[scaleId] = {
    ...getScaleState(scaleId),
    currentWeight: roundWeight(weight),
    lastRaw: `manual:${roundWeight(weight).toFixed(2)}kg`,
    lastRawAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    readingValid: true,
    readingStable: true,
    readingStatus: "manual",
    readingRaw: `manual:${roundWeight(weight).toFixed(2)}kg`,
    readingMode: "manual",
    readingDeviceName: "",
    readingGeneration: 0,
    readingId: null,
    connectionMode: "manual",
    deviceName: ""
  };
  clearCapturedScaleReading(scaleId);
  saveState();
  renderAll();
  closeScaleSettings(scaleId);
  setFormMessage(`Balanza ${scaleId} actualizada a ${formatWeight(weight)}.`);
}

function updateEntryDefaults(showMessage = false) {
  const birdCountPerJava = normalizeBirdCountPerJava(elements.birdCount.value, state.entryDefaults?.birdCountPerJava || 1);
  const javaCount = normalizeJavaCount(elements.javaCount.value, state.entryDefaults?.javaCount || 1);
  const chickenSex = normalizeChickenSex(
    state.entryDefaults?.chickenSex,
    getSuggestedChickenSex(birdCountPerJava)
  );
  const crateTypeId = normalizeCrateTypeId(elements.crateType.value, state.entryDefaults?.crateTypeId || DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);
  const truckPlate = normalizeTruckPlate(elements.truckPlate.value || state.entryDefaults?.truckPlate);

  state.entryDefaults = {
    birdCountPerJava,
    javaCount,
    chickenSex,
    crateTypeId: crateMeta.id,
    originId: normalizeOriginId(state.entryDefaults?.originId),
    journeyKey: String(state.entryDefaults?.journeyKey || getJourneyKey()),
    truckPlate
  };

  elements.birdCount.value = String(birdCountPerJava);
  elements.javaCount.value = String(javaCount);
  elements.crateType.value = crateMeta.id;
  renderChickenSexButtons(elements.sexButtons, chickenSex);

  saveState();
  renderWeightPreview();

  if (showMessage) {
    setFormMessage(`Configuración fija: ${birdCountPerJava} aves/java, ${javaCount} javas, ${getChickenSexMeta(chickenSex).label.toLowerCase()}, java de ${crateMeta.weightKg.toFixed(2)} kg.`);
  }
}

function captureScale(scaleId) {
  const eligibility = getScaleReadingEligibility(scaleId);
  if (!eligibility.ok) {
    clearCapturedScaleReading(scaleId);
    renderWeightPreview();
    setFormMessage(eligibility.reason, true);
    return;
  }

  const snapshot = createScaleReadingSnapshot(scaleId);
  if (!snapshot) {
    setFormMessage(`No se pudo capturar una lectura válida de Balanza ${scaleId}.`, true);
    return;
  }

  setCapturedScaleReading(scaleId, snapshot);
  elements.weightSource.value = String(scaleId);
  renderWeightPreview();
  setFormMessage(`Peso de balanza ${scaleId} capturado: ${formatWeight(snapshot.weightKg)}.`);
}

function addCage(event) {
  event.preventDefault();

  if (!isJourneyConfigured()) {
    setFormMessage("Configura la jornada antes de agregar pesadas.", true);
    return;
  }

  const truckId = elements.truckSelect.value;
  const source = normalizeWeightSource(elements.weightSource.value === "manual" ? "manual" : elements.weightSource.value);
  const birdsPerJava = normalizeBirdCountPerJava(elements.birdCount.value, state.entryDefaults?.birdCountPerJava || 1);
  const javaCount = normalizeJavaCount(elements.javaCount.value, state.entryDefaults?.javaCount || 1);
  const chickenSex = normalizeChickenSex(
    state.entryDefaults?.chickenSex,
    getSuggestedChickenSex(birdsPerJava)
  );
  const totalBirds = calculateBirdTotal(birdsPerJava, javaCount);
  const crateTypeId = normalizeCrateTypeId(elements.crateType.value, state.entryDefaults?.crateTypeId || DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);
  const scaleReading = source === "manual" ? null : getCapturedScaleReading(Number(source));
  const rawWeight = source === "manual" ? getWeightFromSource(source) : scaleReading?.weightKg;
  const grossWeight = roundWeight(rawWeight);
  const breakdown = calculateWeightBreakdown(grossWeight, javaCount, crateMeta.weightKg);
  const truck = state.trucks.find((item) => item.id === truckId);
  const isReturn = isReturnTicket(truck);
  const origin = isReturn ? null : getOriginById(state.entryDefaults?.originId);
  const warehouseOrigin = isWarehouseOrigin(origin);
  const truckPlate = isReturn || warehouseOrigin ? "" : normalizeTruckPlate(elements.truckPlate.value);
  const providerVehicle = warehouseOrigin ? null : getProviderVehicleByPlate(origin, truckPlate);
  elements.truckPlate.value = truckPlate;

  if (!truckId) {
    setFormMessage("Selecciona un ticket de despacho.", true);
    return;
  }

  if (!truck) {
    setFormMessage("El ticket seleccionado no existe.", true);
    return;
  }

  if (isReturn && !getTruckDestination(truck)) {
    setFormMessage("Selecciona el cliente de la devolución antes de agregar pesadas.", true);
    return;
  }

  if (!isReturn && !origin) {
    setFormMessage("Selecciona un proveedor o almacén de origen antes de agregar la pesada.", true);
    return;
  }

  if (!isReturn && !warehouseOrigin && !getProviderVehicles(origin).length) {
    setFormMessage("El proveedor seleccionado no tiene placas activas asignadas.", true);
    return;
  }

  if (!isReturn && !warehouseOrigin && !providerVehicle) {
    setFormMessage("Selecciona una placa activa asignada al proveedor.", true);
    return;
  }

  if (!Number.isInteger(birdsPerJava) || birdsPerJava <= 0) {
    setFormMessage("Ingresa una cantidad de aves por java válida.", true);
    return;
  }

  if (!Number.isInteger(javaCount) || javaCount < 0) {
    setFormMessage("Ingresa una cantidad de javas válida.", true);
    return;
  }

  if (source !== "manual" && !scaleReading) {
    setFormMessage(
      `La captura de Balanza ${Number(source)} venció o perdió su conexión. Captura nuevamente el peso estable.`,
      true
    );
    return;
  }

  if (!Number.isFinite(grossWeight) || grossWeight <= 0) {
    setFormMessage("Ingresa o selecciona un peso bruto válido mayor a 0 kg.", true);
    return;
  }

  if (breakdown.netWeightKg <= 0) {
    setFormMessage(formatWeightMismatchMessage(breakdown), true, buildWeightMismatchErrorOptions(breakdown));
    return;
  }

  if (isTruckRegistered(truck)) {
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado y no admite nuevas pesadas.`, true);
    return;
  }

  const now = new Date();
  const returnCondition = normalizeReturnCondition(state.selectedReturnCondition);
  const record = {
    id: ++state.lastId,
    operationType: getTruckOperationType(truck),
    timestamp: formatPeruIsoString(now),
    hora: formatPeruTime(now, {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    }),
    tipo: isReturn ? "pollo_vivo" : normalizeType(state.selectedType),
    chickenCondition: isReturn ? returnCondition : "vivo",
    chickenSex,
    cantidadAvesPorJava: birdsPerJava,
    cantidadPollosPorJava: birdsPerJava,
    cantidadAves: totalBirds,
    cantidadPollos: totalBirds,
    cantidadJavas: javaCount,
    crateTypeId: crateMeta.id,
    pesoJavaKg: roundWeight(crateMeta.weightKg),
    pesoLeidoKg: breakdown.scaleWeightKg,
    pesoBrutoKg: breakdown.grossWeight,
    taraTotalKg: breakdown.tareWeightKg,
    pesoNetoKg: breakdown.netWeightKg,
    pesoKg: breakdown.netWeightKg,
    lecturaBalanzaComoNeto: false,
    origenPeso: source,
    balanza: source === "manual" ? null : Number(source),
    scaleReading,
    ...buildOriginRecord(origin),
    placaCamion: isReturn ? "" : truckPlate,
    proveedorVehiculoId: isReturn ? null : (providerVehicle?.id || null),
    vehiculoId: isReturn ? null : (providerVehicle?.vehicleId || null)
  };

  truck.cages.push(record);
  if (scaleReading?.readingId) {
    lastRegisteredScaleReadingIds[scaleReading.scaleId] = scaleReading.readingId;
  }

  state.entryDefaults = isReturn
    ? {
        ...(state.entryDefaults || {}),
        birdCountPerJava: birdsPerJava,
        javaCount,
        chickenSex,
        crateTypeId: crateMeta.id
      }
    : {
        birdCountPerJava: birdsPerJava,
        javaCount,
        chickenSex,
        crateTypeId: crateMeta.id,
        originId: origin.id,
        journeyKey: getJourneyKey(),
        truckPlate
      };

  if (source === "manual") {
    elements.manualWeight.value = "";
  } else {
    clearCapturedScaleReading(Number(source));
  }

  saveState();
  renderAll();

  const typeLabel = isReturn
    ? getReturnConditionMeta(record.chickenCondition).label
    : getTypeMeta(record.tipo).label;
  const sexLabel = getChickenSexMeta(record.chickenSex).label;
  setFormMessage(
    isReturn
      ? `Registro #${record.id} en ${truck.name}: devolución ${typeLabel}, ${sexLabel.toLowerCase()}, cliente ${getTruckClientName(truck)}, ${record.cantidadAvesPorJava} aves/java (${record.cantidadAves} aves totales), ${record.cantidadJavas} javas, neto ${record.pesoNetoKg.toFixed(2)} kg.`
      : `Registro #${record.id} en ${truck.name}: ${typeLabel}, ${sexLabel.toLowerCase()}, origen ${origin.name}, ${warehouseOrigin ? "sin placa (origen interno)" : `placa ${truckPlate}`}, ${record.cantidadAvesPorJava} aves/java (${record.cantidadAves} aves totales), ${record.cantidadJavas} javas, neto ${record.pesoNetoKg.toFixed(2)} kg.`
  );
}

function copyJson() {
  const payload = elements.jsonOutput.textContent;

  if (!navigator.clipboard) {
    setFormMessage("No se pudo copiar automáticamente. Copia el JSON manualmente.", true);
    return;
  }

  navigator.clipboard
    .writeText(payload)
    .then(() => setFormMessage("JSON copiado al portapapeles."))
    .catch(() => setFormMessage("No se pudo copiar el JSON.", true));
}

function openJsonModal() {
  closeConfigMenu();
  closeAllScaleSettings();
  closeFontSidebar();
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();
  renderJson();
  elements.jsonModal.hidden = false;
}

function closeJsonModal() {
  elements.jsonModal.hidden = true;
}

function toggleTicketOperation() {
  const truck = getSelectedTruck();

  if (!truck) {
    setFormMessage("Selecciona una columna para cambiar el tipo de ticket.", true);
    return;
  }

  if (isTruckRegistered(truck)) {
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado y no puede cambiar de tipo.`, true);
    return;
  }

  const convertingToDispatch = isReturnTicket(truck);
  const currentTypeLabel = convertingToDispatch ? "devolución" : "despacho";
  const nextTypeLabel = convertingToDispatch ? "despacho" : "devolución";

  if (truck.cages.length) {
    const confirmed = window.confirm(`Esta columna tiene registros de ${currentTypeLabel} sin guardar. Para convertirla en ${nextTypeLabel} se limpiarán esos registros. ¿Continuar?`);
    if (!confirmed) {
      return;
    }
  }

  truck.cages = [];
  truck.operationType = convertingToDispatch
    ? TICKET_OPERATIONS.DISPATCH
    : TICKET_OPERATIONS.RETURN;
  truck.clientId = null;
  truck.destination = null;

  if (convertingToDispatch) {
    state.entryDefaults = {
      ...(state.entryDefaults || {}),
      originId: null,
      journeyKey: getJourneyKey(),
      truckPlate: ""
    };
    elements.truckPlate.value = "";
  }

  saveState();
  renderAll();

  if (convertingToDispatch) {
    closeClientModal();
    setFormMessage(`${truck.name} ahora es un ticket de despacho. Selecciona un origen antes de agregar una pesada.`);
    return;
  }

  openClientModal(truck.id, "return");
}

function openClientModal(truckId, mode = "destination") {
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();

  const truck = state.trucks.find((item) => item.id === truckId);
  if (!truck) {
    setFormMessage("No se encontró el ticket para asignar cliente.", true);
    return;
  }

  if (isTruckRegistered(truck)) {
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado y su destino no puede modificarse.`, true);
    return;
  }

  clientModalMode = mode === "return" ? "return" : "destination";
  if (clientModalMode === "return") {
    truck.operationType = TICKET_OPERATIONS.RETURN;
  }

  clientModalTruckId = truck.id;
  if (elements.clientSearch) {
    elements.clientSearch.value = "";
  }
  if (elements.clientModalTitle) {
    elements.clientModalTitle.textContent = clientModalMode === "return"
      ? "Cliente de devolución"
      : "Asignar destino";
  }
  elements.clientModalTruckLabel.textContent = `${truck.name} - ${getTicketOperationLabel(truck)} - ${getTruckClientName(truck)}`;
  renderClientList(truck.id);
  elements.clientModal.hidden = false;
  window.setTimeout(() => {
    elements.clientSearch?.focus({ preventScroll: true });
    openTextTouchKeyboard(elements.clientSearch);
  }, 0);
}

function closeClientModal() {
  closeTextTouchKeyboard(true, false);
  clientModalTruckId = null;
  clientModalMode = "destination";
  elements.clientModal.hidden = true;
}

function assignClientToTruck(clientId) {
  if (!clientModalTruckId) {
    return;
  }

  const truck = state.trucks.find((item) => item.id === clientModalTruckId);
  if (!truck) {
    closeClientModal();
    setFormMessage("No se encontró el ticket seleccionado.", true);
    return;
  }

  if (isTruckRegistered(truck)) {
    closeClientModal();
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado y su destino no puede modificarse.`, true);
    return;
  }

  const currentModalMode = clientModalMode;
  const normalizedClientId = normalizeClientId(clientId);
  if (currentModalMode === "return" && !normalizedClientId) {
    setFormMessage("Selecciona un cliente para la devolución.", true);
    return;
  }

  if (currentModalMode === "return") {
    const destination = getClientById(normalizedClientId);
    if (!destination || isWarehouseDestination(destination)) {
      setFormMessage("Las devoluciones deben registrarse contra un cliente.", true);
      return;
    }
    truck.operationType = TICKET_OPERATIONS.RETURN;
  }

  truck.clientId = normalizedClientId;
  truck.destination = normalizedClientId
    ? snapshotDestination(getClientById(normalizedClientId))
    : null;

  saveState();
  renderAll();
  closeClientModal();

  if (!normalizedClientId) {
    setFormMessage(`Destino removido de ${truck.name}.`);
    return;
  }

  const client = getTruckDestination(truck);
  const destinationLabel = currentModalMode === "return"
    ? "Cliente de devolución"
    : (isWarehouseDestination(client) ? "Almacén asignado" : "Cliente asignado");
  setFormMessage(`${destinationLabel} a ${truck.name}: ${client ? client.name : normalizedClientId}.`);
}

function openProviderModal(context = "entry") {
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();
  providerPickerContext = context === "edit" ? "edit" : "entry";

  if (providerPickerContext === "entry" && !isJourneyConfigured()) {
    providerPickerContext = null;
    setFormMessage("Configura la jornada antes de seleccionar un origen.", true);
    return;
  }

  if (providerPickerContext === "edit" && !editingContext) {
    providerPickerContext = null;
    setFormMessage("No hay un registro abierto para cambiar el origen.", true);
    return;
  }

  elements.providerModalTitle.textContent = providerPickerContext === "edit"
    ? "Cambiar origen"
    : "Seleccionar origen";
  elements.providerSearch.value = "";
  renderProviderList();
  elements.providerModal.hidden = false;
  window.setTimeout(() => {
    elements.providerSearch.focus({ preventScroll: true });
    openTextTouchKeyboard(elements.providerSearch);
  }, 0);
}

function closeProviderModal() {
  closeTextTouchKeyboard(true, false);
  providerPickerContext = null;
  elements.providerModal.hidden = true;
  elements.providerSearch.value = "";
}

function selectOrigin(originId) {
  const origin = getOriginById(originId);
  if (!origin) {
    renderProviderList();
    return;
  }

  if (providerPickerContext === "edit") {
    if (String(editSelectedOrigin?.id || "") !== origin.id) {
      elements.editTruckPlate.value = "";
      delete elements.editTruckPlate.dataset.historicalPlate;
    }
    editSelectedOrigin = { ...origin };
    renderEditProviderSelection();
    closeProviderModal();
    setItemFormMessage(`Origen seleccionado: ${origin.name}.${isWarehouseOrigin(origin) ? " No requiere placa." : ""}`);
    return;
  }

  if (String(state.entryDefaults?.originId || "") !== origin.id) {
    elements.truckPlate.value = "";
  }
  state.entryDefaults = {
    ...(state.entryDefaults || {}),
    originId: origin.id,
    journeyKey: getJourneyKey(),
    truckPlate: ""
  };
  saveState();
  renderEntryProviderSelection();
  renderDailyProviderList();
  closeProviderModal();
  setFormMessage(`Origen seleccionado: ${origin.name}.${isWarehouseOrigin(origin) ? " No requiere placa." : ""}`);
}

function openCageModal(truckId, cageId) {
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();

  const found = findTruckAndCage(truckId, cageId);

  if (!found) {
    setFormMessage("No se encontró el registro seleccionado.", true);
    return;
  }

  const { truck, cage } = found;
  if (isTruckRegistered(truck)) {
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado. Sus pesadas son de solo lectura.`, true);
    return;
  }

  editingContext = { truckId: truck.id, cageId: cage.id };

  elements.itemCageNumber.textContent = `#${cage.id}`;
  elements.itemTruckName.textContent = truck.name;
  elements.itemHour.textContent = cage.hora || "--:--:--";

  renderEditTypeOptions(getTruckOperationType(truck));
  elements.editType.value = isReturnTicket(truck)
    ? normalizeReturnCondition(cage.chickenCondition)
    : normalizeType(cage.tipo);
  const editJavaCount = normalizeJavaCount(cage.cantidadJavas, 1);
  const editBirdsPerJava = normalizeBirdCountPerJava(
    cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava,
    normalizeBirdCountPerJava(Math.round((Number(cage.cantidadAves ?? cage.cantidadPollos) || 0) / Math.max(editJavaCount, 1)), 0)
  );

  elements.editBirdCount.value = editBirdsPerJava || "";
  elements.editJavaCount.value = editJavaCount;
  editingChickenSex = normalizeChickenSex(
    cage.chickenSex || cage.sexo,
    getSuggestedChickenSex(editBirdsPerJava)
  );
  renderChickenSexButtons(elements.editSexButtons, editingChickenSex);
  elements.editCrateType.value = normalizeCrateTypeId(cage.crateTypeId || getCrateTypeIdByWeight(cage.pesoJavaKg) || DEFAULT_CRATE_TYPE_ID);
  elements.editWeight.value = Number(cage.pesoBrutoKg ?? cage.pesoKg).toFixed(2);
  elements.editWeightSource.value = cage.origenPeso === "manual"
    ? "manual"
    : normalizeWeightSource(cage.balanza || cage.origenPeso || "1");
  const recordOriginName = String(
    cage.origenNombre
      || cage.origen?.nombre
      || cage.origen?.name
      || cage.proveedorOrigenNombre
      || cage.proveedorOrigen?.nombre
      || cage.proveedorOrigen?.name
      || ""
  ).trim();
  const recordOriginId = String(
    cage.origenId || cage.origen?.id || cage.proveedorOrigenId || cage.proveedorOrigen?.id || ""
  ).trim() || null;
  const catalogOrigin = getOriginById(recordOriginId);
  editSelectedOrigin = recordOriginName
    ? {
        ...(catalogOrigin || {}),
        id: recordOriginId,
        name: recordOriginName,
        originType: cage.tipoOrigen || cage.origen?.tipo || catalogOrigin?.originType || "proveedor",
        warehouseNumber: cage.numeroAlmacenOrigen || cage.origen?.numeroAlmacen || catalogOrigin?.warehouseNumber || null
      }
    : null;
  elements.editTruckPlate.innerHTML = "";
  elements.editTruckPlate.dataset.historicalPlate = normalizeTruckPlate(cage.placaCamion || "");
  renderEditProviderSelection();

  setItemFormMessage("");
  elements.itemModal.hidden = false;
}

function closeItemModal() {
  closeTextTouchKeyboard(true, false);
  editingContext = null;
  editSelectedOrigin = null;
  editingChickenSex = DEFAULT_CHICKEN_SEX;
  delete elements.editTruckPlate.dataset.historicalPlate;
  if (!elements.providerModal.hidden && providerPickerContext === "edit") {
    closeProviderModal();
  }
  elements.itemModal.hidden = true;
  setItemFormMessage("");
}

function saveCageChanges(event) {
  event.preventDefault();

  if (!editingContext) {
    setItemFormMessage("No hay un registro seleccionado.", true);
    return;
  }

  const found = findTruckAndCage(editingContext.truckId, editingContext.cageId);

  if (!found) {
    closeItemModal();
    setFormMessage("El registro ya no existe en la lista.", true);
    return;
  }

  const { truck, cage } = found;
  if (isTruckRegistered(truck)) {
    closeItemModal();
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado. Sus pesadas no pueden modificarse.`, true);
    return;
  }

  const isReturn = isReturnTicket(truck);
  const type = isReturn ? "pollo_vivo" : normalizeType(elements.editType.value);
  const returnCondition = isReturn ? normalizeReturnCondition(elements.editType.value) : "vivo";
  const birdsPerJava = normalizeBirdCountPerJava(elements.editBirdCount.value, 0);
  const javaCount = normalizeJavaCount(elements.editJavaCount.value, 1);
  const chickenSex = normalizeChickenSex(
    editingChickenSex,
    getSuggestedChickenSex(birdsPerJava)
  );
  const totalBirds = calculateBirdTotal(birdsPerJava, javaCount);
  const crateTypeId = normalizeCrateTypeId(elements.editCrateType.value, DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);
  const grossWeight = roundWeight(Number(elements.editWeight.value));
  const breakdown = calculateWeightBreakdown(grossWeight, javaCount, crateMeta.weightKg);
  const source = elements.editWeightSource.value;
  const existingScaleReading = normalizeScaleReadingSnapshot(cage.scaleReading);
  const originId = isReturn ? null : (String(editSelectedOrigin?.id || "").trim() || null);
  const originName = isReturn ? "Devolución cliente" : String(editSelectedOrigin?.name || "").trim();
  const warehouseOrigin = !isReturn && isWarehouseOrigin(editSelectedOrigin);
  const truckPlate = isReturn || warehouseOrigin ? "" : normalizeTruckPlate(elements.editTruckPlate.value);
  const providerVehicle = warehouseOrigin
    ? null
    : getProviderVehicleByPlate(editSelectedOrigin, truckPlate);
  const existingOriginId = String(
    cage.origenId || cage.origen?.id || cage.proveedorOrigenId || cage.proveedorOrigen?.id || ""
  ).trim();
  const existingPlate = normalizeTruckPlate(cage.placaCamion || "");
  const historicalPlateUnchanged = !warehouseOrigin
    && originId === existingOriginId
    && truckPlate === existingPlate
    && isValidTruckPlate(truckPlate);
  elements.editTruckPlate.value = truckPlate;

  if (!isReturn && !VALID_TYPES.has(type)) {
    setItemFormMessage("Selecciona un tipo de pollo válido.", true);
    return;
  }

  if (isReturn && !VALID_RETURN_CONDITIONS.has(returnCondition)) {
    setItemFormMessage("Selecciona una condición de devolución válida.", true);
    return;
  }

  if (!Number.isInteger(birdsPerJava) || birdsPerJava <= 0) {
    setItemFormMessage("Ingresa una cantidad válida de aves por java.", true);
    return;
  }

  if (!Number.isInteger(javaCount) || javaCount < 0) {
    setItemFormMessage("Ingresa una cantidad válida de javas.", true);
    return;
  }

  if (!VALID_CRATE_TYPE_IDS.has(crateMeta.id)) {
    setItemFormMessage("Tipo de java inválido.", true);
    return;
  }

  if (!Number.isFinite(grossWeight) || grossWeight <= 0) {
    setItemFormMessage("Ingresa un peso bruto válido mayor a 0 kg.", true);
    return;
  }

  if (
    source !== "manual"
    && (
      !existingScaleReading
      || existingScaleReading.scaleId !== Number(source)
      || Math.abs(existingScaleReading.weightKg - grossWeight) > 0.001
    )
  ) {
    setItemFormMessage(
      "Un peso físico editado necesita una captura auditable de esa balanza. Conserva el peso original o elimina el registro y vuelve a capturarlo.",
      true
    );
    return;
  }

  if (breakdown.netWeightKg <= 0) {
    setItemFormMessage(formatWeightMismatchMessage(breakdown), true, buildWeightMismatchErrorOptions(breakdown));
    return;
  }

  if (!VALID_WEIGHT_SOURCES.has(source)) {
    setItemFormMessage("Origen de peso inválido.", true);
    return;
  }

  if (!isReturn && !originName) {
    setItemFormMessage("Selecciona el proveedor o almacén de origen del registro.", true);
    return;
  }

  if (!isReturn && !warehouseOrigin && !providerVehicle && !historicalPlateUnchanged) {
    setItemFormMessage("Selecciona una placa activa asignada al proveedor.", true);
    return;
  }

  cage.operationType = getTruckOperationType(truck);
  cage.tipo = type;
  cage.chickenCondition = returnCondition;
  cage.chickenSex = chickenSex;
  cage.cantidadAvesPorJava = birdsPerJava;
  cage.cantidadPollosPorJava = birdsPerJava;
  cage.cantidadAves = totalBirds;
  cage.cantidadPollos = totalBirds;
  cage.cantidadJavas = javaCount;
  cage.crateTypeId = crateMeta.id;
  cage.pesoJavaKg = roundWeight(crateMeta.weightKg);
  cage.pesoBrutoKg = breakdown.grossWeight;
  cage.taraTotalKg = breakdown.tareWeightKg;
  cage.pesoNetoKg = breakdown.netWeightKg;
  cage.pesoKg = breakdown.netWeightKg;
  cage.pesoLeidoKg = breakdown.scaleWeightKg;
  cage.lecturaBalanzaComoNeto = false;
  cage.origenPeso = source;
  cage.balanza = source === "manual" ? null : Number(source);
  cage.scaleReading = source === "manual" ? null : existingScaleReading;
  Object.assign(cage, isReturn
    ? buildOriginRecord(null)
    : buildOriginRecord({
        ...editSelectedOrigin,
        id: originId,
        name: originName
      }));
  cage.placaCamion = truckPlate;
  cage.proveedorVehiculoId = isReturn ? null : (providerVehicle?.id
    || (historicalPlateUnchanged ? cage.proveedorVehiculoId : null)
    || null);
  cage.vehiculoId = isReturn ? null : (providerVehicle?.vehicleId
    || (historicalPlateUnchanged ? cage.vehiculoId : null)
    || null);

  saveState();
  renderAll();
  closeItemModal();

  const sexLabel = getChickenSexMeta(cage.chickenSex).label.toLowerCase();
  setFormMessage(isReturn
    ? `Registro #${cage.id} actualizado en ${truck.name}. Devolución ${getReturnConditionMeta(cage.chickenCondition).label}, ${sexLabel}, ${cage.cantidadAvesPorJava} aves/java (${cage.cantidadAves} aves totales), neto ${cage.pesoNetoKg.toFixed(2)} kg.`
    : `Registro #${cage.id} actualizado en ${truck.name}. ${sexLabel}, origen ${originName}, ${warehouseOrigin ? "sin placa (origen interno)" : `placa ${truckPlate}`}, ${cage.cantidadAvesPorJava} aves/java (${cage.cantidadAves} aves totales), neto ${cage.pesoNetoKg.toFixed(2)} kg.`);
}

function releaseConsumedScaleReadingIdIfUnused(removedScaleReading) {
  const scaleId = Number(removedScaleReading?.scaleId);
  if (!SCALE_IDS.includes(scaleId)) {
    return;
  }

  const remainingScaleReadings = state.trucks.flatMap((truck) => (
    Array.isArray(truck.cages)
      ? truck.cages.map((item) => item.scaleReading).filter(Boolean)
      : []
  ));

  if (canReleaseConsumedScaleReadingId(
    lastRegisteredScaleReadingIds[scaleId],
    removedScaleReading,
    remainingScaleReadings
  )) {
    lastRegisteredScaleReadingIds[scaleId] = null;
  }
}

function deleteCageRecord() {
  if (!editingContext) {
    setItemFormMessage("No hay un registro seleccionado.", true);
    return;
  }

  const found = findTruckAndCage(editingContext.truckId, editingContext.cageId);

  if (!found) {
    closeItemModal();
    setFormMessage("El registro ya no existe en la lista.", true);
    return;
  }

  const { truck, cage } = found;
  if (isTruckRegistered(truck)) {
    closeItemModal();
    setFormMessage(`${getTruckTicketLabel(truck)} ya está registrado. Sus pesadas no pueden eliminarse.`, true);
    return;
  }

  const confirmed = window.confirm(`Se eliminará el registro #${cage.id} de ${truck.name}. ¿Continuar?`);
  if (!confirmed) {
    return;
  }

  truck.cages = truck.cages.filter((item) => item.id !== cage.id);
  releaseConsumedScaleReadingIdIfUnused(cage.scaleReading);

  saveState();
  renderAll();
  closeItemModal();

  setFormMessage(`Registro #${cage.id} eliminado de ${truck.name}.`);
}

function clearRegisteredTruckColumn(truckId, successMessage = "") {
  const truck = state.trucks.find((item) => item.id === truckId);

  if (!truck) {
    setFormMessage("No se encontró la columna seleccionada.", true);
    return;
  }

  const registration = normalizeTicketRegistration(truck.registration);
  if (!registration) {
    setFormMessage(`${truck.name} todavía no tiene un ticket guardado para limpiar.`, true);
    return;
  }

  if (pendingTicketRegistrations.has(truck.id)) {
    setFormMessage(`${truck.name} todavía se está registrando.`, true);
    return;
  }

  const previousCode = registration.code;
  resetTruckColumn(truck);
  saveState();
  renderAll();
  closeItemModal();

  if (clientModalTruckId === truck.id) {
    closeClientModal();
  }

  setFormMessage(
    successMessage
      || `${previousCode} ya quedó guardado. ${truck.name} está limpia y lista para otro ticket.`
  );
}

function handleCageRowActivation(row) {
  const truckId = row.dataset.truckId;
  const cageId = row.dataset.cageId;

  if (!truckId || !cageId) {
    return;
  }

  elements.truckSelect.value = truckId;
  renderTypeButtons();
  renderEntryProviderSelection();
  renderTruckColumns();
  renderSelectedTruckDetails();
  openCageModal(truckId, cageId);
}

async function resetDay() {
  const confirmed = window.confirm("Se borrarán todos los tickets de despacho y registros guardados. ¿Continuar?");
  if (!confirmed) {
    return;
  }

  const scaleConnectionPreferences = snapshotScaleConnectionPreferences(state.scales);
  const hasRememberedScaleConnection = SCALE_IDS.some((scaleId) => {
    return Boolean(scaleConnectionPreferences[scaleId]?.autoConnectMode);
  });
  await Promise.all(
    SCALE_IDS.map((scaleId) => disconnectScale(scaleId, false, false))
  );
  state = createDefaultState();
  applyScaleConnectionPreferences(state, scaleConnectionPreferences);
  scaleReadingSequences = { 1: 0, 2: 0 };
  lastRegisteredScaleReadingIds = { 1: null, 2: null };
  elements.truckPlate.value = "";
  saveState();
  renderAll();
  closeItemModal();
  closeClientModal();
  closeProviderModal();
  closeErrorModal();
  closeTextTouchKeyboard(true, false);
  closeNumericPad();
  closeTouchSelect();
  closeConfigMenu();
  closeAllScaleSettings();
  closeFontSidebar();
  setFormMessage(
    hasRememberedScaleConnection
      ? "Jornada reiniciada. Restaurando las balanzas recordadas..."
      : "Jornada reiniciada."
  );
  if (hasRememberedScaleConnection) {
    scheduleScaleConnectionRestore({ reset: true, immediate: true });
  }
}

function isInstalledDesktopApplication() {
  const installedDisplayMode = ["standalone", "window-controls-overlay", "tabbed"]
    .some((mode) => window.matchMedia(`(display-mode: ${mode})`).matches);
  const isMobileDevice = navigator.userAgentData?.mobile === true
    || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || "");

  return installedDisplayMode && !isMobileDevice;
}

function openCustomerDisplay(event) {
  event.preventDefault();
  const displayHref = elements.openCustomerDisplayBtn?.href;
  if (!displayHref) {
    return;
  }

  const displayUrl = new URL(displayHref, window.location.href);
  displayUrl.searchParams.set("source", CUSTOMER_DISPLAY_PRODUCER_ID);
  const windowName = `pantalla-cliente-${CUSTOMER_DISPLAY_PRODUCER_ID}`;
  const windowFeatures = isInstalledDesktopApplication()
    ? "popup=yes,width=1280,height=800,resizable=yes,scrollbars=no"
    : "";

  const displayWindow = window.open(
    displayUrl.toString(),
    windowName,
    windowFeatures
  );

  if (displayWindow) {
    displayWindow.focus();
  } else {
    setFormMessage("La aplicación bloqueó la nueva ventana. Habilita las ventanas emergentes e inténtalo nuevamente.", true);
  }
}

function bindEvents() {
  elements.openCustomerDisplayBtn?.addEventListener("click", openCustomerDisplay);
  elements.backToMenuBtn?.addEventListener("click", () => {
    closeTextTouchKeyboard(true, false);
    closeNumericPad();
    closeTouchSelect();
    closeConfigMenu();
    closeAllScaleSettings();
    closeFontSidebar();
  });

  window.addEventListener("hashchange", initializeMobilePanelFromHash);
  window.addEventListener("popstate", initializeMobilePanelFromHash);
  window.addEventListener("storage", (event) => {
    if (event.key && event.key !== PEOPLE_STORAGE_KEY) {
      return;
    }

    refreshClientsFromDirectory();
  });
  window.addEventListener("pagehide", () => {
    releaseScaleConnectionsForNavigation({ deactivate: true });
  });
  window.addEventListener("pageshow", () => {
    resumeScaleConnectionsAfterNavigation();
    void loadCurrentDirectoryData().finally(() => {
      renderAll();
      scheduleScaleConnectionRestore({ immediate: true });
    });
  });
  window.addEventListener("focus", () => {
    void loadCurrentDirectoryData().finally(() => {
      renderAll();
      scheduleScaleConnectionRestore({ immediate: true });
    });
  });
  navigator.serial?.addEventListener?.("connect", () => {
    scheduleScaleConnectionRestore({ reset: true, immediate: true });
  });
  navigator.bluetooth?.addEventListener?.("availabilitychanged", () => {
    scheduleScaleConnectionRestore({ reset: true, immediate: true });
  });

  elements.typeButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const activeTruck = getSelectedTruck();
      if (isReturnTicket(activeTruck)) {
        state.selectedReturnCondition = normalizeReturnCondition(button.dataset.type);
      } else {
        state.selectedType = normalizeType(button.dataset.type);
      }
      saveState();
      renderTypeButtons();
    });
  });
  elements.sexButtons.forEach((button) => {
    button.addEventListener("click", () => selectEntryChickenSex(button.dataset.sex));
  });
  elements.editSexButtons.forEach((button) => {
    button.addEventListener("click", () => {
      editingChickenSex = normalizeChickenSex(button.dataset.editSex);
      renderChickenSexButtons(elements.editSexButtons, editingChickenSex);
    });
  });

  elements.scaleCaptureButtons[1].addEventListener("click", () => captureScale(1));
  elements.scaleCaptureButtons[2].addEventListener("click", () => captureScale(2));
  elements.scaleSetButtons[1].addEventListener("click", () => updateScale(1));
  elements.scaleSetButtons[2].addEventListener("click", () => updateScale(2));
  elements.scaleSettingsOpenButtons[1]?.addEventListener("click", () => openScaleSettings(1));
  elements.scaleSettingsOpenButtons[2]?.addEventListener("click", () => openScaleSettings(2));
  elements.scaleSettingsCloseButtons[1]?.addEventListener("click", () => closeScaleSettings(1));
  elements.scaleSettingsCloseButtons[2]?.addEventListener("click", () => closeScaleSettings(2));
  elements.scaleConnectBleButtons[1]?.addEventListener("click", () => connectBleScale(1));
  elements.scaleConnectBleButtons[2]?.addEventListener("click", () => connectBleScale(2));
  elements.scaleConnectSerialButtons[1]?.addEventListener("click", () => connectSerialScale(1));
  elements.scaleConnectSerialButtons[2]?.addEventListener("click", () => connectSerialScale(2));
  elements.scaleDisconnectButtons[1]?.addEventListener("click", () => disconnectScale(1, true, true));
  elements.scaleDisconnectButtons[2]?.addEventListener("click", () => disconnectScale(2, true, true));
  elements.scaleSerialSaveButtons[1]?.addEventListener("click", () => saveScaleSerialOptions(1));
  elements.scaleSerialSaveButtons[2]?.addEventListener("click", () => saveScaleSerialOptions(2));

  elements.weightSource.addEventListener("change", renderWeightPreview);
  elements.selectProviderBtn.addEventListener("click", () => openProviderModal("entry"));
  elements.editSelectProviderBtn.addEventListener("click", () => openProviderModal("edit"));
  elements.truckPlate.addEventListener("change", () => {
    state.entryDefaults = {
      ...(state.entryDefaults || {}),
      truckPlate: normalizeTruckPlate(elements.truckPlate.value)
    };
    saveState();
    renderEntryProviderSelection();
    renderDailyProviderList();
  });
  elements.dailyProviderList?.addEventListener("click", (event) => {
    const row = event.target.closest(".daily-provider-row");
    if (!row || row.disabled) {
      return;
    }

    selectDailyProviderVehicle(row.dataset.originId, row.dataset.plate);
  });
  elements.truckSelect.addEventListener("change", () => {
    renderTypeButtons();
    renderEntryProviderSelection();
    renderWeightPreview();
    renderTruckColumns();
    renderSelectedTruckDetails();
  });
  elements.manualWeight.addEventListener("input", renderWeightPreview);
  elements.birdCount.addEventListener("input", handleEntryBirdCountInput);
  elements.birdCount.addEventListener("change", () => updateEntryDefaults(true));
  elements.editBirdCount.addEventListener("input", handleEditBirdCountInput);
  elements.javaCount.addEventListener("input", renderWeightPreview);
  elements.javaCount.addEventListener("change", () => updateEntryDefaults(true));
  elements.crateType.addEventListener("change", () => updateEntryDefaults(true));
  elements.form.addEventListener("submit", addCage);
  elements.configMenuBtn?.addEventListener("click", toggleConfigMenu);
  elements.closeConfigMenuBtn?.addEventListener("click", closeConfigMenu);
  elements.openFontSidebarBtn?.addEventListener("click", openFontSidebar);
  elements.closeFontSidebarBtn?.addEventListener("click", closeFontSidebar);
  elements.resetFontSizesBtn?.addEventListener("click", resetAllCustomFontSizes);
  elements.fontSizeControls?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-font-size-action]");
    if (!button) {
      return;
    }

    const itemId = button.dataset.fontSizeId;
    if (button.dataset.fontSizeAction === "decrease") {
      changeCustomFontSize(itemId, -1);
    } else if (button.dataset.fontSizeAction === "increase") {
      changeCustomFontSize(itemId, 1);
    } else if (button.dataset.fontSizeAction === "reset") {
      resetCustomFontSize(itemId);
    }
  });
  elements.copyJsonBtn.addEventListener("click", copyJson);
  elements.openJsonBtn.addEventListener("click", openJsonModal);
  elements.closeJsonBtn.addEventListener("click", closeJsonModal);
  elements.resetDayBtn.addEventListener("click", resetDay);
  elements.returnTicketBtn?.addEventListener("click", toggleTicketOperation);
  elements.fontDecreaseBtn?.addEventListener("click", () => changeFontSize(-1));
  elements.fontIncreaseBtn?.addEventListener("click", () => changeFontSize(1));
  elements.fontResetBtn?.addEventListener("click", () => applyFontSizePreference("normal"));

  elements.closeClientModalBtn.addEventListener("click", closeClientModal);
  elements.clientSearch?.addEventListener("input", () => {
    if (clientModalTruckId) {
      renderClientList(clientModalTruckId);
    }
  });
  elements.clientList.addEventListener("click", (event) => {
    const option = event.target.closest(".client-option");
    if (!option) {
      return;
    }

    assignClientToTruck(option.dataset.clientId || null);
  });

  elements.closeDeliveryTruckModalBtn?.addEventListener("click", closeDeliverySelection);
  elements.closeDeliveryDriverModalBtn?.addEventListener("click", closeDeliverySelection);
  elements.deliveryTruckSearch?.addEventListener("input", renderDeliveryTruckList);
  elements.deliveryDriverSearch?.addEventListener("input", renderDeliveryDriverList);
  elements.deliveryTruckList?.addEventListener("click", (event) => {
    const option = event.target.closest("[data-delivery-truck-id]");
    if (option) {
      selectDeliveryTruck(option.dataset.deliveryTruckId);
    }
  });
  elements.deliveryDriverList?.addEventListener("click", (event) => {
    const option = event.target.closest("[data-delivery-driver-id]");
    if (option) {
      selectDeliveryDriver(option.dataset.deliveryDriverId);
    }
  });

  elements.closeProviderModalBtn.addEventListener("click", closeProviderModal);
  elements.providerSearch.addEventListener("input", renderProviderList);
  elements.providerList.addEventListener("click", (event) => {
    const option = event.target.closest(".provider-option");
    if (!option) {
      return;
    }

    selectOrigin(option.dataset.originId);
  });

  elements.itemEditForm.addEventListener("submit", saveCageChanges);
  elements.deleteItemBtn.addEventListener("click", deleteCageRecord);
  elements.closeItemModalBtn.addEventListener("click", closeItemModal);
  elements.closeErrorModalBtn?.addEventListener("click", closeErrorModal);

  elements.numericPadKeys.forEach((button) => {
    button.addEventListener("click", () => {
      handleNumericKeyPress(button.dataset.keypadKey);
    });
  });

  elements.numericPadBackBtn.addEventListener("click", () => handleNumericKeyPress("backspace"));
  elements.numericPadClearBtn.addEventListener("click", () => handleNumericKeyPress("clear"));
  elements.numericPadOkBtn.addEventListener("click", confirmNumericPadValue);
  elements.numericPadCloseBtn.addEventListener("click", closeNumericPad);
  elements.textTouchKeyboard?.addEventListener("click", (event) => {
    const key = event.target.closest("[data-text-keyboard-key]");
    if (key) {
      appendTextTouchKeyboardKey(key.dataset.textKeyboardKey);
      return;
    }

    const action = event.target.closest("[data-text-keyboard-action]");
    if (action) {
      handleTextTouchKeyboardAction(action.dataset.textKeyboardAction);
    }
  });
  elements.touchSelectCloseBtn?.addEventListener("click", closeTouchSelect);
  elements.touchSelectOptions?.addEventListener("click", (event) => {
    const option = event.target.closest("[data-touch-select-index]");
    if (!option || option.disabled) {
      return;
    }
    selectTouchOption(option.dataset.touchSelectIndex);
  });

  elements.jsonModal.addEventListener("click", (event) => {
    if (event.target === elements.jsonModal) {
      closeJsonModal();
    }
  });

  elements.clientModal.addEventListener("click", (event) => {
    if (event.target === elements.clientModal) {
      closeClientModal();
    }
  });

  elements.deliveryTruckModal?.addEventListener("click", (event) => {
    if (event.target === elements.deliveryTruckModal) {
      closeDeliverySelection();
    }
  });

  elements.deliveryDriverModal?.addEventListener("click", (event) => {
    if (event.target === elements.deliveryDriverModal) {
      closeDeliverySelection();
    }
  });

  elements.providerModal.addEventListener("click", (event) => {
    if (event.target === elements.providerModal) {
      closeProviderModal();
    }
  });

  elements.itemModal.addEventListener("click", (event) => {
    if (event.target === elements.itemModal) {
      closeItemModal();
    }
  });

  elements.errorModal?.addEventListener("click", (event) => {
    if (event.target === elements.errorModal) {
      closeErrorModal();
    }
  });

  elements.numericPadModal.addEventListener("click", (event) => {
    if (event.target === elements.numericPadModal) {
      closeNumericPad();
    }
  });

  elements.touchSelectModal?.addEventListener("click", (event) => {
    if (event.target === elements.touchSelectModal) {
      closeTouchSelect();
    }
  });

  elements.fontSidebarOverlay?.addEventListener("click", (event) => {
    if (event.target === elements.fontSidebarOverlay) {
      closeFontSidebar();
    }
  });

  SCALE_IDS.forEach((scaleId) => {
    elements.scaleSettingsModals[scaleId]?.addEventListener("click", (event) => {
      if (event.target === elements.scaleSettingsModals[scaleId]) {
        closeScaleSettings(scaleId);
      }
    });
  });

  document.addEventListener("click", (event) => {
    if (elements.configMenu?.hidden) {
      return;
    }

    if (elements.configMenu.contains(event.target) || elements.configMenuBtn?.contains(event.target)) {
      return;
    }

    closeConfigMenu();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }

    if (elements.textTouchKeyboard && !elements.textTouchKeyboard.hidden) {
      closeTextTouchKeyboard(false);
      return;
    }

    if (!elements.numericPadModal.hidden) {
      closeNumericPad();
      return;
    }

    if (elements.touchSelectModal && !elements.touchSelectModal.hidden) {
      closeTouchSelect();
      return;
    }

    if (elements.errorModal && !elements.errorModal.hidden) {
      closeErrorModal();
      return;
    }

    if (elements.deliveryDriverModal && !elements.deliveryDriverModal.hidden) {
      closeDeliverySelection();
      return;
    }

    if (elements.deliveryTruckModal && !elements.deliveryTruckModal.hidden) {
      closeDeliverySelection();
      return;
    }

    if (!elements.providerModal.hidden) {
      closeProviderModal();
      return;
    }

    if (!elements.itemModal.hidden) {
      closeItemModal();
      return;
    }

    if (!elements.clientModal.hidden) {
      closeClientModal();
      return;
    }

    if (!elements.jsonModal.hidden) {
      closeJsonModal();
      return;
    }

    if (!elements.fontSidebarOverlay?.hidden) {
      closeFontSidebar();
      return;
    }

    const openScaleSettingsId = SCALE_IDS.find((scaleId) => !elements.scaleSettingsModals[scaleId]?.hidden);
    if (openScaleSettingsId) {
      closeScaleSettings(openScaleSettingsId);
      return;
    }

    if (!elements.configMenu?.hidden) {
      closeConfigMenu();
    }
  });

  elements.selectedTruckDetails.addEventListener("click", (event) => {
    const registerBtn = event.target.closest(".register-ticket-btn");
    if (registerBtn) {
      void registerDispatchTicket(registerBtn.dataset.registerTicket);
      return;
    }

    const printBtn = event.target.closest(".print-ticket-btn");
    if (!printBtn) {
      return;
    }

    printDispatchTicket(printBtn.dataset.printTicket);
  });

  elements.trucksGrid.addEventListener("click", (event) => {
    const row = event.target.closest(".cage-row");
    if (row) {
      handleCageRowActivation(row);
      return;
    }

    const assignBtn = event.target.closest(".assign-client-btn");
    if (assignBtn) {
      const truck = state.trucks.find((item) => item.id === assignBtn.dataset.assignClient);
      openClientModal(assignBtn.dataset.assignClient, isReturnTicket(truck) ? "return" : "destination");
      return;
    }

    const clearBtn = event.target.closest(".clear-column-btn");
    if (clearBtn) {
      clearRegisteredTruckColumn(clearBtn.dataset.clearColumn);
      return;
    }

    const card = event.target.closest(".truck-card");
    if (!card) {
      return;
    }

    const truckId = card.dataset.truckId;
    if (!truckId) {
      return;
    }

    elements.truckSelect.value = truckId;
    renderTypeButtons();
    renderEntryProviderSelection();
    renderWeightPreview();
    renderTruckColumns();
    renderSelectedTruckDetails();
  });

  elements.trucksGrid.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") {
      return;
    }

    const row = event.target.closest(".cage-row");
    if (!row) {
      return;
    }

    event.preventDefault();
    handleCageRowActivation(row);
  });
}

renderEditTypeOptions();
renderCrateTypeOptions();
bindNumericInputs();
bindTextTouchInputs();
bindTouchSelects();
applyFontSizePreference(loadFontSizePreference(), false);
applyCustomFontSizes();
renderFontSizeControls();
bindEvents();
bindResponsiveLayout();
initializeMobilePanelFromHash();
initializeCustomerDisplaySync();
renderAll();
setFormMessage("Sistema listo. Cargando la configuración de la sucursal...");
void loadCurrentDirectoryData().finally(() => {
  renderAll();
  const hasRememberedScaleConnection = SCALE_IDS.some((scaleId) => {
    return Boolean(getScaleState(scaleId).autoConnectMode);
  });
  setFormMessage(
    `${hasRememberedScaleConnection
      ? "Sistema listo. Restaurando la última conexión de balanza..."
      : "Sistema listo. Conecta una balanza Bluetooth o usa el peso manual del registro."
    }${getScaleFeatureWarning() ? ` ${getScaleFeatureWarning()}` : ""}`
  );
  scheduleScaleConnectionRestore({ reset: true, immediate: true });
});
