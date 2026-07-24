import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import {
  buildRetailScaleStorageKey,
  RetailScaleController,
  migrateLegacyRetailScaleStorage,
  parseIndustrialScaleText,
  parseRetailScalePayload,
  RETAIL_SCALE_LEGACY_MIGRATION_MARKER_KEY,
  RETAIL_SCALE_STORAGE_KEY
} from "../../public/js/despacho-minorista-balanza.js";

const retailViewSource = readFileSync(
  new URL("../../public/js/despacho-minorista.js", import.meta.url),
  "utf8"
);
const retailBladeSource = readFileSync(
  new URL("../../resources/views/despacho-minorista.blade.php", import.meta.url),
  "utf8"
);

function memoryStorage(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    getItem(key) {
      return values.has(key) ? values.get(key) : null;
    },
    setItem(key, value) {
      values.set(key, String(value));
    },
    removeItem(key) {
      values.delete(key);
    }
  };
}

function controllerWithReadings() {
  const readings = [];
  const controller = new RetailScaleController({
    navigator: {},
    storage: memoryStorage(),
    secureContext: true,
    onReading(payload) {
      readings.push(payload);
    }
  });
  return { controller, readings };
}

function createMockBleDevice(options = {}) {
  const listeners = new Map();
  const characteristic = {
    properties: { notify: true, indicate: false, read: false },
    addEventListener(type, listener) {
      listeners.set(`characteristic:${type}`, listener);
    },
    removeEventListener(type, listener) {
      if (listeners.get(`characteristic:${type}`) === listener) {
        listeners.delete(`characteristic:${type}`);
      }
    },
    async startNotifications() {
      return characteristic;
    }
  };
  const service = {
    async getCharacteristic() {
      return characteristic;
    }
  };
  const server = {
    async getPrimaryService(uuid) {
      if (uuid !== "0000181d-0000-1000-8000-00805f9b34fb") {
        throw new Error("Servicio no disponible");
      }
      return service;
    }
  };
  const metrics = { connectCalls: 0, disconnectCalls: 0 };
  const gatt = {
    connected: false,
    async connect() {
      metrics.connectCalls += 1;
      gatt.connected = true;
      return server;
    },
    disconnect() {
      metrics.disconnectCalls += 1;
      gatt.connected = false;
    }
  };
  const device = {
    id: options.id || "ble-retail-1",
    name: options.name || "Balanza BLE minorista",
    gatt,
    addEventListener(type, listener) {
      listeners.set(`device:${type}`, listener);
    },
    removeEventListener(type, listener) {
      if (listeners.get(`device:${type}`) === listener) {
        listeners.delete(`device:${type}`);
      }
    }
  };
  return { device, metrics };
}

function attachSerialConnection(controller) {
  const connection = {
    mode: "serial",
    generation: controller._connectionGeneration + 1,
    abort: false,
    connectedAt: Date.now(),
    decoder: new TextDecoder("utf-8"),
    buffer: "",
    discardUntilDelimiter: false,
    watchdogTimer: null,
    pollingTimer: null
  };
  controller._connectionGeneration = connection.generation;
  controller._connection = connection;
  controller._setStatus("connected", "Serial de prueba", {
    connectionMode: "serial",
    lastError: null
  });
  return connection;
}

