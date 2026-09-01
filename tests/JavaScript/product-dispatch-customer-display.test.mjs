import test from "node:test";
import assert from "node:assert/strict";

import {
  buildProductDispatchCustomerDisplayChannelName,
  buildProductDispatchCustomerDisplayPayload,
  buildProductDispatchCustomerDisplayStorageKey,
  productDispatchCustomerDisplayPayloadMatches,
  resolveProductDispatchCustomerDisplayPreview,
  PRODUCT_DISPATCH_CUSTOMER_DISPLAY_PAYLOAD_TYPE,
  PRODUCT_DISPATCH_CUSTOMER_DISPLAY_REQUEST_TYPE,
  PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE
} from "../../public/js/product-dispatch-customer-display.js";

function displayPayload(overrides = {}) {
  return buildProductDispatchCustomerDisplayPayload({
    branchId: 4,
    userId: 17,
    producerId: "product-dispatch-tab-a",
    producerInstance: 8001,
    revision: 3,
    updatedAt: "2026-09-01T17:30:00.000Z",
    companyTitle: "La Central de los Pollos",
    activeList: {
      number: 2,
      customer: "Venta al público",
      rows: [
        {
          name: "Pollo beneficiado",
          quantity: 2,
          netWeightKg: 7.1256,
          amount: 39.1901,
          productId: 81,
          wasteGramsPerUnit: 350
        }
      ],
      totals: {
        quantity: 2,
        netWeightKg: 7.1256,
        amount: 39.1901
      }
    },
    preview: {
      netWeightKg: 5.2504,
      amount: 28.8772,
      status: "ready"
    },
    currency: "PEN",
    ...overrides
  });
}

test("expone tipos de mensaje exclusivos para Despacho de productos", () => {
  assert.equal(
    PRODUCT_DISPATCH_CUSTOMER_DISPLAY_PAYLOAD_TYPE,
    "product-dispatch-customer-display-state"
  );
  assert.equal(
    PRODUCT_DISPATCH_CUSTOMER_DISPLAY_REQUEST_TYPE,
    "product-dispatch-customer-display-request"
  );
  assert.equal(
    PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE,
    "product-dispatch-customer-display-reset"
  );
});

test("canal y almacenamiento quedan aislados por sucursal, usuario y productor", () => {
  const scopes = [
    [1, 8, "producer-a"],
    [2, 8, "producer-a"],
    [1, 9, "producer-a"],
    [1, 8, "producer-b"]
  ];
  const channels = scopes.map((scope) =>
    buildProductDispatchCustomerDisplayChannelName(...scope)
  );
  const storageKeys = scopes.map((scope) =>
    buildProductDispatchCustomerDisplayStorageKey(...scope)
  );

  assert.equal(new Set(channels).size, scopes.length);
  assert.equal(new Set(storageKeys).size, scopes.length);
  assert.match(channels[0], /productos-1-8-producer-a-v1$/);
  assert.match(storageKeys[0], /productos:1:8:estado-v1:producer-a$/);
  assert.doesNotMatch(channels[0], /minorista/);
});

test("el matcher exige los tres identificadores de aislamiento", () => {
  const payload = displayPayload();

  assert.equal(productDispatchCustomerDisplayPayloadMatches(payload, {
    branchId: 4,
    userId: 17,
    producerId: "product-dispatch-tab-a"
  }), true);
  assert.equal(productDispatchCustomerDisplayPayloadMatches(payload, {
    branchId: 5,
    userId: 17,
    producerId: "product-dispatch-tab-a"
  }), false);
  assert.equal(productDispatchCustomerDisplayPayloadMatches(payload, {
    branchId: 4,
    userId: 18,
    producerId: "product-dispatch-tab-a"
  }), false);
  assert.equal(productDispatchCustomerDisplayPayloadMatches(payload, {
    branchId: 4,
    userId: 17,
    producerId: "product-dispatch-tab-b"
  }), false);
  assert.equal(productDispatchCustomerDisplayPayloadMatches(payload, {
    branchId: 4,
    userId: 17,
    producerId: ""
  }), false);
  assert.equal(productDispatchCustomerDisplayPayloadMatches({
    ...payload,
    type: PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE
  }, {
    branchId: 4,
    userId: 17,
    producerId: "product-dispatch-tab-a"
  }), false);
});

