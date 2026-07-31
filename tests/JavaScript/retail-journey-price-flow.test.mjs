import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const retailDispatchSource = readFileSync(
  new URL("../../public/js/despacho-minorista.js", import.meta.url),
  "utf8"
);

function sourceBetween(startMarker, endMarker) {
  const start = retailDispatchSource.indexOf(startMarker);
  const end = retailDispatchSource.indexOf(endMarker, start);

  assert.notEqual(start, -1, `No se encontró ${startMarker}`);
  assert.notEqual(end, -1, `No se encontró ${endMarker}`);

  return retailDispatchSource.slice(start, end);
}

const normalizeListSource = sourceBetween(
  "function normalizeList",
  "function restoreLists"
);
const keyboardBusySource = sourceBetween(
  "function setTouchKeyboardBusy",
  "function trapTabWithin"
);
const keyboardActionSource = sourceBetween(
  "async function handleTouchKeyboardAction",
  "function defaultTypographyValues"
);
const effectivePriceSource = sourceBetween(
  "function normalizePriceRecord",
  "function missingPriceTypes"
);
const priceEditorSource = sourceBetween(
  "function priceChickenTypeForList",
  "function requiresDelivery"
);
const clearRegisteredListSource = sourceBetween(
  "function clearRegisteredList",
  "function printRegisteredTicket"
);
const saveDispatchSource = sourceBetween(
  "async function saveDispatch",
  "function serialOptionsFromForm"
);
const priceEventSource = sourceBetween(
  'elements.assignPrice.addEventListener("click"',
  'elements.saveDispatch.addEventListener("click"'
);

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });

  return { promise, resolve, reject };
}

function normalizeStoredList(list, overrideVersion = 2) {
  return new Function(
    "roundMoney",
    "createDraftId",
    "OPERATION_RETURN",
    "OPERATION_SALE",
    "TICKET_PRICE_OVERRIDE_VERSION",
    `${normalizeListSource}\nreturn normalizeList;`
  )(
    (value) => Math.round(Number(value) * 100) / 100,
    () => "new-draft",
    "DEVOLUCION",
    "VENTA",
    overrideVersion
  )(list);
}

