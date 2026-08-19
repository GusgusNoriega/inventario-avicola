import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import {
  buildWeightControlTicketHtml
} from "../../public/js/despacho-mayorista-2-ticket-printer.js";

const source = readFileSync(
  new URL("../../public/js/despacho-mayorista-2.js", import.meta.url),
  "utf8"
);
const view = readFileSync(
  new URL("../../resources/views/despacho-mayorista-2.blade.php", import.meta.url),
  "utf8"
);

function ticketWithRecord(record) {
  return {
    code: "M2-PRECIO-PRUEBA",
    sourceModule: "MODULO_DESPACHO_MAYORISTA_2",
    operationType: "DESPACHO",
    destinationName: "Cliente prueba",
    records: [{
      birdsPerCage: 1,
      cages: 1,
      readWeight: 10,
      grossWeight: 10,
      tareWeight: 1,
      netWeight: 9,
      ...record
    }]
  };
}

test("el alta conserva la entrega y acepta la tarifa opcional del cliente como respaldo", () => {
  assert.match(
    source,
    /specialPriceContext\s*=\s*\{[\s\S]*?truckId:\s*truck\.id,[\s\S]*?deliverySelection:\s*options\.deliverySelection \|\| null,[\s\S]*?registerAfterSave:/
  );
  assert.match(
    source,
    /getMissingManualPriceProducts\(truck\)\.length && options\.specialPricesConfirmed !== true[\s\S]*?openSpecialPriceModal\(truck\.id,[\s\S]*?deliverySelection,[\s\S]*?registerAfterSave:\s*true/
  );
  assert.match(
    source,
    /const submittedManualPrices = \{\};[\s\S]*?getTruckProductPriceDetails\(truck, product\.apiCode\)\.source !== "CLIENTE"[\s\S]*?ticketPayload\.manual_prices = submittedManualPrices/
  );
  assert.match(
    source,
    /registerDispatchTicket\(truckId, deliverySelection, \{ specialPricesConfirmed: true \}\)/
  );
});

test("el precio congelado o manual del ticket tiene prioridad y la tarifa del cliente queda como respaldo visible", () => {
  assert.match(
    source,
    /const persisted = normalizeManualPrice[\s\S]*?source: "TICKET"[\s\S]*?const ticketPrice = normalizeManualPrice[\s\S]*?source: "TICKET"[\s\S]*?destination\?\.henPricesKg\?\.\[code\][\s\S]*?source: "CLIENTE"/
  );
  assert.match(view, /id="henPricePreview"/);
  assert.match(source, /Precio del ticket: S\/ \$\{price\.price\.toFixed\(2\)\} por kg/);
  assert.match(source, /El ticket puede reemplazarlo/);
});

test("el popup usa campos de precio por ticket compatibles con el teclado numérico", () => {
  for (const id of [
    "specialPriceModal",
    "specialPriceForm",
    "specialPriceTicketLabel",
    "specialPriceFields",
    "specialPriceMessage",
    "closeSpecialPriceBtn",
    "cancelSpecialPriceBtn",
    "saveSpecialPriceBtn"
  ]) {
    assert.match(view, new RegExp(`id="${id}"`));
  }

  assert.match(
    source,
    /type="number"[\s\S]*?min="0\.01"[\s\S]*?max="99999999\.99"[\s\S]*?step="0\.01"[\s\S]*?required[\s\S]*?readonly[\s\S]*?inputmode="none"[\s\S]*?data-special-price-code=/
  );
  assert.ok(source.includes("/^\\d{1,8}(?:\\.\\d{1,2})?$/"));
});

test("la impresión usa únicamente precios canónicos persistidos y se bloquea si falta alguno", () => {
  assert.match(source, /getTruckProductPrice\(truck, productCode, true\)/);
  assert.match(source, /getMissingManualPriceProducts\(truck, true\)/);
  assert.match(source, /prices:\s*response\.data\?\.prices/);

  const missingCases = [
    [{ typeCode: "GR" }, "GALLINA_ROJA"],
    [{ typeCode: "GALLINA_DOBLE", priceKg: 0 }, "GALLINA_DOBLE"],
    [{ typeCode: "OT" }, "OTROS"],
    [{ typeCode: "PRODUCTO", productCode: "OTROS" }, "OTROS"]
  ];

  for (const [record, expectedCode] of missingCases) {
    assert.throws(
      () => buildWeightControlTicketHtml(ticketWithRecord(record)),
      new RegExp(`Falta asignar precio para: ${expectedCode}`)
    );
  }
});

test("un producto ordinario sin precio conserva el ticket de peso sin resumen monetario", () => {
  const html = buildWeightControlTicketHtml(ticketWithRecord({ typeCode: "PV-M" }));

  assert.match(html, />PV-M</);
  assert.doesNotMatch(html, /PRECIOS ASIGNADOS|TOTAL VALORIZADO/);
});
