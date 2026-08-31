import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
  new URL("../../public/js/recepcion-pollo-vivo.js", import.meta.url),
  "utf8",
).replaceAll("\r\n", "\n");

function functionSource(name) {
  const start = source.search(new RegExp(`^(?:async )?function ${name}\\(`, "m"));
  const end = source.indexOf("\n}", start);
  assert.ok(start >= 0 && end > start, `No se encontró la función ${name}`);
  return source.slice(start, end + 2);
}

function selectElement() {
  let markup = "";
  let selectedValue = "";
  return {
    options: [],
    get innerHTML() { return markup; },
    set innerHTML(value) {
      markup = value;
      this.options = [...value.matchAll(/<option\b[^>]*value="([^"]*)"[^>]*>(.*?)<\/option>/g)]
        .map(([, optionValue, textContent]) => ({ value: optionValue, textContent }));
      selectedValue = this.options[0]?.value || "";
    },
    get value() { return selectedValue; },
    set value(value) {
      selectedValue = this.options.some((option) => option.value === String(value)) ? String(value) : "";
    },
    append(option) { this.options.push(option); },
  };
}

function receptionData(configuration = {}) {
  return {
    company: { id: 1 },
    branch: { id: 1 },
    operating_date: "2026-08-31",
    records: [],
    configuration: {
      default_external_owner_id: 30,
      default_male_birds_per_cage: 7,
      default_female_birds_per_cage: 9,
      default_cage_count: 5,
      default_cage_type_id: 2,
      lanes: Object.fromEntries([1, 2, 3, 4, 5, 6].map((lane) => [lane, { destination_id: lane < 5 ? 20 : 10 }])),
      ...configuration,
    },
    catalog: {
      clients: [{ id: 10, name: "Cliente actual" }],
      warehouses: [{ id: 20, name: "Almacén actual" }],
      external_owners: [{ id: 30, name: "Empresa externa" }],
      cage_types: [
        { id: 1, name: "Java 7 kg", weight_kg: 7, active: true },
        { id: 2, name: "Java 6.8 kg", weight_kg: 6.8, active: true },
        { id: 3, name: "Java 5.4 kg", weight_kg: 5.4, active: true },
        { id: 4, name: "Java histórica", weight_kg: 6.8, active: false },
      ],
    },
  };
}

function createHarness() {
  const state = {
    data: null,
    activeLane: 1,
    sex: "MACHO",
    busy: false,
    pendingCapture: null,
    pendingUpgradeBlocked: false,
    directClientIds: { 5: 10, 6: 10 },
    draftClientInvalid: {},
  };
  const elements = Object.fromEntries([
    "operatingDate", "activeLaneNumber", "sexChoice", "assignmentTitle", "assignmentHelp",
    "externalSummaryLabel", "birdsPerCage", "cageCount", "defaultMaleBirdsPerCage",
    "defaultFemaleBirdsPerCage", "defaultCageCount", "openSettings", "openManualWeight", "manualWeight",
  ].map((name) => [name, { value: "" }]));
  Object.assign(elements, {
    cageType: selectElement(),
    defaultCageType: selectElement(),
    defaultExternalOwner: selectElement(),
    laneDestinations: Array.from({ length: 6 }, selectElement),
    lanes: [], laneButtons: [], sexButtons: [],
    capturePanel: { classList: { toggle() {} } },
    settingsModal: { hidden: true },
    manualWeightModal: { hidden: true },
    summaryModal: { hidden: true },
  });
  const calls = { requests: [], messages: [], settingsMessages: [], timers: [] };
  let respond = async () => ({ data: state.data });
  const dependencies = {
    state, elements,
    document: { createElement: () => ({ value: "", textContent: "" }) },
    globalThis: { setTimeout(callback) { calls.timers.push(callback); } },
    // Se sustituyen sólo DOM auxiliar, transporte y renderizados ajenos a los valores predeterminados.
    dispatchDraft: () => ({ weighings: [] }),
    hydrateDispatchDrafts() {}, reconcileDirectClientSelections() {},
    renderLaneAssignments() {}, renderRecords() {}, renderTotals() {}, renderSelectedTotals() {},
    renderSummaryDetail() {}, updateCaptureAvailability() {}, updateScaleUi() {},
    setMessage(...args) { calls.messages.push(args); },
    setSettingsMessage(...args) { calls.settingsMessages.push(args); },
    setManualWeightMessage() {},
    openDialog(modal) { modal.hidden = false; },
    closeDialog(modal) { modal.hidden = true; },
    restorePendingCapture: async () => false,
    refreshLegacyPendingFromSettings() {},
    firstValidationMessage: (error) => error.message,
    apiRequest(path, options) {
      calls.requests.push({ path, method: options.method, payload: JSON.parse(options.body) });
      return respond(path, options);
    },
  };
  const functions = [
    "escapeHtml", "optionMarkup", "setSelectOptions", "catalogItem", "laneConfiguration",
    "laneProfile", "laneDestination", "captureDefaults", "applyCaptureDefaults",
    "populateConfiguration", "renderData", "selectLane", "selectSex", "formatOperatingDate",
    "openSettings", "closeSettings", "saveSettings", "openManualWeight", "closeManualWeight",
  ];
  const constants = source.slice(source.indexOf("const ZOOM_LEVELS ="), source.indexOf("const elements ="));
  const api = new Function(...Object.keys(dependencies), `
    ${constants}
    ${functions.map(functionSource).join("\n")}
    return { ${functions.join(", ")} };
  `)(...Object.values(dependencies));

  return {
    state, elements, calls, api,
    respondWith(callback) { respond = callback; },
    inputValues() {
      return [elements.birdsPerCage.value, elements.cageCount.value, elements.cageType.value];
    },
    editCapture(birds = "12", cages = "3", cageType = "1") {
      elements.birdsPerCage.value = birds;
      elements.cageCount.value = cages;
      elements.cageType.value = cageType;
    },
  };
}