function createRuntime(options = {}) {
  const chickenType = options.chickenType || { code: "POLLO_PELADO", name: "Pollo pelado" };
  const clientPrice = {
    price_kg: options.clientPrice ?? 8.5,
    source: "CLIENTE",
    history_id: 101
  };
  const client = {
    id: 7,
    name: "Cliente con tarifa propia",
    prices: {
      [chickenType.code]: clientPrice
    }
  };
  const list = {
    draftId: "draft-1",
    clientId: options.withClient === false ? "" : String(client.id),
    operationType: "VENTA",
    priceOverrides: options.override === undefined
      ? {}
      : { [chickenType.code]: options.override },
    saving: false,
    items: []
  };
  const state = {
    activeList: 0,
    savingJourneyPrice: false,
    catalog: {
      clients: [client],
      general_prices: {
        [chickenType.code]: {
          price_kg: options.journeyPrice ?? 9,
          source: "GENERAL",
          history_id: 202
        }
      }
    },
    lists: [list],
    pendingCapture: null,
    selectedItem: null,
    deliveryMode: null
  };
  const keyboardButtons = Array.from({ length: 4 }, () => ({
    disabled: false,
    classList: { contains: () => false }
  }));
  const elements = {
    directPriceInput: {
      value: "",
      placeholder: "",
      dataset: {}
    },
    pricePreview: { textContent: "S/ 8.50 por kg" },
    touchKeyboard: {
      hidden: true,
      querySelectorAll(selector) {
        assert.equal(selector, "button");
        return keyboardButtons;
      }
    },
    station: {
      inert: false,
      removeAttribute(attribute) {
        assert.equal(attribute, "aria-hidden");
      }
    }
  };
  const touchKeyboardState = {
    target: null,
    buffer: "",
    acceptHandler: null,
    accepting: false
  };
  const requests = [];
  const messages = [];
  const issues = [];
  const pendingResponses = [];
  let openCalls = 0;
  let closeCalls = 0;
  let renderCalls = 0;
  let renderListCalls = 0;
  let persistCalls = 0;
  let draftSequence = 1;
  let shownTicket = null;
  let printedTicket = null;

  function closeTouchKeyboard() {
    if (elements.touchKeyboard.hidden) return;
    closeCalls += 1;
    elements.touchKeyboard.hidden = true;
    touchKeyboardState.target = null;
    touchKeyboardState.acceptHandler = null;
    touchKeyboardState.accepting = false;
    keyboardButtons.forEach((button) => {
      button.disabled = false;
    });
  }

  function showLocalActionIssue(issue) {
    issues.push(issue);
    closeTouchKeyboard();
  }

  function openTouchKeyboard(input, options = {}) {
    openCalls += 1;
    touchKeyboardState.target = input;
    touchKeyboardState.buffer = input.value;
    touchKeyboardState.acceptHandler = options.acceptHandler || null;
    elements.touchKeyboard.hidden = false;
  }

  function apiRequest(path, options) {
    requests.push({ path, options });
    const response = deferred();
    pendingResponses.push(response);
    return response.promise;
  }

  function activeList() {
    return state.lists[state.activeList];
  }

  function catalogClient(candidateList = activeList()) {
    return state.catalog.clients.find(
      (candidate) => String(candidate.id) === String(candidateList?.clientId)
    ) || null;
  }

  function emptyList() {
    draftSequence += 1;

    return {
      draftId: `draft-${draftSequence}`,
      clientId: "",
      operationType: "VENTA",
      ticketPriceOverrideVersion: 2,
      priceOverrides: {},
      saving: false,
      items: []
    };
  }

  const runtimeFactory = new Function(
    "RETAIL_STATION",
    "RETAIL_CHICKEN_TYPE_CODES",
    "RETAIL_API_BASE",
    "OPERATION_RETURN",
    "journeyPriceRevision",
    "journeyPriceRefreshPromise",
    "state",
    "touchKeyboardState",
    "elements",
    "chickenTypeByCode",
    "selectedChickenType",
    "activeList",
    "clientFor",
    "showLocalActionIssue",
    "formatMoney",
    "formatMoneyValue",
    "roundMoney",
    "moneyToCents",
    "hasAtMostMoneyDecimals",
    "renderAll",
    "renderLists",
    "setMessage",
    "apiRequest",
    "persistLists",
    "openTouchKeyboard",
    "normalizedTouchKeyboardValue",
    "closeTouchKeyboard",
    "missingPriceTypes",
    "showMissingPricesError",
    "getRetailDispatchErrorPresentation",
    "showRetailError",
    "emptyList",
    "showRegisteredTicket",
    "printTicketAndReport",
    "hasOpenRetailModal",
    `
      ${effectivePriceSource}
      ${keyboardBusySource}
      ${keyboardActionSource}
      ${priceEditorSource}
      ${clearRegisteredListSource}
      ${saveDispatchSource}

      return {
        applyDirectTicketPrice,
        applyDirectJourneyPrice,
        clearRegisteredList,
        currentClientPrice,
        currentGeneralPrice,
        effectivePrice,
        handleTouchKeyboardAction,
        openDirectPriceEditor,
        openTicketPriceEditor: typeof openTicketPriceEditor === "function"
          ? openTicketPriceEditor
          : null,
        refreshJourneyPrices,
        saveDispatch,
        syncJourneyPrice,
        syncJourneyPriceSnapshot
      };
    `
  );

  const runtime = runtimeFactory(
    "2",
    ["POLLO_BENEFICIADO", "POLLO_PELADO"],
    "/despacho-minorista-2",
    "DEVOLUCION",
    0,
    null,
    state,
    touchKeyboardState,
    elements,
    (code) => code === chickenType.code ? chickenType : null,
    () => chickenType,
    activeList,
    (candidateList) => catalogClient(candidateList),
    showLocalActionIssue,
    (value) => `S/ ${Number(value).toFixed(2)}`,
    (value) => Number(value).toFixed(2),
    (value) => Math.round(Number(value) * 100) / 100,
    (value) => Math.round(Number(value) * 100),
    (value) => /^\d+(?:\.\d{1,2})?$/.test(String(value ?? "").trim()),
    () => {
      renderCalls += 1;
    },
    () => {
      renderListCalls += 1;
    },
    (message) => {
      messages.push(message);
    },
    apiRequest,
    () => {
      persistCalls += 1;
    },
    openTouchKeyboard,
    () => touchKeyboardState.buffer,
    closeTouchKeyboard,
    () => [],
    () => {},
    (error) => ({ summary: error?.message || "Error" }),
    () => {},
    emptyList,
    (ticket) => {
      shownTicket = ticket;
    },
    async (ticket) => {
      printedTicket = ticket;
      return true;
    },
    () => false
  );

  return {
    chickenType,
    client,
    clientPrice,
    elements,
    issues,
    keyboardButtons,
    list,
    messages,
    pendingResponses,
    requests,
    runtime,
    state,
    touchKeyboardState,
    get printedTicket() {
      return printedTicket;
    },
    get shownTicket() {
      return shownTicket;
    },
    get closeCalls() {
      return closeCalls;
    },
    get openCalls() {
      return openCalls;
    },
    get persistCalls() {
      return persistCalls;
    },
    get renderCalls() {
      return renderCalls;
    },
    get renderListCalls() {
      return renderListCalls;
    }
  };
}

