import assert from "node:assert/strict";
import test from "node:test";

import { movementRepeatContext } from "../../public/js/finanzas-movimiento-repetido.js";

const filledMovement = {
  currency: "USD",
  client: "12",
  provider: "34",
  method: "5",
  date: "2026-08-01T09:45",
  destination: "78",
  amount: "250.00",
  reference: "OP-12345",
  notes: "Primer depósito",
  applications: [{ documentId: "90", amount: "250.00" }],
  idempotencyKey: "old-key"
};

test("conserva el contexto útil para registrar otro cobro de cliente", () => {
  assert.deepEqual(movementRepeatContext("COBRO_CLIENTE", filledMovement), {
    currency: "USD",
    client: "12",
    method: "5",
    date: "2026-08-01T09:45",
    destination: "78"
  });
});

test("conserva también el proveedor para registrar otro pago directo", () => {
  assert.deepEqual(movementRepeatContext("PAGO_DIRECTO", filledMovement), {
    currency: "USD",
    client: "12",
    provider: "34",
    method: "5",
    date: "2026-08-01T09:45",
    destination: "78"
  });
});

test("no conserva importes ni referencias que podrían duplicar el depósito", () => {
  const context = movementRepeatContext("PAGO_DIRECTO", filledMovement);

  assert.equal("amount" in context, false);
  assert.equal("reference" in context, false);
  assert.equal("notes" in context, false);
  assert.equal("applications" in context, false);
  assert.equal("idempotencyKey" in context, false);
});

test("mantiene el reinicio completo para las demás operaciones", () => {
  assert.equal(movementRepeatContext("PAGO_PROVEEDOR", filledMovement), null);
  assert.equal(movementRepeatContext("COBRO_MINORISTA", filledMovement), null);
});