test("resuelve solamente el neto calculado y el importe de la pesada actual", () => {
  assert.deepEqual(resolveProductDispatchCustomerDisplayPreview({
    hasReading: true,
    netWeightKg: 10.25049,
    amount: 56.3777,
    calculationAvailable: true,
    isPhysical: true,
    isFresh: true,
    connectionMatches: true,
    isExpired: false,
    readWeightKg: 10,
    tareGrams: 250,
    wasteGramsPerUnit: 250
  }), {
    netWeightKg: 10.25,
    amount: 56.38,
    status: "ready"
  });
});

test("mantiene el neto visible cuando solamente el importe está pendiente", () => {
  assert.deepEqual(resolveProductDispatchCustomerDisplayPreview({
    hasReading: true,
    netWeightKg: 10.25,
    amount: 0,
    calculationAvailable: true,
    amountAvailable: false,
    isPhysical: true,
    isFresh: true,
    connectionMatches: true,
    status: "stable"
  }), {
    netWeightKg: 10.25,
    amount: null,
    status: "stable"
  });
});

test("oculta una lectura física vencida, desconectada o sin cálculo completo", () => {
  const physicalReading = {
    hasReading: true,
    netWeightKg: 8.4,
    amount: 46.2,
    calculationAvailable: true,
    isPhysical: true,
    isFresh: true,
    connectionMatches: true,
    isExpired: false
  };

  assert.deepEqual(resolveProductDispatchCustomerDisplayPreview({
    ...physicalReading,
    isFresh: false
  }), {
    netWeightKg: null,
    amount: null,
    status: "unavailable"
  });
  assert.deepEqual(resolveProductDispatchCustomerDisplayPreview({
    ...physicalReading,
    connectionMatches: false
  }), {
    netWeightKg: null,
    amount: null,
    status: "unavailable"
  });
  assert.deepEqual(resolveProductDispatchCustomerDisplayPreview({
    ...physicalReading,
    calculationAvailable: false
  }), {
    netWeightKg: null,
    amount: null,
    status: "calculating"
  });
  assert.deepEqual(resolveProductDispatchCustomerDisplayPreview({
    ...physicalReading,
    hasReading: false
  }), {
    netWeightKg: null,
    amount: null,
    status: "waiting"
  });
});

test("una lectura manual puede mostrar su neto sin una conexión física", () => {
  assert.deepEqual(resolveProductDispatchCustomerDisplayPreview({
    hasReading: true,
    netWeightKg: 4.75,
    amount: 26.125,
    calculationAvailable: true,
    isPhysical: false,
    isFresh: false,
    connectionMatches: false,
    status: "manual"
  }), {
    netWeightKg: 4.75,
    amount: 26.13,
    status: "manual"
  });
});