test("la tarjeta y el botón Cambiar precio abren editores con responsabilidades distintas", () => {
  assert.match(
    priceEventSource,
    /elements\.assignPrice\.addEventListener\("click", openTicketPriceEditor\)/
  );
  assert.match(
    priceEventSource,
    /elements\.priceCard\?\.addEventListener\("click", openDirectPriceEditor\)/
  );
});

test("Minorista 2 descarta overrides de borradores anteriores incluso sin cliente", () => {
  const stalePublicList = normalizeStoredList({
    draftId: "old-public-draft",
    clientId: "",
    ticketPriceOverrideVersion: 1,
    priceOverrides: { POLLO_PELADO: 10.25 },
    items: []
  });
  const currentList = normalizeStoredList({
    draftId: "current-draft",
    clientId: "7",
    ticketPriceOverrideVersion: 2,
    priceOverrides: { POLLO_PELADO: 10.25 },
    items: []
  });

  assert.deepEqual(stalePublicList.priceOverrides, {});
  assert.deepEqual(currentList.priceOverrides, { POLLO_PELADO: 10.25 });
});

test("el precio efectivo respeta MANUAL sobre CLIENTE y CLIENTE sobre GENERAL", () => {
  const harness = createRuntime({ override: 7.25, clientPrice: 8.5, journeyPrice: 9 });

  assert.deepEqual(
    harness.runtime.effectivePrice(harness.list, harness.chickenType.code),
    { value: 7.25, source: "MANUAL" }
  );

  delete harness.list.priceOverrides[harness.chickenType.code];
  assert.deepEqual(
    harness.runtime.effectivePrice(harness.list, harness.chickenType.code),
    { value: 8.5, source: "CLIENTE" }
  );

  harness.list.clientId = "";
  assert.deepEqual(
    harness.runtime.effectivePrice(harness.list, harness.chickenType.code),
    { value: 9, source: "GENERAL" }
  );
});

