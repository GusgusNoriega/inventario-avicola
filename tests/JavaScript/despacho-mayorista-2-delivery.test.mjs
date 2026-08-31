import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { setImmediate as nextTurn } from "node:timers/promises";
import vm from "node:vm";

const source = readFileSync(
  new URL("../../public/js/despacho-mayorista-2.js", import.meta.url),
  "utf8"
);

function functionSource(name) {
  const declaration = new RegExp(`^(?:async )?function ${name}\\(`, "m").exec(source);
  assert.ok(declaration, `Falta la función ${name}`);
  const rest = source.slice(declaration.index);
  const next = /\n(?:async )?function \w+\(/.exec(rest);
  return next ? rest.slice(0, next.index) : rest;
}

function harness({ specialPrices = false } = {}) {
  const requests = [];
  const messages = [];
  const deliveryPrompts = [];
  const listeners = new Map();
  const elements = Object.fromEntries([
    "closeDeliveryTruckModalBtn", "closeDeliveryDriverModalBtn",
    "skipDeliverySelectionBtn", "skipDeliveryTruckBtn", "skipDeliveryDriverBtn",
    "deliveryTruckSearch", "deliveryDriverSearch", "deliveryTruckList", "deliveryDriverList",
    "deliveryTruckModal", "deliveryDriverModal", "deliveryDriverTicketLabel",
    "specialPriceTicketLabel", "saveSpecialPriceBtn", "specialPriceModal"
  ].map((id) => [id, {
    hidden: true,
    value: "",
    focus() {},
    addEventListener(type, listener) { listeners.set(`${id}:${type}`, listener); }
  }]));
  elements.specialPriceFields = { querySelector: () => ({ value: "12.50" }) };

  const truck = {
    id: "ticket-1",
    draftId: "draft-1",
    name: "Despacho externo",
    destination: { id: 9, destinationType: "cliente", isInternalClient: false },
    cages: [{
      id: 1, origenPeso: "manual", cantidadAvesPorJava: 7, cantidadJavas: 1,
      pesoLeidoKg: 15, pesoBrutoKg: 15, timestamp: "2026-08-31T12:00:00Z"
    }]
  };
  const products = specialPrices ? [{ apiCode: "OTROS", label: "Otros" }] : [];
  const context = vm.createContext({
    elements,
    state: { trucks: [truck] },
    deliverySelectionContext: { truckId: truck.id, vehicleId: null },
    specialPriceContext: null,
    keypadContext: {},
    wholesaleTwoWeightAdjustmentsLoaded: true,
    pendingTicketRegistrations: new Set(),
    TICKET_OPERATIONS: { RETURN: "DEVOLUCION" },
    window: { setTimeout: (callback) => callback() },
    getDeliveryTrucks: () => [{ id: 31, plate: "ABC-123" }],
    getDeliveryDrivers: () => [{ id: 42, name: "Chofer de prueba" }],
    getTruckDestination: (item) => item.destination,
    getTruckOperationType: (item) => item.operationType || "DESPACHO",
    isReturnTicket: (item) => item.operationType === "DEVOLUCION",
    isWarehouseDestination: (destination) => destination.destinationType === "almacen",
    truckHasMerchandiseOrigins: () => false,
    isTruckRegistered: (item) => Boolean(item.registration),
    getTruckTicketLabel: (item) => item.name,
    getCageChickenVariantMeta: () => ({ typeId: "pollo_vivo", apiCode: "POLLO_VIVO", code: "MACHO" }),
    getTypeMeta: () => ({ apiCode: "POLLO_VIVO" }),
    getCrateTypeMeta: () => ({ apiCode: "JAVA" }),
    getOriginById: () => null,
    hasCageMerchandiseOrigin: () => false,
    isWarehouseOrigin: () => false,
    normalizeBirdCountPerJava: Number,
    normalizeJavaCount: Number,
    roundBusinessWeight: Number,
    getRequiredManualPriceProducts: () => products,
    getMissingManualPriceProducts: (item) => products.filter((product) => !item.manualPrices?.[product.apiCode]),
    normalizeManualPrices: (value) => value || {},
    normalizeManualPrice: (value) => Number(value) > 0 ? Number(value) : null,
    normalizeTicketRegistration: (value) => value,
    setFormMessage: (message, error) => messages.push({ message, error }),
    openDeliveryTruckModal: (item) => deliveryPrompts.push(item.id),
    apiRequest: async (url, options) => {
      requests.push({ url, body: JSON.parse(options.body) });
      return { data: { id: 100, code: "M2-100" } };
    },
    getApiErrorPresentation: (error) => ({ message: error.message })
  });
  for (const name of [
    "closeTextTouchKeyboard", "openTextTouchKeyboard", "closeNumericPad", "closeTouchSelect",
    "renderDeliveryDriverList", "renderDeliveryTruckList", "renderSelectedTruckDetails",
    "applyRegisteredWeighingSnapshots", "saveState", "renderAll", "renderSpecialPriceFields",
    "setSpecialPriceMessage", "renderHenPricePreview"
  ]) context[name] = () => {};

  for (const name of [
    "isInternalClientDestination", "offersDeliverySelection", "closeDeliverySelection",
    "selectDeliveryTruck", "selectDeliveryDriver", "buildDispatchTicketPayload",
    "openSpecialPriceModal", "closeSpecialPriceModal", "saveSpecialPrices", "registerDispatchTicket"
  ]) vm.runInContext(functionSource(name), context);

  const eventsStart = source.indexOf("  elements.closeDeliveryTruckModalBtn?.addEventListener(");
  const eventsEnd = source.indexOf("  elements.closeProviderModalBtn.addEventListener(", eventsStart);
  assert.ok(eventsStart >= 0 && eventsEnd > eventsStart, "No se encontraron los eventos de entrega");
  vm.runInContext(source.slice(eventsStart, eventsEnd), context);

  return {
    context, truck, elements, requests, messages, deliveryPrompts,
    click(id) {
      const listener = listeners.get(`${id}:click`);
      assert.equal(typeof listener, "function", `El botón ${id} debe tener una acción`);
      listener();
    }
  };
}

test("omitir el transporte registra sin camión ni chofer y limpia una selección anterior", async () => {
  const h = harness();
  h.context.deliverySelectionContext.vehicleId = 31;
  h.click("skipDeliverySelectionBtn");
  await nextTurn();

  assert.equal(h.requests.length, 1);
  assert.equal(Object.hasOwn(h.requests[0].body, "delivery"), false);
  assert.equal(h.context.deliverySelectionContext, null);
  assert.equal(h.elements.deliveryTruckModal.hidden, true);
  assert.equal(h.elements.deliveryDriverModal.hidden, true);
  assert.deepEqual(h.deliveryPrompts, []);
});

for (const [label, vehicleId, driverId] of [
  ["solo chofer", null, 42],
  ["solo camión", 31, null],
  ["camión y chofer", 31, 42]
]) {
  test(`el despacho permite registrar ${label}`, async () => {
    const h = harness();
    if (vehicleId === null) h.click("skipDeliveryTruckBtn");
    else h.context.selectDeliveryTruck(String(vehicleId));
    assert.equal(h.elements.deliveryDriverModal.hidden, false);
    assert.equal(h.requests.length, 0);

    if (driverId === null) h.click("skipDeliveryDriverBtn");
    else h.context.selectDeliveryDriver(String(driverId));
    await nextTurn();

    assert.equal(h.requests.length, 1);
    assert.deepEqual(h.requests[0].body.delivery, { vehicle_id: vehicleId, driver_id: driverId });
    assert.equal(h.requests[0].body.weighings.length, 1);
    assert.equal(h.elements.deliveryDriverModal.hidden, true);
    assert.equal(h.messages.some((message) => message.error), false);
    assert.deepEqual(h.deliveryPrompts, []);
  });
}

test("seleccionar un camión o chofer que dejó de estar disponible sigue bloqueando el registro", () => {
  const h = harness();
  h.context.selectDeliveryTruck(999);
  h.context.selectDeliveryDriver(999);
  assert.equal(h.requests.length, 0);
  assert.equal(h.messages.filter((message) => message.error).length, 2);
  assert.notEqual(h.context.deliverySelectionContext, null);
});

test("confirmar precios especiales conserva la decisión de continuar sin transporte", async () => {
  for (const selection of [{}, { vehicleId: null, driverId: null }]) {
    const h = harness({ specialPrices: true });
    await h.context.registerDispatchTicket(h.truck.id, selection);
    assert.equal(h.requests.length, 0);
    assert.equal(h.elements.specialPriceModal.hidden, false);
    assert.equal(h.context.specialPriceContext.deliverySelection, selection);
    assert.deepEqual(h.deliveryPrompts, []);

    h.context.saveSpecialPrices({ preventDefault() {} });
    await nextTurn();

    assert.equal(h.requests.length, 1);
    assert.equal(Object.hasOwn(h.requests[0].body, "delivery"), false);
    assert.deepEqual(h.requests[0].body.manual_prices, { OTROS: 12.5 });
    assert.equal(h.elements.specialPriceModal.hidden, true);
    assert.deepEqual(h.deliveryPrompts, []);
  }
});

test("la elección opcional se ofrece a externos y almacenes, sin cambiar clientes internos ni devoluciones", () => {
  const h = harness();
  assert.equal(h.context.offersDeliverySelection(h.truck), true);
  h.truck.destination = { destinationType: "almacen" };
  assert.equal(h.context.offersDeliverySelection(h.truck), true);
  h.truck.destination = { destinationType: "cliente", isInternalClient: true };
  assert.equal(h.context.offersDeliverySelection(h.truck), false);
  h.truck.destination.isInternalClient = false;
  h.truck.operationType = "DEVOLUCION";
  assert.equal(h.context.offersDeliverySelection(h.truck), false);
});