test("la primera carga usa macho 7, hembra 9, cinco javas y java 6.8 aunque la primera sea 7 kg", () => {
  const h = createHarness();
  h.api.renderData(receptionData({ default_cage_type_id: null }));
  assert.deepEqual(h.api.captureDefaults(), {
    maleBirdsPerCage: 7, femaleBirdsPerCage: 9, cageCount: 5, cageTypeId: 2,
  });
  assert.deepEqual(h.inputValues(), ["7", "5", "2"]);
  h.api.selectLane(2);
  assert.deepEqual(h.inputValues(), ["9", "5", "2"]);
  h.api.selectLane(3);
  assert.deepEqual(h.inputValues(), ["7", "5", "2"]);
  h.api.selectLane(4);
  assert.deepEqual(h.inputValues(), ["9", "5", "2"]);
});

test("los predeterminados personalizados se aplican por sexo y se muestran en configuración", () => {
  const h = createHarness();
  h.api.renderData(receptionData({
    default_male_birds_per_cage: 11, default_female_birds_per_cage: 13,
    default_cage_count: 4, default_cage_type_id: 3,
  }));
  assert.deepEqual(h.api.captureDefaults(), {
    maleBirdsPerCage: 11, femaleBirdsPerCage: 13, cageCount: 4, cageTypeId: 3,
  });
  assert.deepEqual(h.inputValues(), ["11", "4", "3"]);
  h.api.selectLane(2);
  assert.deepEqual(h.inputValues(), ["13", "4", "3"]);
  assert.equal(h.elements.defaultMaleBirdsPerCage.value, "11");
  assert.equal(h.elements.defaultFemaleBirdsPerCage.value, "13");
  assert.equal(h.elements.defaultCageCount.value, "4");
  assert.equal(h.elements.defaultCageType.value, "3");
  assert.deepEqual(h.elements.defaultCageType.options.map(({ value }) => value), ["", "1", "2", "3"]);
});

test("una java predeterminada inactiva usa 6.8 y no elige otro peso si tampoco existe 6.8 activa", () => {
  const h = createHarness();
  h.api.renderData(receptionData({ default_cage_type_id: 4 }));
  assert.equal(h.api.captureDefaults().cageTypeId, 2);
  assert.equal(h.elements.cageType.value, "2");
  h.state.data.catalog.cage_types = h.state.data.catalog.cage_types.filter(({ id }) => id !== 2);
  h.api.populateConfiguration();
  h.api.applyCaptureDefaults();
  assert.equal(h.api.captureDefaults().cageTypeId, null);
  assert.equal(h.elements.cageType.value, "");
  assert.equal(h.elements.defaultCageType.value, "");
});

test("repintar, elegir la misma columna y abrir o cancelar diálogos conserva la captura editada", () => {
  const h = createHarness();
  h.api.renderData(receptionData());
  h.editCapture();
  h.api.selectLane(1);
  assert.deepEqual(h.inputValues(), ["12", "3", "1"]);
  h.api.renderData(receptionData({ default_male_birds_per_cage: 15, default_cage_count: 8, default_cage_type_id: 3 }));
  assert.deepEqual(h.inputValues(), ["12", "3", "1"]);
  h.api.openSettings();
  h.elements.defaultCageCount.value = "20";
  h.api.closeSettings();
  h.api.openManualWeight();
  h.api.closeManualWeight();
  assert.deepEqual(h.inputValues(), ["12", "3", "1"]);
  assert.equal(h.elements.settingsModal.hidden, true);
  assert.equal(h.elements.manualWeightModal.hidden, true);
  h.api.selectLane(3);
  assert.deepEqual(h.inputValues(), ["15", "8", "3"]);
});

