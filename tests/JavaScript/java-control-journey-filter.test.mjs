import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
  new URL("../../public/js/control-javas.js", import.meta.url),
  "utf8"
);

function sourceBetween(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);

  assert.notEqual(start, -1, `No se encontró ${startMarker}`);
  assert.notEqual(end, -1, `No se encontró ${endMarker}`);

  return source.slice(start, end);
}

function textElement() {
  return {
    textContent: "",
    classList: { toggle() {} }
  };
}

test("el cuadre histórico usa el snapshot guardado y no los saldos actuales", () => {
  const elementNames = [
    "dailyLocalTotal", "trayDailyLocalTotal", "dailyTruckTotal", "trayDailyTruckTotal",
    "dailyQuantity", "trayDailyQuantity", "dailyExpected", "trayDailyExpected",
    "dailyDifference", "trayDailyDifference", "dailyInternalTotal", "trayDailyInternalTotal",
    "dailyInsideTotal", "trayDailyInsideTotal", "dailyExternalTotal", "trayDailyExternalTotal",
    "dailyAccountedTotal", "trayDailyAccountedTotal", "dailyPropertyTotal", "trayDailyPropertyTotal",
    "reconciliationEyebrow", "dailyTruckHelp", "dailyActionHelp", "dailyDifferenceLabel"
  ];
  const elements = Object.fromEntries(elementNames.map((name) => [name, textElement()]));
  const state = {
    trucks: [],
    countBreakdown: {
      configured: true,
      detailed: true,
      local: { javas: 42, trays: 30 },
      trucks_total: { javas: 7, trays: 4 },
      direct_total: { javas: 49, trays: 34 },
      expected_direct: { javas: 50, trays: 36 },
      difference: { javas: -1, trays: -2 },
      internal_clients: { javas: 6, trays: 5 },
      inside_avicola: { javas: 55, trays: 39 },
      external_clients: { javas: 14, trays: 9 },
      accounted_total: { javas: 69, trays: 48 },
      property_total: { javas: 70, trays: 50 }
    }
  };
  const render = new Function(
    "state",
    "elements",
    "normalizeCountBreakdown",
    "setText",
    "signedQuantity",
    "differenceDescription",
    `${sourceBetween("function renderHistoricalReconciliation", "function updateDailyReconciliation")}
     return renderHistoricalReconciliation;`
  )(
    state,
    elements,
    () => { throw new Error("No debe reconstruir el snapshot"); },
    (element, value) => { if (element) element.textContent = String(value); },
    (value) => value > 0 ? `+${value}` : String(value),
    (javas, trays) => `diferencias ${javas}/${trays}`
  );

  render();

  assert.equal(elements.dailyLocalTotal.textContent, "42");
  assert.equal(elements.dailyTruckTotal.textContent, "7");
  assert.equal(elements.dailyExpected.textContent, "50");
  assert.equal(elements.dailyDifference.textContent, "-1");
  assert.equal(elements.dailyExternalTotal.textContent, "14");
  assert.equal(elements.dailyPropertyTotal.textContent, "70");
  assert.equal(elements.dailyDifferenceLabel.textContent, "diferencias -1/-2");
});

