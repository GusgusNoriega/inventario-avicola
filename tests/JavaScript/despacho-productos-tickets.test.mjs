import assert from "node:assert/strict";
import test from "node:test";

import {
  applyProductDispatchCatalogDefaults,
  buildProductDispatchTicketDeletePayload,
  buildProductDispatchTicketQuery,
  buildProductDispatchTicketUpdatePayload,
  normalizeProductDispatchTicketForEditor,
  normalizeProductDispatchTicketsPayload,
  productDispatchEditorFingerprint,
} from "../../public/js/despacho-productos-tickets.js";

test("la consulta de tickets combina búsqueda, fechas y paginación permitida", () => {
  const query = new URLSearchParams(buildProductDispatchTicketQuery({
    search: "  PD-100 Gallina  ",
    date_from: "2026-08-01",
    date_to: "2026-08-31",
    page: 3,
    per_page: 50,
  }));

  assert.equal(query.get("search"), "PD-100 Gallina");
  assert.equal(query.get("date_from"), "2026-08-01");
  assert.equal(query.get("date_to"), "2026-08-31");
  assert.equal(query.get("page"), "3");
  assert.equal(query.get("per_page"), "50");

  const invalidPageSize = new URLSearchParams(buildProductDispatchTicketQuery({ per_page: 30 }));
  assert.equal(invalidPageSize.get("per_page"), "10");
});

test("normaliza el listado detallado y completa la paginación", () => {
  const result = normalizeProductDispatchTicketsPayload({
    data: {
      tickets: [{ id: 8, code: "PD-008", weighings: [{ id: 81 }] }],
      pagination: { current_page: 2, last_page: 4, per_page: 10, total: 35, from: 11, to: 20 },
      summary: { tickets: 35, amount: "912.40" },
      applied_filters: { search: "PD" },
    },
  });

  assert.equal(result.tickets[0].weighings[0].id, 81);
  assert.equal(result.pagination.current_page, 2);
  assert.equal(result.pagination.total, 35);
  assert.equal(result.summary.tickets, 35);
  assert.equal(result.summary.amount, 912.4);
  assert.deepEqual(result.summary.amounts, []);
  assert.equal(result.applied_filters.search, "PD");
});

test("mantiene separados los totales cuando el historial contiene varias monedas", () => {
  const result = normalizeProductDispatchTicketsPayload({
    data: {
      tickets: [],
      pagination: { total: 2 },
      summary: {
        tickets: 2,
        currency: null,
        amount: null,
        amounts: [
          { currency: "PEN", amount: "15.50" },
          { currency: "USD", amount: "8.25" },
        ],
      },
    },
  });

  assert.equal(result.summary.amount, null);
  assert.deepEqual(result.summary.amounts, [
    { currency: "PEN", amount: 15.5 },
    { currency: "USD", amount: 8.25 },
  ]);
});

test("prepara el editor con la hora local y conserva versión e ids", () => {
  const ticket = normalizeProductDispatchTicketForEditor({
    data: {
      id: 15,
      code: "PD-015",
      version: 4,
      product_ticket_title: "CONTROL AVÍCOLA",
      list_number: 6,
      registered_at: "2026-09-02T19:45:00Z",
      registered_at_local: "2026-09-02T14:45:37",
      client: { id: 9, name: "Mercado Central" },
      currency: "PEN",
      weighings: [{
        id: 151,
        product: { id: 7, name: "Gallina" },
        variation: { id: 72, name: "Grande" },
        quantity: 2,
        price_mode: "POR_KG",
        unit_price: 8.5,
        waste_grams_per_unit: 50,
        waste_total_grams: 100,
        tare_grams: 200,
        read_weight_kg: 4.1,
      }],
    },
  });

  assert.equal(ticket.id, 15);
  assert.equal(ticket.version, 4);
  assert.equal(ticket.registered_at, "2026-09-02T14:45:37");
  assert.equal(ticket.original_registered_at, "2026-09-02T14:45:37");
  assert.equal(ticket.client_id, 9);
  assert.equal(ticket.weighings[0].id, 151);
  assert.equal(ticket.weighings[0].product_id, 7);
  assert.equal(ticket.weighings[0].variation_id, 72);
});

