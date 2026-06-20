const STORAGE_KEY = "sistema-pollos-state-v1";

const PEOPLE_STORAGE_KEY = "sistema-pollos-personas-v1";

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
      { id: "truckPrice", label: "Texto de precios ticket", type: "letra", cssVar: "--fs-truck-price", defaultPx: 12, min: 9, max: 28 },
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
      { id: "selectedTotal", label: "Total S/ seleccionado", type: "número", cssVar: "--fs-selected-total", defaultPx: 21, min: 12, max: 48 },
      { id: "keypadValue", label: "Número teclado táctil", type: "número", cssVar: "--fs-keypad-value", defaultPx: 42, min: 22, max: 80 },
      { id: "keypadNumber", label: "Botones teclado táctil", type: "número", cssVar: "--fs-keypad-number", defaultPx: 29, min: 16, max: 58 }
    ]
  }
];
const CUSTOM_FONT_SIZE_ITEMS = CUSTOM_FONT_SIZE_GROUPS.flatMap((group) => group.items);

const CHICKEN_TYPES = [
  { id: "pollo_vivo", label: "Pollo vivo", shortLabel: "Vivo", tagClass: "tag-pollo-vivo", defaultPriceKg: 8.5 },
  { id: "pollo_pelado", label: "Pollo pelado", shortLabel: "Pelado", tagClass: "tag-pollo-pelado", defaultPriceKg: 8.5 },
  { id: "pollo_beneficiado", label: "Pollo beneficiado", shortLabel: "Benef.", tagClass: "tag-pollo-beneficiado", defaultPriceKg: 8.5 }
];
const DISPATCH_CHICKEN_TYPES = CHICKEN_TYPES.filter((type) => type.id !== "pollo_beneficiado");

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
  { id: "cli-001", name: "Rogelio Oscar Cruz Alvino", pricesKg: { pollo_vivo: 8.3, pollo_pelado: 8.7 } },
  { id: "cli-002", name: "Ursula Huaman", pricesKg: { pollo_vivo: 8.1, pollo_pelado: 8.5 } },
  { id: "cli-003", name: "Avicola San Fernando", pricesKg: { pollo_vivo: 8.6, pollo_pelado: 9.0 } },
  { id: "cli-004", name: "Distribuidora Polleria Central", pricesKg: { pollo_vivo: 8.4, pollo_pelado: 8.8 } },
  { id: "cli-005", name: "Comercializadora El Corral", pricesKg: { pollo_vivo: 8.2, pollo_pelado: 8.6 } },
  { id: "cli-006", name: "Mercado Mayorista La Esperanza", pricesKg: { pollo_vivo: 8.5, pollo_pelado: 8.9 } }
];

const WAREHOUSE_DESTINATIONS = [
  {
    id: "destino-almacen-1",
    name: "Almacén 1",
    destinationType: "almacen",
    warehouseNumber: 1
  },
  {
    id: "destino-almacen-2",
    name: "Almacén 2",
    destinationType: "almacen",
    warehouseNumber: 2
  }
];
const WAREHOUSE_ORIGINS = WAREHOUSE_DESTINATIONS.map((warehouse) => ({
  id: `origen-almacen-${warehouse.warehouseNumber}`,
  name: warehouse.name,
  nombre: warehouse.name,
  originType: "almacen",
  warehouseNumber: warehouse.warehouseNumber,
  dni: "",
  direccion: ""
}));

const VALID_TYPES = new Set(DISPATCH_CHICKEN_TYPES.map((type) => type.id));
const VALID_WEIGHT_SOURCES = new Set(["1", "2", "manual"]);
const DEFAULT_GENERAL_PRICES_KG = {
  pollo_vivo: 8.5,
  pollo_pelado: 8.5,
  pollo_beneficiado: 8.5
};
const CRATE_TYPES = [
  { id: "java_700", label: "Java 7.00 kg", weightKg: 7.0 },
  { id: "java_690", label: "Java 6.90 kg", weightKg: 6.9 }
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
const KG_PER_LB = 0.45359237;
const SCALE_SERIAL_DEFAULTS = {
  baudRate: 9600,
  dataBits: 8,
  stopBits: 1,
  parity: "none",
  flowControl: "none"
};
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

const elements = {
  backToMenuBtn: document.getElementById("backToMenuBtn"),
  appShell: document.getElementById("appShell"),
  form: document.getElementById("cageForm"),
  mobileTabs: document.querySelectorAll("[data-mobile-panel-target]"),
  typeButtons: document.querySelectorAll(".type-btn"),
  truckSelect: document.getElementById("truckSelect"),
  selectProviderBtn: document.getElementById("selectProviderBtn"),
  selectedProviderName: document.getElementById("selectedProviderName"),
  truckPlateField: document.getElementById("truckPlateField"),
  truckPlate: document.getElementById("truckPlate"),
  generalPriceInputs: {
    pollo_vivo: document.getElementById("generalPriceVivoKg"),
    pollo_pelado: document.getElementById("generalPricePeladoKg"),
    pollo_beneficiado: document.getElementById("generalPriceBeneficiadoKg")
  },
  birdCount: document.getElementById("birdCount"),
  javaCount: document.getElementById("javaCount"),
  crateType: document.getElementById("crateType"),
  weightSource: document.getElementById("weightSource"),
  manualWeightField: document.getElementById("manualWeightField"),
  manualWeight: document.getElementById("manualWeight"),
  selectedWeightValue: document.getElementById("selectedWeightValue"),
  selectedWeightBreakdown: document.getElementById("selectedWeightBreakdown"),
  formMessage: document.getElementById("formMessage"),
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
  closeClientModalBtn: document.getElementById("closeClientModalBtn"),
  clientModalTruckLabel: document.getElementById("clientModalTruckLabel"),
  clientSearch: document.getElementById("clientSearch"),
  clientList: document.getElementById("clientList"),
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
  editCrateType: document.getElementById("editCrateType"),
  editWeight: document.getElementById("editWeight"),
  editWeightSource: document.getElementById("editWeightSource"),
  editSelectProviderBtn: document.getElementById("editSelectProviderBtn"),
  editSelectedProviderName: document.getElementById("editSelectedProviderName"),
  editTruckPlateField: document.getElementById("editTruckPlateField"),
  editTruckPlate: document.getElementById("editTruckPlate"),
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
  numericPadModal: document.getElementById("numericPadModal"),
  numericPadTitle: document.getElementById("numericPadTitle"),
  numericPadValue: document.getElementById("numericPadValue"),
  numericPadDotBtn: document.getElementById("numericPadDotBtn"),
  numericPadCloseBtn: document.getElementById("numericPadCloseBtn"),
  numericPadBackBtn: document.getElementById("numericPadBackBtn"),
  numericPadClearBtn: document.getElementById("numericPadClearBtn"),
  numericPadOkBtn: document.getElementById("numericPadOkBtn"),
  numericPadKeys: document.querySelectorAll("[data-keypad-key]"),
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
  }
};

let editingContext = null;
let clientModalTruckId = null;
let providerPickerContext = null;
let editSelectedOrigin = null;
let scaleTextDecoder = new TextDecoder("utf-8");
let scaleConnections = { 1: null, 2: null };
let scaleRenderFrames = { 1: null, 2: null };
let scaleLastPersistedAt = { 1: 0, 2: 0 };
let capturedScaleWeights = { 1: null, 2: null };
let keypadContext = {
  targetInput: null,
  value: "",
  allowDecimal: true,
  fieldLabel: ""
};

