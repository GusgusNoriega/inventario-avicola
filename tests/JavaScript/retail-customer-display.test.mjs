import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import {
  buildRetailCustomerDisplayChannelName,
  buildRetailCustomerDisplayPayload,
  buildRetailCustomerDisplayStorageKey,
  retailCustomerDisplayPayloadMatches,
  resolveRetailCustomerDisplayWeights,
  RETAIL_CUSTOMER_DISPLAY_PAYLOAD_TYPE,
  RETAIL_CUSTOMER_DISPLAY_RESET_TYPE
} from "../../public/js/retail-customer-display.js";

const displaySource = readFileSync(
  new URL("../../public/js/pantalla-cliente-minorista.js", import.meta.url),
  "utf8"
);
const stationSource = readFileSync(
  new URL("../../public/js/despacho-minorista.js", import.meta.url),
  "utf8"
);

function sourceBetween(startMarker, endMarker) {
  const start = displaySource.indexOf(startMarker);
  const end = displaySource.indexOf(endMarker, start);

  assert.notEqual(start, -1, `No se encontró ${startMarker}`);
  assert.notEqual(end, -1, `No se encontró ${endMarker}`);

  return displaySource.slice(start, end);
}

function createClassList(initialClasses = []) {
  const values = new Set(initialClasses);

  return {
    add(...classNames) {
      classNames.forEach((className) => values.add(className));
    },
    contains(className) {
      return values.has(className);
    },
    remove(...classNames) {
      classNames.forEach((className) => values.delete(className));
    },
    toggle(className, force) {
      if (force === true) {
        values.add(className);
        return true;
      }
      if (force === false) {
        values.delete(className);
        return false;
      }
      if (values.has(className)) {
        values.delete(className);
        return false;
      }
      values.add(className);
      return true;
    }
  };
}

function createTextElement(initialClasses = []) {
  let text = "";

  return {
    classList: createClassList(initialClasses),
    get textContent() {
      return text;
    },
    set textContent(value) {
      text = String(value);
    },
    set innerHTML(_value) {
      throw new Error("La pantalla pública debe escribir datos con textContent, no con innerHTML.");
    }
  };
}

function loadDisplayConsumer({
  station = "1",
  producerId = "retail-producer-1",
  now = Date.parse("2026-07-24T12:00:00.000Z")
} = {}) {
  const elements = {
    name: createTextElement(),
    ticket: createTextElement(),
    scaleCard: createTextElement(),
    scaleWeight: createTextElement(),
    scaleStatus: createTextElement(["is-waiting"]),
    records: createTextElement(),
    trays: createTextElement(),
    birds: createTextElement(),
    netWeight: createTextElement(),
    amount: createTextElement(),
    announcement: createTextElement(),
    status: createTextElement(["is-waiting"])
  };
  const storageKey = buildRetailCustomerDisplayStorageKey(station, producerId);
  const removedStorageKeys = [];
  const storage = {
    removeItem(key) {
      removedStorageKeys.push(key);
    }
  };
  const clock = { now };
  const DateFacade = {
    now: () => clock.now,
    parse: (value) => Date.parse(value)
  };
  const renderBlock = sourceBetween(
    "function normalizeCount",
    "function readStoredState"
  );

  const runtime = new Function(
    "elements",
    "PRODUCER_ID",
    "RETAIL_STATION",
    "STORAGE_KEY",
    "DISPLAY_TTL_MS",
    "retailCustomerDisplayPayloadMatches",
    "RETAIL_CUSTOMER_DISPLAY_RESET_TYPE",
    "localStorage",
    "Date",
    `
      let lastUpdateAt = 0;
      let lastPayloadTimestamp = 0;
      let lastRevision = 0;
      let lastProducerInstance = 0;
      ${renderBlock}
      return {
        clearDisplay,
        handleReset,
        renderPayload,
        state() {
          return {
            lastUpdateAt,
            lastPayloadTimestamp,
            lastRevision,
            lastProducerInstance
          };
        }
      };
    `
  )(
    elements,
    producerId,
    station,
    storageKey,
    8000,
    retailCustomerDisplayPayloadMatches,
    RETAIL_CUSTOMER_DISPLAY_RESET_TYPE,
    storage,
    DateFacade
  );

  return {
    clock,
    elements,
    removedStorageKeys,
    runtime,
    storageKey
  };
}

