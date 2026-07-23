import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const source = readFileSync(
  new URL("../../public/js/app.js", import.meta.url),
  "utf8"
);

function sourceBetween(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.notEqual(start, -1, `No se encontró ${startMarker}`);
  assert.notEqual(end, -1, `No se encontró ${endMarker}`);
  return source.slice(start, end).replaceAll("export function", "function");
}

function loadParsingHelpers() {
  const parserBlock = sourceBetween(
    "function dataViewToBytes",
    "function isCurrentScaleConnection"
  );
  const stabilityBlock = sourceBetween(
    "export function evaluateScaleStability",
    "function applyScaleStabilityFilter"
  );

  return new Function(`
    const KG_PER_LB = 0.45359237;
    const SCALE_MAX_SERIAL_BUFFER_LENGTH = 2048;
    const SCALE_STABILITY_TOLERANCE_KG = 0.02;
    const SCALE_STABILITY_WINDOW_MS = 1500;
    const SCALE_STABLE_READING_COUNT = 2;
    const SCALE_ZERO_CONFIRMATION_COUNT = 3;
    const SCALE_ZERO_CONFIRMATION_MS = 300;
    const scaleTextDecoder = new TextDecoder("utf-8");
    const roundWeight = (value) => Math.round(value * 100) / 100;
    ${parserBlock}
    ${stabilityBlock}
    return {
      parseIndustrialScaleText,
      parseScalePayload,
      splitScaleTextFrames,
      evaluateScaleStability
    };
  `)();
}

const parsing = loadParsingHelpers();

function loadReconnectHelpers() {
  const normalizeBlock = sourceBetween(
    "function normalizeSerialPortInfo",
    "function normalizeScaleState"
  );
  const identifierBlock = sourceBetween(
    "function serialPortIdentifiersMatch",
    "function otherScaleRemembersSerialPreference"
  );
  const resolutionBlock = sourceBetween(
    "function hasSerialPortIdentifiers",
    "function showScaleRestoreError"
  );

  return new Function(`
    ${normalizeBlock}
    const getSerialPortInfo = (port) => normalizeSerialPortInfo(port?.getInfo?.() || {});
    ${identifierBlock}
    ${resolutionBlock}
    return {
      normalizeSerialPortInfo,
      serialPortPreferencesMatch,
      resolveRememberedSerialPort,
      resolveRememberedBleDevice
    };
  `)();
}

function loadConnectionPreferenceHelpers() {
  const preferenceBlock = sourceBetween(
    "function normalizeScaleSerialOptions",
    "function normalizeType"
  );

  return new Function(`
    const SCALE_IDS = [1, 2];
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
    const MAX_SCALE_RAW_LENGTH = 160;
    const roundWeight = (value) => Math.round(value * 100) / 100;
    ${preferenceBlock}
    return { snapshotScaleConnectionPreferences, applyScaleConnectionPreferences };
  `)();
}

const reconnect = loadReconnectHelpers();

test("reconexión Serial distingue dos RFCOMM con el mismo Service Class ID", () => {
  const serviceId = "00001101-0000-1000-8000-00805f9b34fb";
  const firstPort = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const secondPort = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const ports = [firstPort, secondPort];
  const firstSaved = {
    bluetoothServiceClassId: serviceId,
    matchIndex: 0,
    portIndex: 0
  };
  const secondSaved = {
    bluetoothServiceClassId: serviceId,
    matchIndex: 1,
    portIndex: 1
  };

  const first = reconnect.resolveRememberedSerialPort(firstSaved, ports, new Set());
  const second = reconnect.resolveRememberedSerialPort(secondSaved, ports, new Set([first.port]));

  assert.equal(first.port, firstPort);
  assert.equal(second.port, secondPort);
  assert.notEqual(first.port, second.port);
  assert.equal(first.reason, null);
  assert.equal(second.reason, null);
});

