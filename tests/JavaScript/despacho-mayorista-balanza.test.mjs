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

test("parser mayorista rechaza estados industriales, errores y negativos", () => {
  const parse = parsing.parseIndustrialScaleText;

  assert.equal(parse("S D 12.500 kg").status, "unstable");
  assert.equal(parse("S U 12.500 kg").status, "unstable");
  assert.equal(parse("S I 12.500 kg").status, "error");
  assert.equal(parse("S + 12.500 kg").status, "error");
  assert.equal(parse("S - 12.500 kg").status, "error");
  assert.equal(parse("US,NET 12.500 kg").status, "unstable");
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
    "function setCapturedScaleReading"
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

test("el alta mayorista consume la identidad capturada", () => {
  const addStart = source.indexOf("function addCage(event)");
  const addEnd = source.indexOf("function copyJson", addStart);
  const addFlow = source.slice(addStart, addEnd);

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