test("parser prioriza NET/GROSS, ignora TARE y filtra errores y negativos", () => {
  assert.equal(parseIndustrialScaleText("ST,NET 12.500 kg TARE 0.000 kg")?.weightKg, 12.5);
  assert.equal(parseIndustrialScaleText("ST NET 12.500 GROSS 14.500 kg TARE 2.000 kg")?.weightKg, 12.5);
  assert.equal(parseIndustrialScaleText("ST,GS,+ 14.250 kg")?.weightKg, 14.25);
  assert.equal(parseIndustrialScaleText("ST,TARE 2.500 kg"), null);
  assert.equal(parseIndustrialScaleText("US,NET 12.500 kg")?.weightKg, 12.5);
  assert.equal(parseIndustrialScaleText("US,NT,+ 8.3kg")?.weightKg, 8.3);
  assert.equal(parseRetailScalePayload("US,NT,+ 8.3kg").stableHint, false);
  assert.equal(parseIndustrialScaleText("S D      12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("S U      12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("S I      12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("S S      12.500 kg")?.weightKg, 12.5);
  assert.equal(parseIndustrialScaleText("ERROR 12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("NET -0.005 kg TARE 0.000 kg"), null);
  assert.equal(parseIndustrialScaleText("---"), null);
});

test("Minorista 1 y Minorista 2 publican una trama US tras dos muestras coincidentes", () => {
  for (const station of ["1", "2"]) {
    const controller = new RetailScaleController({
      navigator: {},
      storage: memoryStorage(),
      storageKey: buildRetailScaleStorageKey(station, 10),
      secureContext: true
    });
    const connection = attachSerialConnection(controller);

    controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
    assert.equal(controller.getState().currentWeightKg, null, `Minorista ${station}: primera muestra`);
    assert.equal(controller.getState().inputStatus, "unstable");
    assert.equal(controller.getState().isCaptureReady, false);

    controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
    assert.equal(controller.getState().currentWeightKg, 8.3, `Minorista ${station}: segunda muestra`);
    assert.equal(controller.getState().inputStatus, "stable");
    assert.equal(controller.getState().isCaptureReady, true);

    const readingId = controller.getState().readingId;
    controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
    controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
    assert.equal(controller.getState().inputStatus, "stable");
    assert.equal(controller.getState().isCaptureReady, true);
    assert.equal(controller.getState().readingId, readingId);
  }
});

test("una lectura estable se congela y el segundo clic la registra sin releer la balanza", () => {
  const captureStart = retailViewSource.indexOf("function captureWeight()");
  const captureEnd = retailViewSource.indexOf("function addWeighingToList", captureStart);
  const captureSource = retailViewSource.slice(captureStart, captureEnd);

  assert.notEqual(captureStart, -1);
  assert.notEqual(captureEnd, -1);
  assert.doesNotMatch(retailViewSource, /lastCapturedReadingId|alreadyCaptured/);
  assert.doesNotMatch(captureSource, /Esta lectura ya fue capturada|duplicar el mismo peso/);
  assert.match(captureSource, /const pendingCapture = activePendingCapture\(\)/);
  assert.match(captureSource, /addWeighingToList\(pendingCapture\.listIndex,\s*pendingCapture\.reading\)/);
  assert.match(captureSource, /if \(!availability\.ready\)/);
  assert.match(captureSource, /state\.pendingCapture\s*=\s*\{[\s\S]*listIndex:\s*state\.activeList,[\s\S]*reading:\s*capturedReading/);
  assert.match(retailViewSource, /state\.pendingCapture = null;[\s\S]*state\.scale\.clearReading\(\)/);
  assert.match(retailViewSource, /elements\.captureWeight\.lastChild\.textContent = pendingCapture[\s\S]*Registrar en lista[\s\S]*Capturar en lista/);

  for (const station of ["1", "2"]) {
    const controller = new RetailScaleController({
      navigator: {},
      storage: memoryStorage(),
      storageKey: buildRetailScaleStorageKey(station, 10),
      secureContext: true
    });
    const connection = attachSerialConnection(controller);

    controller._handlePayload("ST,NET 0.950 kg", { source: "serial", connection });
    const firstCapture = controller.getState();
    controller._handlePayload("ST,NET 0.950 kg", { source: "serial", connection });
    const secondCapture = controller.getState();

    assert.equal(firstCapture.readingId, secondCapture.readingId);
    assert.equal(firstCapture.currentWeightKg, 0.95);
    assert.equal(secondCapture.currentWeightKg, 0.95);
    assert.equal(firstCapture.isCaptureReady, true);
    assert.equal(secondCapture.isCaptureReady, true);
  }
});

test("el peso manual visible queda capturado y espera el clic de registro", () => {
  const applyStart = retailViewSource.indexOf("function applyMainManualWeight(event)");
  const applyEnd = retailViewSource.indexOf("function normalizeCatalog", applyStart);
  const applySource = retailViewSource.slice(applyStart, applyEnd);

  assert.notEqual(applyStart, -1);
  assert.notEqual(applyEnd, -1);
  assert.match(applySource, /state\.scale\.setManualReading\(elements\.manualWeightEntry\.value\)/);
  assert.match(applySource, /closeModal\(elements\.manualWeightModal\)/);
  assert.match(applySource, /captureWeight\(\)/);
  assert.ok(
    applySource.indexOf("setManualReading") < applySource.indexOf("captureWeight()"),
    "la lectura manual debe quedar fijada antes de capturar la pesada"
  );
  assert.match(
    retailBladeSource,
    /id="retailOpenManualWeight"[\s\S]*?Colocar peso manual/
  );
  assert.match(
    retailBladeSource,
    /Al confirmar quedará capturado; luego presiona Registrar/
  );
  assert.match(retailBladeSource, /Capturar peso manual/);
  assert.match(
    retailViewSource,
    /elements\.manualWeightTrigger\.disabled = captureLocked \|\| Boolean\(pendingCapture\)/
  );
  assert.match(
    retailViewSource,
    /elements\.openManualWeight\.disabled = captureLocked \|\| Boolean\(pendingCapture\)/
  );
});

test("movimiento real exige reconfirmar dos tramas US antes de crear otra pesada", () => {
  const { controller } = controllerWithReadings();
  const connection = attachSerialConnection(controller);

  controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
  controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
  const firstReadingId = controller.getState().readingId;

  controller._handlePayload("MOTION,NT,+ 8.3kg", { source: "serial", connection });
  assert.equal(controller.getState().inputStatus, "unstable");
  assert.equal(controller.getState().isCaptureReady, false);

  controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
  assert.equal(controller.getState().readingId, firstReadingId);
  assert.equal(controller.getState().inputStatus, "unstable");
  assert.equal(controller.getState().isCaptureReady, false);

  controller._handlePayload("US,NT,+ 8.3kg", { source: "serial", connection });
  assert.notEqual(controller.getState().readingId, firstReadingId);
  assert.equal(controller.getState().inputStatus, "stable");
  assert.equal(controller.getState().isCaptureReady, true);
});

test("perfil SIG BLE interpreta binario antes que texto", () => {
  const payload = new Uint8Array([0x00, 0xc4, 0x09]); // 2500 * 0.005 kg = 12.5 kg
  const parsed = parseRetailScalePayload(payload, "sig-weight");
  assert.equal(parsed.weightKg, 12.5);
  assert.match(parsed.rawValue, /2500/);
  assert.equal(parsed.rejectionReason, null);
  assert.equal(parsed.stableHint, true);

  const invalid = parseRetailScalePayload(new Uint8Array([0x00, 0xff, 0xff]), "sig-weight");
  assert.equal(invalid.weightKg, null);
});

test("una medición SIG BLE completa se acepta como lectura estable", () => {
  const { controller } = controllerWithReadings();
  const connection = attachSerialConnection(controller);
  connection.mode = "ble";
  controller._state.connectionMode = "ble";

  controller._handleBleValue(connection, new Uint8Array([0x00, 0xc4, 0x09]), "sig-weight");
  assert.equal(controller.getState().currentWeightKg, 12.5);
  assert.equal(controller.getState().readingSource, "ble");
  assert.equal(controller.getState().isCaptureReady, true);
});

test("serial solo publica por CR/LF aunque el fragmento termine en kg", () => {
  const { controller, readings } = controllerWithReadings();
  const connection = attachSerialConnection(controller);
  const encoder = new TextEncoder();

  controller._handleSerialChunk(connection, encoder.encode("ST,GROSS 14."));
  assert.equal(readings.length, 0);
  assert.equal(controller.getState().currentWeightKg, null);

  controller._handleSerialChunk(connection, encoder.encode("500 kg"));
  assert.equal(readings.length, 0);
  assert.equal(controller.getState().currentWeightKg, null);

  controller._handleSerialChunk(connection, encoder.encode(" TARE 2.000 kg NET 12.500 kg"));
  assert.equal(readings.length, 0);
  assert.equal(controller.getState().currentWeightKg, null);

  controller._handleSerialChunk(connection, encoder.encode("\r"));
  assert.equal(readings.at(-1).weightKg, 12.5);
  assert.equal(readings.at(-1).isCaptureReady, true);

  controller.clearReading();
  controller._handleSerialChunk(connection, encoder.encode("ST,NET 9.750\r"));
  assert.equal(readings.at(-1).weightKg, 9.75);
  assert.equal(readings.at(-1).isCaptureReady, true);
});

test("todas las lecturas BLE UART de texto acumulan fragmentos hasta CR/LF", () => {
  const { controller, readings } = controllerWithReadings();
  const connection = attachSerialConnection(controller);
  connection.mode = "ble";
  controller._state.connectionMode = "ble";
  const encoder = new TextEncoder();

  controller._handleBleValue(connection, encoder.encode("ST,NET 0"), "text");
  assert.equal(readings.length, 0);

  controller._handleBleValue(connection, encoder.encode("12.500 kg"), "text");
  assert.equal(readings.length, 0);

  controller._handleBleValue(connection, encoder.encode("\n"), "text");
  assert.equal(readings.at(-1).weightKg, 12.5);
  assert.equal(readings.at(-1).isCaptureReady, true);
});

test("overflow descarta toda la línea contaminada y recupera tras el siguiente CR/LF", () => {
  const { controller, readings } = controllerWithReadings();
  const connection = attachSerialConnection(controller);
  const encoder = new TextEncoder();

  controller._handleSerialChunk(connection, encoder.encode("X".repeat(2050)));
  assert.equal(connection.discardUntilDelimiter, true);
  assert.equal(controller.getState().currentWeightKg, null);
  assert.equal(controller.getState().inputStatus, "invalid");

  controller._handleSerialChunk(connection, encoder.encode(" ST,NET 99.999 kg"));
  assert.equal(connection.discardUntilDelimiter, true);
  assert.equal(controller.getState().currentWeightKg, null);
  assert.equal(readings.some((reading) => reading.weightKg === 99.999), false);

  controller._handleSerialChunk(
    connection,
    encoder.encode("\rST,NET 8.250 kg\r")
  );
  assert.equal(connection.discardUntilDelimiter, false);
  assert.equal(controller.getState().currentWeightKg, 8.25);
  assert.equal(controller.getState().readingRaw, "ST,NET 8.250 kg");
  assert.equal(controller.getState().isCaptureReady, true);
});

test("un cero aislado no reemplaza la lectura comercial y el cero confirmado sí", async () => {
  const { controller } = controllerWithReadings();
  const connection = attachSerialConnection(controller);

  controller._handlePayload("ST,NET 12.500 kg", { source: "serial", connection });
  const acceptedId = controller.getState().readingId;
  const acceptedRaw = controller.getState().readingRaw;
  assert.equal(controller.getState().currentWeightKg, 12.5);
  assert.equal(controller.getState().isCaptureReady, true);
  assert.equal(acceptedRaw, "ST,NET 12.500 kg");

  controller._handlePayload("ST,NET 0.000 kg", { source: "serial", connection });
  assert.equal(controller.getState().currentWeightKg, 12.5);
  assert.equal(controller.getState().isStable, true);
  assert.equal(controller.getState().inputStatus, "unstable");
  assert.equal(controller.getState().isCaptureReady, false);
  assert.equal(controller.getState().readingRaw, acceptedRaw);
  assert.equal(controller.getState().lastRaw, "ST,NET 0.000 kg");

  controller._handlePayload("US,NET 12.500 kg", { source: "serial", connection });
  assert.equal(controller.getState().currentWeightKg, 12.5);
  assert.equal(controller.getState().readingRaw, acceptedRaw);
  assert.equal(controller.getState().lastRaw, "US,NET 12.500 kg");
  assert.equal(controller.getState().isCaptureReady, false);

  await new Promise((resolve) => setTimeout(resolve, 275));
  controller._handlePayload("ST,NET 0.000 kg", { source: "serial", connection });
  await new Promise((resolve) => setTimeout(resolve, 275));
  controller._handlePayload("ST,NET 0.000 kg", { source: "serial", connection });
  assert.equal(controller.getState().currentWeightKg, 0);
  assert.equal(controller.getState().inputStatus, "stable");
  assert.notEqual(controller.getState().readingId, acceptedId);
});

test("cero aislado conserva identidad y solo una transición confirmada crea otro lote", async () => {
  const { controller } = controllerWithReadings();
  const connection = attachSerialConnection(controller);

  controller._handlePayload("ST,NET 10.000 kg", { source: "serial", connection });
  const firstReadingId = controller.getState().readingId;

  controller._handlePayload("ST,NET 0.000 kg", { source: "serial", connection });
  assert.equal(controller.getState().currentWeightKg, 10);
  assert.equal(controller.getState().readingId, firstReadingId);
  assert.equal(controller.getState().isCaptureReady, false);

  controller._handlePayload("ST,NET 10.000 kg", { source: "serial", connection });
  assert.equal(controller.getState().currentWeightKg, 10);
  assert.equal(controller.getState().isCaptureReady, true);
  assert.equal(controller.getState().readingId, firstReadingId);

  controller._handlePayload("ST,NET 0.000 kg", { source: "serial", connection });
  await new Promise((resolve) => setTimeout(resolve, 275));
  controller._handlePayload("ST,NET 0.000 kg", { source: "serial", connection });
  assert.equal(controller.getState().currentWeightKg, 0);

  controller._handlePayload("ST,NET 10.000 kg", { source: "serial", connection });
  assert.equal(controller.getState().currentWeightKg, 10);
  assert.notEqual(controller.getState().readingId, firstReadingId);

  const confirmedTransitionId = controller.getState().readingId;
  controller._handlePayload("US,NET 10.000 kg", { source: "serial", connection });
  controller._handlePayload("ST,NET 10.000 kg", { source: "serial", connection });
  assert.equal(
    controller.getState().readingId,
    confirmedTransitionId,
    "una bandera US aislada con el mismo peso no debe inventar otra pesada"
  );

  controller._handlePayload("US,NET 11.000 kg", { source: "serial", connection });
  controller._handlePayload("US,NET 11.000 kg", { source: "serial", connection });
  assert.notEqual(controller.getState().readingId, confirmedTransitionId);
});

test("frescura exige conexión física vigente y clearReading vuelve a null", () => {
  const { controller } = controllerWithReadings();
  const connection = attachSerialConnection(controller);
  controller._handlePayload("ST,NET 9.750 kg", { source: "serial", connection });
  assert.equal(controller.getState().isFresh, true);

  controller._state.readingAt = new Date(Date.now() - 20000).toISOString();
  assert.equal(controller.getState().isFresh, false);
  assert.equal(controller.getState().isCaptureReady, false);

  controller.clearReading();
  assert.equal(controller.getState().currentWeightKg, null);
  assert.equal(controller.getState().readingSource, null);
});

test("getState vence una lectura por reloj aunque el watchdog no se ejecute", () => {
  const { controller } = controllerWithReadings();
  const connection = attachSerialConnection(controller);
  controller._handlePayload("ST,NET 9.750 kg", { source: "serial", connection });
  const accepted = controller.getState();
  const originalDateNow = Date.now;

  try {
    Date.now = () => Date.parse(accepted.readingAt) + accepted.freshnessMs + 1;
    const current = controller.getState();
    assert.equal(current.currentWeightKg, 9.75);
    assert.equal(current.isStable, true);
    assert.equal(current.isFresh, false);
    assert.equal(current.isCaptureReady, false);
  } finally {
    Date.now = originalDateNow;
  }
});

test("lectura manual explícita queda estable y fresca sin convertir lecturas físicas viejas", () => {
  const { controller } = controllerWithReadings();
  controller.setManualReading("7.125");
  assert.equal(controller.getState().currentWeightKg, 7.125);
  assert.equal(controller.getState().readingSource, "manual");
  assert.equal(controller.getState().isCaptureReady, true);

  controller.clearReading();
  assert.equal(controller.getState().currentWeightKg, null);
  assert.equal(controller.getState().readingSource, null);
});

test("watchdog físico no borra una lectura manual explícita", async () => {
  const { controller } = controllerWithReadings();
  const connection = attachSerialConnection(controller);
  connection.connectedAt = Date.now() - 20000;
  controller.setManualReading("6.250");
  controller._startReadingWatchdog(connection);

  await new Promise((resolve) => setTimeout(resolve, 550));
  assert.equal(controller.getState().currentWeightKg, 6.25);
  assert.equal(controller.getState().readingSource, "manual");
  assert.equal(controller.getState().isCaptureReady, true);
  assert.equal(controller.getState().status, "connected");
  assert.equal(controller.getState().connectionMode, "serial");
  controller.clearReading();
  assert.equal(controller.getState().currentWeightKg, null);
  assert.equal(controller.getState().status, "connected");
  assert.equal(controller.getState().connectionMode, "serial");
  controller._stopConnectionTimers(connection);
});

test("callbacks tardíos de una generación anterior no cambian el peso", () => {
  const { controller, readings } = controllerWithReadings();
  const oldConnection = attachSerialConnection(controller);
  controller._connection = null;
  controller._connectionGeneration += 1;

  controller._handleSerialChunk(
    oldConnection,
    new TextEncoder().encode("ST,NET 18.000 kg\r")
  );
  assert.equal(readings.length, 0);
  assert.equal(controller.getState().currentWeightKg, null);
});

test("restauración serial no elige silenciosamente entre adaptadores USB idénticos", () => {
  const persisted = JSON.stringify({
    version: 2,
    autoConnectMode: "serial",
    serialPortInfo: { usbVendorId: 1234, usbProductId: 5678 },
    serialOptions: { baudRate: 9600, dataBits: 8, stopBits: 1, parity: "none", flowControl: "none" }
  });
  const controller = new RetailScaleController({
    navigator: {},
    storage: memoryStorage({ "scale-test": persisted }),
    storageKey: "scale-test",
    secureContext: true
  });
  const portA = { getInfo: () => ({ usbVendorId: 1234, usbProductId: 5678 }) };
  const portB = { getInfo: () => ({ usbVendorId: 1234, usbProductId: 5678 }) };

  assert.equal(controller._findRememberedSerialPort([portA, portB]), null);
  assert.equal(controller._findRememberedSerialPort([portA]), portA);
});

test("restauración serial usa los dos índices recordados para adaptadores idénticos nuevos", () => {
  const serviceId = "00001101-0000-1000-8000-00805f9b34fb";
  const persisted = JSON.stringify({
    version: 2,
    autoConnectMode: "serial",
    serialPortInfo: {
      bluetoothServiceClassId: serviceId,
      matchIndex: 1,
      portIndex: 2
    },
    serialOptions: { baudRate: 9600, dataBits: 8, stopBits: 1, parity: "none", flowControl: "none" }
  });
  const controller = new RetailScaleController({
    navigator: {},
    storage: memoryStorage({ "scale-test": persisted }),
    storageKey: "scale-test",
    secureContext: true
  });
  const unrelatedPort = { getInfo: () => ({ usbVendorId: 999, usbProductId: 1 }) };
  const portA = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const portB = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };

  assert.equal(
    controller._findRememberedSerialPort([unrelatedPort, portA, portB]),
    portB
  );

  controller._state.serialPortInfo.portIndex = 1;
  assert.equal(
    controller._findRememberedSerialPort([unrelatedPort, portA, portB]),
    null
  );

  controller._state.serialPortInfo = {
    bluetoothServiceClassId: serviceId,
    matchIndex: 1,
    portIndex: 1
  };
  assert.equal(
    controller._findRememberedSerialPort([portA]),
    null
  );
});

test("al seleccionar Serial se recuerdan sus índices global y entre dispositivos iguales", async () => {
  const serviceId = "00001101-0000-1000-8000-00805f9b34fb";
  const unrelatedPort = { getInfo: () => ({ usbVendorId: 999, usbProductId: 1 }) };
  const portA = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const portB = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const controller = new RetailScaleController({
    navigator: {
      serial: {
        async getPorts() {
          return [unrelatedPort, portA, portB];
        },
        addEventListener() {}
      }
    },
    storage: memoryStorage(),
    secureContext: true
  });

  await controller._rememberSerialPort(portB);
  assert.deepEqual(controller.getState().serialPortInfo, {
    usbVendorId: null,
    usbProductId: null,
    bluetoothServiceClassId: serviceId,
    matchIndex: 1,
    portIndex: 2
  });
});

test("restauración BLE exige el id exacto y no cae en nombre o dispositivo único", async () => {
  const persisted = JSON.stringify({
    version: 2,
    autoConnectMode: "ble",
    bleDeviceId: "saved-device",
    deviceName: "Balanza negocio"
  });
  const similarlyNamedDevice = { id: "different-device", name: "Balanza negocio" };
  const controller = new RetailScaleController({
    navigator: {
      bluetooth: {
        getDevices: async () => [similarlyNamedDevice]
      }
    },
    storage: memoryStorage({ "scale-test": persisted }),
    storageKey: "scale-test",
    secureContext: true
  });

  const connected = await controller.restoreAuthorizedConnection();
  assert.equal(connected, false);
  assert.equal(controller.getState().status, "offline");
  assert.match(controller.getState().statusMessage, /exactamente/i);
  assert.equal(controller._autoReconnectEnabled, false);
});

test("BLE recordada se restaura por id sin abrir selector y las llamadas simultáneas se agrupan", async () => {
  const storageKey = "scale-ble-restore";
  const storage = memoryStorage({
    [storageKey]: JSON.stringify({
      version: 2,
      autoConnectMode: "ble",
      bleDeviceId: "saved-device",
      deviceName: "Balanza guardada"
    })
  });
  const { device, metrics } = createMockBleDevice({ id: "saved-device" });
  let getDevicesCalls = 0;
  let requestDeviceCalls = 0;
  let releaseDevices;
  const devicesReady = new Promise((resolve) => {
    releaseDevices = resolve;
  });
  const controller = new RetailScaleController({
    navigator: {
      bluetooth: {
        async getDevices() {
          getDevicesCalls += 1;
          await devicesReady;
          return [device];
        },
        async requestDevice() {
          requestDeviceCalls += 1;
          return device;
        }
      }
    },
    storage,
    storageKey,
    secureContext: true
  });

  const firstRestore = controller.restoreAuthorizedConnection();
  const secondRestore = controller.restoreAuthorizedConnection();
  assert.equal(firstRestore, secondRestore);
  releaseDevices();
  assert.equal(await firstRestore, true);
  assert.equal(getDevicesCalls, 1);
  assert.equal(requestDeviceCalls, 0);
  assert.equal(metrics.connectCalls, 1);

  assert.equal(await controller.restoreAuthorizedConnection(), true);
  assert.equal(getDevicesCalls, 1);
  assert.equal(metrics.connectCalls, 1);
  await controller.destroy();
});

test("sin getDevices la restauración BLE no abre selector ni deja reintento infinito", async () => {
  const storageKey = "scale-ble-no-get-devices";
  let requestDeviceCalls = 0;
  const controller = new RetailScaleController({
    navigator: {
      bluetooth: {
        async requestDevice() {
          requestDeviceCalls += 1;
          throw new Error("No debe solicitar un dispositivo sin gesto");
        }
      }
    },
    storage: memoryStorage({
      [storageKey]: JSON.stringify({
        version: 2,
        autoConnectMode: "ble",
        bleDeviceId: "saved-device"
      })
    }),
    storageKey,
    secureContext: true
  });

  assert.equal(await controller.restoreAuthorizedConnection(), false);
  assert.equal(requestDeviceCalls, 0);
  assert.equal(controller._reconnectTimer, null);
  assert.equal(controller._autoReconnectEnabled, false);
  assert.match(controller.getState().statusMessage, /pulsa Conectar BLE/i);
  await controller.destroy();
});

test("destroy desconecta GATT inmediatamente y conserva la preferencia", async () => {
  const storageKey = "scale-ble-destroy";
  const storage = memoryStorage();
  const { device, metrics } = createMockBleDevice({ id: "destroy-device" });
  const controller = new RetailScaleController({
    navigator: {
      bluetooth: {
        async requestDevice() {
          return device;
        }
      }
    },
    storage,
    storageKey,
    secureContext: true
  });

  assert.equal(await controller.connectBle(), true);
  const destroyPromise = controller.destroy();
  assert.equal(device.gatt.connected, false);
  assert.equal(metrics.disconnectCalls, 1);
  const persisted = JSON.parse(storage.getItem(storageKey));
  assert.equal(persisted.autoConnectMode, "ble");
  assert.equal(persisted.bleDeviceId, "destroy-device");
  await destroyPromise;
});

test("configuración legacy se migra una sola vez y exclusivamente a Minorista 1", () => {
  const legacyValue = JSON.stringify({
    version: 2,
    autoConnectMode: "serial",
    serialPortInfo: { usbVendorId: 1234, usbProductId: 5678 }
  });
  const storage = memoryStorage({ [RETAIL_SCALE_STORAGE_KEY]: legacyValue });
  const branchOneKey = `${RETAIL_SCALE_STORAGE_KEY}-branch-10`;
  const branchTwoKey = `${RETAIL_SCALE_STORAGE_KEY}-branch-20`;
  const stationTwoKey = `${RETAIL_SCALE_STORAGE_KEY}-station-2-branch-10`;

  assert.equal(migrateLegacyRetailScaleStorage({
    station: "2",
    storage,
    targetKey: stationTwoKey
  }), false);
  assert.equal(storage.getItem(stationTwoKey), null);
  assert.equal(storage.getItem(RETAIL_SCALE_LEGACY_MIGRATION_MARKER_KEY), null);

  assert.equal(migrateLegacyRetailScaleStorage({
    station: "1",
    storage,
    targetKey: branchOneKey
  }), true);
  assert.equal(storage.getItem(branchOneKey), legacyValue);
  assert.equal(storage.getItem(RETAIL_SCALE_LEGACY_MIGRATION_MARKER_KEY), branchOneKey);

  assert.equal(migrateLegacyRetailScaleStorage({
    station: "1",
    storage,
    targetKey: branchTwoKey
  }), false);
  assert.equal(storage.getItem(branchTwoKey), null);
});

test("cambiar a la clave por sucursal no crea una legacy vacía", () => {
  const storage = memoryStorage();
  const targetKey = `${RETAIL_SCALE_STORAGE_KEY}-branch-10`;
  const controller = new RetailScaleController({
    navigator: {},
    storage,
    secureContext: true
  });

  controller.setStorageKey(targetKey, { reload: true, persistCurrent: false });
  assert.equal(storage.getItem(RETAIL_SCALE_STORAGE_KEY), null);
  assert.equal(controller.getState().storageKey, targetKey);
});

test("Minorista 1 y Minorista 2 usan recuerdos separados en cada sucursal", () => {
  const stationOneBranchTen = buildRetailScaleStorageKey("1", 10);
  const stationTwoBranchTen = buildRetailScaleStorageKey("2", 10);
  const stationOneBranchTwenty = buildRetailScaleStorageKey("1", 20);

  assert.equal(stationOneBranchTen, `${RETAIL_SCALE_STORAGE_KEY}-branch-10`);
  assert.equal(stationTwoBranchTen, `${RETAIL_SCALE_STORAGE_KEY}-station-2-branch-10`);
  assert.equal(stationOneBranchTwenty, `${RETAIL_SCALE_STORAGE_KEY}-branch-20`);
  assert.equal(new Set([
    stationOneBranchTen,
    stationTwoBranchTen,
    stationOneBranchTwenty
  ]).size, 3);

  const storage = memoryStorage({
    [stationOneBranchTen]: JSON.stringify({
      autoConnectMode: "ble",
      bleDeviceId: "minorista-1"
    }),
    [stationTwoBranchTen]: JSON.stringify({
      autoConnectMode: "ble",
      bleDeviceId: "minorista-2"
    })
  });
  const stationOneController = new RetailScaleController({
    navigator: {},
    storage,
    storageKey: stationOneBranchTen
  });
  const stationTwoController = new RetailScaleController({
    navigator: {},
    storage,
    storageKey: stationTwoBranchTen
  });
  assert.equal(stationOneController.getState().bleDeviceId, "minorista-1");
  assert.equal(stationTwoController.getState().bleDeviceId, "minorista-2");
});

test("pagehide minorista libera hardware también con BFCache y al volver crea un controlador limpio", () => {
  const lifecycleStart = retailViewSource.indexOf("function handleRetailPageShow");
  const lifecycleEnd = retailViewSource.indexOf("initializeTypography();", lifecycleStart);
  assert.notEqual(lifecycleStart, -1);
  assert.notEqual(lifecycleEnd, -1);
  const lifecycleSource = retailViewSource.slice(lifecycleStart, lifecycleEnd);

  assert.match(lifecycleSource, /event\?\.persisted && teardownStarted/);
  assert.match(lifecycleSource, /globalThis\.location\.reload\(\)/);
  assert.match(lifecycleSource, /void state\.scale\.destroy\(\)/);
  assert.doesNotMatch(lifecycleSource, /event\?\.type === "pagehide" && event\.persisted\) return/);
});