test("reconexión Serial no reasigna una preferencia duplicada a otro puerto físico", () => {
  const serviceId = "00001101-0000-1000-8000-00805f9b34fb";
  const firstPort = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const secondPort = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const legacySaved = {
    bluetoothServiceClassId: serviceId,
    matchIndex: 0,
    portIndex: 0
  };

  const first = reconnect.resolveRememberedSerialPort(
    legacySaved,
    [firstPort, secondPort],
    new Set()
  );
  const second = reconnect.resolveRememberedSerialPort(
    legacySaved,
    [firstPort, secondPort],
    new Set([first.port])
  );

  assert.equal(first.port, firstPort);
  assert.equal(second.port, null);
  assert.equal(second.reason, "claimed");
});

test("reconexión Serial no sustituye un ordinal recordado que ya no existe", () => {
  const serviceId = "00001101-0000-1000-8000-00805f9b34fb";
  const remainingPort = { getInfo: () => ({ bluetoothServiceClassId: serviceId }) };
  const savedSecondPort = {
    bluetoothServiceClassId: serviceId,
    matchIndex: 1,
    portIndex: 1
  };

  const resolution = reconnect.resolveRememberedSerialPort(
    savedSecondPort,
    [remainingPort],
    new Set()
  );

  assert.equal(resolution.port, null);
  assert.equal(resolution.reason, "unavailable");
});

test("reconexión Serial usa portIndex sin identificadores y detecta asignación ocupada", () => {
  const firstPort = { getInfo: () => ({}) };
  const secondPort = { getInfo: () => ({}) };
  const saved = { matchIndex: 0, portIndex: 1 };

  assert.equal(
    reconnect.resolveRememberedSerialPort(saved, [firstPort, secondPort], new Set()).port,
    secondPort
  );
  assert.equal(
    reconnect.resolveRememberedSerialPort(saved, [firstPort, secondPort], new Set([secondPort])).reason,
    "claimed"
  );
});

test("preferencias Serial solo consideran igual el mismo ordinal físico", () => {
  const base = {
    bluetoothServiceClassId: "00001101-0000-1000-8000-00805f9b34fb",
    matchIndex: 0,
    portIndex: 0
  };
  assert.equal(reconnect.serialPortPreferencesMatch(base, { ...base }), true);
  assert.equal(
    reconnect.serialPortPreferencesMatch(base, { ...base, matchIndex: 1, portIndex: 1 }),
    false
  );
});

test("reconexión BLE exige el ID exacto y no comparte dispositivo entre puestos", () => {
  const devices = [
    { id: "ble-a", name: "Balanza A" },
    { id: "ble-b", name: "Balanza B" }
  ];

  assert.equal(
    reconnect.resolveRememberedBleDevice("ble-b", devices, new Set()).device,
    devices[1]
  );
  assert.equal(
    reconnect.resolveRememberedBleDevice("ble-b", devices, new Set(["ble-b"])).reason,
    "claimed"
  );
  assert.equal(
    reconnect.resolveRememberedBleDevice("ble-c", devices, new Set()).reason,
    "unavailable"
  );
});

