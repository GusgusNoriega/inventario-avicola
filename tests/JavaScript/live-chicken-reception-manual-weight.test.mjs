import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test, { afterEach } from "node:test";
import { RetailScaleController } from "../../public/js/despacho-minorista-balanza.js";
import {
  createEmptyDispatchDraft,
  nextDraftWeighingNumber,
  normalizeDispatchDraft,
  normalizeDraftWeighing,
} from "../../public/js/live-chicken-reception-tickets.js";
import { freezeDispatchClientCorrection } from "../../public/js/live-chicken-reception-pending.js";

const source = readFileSync(
  new URL("../../public/js/recepcion-pollo-vivo.js", import.meta.url),
  "utf8",
).replaceAll("\r\n", "\n");
const controllers = [];
afterEach(() => Promise.all(controllers.splice(0).map((controller) => controller.destroy())));

function sourceBetween(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `No se encontró el bloque ${startMarker}`);
  return source.slice(start, end);
}

function functionSource(name) {
  const start = source.search(new RegExp(`^(?:async )?function ${name}\\(`, "m"));
  const end = source.indexOf("\n}", start);
  assert.ok(start >= 0 && end > start, `No se encontró la función ${name}`);
  return source.slice(start, end + 2);
}

function memoryStorage() {
  const values = new Map();
  return {
    failWrites: false,
    getItem: (key) => values.get(key) ?? null,
    removeItem: (key) => values.delete(key),
    setItem(key, value) {
      if (this.failWrites) throw new Error("Almacenamiento lleno");
      values.set(key, String(value));
    },
  };
}

function createHarness(context, lane = 1) {
  const storage = memoryStorage();
  const state = {
    activeLane: lane,
    sex: "MACHO",
    busy: false,
    manualWeightOverride: null,
    pendingCapture: null,
    pendingUpgradeBlocked: false,
    dispatchDrafts: {},
    draftClientInvalid: {},
    directClientIds: { 5: 10, 6: 10 },
    directClientReselectionRequired: {},
    data: {
      company: { id: 1 },
      branch: { id: 1 },
      operating_date: "2026-08-31",
      configuration: {
        default_male_birds_per_cage: 7,
        default_female_birds_per_cage: 9,
        default_cage_count: 5,
        default_cage_type_id: 2,
        lanes: {
          1: { destination_id: 20 },
          2: { destination_id: 20 },
          5: { destination_id: 10 },
          6: { destination_id: 10 },
        },
      },
      catalog: {
        clients: [{ id: 10, name: "Cliente actual" }],
        warehouses: [{ id: 20, name: "Almacén actual" }],
        cage_types: [
          { id: 1, name: "Java 7 kg", weight_kg: 7, active: true },
          { id: 2, name: "Java 6.8 kg", weight_kg: 6.8, active: true },
        ],
      },
    },
  };
  const elements = Object.fromEntries([
    "scaleWeight", "scaleRaw", "scaleStatus", "connectBle", "connectSerial",
    "disconnectScale", "openScaleSettings", "openManualWeight", "openSettings",
    "capture", "captureLabel", "activeLaneNumber", "manualWeight",
  ].map((name) => [name, {}]));
  Object.assign(elements, {
    main: { dataset: { liveUserId: "1" } },
    birdsPerCage: { value: "7" },
    cageCount: { value: "2" },
    cageType: { value: "1", options: [{ value: "1" }, { value: "2" }] },
    laneButtons: [],
    clientPickerButtons: [],
    registerTicketButtons: [],
    sexButtons: [],
  });
  const calls = { requests: [], messages: [], manualErrors: [], clearedPhysical: 0 };
  let respond = async () => ({ weighing_id: 50, data: state.data });
  const dependencies = {
    state, elements, localStorage: storage, navigator: {},
    createEmptyDispatchDraft, nextDraftWeighingNumber, normalizeDispatchDraft,
    normalizeDraftWeighing, freezeDispatchClientCorrection,
    // Solo se sustituyen las superficies ajenas a la captura y el transporte HTTP.
    renderLaneAssignments() {}, renderRecords() {}, renderTotals() {}, closeManualWeight() {},
    renderData(data) { state.data = data; },
    selectLane(value) { state.activeLane = Number(value); },
    selectSex(value) { state.sex = value; },
    escapeHtml: String,
    setMessage(...args) { calls.messages.push(args); },
    setManualWeightMessage(...args) { calls.manualErrors.push(args); },
    apiRequest(path, options) {
      calls.requests.push({ path, payload: JSON.parse(options.body) });
      return respond(path, options);
    },
    RetailScaleController: class extends RetailScaleController {
      constructor(options) {
        super({ ...options, navigator: {}, storage: memoryStorage(), secureContext: true });
      }
      clearReading() {
        calls.clearedPhysical += 1;
        return super.clearReading();
      }
    },
  };
  const functions = [
    "createUuid", "persistPendingCapture", "clearPendingCapture", "pendingCaptureStorageKey",
    "configureReceptionStorageKeys", "dispatchDraft", "withDispatchDraftLock",
    "dispatchDraftHasPendingRegistration", "persistDispatchDrafts", "hydrateDispatchDrafts",
    "migrateLegacyPendingCapture", "validatePendingCapturePayload", "restorePendingCapture",
    "catalogItem", "laneConfiguration", "laneProfile", "laneDestination", "reconcileDirectClientSelections",
    "firstValidationMessage", "hasValidationError", "captureDefaults", "applyCaptureDefaults",
    "captureScaleState", "consumeCaptureReading",
    "updateScaleUi", "updateCaptureAvailability", "scalePayload", "capturePayload", "captureRequestPayload",
    "freezePendingCaptureForClientCorrection", "refreshAfterInvalidDispatchClient",
    "addCaptureToDispatchDraft", "performCaptureWeighing", "applyManualWeight",
  ];
  const api = new Function(...Object.keys(dependencies), `
    ${sourceBetween("const ZOOM_LEVELS =", "const elements =")}
    ${functions.map(functionSource).join("\n")}
    ${sourceBetween("state.scale = new RetailScaleController({", "elements.summaryTriggers.forEach")}
    configureReceptionStorageKeys(1, 1);
    return { ${functions.join(", ")} };
  `)(...Object.values(dependencies));
  controllers.push(state.scale);

  return {
    state, elements, storage, calls, api,
    respondWith(callback) { respond = callback; },
    applyManual(value = "100,125") {
      elements.manualWeight.value = value;
      api.applyManualWeight({ preventDefault() {} });
      return state.manualWeightOverride;
    },
    physical(weight, mode = "serial") {
      state.scale._setStatus("connected", `Conexión ${mode}`, { connectionMode: mode });
      state.scale._handlePayload(`ST,GS,+ ${Number(weight).toFixed(3)} kg`, { source: mode });
      return state.scale.getState();
    },
  };
}

