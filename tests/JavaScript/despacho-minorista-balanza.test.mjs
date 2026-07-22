import test from "node:test";
import assert from "node:assert/strict";

import {
  RetailScaleController,
  migrateLegacyRetailScaleStorage,
  parseIndustrialScaleText,
  parseRetailScalePayload,
  RETAIL_SCALE_LEGACY_MIGRATION_MARKER_KEY,
  RETAIL_SCALE_STORAGE_KEY
} from "../../public/js/despacho-minorista-balanza.js";

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

test("parser prioriza NET/GROSS, ignora TARE y rechaza US, errores y negativos", () => {
  assert.equal(parseIndustrialScaleText("ST,NET 12.500 kg TARE 0.000 kg")?.weightKg, 12.5);
  assert.equal(parseIndustrialScaleText("ST NET 12.500 GROSS 14.500 kg TARE 2.000 kg")?.weightKg, 12.5);
  assert.equal(parseIndustrialScaleText("ST,GS,+ 14.250 kg")?.weightKg, 14.25);
  assert.equal(parseIndustrialScaleText("ST,TARE 2.500 kg"), null);
  assert.equal(parseIndustrialScaleText("US,NET 12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("S D      12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("S U      12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("S I      12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("S S      12.500 kg")?.weightKg, 12.5);
  assert.equal(parseIndustrialScaleText("ERROR 12.500 kg"), null);
  assert.equal(parseIndustrialScaleText("NET -0.005 kg TARE 0.000 kg"), null);
  assert.equal(parseIndustrialScaleText("---"), null);
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
