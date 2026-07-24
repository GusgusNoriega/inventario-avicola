import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import {
  getRetailDispatchErrorPresentation,
  getRetailLocalActionErrorPresentation,
  retailDispatchFieldLabel
} from "../../public/js/retail-dispatch-errors.js";

const retailDispatchSource = readFileSync(
  new URL("../../public/js/despacho-minorista.js", import.meta.url),
  "utf8"
);

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

test("presenta fallos locales accionables con detalle y una corrección visible", () => {
  const presentation = getRetailLocalActionErrorPresentation({
    caption: "Captura bloqueada",
    title: "La tara supera el peso",
    message: "No se agregó la pesada.",
    details: [
      { label: "Peso ajustado", value: "2.000 kg" },
      { label: "Tara", value: "2.500 kg" }
    ],
    help: "Reduce las bandejas o coloca un peso mayor y vuelve a capturar."
  });

  assert.equal(presentation.caption, "Captura bloqueada");
  assert.equal(presentation.title, "La tara supera el peso");
  assert.equal(presentation.summary, "No se agregó la pesada.");
  assert.deepEqual(presentation.details, [
    { label: "Peso ajustado", value: "2.000 kg" },
    { label: "Tara", value: "2.500 kg" }
  ]);
  assert.match(presentation.help, /vuelve a capturar/i);
});

test("un fallo local sin detalle conserva el motivo en el modal", () => {
  const presentation = getRetailLocalActionErrorPresentation({
    message: "El catálogo de la estación no está disponible."
  });

  assert.equal(presentation.summary, "El catálogo de la estación no está disponible.");
  assert.deepEqual(presentation.details, [{
    label: "Motivo",
    value: "El catálogo de la estación no está disponible."
  }]);
});

test("la captura bloqueada explica el motivo en el modal compartido por ambas estaciones", () => {
  assert.match(retailDispatchSource, /getRetailLocalActionErrorPresentation/);
  assert.match(retailDispatchSource, /function showCaptureIssue\(options = \{\}\)/);
  assert.match(retailDispatchSource, /Despacho minorista 2/);
  assert.match(retailDispatchSource, /La presentación fija no está disponible/);
  assert.match(retailDispatchSource, /Faltan datos del catálogo minorista/);
  assert.match(retailDispatchSource, /La tara iguala o supera el peso/);
  assert.match(retailDispatchSource, /El peso manual no es válido/);
  assert.match(retailDispatchSource, /Hay precios manuales no válidos/);
  assert.match(
    retailDispatchSource,
    /if \(touchKeyboardState\.target && !elements\.touchKeyboard\?\.hidden\)/
  );
  assert.match(
    retailDispatchSource,
    /const captureLocked = state\.loading \|\| state\.lists\.some/
  );
  assert.match(
    retailDispatchSource,
    /elements\.captureWeight\.disabled = captureLocked/
  );
  assert.doesNotMatch(
    retailDispatchSource,
    /elements\.captureWeight\.disabled = !availability\.ready/
  );
});