function assertManualPayload(payload, manual) {
  assert.equal(payload.idempotency_key, manual.id);
  assert.equal(payload.read_weight_kg, manual.weightKg);
  assert.equal(payload.weight_source, "MANUAL");
  assert.equal(payload.scale_reading ?? null, null);
}

function captureInputs(h) {
  return {
    birdsPerCage: Number(h.elements.birdsPerCage.value),
    cageCount: Number(h.elements.cageCount.value),
    cageTypeId: Number(h.elements.cageType.value),
  };
}

function editCaptureInputs(h) {
  h.elements.birdsPerCage.value = "11";
  h.elements.cageCount.value = "3";
  h.elements.cageType.value = "1";
  return captureInputs(h);
}

function assertCaptureDefaults(h, sex = "MACHO") {
  assert.deepEqual(captureInputs(h), {
    birdsPerCage: sex === "HEMBRA" ? 9 : 7,
    cageCount: 5,
    cageTypeId: 2,
  });
}

function assertCaptureInputPayload(payload, inputs) {
  assert.equal(payload.birds_per_cage, inputs.birdsPerCage);
  assert.equal(payload.cage_count, inputs.cageCount);
  assert.equal(payload.cage_type_id, inputs.cageTypeId);
}

for (const [mode, sex] of [["serial", "MACHO"], ["ble", "HEMBRA"]]) {
  test(`peso manual prevalece sobre nuevas tramas ${mode}; guardar el borrador ${sex} restablece los valores configurados`, async (context) => {
    const h = createHarness(context, 5);
    h.state.sex = sex;
    const submittedInputs = editCaptureInputs(h);
    h.physical(45, mode);
    const manual = h.applyManual();
    h.physical(72, mode);

    assert.equal(h.state.scale.getState().currentWeightKg, 72);
    assert.equal(h.state.scale.getState().readingSource, mode);
    assert.equal(h.api.captureScaleState().currentWeightKg, 100.125);
    assert.equal(h.elements.scaleWeight.innerHTML, "100.125 <small>kg</small>");
    assert.match(h.elements.scaleRaw.textContent, /Peso manual pendiente/);
    assert.equal(h.elements.capture.disabled, false);
    assertManualPayload(h.api.capturePayload(), manual);
    assert.equal(Object.hasOwn(h.api.capturePayload(), "scale_reading"), false);

    await h.api.performCaptureWeighing();

    const saved = JSON.parse(h.storage.getItem(h.state.dispatchDraftStorageKey));
    assert.equal(saved.drafts[5].weighings.length, 1);
    assertManualPayload(saved.drafts[5].weighings[0], manual);
    assertCaptureInputPayload(saved.drafts[5].weighings[0], submittedInputs);
    assertCaptureDefaults(h, sex);
    assert.equal(h.state.manualWeightOverride, null);
    assert.equal(h.calls.clearedPhysical, 0);
    assert.equal(h.elements.scaleWeight.innerHTML, "72.000 <small>kg</small>");
    assert.match(h.elements.scaleRaw.textContent, /ST,GS,\+ 72\.000 kg/);
    const next = h.api.capturePayload();
    assert.equal(next.weight_source, "BALANZA_RECEPCION_POLLO_VIVO");
    assert.equal(next.read_weight_kg, 72);
    assert.equal(next.scale_reading.connection_mode, mode.toUpperCase());
    assert.notEqual(next.idempotency_key, manual.id);
  });
}