test("la tarjeta muestra el precio efectivo pero actualiza solo la jornada del producto seleccionado", async () => {
  const productCases = [
    { code: "POLLO_PELADO", name: "Pollo pelado" },
    { code: "POLLO_BENEFICIADO", name: "Pollo beneficiado" }
  ];

  for (const chickenType of productCases) {
    const harness = createRuntime({
      chickenType,
      override: 7.25,
      clientPrice: 8.5,
      journeyPrice: 9
    });

    harness.runtime.openDirectPriceEditor();

    assert.equal(harness.elements.directPriceInput.value, "7.25");
    assert.deepEqual(
      harness.runtime.effectivePrice(harness.list, chickenType.code),
      { value: 7.25, source: "MANUAL" }
    );
    harness.touchKeyboardState.buffer = "9.75";
    const acceptance = harness.runtime.handleTouchKeyboardAction("accept");

    assert.equal(harness.requests.length, 1);
    assert.equal(harness.requests[0].path, "/operacion/precios-jornada");
    assert.deepEqual(JSON.parse(harness.requests[0].options.body), {
      global_prices: { [chickenType.code]: "9.75" },
      expected_prices: { [chickenType.code]: "9.00" }
    });

    harness.pendingResponses[0].resolve({
      data: { global_prices: { [chickenType.code]: 9.75 } }
    });
    await acceptance;

    assert.equal(harness.state.catalog.general_prices[chickenType.code].price_kg, 9.75);
    assert.equal(harness.list.priceOverrides[chickenType.code], 7.25);
    assert.equal(harness.client.prices[chickenType.code], harness.clientPrice);
    assert.equal(harness.clientPrice.price_kg, 8.5);
    assert.deepEqual(
      harness.runtime.effectivePrice(harness.list, chickenType.code),
      { value: 7.25, source: "MANUAL" }
    );
  }
});

test("Cambiar precio aplica un override de columna sin llamar al endpoint de jornada", async () => {
  const harness = createRuntime({ clientPrice: 8.5, journeyPrice: 9 });

  assert.equal(typeof harness.runtime.openTicketPriceEditor, "function");
  harness.runtime.openTicketPriceEditor();

  assert.equal(harness.elements.directPriceInput.value, "8.50");
  harness.touchKeyboardState.buffer = "10.25";
  await harness.runtime.handleTouchKeyboardAction("accept");

  assert.equal(harness.requests.length, 0);
  assert.equal(harness.list.priceOverrides.POLLO_PELADO, 10.25);
  assert.deepEqual(
    harness.runtime.effectivePrice(harness.list, "POLLO_PELADO"),
    { value: 10.25, source: "MANUAL" }
  );
  assert.equal(harness.state.catalog.general_prices.POLLO_PELADO.price_kg, 9);
  assert.equal(harness.clientPrice.price_kg, 8.5);
  assert.equal(harness.persistCalls, 1);
});

test("el ticket usa el override y al terminar libera cliente y columna para volver a la jornada", async () => {
  const harness = createRuntime({ override: 10.25, clientPrice: 8.5, journeyPrice: 9 });
  harness.list.items = [{ chickenTypeCode: "POLLO_PELADO" }];

  const save = harness.runtime.saveDispatch();
  assert.equal(harness.requests[0].path, "/operacion/precios-jornada");
  harness.pendingResponses[0].resolve({
    data: { global_prices: { POLLO_PELADO: 9.6 } }
  });

  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(harness.requests.length, 2);
  assert.equal(harness.requests[1].path, "/despacho-minorista-2/tickets");
  const body = JSON.parse(harness.requests[1].options.body);
  assert.deepEqual(body.price_overrides, { POLLO_PELADO: "10.25" });
  assert.deepEqual(body.expected_prices, { POLLO_PELADO: "10.25" });

  const ticket = { code: "DM2-OVERRIDE" };
  harness.pendingResponses[1].resolve({ data: ticket, message: "Despacho registrado" });
  await save;

  const cleanList = harness.state.lists[0];
  assert.notEqual(cleanList, harness.list);
  assert.equal(cleanList.clientId, "");
  assert.deepEqual(cleanList.priceOverrides, {});
  assert.deepEqual(cleanList.items, []);
  assert.deepEqual(
    harness.runtime.effectivePrice(cleanList, "POLLO_PELADO"),
    { value: 9.6, source: "GENERAL" }
  );
  assert.equal(harness.shownTicket, ticket);
  assert.equal(harness.printedTicket, ticket);
  assert.equal(harness.clientPrice.price_kg, 8.5);
  assert.equal(harness.state.catalog.general_prices.POLLO_PELADO.price_kg, 9.6);
});

