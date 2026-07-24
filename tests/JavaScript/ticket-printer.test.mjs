import test from "node:test";
import assert from "node:assert/strict";

import { buildWeightControlTicketHtml } from "../../public/js/ticket-printer.js";

function compactHtml(html) {
  return html.replace(/\s+/g, " ").trim();
}

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

test("el ticket minorista reproduce el encabezado, detalle y resumen del control de peso", () => {
  const html = buildWeightControlTicketHtml({
    code: "231271",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    operatingDate: "2026-07-23",
    destinationName: "Edwin",
    totalAmount: 1703.19,
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
        typeCode: "PB",
        birds: 40,
        birdsPerCage: 8,
        cages: 5,
        readWeight: 120.45,
        grossWeight: 120.45,
        tareWeight: 12.5,
        netWeight: 107.95,
        priceKg: 8.75,
        amount: 944.56
      },
      {
        typeCode: "PB",
        birds: 32,
        birdsPerCage: 8,
        cages: 4,
        readWeight: 96.7,
        grossWeight: 96.7,
        tareWeight: 10,
        netWeight: 86.7,
        priceKg: 8.75,
        amount: 758.63
      }
    ]
  }, "2026-07-24T12:30:00-05:00");
  const compact = compactHtml(html);

  assert.match(html, /<body class="retail-ticket">/);
  assert.doesNotMatch(html, /<body class="wholesale-ticket">/);
  assert.match(html, /body \{[\s\S]*font-size: 15px;/);
  assert.match(html, /\.business-name \{[\s\S]*font-size: 23px;/);
  assert.match(html, /\.detail-table th \{[\s\S]*font-size: 11\.5px;/);
  assert.match(html, /\.detail-table td \{[\s\S]*font-size: 15\.5px;/);
  assert.match(html, /\.retail-summary-table td \{[\s\S]*font-size: 14\.5px;/);
  assert.match(html, /\.form-fields \{[\s\S]*font-size: 16px;/);
  assert.match(compact, /DISTRIBUIDORA.*DIEGO ALBERTO.*GALLINA.*GD/);
  assert.match(
    compact,
    /CONTROL DE PESO<\/span> <span>231271<\/span>.*FECHA 23\/07\/2026.*EDWIN/
  );
  assert.match(compact, /CAMIÓN: MIN-001.*CHOFER: Chofer minorista/);
  assert.match(
    compact,
    /<th>TIPO<\/th> <th>C\/A<\/th> <th>C\.J<\/th> <th>PESO<br>BRUTO<\/th> <th>PESO<br>TARA<\/th> <th>CONTROL<br>PESO<\/th>/
  );
  assert.match(
    compact,
    /<td>PB<\/td> <td class="number">40<\/td> <td class="number">5<\/td> <td class="number">120\.45<\/td> <td class="number">12\.50<\/td>/
  );
  assert.match(
    compact,
    /<td>PB<\/td> <td class="number">32<\/td> <td class="number">4<\/td> <td class="number">96\.70<\/td> <td class="number">10\.00<\/td>/
  );
  assert.match(
    compact,
    /<tr><th>PESO<\/th><th>AVES<\/th><th>MERM<\/th><\/tr>.*<td>194\.65<\/td> <td>72<\/td> <td>0\.00<\/td>/
  );
  assert.match(
    compact,
    /<tr><th>P\.NETO<\/th><th>PRE\.<\/th><th>SOLES<\/th><\/tr>.*<td>194\.65<\/td> <td class="">8\.75<\/td> <td>1,703\.19<\/td>/
  );
  assert.doesNotMatch(html, /S\/\s/);
  assert.doesNotMatch(html, /12:30/);
});

test("el ticket minorista separa peso leido, tara, merma y peso neto cobrado", () => {
  const html = compactHtml(buildWeightControlTicketHtml({
    code: "T-20260723-004",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    operatingDate: "2026-07-23",
    destinationName: "Venta minorista",
    records: [
      {
        typeCode: "PP",
        birds: 10,
        birdsPerCage: 5,
        cages: 2,
        readWeight: 12,
        grossWeight: 14.5,
        tareWeight: 1,
        netWeight: 13.5,
        priceKg: 8.5,
        amount: 114.75
      }
    ]
  }, "2026-07-23T12:30:00-05:00"));

  assert.match(
    html,
    /<td>PP<\/td> <td class="number">10<\/td> <td class="number">2<\/td> <td class="number">12\.00<\/td> <td class="number">1\.00<\/td>/
  );
  assert.match(
    html,
    /<tr><th>PESO<\/th><th>AVES<\/th><th>MERM<\/th><\/tr>.*<td>11\.00<\/td> <td>10<\/td> <td>2\.50<\/td>/
  );
  assert.match(
    html,
    /<tr><th>P\.NETO<\/th><th>PRE\.<\/th><th>SOLES<\/th><\/tr>.*<td>13\.50<\/td> <td class="">8\.50<\/td> <td>114\.75<\/td>/
  );
});

test("el ticket minorista conserva pollos reales y bandejas incluso cuando son cero", () => {
  const html = compactHtml(buildWeightControlTicketHtml({
    code: "T-20260723-005",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    destinationName: "Venta minorista",
    records: [
      {
        typeCode: "PP",
        birds: 5,
        birdsPerCage: 5,
        cages: 0,
        readWeight: 11.25,
        grossWeight: 11.25,
        tareWeight: 0,
        netWeight: 11.25,
        priceKg: 8,
        amount: 90
      }
    ]
  }, "2026-07-23T12:30:00-05:00"));

  assert.match(
    html,
    /<td>PP<\/td> <td class="number">5<\/td> <td class="number">0<\/td> <td class="number">11\.25<\/td>/
  );
});

test("el retiro directo minorista no imprime un transporte inexistente", () => {
  const html = buildWeightControlTicketHtml({
    code: "T-20260723-006",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    operatingDate: "2026-07-23",
    destinationName: "Cliente que retira",
    delivery: {
      mode: "CUSTOMER_PICKUP",
      vehicle: null,
      driver: null
    },
    records: []
  }, "2026-07-23T12:30:00-05:00");

  assert.doesNotMatch(html, /TRANSPORTE:/);
  assert.doesNotMatch(html, /RETIRO DIRECTO/);
  assert.doesNotMatch(html, /CAMIÓN:/);
  assert.doesNotMatch(html, /CHOFER:/);
  assert.doesNotMatch(html, /<section class="delivery">/);
});

test("el ticket minorista muestra VARIOS cuando las pesadas tienen precios diferentes", () => {
  const html = compactHtml(buildWeightControlTicketHtml({
    code: "T-20260723-007",
    channel: "MINORISTA",
    operationType: "DESPACHO",
    operatingDate: "2026-07-23",
    destinationName: "Venta con precios mixtos",
    totalAmount: 233,
    records: [
      {
        typeCode: "PP",
        birds: 10,
        cages: 2,
        readWeight: 16,
        grossWeight: 16,
        tareWeight: 1,
        netWeight: 15,
        priceKg: 8,
        amount: 120
      },
      {
        typeCode: "PB",
        birds: 8,
        cages: 2,
        readWeight: 14,
        grossWeight: 14,
        tareWeight: 1.5,
        netWeight: 12.5,
        priceKg: 9,
        amount: 112.5
      }
    ]
  }, "2026-07-23T12:30:00-05:00"));

  assert.match(
    html,
    /<tr><th>P\.NETO<\/th><th>PRE\.<\/th><th>SOLES<\/th><\/tr>.*<td>27\.50<\/td> <td class="price-various">VARIOS<\/td> <td>233\.00<\/td>/
  );
});

test("el bloque de transporte mayorista se omite cuando el despacho no lo requiere", () => {
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
