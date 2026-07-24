import test from "node:test";
import assert from "node:assert/strict";

import { buildWeightControlTicketHtml } from "../../public/js/ticket-printer.js";

test("el ticket mayorista usa tipografia grande y muestra el camion y chofer seleccionados", () => {
  const html = buildWeightControlTicketHtml({
    code: "T-20260723-001",
    operationType: "DESPACHO",
    destinationName: "Cliente de prueba",
    delivery: {
      vehicle: {
        id: 10,
        plate: "ABC-123"
      },
      driver: {
        id: 20,
        name: "María <Prueba>"
      }
    },
    records: [
      {
        typeCode: "PV",
        birdsPerCage: 10,
        cages: 2,
        grossWeight: 30,
        tareWeight: 14,
        netWeight: 16
      }
    ]
  }, "2026-07-23T12:30:00-05:00");

  assert.match(html, /<body class="wholesale-ticket">/);
  assert.match(html, /body \{[\s\S]*font-size: 18px;/);
  assert.match(html, /\.delivery \{[\s\S]*font-size: 17px;/);
  assert.match(html, /td \{[\s\S]*font-size: 17px;/);
  assert.match(html, /\.form-fields \{[\s\S]*font-size: 19px;/);
  assert.match(html, /CAMIÓN: ABC-123/);
  assert.match(html, /CHOFER: María &lt;Prueba&gt;/);
});

test("el ticket minorista usa la misma tipografia grande y muestra el camion y chofer asignados", () => {
  const html = buildWeightControlTicketHtml({
    code: "T-20260723-003",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    destinationName: "Venta minorista",
    delivery: {
      vehicle: {
        id: 30,
        plate: "MIN-001"
      },
      driver: {
        id: 40,
        name: "Chofer minorista"
      }
    },
    records: [
      {
        typeCode: "PP",
        birds: 10,
        birdsPerCage: 5,
        cages: 2,
        grossWeight: 14.5,
        tareWeight: 1,
        netWeight: 13.5,
        priceKg: 8.5,
        amount: 114.75
      }
    ]
  }, "2026-07-23T12:30:00-05:00");

  assert.match(html, /<body class="retail-ticket">/);
  assert.doesNotMatch(html, /<body class="wholesale-ticket">/);
  assert.match(html, /body \{[\s\S]*font-size: 18px;/);
  assert.match(html, /\.delivery \{[\s\S]*font-size: 17px;/);
  assert.match(html, /td \{[\s\S]*font-size: 17px;/);
  assert.match(html, /\.form-fields \{[\s\S]*font-size: 19px;/);
  assert.doesNotMatch(html, /\.retail-detail-table th,[\s\S]*font-size: 9\.5px;/);
  assert.match(html, /CAMIÓN: MIN-001/);
  assert.match(html, /CHOFER: Chofer minorista/);
  assert.match(html, /<th>POLLOS<\/th>/);
  assert.match(html, /<td class="number">10<\/td>/);
  assert.doesNotMatch(html, /<th>AV\/B<\/th>/);
  assert.doesNotMatch(html, /<th>BAN<\/th>/);
});

test("el ticket minorista imprime los pollos reales de una pesada sin bandejas", () => {
  const html = buildWeightControlTicketHtml({
    code: "T-20260723-004",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    destinationName: "Venta minorista",
    records: [
      {
        typeCode: "PP",
        birds: 5,
        birdsPerCage: 5,
        cages: 0,
        grossWeight: 11.25,
        tareWeight: 0,
        netWeight: 11.25,
        priceKg: 8,
        amount: 90
      }
    ]
  }, "2026-07-23T12:30:00-05:00");

  assert.match(html, /<th>POLLOS<\/th>/);
  assert.match(html, /<td class="number">5<\/td>/);
  assert.doesNotMatch(html, /<td class="number">0<\/td>/);
});

test("el ticket minorista identifica el retiro directo sin inventar camión ni chofer", () => {
  const html = buildWeightControlTicketHtml({
    code: "T-20260723-005",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    destinationName: "Cliente que retira",
    delivery: {
      mode: "CUSTOMER_PICKUP",
      vehicle: null,
      driver: null
    },
    records: []
  }, "2026-07-23T12:30:00-05:00");

  assert.match(html, /TRANSPORTE: RETIRO DIRECTO POR EL CLIENTE/);
  assert.doesNotMatch(html, /CAMIÓN:/);
  assert.doesNotMatch(html, /CHOFER:/);
});

test("el bloque de transporte se omite cuando el despacho no lo requiere", () => {
  const html = buildWeightControlTicketHtml({
    code: "T-20260723-002",
    operationType: "DESPACHO",
    destinationName: "Almacén interno",
    records: []
  }, "2026-07-23T12:30:00-05:00");

  assert.doesNotMatch(html, /CAMIÓN:/);
  assert.doesNotMatch(html, /CHOFER:/);
  assert.doesNotMatch(html, /<section class="delivery">/);
});