test("el payload redondea los valores y conserva una allowlist pública exacta", () => {
  const payload = displayPayload({
    draftId: "internal-draft",
    productIds: [81],
    rawWeightKg: 5,
    tareGrams: 250,
    wasteGramsPerUnit: 350,
    catalog: { products: [{ id: 81 }] }
  });

  assert.deepEqual(payload, {
    type: PRODUCT_DISPATCH_CUSTOMER_DISPLAY_PAYLOAD_TYPE,
    branchId: "4",
    userId: "17",
    producerId: "product-dispatch-tab-a",
    producerInstance: 8001,
    revision: 3,
    companyTitle: "La Central de los Pollos",
    activeList: {
      number: 2,
      customer: "Venta al público",
      rows: [
        {
          name: "Pollo beneficiado",
          quantity: 2,
          netWeightKg: 7.126,
          amount: 39.19
        }
      ],
      totals: {
        quantity: 2,
        netWeightKg: 7.126,
        amount: 39.19
      }
    },
    preview: {
      netWeightKg: 5.25,
      amount: 28.88,
      status: "ready"
    },
    currency: "PEN",
    updatedAt: "2026-09-01T17:30:00.000Z"
  });

  const serialized = JSON.stringify(payload);
  for (const forbiddenField of [
    "draftId",
    "productId",
    "productIds",
    "rawWeightKg",
    "readWeightKg",
    "grossWeightKg",
    "tare",
    "waste",
    "merma",
    "catalog"
  ]) {
    assert.equal(
      serialized.toLowerCase().includes(forbiddenField.toLowerCase()),
      false,
      `El payload expuso ${forbiddenField}`
    );
  }
});

test("limita textos y filas y usa valores públicos seguros por defecto", () => {
  const rows = Array.from({ length: 155 }, (_, index) => ({
    name: `Producto ${index + 1} ${"x".repeat(150)}`,
    quantity: index + 1,
    netWeightKg: 1.0006,
    amount: 2.345
  }));
  const payload = buildProductDispatchCustomerDisplayPayload({
    branchId: 1,
    userId: 2,
    producerId: "producer-a",
    updatedAt: "2026-09-01T17:30:00.000Z",
    companyTitle: " ",
    activeList: {
      number: -4,
      customer: " ",
      rows
    },
    preview: {
      netWeightKg: -1,
      amount: 200,
      status: ""
    },
    currency: "soles"
  });

  assert.equal(payload.companyTitle, "Despacho de productos");
  assert.equal(payload.activeList.number, 1);
  assert.equal(payload.activeList.customer, "Venta al público");
  assert.equal(payload.activeList.rows.length, 100);
  assert.equal(payload.activeList.rows[0].name.length, 120);
  assert.equal(payload.activeList.totals.quantity, 5050);
  assert.equal(payload.activeList.totals.netWeightKg, 100.1);
  assert.equal(payload.activeList.totals.amount, 235);
  assert.deepEqual(payload.preview, {
    netWeightKg: null,
    amount: null,
    status: "waiting"
  });
  assert.equal(payload.currency, "PEN");
});

test("totales explícitos válidos prevalecen y los inválidos se reconstruyen", () => {
  const explicit = buildProductDispatchCustomerDisplayPayload({
    branchId: 1,
    userId: 2,
    producerId: "producer-a",
    updatedAt: "2026-09-01T17:30:00.000Z",
    activeList: {
      rows: [
        { name: "Pollo", quantity: 2, netWeightKg: 3.125, amount: 18.75 },
        { name: "Pecho", quantity: 1, netWeightKg: 1.25, amount: 15 }
      ],
      totals: {
        quantity: 9,
        netWeightKg: 99.9999,
        amount: 80.555
      }
    }
  });
  const reconstructed = buildProductDispatchCustomerDisplayPayload({
    branchId: 1,
    userId: 2,
    producerId: "producer-a",
    updatedAt: "2026-09-01T17:30:00.000Z",
    activeList: {
      rows: [
        { name: "Pollo", quantity: 2, netWeightKg: 3.125, amount: 18.75 },
        { name: "Pecho", quantity: 1, netWeightKg: 1.25, amount: 15 }
      ],
      totals: {
        quantity: "inválido",
        netWeightKg: "inválido",
        amount: "inválido"
      }
    }
  });

  assert.deepEqual(explicit.activeList.totals, {
    quantity: 9,
    netWeightKg: 100,
    amount: 80.56
  });
  assert.deepEqual(reconstructed.activeList.totals, {
    quantity: 3,
    netWeightKg: 4.375,
    amount: 33.75
  });
});