test("un conteo histórico anterior no inventa valores de bandejas que no fueron guardados", () => {
  const elementNames = [
    "dailyLocalTotal", "trayDailyLocalTotal", "dailyTruckTotal", "trayDailyTruckTotal",
    "dailyQuantity", "trayDailyQuantity", "dailyExpected", "trayDailyExpected",
    "dailyDifference", "trayDailyDifference", "dailyInternalTotal", "trayDailyInternalTotal",
    "dailyInsideTotal", "trayDailyInsideTotal", "dailyExternalTotal", "trayDailyExternalTotal",
    "dailyAccountedTotal", "trayDailyAccountedTotal", "dailyPropertyTotal", "trayDailyPropertyTotal",
    "reconciliationEyebrow", "dailyTruckHelp", "dailyActionHelp", "dailyDifferenceLabel"
  ];
  const elements = Object.fromEntries(elementNames.map((name) => [name, textElement()]));
  const emptyPair = { javas: null, trays: null };
  const state = {
    trucks: [],
    countBreakdown: {
      configured: true,
      detailed: false,
      local: emptyPair,
      trucks_total: emptyPair,
      direct_total: { javas: 40, trays: null },
      expected_direct: { javas: 45, trays: null },
      difference: { javas: -5, trays: null },
      internal_clients: emptyPair,
      inside_avicola: emptyPair,
      external_clients: emptyPair,
      accounted_total: emptyPair,
      property_total: emptyPair
    }
  };
  const render = new Function(
    "state",
    "elements",
    "normalizeCountBreakdown",
    "setText",
    "signedQuantity",
    "differenceDescription",
    sourceBetween("function renderHistoricalReconciliation", "function updateDailyReconciliation")
      + "\nreturn renderHistoricalReconciliation;"
  )(
    state,
    elements,
    () => { throw new Error("No debe reconstruir el snapshot"); },
    (element, value) => { if (element) element.textContent = String(value); },
    (value) => value > 0 ? "+" + value : String(value),
    () => { throw new Error("Un conteo anterior no tiene desglose completo"); }
  );

  render();

  assert.equal(elements.dailyExpected.textContent, "45");
  assert.equal(elements.trayDailyExpected.textContent, "—");
  assert.equal(elements.reconciliationEyebrow.textContent, "Conteo anterior");
  assert.match(elements.dailyActionHelp.textContent, /información que quedó guardada/);
});

test("una jornada histórica sin conteo lo indica sin afirmar que existe un cuadre guardado", () => {
  const elementNames = [
    "dailyLocalTotal", "trayDailyLocalTotal", "dailyTruckTotal", "trayDailyTruckTotal",
    "dailyQuantity", "trayDailyQuantity", "dailyExpected", "trayDailyExpected",
    "dailyDifference", "trayDailyDifference", "dailyInternalTotal", "trayDailyInternalTotal",
    "dailyInsideTotal", "trayDailyInsideTotal", "dailyExternalTotal", "trayDailyExternalTotal",
    "dailyAccountedTotal", "trayDailyAccountedTotal", "dailyPropertyTotal", "trayDailyPropertyTotal",
    "reconciliationEyebrow", "dailyTruckHelp", "dailyActionHelp", "dailyDifferenceLabel"
  ];
  const elements = Object.fromEntries(elementNames.map((name) => [name, textElement()]));
  const emptyPair = { javas: null, trays: null };
  const state = {
    trucks: [],
    countBreakdown: {
      configured: false,
      detailed: false,
      local: emptyPair,
      trucks_total: emptyPair,
      direct_total: emptyPair,
      expected_direct: emptyPair,
      difference: emptyPair,
      internal_clients: emptyPair,
      inside_avicola: emptyPair,
      external_clients: emptyPair,
      accounted_total: emptyPair,
      property_total: emptyPair
    }
  };
  const render = new Function(
    "state",
    "elements",
    "normalizeCountBreakdown",
    "setText",
    "signedQuantity",
    "differenceDescription",
    sourceBetween("function renderHistoricalReconciliation", "function updateDailyReconciliation")
      + "\nreturn renderHistoricalReconciliation;"
  )(
    state,
    elements,
    () => { throw new Error("No debe reconstruir el snapshot"); },
    (element, value) => { if (element) element.textContent = String(value); },
    (value) => String(value),
    () => { throw new Error("No existe una diferencia registrada"); }
  );

  render();

  assert.equal(elements.reconciliationEyebrow.textContent, "Sin conteo registrado");
  assert.match(elements.dailyActionHelp.textContent, /no tiene un conteo guardado/);
  assert.equal(elements.dailyDifferenceLabel.textContent, "No se registró un conteo en esta jornada.");
});