test("reiniciar jornada conserva configuración de ambas balanzas y elimina lecturas", () => {
  const helpers = loadConnectionPreferenceHelpers();
  const originalScales = {
    1: {
      id: 1,
      autoConnectMode: "ble",
      connectionMode: "ble",
      deviceName: "BLE A",
      bleDeviceId: "ble-a",
      currentWeight: 12.5,
      readingValid: true,
      readingStable: true
    },
    2: {
      id: 2,
      autoConnectMode: "serial",
      connectionMode: "serial",
      deviceName: "Serial B",
      serialPortInfo: {
        bluetoothServiceClassId: "00001101-0000-1000-8000-00805f9b34fb",
        matchIndex: 1,
        portIndex: 1
      },
      serialOptions: { baudRate: 19200 },
      currentWeight: 20,
      readingValid: true,
      readingStable: true
    }
  };
  const preferences = helpers.snapshotScaleConnectionPreferences(originalScales);
  const resetState = { scales: {} };
  helpers.applyScaleConnectionPreferences(resetState, preferences);

  assert.equal(resetState.scales[1].bleDeviceId, "ble-a");
  assert.equal(resetState.scales[1].currentWeight, null);
  assert.equal(resetState.scales[1].readingValid, false);
  assert.equal(resetState.scales[2].serialPortInfo.matchIndex, 1);
  assert.equal(resetState.scales[2].serialOptions.baudRate, 19200);
  assert.equal(resetState.scales[2].currentWeight, null);

  const resetFlow = sourceBetween("async function resetDay", "function isInstalledDesktopApplication");
  assert.match(resetFlow, /snapshotScaleConnectionPreferences\(state\.scales\)/);
  assert.match(resetFlow, /disconnectScale\(scaleId, false, false\)/);
  assert.match(resetFlow, /applyScaleConnectionPreferences\(state, scaleConnectionPreferences\)/);
  assert.doesNotMatch(resetFlow, /disconnectScale\(scaleId, false, true\)/);
});