function displayPayload(overrides = {}) {
  return buildRetailCustomerDisplayPayload({
    station: "1",
    producerId: "retail-producer-1",
    producerInstance: 1001,
    revision: 1,
    updatedAt: "2026-07-24T11:59:59.000Z",
    customerName: "Cliente Uno",
    ticketLabel: "Lista 2",
    listNumber: 2,
    operationType: "DESPACHO",
    totals: {
      weighings: 3,
      trays: 5,
      birds: 42,
      net: 18.345,
      amount: 123.45
    },
    pricingComplete: true,
    readWeightKg: 7.1,
    displayWeightKg: 7.31,
    weightSource: "serial",
    weightStatus: "stable",
    isStable: true,
    isFresh: true,
    ...overrides
  });
}

test("canal y almacenamiento quedan aislados por estación y por productor", () => {
  const stationOneChannel = buildRetailCustomerDisplayChannelName("1");
  const stationTwoChannel = buildRetailCustomerDisplayChannelName("2");
  const stationOneProducerA = buildRetailCustomerDisplayStorageKey("1", "producer-a");
  const stationOneProducerB = buildRetailCustomerDisplayStorageKey("1", "producer-b");
  const stationTwoProducerA = buildRetailCustomerDisplayStorageKey("2", "producer-a");

  assert.notEqual(stationOneChannel, stationTwoChannel);
  assert.notEqual(stationOneChannel, "sistema-pollos-pantalla-cliente-v1");
  assert.equal(
    new Set([stationOneProducerA, stationOneProducerB, stationTwoProducerA]).size,
    3
  );
  assert.match(stationOneProducerA, /minorista-1-estado-v1:producer-a$/);
  assert.match(stationTwoProducerA, /minorista-2-estado-v1:producer-a$/);
});