function createDefaultTruck(index) {
  return {
    id: `camion-${index + 1}`,
    name: `Ticket ${index + 1}`,
    clientId: null,
    cages: []
  };
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

function createDefaultScaleState(id) {
  return {
    id,
    currentWeight: 0,
    lastRaw: "",
    updatedAt: null,
    connectionMode: null,
    deviceName: ""
  };
}

function normalizeScaleState(scale, id) {
  const fallback = createDefaultScaleState(id);
  const currentWeight = roundWeight(Number(scale?.currentWeight));

  return {
    ...fallback,
    currentWeight: Number.isFinite(currentWeight) ? currentWeight : 0,
    lastRaw: String(scale?.lastRaw || "").slice(-MAX_SCALE_RAW_LENGTH),
    updatedAt: scale?.updatedAt || null,
    connectionMode: scale?.connectionMode || null,
    deviceName: scale?.deviceName || ""
  };
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

function readPeopleDirectoryClients() {
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

function normalizeCatalogClient(record, source = "directory") {
  const id = String(record?.id || "").trim();
  const name = String(record?.name || record?.nombre || "").trim();

  if (!id || !name) {
    return null;
  }

  const rawPrices = record?.pricesKg || record?.preciosKg || {};

  return {
    id,
    name,
    nombre: name,
    dni: String(record?.dni || "").trim(),
    direccion: String(record?.direccion || "").trim(),
    source,
    destinationType: record?.destinationType === "almacen" ? "almacen" : "cliente",
    warehouseNumber: record?.destinationType === "almacen"
      ? Number(record?.warehouseNumber) || null
      : null,
    updatedAt: record?.updatedAt || record?.createdAt || "",
    pricesKg: {
      pollo_vivo: normalizePriceKg(rawPrices.pollo_vivo ?? record?.precioPolloVivoKg, DEFAULT_GENERAL_PRICES_KG.pollo_vivo),
      pollo_pelado: normalizePriceKg(rawPrices.pollo_pelado ?? record?.precioPolloPeladoKg, DEFAULT_GENERAL_PRICES_KG.pollo_pelado),
      pollo_beneficiado: normalizePriceKg(rawPrices.pollo_beneficiado ?? record?.precioPolloBeneficiadoKg, DEFAULT_GENERAL_PRICES_KG.pollo_beneficiado)
    }
  };
}

function getClientCatalog() {
  const catalogById = new Map();

  readPeopleDirectoryClients().forEach((client) => {
    const normalized = normalizeCatalogClient(client, "directory");
    if (normalized) {
      catalogById.set(normalized.id, normalized);
    }
  });

  CLIENTS.forEach((client) => {
    const normalized = normalizeCatalogClient(client, "sample");
    if (normalized && !catalogById.has(normalized.id)) {
      catalogById.set(normalized.id, normalized);
    }
  });

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

function normalizeClientId(clientId) {
  if (!clientId) {
    return null;
  }

  const normalized = String(clientId);
  return getClientCatalog().some((client) => client.id === normalized) ? normalized : null;
}

function getTruckClientName(truck) {
  const client = getClientById(truck.clientId);
  return client ? client.name : "Sin destino asignado";
}

function isWarehouseDestination(client) {
  return client?.destinationType === "almacen";
}

function getDestinationTypeLabel(client) {
  return isWarehouseDestination(client) ? "Almacén" : "Cliente";
}

function normalizeCatalogProvider(record) {
  const id = String(record?.id || "").trim();
  const name = String(record?.name || record?.nombre || "").trim();

  if (!id || !name) {
    return null;
  }

  return {
    id,
    name,
    nombre: name,
    originType: "proveedor",
    warehouseNumber: null,
    dni: String(record?.dni || "").trim(),
    direccion: String(record?.direccion || "").trim(),
    updatedAt: record?.updatedAt || record?.createdAt || ""
  };
}

function getProviderCatalog() {
  return readPeopleDirectoryProviders()
    .map(normalizeCatalogProvider)
    .filter(Boolean)
    .sort((a, b) => {
      const updatedComparison = String(b.updatedAt || "").localeCompare(String(a.updatedAt || ""));
      return updatedComparison || a.name.localeCompare(b.name, "es", { sensitivity: "base" });
    });
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
  return getProviderById(normalizedId) ? normalizedId : null;
}

function getOriginCatalog() {
  return [
    ...WAREHOUSE_ORIGINS,
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
  return getOriginById(normalizedId) ? normalizedId : null;
}

function isWarehouseOrigin(origin) {
  return origin?.originType === "almacen";
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
    numeroAlmacen: warehouse ? origin.warehouseNumber : null
  };

  return {
    tipoOrigen: origin.originType,
    origenId: origin.id,
    origenNombre: origin.name,
    numeroAlmacenOrigen: warehouse ? origin.warehouseNumber : null,
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

function normalizeCageRecord(cage, fallbackId) {
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
    ? rawBirdsPerJava * javaCount
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
    timestamp: cage?.timestamp || new Date().toISOString(),
    hora: cage?.hora || "--:--:--",
    tipo: normalizeType(cage?.tipo),
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
    ...originRecord,
    placaCamion: truckPlate
  };
}

function createDefaultState() {
  return {
    lastId: 0,
    selectedType: DISPATCH_CHICKEN_TYPES[0].id,
    entryDefaults: {
      birdCountPerJava: 1,
      javaCount: 1,
      crateTypeId: DEFAULT_CRATE_TYPE_ID,
      originId: null
    },
    generalPricesKg: { ...DEFAULT_GENERAL_PRICES_KG },
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

function formatCurrency(value) {
  return `S/ ${Number(value).toFixed(2)}`;
}

function normalizePriceKg(value, fallback = 0) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }

  return roundWeight(parsed);
}

function normalizeJavaCount(value, fallback = 1) {
  const parsed = Math.trunc(Number(value));
  if (!Number.isInteger(parsed) || parsed <= 0) {
    return fallback;
  }

  return parsed;
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

function normalizePricesKg(rawPrices, fallbackPrices = DEFAULT_GENERAL_PRICES_KG) {
  const normalized = {};

  CHICKEN_TYPES.forEach((type) => {
    const fallback = normalizePriceKg(fallbackPrices?.[type.id], type.defaultPriceKg);
    normalized[type.id] = normalizePriceKg(rawPrices?.[type.id], fallback);
  });

  return normalized;
}

function getClientPricesKg(client, fallbackPrices = DEFAULT_GENERAL_PRICES_KG) {
  if (!client) {
    return normalizePricesKg({}, fallbackPrices);
  }

  const rawPrices = {};
  const legacyPrice = normalizePriceKg(client.priceKg, 0);

  CHICKEN_TYPES.forEach((type) => {
    const explicitPrice = client?.pricesKg?.[type.id];

    if (explicitPrice !== undefined && explicitPrice !== null && explicitPrice !== "") {
      rawPrices[type.id] = explicitPrice;
      return;
    }

    if (legacyPrice > 0) {
      rawPrices[type.id] = legacyPrice;
    }
  });

  return normalizePricesKg(rawPrices, fallbackPrices);
}

function formatPricesSummary(pricesKg) {
  const normalizedPrices = normalizePricesKg(pricesKg, DEFAULT_GENERAL_PRICES_KG);
  return DISPATCH_CHICKEN_TYPES
    .map((type) => `${type.shortLabel}: ${formatCurrency(normalizedPrices[type.id])}/kg`)
    .join(" | ");
}

function calculateTotalAmountByType(byType, pricesKg) {
  const normalizedPrices = normalizePricesKg(pricesKg, DEFAULT_GENERAL_PRICES_KG);
  return roundWeight(
    byType.reduce((sum, item) => sum + (Number(item.weight) || 0) * normalizedPrices[item.id], 0)
  );
}

function getTruckPricing(truck) {
  const generalPricesKg = normalizePricesKg(state.generalPricesKg, DEFAULT_GENERAL_PRICES_KG);
  const client = getClientById(truck.clientId);

  if (isWarehouseDestination(client)) {
    return {
      pricesKg: generalPricesKg,
      source: "almacen",
      sourceLabel: "Precios generales (almacén)",
      client
    };
  }

  if (client) {
    return {
      pricesKg: getClientPricesKg(client, generalPricesKg),
      source: "cliente",
      sourceLabel: "Precios cliente",
      client
    };
  }

  return {
    pricesKg: generalPricesKg,
    source: "general",
    sourceLabel: "Precios generales",
    client: null
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
    fieldLabel: ""
  };
}

function renderNumericPad() {
  elements.numericPadTitle.textContent = keypadContext.fieldLabel || "Campo numérico";
  elements.numericPadValue.textContent = keypadContext.value || "0";
  elements.numericPadDotBtn.disabled = !keypadContext.allowDecimal;
}

function openNumericPadForInput(input) {
  if (!input) {
    return;
  }

  if (!elements.numericPadModal.hidden && keypadContext.targetInput === input) {
    return;
  }

  keypadContext.targetInput = input;
  keypadContext.allowDecimal = inputAllowsDecimal(input);
  keypadContext.fieldLabel = getKeypadFieldLabel(input);
  keypadContext.value = sanitizeNumericBuffer(input.value, keypadContext.allowDecimal);

  renderNumericPad();
  elements.numericPadModal.hidden = false;
}

function closeNumericPad() {
  elements.numericPadModal.hidden = true;
  resetKeypadContext();
}

function handleNumericKeyPress(key) {
  if (!keypadContext.targetInput) {
    return;
  }

  let value = keypadContext.value;

  if (key === "clear") {
    keypadContext.value = "";
    renderNumericPad();
    return;
  }

  if (key === "backspace") {
    keypadContext.value = value.slice(0, -1);
    renderNumericPad();
    return;
  }

  if (key === "dot") {
    if (!keypadContext.allowDecimal || value.includes(".")) {
      return;
    }

    keypadContext.value = value ? `${value}.` : "0.";
    renderNumericPad();
    return;
  }

  if (!/^\d+$/.test(key)) {
    return;
  }

  if (value === "0" && !value.includes(".")) {
    value = "";
  }

  keypadContext.value = `${value}${key}`.slice(0, 14);
  renderNumericPad();
}

function confirmNumericPadValue() {
  if (!keypadContext.targetInput) {
    closeNumericPad();
    return;
  }

  const nextValue = keypadContext.value;
  keypadContext.targetInput.value = nextValue;
  keypadContext.targetInput.dispatchEvent(new Event("input", { bubbles: true }));
  keypadContext.targetInput.dispatchEvent(new Event("change", { bubbles: true }));

  closeNumericPad();
}

function bindNumericInputs() {
  const numericInputs = document.querySelectorAll('input[type="number"]');

  numericInputs.forEach((input) => {
    input.readOnly = true;
    input.setAttribute("inputmode", "none");

    let tapState = null;

    if (window.PointerEvent) {
      input.addEventListener("pointerdown", (event) => {
        if (event.button !== 0) {
          return;
        }

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

        if (!shouldOpen) {
          return;
        }

        event.preventDefault();
        openNumericPadForInput(input);
      });
    } else {
      input.addEventListener("click", () => {
        openNumericPadForInput(input);
      });
    }

    input.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") {
        return;
      }

      event.preventDefault();
      openNumericPadForInput(input);
    });
  });
}

function calculateTruckTotals(cages) {
  const byTypeMap = {};
  DISPATCH_CHICKEN_TYPES.forEach((type) => {
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
    const typeId = normalizeType(cage.tipo);
    const bucket = byTypeMap[typeId];
    const javas = normalizeJavaCount(cage.cantidadJavas, 1);
    const avesPorJava = normalizeBirdCountPerJava(cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava, 0);
    const aves = avesPorJava > 0
      ? avesPorJava * javas
      : (Number(cage.cantidadAves ?? cage.cantidadPollos) || 0);
    const grossWeight = Number(cage.pesoBrutoKg ?? cage.pesoKg) || 0;
    const tareWeight = Number(cage.taraTotalKg) || 0;
    const netWeight = Number(cage.pesoNetoKg ?? cage.pesoKg) || 0;

    bucket.records += 1;
    bucket.cages += 1;
    bucket.javas += javas;
    bucket.birds += aves;
    bucket.grossWeight += grossWeight;
    bucket.tareWeight += tareWeight;
    bucket.netWeight += netWeight;
    bucket.weight += netWeight;
  });

  const byType = DISPATCH_CHICKEN_TYPES.map((type) => ({
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
    byType
  };
}

function loadState() {
  const fallback = createDefaultState();

  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return fallback;
    }

    const parsed = JSON.parse(raw);

    if (!parsed || typeof parsed !== "object") {
      return fallback;
    }

    const loadedTrucks = Array.isArray(parsed.trucks) && parsed.trucks.length
      ? parsed.trucks.map((truck, index) => ({
          id: truck.id || `camion-${index + 1}`,
          name: normalizeDispatchTicketName(truck.name, index),
          clientId: normalizeClientId(truck.clientId || truck.clienteId || truck?.cliente?.id),
          cages: Array.isArray(truck.cages)
            ? truck.cages.map((cage, cageIndex) => normalizeCageRecord(cage, cageIndex + 1))
            : []
        }))
      : fallback.trucks;
    const trucks = ensureTruckSlots(loadedTrucks);

    const maxId = trucks.reduce((max, truck) => {
      const localMax = truck.cages.reduce((innerMax, cage) => Math.max(innerMax, cage.id), 0);
      return Math.max(max, localMax);
    }, 0);

    const legacyGeneralPrice = normalizePriceKg(parsed.generalPriceKg, 0);
    const legacyGeneralPrices = legacyGeneralPrice > 0
      ? {
          pollo_vivo: legacyGeneralPrice,
          pollo_pelado: legacyGeneralPrice,
          pollo_beneficiado: legacyGeneralPrice
        }
      : DEFAULT_GENERAL_PRICES_KG;
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
      )
    };

    return {
      lastId: Math.max(Number(parsed.lastId) || 0, maxId),
      selectedType: normalizeType(parsed.selectedType),
      entryDefaults,
      generalPricesKg: normalizePricesKg(parsed.generalPricesKg, legacyGeneralPrices),
      scales: {
        1: normalizeScaleState(parsed?.scales?.[1], 1),
        2: normalizeScaleState(parsed?.scales?.[2], 2)
      },
      trucks
    };
  } catch {
    return fallback;
  }
}

let state = loadState();
reconcileTruckClientAssignments(true);
ensureDefaultOriginSelection(true);
let customFontSizes = loadCustomFontSizes();

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
  closeNumericPad();
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
  closeNumericPad();
  closeAllScaleSettings();
  renderScaleConnectionPanels();
  setScaleSettingsOpen(scaleId, true);
}