test("el selector conserva una vista operativa separada de los conteos cerrados", () => {
  const elements = {
    journey: { innerHTML: "", disabled: false, value: "" },
    journeyTitle: textElement(),
    journeyWindow: textElement()
  };
  const state = {
    activeJourneyId: 2,
    journeys: [
      {
        id: 2,
        operating_date: "2026-08-08",
        status: "ABIERTA",
        starts_at: "inicio actual",
        ends_at: "fin actual",
        has_count: true
      },
      {
        id: 1,
        operating_date: "2026-08-07",
        status: "CERRADA",
        starts_at: "inicio anterior",
        ends_at: "fin anterior",
        has_count: true
      }
    ]
  };
  const render = new Function(
    "page",
    "state",
    "elements",
    "setText",
    "formatOperatingDate",
    "formatDate",
    "escapeHtml",
    sourceBetween("function renderJourneys", "function renderCountJourney")
      + "\nreturn renderJourneys;"
  )(
    "inventory",
    state,
    elements,
    (element, value) => { if (element) element.textContent = String(value); },
    (value) => value,
    (value) => value,
    (value) => String(value)
  );

  render(null);

  assert.equal(elements.journey.value, "");
  assert.match(elements.journey.innerHTML, /Jornada actual · vista operativa/);
  assert.doesNotMatch(elements.journey.innerHTML, /value="2"/);
  assert.match(elements.journey.innerHTML, /value="1"/);
  assert.match(elements.journeyTitle.textContent, /2026-08-08/);

  state.journeys[0].status = "CERRADA";
  render(null);

  assert.equal(elements.journey.value, "");
  assert.match(elements.journey.innerHTML, /value="2"/);
  assert.match(elements.journey.innerHTML, /Conteo cerrado de la jornada actual/);
});

test("un conteo anterior sin desglose no afirma que guardó saldos por cliente", () => {
  const elementNames = [
    "holderTitle", "holderNote", "externalCompanyJavas", "externalCompanyTrays",
    "externalClientsCount", "externalClientsCaption", "internalCompanyJavas",
    "internalCompanyTrays", "internalClientsCount", "internalClientsCaption",
    "externalHolderCount", "externalHolderJavas", "externalHolderTrays",
    "internalHolderCount", "internalHolderJavas", "internalHolderTrays"
  ];
  const elements = Object.fromEntries(elementNames.map((name) => [name, textElement()]));
  elements.externalHolderList = { innerHTML: "" };
  elements.internalHolderList = { innerHTML: "" };
  const state = {
    countIsHistorical: true,
    trucks: [],
    clientHolders: { external: {}, internal: {} },
    countBreakdown: {
      configured: true,
      detailed: false,
      external_clients: { javas: null, trays: null },
      internal_clients: { javas: null, trays: null }
    }
  };
  const render = new Function(
    "state",
    "elements",
    "normalizeClientHolders",
    "normalizeCountBreakdown",
    "setText",
    "renderHolderGroup",
    sourceBetween("function renderClientHolders", "function renderInventory")
      + "\nreturn renderClientHolders;"
  )(
    state,
    elements,
    () => { throw new Error("Ya existe un estado de clientes"); },
    () => { throw new Error("Ya existe un conteo"); },
    (element, value) => { if (element) element.textContent = String(value); },
    () => { throw new Error("No debe mostrar saldos actuales"); }
  );

  render();

  assert.equal(elements.holderTitle.textContent, "Clientes del conteo anterior");
  assert.match(elements.holderNote.textContent, /no conserva totales históricos/);
  assert.equal(elements.externalClientsCaption.textContent, " sin saldo histórico");
  assert.equal(elements.internalClientsCaption.textContent, " sin saldo histórico");
  assert.equal(elements.externalHolderCount.textContent, "Sin detalle");
});

test("una jornada histórica muestra solo los camiones registrados en ese conteo", () => {
  const elements = { dailyTruckInputs: { innerHTML: "" } };
  const state = {
    countIsHistorical: true,
    trucks: [],
    countBreakdown: {
      detailed: true,
      trucks: [
        {
          id: 1,
          plate: "ANT-001",
          current_plate: "NUE-999",
          active: false,
          recorded: true,
          java_quantity: 7,
          tray_quantity: 4
        },
        {
          id: 2,
          plate: "NVA-002",
          current_plate: "NVA-002",
          active: true,
          recorded: false,
          java_quantity: 0,
          tray_quantity: 0
        }
      ]
    }
  };
  const render = new Function(
    "state",
    "elements",
    "normalizeCountBreakdown",
    "escapeHtml",
    `${sourceBetween("function renderDailyTruckInputs", "function readNonNegativeInteger")}
     return renderDailyTruckInputs;`
  )(
    state,
    elements,
    () => { throw new Error("No debe reemplazar el conteo cargado"); },
    (value) => String(value)
  );

  render();

  assert.match(elements.dailyTruckInputs.innerHTML, /ANT-001/);
  assert.match(elements.dailyTruckInputs.innerHTML, /value="7"/);
  assert.match(elements.dailyTruckInputs.innerHTML, /Placa registrada en la jornada/);
  assert.doesNotMatch(elements.dailyTruckInputs.innerHTML, /NUE-999/);
  assert.doesNotMatch(elements.dailyTruckInputs.innerHTML, /NVA-002/);
});