test("restauración automática nunca abre selectores fuera de un clic", () => {
  const restoreFlow = sourceBetween(
    "async function restoreScaleConnections",
    "function hasPendingRememberedScaleConnections"
  );
  assert.match(restoreFlow, /navigator\.serial\.getPorts\(\)/);
  assert.match(restoreFlow, /navigator\.bluetooth\.getDevices\(\)/);
  assert.doesNotMatch(restoreFlow, /requestPort\s*\(/);
  assert.doesNotMatch(restoreFlow, /requestDevice\s*\(/);

  const capabilityBlock = sourceBetween(
    "function canRestoreSerialScaleConnections",
    "function formatScaleTime"
  );
  const capability = new Function("navigator", `
    ${capabilityBlock}
    return { canRestoreSerialScaleConnections, canRestoreBleScaleConnections };
  `);
  assert.equal(capability({ serial: {}, bluetooth: {} }).canRestoreSerialScaleConnections(), false);
  assert.equal(capability({ serial: {}, bluetooth: {} }).canRestoreBleScaleConnections(), false);
  assert.equal(
    capability({ serial: { getPorts() {} }, bluetooth: { getDevices() {} } })
      .canRestoreSerialScaleConnections(),
    true
  );
});

test("pagehide libera hardware sin olvidar la preferencia y BLE se guarda antes de leer", () => {
  const releaseFlow = sourceBetween(
    "function releaseScaleConnectionsForNavigation",
    "function handleScaleDisconnected"
  );
  assert.match(releaseFlow, /releaseUnexpectedScaleConnection\(connection\)/);
  assert.match(releaseFlow, /invalidateScaleReading\(scaleId, "disconnected"/);
  assert.doesNotMatch(releaseFlow, /clearScaleConnectionPreference/);
  assert.match(source, /releaseScaleConnectionsForNavigation\(\{ deactivate: true \}\)/);
  assert.match(source, /resumeScaleConnectionsAfterNavigation\(\)/);

  const bleFlow = sourceBetween("async function connectBleScale", "function handleSerialScaleChunk");
  assert.ok(
    bleFlow.indexOf("rememberBleScaleDevice(scaleId, device)")
      < bleFlow.indexOf("startNotifications()")
  );
});

test("una restauración mayorista vieja no puede continuar después de pagehide", () => {
  const restoreFlow = sourceBetween(
    "async function restoreScaleConnections",
    "function hasPendingRememberedScaleConnections"
  );
  const scheduleFlow = sourceBetween(
    "function scheduleScaleConnectionRestore",
    "function getScaleWeight"
  );

  assert.match(restoreFlow, /const lifecycleGeneration = scaleLifecycleGeneration/);
  assert.match(restoreFlow, /isScaleLifecycleCurrent\(lifecycleGeneration\)/);
  assert.match(restoreFlow, /connectSerialScale\(scaleId, port, \{ lifecycleGeneration \}\)/);
  assert.match(restoreFlow, /connectBleScale\(scaleId, device, \{ lifecycleGeneration \}\)/);
  assert.match(scheduleFlow, /const lifecycleGeneration = scaleLifecycleGeneration/);
  assert.match(scheduleFlow, /isScaleLifecycleCurrent\(lifecycleGeneration\)/);
});

test("configuración local mayorista queda separada por empresa y sucursal", () => {
  const branchBlock = sourceBetween("function branchStorageKey", "function activateBranchStorage");
  const branchStorageKey = new Function(`
    const STORAGE_KEY_PREFIX = "sistema-pollos-state-v2";
    ${branchBlock}
    return branchStorageKey;
  `)();

  assert.notEqual(
    branchStorageKey({ id: 10 }, { id: 1 }),
    branchStorageKey({ id: 10 }, { id: 2 })
  );
  assert.notEqual(
    branchStorageKey({ id: 10 }, { id: 1 }),
    branchStorageKey({ id: 11 }, { id: 1 })
  );
});

test("parser mayorista separa GROSS/NET de TARE antes y después del valor", () => {
  const parse = parsing.parseIndustrialScaleText;

  assert.equal(parse("ST GROSS 50.000 kg NET 43.000 kg TARE 7.000 kg").weightKg, 50);
  assert.equal(parse("ST 50.000 kg GROSS 43.000 kg NET 7.000 kg TARE").weightKg, 50);
  assert.equal(parse("S S 12.500 kg NET 0.000 kg TARE").weightKg, 12.5);
  assert.equal(parse("S S NET 12.500 kg TARE 0.000 kg").weightKg, 12.5);
  assert.equal(parse("ST TARE 7.000 kg").status, "tare-only");
  assert.equal(parse("ST 7.000 kg TARE").status, "tare-only");
  assert.equal(parse("ST 12.500 GROSS").weightKg, 12.5);
  assert.equal(parse("ST 12.500 kg GROSS").weightKg, 12.5);
});

test("parser mayorista filtra estados industriales, errores y negativos", () => {
  const parse = parsing.parseIndustrialScaleText;

  assert.equal(parse("S D 12.500 kg").status, "unstable");
  assert.equal(parse("S U 12.500 kg").status, "unstable");
  assert.equal(parse("S I 12.500 kg").status, "error");
  assert.equal(parse("S + 12.500 kg").status, "error");
  assert.equal(parse("S - 12.500 kg").status, "error");
  assert.equal(parse("US,NET 12.500 kg").weightKg, 12.5);
  assert.equal(parse("US,NET 12.500 kg").stable, false);
  assert.equal(parse("US,NT,+ 8.3kg").weightKg, 8.3);
  assert.equal(parse("US,NT,+ 8.3kg").stable, false);
  assert.equal(parse("ERROR 12.500 kg").status, "error");
  assert.equal(parse("ST,GS,-1.000 kg ID 2").status, "negative");
  assert.equal(parse("S S 12.500 kg").stable, true);
});

test("SIG BLE se interpreta como binario y rechaza tramas parciales o 0xffff", () => {
  const valid = parsing.parseScalePayload(
    Uint8Array.of(0x00, 0x30, 0x30),
    "sig-weight"
  );
  assert.equal(valid.weightKg, 61.68);

  const partial = parsing.parseScalePayload(
    Uint8Array.of(0x30, 0x30),
    "sig-weight"
  );
  assert.equal(partial.weightKg, null);

  const sentinel = parsing.parseScalePayload(
    Uint8Array.of(0x00, 0xff, 0xff),
    "sig-weight"
  );
  assert.equal(sentinel.weightKg, null);
});

test("framing mayorista solo publica texto cerrado por CR/LF", () => {
  let framed = parsing.splitScaleTextFrames("", "S S GROSS 50.000 kg");
  assert.deepEqual(framed.completeFrames, []);
  assert.equal(framed.pendingFrame, "S S GROSS 50.000 kg");

  framed = parsing.splitScaleTextFrames(
    framed.pendingFrame,
    " TARE 7.000 kg\r"
  );
  assert.deepEqual(
    framed.completeFrames,
    ["S S GROSS 50.000 kg TARE 7.000 kg"]
  );
  assert.equal(framed.pendingFrame, "");

  framed = parsing.splitScaleTextFrames("", "1kg\r2kg\n3kg\r\n");
  assert.deepEqual(framed.completeFrames, ["1kg", "2kg", "3kg"]);
});

test("overflow entra en descarte y bloquea captura sin borrar el peso comercial", () => {
  const handlerBlock = sourceBetween(
    "function handleFramedScaleTextChunk",
    "function releaseUnexpectedScaleConnection"
  );
  const state = {
    currentWeight: 12.5,
    readingValid: true,
    readingStable: true,
    readingStatus: "stable",
    lastRaw: "ST 12.500 kg"
  };
  const pendingStatuses = [];
  const connection = {
    mode: "serial",
    generation: 1,
    abort: false,
    decoder: new TextDecoder("utf-8"),
    buffer: "",
    stability: {},
    forceNewReading: false
  };

  const handle = new Function(
    "state",
    "connection",
    "pendingStatuses",
    `
      const MAX_SCALE_RAW_LENGTH = 160;
      const isCurrentScaleConnection = () => true;
      const dataViewToBytes = (value) => value;
      const splitScaleTextFrames = ${parsing.splitScaleTextFrames.toString()};
      const SCALE_MAX_SERIAL_BUFFER_LENGTH = 2048;
      const processScaleTextFrame = () => false;
      const getScaleState = () => state;
      const sanitizeScaleRawText = (value) => String(value).slice(-MAX_SCALE_RAW_LENGTH);
      const getScaleModeLabel = () => "Serial BT";
      const markScaleReadingPending = (_scaleId, status) => {
        state.readingStatus = status;
        pendingStatuses.push(status);
      };
      ${handlerBlock}
      return handleFramedScaleTextChunk;
    `
  )(state, connection, pendingStatuses);

  handle(1, connection, "X".repeat(2049), { mode: "serial" });

  assert.equal(state.currentWeight, 12.5);
  assert.equal(state.readingStatus, "invalid");
  assert.equal(connection.forceNewReading, false);
  assert.equal(connection.discardUntilDelimiter, true);
  assert.match(state.lastRaw, /^\[trama excedida\]/);
  assert.deepEqual(pendingStatuses, ["invalid"]);
});

test("ruido y candidatos pendientes conservan identidad; movimiento la renueva", () => {
  const identityBlock = sourceBetween(
    "export function canReuseScaleReadingId",
    "function invalidateScaleReading"
  );
  const helpers = new Function(`
    const SCALE_STABILITY_TOLERANCE_KG = 0.02;
    ${identityBlock}
    return { shouldStartNewScaleReadingIdentity };
  `)();
  const accepted = {
    readingId: "serial-1",
    valid: true,
    weightKg: 12.5
  };

  for (const status of ["error", "no-weight", "tare-only", "negative", "invalid"]) {
    assert.equal(
      helpers.shouldStartNewScaleReadingIdentity(accepted, { weightKg: null, status }),
      false,
      `El estado ${status} no debe crear una identidad nueva`
    );
  }

  assert.equal(
    helpers.shouldStartNewScaleReadingIdentity(accepted, { weightKg: 12.5, status: "weight" }),
    false
  );
  assert.equal(
    helpers.shouldStartNewScaleReadingIdentity(accepted, { weightKg: null, status: "unstable" }),
    true
  );
  assert.equal(
    helpers.shouldStartNewScaleReadingIdentity(accepted, { weightKg: 0, status: "weight" }),
    false
  );
  assert.equal(
    helpers.shouldStartNewScaleReadingIdentity(accepted, { weightKg: 12.53, status: "weight" }),
    false
  );
});

test("cero requiere confirmación y un peso sin bandera estable requiere dos muestras", () => {
  let zero = parsing.evaluateScaleStability(
    null,
    { weightKg: 0, stable: true },
    1000
  );
  zero = parsing.evaluateScaleStability(
    zero.tracker,
    { weightKg: 0, stable: true },
    1100
  );
  zero = parsing.evaluateScaleStability(
    zero.tracker,
    { weightKg: 0, stable: true },
    1200
  );
  assert.equal(zero.accepted, false);
  assert.equal(zero.status, "confirming-zero");

  zero = parsing.evaluateScaleStability(
    zero.tracker,
    { weightKg: 0, stable: true },
    1300
  );
  assert.equal(zero.accepted, true);

  let regular = parsing.evaluateScaleStability(
    null,
    { weightKg: 25.5, stable: false },
    2000
  );
  assert.equal(regular.accepted, false);
  regular = parsing.evaluateScaleStability(
    regular.tracker,
    { weightKg: 25.51, stable: false },
    2100
  );
  assert.equal(regular.accepted, true);
});

test("dos tramas US consecutivas de la balanza real habilitan el peso mayorista", () => {
  const firstReading = parsing.parseIndustrialScaleText("US,NT,+ 8.3kg");
  let result = parsing.evaluateScaleStability(null, firstReading, 2000);

  assert.equal(firstReading.weightKg, 8.3);
  assert.equal(firstReading.stable, false);
  assert.equal(result.accepted, false);

  const secondReading = parsing.parseIndustrialScaleText("US,NT,+ 8.3kg");
  result = parsing.evaluateScaleStability(result.tracker, secondReading, 2100);

  assert.equal(result.accepted, true);
  assert.equal(result.status, "stable");
});

test("lectura pendiente conserva display pero bloquea captura y evita duplicados", () => {
  const getWeightBlock = sourceBetween(
    "function getScaleWeight",
    "function scaleTimestampAge"
  );
  const eligibilityBlock = sourceBetween(
    "function scaleTimestampAge",
    "function createScaleReadingSnapshot"
  );
  const state = {
    currentWeight: 12.5,
    readingValid: true,
    readingStable: true,
    readingStatus: "stable",
    readingMode: "serial",
    readingGeneration: 4,
    readingId: "serial-1",
    updatedAt: new Date().toISOString()
  };
  const connection = { mode: "serial", generation: 4 };
  const consumed = { 1: null, 2: null };

  const availability = new Function(
    "state",
    "connection",
    "consumed",
    `
      const SCALE_READING_MAX_AGE_MS = 15000;
      const roundWeight = (value) => Math.round(value * 100) / 100;
      const getScaleState = () => state;
      const isCurrentScaleConnection = () => true;
      const isScaleConnectionActive = () => true;
      const scaleConnections = { 1: connection };
      const lastRegisteredScaleReadingIds = consumed;
      ${getWeightBlock}
      ${eligibilityBlock}
      return { getScaleWeight, getScaleReadingEligibility };
    `
  )(state, connection, consumed);

  assert.equal(availability.getScaleWeight(1), 12.5);
  assert.equal(availability.getScaleReadingEligibility(1).ok, true);

  state.readingStatus = "confirming-zero";
  assert.equal(availability.getScaleWeight(1), 12.5);
  assert.equal(availability.getScaleReadingEligibility(1).ok, false);

  state.readingStatus = "unstable";
  assert.equal(availability.getScaleWeight(1), 12.5);
  assert.equal(availability.getScaleReadingEligibility(1).ok, false);

  state.readingStatus = "stable";
  consumed[1] = "serial-1";
  assert.equal(availability.getScaleReadingEligibility(1).ok, false);

  state.readingId = "serial-2";
  assert.equal(availability.getScaleReadingEligibility(1).ok, true);
});

test("solo una transición confirmada o movimiento habilita nuevamente el mismo peso", () => {
  const identityBlock = sourceBetween(
    "export function canReuseScaleReadingId",
    "function invalidateScaleReading"
  );
  const acceptBlock = sourceBetween(
    "function acceptScaleReading",
    "export function evaluateScaleStability"
  );
  const state = {
    currentWeight: null,
    readingValid: false,
    readingStable: false,
    readingStatus: "unknown",
    readingMode: null,
    readingGeneration: 0,
    readingId: null,
    deviceName: "",
    connectionMode: null
  };
  const connection = {
    mode: "serial",
    generation: 4,
    deviceName: "Puerto prueba",
    forceNewReading: false
  };

  const identity = new Function(
    "state",
    "connection",
    `
      const SCALE_STABILITY_TOLERANCE_KG = 0.02;
      const roundWeight = (value) => Math.round(value * 100) / 100;
      let scaleReadingSequences = { 1: 0, 2: 0 };
      const getScaleState = () => state;
      const scheduleScaleReadingExpiry = () => {};
      ${identityBlock}
      ${acceptBlock}
      return {
        accept(weightKg, raw = "ST " + weightKg + " kg") {
          return acceptScaleReading(
            1,
            connection,
            { weightKg },
            raw,
            { mode: "serial", deviceName: connection.deviceName }
          );
        },
        observe(reading) {
          if (shouldStartNewScaleReadingIdentity({
            readingId: state.readingId,
            valid: state.readingValid,
            weightKg: state.currentWeight
          }, reading)) {
            connection.forceNewReading = true;
          }
        }
      };
    `
  )(state, connection);

  identity.accept(12.5);
  const firstId = state.readingId;
  identity.accept(12.5);
  assert.equal(state.readingId, firstId);

  identity.observe({ weightKg: null, status: "error" });
  identity.accept(12.5);
  assert.equal(state.readingId, firstId);

  identity.observe({ weightKg: 0, status: "weight" });
  identity.accept(12.5);
  assert.equal(state.readingId, firstId, "un cero aislado no debe habilitar un duplicado");

  identity.accept(0);
  const confirmedZeroId = state.readingId;
  assert.notEqual(confirmedZeroId, firstId);
  identity.accept(12.5);
  const afterConfirmedZeroId = state.readingId;
  assert.notEqual(afterConfirmedZeroId, firstId);
  assert.notEqual(afterConfirmedZeroId, confirmedZeroId);

  const beforeMovementId = state.readingId;
  identity.observe({ weightKg: null, status: "unstable" });
  identity.accept(12.5);
  assert.notEqual(state.readingId, beforeMovementId);

  const beforeConfirmedChangeId = state.readingId;
  identity.observe({ weightKg: 11, status: "weight" });
  identity.accept(12.5);
  assert.equal(state.readingId, beforeConfirmedChangeId, "un candidato distinto aislado no basta");
  identity.accept(11);
  const confirmedChangeId = state.readingId;
  assert.notEqual(confirmedChangeId, beforeConfirmedChangeId);
  identity.accept(12.5);
  assert.notEqual(state.readingId, confirmedChangeId);
});

test("snapshot usa raw y hora aceptados, no la última trama inválida ni el clic", () => {
  const normalizeBlock = sourceBetween(
    "function normalizeScaleReadingSnapshot",
    "function normalizeCageRecord"
  );
  const snapshotBlock = sourceBetween(
    "function createScaleReadingSnapshot",
    "function getWeightFromSource"
  );
  const acceptedAt = "2026-07-22T15:00:00.000Z";

  const snapshot = new Function(`
    const SCALE_IDS = [1, 2];
    const MAX_SCALE_RAW_LENGTH = 160;
    const roundWeight = (value) => Math.round(value * 100) / 100;
    const getScaleReadingEligibility = () => ({
      ok: true,
      weight: 42.25,
      scale: {
        readingId: "serial-1",
        readingRaw: "ST,GS,+00042.25kg",
        lastRaw: "US,GS,+00000.00kg",
        updatedAt: "${acceptedAt}",
        readingDeviceName: "respaldo"
      },
      connection: {
        mode: "serial",
        deviceName: "Puerto prueba",
        generation: 9
      }
    });
    ${normalizeBlock}
    ${snapshotBlock}
    return createScaleReadingSnapshot(1);
  `)();

  assert.equal(snapshot.readingId, "serial-1");
  assert.equal(snapshot.rawFrame, "ST,GS,+00042.25kg");
  assert.equal(snapshot.capturedAt, acceptedAt);
  assert.equal(snapshot.readingAt, acceptedAt);
  assert.equal(snapshot.deviceName, "Puerto prueba");
});

test("la balanza seleccionada alimenta la vista previa con su peso en vivo", () => {
  const weightSourceBlock = sourceBetween(
    "function getWeightFromSource",
    "function formatWeightBreakdownDetail"
  );
  let liveWeight = 11.8;
  const getWeightFromSource = new Function(
    "readScaleWeight",
    `
      const SCALE_IDS = [1, 2];
      const elements = { manualWeight: { value: "" } };
      const roundWeight = (value) => Math.round(value * 100) / 100;
      const getScaleWeight = (scaleId) => readScaleWeight(scaleId);
      ${weightSourceBlock}
      return getWeightFromSource;
    `
  )(() => liveWeight);

  assert.equal(getWeightFromSource("2"), 11.8);
  liveWeight = 6.7;
  assert.equal(getWeightFromSource("2"), 6.7);
  assert.doesNotMatch(weightSourceBlock, /CapturedScale/);
});

test("el alta mayorista captura y consume la lectura vigente al registrar", () => {
  const addStart = source.indexOf("function addCage(event)");
  const addEnd = source.indexOf("function copyJson", addStart);
  const addFlow = source.slice(addStart, addEnd);

  assert.match(
    addFlow,
    /createScaleReadingSnapshot\(Number\(source\)\)/
  );
  assert.doesNotMatch(addFlow, /getCapturedScaleReading/);
  assert.match(
    addFlow,
    /lastRegisteredScaleReadingIds\[scaleReading\.scaleId\]\s*=\s*scaleReading\.readingId/
  );
});

test("borrar libera la identidad solo cuando ya no queda otra fila que la use", () => {
  const identityBlock = sourceBetween(
    "export function canReuseScaleReadingId",
    "function invalidateScaleReading"
  );
  const { canReleaseConsumedScaleReadingId } = new Function(`
    const SCALE_STABILITY_TOLERANCE_KG = 0.02;
    ${identityBlock}
    return { canReleaseConsumedScaleReadingId };
  `)();
  const removed = { scaleId: 1, readingId: "serial-1" };

  assert.equal(canReleaseConsumedScaleReadingId("serial-1", removed, []), true);
  assert.equal(
    canReleaseConsumedScaleReadingId("serial-1", removed, [{ readingId: "serial-1" }]),
    false
  );
  assert.equal(canReleaseConsumedScaleReadingId("serial-2", removed, []), false);

  const deleteStart = source.indexOf("function releaseConsumedScaleReadingIdIfUnused");
  const deleteEnd = source.indexOf("function clearRegisteredTruckColumn", deleteStart);
  const deleteFlow = source.slice(deleteStart, deleteEnd);
  assert.match(deleteFlow, /releaseConsumedScaleReadingIdIfUnused\(cage\.scaleReading\)/);
  assert.match(deleteFlow, /lastRegisteredScaleReadingIds\[scaleId\]\s*=\s*null/);
});
