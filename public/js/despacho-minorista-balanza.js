/**
 * Controlador de una balanza para el despacho minorista.
 *
 * No conoce ni modifica el DOM. La vista recibe cambios mediante los callbacks
 * `onReading`, `onStatus` y `onRaw`, o consultando la propiedad `state`.
 */

export const RETAIL_SCALE_STORAGE_KEY = "sistema-pollos-retail-scale-v1";
export const RETAIL_SCALE_LEGACY_MIGRATION_MARKER_KEY = `${RETAIL_SCALE_STORAGE_KEY}-legacy-migrated-to`;

export const RETAIL_SCALE_SERIAL_DEFAULTS = Object.freeze({
  baudRate: 9600,
  dataBits: 8,
  stopBits: 1,
  parity: "none",
  flowControl: "none"
});

/**
 * Cada puesto minorista conserva su balanza de forma independiente por
 * sucursal. La función vive aquí para que todas las vistas construyan la
 * clave exactamente igual y nunca hereden el dispositivo de otro puesto.
 */
export function buildRetailScaleStorageKey(station, branchId) {
  const normalizedStation = String(station || "1").trim() || "1";
  const normalizedBranchId = String(branchId ?? "default").trim() || "default";
  return normalizedStation === "1"
    ? `${RETAIL_SCALE_STORAGE_KEY}-branch-${normalizedBranchId}`
    : `${RETAIL_SCALE_STORAGE_KEY}-station-${normalizedStation}-branch-${normalizedBranchId}`;
}

const freezeBleProfile = (profile) => Object.freeze({
  ...profile,
  characteristics: Object.freeze([...profile.characteristics])
});

// Se mantienen los mismos perfiles probados por la pantalla mayorista, pero
// esta lista y toda la conexión viven exclusivamente dentro del módulo retail.
export const RETAIL_SCALE_BLE_PROFILES = Object.freeze([
  freezeBleProfile({
    id: "sig-weight-scale",
    label: "BLE Weight Scale",
    service: "0000181d-0000-1000-8000-00805f9b34fb",
    characteristics: ["00002a9d-0000-1000-8000-00805f9b34fb"],
    parser: "sig-weight"
  }),
  freezeBleProfile({
    id: "nordic-uart",
    label: "Nordic UART",
    service: "6e400001-b5a3-f393-e0a9-e50e24dcca9e",
    characteristics: ["6e400003-b5a3-f393-e0a9-e50e24dcca9e"],
    parser: "text"
  }),
  freezeBleProfile({
    id: "hm10-ffe0",
    label: "HM-10 FFE0",
    service: "0000ffe0-0000-1000-8000-00805f9b34fb",
    characteristics: ["0000ffe1-0000-1000-8000-00805f9b34fb"],
    parser: "text"
  }),
  freezeBleProfile({
    id: "fff0-serial",
    label: "Serial FFF0",
    service: "0000fff0-0000-1000-8000-00805f9b34fb",
    characteristics: [
      "0000fff1-0000-1000-8000-00805f9b34fb",
      "0000fff4-0000-1000-8000-00805f9b34fb"
    ],
    parser: "text"
  }),
  freezeBleProfile({
    id: "ffe5-serial",
    label: "Serial FFE5",
    service: "0000ffe5-0000-1000-8000-00805f9b34fb",
    characteristics: ["0000ffe4-0000-1000-8000-00805f9b34fb"],
    parser: "text"
  })
]);

export const RETAIL_SCALE_BLE_SERVICE_UUIDS = Object.freeze(
  Array.from(new Set(RETAIL_SCALE_BLE_PROFILES.map((profile) => profile.service)))
);

const KG_PER_LB = 0.45359237;
const MAX_RAW_LENGTH = 240;
const MAX_SERIAL_BUFFER_LENGTH = 2048;
const BLE_READ_INTERVAL_MS = 750;
const PERSIST_INTERVAL_MS = 1000;
const READING_FRESHNESS_MS = 15000;
const READING_WATCHDOG_INTERVAL_MS = 500;
const STABLE_CONFIRMATION_COUNT = 2;
const ZERO_CONFIRMATION_COUNT = 2;
const ZERO_CONFIRMATION_MIN_MS = 250;
const WEIGHT_STABILITY_TOLERANCE_KG = 0.005;
const RECONNECT_BACKOFF_MS = Object.freeze([750, 1500, 3000, 5000, 10000]);
const SERIAL_DATA_BITS = new Set([7, 8]);
const SERIAL_STOP_BITS = new Set([1, 2]);
const SERIAL_PARITIES = new Set(["none", "even", "odd"]);
const SERIAL_FLOW_CONTROLS = new Set(["none", "hardware"]);
const CONNECTION_STATUSES = new Set(["offline", "connecting", "connected", "error"]);

/**
 * Traslada una sola vez la configuración histórica de Minorista 1 a su clave
 * por sucursal. El marcador impide que la misma balanza se herede luego en
 * otra sucursal del mismo navegador. Minorista 2 nunca participa.
 */
export function migrateLegacyRetailScaleStorage(options = {}) {
  if (String(options.station || "") !== "1") return false;

  const storage = options.storage || null;
  const legacyKey = String(options.legacyKey || RETAIL_SCALE_STORAGE_KEY);
  const targetKey = String(options.targetKey || "").trim();
  const markerKey = String(
    options.markerKey || RETAIL_SCALE_LEGACY_MIGRATION_MARKER_KEY
  );
  if (!storage?.getItem || !storage?.setItem || !targetKey || targetKey === legacyKey) {
    return false;
  }

  let copiedTarget = false;
  try {
    if (storage.getItem(markerKey) !== null) return false;

    const legacyValue = storage.getItem(legacyKey);
    if (legacyValue === null) return false;

    if (storage.getItem(targetKey) !== null) {
      storage.setItem(markerKey, targetKey);
      return false;
    }

    storage.setItem(targetKey, legacyValue);
    copiedTarget = true;
    storage.setItem(markerKey, targetKey);
    return true;
  } catch {
    if (copiedTarget) {
      try {
        storage.removeItem?.(targetKey);
      } catch {
        // Si el almacenamiento está bloqueado no hay una recuperación adicional segura.
      }
    }
    return false;
  }
}

function roundWeight(value) {
  return Math.round((Number(value) + Number.EPSILON) * 1000) / 1000;
}

