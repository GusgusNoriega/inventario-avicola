import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
  new URL("../../public/js/finanzas-caja-efectivo.js", import.meta.url),
  "utf8"
);

function sourceBetween(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.notEqual(start, -1, `No se encontro ${startMarker}`);
  assert.notEqual(end, -1, `No se encontro ${endMarker}`);
  return source.slice(start, end);
}

const helpers = new Function(
  "escapeHtml",
  `${sourceBetween("function ledgerKey", "function renderLedger")}
  return { ledgerKey, traceDescription, traceMarkup };`
)(value => String(value)
  .replaceAll("&", "&amp;")
  .replaceAll("<", "&lt;")
  .replaceAll(">", "&gt;")
  .replaceAll('"', "&quot;"));

test("las filas de caja y pagos genericos usan identificadores separados", () => {
  assert.equal(helpers.ledgerKey({ row_key: "caja:8", pago_id: 8 }), "caja:8");
  assert.equal(helpers.ledgerKey({ movimiento_caja_id: 8, pago_id: 12 }), "caja:8");
  assert.equal(helpers.ledgerKey({ movimiento_caja_id: null, pago_id: 8 }), "pago:8");
  assert.notEqual(
    helpers.ledgerKey({ movimiento_caja_id: 8, pago_id: 12 }),
    helpers.ledgerKey({ movimiento_caja_id: null, pago_id: 8 })
  );
});

test("la traza muestra cobranza, asignacion, cobrador y asiento", () => {
  const description = helpers.traceDescription({
    cobranza: {
      id: 4,
      codigo: "COB-004",
      referencia: "RUTA-9",
      rol_pago: "DETALLE_REASIGNADO",
      asignacion: { id: 7 },
      cobrador: { nombre: "Ana" }
    },
    trazabilidad: { pago: { codigo: "PG-010" } }
  });

  assert.match(description, /Cobranza COB-004/);
  assert.match(description, /detalle identificado/);
  assert.match(description, /asignaci.n #7/);
  assert.match(description, /cobrador Ana/);
  assert.match(description, /asiento PG-010/);
});

test("gastos y compras conservan su origen empresarial", () => {
  assert.match(helpers.traceDescription({
    origen: { tipo: "GASTO_EMPRESA", codigo: "GAS-12", referencia: "B001-9" },
    trazabilidad: {}
  }), /Origen: Gasto de empresa .* GAS-12 .* ref\. B001-9/);
  assert.match(helpers.traceDescription({
    origen: { tipo: "COMPRA", codigo: "COM-15", referencia: "F001-2" },
    trazabilidad: {}
  }), /Origen: Compra .* COM-15 .* ref\. F001-2/);
});

test("solo una ruta interna se convierte en enlace de trazabilidad", () => {
  const linked = helpers.traceMarkup({
    origen: { tipo: "COMPRA", codigo: "COM-15", url: "/compras?compra=15" },
    trazabilidad: {}
  });
  assert.match(linked, /^<a /);
  assert.match(linked, /href="\/compras\?compra=15"/);

  const external = helpers.traceMarkup({
    origen: { tipo: "COMPRA", codigo: "COM-15", url: "https://example.test" },
    trazabilidad: {}
  });
  assert.match(external, /^<span /);
  assert.doesNotMatch(external, /href=/);
});

test("editar y eliminar exigen un movimiento manual y permiso explicito", () => {
  const renderFlow = sourceBetween("function renderLedger", "function applySummary");
  const editFlow = sourceBetween("function openEditDialog", "function movementPayload");
  const deleteFlow = sourceBetween("async function deleteMovement", "elements.saveDefault");

  assert.match(renderFlow, /record\.puede_editar && record\.movimiento_caja_id/);
  assert.match(renderFlow, /record\.puede_anular && record\.movimiento_caja_id/);
  assert.match(editFlow, /!record\.puede_editar \|\| !record\.movimiento_caja_id/);
  assert.match(deleteFlow, /!record\.puede_anular \|\| !record\.movimiento_caja_id/);
});
