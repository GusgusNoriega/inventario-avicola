import test from "node:test";
import assert from "node:assert/strict";

import { buildWeightControlTicketHtml } from "../../public/js/ticket-printer.js";

test("el ticket usa tipografia grande y muestra el camion y chofer seleccionados", () => {
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

  assert.match(html, /body \{[\s\S]*font-size: 13px;/);
  assert.match(html, /\.delivery \{[\s\S]*font-size: 12\.5px;/);
  assert.match(html, /CAMIÓN: ABC-123/);
  assert.match(html, /CHOFER: María &lt;Prueba&gt;/);
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