test("el modo histórico queda protegido contra escrituras", () => {
  const loadSource = sourceBetween("async function loadControl", "function restoreInventoryAfterLoadFailure");
  const dailyCountSource = sourceBetween("function renderDailyCount", "function openInventoryModal");
  const inventorySubmitSource = sourceBetween("async function submitInventory", "async function submitDailyCount");
  const countSubmitSource = sourceBetween("async function submitDailyCount", "async function submitReceipt");

  assert.match(dailyCountSource, /state\.countCanEdit/);
  assert.match(dailyCountSource, /input\.disabled = !canEdit/);
  assert.match(inventorySubmitSource, /if \(state\.countIsHistorical\)/);
  assert.match(countSubmitSource, /state\.countIsHistorical \|\| !state\.countCanEdit/);
  assert.match(inventorySubmitSource, /journeyId: ""/);
  assert.match(countSubmitSource, /journeyId: ""/);
  assert.match(inventorySubmitSource, /restoreOnError: false/);
  assert.match(countSubmitSource, /restoreOnError: false/);
  assert.match(inventorySubmitSource, /if \(!refreshed\)/);
  assert.match(countSubmitSource, /if \(!refreshed\)/);
  assert.match(inventorySubmitSource, /elements\.journey\.disabled = true/);
  assert.match(countSubmitSource, /elements\.journey\.disabled = true/);
  assert.match(inventorySubmitSource, /lockInventoryUntilReload/);
  assert.match(countSubmitSource, /lockInventoryUntilReload/);
  assert.match(loadSource, /return true/);
  assert.match(loadSource, /return false/);
});

test("cada cliente puede abrir la edición de saldo aunque no tenga pendientes", () => {
  const elements = { clientRows: { innerHTML: "" } };
  const state = {
    clients: [
      { id: 1, name: "CON SALDO", document_number: "100", java_balance: 3, tray_balance: 2 },
      { id: 2, name: "SIN SALDO", document_number: "200", java_balance: 0, tray_balance: 0 }
    ]
  };
  const render = new Function(
    "state",
    "elements",
    "escapeHtml",
    `${sourceBetween("function renderClients", "function renderClientPagination")}
     return renderClients;`
  )(
    state,
    elements,
    (value) => String(value)
  );

  render();

  assert.equal((elements.clientRows.innerHTML.match(/data-edit-balance-client=/g) || []).length, 2);
  assert.match(elements.clientRows.innerHTML, /data-edit-balance-client="2">Editar saldo/);
  assert.match(elements.clientRows.innerHTML, /data-receive-client="2" disabled/);
});

test("la edición no interpreta campos vacíos como saldo cero", () => {
  const submitSource = sourceBetween("async function submitBalanceEdit", "async function loadControl");

  assert.match(submitSource, /javaValue === "" \|\| trayValue === ""/);
  assert.match(submitSource, /Completa los dos nuevos saldos/);
  assert.match(submitSource, /const javaBalance = Number\(javaValue\)/);
  assert.match(submitSource, /const trayBalance = Number\(trayValue\)/);
});

test("la edición bloquea cierre y navegación mientras guarda y conserva el foco", () => {
  const closeSource = sourceBetween("function closeBalanceEditModal", "function setBalanceEditBusy");
  const busySource = sourceBetween("function setBalanceEditBusy", "function trapBalanceEditFocus");
  const focusSource = sourceBetween("function trapBalanceEditFocus", "async function submitBalanceEdit");
  const submitSource = sourceBetween("async function submitBalanceEdit", "async function loadControl");

  assert.match(closeSource, /state\.balanceEdit\?\.submitting/);
  assert.match(closeSource, /trigger \|\| elements\.balanceMessage/);
  assert.match(busySource, /balanceEditTraceLink\.tabIndex = isBusy \? -1 : 0/);
  assert.match(busySource, /balanceEditDialog\?\.focus/);
  assert.match(focusSource, /event\.key !== "Tab"/);
  assert.match(focusSource, /event\.preventDefault\(\)/);
  assert.match(submitSource, /edit\.submitting = true/);
  assert.match(submitSource, /setBalanceEditBusy\(true\)/);
});