function normalizeWeightUnit(unit) {
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

/**
 * Extrae la lectura con mayor probabilidad de ser un peso desde una trama.
 * Admite coma o punto decimal y unidades kg, lb o g. Sin unidad se asume kg.
 */
function scaleTextRejection(text) {
  if (/\b(?:ERR(?:OR)?|FAULT|FALLO|OL|OVERLOAD|UNDERLOAD)\b/i.test(text)) {
    return "error";
  }

  if (/^\s*S\s+(?:I|\+|-)(?:\s|,|$)/i.test(text)) {
    return "error";
  }

  if (/\b(?:MOTION|MOVING|DYNAMIC|DINAMICO)\b/i.test(text)
    || /^\s*S\s+(?:D|U)(?:\s|,|$)/i.test(text)) {
    return "unstable";
  }

  return null;
}

function candidateRole(text, start, end) {
  const contextStart = Math.max(0, start - 28);
  const contextEnd = Math.min(text.length, end + 20);
  const context = text.slice(contextStart, contextEnd).toUpperCase();
  const roles = [
    { role: "net", pattern: /\b(?:NET|NETO|NT|NW)\b/g },
    { role: "gross", pattern: /\b(?:GROSS|BRUTO|GS|GW)\b/g },
    { role: "tare", pattern: /\b(?:TARE|TARA|TW)\b/g }
  ];
  const candidates = [];

  roles.forEach(({ role, pattern }) => {
    let label = pattern.exec(context);
    while (label) {
      const labelStart = contextStart + label.index;
      const labelEnd = labelStart + label[0].length;
      const distance = labelEnd <= start
        ? start - labelEnd
        : labelStart >= end
          ? labelStart - end + 4
          : 0;
      candidates.push({ role, distance });
      label = pattern.exec(context);
    }
  });

  return candidates.sort((left, right) => left.distance - right.distance)[0]?.role || "weight";
}

function analyzeIndustrialScaleText(rawText) {
  const text = String(rawText ?? "").replace(/\0/g, " ").trim();
  if (!text) {
    return { reading: null, rejectionReason: "unknown", stableHint: null };
  }

  // Hay indicadores Serial BT que reportan siempre US aunque el peso haya
  // dejado de cambiar. Se conserva el valor numérico y el controlador exige
  // dos muestras consecutivas dentro de la tolerancia antes de publicarlo.
  const reportsUnstableWeight = /\b(?:US|UNSTABLE|INESTABLE)\b/i.test(text);
  const rejectionReason = scaleTextRejection(text);
  if (rejectionReason) {
    return { reading: null, rejectionReason, stableHint: false };
  }

  const matches = [];
  let hasNegativePrimaryWeight = false;
  const pattern = /([+-]?\s*\d+(?:[.,]\d+)?)(?:\s*(kilogramos|kilogramo|kgs|kg|libras|libra|lbs|lb|gramos|gramo|gr|g)\b)?/gi;
  let match = pattern.exec(text);

  while (match) {
    const numericText = match[1].replace(/\s+/g, "").replace(",", ".");
    const numericValue = Number(numericText);

    if (Number.isFinite(numericValue)) {
      const unit = normalizeWeightUnit(match[2]);
      const hasUnit = Boolean(match[2]);
      const hasDecimal = /[.,]/.test(match[1]);
      const hasSign = /^[+-]/.test(match[1].trim());
      const weightKg = convertWeightToKg(numericValue, unit);
      const role = candidateRole(text, match.index, pattern.lastIndex);
      if (numericValue < 0) {
        if (role !== "tare") hasNegativePrimaryWeight = true;
        match = pattern.exec(text);
        continue;
      }
      const roleScore = role === "net"
        ? 200
        : role === "gross"
          ? 100
          : role === "tare"
            ? -200
            : 0;
      const score = roleScore
        + (hasUnit ? 20 : 0)
        + (hasDecimal ? 5 : 0)
        + (hasSign ? 2 : 0)
        + match.index / 100000;

      matches.push({
        weightKg,
        unit,
        sourceValue: numericValue,
        rawValue: match[0].trim(),
        role,
        score
      });
    }

    match = pattern.exec(text);
  }

  if (hasNegativePrimaryWeight) {
    return { reading: null, rejectionReason: "negative", stableHint: false };
  }

  if (!matches.length) {
    const hasNegativeNumber = /-\s*\d+(?:[.,]\d+)?/.test(text);
    return {
      reading: null,
      rejectionReason: hasNegativeNumber ? "negative" : "unknown",
      stableHint: null
    };
  }

  const bestMatch = matches.sort((left, right) => right.score - left.score)[0];
  if (bestMatch.role === "tare") {
    return { reading: null, rejectionReason: "tare", stableHint: null };
  }

  return {
    reading: {
      weightKg: roundWeight(bestMatch.weightKg),
      unit: bestMatch.unit,
      sourceValue: bestMatch.sourceValue,
      rawValue: bestMatch.rawValue,
      role: bestMatch.role
    },
    rejectionReason: null,
    stableHint: reportsUnstableWeight
      ? false
      : (
          /\b(?:ST|STABLE|ESTABLE)\b/i.test(text)
          || /^\s*S\s+S(?:\s|,|$)/i.test(text)
        ) ? true : null
  };
}

export function parseIndustrialScaleText(rawText) {
  return analyzeIndustrialScaleText(rawText).reading;
}

function toBytes(value) {
  if (value instanceof DataView) {
    return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
  }

  if (value instanceof Uint8Array) {
    return value;
  }

  if (typeof ArrayBuffer !== "undefined" && ArrayBuffer.isView(value)) {
    return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
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

function decodeBytes(value) {
  const bytes = toBytes(value);
  if (!bytes.length) {
    return "";
  }

  try {
    return new TextDecoder("utf-8").decode(bytes);
  } catch {
    return "";
  }
}

function parseSigWeightMeasurement(value) {
  const bytes = toBytes(value);
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
    rawValue: `BLE Weight Measurement ${rawWeight}`
  };
}

/**
 * Normaliza texto o bytes recibidos desde Web Bluetooth/Web Serial.
 */
export function parseRetailScalePayload(payload, parser = "text") {
  if (typeof payload === "string") {
    const analyzed = analyzeIndustrialScaleText(payload);
    return {
      rawText: payload,
      weightKg: analyzed.reading?.weightKg ?? null,
      rawValue: analyzed.reading?.rawValue ?? null,
      role: analyzed.reading?.role ?? null,
      rejectionReason: analyzed.rejectionReason,
      stableHint: analyzed.stableHint
    };
  }

  const bytes = toBytes(payload);
  const decodedText = decodeBytes(bytes);

  if (parser === "sig-weight") {
    const binaryReading = parseSigWeightMeasurement(bytes);
    if (binaryReading) {
      return {
        rawText: bytesToHex(bytes),
        weightKg: binaryReading.weightKg,
        rawValue: binaryReading.rawValue,
        role: "weight",
        rejectionReason: null,
        stableHint: true
      };
    }
  }

  const analyzed = analyzeIndustrialScaleText(decodedText);

  if (analyzed.reading) {
    return {
      rawText: decodedText,
      weightKg: analyzed.reading.weightKg,
      rawValue: analyzed.reading.rawValue,
      role: analyzed.reading.role,
      rejectionReason: null,
      stableHint: analyzed.stableHint
    };
  }

  return {
    rawText: decodedText || bytesToHex(bytes),
    weightKg: null,
    rawValue: null,
    role: null,
    rejectionReason: analyzed.rejectionReason,
    stableHint: analyzed.stableHint
  };
}

function sanitizeRawText(rawText) {
  return String(rawText ?? "")
    .replace(/\0/g, "")
    .replace(/\r/g, "\\r")
    .replace(/\n/g, "\\n")
    .replace(/\s+/g, " ")
    .trim()
    .slice(-MAX_RAW_LENGTH);
}

/**
 * Valida opciones aceptadas por SerialPort.open().
 */
export function normalizeRetailSerialOptions(options = {}, fallback = RETAIL_SCALE_SERIAL_DEFAULTS) {
  const normalizedFallback = fallback && typeof fallback === "object"
    ? fallback
    : RETAIL_SCALE_SERIAL_DEFAULTS;
  const source = options && typeof options === "object" ? options : {};
  const merged = { ...normalizedFallback, ...source };
  const baudRate = Number(merged.baudRate);
  const dataBits = Number(merged.dataBits);
  const stopBits = Number(merged.stopBits);
  const parity = String(merged.parity || "none").toLowerCase();
  const flowControl = String(merged.flowControl || "none").toLowerCase();

  if (!Number.isInteger(baudRate) || baudRate <= 0) {
    throw new RangeError("baudRate debe ser un entero mayor que cero.");
  }

  if (!SERIAL_DATA_BITS.has(dataBits)) {
    throw new RangeError("dataBits debe ser 7 u 8.");
  }

  if (!SERIAL_STOP_BITS.has(stopBits)) {
    throw new RangeError("stopBits debe ser 1 o 2.");
  }

  if (!SERIAL_PARITIES.has(parity)) {
    throw new RangeError("parity debe ser none, even u odd.");
  }

  if (!SERIAL_FLOW_CONTROLS.has(flowControl)) {
    throw new RangeError("flowControl debe ser none o hardware.");
  }

  return { baudRate, dataBits, stopBits, parity, flowControl };
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
  const bluetoothServiceClassId = String(info.bluetoothServiceClassId || "").trim();
  const hasMatchIndex = Object.prototype.hasOwnProperty.call(info, "matchIndex")
    && info.matchIndex !== null
    && info.matchIndex !== "";
  const hasPortIndex = Object.prototype.hasOwnProperty.call(info, "portIndex")
    && info.portIndex !== null
    && info.portIndex !== "";
  const matchIndex = hasMatchIndex ? Number(info.matchIndex) : null;
  const portIndex = hasPortIndex ? Number(info.portIndex) : null;

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

function getSerialPortInfo(port) {
  try {
    return normalizeSerialPortInfo(port?.getInfo?.() || {});
  } catch {
    return null;
  }
}

function serialPortIdentifiersMatch(portInfo, savedInfo) {
  const current = normalizeSerialPortInfo(portInfo);
  const saved = normalizeSerialPortInfo(savedInfo);
  if (!current || !saved) {
    return false;
  }

  const comparableKeys = ["usbVendorId", "usbProductId", "bluetoothServiceClassId"];
  const keysToCompare = comparableKeys.filter((key) => saved[key] !== null && saved[key] !== undefined);
  return keysToCompare.length > 0 && keysToCompare.every((key) => current[key] === saved[key]);
}

function normalizePersistedState(value) {
  const source = value && typeof value === "object" ? value : {};
  const autoConnectMode = ["ble", "serial"].includes(source.autoConnectMode)
    ? source.autoConnectMode
    : null;
  let serialOptions = { ...RETAIL_SCALE_SERIAL_DEFAULTS };

  try {
    serialOptions = normalizeRetailSerialOptions(source.serialOptions || {});
  } catch {
    serialOptions = { ...RETAIL_SCALE_SERIAL_DEFAULTS };
  }

  return {
    autoConnectMode,
    deviceName: String(source.deviceName || ""),
    bleDeviceId: String(source.bleDeviceId || ""),
    profileId: String(source.profileId || ""),
    profileLabel: String(source.profileLabel || ""),
    serialPortInfo: normalizeSerialPortInfo(source.serialPortInfo),
    serialOptions
  };
}

function getConnectionErrorMessage(error, fallback) {
  if (error?.name === "NotFoundError") {
    return "Selección cancelada por el usuario.";
  }

  if (error?.name === "SecurityError") {
    return "El navegador bloqueó el acceso. Usa localhost o HTTPS.";
  }

  if (error?.name === "NetworkError") {
    return "No se pudo mantener la conexión con la balanza.";
  }

  if (error?.name === "InvalidStateError") {
    return "La balanza o el puerto ya están siendo usados por otra pantalla.";
  }

  return error?.message || fallback;
}

function invokeCallback(callback, payload) {
  if (typeof callback !== "function") {
    return;
  }

  try {
    callback(payload);
  } catch (error) {
    // Un error de renderizado del consumidor no debe cortar el flujo del puerto.
    if (typeof globalThis.reportError === "function") {
      globalThis.reportError(error);
    }
  }
}

function manualWeightToKg(value) {
  if (typeof value === "number") {
    return Number.isFinite(value) && value >= 0 ? roundWeight(value) : null;
  }

  const text = String(value ?? "").trim();
  if (!text) {
    return null;
  }

  const directValue = Number(text.replace(",", "."));
  if (Number.isFinite(directValue) && directValue >= 0) {
    return roundWeight(directValue);
  }

  const parsed = parseIndustrialScaleText(text);
  return Number.isFinite(parsed?.weightKg) && parsed.weightKg >= 0
    ? roundWeight(parsed.weightKg)
    : null;
}

export class RetailScaleController {
  constructor(options = {}) {
    this._navigator = options.navigator ?? globalThis.navigator ?? null;
    this._storage = options.storage ?? globalThis.localStorage ?? null;
    this._storageKey = String(options.storageKey || RETAIL_SCALE_STORAGE_KEY);
    this._secureContext = typeof options.secureContext === "boolean"
      ? options.secureContext
      : globalThis.isSecureContext !== false;
    this._callbacks = {
      onReading: typeof options.onReading === "function" ? options.onReading : null,
      onStatus: typeof options.onStatus === "function" ? options.onStatus : null,
      onRaw: typeof options.onRaw === "function" ? options.onRaw : null
    };
    this._connection = null;
    this._connectionGeneration = 0;
    this._operationQueue = Promise.resolve();
    this._persistTimer = null;
    this._lastPersistedAt = 0;
    this._pendingReading = null;
    this._nextStableReadingStartsNewIdentity = false;
    this._readingSequence = 0;
    this._reconnectTimer = null;
    this._reconnectAttempt = 0;
    this._autoReconnectEnabled = false;
    this._restorePromise = null;
    this._destroyPromise = null;
    this._destroyed = false;

    const persisted = this._loadPersistedState();
    let serialOptions = persisted.serialOptions;
    if (options.serialOptions) {
      serialOptions = normalizeRetailSerialOptions(options.serialOptions, serialOptions);
    }

    this._state = {
      version: 2,
      status: "offline",
      statusMessage: persisted.autoConnectMode
        ? "Conexión recordada; pendiente de restaurar."
        : "Balanza sin conexión.",
      lastError: null,
      connectionMode: null,
      autoConnectMode: persisted.autoConnectMode,
      deviceName: persisted.deviceName,
      bleDeviceId: persisted.bleDeviceId,
      profileId: persisted.profileId,
      profileLabel: persisted.profileLabel,
      serialPortInfo: persisted.serialPortInfo,
      serialOptions,
      currentWeightKg: null,
      updatedAt: null,
      readingAt: null,
      readingSource: null,
      readingStatus: "unknown",
      inputStatus: "unknown",
      readingId: null,
      isStable: false,
      readingRaw: "",
      lastRaw: "",
      lastRawAt: null,
      lastRawValue: null
    };

    this._serialConnectHandler = () => {
      if (this._autoReconnectEnabled && !this._connection && this._state.autoConnectMode === "serial") {
        this._cancelReconnect();
        this._scheduleReconnect(0);
      }
    };
    this._serialDisconnectHandler = (event) => {
      const connection = this._connection;
      const disconnectedPort = event?.port
        || (typeof event?.target?.getInfo === "function" ? event.target : null);
      if (connection?.mode === "serial" && disconnectedPort === connection.port) {
        this._handleUnexpectedDisconnect(connection, "El puerto serial de la balanza se desconectó.");
      }
    };
    this._navigator?.serial?.addEventListener?.("connect", this._serialConnectHandler);
    this._navigator?.serial?.addEventListener?.("disconnect", this._serialDisconnectHandler);
  }

  get state() {
    return this.getState();
  }

  getState() {
    const capabilities = this.getCapabilities();
    const readingAtMs = Date.parse(this._state.readingAt || "");
    const isPhysicalReading = ["ble", "serial"].includes(this._state.readingSource);
    const hasFreshTimestamp = Number.isFinite(readingAtMs)
      && Date.now() - readingAtMs <= READING_FRESHNESS_MS;
    const transportMatchesReading = !isPhysicalReading
      || (this._state.status === "connected" && this._state.connectionMode === this._state.readingSource);
    const isFresh = this._state.isStable
      && Number.isFinite(this._state.currentWeightKg)
      && this._state.inputStatus === "stable"
      && (this._state.readingSource === "manual" || hasFreshTimestamp)
      && transportMatchesReading;
    return {
      ...this._state,
      isConnected: this._state.status === "connected",
      isConnecting: this._state.status === "connecting",
      isFresh,
      isCaptureReady: isFresh && this._state.currentWeightKg > 0,
      freshnessMs: READING_FRESHNESS_MS,
      serialOptions: { ...this._state.serialOptions },
      serialPortInfo: this._state.serialPortInfo ? { ...this._state.serialPortInfo } : null,
      storageKey: this._storageKey,
      capabilities
    };
  }

  getCapabilities() {
    return {
      secureContext: this._secureContext,
      bluetooth: Boolean(this._navigator?.bluetooth),
      bluetoothRestore: Boolean(this._navigator?.bluetooth?.getDevices),
      serial: Boolean(this._navigator?.serial),
      serialRestore: Boolean(this._navigator?.serial?.getPorts)
    };
  }

  setStorageKey(storageKey, options = {}) {
    this._assertActive();
    const nextKey = String(storageKey || "").trim();
    if (!nextKey) {
      throw new TypeError("La clave de almacenamiento de la balanza no puede estar vacía.");
    }
    if (nextKey === this._storageKey) return this.state;
    if (this._connection) {
      throw new Error("Desconecta la balanza antes de cambiar la sucursal de almacenamiento.");
    }

    if (options.persistCurrent !== false) {
      this._persist(true);
    } else {
      if (this._persistTimer) {
        globalThis.clearTimeout(this._persistTimer);
        this._persistTimer = null;
      }
      this._lastPersistedAt = 0;
    }
    this._storageKey = nextKey;

    if (options.reload !== false) {
      const persisted = this._loadPersistedState();
      Object.assign(this._state, {
        status: "offline",
        statusMessage: persisted.autoConnectMode
          ? "Conexión recordada; pendiente de restaurar."
          : "Balanza sin conexión.",
        lastError: null,
        connectionMode: null,
        autoConnectMode: persisted.autoConnectMode,
        deviceName: persisted.deviceName,
        bleDeviceId: persisted.bleDeviceId,
        profileId: persisted.profileId,
        profileLabel: persisted.profileLabel,
        serialPortInfo: persisted.serialPortInfo,
        serialOptions: persisted.serialOptions,
        currentWeightKg: null,
        updatedAt: null,
        readingAt: null,
        readingSource: null,
        readingStatus: "unknown",
        inputStatus: "unknown",
        readingId: null,
        isStable: false,
        readingRaw: "",
        lastRaw: "",
        lastRawAt: null,
        lastRawValue: null
      });
      this._pendingReading = null;
      this._nextStableReadingStartsNewIdentity = false;
      this.emitCurrentState();
    }

    return this.state;
  }

  setCallbacks(callbacks = {}, emitCurrent = true) {
    ["onReading", "onStatus", "onRaw"].forEach((name) => {
      if (Object.prototype.hasOwnProperty.call(callbacks, name)) {
        this._callbacks[name] = typeof callbacks[name] === "function" ? callbacks[name] : null;
      }
    });

    if (emitCurrent) {
      this.emitCurrentState();
    }
  }

  emitCurrentState() {
    this._emitStatus();
    this._emitReading();
    if (this._state.lastRaw) {
      this._emitRaw();
    }
    return this.state;
  }

  configureSerial(options = {}) {
    this._assertActive();
    const normalized = normalizeRetailSerialOptions(options, this._state.serialOptions);
    const changed = Object.keys(RETAIL_SCALE_SERIAL_DEFAULTS)
      .some((key) => normalized[key] !== this._state.serialOptions[key]);
    if (changed && this._connection?.mode === "serial") {
      throw new Error("Desconecta la balanza serial antes de cambiar sus parámetros de comunicación.");
    }

    this._state.serialOptions = normalized;
    this._persist(true);
    this._emitStatus();
    return { ...this._state.serialOptions };
  }

  setManualReading(value, options = {}) {
    this._assertActive();
    const weightKg = manualWeightToKg(value);
    if (!Number.isFinite(weightKg) || weightKg <= 0) {
      throw new RangeError("La lectura manual debe ser un peso válido mayor que cero.");
    }

    const rawText = options.rawText || `MANUAL ${weightKg.toFixed(3)} kg`;
    this._setRaw(rawText, "manual");
    this._commitStableReading(weightKg, {
      source: "manual",
      rawValue: String(value),
      rawFrame: rawText,
      forceNewReading: true
    });
    return this.state;
  }

  clearReading() {
    this._assertActive();
    this._invalidateReading("unknown", { clearStable: true });
    return this.state;
  }

  connectBle(options = {}) {
    this._autoReconnectEnabled = true;
    this._cancelReconnect();
    return this._enqueue(() => this._connectBle(options));
  }

  connectSerial(options = {}) {
    this._autoReconnectEnabled = true;
    this._cancelReconnect();
    return this._enqueue(() => this._connectSerial(options));
  }

  disconnect(options = {}) {
    return this._enqueue(async () => {
      this._assertActive();
      this._autoReconnectEnabled = false;
      this._cancelReconnect();
      const forget = Boolean(options.forget);
      await this._disconnectTransport({ forget, updateStatus: false });
      this._invalidateReading("unknown", { clearStable: true });

      this._setStatus("offline", forget
        ? "Balanza desconectada y preferencia eliminada."
        : "Balanza desconectada.", {
        connectionMode: null,
        lastError: null
      });
      this._persist(true);
      return true;
    });
  }

  forgetConnection() {
    return this.disconnect({ forget: true });
  }

  restoreAuthorizedConnection() {
    this._assertActive();
    this._autoReconnectEnabled = true;
    this._cancelReconnect();

    if (this._hasActiveTransport()) {
      return Promise.resolve(true);
    }

    // load, pageshow, visibilitychange y focus pueden llegar casi juntos. Una
    // sola restauración compartida evita abrir/cerrar el mismo transporte en
    // cadena y, sobre todo, evita que aparezca más de un intento simultáneo.
    if (this._restorePromise) {
      return this._restorePromise;
    }

    const restorePromise = this._enqueue(async () => {
      if (this._hasActiveTransport()) return true;
      const connected = await this._restoreAuthorizedConnection();
      if (!connected) this._scheduleReconnect();
      return connected;
    });
    const trackedRestorePromise = restorePromise.finally(() => {
      if (this._restorePromise === trackedRestorePromise) {
        this._restorePromise = null;
      }
    });
    this._restorePromise = trackedRestorePromise;
    return trackedRestorePromise;
  }

  restore() {
    return this.restoreAuthorizedConnection();
  }

  destroy() {
    if (this._destroyPromise) {
      return this._destroyPromise;
    }
    if (this._destroyed) {
      return Promise.resolve();
    }

    // pagehide no espera promesas. Se marca y se desconecta el GATT de forma
    // síncrona antes de cualquier await; el resto de la limpieza continúa en
    // segundo plano. La preferencia persistida no se elimina.
    this._destroyed = true;
    this._autoReconnectEnabled = false;
    this._cancelReconnect();
    const connection = this._connection;
    this._connection = null;
    this._connectionGeneration += 1;
    this._abortConnectionImmediately(connection);
    this._persist(true);
    this._navigator?.serial?.removeEventListener?.("connect", this._serialConnectHandler);
    this._navigator?.serial?.removeEventListener?.("disconnect", this._serialDisconnectHandler);
    this._callbacks = { onReading: null, onStatus: null, onRaw: null };

    const pendingOperations = this._operationQueue.catch(() => undefined);
    this._destroyPromise = pendingOperations.then(async () => {
      await this._releaseConnection(connection);
    });
    this._operationQueue = this._destroyPromise.catch(() => undefined);
    return this._destroyPromise;
  }

  _assertActive() {
    if (this._destroyed) {
      throw new Error("El controlador de balanza ya fue destruido.");
    }
  }

  _enqueue(operation) {
    const run = this._operationQueue.then(operation, operation);
    this._operationQueue = run.catch(() => undefined);
    return run;
  }

  _isCurrentConnection(connection) {
    return Boolean(connection)
      && !connection.abort
      && this._connection === connection
      && connection.generation === this._connectionGeneration;
  }

  _hasActiveTransport() {
    const connection = this._connection;
    if (!this._isCurrentConnection(connection)
      || this._state.status !== "connected"
      || this._state.connectionMode !== connection.mode) {
      return false;
    }

    if (connection.mode === "ble") {
      return connection.device?.gatt?.connected !== false;
    }

    if (connection.mode === "serial"
      && connection.port
      && "readable" in connection.port
      && connection.port.readable === null) {
      return false;
    }

    return true;
  }

  _cancelReconnect() {
    if (this._reconnectTimer) {
      globalThis.clearTimeout(this._reconnectTimer);
      this._reconnectTimer = null;
    }
  }

  _scheduleReconnect(delayOverride = null) {
    if (this._destroyed
      || !this._autoReconnectEnabled
      || !this._state.autoConnectMode
      || this._connection
      || this._reconnectTimer) {
      return;
    }

    const delay = Number.isFinite(delayOverride)
      ? Math.max(Number(delayOverride), 0)
      : RECONNECT_BACKOFF_MS[Math.min(this._reconnectAttempt, RECONNECT_BACKOFF_MS.length - 1)];
    this._reconnectAttempt += 1;
    this._reconnectTimer = globalThis.setTimeout(() => {
      this._reconnectTimer = null;
      void this._enqueue(async () => {
        if (this._destroyed || !this._autoReconnectEnabled || this._connection) return false;
        const connected = await this._restoreAuthorizedConnection();
        if (!connected) this._scheduleReconnect();
        return connected;
      });
    }, delay);
  }

  _markConnectionReady(connection) {
    if (!this._isCurrentConnection(connection)) return;
    this._cancelReconnect();
    this._reconnectAttempt = 0;
    this._startReadingWatchdog(connection);
  }

  _startReadingWatchdog(connection) {
    if (!this._isCurrentConnection(connection)) return;
    if (connection.watchdogTimer) globalThis.clearInterval(connection.watchdogTimer);

    connection.watchdogTimer = globalThis.setInterval(() => {
      if (!this._isCurrentConnection(connection)) return;
      if (this._state.readingSource === "manual") return;
      const readingAt = Date.parse(this._state.readingAt || "");
      const readingBelongsToConnection = this._state.readingSource === connection.mode;
      const waitingForFirstReading = !readingBelongsToConnection || !Number.isFinite(readingAt);
      const expired = waitingForFirstReading
        ? Date.now() - connection.connectedAt > READING_FRESHNESS_MS
        : Date.now() - readingAt > READING_FRESHNESS_MS;
      if (expired && (this._state.currentWeightKg !== null || this._state.readingStatus !== "stale")) {
        this._invalidateReading("stale", { clearStable: true });
      }
    }, READING_WATCHDOG_INTERVAL_MS);
  }

  _loadPersistedState() {
    try {
      const stored = this._storage?.getItem?.(this._storageKey);
      return normalizePersistedState(stored ? JSON.parse(stored) : null);
    } catch {
      return normalizePersistedState(null);
    }
  }

  _persist(force = false) {
    if (!this._storage?.setItem) {
      return;
    }

    const now = Date.now();
    if (!force && now - this._lastPersistedAt < PERSIST_INTERVAL_MS) {
      if (!this._persistTimer) {
        const delay = Math.max(PERSIST_INTERVAL_MS - (now - this._lastPersistedAt), 0);
        this._persistTimer = globalThis.setTimeout(() => {
          this._persistTimer = null;
          this._persist(true);
        }, delay);
      }
      return;
    }

    if (this._persistTimer) {
      globalThis.clearTimeout(this._persistTimer);
      this._persistTimer = null;
    }

    const persisted = {
      version: 2,
      autoConnectMode: this._state.autoConnectMode,
      deviceName: this._state.deviceName,
      bleDeviceId: this._state.bleDeviceId,
      profileId: this._state.profileId,
      profileLabel: this._state.profileLabel,
      serialPortInfo: this._state.serialPortInfo,
      serialOptions: this._state.serialOptions
    };

    try {
      this._storage.setItem(this._storageKey, JSON.stringify(persisted));
      this._lastPersistedAt = now;
    } catch {
      // La conexión continúa aunque el navegador no permita almacenamiento.
    }
  }

  _setStatus(status, message, changes = {}) {
    this._state.status = CONNECTION_STATUSES.has(status) ? status : "offline";
    this._state.statusMessage = String(message || "");

    if (Object.prototype.hasOwnProperty.call(changes, "connectionMode")) {
      this._state.connectionMode = changes.connectionMode;
    }
    if (Object.prototype.hasOwnProperty.call(changes, "deviceName")) {
      this._state.deviceName = String(changes.deviceName || "");
    }
    if (Object.prototype.hasOwnProperty.call(changes, "profileId")) {
      this._state.profileId = String(changes.profileId || "");
    }
    if (Object.prototype.hasOwnProperty.call(changes, "profileLabel")) {
      this._state.profileLabel = String(changes.profileLabel || "");
    }
    if (Object.prototype.hasOwnProperty.call(changes, "lastError")) {
      this._state.lastError = changes.lastError ? String(changes.lastError) : null;
    }

    this._emitStatus();
  }

  _emitStatus() {
    const state = this.getState();
    invokeCallback(this._callbacks.onStatus, {
      status: state.status,
      message: state.statusMessage,
      mode: state.connectionMode,
      deviceName: state.deviceName,
      profileId: state.profileId || null,
      profileLabel: state.profileLabel || null,
      error: state.lastError,
      state
    });
  }

  _emitReading(rawValue = null) {
    const state = this.getState();
    invokeCallback(this._callbacks.onReading, {
      weightKg: state.currentWeightKg,
      source: state.readingSource,
      updatedAt: state.updatedAt,
      readingAt: state.readingAt,
      readingId: state.readingId,
      readingStatus: state.readingStatus,
      inputStatus: state.inputStatus,
      isStable: state.isStable,
      isFresh: state.isFresh,
      isCaptureReady: state.isCaptureReady,
      rawValue: rawValue ?? state.lastRawValue,
      state
    });
  }

  _emitRaw(source = this._state.readingSource) {
    const state = this.getState();
    invokeCallback(this._callbacks.onRaw, {
      raw: state.lastRaw,
      source,
      receivedAt: state.lastRawAt,
      state
    });
  }

  _setRaw(rawText, source) {
    const sanitized = sanitizeRawText(rawText);
    if (!sanitized) {
      return;
    }

    this._state.lastRaw = sanitized;
    this._state.lastRawAt = new Date().toISOString();
    this._emitRaw(source);
  }

  _invalidateReading(status = "unknown", options = {}) {
    const clearStable = Boolean(options.clearStable);
    this._pendingReading = null;
    this._state.inputStatus = status;

    if (!clearStable && status === "unstable" && this._state.readingId) {
      this._nextStableReadingStartsNewIdentity = true;
    }

    if (clearStable) {
      this._nextStableReadingStartsNewIdentity = false;
      this._state.currentWeightKg = null;
      this._state.updatedAt = null;
      this._state.readingAt = null;
      this._state.readingSource = null;
      this._state.readingStatus = status;
      this._state.readingId = null;
      this._state.isStable = false;
      this._state.readingRaw = "";
      this._state.lastRawValue = null;
    }

    this._emitReading();
  }

  _commitStableReading(weightKg, options = {}) {
    const normalizedWeight = roundWeight(Number(weightKg));
    if (!Number.isFinite(normalizedWeight) || normalizedWeight < 0) {
      return false;
    }

    const source = options.source || null;
    const sameReading = !options.forceNewReading
      && !this._nextStableReadingStartsNewIdentity
      && this._state.readingId
      && this._state.readingSource === source
      && Number.isFinite(this._state.currentWeightKg)
      && Math.abs(this._state.currentWeightKg - normalizedWeight) <= WEIGHT_STABILITY_TOLERANCE_KG;
    const now = new Date().toISOString();

    this._state.currentWeightKg = normalizedWeight;
    this._state.updatedAt = now;
    this._state.readingAt = now;
    this._state.readingSource = source;
    this._state.readingStatus = "stable";
    this._state.inputStatus = "stable";
    this._state.isStable = true;
    this._state.readingRaw = sanitizeRawText(options.rawFrame || this._state.lastRaw);
    this._state.readingId = sameReading
      ? this._state.readingId
      : `${source || "reading"}-${Date.now()}-${++this._readingSequence}`;
    this._state.lastRawValue = options.rawValue ?? null;
    this._state.lastError = null;
    this._pendingReading = null;
    this._nextStableReadingStartsNewIdentity = false;
    this._emitReading(options.rawValue ?? null);
    return true;
  }

  _acceptReadingCandidate(weightKg, options = {}) {
    const normalizedWeight = roundWeight(Number(weightKg));
    if (!Number.isFinite(normalizedWeight) || normalizedWeight < 0) {
      this._invalidateReading("invalid", { clearStable: false });
      return false;
    }

    const now = Date.now();
    const source = options.source || null;
    const matchesCommittedReading = Boolean(this._state.readingId)
      && this._state.isStable
      && this._state.inputStatus === "stable"
      && !this._nextStableReadingStartsNewIdentity
      && this._state.readingSource === source
      && Number.isFinite(this._state.currentWeightKg)
      && Math.abs(this._state.currentWeightKg - normalizedWeight) <= WEIGHT_STABILITY_TOLERANCE_KG;
    if (matchesCommittedReading) {
      return this._commitStableReading(normalizedWeight, options);
    }

    const pendingMatches = this._pendingReading
      && this._pendingReading.source === source
      && Math.abs(this._pendingReading.weightKg - normalizedWeight) <= WEIGHT_STABILITY_TOLERANCE_KG;

    if (pendingMatches) {
      this._pendingReading.count += 1;
      this._pendingReading.lastAt = now;
    } else {
      this._pendingReading = {
        weightKg: normalizedWeight,
        source,
        count: 1,
        firstAt: now,
        lastAt: now
      };
    }

    const isZero = normalizedWeight === 0;
    const zeroConfirmed = isZero
      && this._pendingReading.count >= ZERO_CONFIRMATION_COUNT
      && now - this._pendingReading.firstAt >= ZERO_CONFIRMATION_MIN_MS;
    const nonZeroConfirmed = !isZero
      && (options.stableHint === true || this._pendingReading.count >= STABLE_CONFIRMATION_COUNT);

    if (!zeroConfirmed && !nonZeroConfirmed) {
      this._state.inputStatus = "unstable";
      this._emitReading(options.rawValue ?? null);
      return false;
    }

    return this._commitStableReading(normalizedWeight, options);
  }

  _handlePayload(payload, options = {}) {
    if (options.connection && !this._isCurrentConnection(options.connection)) {
      return false;
    }

    const parsed = parseRetailScalePayload(payload, options.parser || "text");
    this._setRaw(parsed.rawText, options.source);

    if (options.connection && !this._isCurrentConnection(options.connection)) {
      return false;
    }

    if (parsed.rejectionReason || !Number.isFinite(parsed.weightKg) || parsed.weightKg < 0) {
      const status = parsed.rejectionReason === "unstable" ? "unstable" : "invalid";
      this._invalidateReading(status, { clearStable: false });
      return false;
    }

    return this._acceptReadingCandidate(parsed.weightKg, {
      source: options.source,
      rawValue: parsed.rawValue,
      rawFrame: parsed.rawText,
      stableHint: parsed.stableHint
    });
  }

  async _connectBle(options = {}) {
    this._assertActive();
    if (!this._secureContext) {
      this._setStatus("error", "Para usar Bluetooth abre la aplicación en localhost o HTTPS.", {
        connectionMode: null,
        lastError: "Contexto no seguro"
      });
      return false;
    }

    const bluetooth = this._navigator?.bluetooth;
    if (!bluetooth) {
      this._setStatus("error", "Este navegador no soporta Web Bluetooth BLE.", {
        connectionMode: null,
        lastError: "Web Bluetooth no disponible"
      });
      return false;
    }

    await this._disconnectTransport({ forget: false, updateStatus: false });
    this._invalidateReading("unknown", { clearStable: true });
    const restoring = Boolean(options.restoring);
    this._setStatus("connecting", restoring
      ? "Restaurando conexión BLE..."
      : "Selecciona la balanza Bluetooth...", {
      connectionMode: "ble",
      lastError: null
    });

    let connection = null;
    try {
      const device = options.device || await bluetooth.requestDevice({
        acceptAllDevices: true,
        optionalServices: RETAIL_SCALE_BLE_SERVICE_UUIDS
      });
      this._assertActive();
      const deviceName = device?.name || "BLE sin nombre";
      if (!device?.gatt) {
        throw new Error("El dispositivo seleccionado no expone una conexión GATT.");
      }

      connection = {
        mode: "ble",
        generation: ++this._connectionGeneration,
        device,
        deviceName,
        abort: false,
        connectedAt: Date.now(),
        pollingTimer: null,
        watchdogTimer: null,
        isPolling: false,
        notifyActive: false,
        decoder: new TextDecoder("utf-8"),
        buffer: "",
        discardUntilDelimiter: false
      };
      this._connection = connection;
      connection.disconnectHandler = () => {
        this._handleUnexpectedDisconnect(connection, "La balanza BLE se desconectó.");
      };
      device.addEventListener?.("gattserverdisconnected", connection.disconnectHandler);

      this._setStatus("connecting", `Conectando a ${deviceName}...`, {
        connectionMode: "ble",
        deviceName,
        lastError: null
      });

      const server = await device.gatt.connect();
      if (connection.abort || this._connection !== connection) {
        return false;
      }

      const found = await this._findBleCharacteristic(server);
      if (!this._isCurrentConnection(connection)) return false;
      if (!found) {
        throw new Error("No se encontró una característica BLE compatible con la balanza.");
      }

      connection.server = server;
      connection.characteristic = found.characteristic;
      connection.canRead = found.canRead;
      connection.parser = found.profile.parser;
      connection.profileId = found.profile.id;
      connection.profileLabel = found.profile.label;
      connection.valueHandler = (event) => {
        this._handleBleValue(connection, event.target.value, found.profile.parser);
      };

      if (found.canNotify) {
        found.characteristic.addEventListener?.("characteristicvaluechanged", connection.valueHandler);
        await found.characteristic.startNotifications();
        if (!this._isCurrentConnection(connection)) return false;
        connection.notifyActive = true;
      }

      if (found.canRead) {
        const value = await found.characteristic.readValue();
        if (!this._isCurrentConnection(connection)) return false;
        this._handleBleValue(connection, value, found.profile.parser);
      }

      if (!found.canNotify && found.canRead) {
        this._startBlePolling(connection);
      }

      this._state.autoConnectMode = "ble";
      this._state.bleDeviceId = String(device.id || "");
      this._state.serialPortInfo = null;
      this._state.deviceName = deviceName;
      this._state.profileId = found.profile.id;
      this._state.profileLabel = found.profile.label;
      this._persist(true);
      this._setStatus("connected", `Conectada por BLE · ${found.profile.label}`, {
        connectionMode: "ble",
        deviceName,
        profileId: found.profile.id,
        profileLabel: found.profile.label,
        lastError: null
      });
      this._markConnectionReady(connection);
      return true;
    } catch (error) {
      await this._disconnectTransport({ forget: false, updateStatus: false });
      if (this._destroyed) return false;
      const message = getConnectionErrorMessage(error, "No se pudo conectar la balanza por BLE.");
      this._setStatus("error", message, {
        connectionMode: null,
        lastError: message
      });
      if (restoring) this._scheduleReconnect();
      return false;
    }
  }

  async _findBleCharacteristic(server) {
    for (const profile of RETAIL_SCALE_BLE_PROFILES) {
      let service = null;
      try {
        service = await server.getPrimaryService(profile.service);
      } catch {
        continue;
      }

      for (const characteristicUuid of profile.characteristics) {
        try {
          const characteristic = await service.getCharacteristic(characteristicUuid);
          const canNotify = Boolean(characteristic.properties?.notify || characteristic.properties?.indicate);
          const canRead = Boolean(characteristic.properties?.read);
          if (canNotify || canRead) {
            return { profile, characteristic, canNotify, canRead };
          }
        } catch {
          // Se prueba el siguiente UUID del perfil.
        }
      }
    }

    return null;
  }

  _startBlePolling(connection) {
    this._stopConnectionTimers(connection);
    connection.pollingTimer = globalThis.setInterval(async () => {
      if (connection.abort
        || this._connection !== connection
        || connection.isPolling
        || !connection.characteristic) {
        return;
      }

      connection.isPolling = true;
      try {
        const value = await connection.characteristic.readValue();
        if (!this._isCurrentConnection(connection)) return;
        this._handleBleValue(connection, value, connection.parser);
      } catch (error) {
        this._handleUnexpectedDisconnect(
          connection,
          getConnectionErrorMessage(error, "No se pudo leer la balanza BLE.")
        );
      } finally {
        connection.isPolling = false;
      }
    }, BLE_READ_INTERVAL_MS);
  }

  async _connectSerial(options = {}) {
    this._assertActive();
    if (!this._secureContext) {
      this._setStatus("error", "Para usar el puerto serial abre la aplicación en localhost o HTTPS.", {
        connectionMode: null,
        lastError: "Contexto no seguro"
      });
      return false;
    }

    const serial = this._navigator?.serial;
    if (!serial) {
      this._setStatus("error", "Este navegador no soporta Web Serial.", {
        connectionMode: null,
        lastError: "Web Serial no disponible"
      });
      return false;
    }

    let serialOptions;
    try {
      serialOptions = normalizeRetailSerialOptions(
        options.serialOptions || options,
        this._state.serialOptions
      );
    } catch (error) {
      this._setStatus("error", error.message, {
        connectionMode: null,
        lastError: error.message
      });
      return false;
    }

    await this._disconnectTransport({ forget: false, updateStatus: false });
    this._invalidateReading("unknown", { clearStable: true });
    const restoring = Boolean(options.restoring);
    this._state.serialOptions = serialOptions;
    this._setStatus("connecting", restoring
      ? "Restaurando puerto serial de la balanza..."
      : "Selecciona el puerto serial de la balanza...", {
      connectionMode: "serial",
      lastError: null
    });

    let connection = null;
    try {
      const port = options.port || await serial.requestPort();
      this._assertActive();
      await port.open(serialOptions);
      if (this._destroyed) {
        try {
          await port.close();
        } catch {
          // La página ya está saliendo y el navegador puede haberlo cerrado.
        }
        return false;
      }
      connection = {
        mode: "serial",
        generation: ++this._connectionGeneration,
        port,
        deviceName: "Puerto serial de balanza",
        abort: false,
        connectedAt: Date.now(),
        reader: null,
        readPromise: null,
        decoder: new TextDecoder("utf-8"),
        buffer: "",
        discardUntilDelimiter: false,
        watchdogTimer: null
      };
      this._connection = connection;
      await this._rememberSerialPort(port);

      this._state.autoConnectMode = "serial";
      this._state.bleDeviceId = "";
      this._state.profileId = "web-serial";
      this._state.profileLabel = `${serialOptions.baudRate} baudios`;
      this._state.deviceName = connection.deviceName;
      this._persist(true);
      this._setStatus("connected", `Conectada por puerto serial · ${serialOptions.baudRate} baudios`, {
        connectionMode: "serial",
        deviceName: connection.deviceName,
        profileId: "web-serial",
        profileLabel: `${serialOptions.baudRate} baudios`,
        lastError: null
      });

      connection.readPromise = this._readSerial(connection);
      this._markConnectionReady(connection);
      return true;
    } catch (error) {
      await this._disconnectTransport({ forget: false, updateStatus: false });
      if (this._destroyed) return false;
      const message = getConnectionErrorMessage(error, "No se pudo abrir el puerto serial de la balanza.");
      this._setStatus("error", message, {
        connectionMode: null,
        lastError: message
      });
      if (restoring) this._scheduleReconnect();
      return false;
    }
  }

  async _rememberSerialPort(port) {
    let authorizedPorts = [];
    try {
      authorizedPorts = await this._navigator?.serial?.getPorts?.() || [];
    } catch {
      authorizedPorts = [];
    }

    const portInfo = getSerialPortInfo(port) || {
      usbVendorId: null,
      usbProductId: null,
      bluetoothServiceClassId: null,
      matchIndex: null,
      portIndex: null
    };
    const matchingPorts = authorizedPorts.filter((candidate) => {
      return serialPortIdentifiersMatch(getSerialPortInfo(candidate), portInfo);
    });
    const rememberedMatchIndex = matchingPorts.findIndex((candidate) => candidate === port);
    const rememberedPortIndex = authorizedPorts.findIndex((candidate) => candidate === port);

    this._state.serialPortInfo = normalizeSerialPortInfo({
      ...portInfo,
      matchIndex: rememberedMatchIndex >= 0 ? rememberedMatchIndex : null,
      portIndex: rememberedPortIndex >= 0 ? rememberedPortIndex : null
    });
  }

  _handleSerialChunk(connection, value) {
    this._handleTextChunk(connection, value, {
      source: "serial"
    });
  }

  _handleBleValue(connection, value, parser = "text") {
    if (!this._isCurrentConnection(connection)) return false;

    if (parser === "sig-weight") {
      return this._handlePayload(value, {
        parser,
        source: "ble",
        connection
      });
    }

    this._handleTextChunk(connection, value, {
      source: "ble"
    });
    return true;
  }

  _handleTextChunk(connection, value, options = {}) {
    if (!this._isCurrentConnection(connection)) return;
    const bytes = toBytes(value);
    let text = "";
    try {
      text = connection.decoder.decode(bytes, { stream: true });
    } catch {
      text = "";
    }

    if (!text) {
      return;
    }

    let remaining = text;
    const nextDelimiter = (source) => source.match(/\r\n|\r|\n/);
    const reportOversizedFrame = (frame) => {
      const discarded = String(frame || "").slice(-MAX_RAW_LENGTH);
      this._setRaw(
        `[Trama ${options.source || "text"} sin cierre descartada] ${discarded}`,
        options.source
      );
      this._invalidateReading("invalid", { clearStable: false });
    };

    if (connection.discardUntilDelimiter) {
      const delimiter = nextDelimiter(remaining);
      if (!delimiter) return;
      connection.discardUntilDelimiter = false;
      remaining = remaining.slice(delimiter.index + delimiter[0].length);
    }

    while (remaining && this._isCurrentConnection(connection)) {
      const delimiter = nextDelimiter(remaining);
      if (!delimiter) {
        connection.buffer = `${connection.buffer}${remaining}`;
        if (connection.buffer.length > MAX_SERIAL_BUFFER_LENGTH) {
          reportOversizedFrame(connection.buffer);
          connection.buffer = "";
          connection.discardUntilDelimiter = true;
        }
        return;
      }

      const frame = `${connection.buffer}${remaining.slice(0, delimiter.index)}`;
      connection.buffer = "";
      remaining = remaining.slice(delimiter.index + delimiter[0].length);

      if (frame.length > MAX_SERIAL_BUFFER_LENGTH) {
        reportOversizedFrame(frame);
      } else if (frame.trim()) {
        this._handlePayload(frame, {
          parser: "text",
          source: options.source,
          connection
        });
      }
    }

  }

  async _readSerial(connection) {
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
            if (value && this._isCurrentConnection(connection)) {
              this._handleSerialChunk(connection, value);
            }
          }
        } finally {
          try {
            reader.releaseLock();
          } catch {
            // El stream puede haber liberado el lock durante la desconexión.
          }
          if (connection.reader === reader) {
            connection.reader = null;
          }
        }
      }
    } catch (error) {
      if (!connection.abort && this._connection === connection) {
        this._handleUnexpectedDisconnect(
          connection,
          getConnectionErrorMessage(error, "La lectura serial de la balanza se interrumpió.")
        );
      }
      return;
    }

    if (!connection.abort && this._connection === connection) {
      this._handleUnexpectedDisconnect(connection, "El puerto serial de la balanza se cerró.");
    }
  }

  async _restoreAuthorizedConnection() {
    this._assertActive();
    if (this._hasActiveTransport()) {
      return true;
    }
    if (!this._state.autoConnectMode) {
      this._setStatus("offline", "No hay una conexión de balanza guardada.", {
        connectionMode: null,
        lastError: null
      });
      return false;
    }

    if (!this._secureContext) {
      this._autoReconnectEnabled = false;
      this._setStatus("error", "La conexión guardada requiere localhost o HTTPS.", {
        connectionMode: null,
        lastError: "Contexto no seguro"
      });
      return false;
    }

    if (this._state.autoConnectMode === "serial") {
      const serial = this._navigator?.serial;
      if (!serial?.getPorts) {
        this._autoReconnectEnabled = false;
        this._setStatus("offline", "El navegador no permite restaurar el puerto serial guardado.", {
          connectionMode: null,
          lastError: null
        });
        return false;
      }

      let ports = [];
      try {
        ports = await serial.getPorts();
      } catch (error) {
        const message = getConnectionErrorMessage(error, "No se pudieron consultar los puertos autorizados.");
        this._setStatus("error", message, { connectionMode: null, lastError: message });
        return false;
      }

      const port = this._findRememberedSerialPort(ports);
      if (!port) {
        if (this._serialRestoreRequiresManualSelection(ports)) {
          this._autoReconnectEnabled = false;
        }
        this._setStatus("offline", "No se pudo identificar de forma inequívoca el puerto guardado. Selecciónalo manualmente.", {
          connectionMode: null,
          lastError: null
        });
        return false;
      }

      return this._connectSerial({
        port,
        restoring: true,
        serialOptions: this._state.serialOptions
      });
    }

    const bluetooth = this._navigator?.bluetooth;
    if (!bluetooth?.getDevices) {
      this._autoReconnectEnabled = false;
      this._setStatus("offline", "La reconexión BLE automática no está disponible en este navegador. Pulsa Conectar BLE.", {
        connectionMode: null,
        lastError: null
      });
      return false;
    }

    let devices = [];
    try {
      devices = await bluetooth.getDevices();
    } catch (error) {
      const message = getConnectionErrorMessage(error, "No se pudieron consultar dispositivos BLE autorizados.");
      this._setStatus("error", message, { connectionMode: null, lastError: message });
      return false;
    }

    const device = this._state.bleDeviceId
      ? devices.find((candidate) => String(candidate.id || "") === this._state.bleDeviceId) || null
      : null;

    if (!device) {
      this._autoReconnectEnabled = false;
      this._setStatus("offline", "No se pudo identificar exactamente la balanza BLE guardada. Selecciónala manualmente.", {
        connectionMode: null,
        lastError: null
      });
      return false;
    }

    return this._connectBle({ device, restoring: true });
  }

  _findRememberedSerialPort(ports) {
    const savedInfo = this._state.serialPortInfo;
    if (!savedInfo) return null;

    const hasIdentifiers = savedInfo.usbVendorId !== null
      || savedInfo.usbProductId !== null
      || Boolean(savedInfo.bluetoothServiceClassId);

    if (hasIdentifiers) {
      const matches = ports.filter((port) => {
        return serialPortIdentifiersMatch(getSerialPortInfo(port), savedInfo);
      });
      if (matches.length === 1) {
        // Un ordinal mayor que cero demuestra que al guardar había más de un
        // adaptador idéntico. Si ahora solo queda uno, no se puede asegurar que
        // sea el mismo dispositivo físico.
        return Number.isInteger(savedInfo.matchIndex) && savedInfo.matchIndex > 0
          ? null
          : matches[0];
      }

      // Web Serial no entrega un id físico para diferenciar dos adaptadores
      // USB/RFCOMM con la misma información. Para conexiones nuevas guardamos
      // tanto su posición entre los iguales como su posición global. Solo se
      // restaura si ambas rutas siguen apuntando al mismo puerto. Los recuerdos
      // antiguos que no tienen estos índices permanecen ambiguos y requieren
      // una nueva selección manual.
      const hasRememberedIndexes = Number.isInteger(savedInfo.matchIndex)
        && savedInfo.matchIndex >= 0
        && Number.isInteger(savedInfo.portIndex)
        && savedInfo.portIndex >= 0;
      if (!hasRememberedIndexes || matches.length === 0) return null;

      const matchCandidate = matches[savedInfo.matchIndex] || null;
      const portCandidate = ports[savedInfo.portIndex] || null;
      return matchCandidate && matchCandidate === portCandidate ? matchCandidate : null;
    }

    // Sin ningún identificador solo un puerto autorizado es inequívoco.
    return ports.length === 1 ? ports[0] : null;
  }

  _serialRestoreRequiresManualSelection(ports) {
    const savedInfo = this._state.serialPortInfo;
    if (!savedInfo) return true;

    const hasIdentifiers = savedInfo.usbVendorId !== null
      || savedInfo.usbProductId !== null
      || Boolean(savedInfo.bluetoothServiceClassId);
    if (!hasIdentifiers) return ports.length > 1;

    const matches = ports.filter((port) => {
      return serialPortIdentifiersMatch(getSerialPortInfo(port), savedInfo);
    });
    if (Number.isInteger(savedInfo.matchIndex)
      && savedInfo.matchIndex > 0
      && matches.length > 0
      && matches.length <= savedInfo.matchIndex) {
      return true;
    }
    // Cero coincidencias normalmente significa que la balanza está apagada o
    // desconectada: se conserva el reintento y el evento serial "connect".
    return matches.length > 1;
  }

  _handleUnexpectedDisconnect(connection, message) {
    if (!this._isCurrentConnection(connection)) {
      return;
    }

    this._connection = null;
    this._connectionGeneration += 1;
    connection.abort = true;
    this._stopConnectionTimers(connection);
    this._invalidateReading("stale", { clearStable: true });
    this._setStatus("error", message, {
      connectionMode: null,
      lastError: message
    });
    void Promise.resolve()
      .then(() => this._releaseConnection(connection))
      .finally(() => this._scheduleReconnect());
  }

  _stopConnectionTimers(connection) {
    if (connection?.pollingTimer) {
      globalThis.clearInterval(connection.pollingTimer);
      connection.pollingTimer = null;
    }
    if (connection?.watchdogTimer) {
      globalThis.clearInterval(connection.watchdogTimer);
      connection.watchdogTimer = null;
    }
  }

  _abortConnectionImmediately(connection) {
    if (!connection) return;

    connection.abort = true;
    connection.buffer = "";
    connection.discardUntilDelimiter = false;
    this._stopConnectionTimers(connection);

    if (connection.characteristic && connection.valueHandler) {
      try {
        connection.characteristic.removeEventListener?.("characteristicvaluechanged", connection.valueHandler);
      } catch {
        // El navegador puede haber descartado ya la característica.
      }
    }

    if (connection.device && connection.disconnectHandler) {
      try {
        connection.device.removeEventListener?.("gattserverdisconnected", connection.disconnectHandler);
      } catch {
        // El navegador puede haber descartado ya el dispositivo.
      }
    }

    // Es deliberadamente síncrono: pagehide no espera stopNotifications().
    if (connection.device?.gatt?.connected) {
      try {
        connection.device.gatt.disconnect();
      } catch {
        // Desconexión BLE de mejor esfuerzo.
      }
    }
    connection.notifyActive = false;

    if (connection.reader && !connection.readerCancelPromise) {
      try {
        connection.readerCancelPromise = Promise.resolve(connection.reader.cancel())
          .catch(() => undefined);
      } catch {
        connection.readerCancelPromise = Promise.resolve();
      }
    }
  }

  async _releaseConnection(connection) {
    if (connection) {
      this._abortConnectionImmediately(connection);

      if (connection.reader) {
        try {
          await (connection.readerCancelPromise || connection.reader.cancel());
        } catch {
          // El reader puede haberse cerrado solo.
        }
      }

      if (connection.readPromise) {
        try {
          await connection.readPromise;
        } catch {
          // El loop ya reporta sus errores mediante onStatus.
        }
      }

      if (connection.port) {
        try {
          await connection.port.close();
        } catch {
          // El puerto puede estar cerrado o liberándose.
        }
      }
    }
  }

  async _disconnectTransport(options = {}) {
    const connection = this._connection;
    this._connection = null;
    this._connectionGeneration += 1;
    await this._releaseConnection(connection);

    this._state.connectionMode = null;

    if (options.forget) {
      this._state.autoConnectMode = null;
      this._state.deviceName = "";
      this._state.bleDeviceId = "";
      this._state.profileId = "";
      this._state.profileLabel = "";
      this._state.serialPortInfo = null;
    }

    if (options.updateStatus) {
      this._setStatus("offline", "Balanza desconectada.", {
        connectionMode: null,
        lastError: null
      });
    }
  }
}

export { RetailScaleController as DespachoMinoristaBalanza };
export default RetailScaleController;