function saveState() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

function reconcileTruckClientAssignments(shouldSave = false) {
  const validClientIds = new Set(getClientCatalog().map((client) => client.id));
  let changed = false;

  state.trucks.forEach((truck) => {
    if (truck.clientId && !validClientIds.has(truck.clientId)) {
      truck.clientId = null;
      changed = true;
    }
  });

  if (changed && shouldSave) {
    saveState();
  }

  return changed;
}

function ensureDefaultOriginSelection(shouldSave = false) {
  const origins = getOriginCatalog();
  const providers = origins.filter((origin) => !isWarehouseOrigin(origin));
  const currentOriginId = String(
    state.entryDefaults?.originId || state.entryDefaults?.providerId || ""
  ).trim();
  const nextOriginId = origins.some((origin) => origin.id === currentOriginId)
    ? currentOriginId
    : providers[0]?.id || origins[0]?.id || null;
  const changed = currentOriginId !== String(nextOriginId || "");

  state.entryDefaults = {
    ...(state.entryDefaults || {}),
    originId: nextOriginId
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

function getScaleState(scaleId) {
  if (!state.scales[scaleId]) {
    state.scales[scaleId] = createDefaultScaleState(scaleId);
  }

  state.scales[scaleId] = normalizeScaleState(state.scales[scaleId], scaleId);
  return state.scales[scaleId];
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

function formatScaleTime(timestamp) {
  if (!timestamp) {
    return "Sin lecturas";
  }

  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) {
    return "Lectura guardada";
  }

  return `Leído ${date.toLocaleTimeString("es-CO", {
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
    let statusText = featureWarning || "Sin conexión Bluetooth";
    const isConnecting = connection?.status === "connecting";
    const isConnected = connection?.status === "connected";

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
      disconnectButton.disabled = !isConnecting && !isConnected;
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
    renderJson();
    renderScaleConnectionPanels();
  });
}

function persistScaleReading(scaleId, force = false) {
  const now = Date.now();
  if (!force && now - scaleLastPersistedAt[scaleId] < SCALE_PERSIST_INTERVAL_MS) {
    return;
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

function parseIndustrialScaleText(rawText) {
  const text = String(rawText ?? "").replace(/\0/g, " ").trim();
  if (!text) {
    return null;
  }

  const matches = [];
  const weightPattern = /([+-]?\s*\d+(?:[.,]\d+)?)\s*(kg|kgs|kilogramo|kilogramos|lb|lbs|libra|libras|g|gr|gramo|gramos)?/gi;
  let match = weightPattern.exec(text);

  while (match) {
    const numericText = match[1].replace(/\s+/g, "").replace(",", ".");
    const value = Number(numericText);

    if (Number.isFinite(value)) {
      const unit = parseWeightUnit(match[2]);
      const hasUnit = Boolean(match[2]);
      const hasDecimal = /[.,]/.test(match[1]);
      const hasSign = /^[+-]/.test(match[1].trim());
      const weightKg = convertWeightToKg(value, unit);
      const score = (hasUnit ? 10 : 0) + (hasDecimal ? 4 : 0) + (hasSign ? 2 : 0) + match.index / 100000;

      matches.push({
        weightKg,
        score,
        rawValue: match[0].trim()
      });
    }

    match = weightPattern.exec(text);
  }

  if (!matches.length) {
    return null;
  }

  const bestMatch = matches.sort((a, b) => b.score - a.score)[0];
  return {
    weightKg: roundWeight(bestMatch.weightKg),
    rawValue: bestMatch.rawValue
  };
}

function parseSigWeightMeasurement(value) {
  const bytes = dataViewToBytes(value);
  if (bytes.length < 3) {
    return null;
  }

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const flags = view.getUint8(0);
  const rawWeight = view.getUint16(1, true);
  const usesImperialUnits = Boolean(flags & 0x01);
  const weightKg = usesImperialUnits
    ? rawWeight * 0.01 * KG_PER_LB
    : rawWeight * 0.005;

  return {
    weightKg: roundWeight(weightKg),
    rawValue: `BLE Weight Measurement ${rawWeight}`
  };
}

function parseScalePayload(payload, parser = "text") {
  if (typeof payload === "string") {
    const textReading = parseIndustrialScaleText(payload);
    return {
      rawText: payload,
      weightKg: textReading?.weightKg,
      rawValue: textReading?.rawValue
    };
  }

  const bytes = dataViewToBytes(payload);
  const decodedText = decodeScaleBytes(bytes);
  const decodedReading = parseIndustrialScaleText(decodedText);

  if (decodedReading) {
    return {
      rawText: decodedText,
      weightKg: decodedReading.weightKg,
      rawValue: decodedReading.rawValue
    };
  }

  if (parser === "sig-weight") {
    const binaryReading = parseSigWeightMeasurement(bytes);
    if (binaryReading) {
      return {
        rawText: decodedText || bytesToHex(bytes),
        weightKg: binaryReading.weightKg,
        rawValue: binaryReading.rawValue
      };
    }
  }

  return {
    rawText: decodedText || bytesToHex(bytes),
    weightKg: null,
    rawValue: null
  };
}

function handleScalePayload(scaleId, payload, options = {}) {
  const reading = parseScalePayload(payload, options.parser || "text");
  const scale = getScaleState(scaleId);
  const connection = scaleConnections[scaleId];
  const rawText = sanitizeScaleRawText(reading.rawText);

  if (rawText) {
    scale.lastRaw = rawText;
  }

  if (!Number.isFinite(reading.weightKg)) {
    if (connection?.status === "connected") {
      connection.statusMessage = `Conectada ${getScaleModeLabel(connection.mode)} - datos sin peso legible`;
    }

    persistScaleReading(scaleId);
    scheduleScaleRender(scaleId);
    return false;
  }

  scale.currentWeight = roundWeight(Math.max(reading.weightKg, 0));
  scale.updatedAt = new Date().toISOString();
  scale.connectionMode = options.mode || connection?.mode || scale.connectionMode;
  scale.deviceName = options.deviceName || connection?.deviceName || scale.deviceName;

  if (connection?.status === "connected") {
    const profileLabel = options.profileLabel || connection.profileLabel || getScaleModeLabel(connection.mode);
    connection.statusMessage = `Conectada ${getScaleModeLabel(connection.mode)} - ${profileLabel}`;
  }

  persistScaleReading(scaleId);
  scheduleScaleRender(scaleId);
  return true;
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
}

function handleScaleDisconnected(scaleId, message = "Balanza desconectada.") {
  const connection = scaleConnections[scaleId];
  if (!connection || connection.abort) {
    return;
  }

  clearScaleConnectionTimers(connection);
  scaleConnections[scaleId] = {
    status: "error",
    statusMessage: message
  };
  renderScaleConnectionPanels();
  setFormMessage(message, true);
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
      handleScalePayload(scaleId, value, {
        parser: currentConnection.parser,
        mode: "ble",
        deviceName: currentConnection.deviceName,
        profileLabel: currentConnection.profileLabel
      });
    } catch (error) {
      handleScaleDisconnected(scaleId, getConnectionErrorMessage(error, `No se pudo leer la balanza ${scaleId}.`));
    } finally {
      currentConnection.isPolling = false;
    }
  }, 750);
}

async function disconnectScale(scaleId, showMessage = true) {
  const connection = scaleConnections[scaleId];
  if (!connection) {
    return;
  }

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

  if (connection.port) {
    try {
      await connection.port.close();
    } catch {
      // El puerto puede seguir liberando el reader; el loop de lectura terminara solo.
    }
  }

  scaleConnections[scaleId] = null;
  renderScaleConnectionPanels();

  if (showMessage) {
    setFormMessage(`Balanza ${scaleId} desconectada.`);
  }
}

async function connectBleScale(scaleId) {
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
  scaleConnections[scaleId] = {
    mode: "ble",
    status: "connecting",
    statusMessage: `Selecciona el dispositivo BLE para Balanza ${scaleId}...`
  };
  renderScaleConnectionPanels();

  try {
    const device = await navigator.bluetooth.requestDevice({
      acceptAllDevices: true,
      optionalServices: SCALE_BLE_SERVICE_UUIDS
    });
    const deviceName = device.name || "BLE sin nombre";
    const connection = scaleConnections[scaleId];

    connection.device = device;
    connection.deviceName = deviceName;
    connection.statusMessage = `Conectando a ${deviceName}...`;
    connection.disconnectHandler = () => {
      const currentConnection = scaleConnections[scaleId];
      if (currentConnection !== connection || currentConnection.abort) {
        return;
      }

      handleScaleDisconnected(scaleId, `Balanza ${scaleId} BLE desconectada.`);
    };
    device.addEventListener("gattserverdisconnected", connection.disconnectHandler);
    renderScaleConnectionPanels();

    const server = await device.gatt.connect();
    const found = await findBleScaleCharacteristic(server);
    if (!found) {
      throw new Error("No se encontro una caracteristica BLE compatible. Agrega el UUID de la balanza cuando tengas la marca/modelo.");
    }

    connection.server = server;
    connection.characteristic = found.characteristic;
    connection.canRead = found.canRead;
    connection.parser = found.profile.parser;
    connection.profileLabel = found.profile.label;
    connection.status = "connected";
    connection.statusMessage = `Conectada BLE - ${found.profile.label}`;
    connection.valueHandler = (event) => {
      handleScalePayload(scaleId, event.target.value, {
        parser: found.profile.parser,
        mode: "ble",
        deviceName,
        profileLabel: found.profile.label
      });
    };

    if (found.canNotify) {
      found.characteristic.addEventListener("characteristicvaluechanged", connection.valueHandler);
      await found.characteristic.startNotifications();
      connection.notifyActive = true;
    }

    if (found.canRead) {
      const value = await found.characteristic.readValue();
      handleScalePayload(scaleId, value, {
        parser: found.profile.parser,
        mode: "ble",
        deviceName,
        profileLabel: found.profile.label
      });
    }

    if (!found.canNotify && found.canRead) {
      startBleReadPolling(scaleId);
    }

    renderScaleConnectionPanels();
    setFormMessage(`Balanza ${scaleId} conectada por BLE (${found.profile.label}).`);
  } catch (error) {
    await disconnectScale(scaleId, false);
    const message = getConnectionErrorMessage(error, `No se pudo conectar la balanza ${scaleId} por BLE.`);
    scaleConnections[scaleId] = {
      status: "error",
      statusMessage: message
    };
    renderScaleConnectionPanels();
    setFormMessage(message, error?.name !== "NotFoundError");
  }
}

function handleSerialScaleChunk(scaleId, bytes) {
  const connection = scaleConnections[scaleId];
  if (!connection) {
    return;
  }

  const chunkText = decodeScaleBytes(bytes);
  if (!chunkText) {
    handleScalePayload(scaleId, bytes, {
      parser: "text",
      mode: "serial",
      deviceName: connection.deviceName,
      profileLabel: "Puerto serial"
    });
    return;
  }

  connection.buffer = `${connection.buffer || ""}${chunkText}`.slice(-512);
  const parts = connection.buffer.split(/\r?\n/);
  connection.buffer = parts.pop() || "";

  let parsed = false;
  parts.forEach((line) => {
    if (!line.trim()) {
      return;
    }

    parsed = handleScalePayload(scaleId, line, {
      parser: "text",
      mode: "serial",
      deviceName: connection.deviceName,
      profileLabel: "Puerto serial"
    }) || parsed;
  });

  if (!parsed && connection.buffer.trim()) {
    handleScalePayload(scaleId, connection.buffer, {
      parser: "text",
      mode: "serial",
      deviceName: connection.deviceName,
      profileLabel: "Puerto serial"
    });
  }
}

async function readSerialScale(scaleId) {
  const connection = scaleConnections[scaleId];
  if (!connection?.port) {
    return;
  }

  try {
    while (!connection.abort && connection.port.readable) {
      const reader = connection.port.readable.getReader();
      connection.reader = reader;

      try {
        while (!connection.abort) {
          const { value, done } = await reader.read();
          if (done) {
            break;
          }

          if (value) {
            handleSerialScaleChunk(scaleId, value);
          }
        }
      } finally {
        reader.releaseLock();
        if (scaleConnections[scaleId] === connection) {
          connection.reader = null;
        }
      }
    }
  } catch (error) {
    if (!connection.abort) {
      handleScaleDisconnected(scaleId, getConnectionErrorMessage(error, `Lectura serial interrumpida en balanza ${scaleId}.`));
    }
    return;
  }

  if (!connection.abort && scaleConnections[scaleId] === connection) {
    handleScaleDisconnected(scaleId, `Puerto serial de balanza ${scaleId} desconectado.`);
  }
}

async function connectSerialScale(scaleId) {
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
  scaleConnections[scaleId] = {
    mode: "serial",
    status: "connecting",
    statusMessage: `Selecciona el puerto Bluetooth serial de Balanza ${scaleId}...`
  };
  renderScaleConnectionPanels();

  try {
    const port = await navigator.serial.requestPort();
    await port.open(SCALE_SERIAL_DEFAULTS);
    scaleConnections[scaleId] = {
      mode: "serial",
      status: "connected",
      statusMessage: "Conectada Serial BT - esperando datos",
      port,
      deviceName: "Puerto serial Bluetooth",
      profileLabel: "Puerto serial",
      buffer: "",
      abort: false
    };
    renderScaleConnectionPanels();
    readSerialScale(scaleId);
    setFormMessage(`Balanza ${scaleId} conectada por puerto serial Bluetooth a ${SCALE_SERIAL_DEFAULTS.baudRate} baudios.`);
  } catch (error) {
    await disconnectScale(scaleId, false);
    const message = getConnectionErrorMessage(error, `No se pudo abrir el puerto serial de balanza ${scaleId}.`);
    scaleConnections[scaleId] = {
      status: "error",
      statusMessage: message
    };
    renderScaleConnectionPanels();
    setFormMessage(message, error?.name !== "NotFoundError");
  }
}

function getScaleWeight(scaleId) {
  return getScaleState(scaleId).currentWeight || 0;
}

function parseVisibleWeight(value) {
  const text = String(value ?? "").trim();
  if (!text) {
    return 0;
  }

  const directValue = Number(text.replace(",", "."));
  if (Number.isFinite(directValue) && directValue > 0) {
    return roundWeight(directValue);
  }

  const parsed = parseIndustrialScaleText(text);
  return Number.isFinite(parsed?.weightKg) && parsed.weightKg > 0
    ? roundWeight(parsed.weightKg)
    : 0;
}

function getVisibleScaleWeight(scaleId) {
  const candidates = [
    elements.scaleInputs[scaleId]?.value,
    elements.scaleDisplays[scaleId]?.textContent,
    elements.miniScaleDisplays[scaleId]?.textContent
  ];

  for (const candidate of candidates) {
    const weight = parseVisibleWeight(candidate);
    if (weight > 0) {
      return weight;
    }
  }

  return 0;
}

function getCapturedScaleWeight(scaleId) {
  const weight = roundWeight(Number(capturedScaleWeights[scaleId]));
  return Number.isFinite(weight) && weight > 0 ? weight : 0;
}

function setCapturedScaleWeight(scaleId, weight) {
  const normalizedWeight = roundWeight(Number(weight));
  capturedScaleWeights[scaleId] = Number.isFinite(normalizedWeight) && normalizedWeight > 0
    ? normalizedWeight
    : null;
}

function clearCapturedScaleWeight(scaleId) {
  if (capturedScaleWeights[scaleId] !== undefined) {
    capturedScaleWeights[scaleId] = null;
  }
}

function syncVisibleScaleWeight(scaleId) {
  const currentWeight = getScaleWeight(scaleId);
  if (currentWeight > 0) {
    return currentWeight;
  }

  const visibleWeight = getVisibleScaleWeight(scaleId);
  if (visibleWeight <= 0) {
    return currentWeight;
  }

  const scale = getScaleState(scaleId);
  state.scales[scaleId] = {
    ...scale,
    currentWeight: visibleWeight,
    lastRaw: scale.lastRaw || `visible:${visibleWeight.toFixed(2)}kg`,
    updatedAt: scale.updatedAt || new Date().toISOString()
  };

  saveState();
  renderScaleDisplays();

  return visibleWeight;
}

function getWeightFromSource(source) {
  if (source === "manual") {
    return Number(elements.manualWeight.value);
  }

  const scaleId = Number(source);
  return getCapturedScaleWeight(scaleId) || syncVisibleScaleWeight(scaleId);
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
  elements.typeButtons.forEach((button) => {
    button.classList.toggle("is-active", button.dataset.type === state.selectedType);
  });
}

function renderEditTypeOptions() {
  elements.editType.innerHTML = DISPATCH_CHICKEN_TYPES
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
  const crateTypeId = normalizeCrateTypeId(defaults.crateTypeId, DEFAULT_CRATE_TYPE_ID);
  const originId = normalizeOriginId(defaults.originId || defaults.providerId);

  state.entryDefaults = { birdCountPerJava, javaCount, crateTypeId, originId };
  elements.birdCount.value = String(birdCountPerJava);
  elements.javaCount.value = String(javaCount);
  elements.crateType.value = crateTypeId;
  renderEntryProviderSelection();
}

function renderEntryProviderSelection() {
  const origin = getOriginById(state.entryDefaults?.originId);
  const hasOrigin = Boolean(origin);

  elements.selectedProviderName.textContent = origin?.name || "Seleccionar origen";
  elements.selectProviderBtn.classList.toggle("is-empty", !hasOrigin);
  updateTruckPlateField(origin, elements.truckPlate, elements.truckPlateField);
}

function renderEditProviderSelection() {
  const originName = String(editSelectedOrigin?.name || "").trim();
  elements.editSelectedProviderName.textContent = originName || "Sin origen registrado";
  elements.editSelectProviderBtn.classList.toggle("is-empty", !originName);
  updateTruckPlateField(editSelectedOrigin, elements.editTruckPlate, elements.editTruckPlateField);
}

function updateTruckPlateField(origin, input, field) {
  const warehouse = isWarehouseOrigin(origin);
  field.hidden = warehouse;
  input.disabled = warehouse;
  input.required = !warehouse;

  if (warehouse) {
    input.value = "";
  }
}

function renderGeneralPrices() {
  state.generalPricesKg = normalizePricesKg(state.generalPricesKg, DEFAULT_GENERAL_PRICES_KG);

  DISPATCH_CHICKEN_TYPES.forEach((type) => {
    const input = elements.generalPriceInputs[type.id];
    if (!input) {
      return;
    }

    input.value = state.generalPricesKg[type.id].toFixed(2);
  });
}

function renderScaleDisplays() {
  SCALE_IDS.forEach((scaleId) => {
    const weight = getScaleWeight(scaleId);
    const value = weight.toFixed(2);
    if (elements.scaleDisplays[scaleId]) {
      elements.scaleDisplays[scaleId].innerHTML = `${value} <span>kg</span>`;
    }
    if (elements.miniScaleDisplays[scaleId]) {
      elements.miniScaleDisplays[scaleId].innerHTML = `${value} <small>kg</small>`;
    }
    if (elements.scaleInputs[scaleId]) {
      elements.scaleInputs[scaleId].value = weight ? value : "";
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
    .map((truck) => `<option value="${escapeHtml(truck.id)}">${escapeHtml(truck.name)}</option>`)
    .join("");

  const exists = state.trucks.some((truck) => truck.id === currentValue);
  elements.truckSelect.value = exists ? currentValue : state.trucks[0]?.id;
}

function renderWeightPreview() {
  const source = elements.weightSource.value;
  const isManual = source === "manual";
  elements.manualWeightField.hidden = !isManual;

  const grossWeight = getWeightFromSource(source);
  const javaCount = normalizeJavaCount(elements.javaCount.value, state.entryDefaults?.javaCount || 1);
  const birdsPerJava = normalizeBirdCountPerJava(elements.birdCount.value, state.entryDefaults?.birdCountPerJava || 1);
  const totalBirds = birdsPerJava > 0 ? birdsPerJava * javaCount : 0;
  const crateTypeId = normalizeCrateTypeId(elements.crateType.value, state.entryDefaults?.crateTypeId || DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);

  elements.crateType.value = crateMeta.id;

  if (!Number.isFinite(grossWeight) || grossWeight < 0) {
    elements.selectedWeightValue.textContent = "--";
    elements.selectedWeightBreakdown.textContent = "Bruto -- - Javas -- | Aves totales --";
    return;
  }

  const breakdown = calculateWeightBreakdown(grossWeight, javaCount, crateMeta.weightKg);
  elements.selectedWeightValue.textContent = formatWeight(Math.max(breakdown.netWeightKg, 0));
  elements.selectedWeightBreakdown.textContent = formatWeightBreakdownDetail(breakdown, totalBirds);
}

function renderClientList(truckId) {
  const truck = state.trucks.find((item) => item.id === truckId);
  if (!truck) {
    elements.clientList.innerHTML = "";
    return;
  }

  const generalPricesKg = normalizePricesKg(state.generalPricesKg, DEFAULT_GENERAL_PRICES_KG);
  const query = String(elements.clientSearch?.value || "").trim().toLocaleLowerCase("es");
  const destinations = getClientCatalog().filter((client) => {
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

  if (!query) {
    options.push(`
      <button class="client-option ${noneActive}" type="button" data-client-id="">
        <span class="client-option-name">Sin destino asignado</span>
        <span class="client-option-price">Usa precios generales: ${formatPricesSummary(generalPricesKg)}</span>
      </button>
    `);
  }

  destinations.forEach((client) => {
    const isActive = truck.clientId === client.id ? "is-active" : "";
    const warehouse = isWarehouseDestination(client);
    const pricesKg = warehouse
      ? generalPricesKg
      : getClientPricesKg(client, generalPricesKg);
    const detail = warehouse
      ? `Destino interno · Usa precios generales: ${formatPricesSummary(pricesKg)}`
      : `Precios cliente: ${formatPricesSummary(pricesKg)}`;

    options.push(`
      <button class="client-option ${warehouse ? "is-warehouse" : ""} ${isActive}" type="button" data-client-id="${escapeHtml(client.id)}">
        <span class="client-option-heading">
          <span class="client-option-name">${escapeHtml(client.name)}</span>
          <span class="client-option-kind">${escapeHtml(getDestinationTypeLabel(client))}</span>
        </span>
        <span class="client-option-price">${escapeHtml(detail)}</span>
      </button>
    `);
  });

  elements.clientList.innerHTML = options.length
    ? options.join("")
    : `<div class="client-empty">No hay clientes o almacenes que coincidan con la búsqueda.</div>`;
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
    const details = [
      warehouse ? "Origen interno · No requiere placa" : "Proveedor registrado",
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

function buildTruckTableRows(cages, truckId) {
  return cages
    .map((cage) => {
      const typeMeta = getTypeMeta(cage.tipo);
      const typeTag = `<span class="tag ${typeMeta.tagClass}">${typeMeta.label}</span>`;
      const weightSourceText = cage.origenPeso === "manual" ? "Manual" : `B${cage.balanza}`;
      const merchandiseOrigin = String(cage.origenNombre || cage.proveedorOrigenNombre || "").trim();
      const sourceText = merchandiseOrigin
        ? `${merchandiseOrigin} · ${weightSourceText}`
        : weightSourceText;
      const javas = normalizeJavaCount(cage.cantidadJavas, 1);
      const avesPorJava = normalizeBirdCountPerJava(cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava, 0);
      const aves = avesPorJava > 0
        ? avesPorJava * javas
        : (Number(cage.cantidadAves ?? cage.cantidadPollos) || 0);
      const grossWeight = Number(cage.pesoBrutoKg ?? cage.pesoKg) || 0;
      const tareWeight = Number(cage.taraTotalKg) || 0;
      const netWeight = Number(cage.pesoNetoKg ?? cage.pesoKg) || 0;

      return `
        <tr class="cage-row" data-truck-id="${escapeHtml(truckId)}" data-cage-id="${escapeHtml(cage.id)}" tabindex="0" role="button" aria-label="Editar registro ${escapeHtml(cage.id)}">
          <td class="truck-cell-id">${escapeHtml(cage.id)}</td>
          <td class="truck-cell-type">${typeTag}</td>
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

function getTicketTypeCode(typeId) {
  const normalizedType = normalizeType(typeId);
  return normalizedType === "pollo_pelado" ? "PP" : "PV";
}

function getCommonTicketValue(values, fallback = "--") {
  const uniqueValues = Array.from(new Set(
    values
      .map((value) => String(value || "").trim())
      .filter(Boolean)
  ));

  if (!uniqueValues.length) {
    return fallback;
  }

  return uniqueValues.length === 1 ? uniqueValues[0] : "VARIOS";
}

function buildDispatchTicketHtml(truck) {
  const totals = calculateTruckTotals(truck.cages);
  const pricing = getTruckPricing(truck);
  const totalAmount = calculateTotalAmountByType(totals.byType, pricing.pricesKg);
  const emittedAt = new Date();
  const originName = getCommonTicketValue(
    truck.cages.map((cage) => cage.origenNombre || cage.proveedorOrigenNombre)
  );
  const truckPlate = getCommonTicketValue(
    truck.cages.map((cage) => cage.placaCamion),
    "ORIGEN INTERNO"
  );
  const rows = truck.cages.map((cage) => {
    const birdsPerJava = normalizeBirdCountPerJava(
      cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava,
      0
    );
    const javaCount = normalizeJavaCount(cage.cantidadJavas, 1);
    const grossWeight = Number(cage.pesoBrutoKg ?? cage.pesoKg) || 0;
    const tareWeight = Number(cage.taraTotalKg) || 0;
    const netWeight = Number(cage.pesoNetoKg ?? cage.pesoKg) || 0;

    return `
      <tr>
        <td>${escapeHtml(getTicketTypeCode(cage.tipo))}</td>
        <td class="number">${birdsPerJava}</td>
        <td class="number">${javaCount}</td>
        <td class="number">${grossWeight.toFixed(2)}</td>
        <td class="number">${tareWeight.toFixed(2)}</td>
        <td class="number">${netWeight.toFixed(2)}</td>
      </tr>
    `;
  }).join("");
  const typeTotals = totals.byType
    .filter((item) => item.records > 0)
    .map((item) => `
      <tr>
        <td>${escapeHtml(getTicketTypeCode(item.id))}</td>
        <td>${item.records}</td>
        <td>${item.javas}</td>
        <td>${item.birds}</td>
        <td>${item.grossWeight.toFixed(2)}</td>
        <td>${item.tareWeight.toFixed(2)}</td>
        <td>${item.netWeight.toFixed(2)}</td>
      </tr>
    `)
    .join("");

  return `<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>${escapeHtml(truck.name)} - Ticket de despacho</title>
  <style>
    @page {
      size: auto;
      margin: 0;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      margin: 0;
      padding: 0;
      background: #fff;
      color: #000;
    }

    body {
      width: 76mm;
      margin: 0 auto;
      padding: 3mm 2mm 8mm;
      font-family: "Courier New", Courier, monospace;
      font-size: 9px;
      line-height: 1.25;
    }

    h1,
    h2,
    p {
      margin: 0;
    }

    .center {
      text-align: center;
    }

    .business-name {
      font-size: 15px;
      font-weight: 900;
      letter-spacing: 0.04em;
    }

    .document-title {
      margin-top: 1mm;
      font-size: 11px;
      font-weight: 800;
    }

    .separator {
      margin: 2mm 0;
      border-top: 1px dashed #000;
    }

    .info {
      display: grid;
      grid-template-columns: 22mm 1fr;
      gap: 0.7mm 1mm;
    }

    .info strong {
      font-weight: 800;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    th,
    td {
      padding: 0.75mm 0.35mm;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: clip;
    }

    th {
      border-top: 1px solid #000;
      border-bottom: 1px solid #000;
      font-size: 7.5px;
      text-align: right;
    }

    th:first-child,
    td:first-child {
      text-align: left;
    }

    td {
      font-size: 8.5px;
    }

    .number {
      text-align: right;
    }

    .detail-table th:nth-child(1) { width: 10%; }
    .detail-table th:nth-child(2) { width: 12%; }
    .detail-table th:nth-child(3) { width: 10%; }
    .detail-table th:nth-child(4) { width: 23%; }
    .detail-table th:nth-child(5) { width: 20%; }
    .detail-table th:nth-child(6) { width: 25%; }

    .summary-title {
      margin-bottom: 1mm;
      font-weight: 800;
    }

    .summary-table th,
    .summary-table td {
      text-align: right;
      font-size: 7.5px;
    }

    .summary-table th:first-child,
    .summary-table td:first-child {
      text-align: left;
    }

    .grand-total {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 1mm;
      padding: 0.8mm 0;
      font-weight: 800;
    }

    .amount {
      font-size: 11px;
    }

    .signature {
      margin-top: 7mm;
      display: grid;
      gap: 5mm;
    }

    .signature-line {
      border-top: 1px solid #000;
      padding-top: 1mm;
      text-align: center;
    }

    .footer {
      margin-top: 4mm;
      text-align: center;
      font-size: 7px;
    }
  </style>
</head>
<body>
  <header class="center">
    <h1 class="business-name">SISTEMA POLLOS</h1>
    <h2 class="document-title">TICKET DE DESPACHO</h2>
  </header>

  <div class="separator"></div>

  <section class="info">
    <strong>TICKET:</strong><span>${escapeHtml(truck.name)}</span>
    <strong>FECHA:</strong><span>${escapeHtml(emittedAt.toLocaleDateString("es-CO"))}</span>
    <strong>HORA:</strong><span>${escapeHtml(emittedAt.toLocaleTimeString("es-CO"))}</span>
    <strong>DESTINO:</strong><span>${escapeHtml(getTruckClientName(truck))}</span>
    <strong>ORIGEN:</strong><span>${escapeHtml(originName)}</span>
    <strong>PLACA:</strong><span>${escapeHtml(truckPlate)}</span>
  </section>

  <div class="separator"></div>

  <table class="detail-table">
    <thead>
      <tr>
        <th>TIPO</th>
        <th>A/J</th>
        <th>CJ</th>
        <th>P.BRUTO</th>
        <th>TARA</th>
        <th>P.NETO</th>
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>

  <div class="separator"></div>

  <p class="summary-title">TOTALES</p>
  <table class="summary-table">
    <thead>
      <tr>
        <th>TIPO</th>
        <th>REG.</th>
        <th>JAVAS</th>
        <th>AVES</th>
        <th>BRUTO</th>
        <th>TARA</th>
        <th>NETO</th>
      </tr>
    </thead>
    <tbody>${typeTotals}</tbody>
  </table>

  <div class="separator"></div>

  <div class="grand-total"><span>TOTAL AVES:</span><span>${totals.birds}</span></div>
  <div class="grand-total"><span>TOTAL JAVAS:</span><span>${totals.javas}</span></div>
  <div class="grand-total"><span>PESO BRUTO:</span><span>${totals.grossWeight.toFixed(2)} kg</span></div>
  <div class="grand-total"><span>TARA JAVAS:</span><span>${totals.tareWeight.toFixed(2)} kg</span></div>
  <div class="grand-total"><span>PESO NETO:</span><span>${totals.netWeight.toFixed(2)} kg</span></div>
  <div class="grand-total amount"><span>TOTAL:</span><span>${escapeHtml(formatCurrency(totalAmount))}</span></div>

  <section class="signature">
    <div class="signature-line">NOMBRE</div>
    <div class="signature-line">FIRMA</div>
  </section>

  <p class="footer">Documento generado por Sistema Pollos</p>
</body>
</html>`;
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

  const printFrame = document.createElement("iframe");
  let cleanupTimer = null;

  printFrame.className = "ticket-print-frame";
  printFrame.title = `Impresión de ${truck.name}`;
  printFrame.setAttribute("aria-hidden", "true");
  printFrame.addEventListener("load", () => {
    const printWindow = printFrame.contentWindow;

    if (!printWindow) {
      printFrame.remove();
      setFormMessage("No se pudo abrir el servicio de impresión.", true);
      return;
    }

    const cleanup = () => {
      if (cleanupTimer) {
        window.clearTimeout(cleanupTimer);
      }
      printFrame.remove();
    };

    printWindow.addEventListener("afterprint", cleanup, { once: true });
    cleanupTimer = window.setTimeout(cleanup, 60000);

    window.setTimeout(() => {
      try {
        printWindow.focus();
        printWindow.print();
        setFormMessage(`${truck.name} enviado a la ventana de impresión.`);
      } catch {
        cleanup();
        setFormMessage("No se pudo iniciar la impresión del ticket.", true);
      }
    }, 150);
  }, { once: true });

  printFrame.srcdoc = buildDispatchTicketHtml(truck);
  document.body.appendChild(printFrame);
}

function buildSelectedTruckDetails(truck, totals, pricing) {
  const typeItems = totals.byType
    .map((item) => {
      const toneClass = `type-total-${item.id.replace(/_/g, "-")}`;
      const priceKg = normalizePriceKg(pricing.pricesKg[item.id], 0);
      const amount = roundWeight(item.weight * priceKg);

      return `
        <div class="selected-truck-type ${toneClass}">
          <span>${item.label}</span>
          <strong>${item.records} registros | ${item.javas} javas | ${item.birds} aves</strong>
          <small>Neto ${item.weight.toFixed(2)} kg | ${formatCurrency(priceKg)}/kg | ${formatCurrency(amount)}</small>
        </div>
      `;
    })
    .join("");

  return `
    <div class="selected-truck-bar-head">
      <div>
        <span>Ticket seleccionado</span>
        <strong>${escapeHtml(truck.name)}</strong>
        <small>${escapeHtml(getTruckClientName(truck))}</small>
      </div>
      <div class="selected-truck-price">
        <span>${pricing.sourceLabel}</span>
        <strong>${formatPricesSummary(pricing.pricesKg)}</strong>
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
      <div class="selected-truck-stat selected-truck-total">
        <span>Total S/</span>
        <strong>${formatCurrency(pricing.totalAmount)}</strong>
      </div>
    </div>

    <div class="selected-truck-types">
      ${typeItems}
    </div>

    <div class="selected-truck-actions">
      <button
        class="print-ticket-btn"
        type="button"
        data-print-ticket="${escapeHtml(truck.id)}"
        ${totals.records ? "" : "disabled"}
      >Generar ticket</button>
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

  const totals = calculateTruckTotals(truck.cages);
  const pricingBase = getTruckPricing(truck);
  const pricing = {
    ...pricingBase,
    totalAmount: calculateTotalAmountByType(totals.byType, pricingBase.pricesKg)
  };

  elements.selectedTruckDetails.innerHTML = buildSelectedTruckDetails(truck, totals, pricing);
}

function renderTruckColumns() {
  const activeTruckId = elements.truckSelect.value;

  elements.trucksGrid.innerHTML = state.trucks
    .map((truck) => {
      const totals = calculateTruckTotals(truck.cages);
      const pricingBase = getTruckPricing(truck);
      const totalAmount = calculateTotalAmountByType(totals.byType, pricingBase.pricesKg);
      const pricing = {
        ...pricingBase,
        totalAmount
      };

      const tableContent = totals.cages
        ? `
          <div class="table-wrap">
            <table class="truck-table">
              <thead>
                <tr>
                  <th class="truck-head-id">#</th>
                  <th class="truck-head-type">Tipo</th>
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
                ${buildTruckTableRows(truck.cages, truck.id)}
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
      const priceLabel = pricing.sourceLabel;

      return `
        <article class="truck-card ${isActive}" data-truck-id="${escapeHtml(truck.id)}">
          <header class="truck-head">
            <div class="truck-head-top">
              <h3>${escapeHtml(truck.name)}</h3>
              <button class="assign-client-btn" type="button" data-assign-client="${escapeHtml(truck.id)}">Destino</button>
            </div>
            <p class="truck-client-name">${clientName}</p>
            <p class="truck-price-line">${priceLabel}: ${formatPricesSummary(pricing.pricesKg)}</p>
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
            <div class="metric">
              <span>Total S/</span>
              <strong>${totalAmount.toFixed(2)}</strong>
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
      const truckTotals = calculateTruckTotals(truck.cages);
      const pricing = getTruckPricing(truck);
      acc.records += truckTotals.records;
      acc.javas += truckTotals.javas;
      acc.birds += truckTotals.birds;
      acc.grossWeight += truckTotals.grossWeight;
      acc.tareWeight += truckTotals.tareWeight;
      acc.netWeight += truckTotals.netWeight;
      acc.amount += calculateTotalAmountByType(truckTotals.byType, pricing.pricesKg);
      return acc;
    },
    { records: 0, javas: 0, birds: 0, grossWeight: 0, tareWeight: 0, netWeight: 0, amount: 0 }
  );

  elements.globalStats.textContent = `Registros: ${total.records} | Javas: ${total.javas} | Javas kg: ${roundWeight(total.tareWeight).toFixed(2)} | Aves: ${total.birds} | Neto aves kg: ${roundWeight(total.netWeight).toFixed(2)} | Total S/: ${roundWeight(total.amount).toFixed(2)}`;
}

function buildJsonPayload() {
  const generalPricesKg = normalizePricesKg(state.generalPricesKg, DEFAULT_GENERAL_PRICES_KG);

  return {
    fechaCorte: new Date().toISOString(),
    precioGeneralKg: generalPricesKg.pollo_vivo,
    preciosGeneralesKg: generalPricesKg,
    tiposDisponibles: DISPATCH_CHICKEN_TYPES.map((type) => ({ id: type.id, nombre: type.label })),
    tiposJavasDisponibles: CRATE_TYPES.map((crate) => ({ id: crate.id, nombre: crate.label, pesoKg: crate.weightKg })),
    clientesDisponibles: getClientCatalog().map((client) => {
      const pricesKg = isWarehouseDestination(client)
        ? generalPricesKg
        : getClientPricesKg(client, generalPricesKg);

      return {
        id: client.id,
        name: client.name,
        nombre: client.name,
        tipoDestino: client.destinationType,
        numeroAlmacen: client.warehouseNumber,
        priceKg: pricesKg.pollo_vivo,
        pricesKg
      };
    }),
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
      const totals = calculateTruckTotals(truck.cages);
      const pricing = getTruckPricing(truck);
      const totalAmount = calculateTotalAmountByType(totals.byType, pricing.pricesKg);
      const client = getClientById(truck.clientId);
      const clientPricesKg = client
        ? (isWarehouseDestination(client) ? generalPricesKg : getClientPricesKg(client, generalPricesKg))
        : null;
      const destination = client
        ? {
            id: client.id,
            name: client.name,
            nombre: client.name,
            tipo: client.destinationType,
            numeroAlmacen: client.warehouseNumber,
            priceKg: clientPricesKg.pollo_vivo,
            pricesKg: clientPricesKg
          }
        : null;

      return {
        id: truck.id,
        nombre: truck.name,
        cliente: destination,
        destino: destination,
        tipoDestino: client?.destinationType || null,
        precioAplicadoKg: pricing.pricesKg.pollo_vivo,
        preciosAplicadosKg: pricing.pricesKg,
        origenPrecio: pricing.source,
        totalImporte: totalAmount,
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
          totalKg: item.weight,
          precioKgAplicado: normalizePriceKg(pricing.pricesKg[item.id], 0),
          totalImporte: roundWeight(item.weight * normalizePriceKg(pricing.pricesKg[item.id], 0))
        })),
        jaulas: truck.cages
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
  renderGeneralPrices();
  renderScaleDisplays();
  renderScaleConnectionPanels();
  renderTruckSelect();
  renderEntryDefaults();
  renderWeightPreview();
  renderTruckColumns();
  renderSelectedTruckDetails();
  renderGlobalStats();
  renderJson();

  if (!elements.clientModal.hidden && clientModalTruckId) {
    const truck = state.trucks.find((item) => item.id === clientModalTruckId);
    if (truck) {
      const pricing = getTruckPricing(truck);
      elements.clientModalTruckLabel.textContent = `${truck.name} - ${getTruckClientName(truck)} (${pricing.sourceLabel}: ${formatPricesSummary(pricing.pricesKg)})`;
      renderClientList(clientModalTruckId);
    }
  }

  if (!elements.providerModal.hidden) {
    renderProviderList();
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
    updatedAt: new Date().toISOString(),
    connectionMode: "manual",
    deviceName: ""
  };
  saveState();
  renderAll();
  closeScaleSettings(scaleId);
  setFormMessage(`Balanza ${scaleId} actualizada a ${formatWeight(weight)}.`);
}

function updateGeneralPrice(typeId) {
  const input = elements.generalPriceInputs[typeId];
  const typeMeta = getTypeMeta(typeId);

  if (!input || !VALID_TYPES.has(typeId)) {
    return;
  }

  const price = normalizePriceKg(input.value, 0);

  if (price <= 0) {
    setFormMessage(`Ingresa un precio general válido para ${typeMeta.label.toLowerCase()}.`, true);
    renderGeneralPrices();
    return;
  }

  state.generalPricesKg = normalizePricesKg(state.generalPricesKg, DEFAULT_GENERAL_PRICES_KG);
  state.generalPricesKg[typeId] = price;
  saveState();
  renderAll();
  setFormMessage(`Precio general de ${typeMeta.label.toLowerCase()} actualizado a ${formatCurrency(price)} por kg.`);
}

function updateEntryDefaults(showMessage = false) {
  const birdCountPerJava = normalizeBirdCountPerJava(elements.birdCount.value, state.entryDefaults?.birdCountPerJava || 1);
  const javaCount = normalizeJavaCount(elements.javaCount.value, state.entryDefaults?.javaCount || 1);
  const crateTypeId = normalizeCrateTypeId(elements.crateType.value, state.entryDefaults?.crateTypeId || DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);

  state.entryDefaults = {
    birdCountPerJava,
    javaCount,
    crateTypeId: crateMeta.id,
    originId: normalizeOriginId(state.entryDefaults?.originId)
  };

  elements.birdCount.value = String(birdCountPerJava);
  elements.javaCount.value = String(javaCount);
  elements.crateType.value = crateMeta.id;

  saveState();
  renderWeightPreview();

  if (showMessage) {
    setFormMessage(`Configuración fija: ${birdCountPerJava} aves/java, ${javaCount} javas de ${crateMeta.weightKg.toFixed(2)} kg.`);
  }
}

function captureScale(scaleId) {
  const weight = syncVisibleScaleWeight(scaleId);
  setCapturedScaleWeight(scaleId, weight);
  elements.weightSource.value = String(scaleId);
  renderWeightPreview();
  setFormMessage(
    weight > 0
      ? `Peso de balanza ${scaleId} seleccionado: ${formatWeight(weight)}.`
      : `Peso de balanza ${scaleId} seleccionado para el próximo registro.`
  );
}

function addCage(event) {
  event.preventDefault();

  const truckId = elements.truckSelect.value;
  const source = normalizeWeightSource(elements.weightSource.value === "manual" ? "manual" : elements.weightSource.value);
  const birdsPerJava = normalizeBirdCountPerJava(elements.birdCount.value, state.entryDefaults?.birdCountPerJava || 1);
  const javaCount = normalizeJavaCount(elements.javaCount.value, state.entryDefaults?.javaCount || 1);
  const totalBirds = birdsPerJava * javaCount;
  const crateTypeId = normalizeCrateTypeId(elements.crateType.value, state.entryDefaults?.crateTypeId || DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);
  const rawWeight = getWeightFromSource(source);
  const grossWeight = roundWeight(rawWeight);
  const breakdown = calculateWeightBreakdown(grossWeight, javaCount, crateMeta.weightKg);
  const origin = getOriginById(state.entryDefaults?.originId);
  const warehouseOrigin = isWarehouseOrigin(origin);
  const truckPlate = warehouseOrigin ? "" : normalizeTruckPlate(elements.truckPlate.value);
  elements.truckPlate.value = truckPlate;

  if (!truckId) {
    setFormMessage("Selecciona un ticket de despacho.", true);
    return;
  }

  if (!origin) {
    setFormMessage("Selecciona un proveedor o almacén de origen antes de agregar la pesada.", true);
    return;
  }

  if (!warehouseOrigin && !isValidTruckPlate(truckPlate)) {
    setFormMessage("Ingresa una placa válida con letras mayúsculas, números y guiones.", true);
    return;
  }

  if (!Number.isInteger(birdsPerJava) || birdsPerJava <= 0) {
    setFormMessage("Ingresa una cantidad de aves por java válida.", true);
    return;
  }

  if (!Number.isInteger(javaCount) || javaCount <= 0) {
    setFormMessage("Ingresa una cantidad de javas válida.", true);
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

  const truck = state.trucks.find((item) => item.id === truckId);
  if (!truck) {
    setFormMessage("El ticket seleccionado no existe.", true);
    return;
  }

  const now = new Date();
  const record = {
    id: ++state.lastId,
    timestamp: now.toISOString(),
    hora: now.toLocaleTimeString("es-CO", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    }),
    tipo: normalizeType(state.selectedType),
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
    ...buildOriginRecord(origin),
    placaCamion: truckPlate
  };

  truck.cages.push(record);

  state.entryDefaults = {
    birdCountPerJava: birdsPerJava,
    javaCount,
    crateTypeId: crateMeta.id,
    originId: origin.id
  };

  if (source === "manual") {
    elements.manualWeight.value = "";
  } else {
    clearCapturedScaleWeight(Number(source));
  }

  saveState();
  renderAll();

  const typeLabel = getTypeMeta(record.tipo).label;
  setFormMessage(
    `Registro #${record.id} en ${truck.name}: ${typeLabel}, origen ${origin.name}, ${warehouseOrigin ? "sin placa (origen interno)" : `placa ${truckPlate}`}, ${record.cantidadAvesPorJava} aves/java (${record.cantidadAves} aves totales), ${record.cantidadJavas} javas, neto ${record.pesoNetoKg.toFixed(2)} kg.`
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
  closeNumericPad();
  renderJson();
  elements.jsonModal.hidden = false;
}

function closeJsonModal() {
  elements.jsonModal.hidden = true;
}

function openClientModal(truckId) {
  closeNumericPad();

  const truck = state.trucks.find((item) => item.id === truckId);
  if (!truck) {
    setFormMessage("No se encontró el ticket para asignar cliente.", true);
    return;
  }

  clientModalTruckId = truck.id;
  if (elements.clientSearch) {
    elements.clientSearch.value = "";
  }
  const pricing = getTruckPricing(truck);
  elements.clientModalTruckLabel.textContent = `${truck.name} - ${getTruckClientName(truck)} (${pricing.sourceLabel}: ${formatPricesSummary(pricing.pricesKg)})`;
  renderClientList(truck.id);
  elements.clientModal.hidden = false;
  window.setTimeout(() => elements.clientSearch?.focus(), 0);
}

function closeClientModal() {
  clientModalTruckId = null;
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

  const normalizedClientId = normalizeClientId(clientId);
  truck.clientId = normalizedClientId;

  saveState();
  renderAll();
  closeClientModal();

  if (!normalizedClientId) {
    setFormMessage(`Destino removido de ${truck.name}.`);
    return;
  }

  const client = getClientById(normalizedClientId);
  const generalPricesKg = normalizePricesKg(state.generalPricesKg, DEFAULT_GENERAL_PRICES_KG);
  const clientPricesKg = isWarehouseDestination(client)
    ? generalPricesKg
    : getClientPricesKg(client, generalPricesKg);
  const destinationLabel = isWarehouseDestination(client) ? "Almacén asignado" : "Cliente asignado";
  setFormMessage(`${destinationLabel} a ${truck.name}: ${client ? client.name : normalizedClientId} (${formatPricesSummary(clientPricesKg)}).`);
}

function openProviderModal(context = "entry") {
  closeNumericPad();
  providerPickerContext = context === "edit" ? "edit" : "entry";

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
  window.setTimeout(() => elements.providerSearch.focus(), 0);
}

function closeProviderModal() {
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
    editSelectedOrigin = { ...origin };
    renderEditProviderSelection();
    closeProviderModal();
    setItemFormMessage(`Origen seleccionado: ${origin.name}.${isWarehouseOrigin(origin) ? " No requiere placa." : ""}`);
    return;
  }

  state.entryDefaults = {
    ...(state.entryDefaults || {}),
    originId: origin.id
  };
  saveState();
  renderEntryProviderSelection();
  closeProviderModal();
  setFormMessage(`Origen seleccionado: ${origin.name}.${isWarehouseOrigin(origin) ? " No requiere placa." : ""}`);
}

function openCageModal(truckId, cageId) {
  closeNumericPad();

  const found = findTruckAndCage(truckId, cageId);

  if (!found) {
    setFormMessage("No se encontró el registro seleccionado.", true);
    return;
  }

  const { truck, cage } = found;
  editingContext = { truckId: truck.id, cageId: cage.id };

  elements.itemCageNumber.textContent = `#${cage.id}`;
  elements.itemTruckName.textContent = truck.name;
  elements.itemHour.textContent = cage.hora || "--:--:--";

  elements.editType.value = normalizeType(cage.tipo);
  const editJavaCount = normalizeJavaCount(cage.cantidadJavas, 1);
  const editBirdsPerJava = normalizeBirdCountPerJava(
    cage.cantidadAvesPorJava ?? cage.cantidadPollosPorJava,
    normalizeBirdCountPerJava(Math.round((Number(cage.cantidadAves ?? cage.cantidadPollos) || 0) / Math.max(editJavaCount, 1)), 0)
  );

  elements.editBirdCount.value = editBirdsPerJava || "";
  elements.editJavaCount.value = editJavaCount;
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
        id: recordOriginId,
        name: recordOriginName,
        originType: cage.tipoOrigen || cage.origen?.tipo || catalogOrigin?.originType || "proveedor",
        warehouseNumber: cage.numeroAlmacenOrigen || cage.origen?.numeroAlmacen || catalogOrigin?.warehouseNumber || null
      }
    : null;
  elements.editTruckPlate.value = normalizeTruckPlate(cage.placaCamion || "");
  renderEditProviderSelection();

  setItemFormMessage("");
  elements.itemModal.hidden = false;
}

function closeItemModal() {
  editingContext = null;
  editSelectedOrigin = null;
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
  const type = normalizeType(elements.editType.value);
  const birdsPerJava = normalizeBirdCountPerJava(elements.editBirdCount.value, 0);
  const javaCount = normalizeJavaCount(elements.editJavaCount.value, 1);
  const totalBirds = birdsPerJava * javaCount;
  const crateTypeId = normalizeCrateTypeId(elements.editCrateType.value, DEFAULT_CRATE_TYPE_ID);
  const crateMeta = getCrateTypeMeta(crateTypeId);
  const grossWeight = roundWeight(Number(elements.editWeight.value));
  const breakdown = calculateWeightBreakdown(grossWeight, javaCount, crateMeta.weightKg);
  const source = elements.editWeightSource.value;
  const originId = String(editSelectedOrigin?.id || "").trim() || null;
  const originName = String(editSelectedOrigin?.name || "").trim();
  const warehouseOrigin = isWarehouseOrigin(editSelectedOrigin);
  const truckPlate = warehouseOrigin ? "" : normalizeTruckPlate(elements.editTruckPlate.value);
  elements.editTruckPlate.value = truckPlate;

  if (!VALID_TYPES.has(type)) {
    setItemFormMessage("Selecciona un tipo de pollo válido.", true);
    return;
  }

  if (!Number.isInteger(birdsPerJava) || birdsPerJava <= 0) {
    setItemFormMessage("Ingresa una cantidad válida de aves por java.", true);
    return;
  }

  if (!Number.isInteger(javaCount) || javaCount <= 0) {
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

  if (breakdown.netWeightKg <= 0) {
    setItemFormMessage(formatWeightMismatchMessage(breakdown), true, buildWeightMismatchErrorOptions(breakdown));
    return;
  }

  if (!VALID_WEIGHT_SOURCES.has(source)) {
    setItemFormMessage("Origen de peso inválido.", true);
    return;
  }

  if (!originName) {
    setItemFormMessage("Selecciona el proveedor o almacén de origen del registro.", true);
    return;
  }

  if (!warehouseOrigin && !isValidTruckPlate(truckPlate)) {
    setItemFormMessage("Ingresa una placa válida con letras mayúsculas, números y guiones.", true);
    return;
  }

  cage.tipo = type;
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
  Object.assign(cage, buildOriginRecord({
    ...editSelectedOrigin,
    id: originId,
    name: originName
  }));
  cage.placaCamion = truckPlate;

  saveState();
  renderAll();
  closeItemModal();

  setFormMessage(`Registro #${cage.id} actualizado en ${truck.name}. Origen ${originName}, ${warehouseOrigin ? "sin placa (origen interno)" : `placa ${truckPlate}`}, ${cage.cantidadAvesPorJava} aves/java (${cage.cantidadAves} aves totales), neto ${cage.pesoNetoKg.toFixed(2)} kg.`);
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
  const confirmed = window.confirm(`Se eliminará el registro #${cage.id} de ${truck.name}. ¿Continuar?`);
  if (!confirmed) {
    return;
  }

  truck.cages = truck.cages.filter((item) => item.id !== cage.id);

  saveState();
  renderAll();
  closeItemModal();

  setFormMessage(`Registro #${cage.id} eliminado de ${truck.name}.`);
}

function handleCageRowActivation(row) {
  const truckId = row.dataset.truckId;
  const cageId = row.dataset.cageId;

  if (!truckId || !cageId) {
    return;
  }

  elements.truckSelect.value = truckId;
  renderTruckColumns();
  renderSelectedTruckDetails();
  openCageModal(truckId, cageId);
}

function resetDay() {
  const confirmed = window.confirm("Se borrarán todos los tickets de despacho y registros guardados. ¿Continuar?");
  if (!confirmed) {
    return;
  }

  SCALE_IDS.forEach((scaleId) => {
    disconnectScale(scaleId, false);
  });
  state = createDefaultState();
  elements.truckPlate.value = "";
  saveState();
  renderAll();
  closeItemModal();
  closeClientModal();
  closeProviderModal();
  closeErrorModal();
  closeNumericPad();
  closeConfigMenu();
  closeAllScaleSettings();
  closeFontSidebar();
  setFormMessage("Jornada reiniciada.");
}

function bindEvents() {
  elements.backToMenuBtn?.addEventListener("click", () => {
    closeNumericPad();
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
  window.addEventListener("pageshow", refreshClientsFromDirectory);
  window.addEventListener("focus", refreshClientsFromDirectory);

  elements.typeButtons.forEach((button) => {
    button.addEventListener("click", () => {
      state.selectedType = normalizeType(button.dataset.type);
      saveState();
      renderTypeButtons();
    });
  });

  elements.scaleSetButtons[1].addEventListener("click", () => updateScale(1));
  elements.scaleSetButtons[2].addEventListener("click", () => updateScale(2));

  elements.scaleCaptureButtons[1].addEventListener("click", () => captureScale(1));
  elements.scaleCaptureButtons[2].addEventListener("click", () => captureScale(2));
  elements.scaleSettingsOpenButtons[1]?.addEventListener("click", () => openScaleSettings(1));
  elements.scaleSettingsOpenButtons[2]?.addEventListener("click", () => openScaleSettings(2));
  elements.scaleSettingsCloseButtons[1]?.addEventListener("click", () => closeScaleSettings(1));
  elements.scaleSettingsCloseButtons[2]?.addEventListener("click", () => closeScaleSettings(2));
  elements.scaleConnectBleButtons[1]?.addEventListener("click", () => connectBleScale(1));
  elements.scaleConnectBleButtons[2]?.addEventListener("click", () => connectBleScale(2));
  elements.scaleConnectSerialButtons[1]?.addEventListener("click", () => connectSerialScale(1));
  elements.scaleConnectSerialButtons[2]?.addEventListener("click", () => connectSerialScale(2));
  elements.scaleDisconnectButtons[1]?.addEventListener("click", () => disconnectScale(1));
  elements.scaleDisconnectButtons[2]?.addEventListener("click", () => disconnectScale(2));

  elements.weightSource.addEventListener("change", renderWeightPreview);
  elements.selectProviderBtn.addEventListener("click", () => openProviderModal("entry"));
  elements.editSelectProviderBtn.addEventListener("click", () => openProviderModal("edit"));
  elements.truckPlate.addEventListener("input", () => {
    elements.truckPlate.value = normalizeTruckPlate(elements.truckPlate.value);
  });
  elements.editTruckPlate.addEventListener("input", () => {
    elements.editTruckPlate.value = normalizeTruckPlate(elements.editTruckPlate.value);
  });
  elements.truckSelect.addEventListener("change", () => {
    renderWeightPreview();
    renderTruckColumns();
    renderSelectedTruckDetails();
  });
  elements.manualWeight.addEventListener("input", renderWeightPreview);
  elements.birdCount.addEventListener("input", renderWeightPreview);
  elements.birdCount.addEventListener("change", () => updateEntryDefaults(true));
  elements.javaCount.addEventListener("input", renderWeightPreview);
  elements.javaCount.addEventListener("change", () => updateEntryDefaults(true));
  elements.crateType.addEventListener("change", () => updateEntryDefaults(true));
  DISPATCH_CHICKEN_TYPES.forEach((type) => {
    const input = elements.generalPriceInputs[type.id];
    if (!input) {
      return;
    }

    input.addEventListener("change", () => updateGeneralPrice(type.id));
  });

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

    if (!elements.numericPadModal.hidden) {
      closeNumericPad();
      return;
    }

    if (elements.errorModal && !elements.errorModal.hidden) {
      closeErrorModal();
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
      openClientModal(assignBtn.dataset.assignClient);
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
applyFontSizePreference(loadFontSizePreference(), false);
applyCustomFontSizes();
renderFontSizeControls();
bindEvents();
bindResponsiveLayout();
initializeMobilePanelFromHash();
renderAll();
setFormMessage(`Sistema listo. Conecta una balanza Bluetooth o actualiza una lectura manual.${getScaleFeatureWarning() ? ` ${getScaleFeatureWarning()}` : ""}`);
