test("una tarifa CLIENTE abre la tarjeta con el precio efectivo y no altera la tarifa asignada", () => {
  const harness = createRuntime();

  harness.runtime.openDirectPriceEditor();

  assert.equal(harness.openCalls, 1);
  assert.equal(harness.requests.length, 0);
  assert.equal(harness.issues.length, 0);
  assert.equal(harness.elements.directPriceInput.value, "8.50");
  assert.equal(harness.client.prices.POLLO_PELADO, harness.clientPrice);
  assert.equal(harness.clientPrice.price_kg, 8.5);
  assert.equal(harness.clientPrice.source, "CLIENTE");
});

test("el refresco remoto actualiza la jornada pero conserva override y tarifa CLIENTE", async () => {
  const harness = createRuntime();
  harness.list.priceOverrides.POLLO_PELADO = 7.25;

  const refresh = harness.runtime.refreshJourneyPrices();

  assert.equal(harness.requests.length, 1);
  assert.equal(harness.requests[0].path, "/operacion/precios-jornada");
  assert.equal(harness.requests[0].options, undefined);

  harness.pendingResponses[0].resolve({
    data: {
      global_prices: { POLLO_PELADO: 9.6 }
    }
  });

  assert.equal(await refresh, true);
  assert.equal(harness.state.catalog.general_prices.POLLO_PELADO.price_kg, 9.6);
  assert.equal(harness.client.prices.POLLO_PELADO, harness.clientPrice);
  assert.equal(harness.clientPrice.price_kg, 8.5);
  assert.equal(harness.clientPrice.source, "CLIENTE");
  assert.equal(harness.list.priceOverrides.POLLO_PELADO, 7.25);
  assert.deepEqual(
    harness.runtime.effectivePrice(harness.list, "POLLO_PELADO"),
    { value: 7.25, source: "MANUAL" }
  );
  assert.equal(harness.persistCalls, 1);
  assert.equal(harness.renderCalls, 1);
});

test("saveDispatch exige revisar un cambio remoto y solo publica tras un segundo intento estable", async () => {
  const harness = createRuntime({ withClient: false });
  harness.list.items = [{ chickenTypeCode: "POLLO_PELADO" }];

  const save = harness.runtime.saveDispatch();

  assert.equal(harness.requests.length, 1);
  assert.equal(harness.requests[0].path, "/operacion/precios-jornada");
  harness.pendingResponses[0].resolve({
    data: {
      global_prices: { POLLO_PELADO: 9.8 }
    }
  });

  await new Promise((resolve) => setImmediate(resolve));
  const unexpectedPost = harness.requests.find((request) => request.options?.method === "POST");
  if (unexpectedPost) {
    harness.pendingResponses.at(-1).resolve({ data: { code: "T-INESPERADO" } });
  }
  await save;

  assert.equal(unexpectedPost, undefined);
  assert.equal(harness.requests.length, 1);
  assert.equal(harness.issues.length, 1);
  assert.equal(harness.issues[0].caption, "Precio de jornada actualizado");
  assert.match(harness.issues[0].title, /revisa el nuevo total/i);
  assert.equal(harness.state.catalog.general_prices.POLLO_PELADO.price_kg, 9.8);
  assert.equal(harness.list.saving, false);
  assert.equal(harness.elements.station.inert, false);
  assert.ok(harness.renderListCalls >= 2);

  const confirmedSave = harness.runtime.saveDispatch();
  assert.equal(harness.requests.length, 2);
  assert.equal(harness.requests[1].path, "/operacion/precios-jornada");
  harness.pendingResponses[1].resolve({
    data: {
      global_prices: { POLLO_PELADO: 9.8 }
    }
  });

  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(harness.requests.length, 3);
  assert.deepEqual(
    harness.requests.map((request) => request.options?.method || "GET"),
    ["GET", "GET", "POST"]
  );
  assert.equal(harness.requests[2].path, "/despacho-minorista-2/tickets");
  const body = JSON.parse(harness.requests[2].options.body);
  assert.deepEqual(body.price_overrides, {});
  assert.deepEqual(body.expected_prices, { POLLO_PELADO: "9.80" });

  harness.pendingResponses[2].resolve({
    data: { code: "DM2-PRUEBA" },
    message: "Despacho registrado"
  });
  await confirmedSave;

  assert.equal(harness.issues.length, 1);
  assert.equal(harness.state.lists[0].saving, false);
});