for (const [lane, sex] of [[1, "MACHO"], [2, "HEMBRA"]]) {
  test(`entrada manual ${sex} queda fija durante HTTP y restablece los valores configurados al confirmarse`, async (context) => {
    const h = createHarness(context, lane);
    const submittedInputs = editCaptureInputs(h);
    h.physical(45);
    const manual = h.applyManual("100");
    let confirm;
    h.respondWith(() => new Promise((resolve) => { confirm = resolve; }));
    const capture = h.api.performCaptureWeighing();

    assertManualPayload(h.calls.requests[0].payload, manual);
    assertCaptureInputPayload(h.calls.requests[0].payload, submittedInputs);
    assert.deepEqual(captureInputs(h), submittedInputs);
    assert.equal(Object.hasOwn(h.calls.requests[0].payload, "scale_reading"), false);
    assertManualPayload(JSON.parse(h.storage.getItem(h.state.pendingCaptureStorageKey)), manual);
    h.physical(91, "ble");
    assert.equal(h.elements.scaleWeight.innerHTML, "100.000 <small>kg</small>");
    assert.match(h.elements.scaleRaw.textContent, /Peso congelado para reintento · Manual/);
    assert.equal(h.state.manualWeightOverride, manual);
    confirm({ weighing_id: 50, data: h.state.data });
    await capture;

    assert.equal(h.state.manualWeightOverride, null);
    assert.equal(h.state.pendingCapture, null);
    assertCaptureDefaults(h, sex);
    assert.equal(h.storage.getItem(h.state.pendingCaptureStorageKey), null);
    assert.equal(h.calls.clearedPhysical, 0);
    assert.equal(h.elements.scaleWeight.innerHTML, "91.000 <small>kg</small>");
    assert.equal(h.api.capturePayload().scale_reading.connection_mode, "BLE");
  });
}

for (const failure of ["tara inválida", "almacenamiento lleno"]) {
  test(`fallo de borrador por ${failure} conserva el manual y su UUID para corregir y reintentar`, async (context) => {
    const h = createHarness(context, 6);
    editCaptureInputs(h);
    const manual = h.applyManual("100");
    if (failure === "tara inválida") h.elements.cageCount.value = "20";
    else h.storage.failWrites = true;
    const submittedInputs = captureInputs(h);

    await h.api.performCaptureWeighing();
    h.physical(84);

    assert.equal(h.state.dispatchDrafts[6].weighings.length, 0);
    assert.equal(h.state.manualWeightOverride, manual);
    assert.deepEqual(captureInputs(h), submittedInputs);
    assertManualPayload(h.api.capturePayload(), manual);
    assertCaptureInputPayload(h.api.capturePayload(), submittedInputs);
    assert.equal(h.elements.scaleWeight.innerHTML, "100.000 <small>kg</small>");
    assert.equal(h.calls.messages.at(-1)[1], "error");
    h.elements.cageCount.value = "2";
    h.storage.failWrites = false;
    await h.api.performCaptureWeighing();

    assert.equal(h.state.dispatchDrafts[6].weighings.length, 1);
    assertManualPayload(h.state.dispatchDrafts[6].weighings[0], manual);
    assert.equal(h.state.manualWeightOverride, null);
    assertCaptureDefaults(h);
    assert.equal(h.elements.scaleWeight.innerHTML, "84.000 <small>kg</small>");
  });
}