test("cambiar sexo en despacho actualiza sólo aves por java y repetir el mismo sexo conserva la edición", () => {
  const h = createHarness();
  h.api.renderData(receptionData());
  h.api.selectLane(5);
  h.editCapture();
  h.api.selectSex("HEMBRA");
  assert.deepEqual(h.inputValues(), ["9", "3", "1"]);
  h.elements.birdsPerCage.value = "14";
  h.api.selectSex("HEMBRA");
  assert.deepEqual(h.inputValues(), ["14", "3", "1"]);
  h.api.selectSex("MACHO");
  assert.deepEqual(h.inputValues(), ["7", "3", "1"]);
  h.api.selectLane(6);
  assert.deepEqual(h.inputValues(), ["7", "5", "2"]);
});

test("una captura pendiente conserva sexo, cantidades y java inactiva al repintar y abrir configuración", () => {
  const h = createHarness();
  h.api.renderData(receptionData());
  h.api.selectLane(5);
  h.api.selectSex("HEMBRA");
  h.elements.cageType.append({ value: "4", textContent: "Java histórica · captura pendiente" });
  h.editCapture("17", "8", "4");
  h.state.pendingCapture = { lane: 5, sex: "HEMBRA", birds_per_cage: 17, cage_count: 8, cage_type_id: 4 };
  h.api.applyCaptureDefaults();
  h.api.selectLane(6);
  h.api.selectSex("MACHO");
  h.api.selectLane(5);
  h.api.selectSex("HEMBRA");
  h.api.renderData(receptionData({ default_female_birds_per_cage: 20, default_cage_count: 10 }));
  h.api.openSettings();
  h.api.closeSettings();
  assert.equal(h.state.activeLane, 5);
  assert.equal(h.state.sex, "HEMBRA");
  assert.deepEqual(h.inputValues(), ["17", "8", "4"]);
  assert.ok(h.elements.cageType.options.some((option) => option.value === "4"));
  assert.ok(!h.elements.defaultCageType.options.some((option) => option.value === "4"));
});

test("sin datos no se aplican predeterminados sobre cantidades ya ingresadas", () => {
  const h = createHarness();
  h.elements.birdsPerCage.value = "18";
  h.elements.cageCount.value = "6";
  h.api.applyCaptureDefaults();
  assert.deepEqual(h.inputValues(), ["18", "6", ""]);
});

test("guardar configuración envía los cuatro campos y aplica valores sólo después de la confirmación", async () => {
  const h = createHarness();
  h.api.renderData(receptionData());
  h.editCapture();
  h.elements.defaultMaleBirdsPerCage.value = "11";
  h.elements.defaultFemaleBirdsPerCage.value = "13";
  h.elements.defaultCageCount.value = "4";
  h.elements.defaultCageType.value = "3";
  let confirm;
  h.respondWith(() => new Promise((resolve) => { confirm = resolve; }));
  const saving = h.api.saveSettings({ preventDefault() {} });
  assert.deepEqual(h.inputValues(), ["12", "3", "1"]);
  assert.equal(h.state.busy, true);
  const request = h.calls.requests[0];
  assert.equal(request.path, "/recepcion-pollo-vivo/configuracion");
  assert.equal(request.method, "PUT");
  assert.equal(request.payload.default_male_birds_per_cage, 11);
  assert.equal(request.payload.default_female_birds_per_cage, 13);
  assert.equal(request.payload.default_cage_count, 4);
  assert.equal(request.payload.default_cage_type_id, 3);
  confirm({ data: receptionData(request.payload) });
  await saving;
  assert.deepEqual(h.inputValues(), ["11", "4", "3"]);
  assert.equal(h.state.busy, false);
  assert.equal(h.calls.settingsMessages.at(-1)[1], "success");
});

test("un error al guardar configuración conserva la captura y permite corregir los predeterminados", async () => {
  const h = createHarness();
  h.api.renderData(receptionData());
  h.editCapture();
  h.elements.defaultMaleBirdsPerCage.value = "11";
  h.elements.defaultCageCount.value = "4";
  h.respondWith(async () => { throw new Error("No se pudo guardar"); });
  await h.api.saveSettings({ preventDefault() {} });
  assert.deepEqual(h.inputValues(), ["12", "3", "1"]);
  assert.equal(h.elements.defaultMaleBirdsPerCage.value, "11");
  assert.equal(h.elements.defaultCageCount.value, "4");
  assert.equal(h.state.data.configuration.default_male_birds_per_cage, 7);
  assert.equal(h.state.busy, false);
  assert.equal(h.calls.settingsMessages.at(-1)[1], "error");
});