test("un conflicto de tarifa CLIENTE recarga catálogo y el segundo intento envía el expected vigente", async () => {
  const harness = createRuntime();
  harness.list.items = [{ chickenTypeCode: "POLLO_PELADO" }];

  const firstSave = harness.runtime.saveDispatch();
  assert.equal(harness.requests.length, 1);
  assert.equal(harness.requests[0].path, "/operacion/precios-jornada");
  harness.pendingResponses[0].resolve({
    data: {
      global_prices: { POLLO_PELADO: 9 }
    }
  });

  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(harness.requests.length, 2);
  assert.equal(harness.requests[1].path, "/despacho-minorista-2/tickets");
  assert.deepEqual(JSON.parse(harness.requests[1].options.body).expected_prices, {
    POLLO_PELADO: "8.50"
  });

  const conflict = new Error("El precio ya no está vigente");
  conflict.data = {
    errors: {
      "expected_prices.POLLO_PELADO": ["La tarifa del cliente cambió a S/ 8.85."]
    }
  };
  harness.pendingResponses[1].reject(conflict);

  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(harness.requests.length, 3);
  assert.equal(harness.requests[2].path, "/despacho-minorista-2/catalogo");
  harness.pendingResponses[2].resolve({
    data: {
      clients: [{
        id: 7,
        name: "Cliente con tarifa propia",
        prices: {
          POLLO_PELADO: {
            price_kg: 8.85,
            source: "CLIENTE",
            history_id: 303
          }
        }
      }],
      general_prices: {
        POLLO_PELADO: {
          price_kg: 9,
          source: "GENERAL",
          history_id: 202
        }
      }
    }
  });
  await firstSave;

  const refreshedClientPrice = harness.state.catalog.clients[0].prices.POLLO_PELADO;
  assert.notEqual(refreshedClientPrice, harness.clientPrice);
  assert.equal(refreshedClientPrice.price_kg, 8.85);
  assert.equal(refreshedClientPrice.source, "CLIENTE");
  assert.equal(harness.issues.length, 1);
  assert.equal(harness.issues[0].caption, "Precio actualizado durante la grabación");
  assert.equal(harness.issues[0].message, "La tarifa del cliente cambió a S/ 8.85.");
  assert.equal(harness.list.saving, false);

  const secondSave = harness.runtime.saveDispatch();
  assert.equal(harness.requests.length, 4);
  assert.equal(harness.requests[3].path, "/operacion/precios-jornada");
  harness.pendingResponses[3].resolve({
    data: {
      global_prices: { POLLO_PELADO: 9 }
    }
  });

  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(harness.requests.length, 5);
  assert.equal(harness.requests[4].path, "/despacho-minorista-2/tickets");
  const secondBody = JSON.parse(harness.requests[4].options.body);
  assert.deepEqual(secondBody.expected_prices, { POLLO_PELADO: "8.85" });
  assert.deepEqual(secondBody.price_overrides, {});

  harness.pendingResponses[4].resolve({
    data: { code: "DM2-CLIENTE-ACTUALIZADO" },
    message: "Despacho registrado"
  });
  await secondSave;

  assert.equal(harness.issues.length, 1);
  assert.equal(harness.state.lists[0].saving, false);
});