test("rechazo 422 de entrada conserva peso manual, origen y UUID para corregir la captura", async (context) => {
  const h = createHarness(context);
  const submittedInputs = editCaptureInputs(h);
  const manual = h.applyManual("100");
  h.respondWith(async () => {
    throw Object.assign(new Error("La tara es mayor al peso"), { status: 422 });
  });

  await h.api.performCaptureWeighing();
  h.physical(65);

  assert.equal(h.state.pendingCapture, null);
  assert.equal(h.state.manualWeightOverride, manual);
  assert.deepEqual(captureInputs(h), submittedInputs);
  assertManualPayload(h.api.capturePayload(), manual);
  assertCaptureInputPayload(h.api.capturePayload(), submittedInputs);
  assert.equal(h.elements.scaleWeight.innerHTML, "100.000 <small>kg</small>");
  assert.equal(h.calls.clearedPhysical, 0);
});

test("fallo de red congela el manual y reintenta exactamente el payload original", async (context) => {
  const h = createHarness(context);
  const submittedInputs = editCaptureInputs(h);
  const manual = h.applyManual("100");
  h.respondWith(async () => { throw new Error("Sin red"); });
  await h.api.performCaptureWeighing();
  const original = h.calls.requests[0].payload;
  h.physical(65);
  h.applyManual("200");

  assert.equal(h.state.manualWeightOverride, manual);
  assert.deepEqual(captureInputs(h), submittedInputs);
  assert.deepEqual(h.api.capturePayload(), original);
  assert.equal(h.elements.scaleWeight.innerHTML, "100.000 <small>kg</small>");
  h.respondWith(async () => ({ weighing_id: 50, data: h.state.data }));
  await h.api.performCaptureWeighing();

  assert.deepEqual(h.calls.requests[1].payload, original);
  assert.equal(h.state.manualWeightOverride, null);
  assert.equal(h.state.pendingCapture, null);
  assertCaptureDefaults(h);
  assert.equal(h.elements.scaleWeight.innerHTML, "65.000 <small>kg</small>");
});

async function recoverManualPending(h) {
  const manual = h.applyManual("100");
  const payload = h.api.capturePayload();
  h.api.persistPendingCapture(payload);
  h.state.manualWeightOverride = null;
  h.state.pendingCapture = null;
  h.physical(57);
  assert.equal(await h.api.restorePendingCapture(1, 1), true);
  h.api.updateScaleUi();
  assert.equal(h.state.manualWeightOverride, null);
  assertManualPayload(h.api.capturePayload(), manual);
  assert.equal(h.elements.scaleWeight.innerHTML, "100.000 <small>kg</small>");
  return manual;
}

test("recupera un pending manual sin override y al confirmarlo conserva la lectura física", async (context) => {
  const h = createHarness(context);
  const manual = await recoverManualPending(h);
  await h.api.performCaptureWeighing();

  assertManualPayload(h.calls.requests[0].payload, manual);
  assert.equal(h.state.pendingCapture, null);
  assert.equal(h.state.manualWeightOverride, null);
  assert.equal(h.calls.clearedPhysical, 0);
  assert.equal(h.elements.scaleWeight.innerHTML, "57.000 <small>kg</small>");
});

test("confirmar un pending manual ajeno no elimina otro override local", async (context) => {
  const h = createHarness(context);
  const oldManual = h.applyManual("100");
  const oldPayload = h.api.capturePayload();
  const newManual = h.applyManual("150");
  h.state.pendingCapture = oldPayload;
  h.physical(57);
  await h.api.performCaptureWeighing();

  assertManualPayload(h.calls.requests[0].payload, oldManual);
  assert.equal(h.state.manualWeightOverride, newManual);
  assertManualPayload(h.api.capturePayload(), newManual);
  assert.equal(h.elements.scaleWeight.innerHTML, "150.000 <small>kg</small>");
  assert.equal(h.calls.clearedPhysical, 0);
});

test("rechazo 422 del pending manual recuperado permite corregir sin perder peso ni UUID", async (context) => {
  const h = createHarness(context);
  const manual = await recoverManualPending(h);
  h.respondWith(async () => { throw Object.assign(new Error("Corrige la tara"), { status: 422 }); });
  await h.api.performCaptureWeighing();

  assert.equal(h.state.pendingCapture, null);
  assertManualPayload(h.api.capturePayload(), manual);
  assert.equal(h.elements.scaleWeight.innerHTML, "100.000 <small>kg</small>");
  assert.equal(h.calls.clearedPhysical, 0);
});