test("el productor publica la lista activa, sus totales y el peso directo de la balanza", () => {
  const builderStart = stationSource.indexOf("function buildCurrentRetailCustomerDisplayState()");
  const builderEnd = stationSource.indexOf(
    "function flushRetailCustomerDisplayStorage()",
    builderStart
  );
  assert.notEqual(builderStart, -1);
  assert.notEqual(builderEnd, -1);
  const builder = stationSource.slice(builderStart, builderEnd);

  assert.match(builder, /const list = activeList\(\)/);
  assert.match(builder, /const totals = listTotals\(list\)/);
  assert.match(builder, /const values = previewValues\(\)/);
  assert.match(builder, /const customer = clientFor\(list\)/);
  assert.match(builder, /pricingComplete = missingPriceTypes\(list\)\.length === 0/);
  assert.match(builder, /customerName: customer\?\.name \|\| "Venta sin cliente"/);
  assert.match(builder, /ticketLabel: `Lista \$\{state\.activeList \+ 1\}`/);
  assert.match(builder, /const availability = liveReadingAvailability\(\)/);
  assert.match(builder, /const displayWeights = resolveRetailCustomerDisplayWeights\(/);
  assert.match(builder, /readWeightKg: values\.readWeight/);
  assert.match(builder, /displayWeightKg: values\.readWeight/);
  assert.doesNotMatch(builder, /displayWeightKg: values\.grossWeight/);
  assert.match(builder, /displayWeightKg: displayWeights\.displayWeightKg/);
  assert.match(builder, /readWeightKg: displayWeights\.readWeightKg/);

  const previewStart = stationSource.indexOf("function renderWeightPreview()");
  const previewEnd = stationSource.indexOf("function renderChickenTypes()", previewStart);
  assert.match(
    stationSource.slice(previewStart, previewEnd),
    /adjustedWeight\.textContent = values\.hasReading \? values\.readWeight\.toFixed\(3\)/
  );
  assert.match(
    stationSource.slice(previewStart, previewEnd),
    /publishRetailCustomerDisplayState\(\)/
  );
  assert.match(
    stationSource,
    /displayUrl\.searchParams\.set\("source", RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID\)/
  );
  assert.match(
    stationSource,
    /event\.data\.producerId === RETAIL_CUSTOMER_DISPLAY_PRODUCER_ID/
  );
});

test("una lectura física vencida o desconectada no se presenta como peso actual", () => {
  const physicalReading = {
    hasReading: true,
    readWeightKg: 12.4,
    displayWeightKg: 12.64,
    isPhysical: true,
    isFresh: true,
    connectionMatches: true,
    isExpired: false
  };

  assert.deepEqual(resolveRetailCustomerDisplayWeights(physicalReading), {
    readWeightKg: 12.4,
    displayWeightKg: 12.64
  });
  assert.deepEqual(resolveRetailCustomerDisplayWeights({
    ...physicalReading,
    isFresh: false
  }), {
    readWeightKg: null,
    displayWeightKg: null
  });
  assert.deepEqual(resolveRetailCustomerDisplayWeights({
    ...physicalReading,
    connectionMatches: false
  }), {
    readWeightKg: null,
    displayWeightKg: null
  });
  assert.deepEqual(resolveRetailCustomerDisplayWeights({
    ...physicalReading,
    isExpired: true
  }), {
    readWeightKg: null,
    displayWeightKg: null
  });
});

test("el peso manual sigue visible sin exigir una conexión física", () => {
  assert.deepEqual(resolveRetailCustomerDisplayWeights({
    hasReading: true,
    readWeightKg: 8.2,
    displayWeightKg: 8.36,
    isPhysical: false,
    isFresh: false,
    connectionMatches: false,
    isExpired: false
  }), {
    readWeightKg: 8.2,
    displayWeightKg: 8.36
  });
});

test("un source vacío nunca acepta un estado, ni siquiera con productor vacío", () => {
  const payloadWithoutProducer = buildRetailCustomerDisplayPayload({
    station: "1",
    producerId: "",
    updatedAt: "2026-07-24T12:00:00.000Z"
  });

  assert.equal(
    retailCustomerDisplayPayloadMatches(payloadWithoutProducer, {
      station: "1",
      producerId: ""
    }),
    false
  );
  assert.equal(
    retailCustomerDisplayPayloadMatches(displayPayload(), {
      station: "1",
      producerId: "   "
    }),
    false
  );
});

test("el payload público usa una allowlist exacta y no filtra datos internos de la venta", () => {
  const payload = buildRetailCustomerDisplayPayload({
    station: 2,
    producerId: "retail-producer-2",
    producerInstance: 2050,
    revision: 7,
    updatedAt: "2026-07-24T12:30:00.000Z",
    customerName: "Comercial Lima",
    ticketLabel: "Lista 4",
    listNumber: 4,
    operationType: "DEVOLUCION",
    presentation: "Hembra abierta",
    totals: {
      weighings: 3,
      trays: 5,
      birds: 42,
      net: 18.3456,
      amount: -241.678,
      priceKg: 13.25,
      lineItems: [{ id: 91 }]
    },
    pricingComplete: true,
    readWeightKg: 7.1234,
    displayWeightKg: 7.2344,
    weightSource: "serial",
    weightStatus: "stable",
    isStable: true,
    isFresh: true,
    draftId: "interno-no-publicable",
    clientId: 33,
    catalog: { clients: [{ id: 33 }] },
    unitPrices: { POLLO_PELADO: 13.25 },
    payments: [{ methodId: 1, accountId: 2 }],
    delivery: { vehicleId: 8, driverId: 9 }
  });

  assert.deepEqual(payload, {
    type: RETAIL_CUSTOMER_DISPLAY_PAYLOAD_TYPE,
    station: "2",
    producerId: "retail-producer-2",
    producerInstance: 2050,
    revision: 7,
    customerName: "Comercial Lima",
    ticket: {
      label: "Lista 4",
      listNumber: 4,
      operationType: "DEVOLUCION",
      presentation: "Hembra abierta",
      weighings: 3,
      trays: 5,
      birds: 42,
      netWeightKg: 18.346,
      amount: -241.68,
      pricingComplete: true,
      currency: "PEN"
    },
    scale: {
      readWeightKg: 7.123,
      displayWeightKg: 7.234,
      source: "serial",
      status: "stable",
      isStable: true,
      isFresh: true
    },
    updatedAt: "2026-07-24T12:30:00.000Z"
  });

  const serialized = JSON.stringify(payload);
  for (const forbiddenField of [
    "draftId",
    "clientId",
    "catalog",
    "priceKg",
    "unitPrices",
    "payments",
    "accountId",
    "delivery",
    "vehicleId",
    "driverId",
    "lineItems"
  ]) {
    assert.equal(
      serialized.includes(forbiddenField),
      false,
      `El payload expuso ${forbiddenField}`
    );
  }
});

test("precio incompleto publica importe nulo y los pesos ausentes permanecen nulos", () => {
  const payload = buildRetailCustomerDisplayPayload({
    station: "1",
    producerId: "producer-a",
    producerInstance: 20,
    revision: 2,
    updatedAt: "2026-07-24T12:00:00.000Z",
    totals: {
      weighings: 2,
      trays: 4,
      birds: 31,
      net: 11.4,
      amount: 98.7
    },
    pricingComplete: false,
    readWeightKg: null,
    displayWeightKg: ""
  });

  assert.equal(payload.ticket.weighings, 2);
  assert.equal(payload.ticket.trays, 4);
  assert.equal(payload.ticket.birds, 31);
  assert.equal(payload.ticket.netWeightKg, 11.4);
  assert.equal(payload.ticket.amount, null);
  assert.equal(payload.ticket.pricingComplete, false);
  assert.equal(payload.scale.readWeightKg, null);
  assert.equal(payload.scale.displayWeightKg, null);
});

test("el filtro rechaza otra estación, otro productor y otro tipo de mensaje", () => {
  const payload = displayPayload();

  assert.equal(
    retailCustomerDisplayPayloadMatches(payload, {
      station: "1",
      producerId: "retail-producer-1"
    }),
    true
  );
  assert.equal(
    retailCustomerDisplayPayloadMatches(payload, {
      station: "2",
      producerId: "retail-producer-1"
    }),
    false
  );
  assert.equal(
    retailCustomerDisplayPayloadMatches(payload, {
      station: "1",
      producerId: "retail-producer-2"
    }),
    false
  );
  assert.equal(
    retailCustomerDisplayPayloadMatches(
      { ...payload, type: "customer-display-state" },
      { station: "1", producerId: "retail-producer-1" }
    ),
    false
  );
});

test("el consumidor renderiza solo texto y conserva todos los totales del ticket", () => {
  const { elements, runtime } = loadDisplayConsumer();

  runtime.renderPayload(displayPayload());

  assert.equal(elements.name.textContent, "Cliente Uno");
  assert.equal(elements.ticket.textContent, "Lista 2");
  assert.equal(elements.records.textContent, "3");
  assert.equal(elements.trays.textContent, "5");
  assert.equal(elements.birds.textContent, "42");
  assert.equal(elements.netWeight.textContent, "18.345 kg");
  assert.equal(elements.amount.textContent, "S/ 123.45");
  assert.equal(elements.amount.classList.contains("is-pending"), false);
  assert.equal(elements.scaleWeight.textContent, "7.310");
  assert.equal(elements.scaleStatus.textContent, "Peso estable");
  assert.equal(elements.status.textContent, "En vivo");
  assert.match(elements.announcement.textContent, /42 pollos, 5 bandejas/);

  const renderBlock = sourceBetween("function normalizeCount", "function readStoredState");
  assert.match(renderBlock, /elements\.name\.textContent = customerName/);
  assert.match(renderBlock, /elements\.amount\.textContent = amountLabel/);
  assert.doesNotMatch(renderBlock, /\.innerHTML\s*=/);
});

test("importe pendiente y peso nulo nunca se presentan como dinero o peso cero", () => {
  const { elements, runtime } = loadDisplayConsumer();

  runtime.renderPayload(displayPayload({
    pricingComplete: false,
    totals: {
      weighings: 1,
      trays: 2,
      birds: 16,
      net: 6.25,
      amount: 84.5
    },
    readWeightKg: null,
    displayWeightKg: null
  }));

  assert.equal(elements.amount.textContent, "Precio pendiente");
  assert.equal(elements.amount.classList.contains("is-pending"), true);
  assert.equal(elements.scaleWeight.textContent, "---");
  assert.equal(elements.scaleCard.classList.contains("has-reading"), false);
  assert.equal(elements.scaleStatus.textContent, "Sin lectura");
  assert.match(elements.announcement.textContent, /Precio pendiente\.$/);
});

test("TTL descarta estados vencidos aunque tengan una revisión mayor", () => {
  const now = Date.parse("2026-07-24T12:00:00.000Z");
  const { elements, runtime } = loadDisplayConsumer({ now });

  runtime.renderPayload(displayPayload({
    revision: 4,
    updatedAt: "2026-07-24T11:59:59.000Z",
    customerName: "Estado vigente"
  }));
  runtime.renderPayload(displayPayload({
    revision: 99,
    updatedAt: "2026-07-24T11:59:51.999Z",
    customerName: "Estado vencido"
  }));

  assert.equal(elements.name.textContent, "Estado vigente");
  assert.equal(runtime.state().lastRevision, 4);
  assert.match(displaySource, /const DISPLAY_TTL_MS = 8000;/);
  assert.match(
    displaySource,
    /Date\.now\(\) - lastUpdateAt > DISPLAY_TTL_MS[\s\S]*clearDisplay\("Esperando despacho", true\)/
  );
});

test("reset válido limpia pantalla y storage; reset ajeno no afecta la sesión", () => {
  const { elements, removedStorageKeys, runtime, storageKey } = loadDisplayConsumer();
  runtime.renderPayload(displayPayload());

  runtime.handleReset({
    type: RETAIL_CUSTOMER_DISPLAY_RESET_TYPE,
    station: "2",
    producerId: "retail-producer-1"
  });
  assert.equal(elements.name.textContent, "Cliente Uno");

  runtime.handleReset({
    type: RETAIL_CUSTOMER_DISPLAY_RESET_TYPE,
    station: "1",
    producerId: "otro-productor"
  });
  assert.equal(elements.name.textContent, "Cliente Uno");

  runtime.handleReset({
    type: RETAIL_CUSTOMER_DISPLAY_RESET_TYPE,
    station: "1",
    producerId: "retail-producer-1"
  });

  assert.equal(elements.name.textContent, "Esperando cliente");
  assert.equal(elements.ticket.textContent, "Sin lista vinculada");
  assert.equal(elements.records.textContent, "0");
  assert.equal(elements.trays.textContent, "0");
  assert.equal(elements.birds.textContent, "0");
  assert.equal(elements.netWeight.textContent, "0.000 kg");
  assert.equal(elements.amount.textContent, "S/ 0.00");
  assert.equal(elements.scaleWeight.textContent, "---");
  assert.equal(elements.status.textContent, "Despacho cerrado");
  assert.deepEqual(removedStorageKeys, [storageKey]);
  assert.equal(runtime.state().lastRevision, Number.MAX_SAFE_INTEGER);

  runtime.renderPayload(displayPayload({
    revision: 999,
    customerName: "Mensaje tardío"
  }));
  assert.equal(elements.name.textContent, "Esperando cliente");
  assert.match(
    displaySource,
    /if \(!event\.newValue\) \{[\s\S]*clearDisplay\("Despacho cerrado"\)/
  );
});