test("la aceptación asíncrona envía una sola actualización y libera el teclado al terminar", async () => {
  const harness = createRuntime();
  harness.runtime.openDirectPriceEditor();
  harness.touchKeyboardState.buffer = "9.75";

  const firstAccept = harness.runtime.handleTouchKeyboardAction("accept");
  const duplicateAccept = harness.runtime.handleTouchKeyboardAction("accept");

  assert.equal(harness.requests.length, 1);
  assert.equal(harness.touchKeyboardState.accepting, true);
  assert.equal(harness.state.savingJourneyPrice, true);
  assert.equal(harness.closeCalls, 0);
  assert.equal(harness.keyboardButtons.every((button) => button.disabled), true);
  assert.deepEqual(JSON.parse(harness.requests[0].options.body), {
    global_prices: { POLLO_PELADO: "9.75" },
    expected_prices: { POLLO_PELADO: "9.00" }
  });

  harness.pendingResponses[0].resolve({
    data: {
      global_prices: { POLLO_PELADO: 9.75 }
    }
  });
  await Promise.all([firstAccept, duplicateAccept]);

  assert.equal(harness.requests.length, 1);
  assert.equal(harness.closeCalls, 1);
  assert.equal(harness.touchKeyboardState.accepting, false);
  assert.equal(harness.state.savingJourneyPrice, false);
  assert.equal(harness.keyboardButtons.every((button) => !button.disabled), true);
  assert.equal(harness.state.catalog.general_prices.POLLO_PELADO.price_kg, 9.75);
  assert.equal(harness.state.catalog.general_prices.POLLO_PELADO.source, "GENERAL");
  assert.equal(harness.client.prices.POLLO_PELADO, harness.clientPrice);
  assert.equal(harness.clientPrice.price_kg, 8.5);
  assert.equal(harness.clientPrice.source, "CLIENTE");
  assert.equal(harness.persistCalls, 1);
  assert.ok(harness.renderCalls >= 2);
});

test("un error de jornada conserva ambos precios y libera los estados de guardado y teclado", async () => {
  const harness = createRuntime();
  const originalGeneralPrice = harness.state.catalog.general_prices.POLLO_PELADO;
  harness.runtime.openDirectPriceEditor();
  harness.touchKeyboardState.buffer = "10.25";

  const acceptance = harness.runtime.handleTouchKeyboardAction("accept");
  assert.equal(harness.touchKeyboardState.accepting, true);
  assert.equal(harness.state.savingJourneyPrice, true);

  const error = new Error("La solicitud no pudo completarse");
  error.data = {
    errors: {
      "global_prices.POLLO_PELADO": ["El precio fue rechazado."]
    }
  };
  harness.pendingResponses[0].reject(error);
  await acceptance;

  assert.equal(harness.requests.length, 1);
  assert.equal(harness.issues.length, 1);
  assert.equal(harness.issues[0].message, "El precio fue rechazado.");
  assert.equal(harness.closeCalls, 1);
  assert.equal(harness.touchKeyboardState.accepting, false);
  assert.equal(harness.state.savingJourneyPrice, false);
  assert.equal(harness.keyboardButtons.every((button) => !button.disabled), true);
  assert.equal(harness.state.catalog.general_prices.POLLO_PELADO, originalGeneralPrice);
  assert.equal(harness.client.prices.POLLO_PELADO, harness.clientPrice);
  assert.equal(harness.clientPrice.price_kg, 8.5);
  assert.equal(harness.persistCalls, 0);
});
