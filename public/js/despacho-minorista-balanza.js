/**
 * Controlador de una balanza para el despacho minorista.
 *
 * No conoce ni modifica el DOM. La vista recibe cambios mediante los callbacks
 * `onReading`, `onStatus` y `onRaw`, o consultando la propiedad `state`.
 */

export const RETAIL_SCALE_STORAGE_KEY = "sistema-pollos-retail-scale-v1";

export const RETAIL_SCALE_SERIAL_DEFAULTS = Object.freeze({
  baudRate: 9600,
  dataBits: 8,
  stopBits: 1,
  parity: "none",
  flowControl: "none"
});

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
const SERIAL_FRAME_IDLE_MS = 140;
const SERIAL_DATA_BITS = new Set([7, 8]);
const SERIAL_STOP_BITS = new Set([1, 2]);
const SERIAL_PARITIES = new Set(["none", "even", "odd"]);
const SERIAL_FLOW_CONTROLS = new Set(["none", "hardware"]);
const CONNECTION_STATUSES = new Set(["offline", "connecting", "connected", "error"]);

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
export function parseIndustrialScaleText(rawText) {
  const text = String(rawText ?? "").replace(/\0/g, " ").trim();
  if (!text) {
    return null;
  }

  const matches = [];
  const pattern = /([+-]?\s*\d+(?:[.,]\d+)?)\s*(kg|kgs|kilogramo|kilogramos|lb|lbs|libra|libras|g|gr|gramo|gramos)?/gi;
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
      const score = (hasUnit ? 10 : 0)
        + (hasDecimal ? 4 : 0)
        + (hasSign ? 2 : 0)
        + match.index / 100000;

      matches.push({
        weightKg,
        unit,
        sourceValue: numericValue,
        rawValue: match[0].trim(),
        score
      });
    }

    match = pattern.exec(text);
  }

  if (!matches.length) {
    return null;
  }

  const bestMatch = matches.sort((left, right) => right.score - left.score)[0];
  return {
    weightKg: roundWeight(bestMatch.weightKg),
    unit: bestMatch.unit,
    sourceValue: bestMatch.sourceValue,
    rawValue: bestMatch.rawValue
  };
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
    const reading = parseIndustrialScaleText(payload);
    return {
      rawText: payload,
      weightKg: reading?.weightKg ?? null,
      rawValue: reading?.rawValue ?? null
    };
  }

  const bytes = toBytes(payload);
  const decodedText = decodeBytes(bytes);
  const textReading = parseIndustrialScaleText(decodedText);

  if (textReading) {
    return {
      rawText: decodedText,
      weightKg: textReading.weightKg,
      rawValue: textReading.rawValue
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
  const matchIndex = Number(info.matchIndex);
  const portIndex = Number(info.portIndex);

  const normalized = {
    usbVendorId: Number.isInteger(usbVendorId) && usbVendorId >= 0 ? usbVendorId : null,
    usbProductId: Number.isInteger(usbProductId) && usbProductId >= 0 ? usbProductId : null,
    bluetoothServiceClassId: bluetoothServiceClassId || null,
    matchIndex: Number.isInteger(matchIndex) && matchIndex >= 0 ? matchIndex : 0,
    portIndex: Number.isInteger(portIndex) && portIndex >= 0 ? portIndex : 0
  };

  return normalized.usbVendorId !== null
    || normalized.usbProductId !== null
    || normalized.bluetoothServiceClassId
    || Number.isInteger(portIndex)
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
  const currentWeightKg = roundWeight(Number(source.currentWeightKg));
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
    currentWeightKg: Number.isFinite(currentWeightKg) && currentWeightKg >= 0 ? currentWeightKg : 0,
    updatedAt: source.updatedAt || null,
    readingSource: ["manual", "ble", "serial"].includes(source.readingSource)
      ? source.readingSource
      : null,
    lastRaw: sanitizeRawText(source.lastRaw),
    lastRawAt: source.lastRawAt || null,
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
    this._operationQueue = Promise.resolve();
    this._persistTimer = null;
    this._lastPersistedAt = 0;
    this._destroyed = false;

    const persisted = this._loadPersistedState();
    let serialOptions = persisted.serialOptions;
    if (options.serialOptions) {
      serialOptions = normalizeRetailSerialOptions(options.serialOptions, serialOptions);
    }

    this._state = {
      version: 1,
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
      currentWeightKg: persisted.currentWeightKg,
      updatedAt: persisted.updatedAt,
      readingSource: persisted.readingSource,
      lastRaw: persisted.lastRaw,
      lastRawAt: persisted.lastRawAt
    };
  }

  get state() {
    return this.getState();
  }

  getState() {
    const capabilities = this.getCapabilities();
    return {
      ...this._state,
      isConnected: this._state.status === "connected",
      isConnecting: this._state.status === "connecting",
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

    this._persist(true);
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
        currentWeightKg: persisted.currentWeightKg,
        updatedAt: persisted.updatedAt,
        readingSource: persisted.readingSource,
        lastRaw: persisted.lastRaw,
        lastRawAt: persisted.lastRawAt
      });
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
    if (this._state.updatedAt) {
      this._emitReading();
    }
    if (this._state.lastRaw) {
      this._emitRaw();
    }
    return this.state;
  }

  configureSerial(options = {}) {
    this._assertActive();
    this._state.serialOptions = normalizeRetailSerialOptions(options, this._state.serialOptions);
    this._persist(true);
    this._emitStatus();
    return { ...this._state.serialOptions };
  }

  setManualReading(value, options = {}) {
    this._assertActive();
    const weightKg = manualWeightToKg(value);
    if (!Number.isFinite(weightKg)) {
      throw new RangeError("La lectura manual debe ser un peso válido mayor o igual que cero.");
    }

    const rawText = options.rawText || `MANUAL ${weightKg.toFixed(3)} kg`;
    this._setRaw(rawText, "manual");
    this._setReading(weightKg, {
      source: "manual",
      rawValue: String(value),
      persistImmediately: true
    });
    return this.state;
  }

  clearReading() {
    this._assertActive();
    this._state.currentWeightKg = 0;
    this._state.updatedAt = new Date().toISOString();
    this._state.readingSource = null;
    this._persist(true);
    this._emitReading();
    return this.state;
  }

  connectBle(options = {}) {
    return this._enqueue(() => this._connectBle(options));
  }

  connectSerial(options = {}) {
    return this._enqueue(() => this._connectSerial(options));
  }

  disconnect(options = {}) {
    return this._enqueue(async () => {
      this._assertActive();
      const forget = Boolean(options.forget);
      const clearReading = Boolean(options.clearReading);
      await this._disconnectTransport({ forget, updateStatus: false });

      if (clearReading) {
        this._state.currentWeightKg = 0;
        this._state.updatedAt = null;
        this._state.readingSource = null;
      }

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
    return this._enqueue(() => this._restoreAuthorizedConnection());
  }

  restore() {
    return this.restoreAuthorizedConnection();
  }

  async destroy() {
    if (this._destroyed) {
      return;
    }

    await this._enqueue(async () => {
      await this._disconnectTransport({ forget: false, updateStatus: false });
      this._persist(true);
      this._destroyed = true;
      this._callbacks = { onReading: null, onStatus: null, onRaw: null };
    });
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
      version: 1,
      currentWeightKg: this._state.currentWeightKg,
      updatedAt: this._state.updatedAt,
      readingSource: this._state.readingSource,
      lastRaw: this._state.lastRaw,
      lastRawAt: this._state.lastRawAt,
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
      rawValue,
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
    this._persist();
    this._emitRaw(source);
  }

  _setReading(weightKg, options = {}) {
    const normalizedWeight = roundWeight(Number(weightKg));
    if (!Number.isFinite(normalizedWeight) || normalizedWeight < 0) {
      return false;
    }

    this._state.currentWeightKg = normalizedWeight;
    this._state.updatedAt = new Date().toISOString();
    this._state.readingSource = options.source || null;
    this._state.lastError = null;
    this._persist(Boolean(options.persistImmediately));
    this._emitReading(options.rawValue ?? null);
    return true;
  }

  _handlePayload(payload, options = {}) {
    const parsed = parseRetailScalePayload(payload, options.parser || "text");
    this._setRaw(parsed.rawText, options.source);

    if (!Number.isFinite(parsed.weightKg)) {
      return false;
    }

    return this._setReading(Math.max(parsed.weightKg, 0), {
      source: options.source,
      rawValue: parsed.rawValue
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
      const deviceName = device?.name || "BLE sin nombre";
      if (!device?.gatt) {
        throw new Error("El dispositivo seleccionado no expone una conexión GATT.");
      }

      connection = {
        mode: "ble",
        device,
        deviceName,
        abort: false,
        pollingTimer: null,
        isPolling: false,
        notifyActive: false
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
        this._handlePayload(event.target.value, {
          parser: found.profile.parser,
          source: "ble"
        });
      };

      if (found.canNotify) {
        found.characteristic.addEventListener?.("characteristicvaluechanged", connection.valueHandler);
        await found.characteristic.startNotifications();
        connection.notifyActive = true;
      }

      if (found.canRead) {
        const value = await found.characteristic.readValue();
        this._handlePayload(value, { parser: found.profile.parser, source: "ble" });
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
      return true;
    } catch (error) {
      await this._disconnectTransport({ forget: false, updateStatus: false });
      const message = getConnectionErrorMessage(error, "No se pudo conectar la balanza por BLE.");
      this._setStatus("error", message, {
        connectionMode: null,
        lastError: message
      });
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
        this._handlePayload(value, { parser: connection.parser, source: "ble" });
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
      await port.open(serialOptions);
      connection = {
        mode: "serial",
        port,
        deviceName: "Puerto serial de balanza",
        abort: false,
        reader: null,
        readPromise: null,
        decoder: new TextDecoder("utf-8"),
        buffer: "",
        flushTimer: null
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
      return true;
    } catch (error) {
      await this._disconnectTransport({ forget: false, updateStatus: false });
      const message = getConnectionErrorMessage(error, "No se pudo abrir el puerto serial de la balanza.");
      this._setStatus("error", message, {
        connectionMode: null,
        lastError: message
      });
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
      matchIndex: 0,
      portIndex: 0
    };
    const matchingPorts = authorizedPorts.filter((candidate) => {
      return serialPortIdentifiersMatch(getSerialPortInfo(candidate), portInfo);
    });

    this._state.serialPortInfo = normalizeSerialPortInfo({
      ...portInfo,
      matchIndex: Math.max(matchingPorts.findIndex((candidate) => candidate === port), 0),
      portIndex: Math.max(authorizedPorts.findIndex((candidate) => candidate === port), 0)
    });
  }

  _handleSerialChunk(connection, value) {
    const bytes = toBytes(value);
    let text = "";
    try {
      text = connection.decoder.decode(bytes, { stream: true });
    } catch {
      text = "";
    }

    if (!text) {
      this._handlePayload(bytes, { parser: "text", source: "serial" });
      return;
    }

    if (connection.flushTimer) {
      globalThis.clearTimeout(connection.flushTimer);
      connection.flushTimer = null;
    }

    connection.buffer = `${connection.buffer}${text}`.slice(-MAX_SERIAL_BUFFER_LENGTH);
    const frames = connection.buffer.split(/\r\n|\r|\n/);

    if (frames.length > 1) {
      connection.buffer = frames.pop() || "";
      frames.forEach((frame) => {
        if (frame.trim()) {
          this._handlePayload(frame, { parser: "text", source: "serial" });
        }
      });
      this._scheduleSerialBufferFlush(connection);
      return;
    }

    this._scheduleSerialBufferFlush(connection);
  }

  _scheduleSerialBufferFlush(connection) {
    if (!connection.buffer.trim()) return;
    const pendingFrame = connection.buffer;
    connection.flushTimer = globalThis.setTimeout(() => {
      connection.flushTimer = null;
      if (connection.abort || this._connection !== connection || connection.buffer !== pendingFrame) return;
      connection.buffer = "";
      this._handlePayload(pendingFrame, { parser: "text", source: "serial" });
    }, SERIAL_FRAME_IDLE_MS);
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
            if (value) {
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
    if (!this._state.autoConnectMode) {
      this._setStatus("offline", "No hay una conexión de balanza guardada.", {
        connectionMode: null,
        lastError: null
      });
      return false;
    }

    if (!this._secureContext) {
      this._setStatus("error", "La conexión guardada requiere localhost o HTTPS.", {
        connectionMode: null,
        lastError: "Contexto no seguro"
      });
      return false;
    }

    if (this._state.autoConnectMode === "serial") {
      const serial = this._navigator?.serial;
      if (!serial?.getPorts) {
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
        this._setStatus("offline", "El puerto serial guardado no está autorizado en este navegador.", {
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
      this._setStatus("offline", "Para reconectar BLE pulsa Conectar; este navegador no expone dispositivos autorizados.", {
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

    const device = devices.find((candidate) => {
      return this._state.bleDeviceId && String(candidate.id || "") === this._state.bleDeviceId;
    }) || devices.find((candidate) => {
      return this._state.deviceName && candidate.name === this._state.deviceName;
    }) || (devices.length === 1 ? devices[0] : null);

    if (!device) {
      this._setStatus("offline", "La balanza BLE guardada no está autorizada en este navegador.", {
        connectionMode: null,
        lastError: null
      });
      return false;
    }

    return this._connectBle({ device, restoring: true });
  }

  _findRememberedSerialPort(ports) {
    const savedInfo = this._state.serialPortInfo;
    if (!savedInfo) {
      return ports.length === 1 ? ports[0] : null;
    }

    const hasIdentifiers = savedInfo.usbVendorId !== null
      || savedInfo.usbProductId !== null
      || Boolean(savedInfo.bluetoothServiceClassId);

    if (hasIdentifiers) {
      const matches = ports.filter((port) => {
        return serialPortIdentifiersMatch(getSerialPortInfo(port), savedInfo);
      });
      if (matches[savedInfo.matchIndex]) {
        return matches[savedInfo.matchIndex];
      }
      if (matches.length === 1) {
        return matches[0];
      }
    }

    return ports[savedInfo.portIndex] || (ports.length === 1 ? ports[0] : null);
  }

  _handleUnexpectedDisconnect(connection, message) {
    if (!connection || connection.abort || this._connection !== connection) {
      return;
    }

    connection.abort = true;
    this._stopConnectionTimers(connection);
    this._setStatus("error", message, {
      connectionMode: null,
      lastError: message
    });
  }

  _stopConnectionTimers(connection) {
    if (connection?.pollingTimer) {
      globalThis.clearInterval(connection.pollingTimer);
      connection.pollingTimer = null;
    }
    if (connection?.flushTimer) {
      globalThis.clearTimeout(connection.flushTimer);
      connection.flushTimer = null;
    }
  }

  async _disconnectTransport(options = {}) {
    const connection = this._connection;
    this._connection = null;

    if (connection) {
      connection.abort = true;
      this._stopConnectionTimers(connection);

      if (connection.characteristic && connection.valueHandler) {
        try {
          connection.characteristic.removeEventListener?.("characteristicvaluechanged", connection.valueHandler);
        } catch {
          // El dispositivo puede haberse desconectado antes de retirar el listener.
        }
      }

      if (connection.characteristic && connection.notifyActive) {
        try {
          await connection.characteristic.stopNotifications();
        } catch {
          // Desconexión BLE de mejor esfuerzo.
        }
      }

      if (connection.device && connection.disconnectHandler) {
        try {
          connection.device.removeEventListener?.("gattserverdisconnected", connection.disconnectHandler);
        } catch {
          // El navegador puede haber descartado el dispositivo.
        }
      }

      if (connection.device?.gatt?.connected) {
        try {
          connection.device.gatt.disconnect();
        } catch {
          // Desconexión BLE de mejor esfuerzo.
        }
      }

      if (connection.reader) {
        try {
          await connection.reader.cancel();
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