test("el payload de corrección recalcula merma y solo envía el contrato editable", () => {
  const payload = buildProductDispatchTicketUpdatePayload({
    version: 7,
    correction_reason: "  Corrección solicitada por caja  ",
    ticket_title: "  DESPACHO PRINCIPAL  ",
    list_number: 3,
    client_id: "",
    registered_at: "2026-09-02T10:30",
    weighings: [
      {
        id: 90,
        product_id: 5,
        variation_id: 51,
        quantity: 3,
        price_mode: "POR_KG",
        unit_price: 12.345,
        waste_grams_per_unit: 75,
        waste_total_grams: 999999,
        tare_grams: 250,
        read_weight_kg: 5.1234,
      },
      {
        product_id: 6,
        variation_id: null,
        quantity: 4,
        price_mode: "POR_UNIDAD",
        unit_price: 0.8,
        waste_grams_per_unit: 0,
        tare_grams: 0,
        read_weight_kg: 1,
      },
    ],
  });

  assert.deepEqual(Object.keys(payload), [
    "version",
    "correction_reason",
    "ticket_title",
    "list_number",
    "client_id",
    "registered_at",
    "weighings",
  ]);
  assert.equal(payload.correction_reason, "Corrección solicitada por caja");
  assert.equal(payload.ticket_title, "DESPACHO PRINCIPAL");
  assert.equal(payload.client_id, null);
  assert.equal(payload.weighings[0].id, 90);
  assert.equal(payload.weighings[0].unit_price, 12.35);
  assert.equal(payload.weighings[0].waste_total_grams, 225);
  assert.equal("id" in payload.weighings[1], false);
  assert.equal(payload.weighings[1].price_mode, "POR_UNIDAD");
});

test("permite guardar una corrección sin motivo", () => {
  const payload = buildProductDispatchTicketUpdatePayload({
    version: 8,
    correction_reason: "   ",
    ticket_title: "DESPACHO",
    list_number: 1,
    registered_at: "2026-09-02T10:30",
    weighings: [],
  });

  assert.equal(payload.correction_reason, "");
});

test("la eliminación envía la versión actual del ticket", () => {
  assert.deepEqual(buildProductDispatchTicketDeletePayload({
    version: " 2026-09-02T14:30:00.000000Z ",
  }), {
    version: "2026-09-02T14:30:00.000000Z",
  });

  assert.deepEqual(buildProductDispatchTicketDeletePayload({
    updated_at: "2026-09-02T15:00:00Z",
  }), {
    version: "2026-09-02T15:00:00Z",
  });
});

test("la huella del editor detecta cambios y trata :00 como el mismo minuto", () => {
  const draft = {
    ticket_title: "DESPACHO",
    list_number: 1,
    client_id: null,
    registered_at: "2026-09-02T10:30:00",
    correction_reason: "",
    weighings: [{
      id: 1,
      product_id: 5,
      variation_id: null,
      quantity: 1,
      price_mode: "POR_KG",
      unit_price: 10,
      waste_grams_per_unit: 0,
      tare_grams: 0,
      read_weight_kg: 2,
    }],
  };
  const baseline = productDispatchEditorFingerprint(draft);

  assert.equal(productDispatchEditorFingerprint({
    ...structuredClone(draft),
    registered_at: "2026-09-02T10:30",
  }), baseline);
  assert.notEqual(productDispatchEditorFingerprint({
    ...structuredClone(draft),
    ticket_title: "DESPACHO CORREGIDO",
  }), baseline);
  assert.notEqual(productDispatchEditorFingerprint({
    ...structuredClone(draft),
    weighings: [{ ...draft.weighings[0], quantity: 2 }],
  }), baseline);
});

test("al volver a la selección histórica restaura modo precio y merma originales", () => {
  const ticket = normalizeProductDispatchTicketForEditor({
    id: 20,
    registered_at_local: "2026-09-02T10:30:00",
    weighings: [{
      id: 201,
      product: { id: 7, name: "Producto histórico" },
      variation: null,
      quantity: 1,
      price_mode: "POR_KG",
      unit_price: 8.5,
      waste_grams_per_unit: 50,
      read_weight_kg: 2,
    }],
  });
  const line = ticket.weighings[0];
  const catalog = {
    products: [
      { id: 7, name: "Producto actual", price_mode: "POR_UNIDAD", price: 2, waste_grams_per_unit: 0, variations: [] },
      { id: 8, name: "Otro producto", price_mode: "POR_UNIDAD", price: 3, waste_grams_per_unit: 5, variations: [] },
    ],
  };

  line.product_id = 8;
  applyProductDispatchCatalogDefaults(line, catalog);
  assert.equal(line.unit_price, 3);
  line.product_id = 7;
  applyProductDispatchCatalogDefaults(line, catalog);

  assert.equal(line.product_name, "Producto histórico");
  assert.equal(line.price_mode, "POR_KG");
  assert.equal(line.unit_price, 8.5);
  assert.equal(line.waste_grams_per_unit, 50);
});