test("la trazabilidad presenta la corrección con responsable, motivo y saldos", () => {
  const elements = { movementRows: { innerHTML: "" } };
  const state = {
    movements: [{
      type: "AJUSTE_SALDO",
      is_adjustment: true,
      occurred_at: "2026-08-08T10:00:00Z",
      client: { name: "CLIENTE AJUSTADO" },
      java_delta: 4,
      tray_delta: -5,
      balance_before: { javas: 10, trays: 8 },
      balance_after: { javas: 14, trays: 3 },
      observations: "Error de digitación",
      created_by: { name: "Operador Uno" }
    }]
  };
  const render = new Function(
    "state",
    "elements",
    "formatDate",
    "escapeHtml",
    "assetStack",
    "signedQuantity",
    "assetPairText",
    `${sourceBetween("function renderMovements", "function renderTruckActivity")}
     return renderMovements;`
  )(
    state,
    elements,
    (value) => value,
    (value) => String(value ?? ""),
    (javas, trays, tone) => `${tone}:${javas}/${trays}`,
    (value) => value > 0 ? `+${value}` : String(value),
    (javas, trays) => `${javas} javas · ${trays} bandejas`
  );

  render();

  assert.match(elements.movementRows.innerHTML, /Corrección/);
  assert.match(elements.movementRows.innerHTML, /is-adjustment:\+4\/-5/);
  assert.match(elements.movementRows.innerHTML, /Operador Uno/);
  assert.match(elements.movementRows.innerHTML, /10 javas · 8 bandejas.*14 javas · 3 bandejas/);
  assert.match(elements.movementRows.innerHTML, /Error de digitación/);
});

test("si falla el cambio de jornada se restaura el último estado utilizable", () => {
  const calls = [];
  const state = { countJourneyId: 27, countJourneyFilterId: 19 };
  const elements = { dailyMessage: textElement() };
  const restore = new Function(
    "page",
    "state",
    "elements",
    "renderJourneys",
    "renderInventory",
    "renderClientHolders",
    "renderDailyCount",
    "setMessage",
    sourceBetween("function restoreInventoryAfterLoadFailure", "async function submitInventory")
      + "\nreturn restoreInventoryAfterLoadFailure;"
  )(
    "inventory",
    state,
    elements,
    (journeyId) => calls.push(["journey", journeyId]),
    () => calls.push(["inventory"]),
    () => calls.push(["holders"]),
    () => calls.push(["count"]),
    (element, message, isError) => {
      element.textContent = message;
      element.isError = isError;
    }
  );

  restore("Error de red");

  assert.deepEqual(calls, [
    ["journey", 19],
    ["inventory"],
    ["holders"],
    ["count"]
  ]);
  assert.equal(
    elements.dailyMessage.textContent,
    "No se pudo actualizar la pantalla. Conservamos los últimos datos visibles. Error de red"
  );
  assert.equal(elements.dailyMessage.isError, true);
});

test("un guardado confirmado sin recarga bloquea acciones hasta actualizar la página", () => {
  const inventoryInput = { disabled: false };
  const inventoryButton = { disabled: false };
  const dailyInput = { disabled: false };
  const dailyButton = { disabled: false };
  const state = { requiresReload: false };
  const elements = {
    journey: { disabled: false },
    inventoryOpen: { disabled: false },
    inventoryForm: {
      querySelectorAll: () => [inventoryInput, inventoryButton]
    },
    dailyForm: {
      querySelectorAll: () => [dailyInput, dailyButton]
    }
  };
  const lock = new Function(
    "state",
    "elements",
    sourceBetween("function lockInventoryUntilReload", "async function submitInventory")
      + "\nreturn lockInventoryUntilReload;"
  )(state, elements);

  lock();

  assert.equal(state.requiresReload, true);
  assert.equal(elements.journey.disabled, true);
  assert.equal(elements.inventoryOpen.disabled, true);
  assert.equal(inventoryInput.disabled, true);
  assert.equal(inventoryButton.disabled, true);
  assert.equal(dailyInput.disabled, true);
  assert.equal(dailyButton.disabled, true);
});
