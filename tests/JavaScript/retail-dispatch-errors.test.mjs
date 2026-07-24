import test from "node:test";
import assert from "node:assert/strict";

import {
  getRetailDispatchErrorPresentation,
  retailDispatchFieldLabel
} from "../../public/js/retail-dispatch-errors.js";

test("presenta todos los errores de validación del ticket con nombres comprensibles", () => {
  const presentation = getRetailDispatchErrorPresentation({
    status: 422,
    data: {
      errors: {
        "payments.0.cuenta_destino_id": ["Selecciona una cuenta o caja activa de la empresa."],
        "payments.0.importe": ["El importe debe ser mayor que cero."],
        "weighings.1.read_weight_kg": ["El peso debe ser mayor que la tara."]
      }
    }
  });

  assert.equal(presentation.title, "Revisa los datos del ticket");
  assert.equal(presentation.details.length, 3);
  assert.deepEqual(
    presentation.details.map((detail) => detail.label),
    ["Pago 1 · cuenta o caja", "Pago 1 · importe", "Pesada 2 · peso leído"]
  );
  assert.match(presentation.message, /No se guardó el despacho/);
});

test("un error 500 nunca expone SQL ni la traza recibida", () => {
  const presentation = getRetailDispatchErrorPresentation({
    status: 500,
    message: "SQLSTATE[42S22]: Unknown column 'estacion'",
    data: {
      message: "SQLSTATE[42S22]: Unknown column 'estacion'",
      errors: {
        database: ["SQLSTATE[42S22]: Unknown column 'estacion'"]
      },
      trace: [{ file: "RetailDispatchService.php" }]
    }
  });
  const visibleText = JSON.stringify(presentation);

  assert.equal(presentation.title, "No se pudo guardar el ticket");
  assert.match(visibleText, /HTTP 500/);
  assert.doesNotMatch(visibleText, /SQLSTATE|estacion|RetailDispatchService/);
});

test("distingue sesión vencida, falta de permiso y error de conexión", () => {
  assert.match(getRetailDispatchErrorPresentation({ status: 419 }).summary, /sesión venció/i);
  assert.match(getRetailDispatchErrorPresentation({ status: 403 }).summary, /no tiene permiso/i);
  assert.match(getRetailDispatchErrorPresentation(new TypeError("Failed to fetch")).summary, /conexión/i);
});

test("traduce campos conocidos y conserva una etiqueta útil para campos nuevos", () => {
  assert.equal(retailDispatchFieldLabel("delivery.driver_id"), "Chofer de entrega");
  assert.equal(retailDispatchFieldLabel("price_overrides.POLLO_PELADO"), "Precio · Pollo pelado");
  assert.equal(retailDispatchFieldLabel("campo_nuevo"), "Campo nuevo");
});
